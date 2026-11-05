<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/MaxSubscriptionAttributionPolicy.php';

/** Direct SELECT-only access on the retained Bitrix host, without Bitrix bootstrap. */
final class MaxTrackerLookupRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }

    public static function fromSettings(string $settingsPath): self
    {
        if (!is_file($settingsPath)) throw new RuntimeException('tracker_unavailable');
        $level=ob_get_level(); ob_start();
        try { $s=require $settingsPath; } finally { while(ob_get_level()>$level)ob_end_clean(); }
        $d=$s['connections']['value']['default']??null;
        if (!is_array($d)) throw new RuntimeException('tracker_unavailable');
        $host=(string)($d['host']??'localhost'); $name=(string)($d['database']??'');
        if (strpbrk($host.$name,";\r\n")!==false) throw new RuntimeException('tracker_unavailable');
        $pdo=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',(string)($d['login']??''),(string)($d['password']??''),
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
        return new self($pdo);
    }

    public function lookup(string $counterId, array $userIds, ?array $membership = null): array
    {
        $validationNow=$membership['cutoff_unix']??1;
        MaxTrackerLookupProtocol::body($counterId,$userIds,$validationNow,str_repeat('0',32),$membership);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $this->pdo->inTransaction()) throw new RuntimeException('tracker_readonly_required');
        $this->pdo->exec('SET TRANSACTION READ ONLY');
        $this->pdo->beginTransaction();
        try {
            // UF_PLATFORM in this MAX-only table is a device platform (android/ios),
            // not a messenger namespace. MAX identity comes from this store itself.
            $sql="SELECT s.ID,s.UF_CLIENT_ID,s.UF_DATE FROM max_sub_links s WHERE s.UF_CHAT_ID=?"
                ." AND s.UF_CLIENT_ID REGEXP '^[1-9][0-9]{0,39}$' AND s.UF_CLIENT_ID NOT REGEXP '[^0-9]'"
                ." AND EXISTS (SELECT 1 FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID"
                ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.IBLOCK_ELEMENT_ID="
                ." CASE WHEN s.UF_METRIC_SOURCE IS NOT NULL AND s.UF_METRIC_SOURCE<>'' THEN s.UF_METRIC_SOURCE ELSE s.UF_SOURCE END AND ep.VALUE=?)"
                ." ORDER BY s.ID DESC LIMIT 1";
            $q=$this->pdo->prepare($sql); $found=[];
            foreach ($userIds as $userId) {
                $q->execute([$userId,$counterId]); $r=$q->fetch();
                if (!$r) { $found[]=['external_user_id'=>$userId,'found'=>false]; continue; }
                $cid=(string)$r['UF_CLIENT_ID']; $record=(string)$r['ID'];
                if (!MaxTrackerLookupProtocol::id($cid,40)||!MaxTrackerLookupProtocol::id($record)) throw new RuntimeException('tracker_invalid_record');
                $observed=null;
                try { $observed=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$r['UF_DATE'],new DateTimeZone('Europe/London')); } catch (InvalidArgumentException $ignored) {}
                $found[]=['external_user_id'=>$userId,'found'=>true,'client_id'=>$cid,'record_id'=>$record,'observed_at_unix'=>$observed];
            }
            if ($membership !== null) {
                $receipts=$this->membershipReceipts($counterId,$userIds,$membership);
                foreach ($found as &$row) $row['membership_receipts']=$receipts[$row['external_user_id']]??[];
                unset($row);
            }
            return $found;
        } finally { if($this->pdo->inTransaction())$this->pdo->rollBack(); }
    }

    /** Exact per-chat history after the caller's prospective fence; current state is never backdated. */
    private function membershipReceipts(string $counterId,array $userIds,array $window): array
    {
        $historyTable=$this->tableName(10);$mainTable=$this->tableName(9);$retentionTable=$this->tableName(12);
        $sql='SELECT h.ID,h.UF_CHAT_ID,h.UF_SOURCE,h.UF_TYPE,h.UF_DATE FROM `'.$historyTable.'` h'
            .' WHERE h.UF_CHAT_ID=? AND h.UF_TYPE IN (3,4) AND h.UF_DATE>=? AND h.UF_DATE<=?'
            .' AND EXISTS (SELECT 1 FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID'
            ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.IBLOCK_ELEMENT_ID=h.UF_SOURCE AND ep.VALUE=?)"
            .' ORDER BY h.UF_DATE,h.ID LIMIT 21';
        $q=$this->pdo->prepare($sql);$out=[];
        $source="CASE WHEN s.UF_METRIC_SOURCE IS NOT NULL AND s.UF_METRIC_SOURCE<>'' THEN s.UF_METRIC_SOURCE ELSE s.UF_SOURCE END";
        $retentionSql='SELECT r.ID,r.UF_SUB_ID,r.UF_DATE,s.UF_SUB_DATE,'.$source.' AS LINK_SOURCE FROM `'.$retentionTable.'` r'
            .' JOIN `'.$mainTable.'` s ON s.ID=r.UF_SUB_ID WHERE s.UF_CHAT_ID=? AND r.UF_DATE>=? AND r.UF_DATE<=?'
            .' AND EXISTS (SELECT 1 FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID'
            ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.IBLOCK_ELEMENT_ID=".$source.' AND ep.VALUE=?)'
            .' ORDER BY r.UF_DATE,r.ID LIMIT 21';
        $retentionQuery=$this->pdo->prepare($retentionSql);
        $zone=new DateTimeZone('Europe/London');
        $from=(new DateTimeImmutable('@'.(int)$window['not_before_unix']))->setTimezone($zone)->format('Y-m-d H:i:s');
        $to=(new DateTimeImmutable('@'.(int)$window['cutoff_unix']))->setTimezone($zone)->format('Y-m-d H:i:s');
        $budget=100;
        foreach($userIds as$userId){
            $q->execute([$userId,$from,$to,$counterId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $retentionQuery->execute([$userId,$from,$to,$counterId]);$checkRows=$retentionQuery->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)>20||count($checkRows)>20){$out[$userId]=[];continue;}
            $checks=[];$invalid=false;
            foreach($checkRows as$row){
                $record=(string)$row['ID'];$subscription=(string)$row['UF_SUB_ID'];$sourceId=(string)$row['LINK_SOURCE'];
                if(!MaxTrackerLookupProtocol::id($record)||!MaxTrackerLookupProtocol::id($subscription)
                    ||!MaxTrackerLookupProtocol::id($sourceId)){$invalid=true;break;}
                try{$at=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$row['UF_DATE'],$zone);
                    $subscriptionAt=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$row['UF_SUB_DATE'],$zone);}
                catch(InvalidArgumentException $ignored){$invalid=true;break;}
                if($at<(int)$window['not_before_unix']||$at>(int)$window['cutoff_unix'])continue;
                $checks[]=['record_id'=>$record,'subscription_id'=>$subscription,'source'=>$sourceId,'at'=>$at,'subscription_at'=>$subscriptionAt];
            }
            if($invalid){$out[$userId]=[];continue;}
            $periods=[];$open=[];
            foreach($rows as$row){
                $record=(string)$row['ID'];$type=(string)$row['UF_TYPE'];$sourceId=(string)$row['UF_SOURCE'];
                if(!MaxTrackerLookupProtocol::id($record)||!MaxTrackerLookupProtocol::id((string)$row['UF_CHAT_ID'])
                    ||!MaxTrackerLookupProtocol::id($sourceId)||!in_array($type,['3','4'],true)){$invalid=true;break;}
                try{$at=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$row['UF_DATE'],$zone);}
                catch(InvalidArgumentException $ignored){$invalid=true;break;}
                if($at<(int)$window['not_before_unix']||$at>(int)$window['cutoff_unix'])continue;
                if($type==='3'){
                    // Repeated user_added while the same period is still open
                    // is another observation of that state, not a rejoin.
                    if(isset($open[$sourceId])&&$periods[$open[$sourceId]]['left']===null)continue;
                    $periods[]=['record_id'=>$record,'source'=>$sourceId,'at'=>$at,'left'=>null];$open[$sourceId]=count($periods)-1;
                }elseif(isset($open[$sourceId])&&$periods[$open[$sourceId]]['left']===null){
                    $periods[$open[$sourceId]]['left']=['record_id'=>$record,'at'=>$at];unset($open[$sourceId]);
                }
            }
            if($invalid){$out[$userId]=[];continue;}
            $receipts=[];
            foreach($periods as$period){
                $receipts[]=['kind'=>'subscription','source_record_id'=>$period['record_id'],
                    'state'=>'subscribed','occurred_at_unix'=>$period['at']];
                $check=$period['at']+1800;
                if($check>(int)$window['cutoff_unix'])continue;
                $left=$period['left'];$notRetained=$left!==null&&$left['at']<=$check;
                if($notRetained){
                    $receipts[]=['kind'=>'retention_30m','source_record_id'=>$left['record_id'],
                        'subscription_source_record_id'=>$period['record_id'],'state'=>'not_retained',
                        'occurred_at_unix'=>$check,'minimum_age_seconds'=>1800];
                    continue;
                }
                foreach($checks as$actual)if($actual['source']===$period['source']&&$actual['subscription_at']===$period['at']
                    &&$actual['at']>=$check&&($left===null||$left['at']>$actual['at'])){
                    $receipts[]=['kind'=>'retention_30m','source_record_id'=>$actual['record_id'],
                        'subscription_source_record_id'=>$period['record_id'],'state'=>'retained',
                        'occurred_at_unix'=>$actual['at'],'minimum_age_seconds'=>1800];break;
                }
            }
            if(count($receipts)>20){$out[$userId]=[];continue;}
            if(count($receipts)>$budget)throw new RuntimeException('tracker_membership_response_too_large');
            $budget-=count($receipts);
            $out[$userId]=$receipts;
        }
        return $out;
    }

    /** A processing marker is not proof of current membership or a billable member page. */
    public function currentMemberPage(string $counterId,string $after,int $now):array
    {
        MaxTrackerLookupProtocol::body($counterId,[],$now,str_repeat('0',32),null,$after);
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$this->pdo->inTransaction())
            throw new RuntimeException('tracker_readonly_required');
        // History-backed current membership needs its own reviewed contract.
        // Keep the existing legacy page unavailable instead of inventing subscribers.
        throw new RuntimeException('tracker_current_membership_unavailable');
    }


    /**
     * Display-only membership observed in retained HL10 events. It never reads
     * an HL9 processing marker, creates money, or verifies live platform state.
     */
    public function historyMemberPage(string $counterId,string $after,int $now):array
    {
        $cursor=MaxTrackerLookupProtocol::historyCursor($after,$now,$counterId);
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$this->pdo->inTransaction())
            throw new RuntimeException('tracker_readonly_required');
        $version=(string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        if(stripos($version,'mariadb')!==false||!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+/',$version,$v)
            ||version_compare($v[0],'5.7.8','<'))throw new RuntimeException('tracker_history_unavailable');
        $started=hrtime(true);$bytes=0;
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $this->pdo->beginTransaction();
        try{
            $metadata=$this->historySelect('SELECT /* history_timeout */ TABLE_NAME FROM b_hlblock_entity WHERE ID=10 LIMIT 2',[],2,$started,$bytes);
            $table=(string)($metadata[0]['TABLE_NAME']??'');
            if(count($metadata)!==1||preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$table)!==1)
                throw new RuntimeException('tracker_history_unavailable');
            $engines=$this->historySelect('SELECT /* history_timeout */ TABLE_NAME,ENGINE FROM information_schema.TABLES'
                .' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\' AND TABLE_NAME IN (?,?,?,?)',
                [$table,'b_hlblock_entity','b_iblock_property','b_iblock_element_property'],4,$started,$bytes);
            $expected=array_fill_keys([$table,'b_hlblock_entity','b_iblock_property','b_iblock_element_property'],true);
            foreach($engines as$engine){
                $name=(string)($engine['TABLE_NAME']??'');
                if(!isset($expected[$name])||strtoupper((string)($engine['ENGINE']??''))!=='INNODB')
                    throw new RuntimeException('tracker_history_unavailable');
                unset($expected[$name]);
            }
            if($expected)throw new RuntimeException('tracker_history_unavailable');
            $sourceRows=$this->historySelect('SELECT /* history_timeout */ DISTINCT ep.IBLOCK_ELEMENT_ID AS source_id'
                .' FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID'
                ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.VALUE=? ORDER BY ep.IBLOCK_ELEMENT_ID LIMIT 257",
                [$counterId],257,$started,$bytes);
            if(count($sourceRows)>256)throw new RuntimeException('tracker_history_unavailable');
            $sources=[];
            foreach($sourceRows as$source){
                $id=(string)($source['source_id']??'');
                if(!MaxTrackerLookupProtocol::id($id)||self::historyDecimal($id,(string)PHP_INT_MAX)>0)
                    throw new RuntimeException('tracker_history_unavailable');
                $sources[]=$id;
            }
            usort($sources,[self::class,'historyDecimal']);
            if(count(array_unique($sources))!==count($sources))throw new RuntimeException('tracker_history_unavailable');
            $sourceHash=hash('sha256',json_encode($sources,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            if($cursor!==null&&!hash_equals($cursor['source_sha256'],$sourceHash))
                throw new RuntimeException('tracker_history_snapshot_changed');
            $cutoff=$cursor['cutoff_unix']??$now;
            if(!$sources)return self::historyPage([], $cutoff,'0',null,false,0,0);
            $sourceParams=array_map(static fn(string $id):int=>(int)$id,$sources);
            $in=implode(',',array_fill(0,count($sources),'?'));
            $quoted='`'.$table.'`';
            $zone=new DateTimeZone('Europe/London');
            // Freeze all retained event IDs, including uncertain or future clocks.
            // A bad later clock must not revive an older subscribed observation.
            if($cursor===null){
                $f=$this->historySelect('SELECT /* history_timeout */ h.ID FROM '.$quoted.' h'
                    .' WHERE h.UF_SOURCE IN ('.$in.') AND h.UF_TYPE IN (3,4)'
                    .' ORDER BY h.ID DESC LIMIT 1',$sourceParams,1,$started,$bytes);
                $fence=$f?(string)$f[0]['ID']:'0';
                if($fence!=='0'&&!MaxTrackerLookupProtocol::id($fence))throw new RuntimeException('tracker_history_invalid_record');
            }else{$fence=$cursor['history_fence_id'];}
            if($fence==='0')return self::historyPage([],$cutoff,'0',null,false,0,0);
            $params=array_merge($sourceParams,[$fence]);
            $before='';
            if($cursor!==null){$before=' AND h.ID<?';$params[]=$cursor['before_id'];}
            $candidates=$this->historySelect('SELECT /* history_timeout */ h.ID,h.UF_CHAT_ID,h.UF_SOURCE,h.UF_DATE FROM '.$quoted.' h'
                .' WHERE h.UF_SOURCE IN ('.$in.') AND h.ID<=? AND h.UF_TYPE=3'.$before
                .' ORDER BY h.ID DESC LIMIT 65',$params,65,$started,$bytes);
            if(!$candidates)return self::historyPage([],$cutoff,$fence,null,false,0,0);
            $indexes=$this->historySelect('SELECT /* history_timeout */ INDEX_NAME FROM information_schema.STATISTICS'
                ." WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND SEQ_IN_INDEX=1 AND COLUMN_NAME='UF_CHAT_ID'"
                .' ORDER BY NON_UNIQUE,INDEX_NAME LIMIT 16',[$table],16,$started,$bytes);
            $index=(string)($indexes[0]['INDEX_NAME']??'');
            if(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$index)!==1)throw new RuntimeException('tracker_history_unavailable');
            $pointFrom=$quoted.' h FORCE INDEX (`'.$index.'`)';
            $pointWhere='h.UF_CHAT_ID=? AND BINARY h.UF_CHAT_ID=BINARY ? AND h.UF_SOURCE=? AND h.ID<=?';
            $unknownOrder='(h.UF_DATE IS NULL OR h.UF_DATE<\'2000-01-01 00:00:00\' OR MONTH(h.UF_DATE)=0 OR DAYOFMONTH(h.UF_DATE)=0) DESC,';
            $rows=[];$scanned=0;$unknown=0;$lastScanned=null;$decisions=[];
            foreach($candidates as$candidate){
                if($scanned===64||count($rows)===5)break;
                $scanned++;$record=(string)($candidate['ID']??'');$lastScanned=$record;
                $user=(string)($candidate['UF_CHAT_ID']??'');$source=(string)($candidate['UF_SOURCE']??'');
                if(!MaxTrackerLookupProtocol::id($record)||!MaxTrackerLookupProtocol::id($user)
                    ||!MaxTrackerLookupProtocol::id($source)||!in_array($source,$sources,true)){
                    $unknown++;continue;
                }
                try{$candidateAt=self::historyTime($candidate['UF_DATE']??null,$zone,$cutoff);}
                catch(InvalidArgumentException $ignored){$unknown++;continue;}
                $key=$user.':'.$source;
                if(!array_key_exists($key,$decisions)){
                    $pointParams=[$user,$user,(int)$source,$fence];
                    $latest=$this->historySelect('SELECT /* history_timeout */ h.ID,h.UF_TYPE,h.UF_DATE FROM '.$pointFrom
                        .' WHERE '.$pointWhere.' AND h.UF_TYPE IN (3,4) ORDER BY '.$unknownOrder.'h.UF_DATE DESC,h.ID DESC LIMIT 1',
                        $pointParams,1,$started,$bytes);
                    if(!$latest){$decisions[$key]=null;}
                    else{
                        $last=$latest[0];$lastId=(string)($last['ID']??'');$type=(string)($last['UF_TYPE']??'');
                        try{$lastAt=self::historyTime($last['UF_DATE']??null,$zone,$cutoff);}
                        catch(InvalidArgumentException $ignored){$lastAt=null;}
                        if(!MaxTrackerLookupProtocol::id($lastId)||!in_array($type,['3','4'],true)||$lastAt===null)
                            $decisions[$key]=null;
                        else $decisions[$key]=['ID'=>$lastId,'type'=>$type,'date'=>$last['UF_DATE'],'at'=>$lastAt];
                    }
                }
                $last=$decisions[$key];
                if($last===null){$unknown++;continue;}
                if($last['type']!=='3'||$last['ID']!==$record)continue;
                $pointParams=[$user,$user,(int)$source,$fence];
                $left=$this->historySelect('SELECT /* history_timeout */ h.ID,h.UF_DATE FROM '.$pointFrom
                    .' WHERE '.$pointWhere.' AND h.UF_TYPE=4 ORDER BY '.$unknownOrder.'h.UF_DATE DESC,h.ID DESC LIMIT 1',
                    $pointParams,1,$started,$bytes);
                $entryBasis='first_retained_join';$afterLeft='';$joinParams=$pointParams;
                if($left){
                    $leftId=(string)($left[0]['ID']??'');
                    try{$leftAt=self::historyTime($left[0]['UF_DATE']??null,$zone,$cutoff);}
                    catch(InvalidArgumentException $ignored){$unknown++;continue;}
                    if(!MaxTrackerLookupProtocol::id($leftId)||$leftAt>$last['at']
                        ||($leftAt===$last['at']&&self::historyDecimal($leftId,$last['ID'])>=0)){$unknown++;continue;}
                    $entryBasis='after_observed_leave';
                    $afterLeft=' AND (h.UF_DATE>? OR (h.UF_DATE=? AND h.ID>?))';
                    array_push($joinParams,$left[0]['UF_DATE'],$left[0]['UF_DATE'],$leftId);
                }
                $first=$this->historySelect('SELECT /* history_timeout */ h.ID,h.UF_DATE FROM '.$pointFrom
                    .' WHERE '.$pointWhere.' AND h.UF_TYPE=3'.$afterLeft.' ORDER BY h.UF_DATE,h.ID LIMIT 1',
                    $joinParams,1,$started,$bytes);
                if(!$first){$unknown++;continue;}
                $firstId=(string)($first[0]['ID']??'');
                try{$firstAt=self::historyTime($first[0]['UF_DATE']??null,$zone,$cutoff);}
                catch(InvalidArgumentException $ignored){$unknown++;continue;}
                if(!MaxTrackerLookupProtocol::id($firstId)||$firstAt>$last['at']){$unknown++;continue;}
                $rows[]=['external_user_id'=>$user,'source_id'=>$source,'state'=>'observed_subscribed',
                    'last_event_record_id'=>$last['ID'],'last_event_recorded_at_unix'=>$last['at'],
                    'episode_join_record_id'=>$firstId,'episode_join_recorded_at_unix'=>$firstAt,'entry_basis'=>$entryBasis];
            }
            $more=count($candidates)>$scanned;
            $next=$more&&$lastScanned!==null?MaxTrackerLookupProtocol::encodeHistoryCursor($counterId,$cutoff,$fence,$lastScanned,$sourceHash):null;
            self::historyBudget($started,$bytes);
            return self::historyPage($rows,$cutoff,$fence,$next,$more,$scanned,$unknown);
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    }

    private function historySelect(string $sql,array $params,int $limit,int $started,int &$bytes):array
    {
        self::historyBudget($started,$bytes);
        $remaining=(int)floor((6000000000-(hrtime(true)-$started))/1000000);
        if($remaining<1)throw new RuntimeException('tracker_history_budget_exceeded');
        $sql=str_replace('/* history_timeout */','/*+ MAX_EXECUTION_TIME('.min(1000,$remaining).') */',$sql);
        $q=$this->pdo->prepare($sql);
        try{
            foreach($params as$i=>$value)$q->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
            $q->execute();$rows=[];
            while(($row=$q->fetch(PDO::FETCH_ASSOC))!==false){
                if(count($rows)>=$limit)throw new RuntimeException('tracker_history_unavailable');
                foreach($row as$value){if($value!==null)$bytes+=strlen((string)$value);}
                self::historyBudget($started,$bytes);$rows[]=$row;
            }
            self::historyBudget($started,$bytes);
            return $rows;
        }finally{$q->closeCursor();}
    }

    private static function historyBudget(int $started,int $bytes):void
    {
        if(hrtime(true)-$started>6000000000||$bytes>524288)throw new RuntimeException('tracker_history_budget_exceeded');
    }

    private static function historyTime($date,DateTimeZone $zone,int $cutoff):int
    {
        $at=MaxSubscriptionAttributionPolicy::eventTimeUnix($date,$zone);
        if($at<946684800||$at>$cutoff)throw new InvalidArgumentException('tracker_history_clock_unknown');
        return $at;
    }

    private static function historyDecimal(string $a,string $b):int
    {
        return strlen($a)<=>strlen($b)?:strcmp($a,$b);
    }

    private static function historyPage(array $rows,int $cutoff,string $fence,?string $next,bool $more,int $scanned,int $unknown):array
    {
        return ['basis'=>'retained_membership_history_v1','rows'=>$rows,'cutoff_unix'=>$cutoff,'history_fence_id'=>$fence,
            'next_cursor'=>$next,'has_more'=>$more,'scanned_event_count'=>$scanned,'omitted_unknown_count'=>$unknown,
            'coverage'=>'bounded_retained_history_not_live_platform_verification'];
    }

    private function tableName(int $id): string
    {
        $q=$this->pdo->prepare('SELECT TABLE_NAME FROM b_hlblock_entity WHERE ID=?');$q->execute([$id]);$table=(string)$q->fetchColumn();
        if(!preg_match('/\A[a-z0-9_]{1,64}\z/D',$table))throw new RuntimeException('tracker_membership_unavailable');
        return$table;
    }
}

