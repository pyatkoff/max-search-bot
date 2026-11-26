<?php
declare(strict_types=1);

require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';
require_once __DIR__.'/ArchiveResearchFeatureExtractor.php';
require_once __DIR__.'/ArchiveResearchSemanticExtractor.php';
require_once __DIR__.'/ArchivePredictiveStudyStore.php';
require_once __DIR__.'/AdvertisingVisitRepository.php';
require_once __DIR__.'/TelegramBusinessIdentitySummary.php';

/** Private historical research reader. No intake, model, send or read-marker owner. */
final class ArchiveResearchReadService
{
    public const MAX_OUTPUT_BYTES=65536;
    public const MAX_RESPONSE_BYTES=self::MAX_OUTPUT_BYTES;
    private const OPERATOR_OUTPUT_BYTES=2097152;
    public const MAX_PAGE_SOURCE_BYTES=32768;
    public const MAX_RECORD_BYTES=1048576;
    public const CURSOR_TTL=1200;
    private const FEATURE_METADATA_ROWS=1000;
    private const FEATURE_SOURCE_BYTES=1048576;
    private const TIMING_SET_VERSION='observed_role_switch_v1';
    private const MAX_ROWS=500000;
    private const MAX_CHATS=30000;
    private const MAX_BYTES=536870912;
    private const MAX_SECONDS=8.0;
    private float $deadline=0;
    private string $stage='unknown';
    private bool $mysql;
    private array $permissionRows=[];
    private array $accountAliases=[];

    public function __construct(private PDO $pdo, private $clock=null, private $keyProvider=null,private bool $qualityHistory=false)
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['mysql','sqlite'],true)) self::fail('unavailable');
        $this->mysql=$driver==='mysql';
    }

    public function inventory(int $admin,string $connection,array $args=[]): array
    {
        self::arguments($args,['cursor','limit','recent_start'],50);
        if(array_key_exists('recent_start',$args)&&($args['recent_start']!==true||!$this->qualityHistory||isset($args['cursor'])))self::fail('invalid_arguments');
        return $this->read($admin,$connection,function(string $key) use($admin,$connection,$args):array {
            $state=isset($args['cursor'])?$this->decode($args['cursor'],'inventory_or_features',$key,$admin,$connection):null;
            if(in_array($state['k']??null,['feature_inventory','semantic_inventory'],true)&&$state['a']!=='0'&&!isset($this->accountAliases[$state['a']]))self::fail('forbidden');
            $currentFence=$this->fence();$fence=$state['f']??$currentFence;
            if(self::decimalGreater($fence,$currentFence))self::fail('stale_cursor');
            // Explicit quality-only shortcut: no whole-archive summary or chat inventory.
            if(($args['recent_start']??false)===true){
                $this->stage='summary';
                $this->checkRecentSourceLimit($fence,self::MAX_ROWS,false);
                $this->checkRecentSourceLimit($fence,self::MAX_CHATS,true);
                return $this->recentInventory([
                    'v'=>1,'k'=>'recent_inventory','a'=>'0','c'=>'0','f'=>$fence,'n'=>'0','e'=>$this->now()+self::CURSOR_TTL],
                    $args['limit']??25,$key,$admin,$connection);
            }
            if(($state['k']??null)==='recent_inventory')return$this->recentInventory($state,$args['limit']??25,$key,$admin,$connection);
            if(in_array($state['k']??null,['feature_inventory','semantic_inventory'],true))return$this->featureInventory($state,$args['limit']??25,$key,$admin,$connection);
            $expiry=$this->now()+self::CURSOR_TTL;
            $summary=$state===null?$this->summary($fence):null;
            $limit=$args['limit']??25;
            [$rows,$more]=$this->inventoryRows($fence,$state['a']??'0',$state['c']??'0',$limit);$chats=[];
            foreach($rows as$row){
                $a=(string)$row['account_id'];$c=(string)$row['chat_id'];
                $chats[]=['chat_ref'=>$this->encode(['v'=>1,'k'=>'chat','a'=>$a,'c'=>$c,'f'=>$fence,'e'=>$expiry],$key,$admin,$connection),
                    'account_alias'=>$this->accountAliases[$a],'message_records'=>(int)$row['records'],
                    'source_json_bytes'=>null,'source_json_bytes_status'=>'not_scanned','deleted_records'=>(int)$row['deleted_records'],
                    'brand'=>'unknown','trip_count'=>null,'person_identity'=>'unverified'];
                if($this->qualityHistory){$chats[count($chats)-1]['chat_key']=hash('sha256',self::json([$admin,$connection,$a,$c]));$chats[count($chats)-1]['period_clock_metadata']=[
                    'latest_created_at'=>self::time($row['latest_created_at']??null),
                    'latest_version_at'=>self::time($row['latest_version_at']??null),
                    'unknown_created_at_records'=>(int)($row['unknown_created_at_records']??0),
                    'meaning'=>'retained_declared_source_instants_not_historical_availability'];}
            }
            $next=null;
            if($more&&$rows){$last=$rows[count($rows)-1];$next=$this->encode(['v'=>1,'k'=>'inventory','a'=>(string)$last['account_id'],
                'c'=>(string)$last['chat_id'],'f'=>$fence,'e'=>$expiry],$key,$admin,$connection);}
            return $this->base($fence)+['totals'=>$summary['totals']??null,'accounts'=>$summary['accounts']??null,
                'historical_prefix_study'=>ArchivePredictiveStudyStore::summaryForScope(hash('sha256',self::json($this->permissionRows))),
                'summary_not_recomputed'=>$state!==null,'feature_scan'=>$this->featureDescriptor($fence,$key,$admin,$connection),
                'semantic_scan'=>$this->semanticDescriptor($fence,$key,$admin,$connection,$state),
                ...($this->qualityHistory?['newest_retained'=>$this->recentDescriptor($fence,$key,$admin,$connection)]:[]),
                'chats'=>$chats,'has_more'=>$more,'next_cursor'=>$next];
        });
    }

    public function history(int $admin,string $connection,array $args): array
    {
        self::arguments($args,['chat_ref','cursor','limit'],100);
        if(!isset($args['chat_ref'])||!is_string($args['chat_ref'])||$args['chat_ref']==='')self::fail('invalid_arguments');
        return $this->read($admin,$connection,function(string$key)use($admin,$connection,$args):array {
            $chat=$this->decode($args['chat_ref'],'chat_or_exact_message',$key,$admin,$connection);
            $exact=$chat['k']==='exact_message';
            if($exact&&isset($args['cursor']))self::fail('invalid_arguments');
            // Current account denial takes precedence over an older cursor's now-smaller fence.
            if(!isset($this->accountAliases[$chat['a']]))self::fail('forbidden');
            if(self::decimalGreater($chat['f'],$this->fence()))self::fail('stale_cursor');
            $cursor=isset($args['cursor'])?$this->decode($args['cursor'],'history',$key,$admin,$connection):null;
            $ref=hash('sha256',$args['chat_ref']);
            if($cursor&&($cursor['a']!==$chat['a']||$cursor['c']!==$chat['c']||$cursor['f']!==$chat['f']
                ||$cursor['r']!==$ref||$cursor['e']!==$chat['e']))self::fail('stale_cursor');
            [$where,$values]=$this->scope($chat['f'],false);
            $where.=' AND m.account_id=? AND m.chat_id=?';array_push($values,$chat['a'],$chat['c']);
            if($exact){$where.=" AND m.id=? AND m.message_id=? AND m.source_kind='business_message'";array_push($values,$chat['i'],$chat['n']);}
            $this->stage='history_stats';
            $stat=$this->q('SELECT COUNT(*) AS records,COALESCE(SUM('.$this->jsonBytes().'),0) AS bytes '
                .'FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '.$where,$values)->fetch(PDO::FETCH_ASSOC);
            if((int)$stat['records']===0)self::fail('chat_unavailable');
            if((int)$stat['records']>self::MAX_ROWS||(int)$stat['bytes']>self::MAX_BYTES)self::fail('source_limit');
            [$historicalWhere,$historicalValues]=$this->scope($chat['f'],true);
            $this->stage='history_anchor';
            $historical=$this->q('SELECT 1 FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '
                .$historicalWhere.' AND m.account_id=? AND m.chat_id=? LIMIT 1',[...$historicalValues,$chat['a'],$chat['c']])->fetchColumn();
            if($historical===false)self::fail('chat_unavailable');
            $after=$cursor['n']??'0';$limit=$exact?1:($args['limit']??50);
            $jsonValid=$this->mysql?'JSON_VALID(m.record_json)':'json_valid(m.record_json)';
            $botType=$this->mysql?"JSON_TYPE(JSON_EXTRACT(m.record_json,'$.bot_key'))":"json_type(m.record_json,'$.bot_key')";
            $botValue=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT(m.record_json,'$.bot_key'))":"CAST(json_extract(m.record_json,'$.bot_key') AS TEXT)";
            $safe='CASE WHEN '.$this->jsonBytes().'<='.self::MAX_RECORD_BYTES.' THEN CASE WHEN '.$jsonValid.'=1 THEN ';
            $this->stage='history_metadata';
            $metadata=$this->q('SELECT m.id,m.account_id,m.chat_id,m.message_id,m.source_kind,m.version_at,m.is_deleted,'
                .$this->jsonBytes().' AS bytes,'.$safe.'1 ELSE 0 END ELSE 0 END AS valid_json,'
                .$safe.$botType." ELSE NULL END ELSE NULL END AS bot_type,"
                .$safe.'SUBSTR('.$botValue.',1,97) ELSE NULL END ELSE NULL END AS bot_value '
                .'FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '.$where
                .' AND m.message_id>? ORDER BY m.message_id LIMIT '.($limit+1),[...$values,$after])->fetchAll(PDO::FETCH_ASSOC);
            $hasMore=count($metadata)>$limit;$metadata=array_slice($metadata,0,$limit);
            $selected=[];$fetch=[];$bytes=0;
            foreach($metadata as$row){
                $status=$this->metadataStatus($row);
                $size=(int)$row['bytes'];
                if($status==='available'&&$size>self::MAX_PAGE_SOURCE_BYTES)$status='record_exceeds_page_budget';
                if($status==='available'&&$bytes+$size>self::MAX_PAGE_SOURCE_BYTES){$hasMore=true;break;}
                $row['read_status']=$status;$selected[]=$row;
                if($status==='available'){$fetch[]=(string)$row['id'];$bytes+=$size;}
            }
            $raw=[];
            if($fetch){
                $this->stage='history_payload';
                $rows=$this->q('SELECT m.id,m.record_json FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key '
                    .'WHERE '.$where.' AND m.id IN ('.implode(',',array_fill(0,count($fetch),'?')).')',[...$values,...$fetch])->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as$row)$raw[(string)$row['id']]=$row['record_json'];
                foreach($selected as$row)if($row['read_status']==='available'&&(!isset($raw[(string)$row['id']])||strlen($raw[(string)$row['id']])!==(int)$row['bytes']))self::fail('source_changed');
                if(count($raw)!==count($fetch))self::fail('source_changed');
            }
            $messages=[];$omitted=0;$last=$after;
            foreach($selected as$row){
                $item=$this->project($row,$raw[(string)$row['id']]??null);
                $messages[]=$item;$last=(string)$row['message_id'];
                if($item['read_status']!=='available')$omitted++;
            }
            $make=function(array$msgs,string$last,int$skipped,bool$more)use($chat,$ref,$args,$key,$admin,$connection,$stat,$bytes,$exact):array{
                if($exact)$more=false;
                $next=$more?$this->encode(['v'=>1,'k'=>'history','a'=>$chat['a'],'c'=>$chat['c'],'f'=>$chat['f'],
                    'n'=>$last,'r'=>$ref,'e'=>$chat['e']],$key,$admin,$connection):null;
                return $this->base($chat['f'])+['chat_ref'=>$args['chat_ref'],'account_alias'=>$this->accountAliases[$chat['a']],
                    ...($exact?['selection_scope'=>'selected_record_only']:[]),
                    'stored_records_within_fence'=>(int)$stat['records'],'messages'=>$msgs,
                    'page_source_json_bytes'=>$bytes,'page_omitted_records'=>$skipped,'has_more'=>$more,'next_cursor'=>$next,
                    'history_complete'=>false,'page_complete'=>!$more&&$skipped===0,
                    'history_limits'=>['external_messenger_completeness'=>'unknown','new_rows_after_fence'=>'excluded',
                        'current_versions_may_change_between_pages'=>true,'missing_media_contents'=>true,
                        'message_availability_at_historical_prediction'=>'unknown']];
            };
            $result=$make($messages,$last,$omitted,$hasMore);
            $firstSelected=$selected[0]??null;
            while(strlen(self::json($result))>self::MAX_RESPONSE_BYTES&&$messages){
                array_pop($messages);$selected=array_slice($selected,0,count($messages));$hasMore=true;
                $last=$selected?(string)$selected[count($selected)-1]['message_id']:$after;
                $omitted=count(array_filter($messages,static fn($m)=>$m['read_status']!=='available'));
                $result=$make($messages,$last,$omitted,true);
            }
            if(!$messages&&$firstSelected){
                $firstSelected['read_status']='response_record_limit';
                $item=$this->project($firstSelected,null);$last=(string)$firstSelected['message_id'];
                $result=$make([$item],$last,1,count($metadata)>1||$hasMore);
            }
            return $result;
        });
    }

    /** CLI wrapper; authority is supplied by the real operator boundary, never fabricated. */
    public function operatorBusinessIdentitySummary(callable $authorize,string $fence,int $cutoff):array
    {
        if(PHP_SAPI!=='cli')self::fail('forbidden');
        return $this->businessIdentitySummary($authorize,$fence,$cutoff);
    }

    /** Bounded anonymous overlap only. The caller owns current explicit read authorization. */
    public function businessIdentitySummary(callable $authorize,string $fence,int $cutoff):array
    {
        return$this->businessIdentityRead($authorize,$fence,$cutoff);
    }

    /** One complete retained date window or a disjoint decimal-peer suffix of it. */
    public function businessIdentityWindow(callable $authorize,string $fence,int $from,int $cutoff,?string $peerSuffix=null):array
    {
        if($from<946684800||$from>$cutoff||($peerSuffix!==null&&preg_match('/\A[0-9]{1,2}\z/D',$peerSuffix)!==1))self::fail('invalid_arguments');
        return$this->businessIdentityRead($authorize,$fence,$cutoff,$from,$peerSuffix);
    }

    private function businessIdentityRead(callable $authorize,string $fence,int $cutoff,?int $from=null,?string $peerSuffix=null):array
    {
        $now=$this->now();
        if(!$this->qualityHistory)self::fail('forbidden');
        if(!self::decimal($fence,false)||$cutoff<1||$cutoff>$now)self::fail('invalid_arguments');
        $readRows=0;$readBytes=0;$strictPermissions=null;$partitionable=false;
        $read=function(string $sql,array $args=[])use(&$readRows,&$readBytes,&$partitionable):array{
            $q=$this->q($sql,$args);$out=[];
            while($row=$q->fetch(PDO::FETCH_ASSOC)){
                $readRows++;$readBytes+=strlen(self::json($row));$this->budget();
                if($readRows>1001||$readBytes>1048576)self::fail($partitionable?'window_partition_limit':'source_limit');
                $out[]=$row;
            }
            return$out;
        };
        $permissions=function()use($read):array{
            return$read('SELECT a.account_id,a.bot_key,a.connection_id FROM telegram_archive_accounts a '
                .'JOIN telegram_dialogue_connections c ON c.project_key=a.project_key AND HEX(c.project_key)=HEX(a.project_key) '
                .'AND c.bot_key=a.bot_key AND HEX(c.bot_key)=HEX(a.bot_key) '
                .'AND c.connection_id=a.connection_id AND HEX(c.connection_id)=HEX(a.connection_id) '
                .'JOIN projects p ON p.project_key=a.project_key AND HEX(p.project_key)=HEX(a.project_key) '
                ."WHERE a.project_key='anytour' AND HEX(a.project_key)=HEX('anytour') AND p.is_active=1 "
                .'AND a.approved=1 AND a.provider_enabled=1 AND c.is_enabled=1 AND c.account_id=a.account_id '
                .'ORDER BY a.account_id,a.bot_key,a.connection_id LIMIT 101');
        };
        $authority=function()use($authorize,$permissions,&$strictPermissions):void{
            try{$authorize();}catch(Throwable $ignored){self::fail('forbidden');}
            // This second, exact-scope check is outside the original snapshot.
            if($strictPermissions!==null&&$strictPermissions!==$permissions())self::fail('permission_changed');
        };
        return$this->readSnapshot($authority,function()use($read,$permissions,&$strictPermissions,$fence,$cutoff,$now,$from,$peerSuffix,&$readRows,&$readBytes,&$partitionable):array{
            $strictPermissions=$permissions();
            if(!$strictPermissions)self::fail('forbidden');
            if(count($strictPermissions)>100)self::fail('source_limit');
            $accounts=[];
            foreach($strictPermissions as$p){
                if(!self::decimal((string)$p['account_id'],false)||!is_string($p['bot_key'])||$p['bot_key']===''
                    ||strlen($p['bot_key'])>96||!is_string($p['connection_id'])||$p['connection_id']===''||strlen($p['connection_id'])>512)self::fail('unavailable');
                $accounts[(string)$p['account_id']]=true;
            }
            if(self::decimalGreater($fence,$this->fence()))self::fail('source_changed');
            // Only data-dependent window work can be divided into peer suffixes.
            // Global account admission and malformed permissions retain their own stop.
            $partitionable=$from!==null;
            $this->stage='inventory_page';[$where,$values]=$this->scope($fence,false);
            $where.=" AND HEX(m.project_key)=HEX('anytour') AND HEX(p.project_key)=HEX('anytour')"
                ." AND m.source_kind='business_message' AND HEX(m.source_kind)=HEX('business_message')"
                .' AND m.account_id IN ('.implode(',',array_fill(0,count($accounts),'?')).')';
            array_push($values,...array_map('strval',array_keys($accounts)));
            if($peerSuffix!==null){
                $peer='CAST(m.chat_id AS '.($this->mysql?'CHAR':'TEXT').')';
                // Canonical one-digit peers belong to their zero-padded two-digit
                // child, so refining parent "3" never loses peer "3" from "03".
                if(strlen($peerSuffix)===2)$peer=$this->mysql?"CONCAT('0',".$peer.')':"('0'||".$peer.')';
                $where.=' AND HEX(SUBSTR('.$peer.',-'.strlen($peerSuffix).'))=HEX(?)';$values[]=$peerSuffix;
            }
            $selectionLimit=25;$dateFields='';$dateWhere='';$dateValues=[];
            $period=null;
            if($from!==null){
                // Occurrence time exists only in normalized JSON. A version-time
                // prefilter would silently omit corrupt or inconsistent source clocks.
                $created=$this->businessScalar('created_at','integer',12);
                $version=$this->businessScalar('version_at','integer',12);
                $number=$this->mysql?'UNSIGNED':'INTEGER';
                $createdNumber='CAST(('.$created.') AS '.$number.')';
                $versionNumber='CAST(('.$version.') AS '.$number.')';
                $invalid='('.$created.') IS NULL OR '.$createdNumber.'<946684800 OR '.$createdNumber.'>'.$now
                    .' OR ('.$version.') IS NULL OR '.$versionNumber.'<'.$createdNumber.' OR '.$versionNumber.'>'.$now
                    .' OR m.version_at IS NULL OR HEX(CAST(m.version_at AS '.($this->mysql?'CHAR':'TEXT').'))<>HEX(('.$version.'))';
                $dateWhere=' AND (('.$createdNumber.'>=? AND '.$createdNumber.'<=?) OR '.$invalid.')';
                $dateValues=[$from,$cutoff];
                $dateFields=','.$created.' AS selected_created_at,'.$version.' AS selected_version_at';
                // Reserve a second bounded metadata projection for each selected row.
                // Remaining native/visit/permission work still shares the same cap.
                $selectionLimit=max(1,min(501,intdiv(1001-$readRows,2)));
                $iso=static fn(int $at):string=>(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Europe/Moscow'))->format(DATE_ATOM);
                $period=['from_unix'=>$from,'until_unix'=>$cutoff,'from'=>$iso($from),'until'=>$iso($cutoff),
                    'timezone'=>'Europe/Moscow','bounds'=>'inclusive','clock_basis'=>'typed_stored_created_at',
                    'dated_candidate_records'=>0,'unknown_created_at_records'=>0,'invalid_clock_records'=>0,
                    'unknown_clock_scope'=>'retained_business_records_in_partition_not_proven_in_period','external_history_complete'=>false];
            }
            // In window mode the date/unknown-clock predicate applies before LIMIT.
            // Only typed scalars cross SQL; fixed IDs precede all other JSON metadata.
            $selected=$read('SELECT m.id,m.account_id,m.chat_id,m.message_id,m.version_at,m.is_deleted'.$dateFields.' '
                .'FROM telegram_dialogue_messages m'.($this->mysql?' FORCE INDEX (PRIMARY)':'')
                .' JOIN projects p ON p.project_key=m.project_key WHERE '.$where.$dateWhere.' ORDER BY m.id DESC LIMIT '.$selectionLimit,[...$values,...$dateValues]);
            if($from!==null&&count($selected)>=$selectionLimit)self::fail('window_partition_limit');
            if($period!==null)foreach($selected as$row){
                $created=self::time($row['selected_created_at']);$version=self::time($row['selected_version_at']);
                if($created===null||$created<946684800||$created>$now)$period['unknown_created_at_records']++;
                elseif($version===null||$version<$created||$version>$now||(string)$version!==(string)$row['version_at'])$period['invalid_clock_records']++;
                elseif($created>=$from&&$created<=$cutoff)$period['dated_candidate_records']++;
                else self::fail('source_changed');
            }
            $counts=array_fill_keys(['selected_records','verified_inbound_records','verified_outbound_records',
                'excluded_deleted_records','excluded_metadata_records','excluded_permission_records','excluded_service_records',
                'excluded_known_test_records','excluded_role_records','cutoff_advanced_records'],0);
            $counts['selected_records']=count($selected);$ids=[];
            foreach($selected as$row)if((string)$row['is_deleted']==='0')$ids[]=(string)$row['id'];
            $metadata=[];
            if($ids){
                $fields=['m.id'];
                foreach(['account_id'=>32,'chat_id'=>32,'message_id'=>32,'sender_id'=>32,'source_kind'=>32,
                    'sender_type'=>16,'direction'=>16,'bot_key'=>96,'connection_id'=>512]as$key=>$max)
                    $fields[]=$this->businessScalar($key,'string',$max).' AS j_'.$key;
                foreach(['created_at','version_at']as$key)$fields[]=$this->businessScalar($key,'integer',12).' AS j_'.$key;
                $fields[]=$this->businessScalar('is_deleted','boolean',5).' AS j_is_deleted';
                $this->stage='history_metadata';
                foreach($read('SELECT '.implode(',',$fields).' FROM telegram_dialogue_messages m '
                    .'JOIN projects p ON p.project_key=m.project_key WHERE '.$where
                    .' AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).') LIMIT '.count($ids),[...$values,...$ids])as$row){
                    $id=(string)$row['id'];if(isset($metadata[$id]))self::fail('source_changed');$metadata[$id]=$row;
                }
                if(count($metadata)!==count($ids))self::fail('source_changed');
            }
            $pending=[];$peers=[];
            foreach($selected as$row){
                if((string)$row['is_deleted']!=='0'){$counts['excluded_deleted_records']++;continue;}
                $m=$metadata[(string)$row['id']]??[];$valid=true;
                foreach(['account_id','chat_id','message_id']as$key)
                    if(!self::decimal((string)$row[$key],false)||($m['j_'.$key]??null)!==(string)$row[$key])$valid=false;
                $created=self::time($m['j_created_at']??null);$version=self::time($m['j_version_at']??null);
                if(!$valid||($m['j_source_kind']??null)!=='business_message'||!in_array($m['j_is_deleted']??null,[0,'0'],true)
                    ||!is_string($m['j_sender_id']??null)||!self::decimal($m['j_sender_id'],false)
                    ||!is_string($m['j_sender_type']??null)||!is_string($m['j_direction']??null)
                    ||$created===null||$created<946684800||$version===null||$version<$created||$version>$now
                    ||(string)$version!==(string)$row['version_at']){$counts['excluded_metadata_records']++;continue;}
                $permitted=false;
                foreach($strictPermissions as$p)if((string)$p['account_id']===(string)$row['account_id']
                    &&$p['bot_key']===($m['j_bot_key']??null)&&$p['connection_id']===($m['j_connection_id']??null)){$permitted=true;break;}
                if(!$permitted){$counts['excluded_permission_records']++;continue;}
                if($m['j_sender_id']==='777000'||(string)$row['account_id']===(string)$row['chat_id']){$counts['excluded_service_records']++;continue;}
                if($version>$cutoff){$counts['cutoff_advanced_records']++;continue;}
                $pending[]=['account'=>(string)$row['account_id'],'peer'=>(string)$row['chat_id'],'at'=>$created,
                    'sender'=>$m['j_sender_id'],'role'=>$m['j_sender_type'],'direction'=>$m['j_direction']];
                $peers[(string)$row['chat_id']]=true;
            }
            $native=[];
            if($peers){
                $peerIds=array_map('strval',array_keys($peers));$marks=implode(',',array_fill(0,count($peerIds),'?'));
                foreach($read('SELECT cc.external_user_id,MAX(CASE WHEN c.is_test=1 THEN 1 ELSE 0 END) AS known_test '
                    .'FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id '
                    .'AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel) '
                    ."WHERE c.project_key='anytour' AND HEX(c.project_key)=HEX('anytour') AND c.channel='telegram' AND HEX(c.channel)=HEX('telegram') "
                    .'AND HEX(c.external_chat_id)=HEX(cc.external_chat_id) AND HEX(cc.external_user_id)=HEX(cc.external_chat_id) '
                    .'AND cc.external_user_id IN ('.$marks.') AND HEX(cc.external_user_id) IN ('
                    .implode(',',array_fill(0,count($peerIds),'HEX(?)')).') GROUP BY cc.external_user_id LIMIT '.(count($peerIds)+1),[...$peerIds,...$peerIds])as$row){
                    $peer=(string)$row['external_user_id'];if(!in_array($peer,$peerIds,true))self::fail('source_changed');
                    $native[$peer]=(int)$row['known_test'];
                }
            }
            $events=[];$admitted=[];$pairs=[];
            foreach($pending as$row){
                if(($native[$row['peer']]??0)===1){$counts['excluded_known_test_records']++;continue;}
                if($row['role']==='customer'&&$row['direction']==='inbound'&&$row['sender']===$row['peer']){
                    $counts['verified_inbound_records']++;$events[]=['peer'=>$row['peer'],'at'=>$row['at']];
                    $admitted[$row['peer']]=true;$pairs[$row['account'].':'.$row['peer']]=true;
                }elseif($row['role']==='manager'&&$row['direction']==='outbound'&&$row['sender']===$row['account'])$counts['verified_outbound_records']++;
                else$counts['excluded_role_records']++;
            }
            if(array_sum($counts)!==2*$counts['selected_records'])self::fail('source_changed');
            $history=[];$admittedIds=array_map('strval',array_keys($admitted));
            foreach(array_chunk($admittedIds,100)as$batch){
                $limit=1001-$readRows;if($limit<1)self::fail($partitionable?'window_partition_limit':'source_limit');
                $candidates=(new AdvertisingVisitRepository($this->pdo))->entryContextCandidates(
                    ['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'],
                    $batch,$limit,$read,true);
                if(!$candidates['complete'])self::fail($partitionable?'window_partition_limit':'source_limit');
                foreach($candidates['rows']as$row){
                    $peer=(string)$row['external_user_id'];if(!in_array($peer,$batch,true))self::fail('source_changed');
                    $history[$peer][]=$row;
                }
            }
            $overlap=['status'=>'available','basis'=>'current_saved_rows_not_historical_cutoff',
                'native_bot_peers'=>count(array_intersect(array_keys($admitted),array_keys($native))),
                'signal_peers'=>0,'outbox_peers'=>0,'signal_status'=>'available','outbox_status'=>'available'];
            if($admittedIds){
                $marks=implode(',',array_fill(0,count($admittedIds),'?'));
                foreach(['signal'=>['advertising_signals','source_key','occurred_at_unix'],
                    'outbox'=>['max_channel_conversion_outbox','identity_source_key','event_unix']]as$key=>[$table,$source,$clock]){
                    try{
                        $rows=$read('SELECT external_user_id FROM '.$table
                            ." WHERE project_key='anytour' AND HEX(project_key)=HEX('anytour') AND platform='telegram' AND HEX(platform)=HEX('telegram')"
                            ." AND counter_id='98615635' AND HEX(counter_id)=HEX('98615635') AND $source='tgmini' AND HEX($source)=HEX('tgmini')"
                            .' AND '.$clock.'>=946684800 AND '.$clock.'<=? AND external_user_id IN ('.$marks.') AND HEX(external_user_id) IN ('
                            .implode(',',array_fill(0,count($admittedIds),'HEX(?)')).') GROUP BY external_user_id LIMIT '.(count($admittedIds)+1),[$now,...$admittedIds,...$admittedIds]);
                        $found=[];foreach($rows as$r){$id=(string)$r['external_user_id'];if(!in_array($id,$admittedIds,true))self::fail('source_changed');$found[$id]=true;}
                        $overlap[$key.'_peers']=count($found);
                    }catch(PDOException $error){
                        if(!self::businessSchemaUnavailable($error))throw$error;
                        $overlap['status']='partial';$overlap[$key.'_status']='unknown';$overlap[$key.'_peers']=null;
                    }
                }
            }
            $import=['status'=>'available','checkpoint_present'=>false,'cursor_bound'=>false,
                'last_observation_received_at_unix'=>null,'freshness'=>'unknown','history_complete'=>false];
            try{
                $rows=$read('SELECT c.last_cursor,o.source_cursor,o.received_at_unix FROM advertising_visit_cursors c '
                    .'LEFT JOIN advertising_visit_observations o ON HEX(o.project_key)=HEX(c.project_key) AND HEX(o.source_key)=HEX(c.source_key) '
                    .'AND HEX(o.platform)=HEX(c.platform) AND HEX(o.counter_id)=HEX(c.counter_id) AND HEX(o.source_cursor)=HEX(c.last_cursor) '
                    ."WHERE c.project_key='anytour' AND HEX(c.project_key)=HEX('anytour') AND c.source_key='tgmini' AND HEX(c.source_key)=HEX('tgmini') "
                    ."AND c.platform='telegram' AND HEX(c.platform)=HEX('telegram') AND c.counter_id='98615635' AND HEX(c.counter_id)=HEX('98615635') LIMIT 2");
                if(count($rows)>1)self::fail('source_changed');
                if($rows){
                    $r=$rows[0];$import['checkpoint_present']=true;$received=self::time($r['received_at_unix']);
                    $import['cursor_bound']=self::decimal((string)$r['last_cursor'],false)
                        &&(string)$r['last_cursor']===(string)$r['source_cursor']&&$received!==null&&$received<=$now;
                    if($import['cursor_bound'])$import['last_observation_received_at_unix']=$received;
                }
            }catch(PDOException $error){
                if(!self::businessSchemaUnavailable($error))throw$error;
                $import['status']='unknown';$import['checkpoint_present']=$import['cursor_bound']=null;
            }
            $result=['ok'=>true,'schema_version'=>1,'operation'=>'business_identity_summary','project_key'=>'anytour',
                'snapshot_at_unix'=>$now,'cutoff_at_unix'=>$cutoff,
                'selection'=>['fence'=>$fence,'limit'=>25,'order'=>'archive_record_id_desc','historical_snapshot'=>false,
                    'meaning'=>'fixed_ids_current_retained_versions; versions_after_cutoff_excluded'],
                'coverage'=>['brand'=>'unknown','legacy_non_test'=>'unknown','history_complete'=>false,
                    'scope'=>'currently_approved_account_bot_connection_in_anytour_registry','owner_service_exclusion'=>'applied',
                    'native_test_exclusion'=>'applied','qualified_conversion_total'=>null],
                'counts'=>$counts,'distinct'=>['account_chat_pairs'=>count($pairs),'telegram_peers'=>count($admitted)],
                'identities'=>TelegramBusinessIdentitySummary::aggregate($events,$history,$now),'overlap'=>$overlap,'import'=>$import,
                'budgets'=>['max_projected_rows'=>1001,'max_projected_bytes'=>1048576,'max_read_seconds'=>self::MAX_SECONDS],
                'text_materialized'=>false,'model_calls'=>0,'database_writes'=>0,'conversion_uploads'=>0];
            if($period!==null){
                $result['operation']='business_identity_window_summary';$result['period']=$period;
                $result['selection']['limit']=$selectionLimit;$result['selection']['complete']=true;
                $result['selection']['meaning']='complete_retained_date_candidates_and_unknown_clocks; current_versions_not_historical_snapshot';
                $result['partition']=['peer_suffix'=>$peerSuffix,'basis'=>'zero_padded_decimal_chat_id_suffix','single_snapshot'=>true];
                $result['scope_fingerprint']=hash('sha256',self::json($strictPermissions));
            }
            return$result;
        },$authority,self::MAX_RESPONSE_BYTES);
    }

    private static function businessSchemaUnavailable(PDOException $error):bool
    {
        if(substr((string)$error->getCode(),0,2)==='42')return true;
        // SQLite reports missing optional tables under HY000; do not hide other storage errors.
        return (string)$error->getCode()==='HY000'&&(int)($error->errorInfo[1]??0)===1
            &&preg_match('/\bno such table: (?:advertising_signals|max_channel_conversion_outbox|advertising_visit_cursors)\b/',$error->getMessage())===1;
    }

    /** SQL extracts typed, byte-bounded scalars after the selected IDs have been fixed. */
    private function businessScalar(string $key,string $type,int $max):string
    {
        $valid=$this->mysql?'JSON_VALID(m.record_json)':'json_valid(m.record_json)';
        $doc='CASE WHEN '.$this->jsonBytes().'<='.self::MAX_RECORD_BYTES.' AND '.$valid."=1 THEN m.record_json ELSE '{}' END";
        $path="'$.".$key."'";
        $kind=$this->mysql?'JSON_TYPE(JSON_EXTRACT('.$doc.','.$path.'))':'json_type('.$doc.','.$path.')';
        $value=$this->mysql?'JSON_UNQUOTE(JSON_EXTRACT('.$doc.','.$path.'))':'CAST(json_extract('.$doc.','.$path.') AS TEXT)';
        if($type==='boolean')return$this->mysql?"CASE WHEN $kind='BOOLEAN' THEN CASE $value WHEN 'false' THEN 0 WHEN 'true' THEN 1 ELSE NULL END ELSE NULL END"
            :"CASE WHEN $kind='false' THEN 0 WHEN $kind='true' THEN 1 ELSE NULL END";
        $expected=$this->mysql?strtoupper($type):($type==='string'?'text':'integer');
        $bytes=$this->mysql?'LENGTH('.$value.')':'LENGTH(CAST('.$value.' AS BLOB))';
        return"CASE WHEN $kind='$expected' AND $bytes<=$max THEN $value ELSE NULL END";
    }

    /**
     * Internal finite-study source page. The CLI supplies its real authority guard;
     * no OAuth identity, bearer, token or connection is manufactured here.
     * Native positions and projected text are private process data, never MCP output.
     */
    public function operatorStudyPage(callable$authorize,array$position=[],int$limit=25):array
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        if(PHP_SAPI!=='cli')self::fail('forbidden');
        if($limit<1||$limit>50)self::fail('invalid_arguments');
        $initial=$position===[];
        if(!$initial){
            $keys=['f','a','c','n','scope_digest'];
            if(count($position)!==5||array_diff(array_keys($position),$keys))self::fail('invalid_arguments');
            foreach(['f','a','c','n']as$field)if(!is_string($position[$field])||!self::decimal($position[$field],true))self::fail('invalid_arguments');
            if(!is_string($position['scope_digest'])||preg_match('/\A[a-f0-9]{64}\z/D',$position['scope_digest'])!==1
                ||(($position['a']==='0'||$position['c']==='0')&&($position['a']!=='0'||$position['c']!=='0'||$position['n']!=='0')))
                self::fail('invalid_arguments');
        }
        $authority=static function()use($authorize):void{
            try{$authorize();}catch(Throwable$ignored){self::fail('forbidden');}
        };
        return$this->readSnapshot($authority,function()use($position,$limit,$initial):array{
            $scopeDigest=hash('sha256',self::json($this->permissionRows));
            if(!$initial){
                if($position['a']!=='0'&&!isset($this->accountAliases[$position['a']]))self::fail('forbidden');
                if(!hash_equals($scopeDigest,$position['scope_digest']))self::fail('permission_changed');
            }
            $currentFence=$this->fence();$fence=$initial?$currentFence:$position['f'];
            if(self::decimalGreater($fence,$currentFence))self::fail('source_changed');
            $expected=null;
            if($initial){$summary=$this->summary($fence);$expected=['desktop_records'=>$summary['totals']['message_records'],
                'account_chat_pairs'=>$summary['totals']['account_chat_pairs']];}
            $cursor=$initial?['a'=>'0','c'=>'0','n'=>'0']:array_intersect_key($position,array_fill_keys(['a','c','n'],true));
            $current=$cursor['a']!=='0';$anchor=$current;$windows=[];$metadataRows=0;$sourceBytes=0;$exhausted=false;
            while(count($windows)<$limit&&$metadataRows<self::FEATURE_METADATA_ROWS){
                $this->budget();
                if(!$current){
                    $chat=$this->featureNextChat($fence,$cursor['a'],$cursor['c']);
                    if($chat===null){$exhausted=true;break;}
                    $metadataRows++;$cursor=['a'=>$chat['a'],'c'=>$chat['c'],'n'=>'0'];$current=true;$anchor=false;
                }
                if($anchor){
                    $this->stage='history_anchor';[$where,$values]=$this->scope($fence,true);
                    $found=$this->q('SELECT 1 FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '
                        .$where.' AND m.account_id=? AND m.chat_id=? LIMIT 1',[...$values,$cursor['a'],$cursor['c']])->fetchColumn();
                    if($found===false){$current=false;$anchor=false;continue;}
                    $metadataRows++;$anchor=false;
                }
                $remaining=self::FEATURE_METADATA_ROWS-$metadataRows;if($remaining<1)break;
                $windowLimit=min(100,$remaining);$metadata=$this->featureMetadata($fence,$cursor,$windowLimit);$metadataRows+=count($metadata);
                if(!$metadata){$current=false;continue;}
                $selected=[];$fetch=[];$pageBytes=0;
                foreach($metadata as$row){
                    $status=$this->metadataStatus($row);$size=(int)$row['bytes'];
                    if($status==='available'&&$sourceBytes+$pageBytes+$size>self::FEATURE_SOURCE_BYTES)break;
                    $row['read_status']=$status;$selected[]=$row;
                    if($status==='available'){$fetch[]=(string)$row['id'];$pageBytes+=$size;}
                }
                if(!$selected)break;
                $raw=[];
                if($fetch){
                    $this->stage='history_payload';[$where,$values]=$this->scope($fence,false);
                    $rows=$this->q('SELECT m.id,m.record_json FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '
                        .$where.' AND m.account_id=? AND m.chat_id=? AND m.id IN ('.implode(',',array_fill(0,count($fetch),'?')).')',
                        [...$values,$cursor['a'],$cursor['c'],...$fetch])->fetchAll(PDO::FETCH_ASSOC);
                    foreach($rows as$row)$raw[(string)$row['id']]=$row['record_json'];
                    if(count($raw)!==count($fetch))self::fail('source_changed');
                    foreach($selected as$row)if($row['read_status']==='available'
                        &&(!isset($raw[(string)$row['id']])||strlen($raw[(string)$row['id']])!==(int)$row['bytes']))self::fail('source_changed');
                }
                $messages=[];
                foreach($selected as$row){$this->budget();$messages[]=$this->project($row,$raw[(string)$row['id']]??null);}
                $complete=count($selected)===count($metadata)&&count($metadata)<$windowLimit;
                $last=$selected[count($selected)-1];$cursor=['a'=>(string)$last['account_id'],'c'=>(string)$last['chat_id'],'n'=>(string)$last['message_id']];
                $windows[]=['position'=>$cursor,'selected'=>$selected,'result'=>['account_id'=>$cursor['a'],'chat_id'=>$cursor['c'],
                    'account_alias'=>$this->accountAliases[$cursor['a']],'chat_window_complete'=>$complete,'messages'=>$messages]];
                $sourceBytes+=$pageBytes;$current=!$complete;
                if(count($selected)<count($metadata)||$sourceBytes>=self::FEATURE_SOURCE_BYTES)break;
            }
            $make=function(array$items,bool$more)use($fence,$scopeDigest,$expected,$metadataRows,$sourceBytes,$position,$initial):array{
                $last=$items?$items[count($items)-1]['position']:($initial?['a'=>'0','c'=>'0','n'=>'0']:$position);
                return['project_key'=>'anytour','source_fence'=>$fence,'scope_digest'=>$scopeDigest,'expected'=>$expected,
                    'windows'=>array_column($items,'result'),'next_position'=>$more?['f'=>$fence,'a'=>$last['a'],'c'=>$last['c'],'n'=>$last['n'],'scope_digest'=>$scopeDigest]:null,
                    'has_more'=>$more,'metadata_rows'=>$metadataRows,'source_json_bytes'=>$sourceBytes,
                    'returned_records'=>array_sum(array_map(static fn(array$w):int=>count($w['result']['messages']),$items))];
            };
            if(!$windows&&!$exhausted)self::fail('source_limit');
            $result=$make($windows,!$exhausted);
            // Only the returned prefix advances; reread trimmed suffixes on the next page.
            while(strlen(self::json($result))>self::OPERATOR_OUTPUT_BYTES){
                $this->budget();
                if(count($windows)>1){array_pop($windows);$result=$make($windows,true);continue;}
                $messages=&$windows[0]['result']['messages'];
                if(count($messages)>1){
                    array_pop($messages);array_pop($windows[0]['selected']);$last=$messages[count($messages)-1];
                    $windows[0]['position']['n']=$last['native_message_id'];$windows[0]['result']['chat_window_complete']=false;
                }else{
                    // Same explicit unknown-record fallback used by bounded public history reads.
                    $row=$windows[0]['selected'][0];$row['read_status']='response_record_limit';
                    $messages=[$this->project($row,null)];$windows[0]['result']['chat_window_complete']=false;
                }
                unset($messages);$result=$make($windows,true);
                if(count($windows)===1&&count($windows[0]['result']['messages'])===1
                    &&strlen(self::json($result))>self::OPERATOR_OUTPUT_BYTES)self::fail('response_limit');
            }
            return$result;
        },$authority,self::OPERATOR_OUTPUT_BYTES);
    }

    private function read(int$admin,string$connection,callable$operation):array
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        if($admin<1||$connection===''||strlen($connection)>128||!is_callable($this->keyProvider))self::fail('forbidden');
        $key=null;
        return$this->readSnapshot(
            function()use($admin,$connection,&$key):void{$key=$this->key($admin,$connection);},
            function()use($operation,&$key):array{return$operation($key);},
            function()use($admin,$connection,&$key):void{
                $freshKey=$this->key($admin,$connection);if(!hash_equals($key,$freshKey))self::fail('stale_cursor');
            },self::MAX_RESPONSE_BYTES);
    }

    private function readSnapshot(callable$before,callable$operation,callable$after,int$outputCap):array
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        $this->deadline=microtime(true)+self::MAX_SECONDS;$owned=false;$queryOnly=null;
        try{
            $this->stage='key_before';$before();
            $this->stage='transaction_begin';
            if($this->mysql){
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            }else{
                $queryOnly=(int)$this->pdo->query('PRAGMA query_only')->fetchColumn();
                $this->pdo->exec('PRAGMA query_only=ON');$this->pdo->beginTransaction();
            }
            $owned=true;$this->stage='permissions_before';$this->permissionRows=$this->permissions();$original=$this->permissionRows;
            if(!$original)self::fail('forbidden');
            $this->accountAliases=[];foreach($original as$row){$id=(string)$row['account_id'];if(!isset($this->accountAliases[$id]))$this->accountAliases[$id]='A'.(count($this->accountAliases)+1);}
            $result=$operation();
            $this->stage='rollback';$this->pdo->rollBack();$owned=false;
            if($queryOnly!==null){$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));$queryOnly=null;}
            // Outside the old consistent snapshot: revocations cannot use its old permission rows.
            $this->stage='key_after';$after();
            $this->stage='permissions_after';if($original!==$this->permissions())self::fail('permission_changed');
            $this->stage='response';$this->budget();
            if(strlen(self::json($result))>$outputCap)self::fail('response_limit');
            return$result;
        }catch(ArchiveResearchReadException$e){
            throw $e->stage==='unknown'?new ArchiveResearchReadException($e->reason,$this->stage,$e->sqlstateClass,$e->driverCode):$e;
        }catch(PDOException$e){throw self::databaseError($e,$this->stage);}
        catch(DomainException$e){throw new ArchiveResearchReadException($this->stage==='key_before'?'forbidden':($this->stage==='key_after'?'permission_changed':'unavailable'),$this->stage);}
        catch(Throwable$e){throw new ArchiveResearchReadException('unavailable',$this->stage);}
        finally{
            try{
                if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();
                if($queryOnly!==null)$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));
            }catch(PDOException$e){throw self::databaseError($e,'rollback');}
            finally{$this->permissionRows=[];$this->accountAliases=[];}
        }
    }

    private function permissions():array
    {
        $rows=$this->q('SELECT a.account_id,a.bot_key,a.connection_id FROM telegram_archive_accounts a '
            .'JOIN telegram_dialogue_connections c ON c.project_key=a.project_key AND c.bot_key=a.bot_key AND c.connection_id=a.connection_id '
            .'JOIN projects p ON p.project_key=a.project_key WHERE a.project_key=? AND p.is_active=1 AND a.approved=1 '
            .'AND a.provider_enabled=1 AND c.is_enabled=1 AND c.account_id=a.account_id ORDER BY a.account_id,a.bot_key,a.connection_id LIMIT 101',['anytour'])->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>100)self::fail('source_limit');
        foreach($rows as$row)if(!self::decimal((string)$row['account_id'],false)||!is_string($row['bot_key'])||$row['bot_key']===''
            ||strlen($row['bot_key'])>96||!is_string($row['connection_id'])||$row['connection_id']==='')self::fail('unavailable');
        return$rows;
    }

    private function scope(string$fence,bool$historical):array
    {
        $ids=array_keys($this->accountAliases);
        if(!$ids)self::fail('forbidden');
        return["m.project_key=? AND p.is_active=1 AND m.account_id IN (".implode(',',array_fill(0,count($ids),'?')).')'
            .' AND m.id<=? AND m.chat_id<>777000 AND NOT '.InternalOwnerDialogueScope::archiveSql('m')
            .($historical&&!$this->qualityHistory?" AND m.source_kind='desktop_import'":" AND m.source_kind IN ('desktop_import','business_message')"),
            ['anytour',...$ids,$fence]];
    }

    private function fence():string
    {
        $this->stage='fence';$ids=array_keys($this->accountAliases);
        if(!$ids)self::fail('forbidden');
        // A cheap row-ID upper bound is not admission. Every data query still uses scope().
        return(string)$this->q('SELECT COALESCE(MAX(m.id),0) FROM telegram_dialogue_messages m '
            .'WHERE m.project_key=? AND m.account_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',
            ['anytour',...$ids])->fetchColumn();
    }

    /** Prove the historical source cap without computing per-account totals. */
    private function checkRecentSourceLimit(string $fence,int $limit,bool $distinctChats): void
    {
        // An unsigned unique ID in [0, fence] bounds both rows and pairs.
        if($this->mysql && self::decimalGreater((string)$limit,$fence))return;

        $accounts=array_keys($this->accountAliases);
        $placeholders=implode(',',array_fill(0,count($accounts),'?'));
        $select=$distinctChats?'DISTINCT m.project_key,m.account_id,m.chat_id':'m.id';
        $from=' FROM telegram_dialogue_messages m';
        $indexHint=$distinctChats && $this->mysql?' FORCE INDEX (uq_telegram_dialogue_message)':'';
        $boundary=' LIMIT 1 OFFSET '.$limit;

        // Check the first excess row/key in an indexed superset in this snapshot.
        // Access, source-kind and owner exclusions can only reduce the true size.
        $probe=$this->q('SELECT '.$select.$from.$indexHint.' WHERE m.project_key=? AND m.account_id IN ('.$placeholders.')'
            .$boundary,['anytour',...$accounts]);
        if($probe->fetchColumn()===false)return;

        // The superset alone can never deny an otherwise admissible archive.
        [$where,$values]=$this->scope($fence,true);
        $excess=$this->q('SELECT '.$select.$from.' JOIN projects p ON p.project_key=m.project_key WHERE '.$where
            .$boundary,$values);
        if($excess->fetchColumn()!==false)self::fail('source_limit');
    }

    private function summary(string$fence):array
    {
        $this->stage='summary';[$where,$values]=$this->scope($fence,true);
        // Inventory counts metadata only; no whole-archive LONGTEXT byte scan.
        $rows=$this->q('SELECT m.account_id,COUNT(*) AS records,COUNT(DISTINCT m.chat_id) AS chats '
            .'FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '.$where
            .' GROUP BY m.account_id ORDER BY m.account_id',$values)->fetchAll(PDO::FETCH_ASSOC);
        $totals=['message_records'=>0,'account_chat_pairs'=>0,'source_json_bytes'=>null,'source_json_bytes_status'=>'not_scanned'];$accounts=[];
        foreach($rows as$row){
            $totals['message_records']+=(int)$row['records'];$totals['account_chat_pairs']+=(int)$row['chats'];
            $accounts[]=['account_alias'=>$this->accountAliases[(string)$row['account_id']],'message_records'=>(int)$row['records'],
                'account_chat_pairs'=>(int)$row['chats'],'source_json_bytes'=>null,'source_json_bytes_status'=>'not_scanned','brand'=>'unknown'];
        }
        if($totals['message_records']>self::MAX_ROWS||$totals['account_chat_pairs']>self::MAX_CHATS)self::fail('source_limit');
        return['totals'=>$totals,'accounts'=>$accounts];
    }

    /** Seek distinct chat keys first; aggregate only the selected page, never the archive tail. */
    private function inventoryRows(string$fence,string$afterAccount,string$afterChat,int$limit):array
    {
        $this->stage='inventory_page';[$where,$values]=$this->scope($fence,true);
        $from='FROM telegram_dialogue_messages m'.($this->mysql?' FORCE INDEX (uq_telegram_dialogue_message)':'')
            .' JOIN projects p ON p.project_key=m.project_key ';
        $keys=[];
        foreach(array_keys($this->accountAliases)as$account){
            $account=(string)$account;
            if(self::decimalGreater($afterAccount,$account))continue;
            $after=$account===$afterAccount?$afterChat:'0';
            while(count($keys)<=$limit){
                // Admission, owner/service exclusion and the row fence are applied before LIMIT.
                $row=$this->q('SELECT m.account_id,m.chat_id '.$from.'WHERE '.$where
                    .' AND m.account_id=? AND m.chat_id>? ORDER BY m.chat_id,m.message_id LIMIT 1',
                    [...$values,$account,$after])->fetch(PDO::FETCH_ASSOC);
                if($row===false)break;
                $after=(string)$row['chat_id'];$keys[]=$row;
                if(count($keys)>$limit)break 2;
            }
        }
        $more=count($keys)>$limit;$keys=array_slice($keys,0,$limit);$selected=[];$metadata=[];
        foreach($keys as$row)$selected[(string)$row['account_id']][]=(string)$row['chat_id'];
        foreach($selected as$account=>$chats){
            $clockSelect='';
            if($this->qualityHistory){
                $created=$this->mysql
                    ? "CASE WHEN JSON_VALID(m.record_json)=1 THEN CASE WHEN JSON_TYPE(JSON_EXTRACT(m.record_json,'$.created_at'))='INTEGER' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(m.record_json,'$.created_at')) AS UNSIGNED) ELSE NULL END ELSE NULL END"
                    : "CASE WHEN json_valid(m.record_json)=1 THEN CASE WHEN json_type(m.record_json,'$.created_at')='integer' THEN json_extract(m.record_json,'$.created_at') ELSE NULL END ELSE NULL END";
                $created='CASE WHEN ('.$created.')>0 AND ('.$created.')<=253402300799 THEN ('.$created.') ELSE NULL END';
                $clockSelect=',MAX('.$created.') AS latest_created_at,MAX(m.version_at) AS latest_version_at,SUM(CASE WHEN ('.$created.') IS NULL THEN 1 ELSE 0 END) AS unknown_created_at_records';
            }
            $rows=$this->q('SELECT m.account_id,m.chat_id,COUNT(*) AS records,'
                .'COALESCE(SUM(m.is_deleted<>0),0) AS deleted_records '.$clockSelect.' '.$from.'WHERE '.$where
                .' AND m.account_id=? AND m.chat_id IN ('.implode(',',array_fill(0,count($chats),'?')).')'
                .' GROUP BY m.account_id,m.chat_id ORDER BY m.chat_id',[...$values,(string)$account,...$chats])->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as$row)$metadata[(string)$row['account_id'].':'.(string)$row['chat_id']]=$row;
        }
        $rows=[];
        foreach($keys as$key){
            $id=(string)$key['account_id'].':'.(string)$key['chat_id'];
            if(!isset($metadata[$id]))self::fail('source_changed');
            $rows[]=$metadata[$id];
        }
        return[$rows,$more];
    }

    /** Newly inserted row order is not a recording clock or an inventory of late edits. */
    private function recentDescriptor(string$fence,string$key,int$admin,string$connection):array
    {
        return['operation'=>'recent_business_message_inventory','order'=>'archive_record_id_desc',
            'clock_basis'=>'stored_record_order_not_receipt_time','existing_row_edits_are_not_reordered'=>true,
            'latest_activity_inventory'=>false,'source_kind'=>'business_message','text_materialized'=>false,
            'payload_admission'=>'checked_on_context_read',
            'start_cursor'=>$this->encode(['v'=>1,'k'=>'recent_inventory','a'=>'0','c'=>'0','f'=>$fence,
                'n'=>'0','e'=>$this->now()+self::CURSOR_TTL],$key,$admin,$connection)];
    }

    private function recentInventory(array$state,int$limit,string$key,int$admin,string$connection):array
    {
        if(!$this->qualityHistory)self::fail('stale_cursor');
        $this->stage='inventory_page';[$where,$values]=$this->scope($state['f'],false);
        $where.=" AND m.source_kind='business_message'";
        if($state['n']!=='0'){$where.=' AND m.id<?';$values[]=$state['n'];}
        // Scope and the original upper fence apply before LIMIT. No source JSON is materialized.
        $rows=$this->q('SELECT m.id,m.account_id,m.chat_id,m.message_id,m.source_kind,m.version_at,m.is_deleted '
            .'FROM telegram_dialogue_messages m'.($this->mysql?' FORCE INDEX (PRIMARY)':'')
            .' JOIN projects p ON p.project_key=m.project_key WHERE '.$where
            .' ORDER BY m.id DESC LIMIT '.($limit+1),$values)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$records=[];$expiry=$this->now()+self::CURSOR_TTL;
        foreach($rows as$row){
            $records[]=['message_ref'=>$this->encode(['v'=>1,'k'=>'exact_message','a'=>(string)$row['account_id'],
                'c'=>(string)$row['chat_id'],'f'=>$state['f'],'i'=>(string)$row['id'],'n'=>(string)$row['message_id'],'e'=>$expiry],$key,$admin,$connection),
                'source_ref'=>'archive_message:'.(string)$row['id'],'account_alias'=>$this->accountAliases[(string)$row['account_id']],
                'source_kind'=>'business_message','version_at'=>self::time($row['version_at']),'deleted'=>(int)$row['is_deleted']!==0,
                'selection_scope'=>'selected_record_only'];
        }
        $next=null;
        if($more&&$rows)$next=$this->encode(['v'=>1,'k'=>'recent_inventory','a'=>'0','c'=>'0','f'=>$state['f'],
            'n'=>(string)$rows[count($rows)-1]['id'],'e'=>$expiry],$key,$admin,$connection);
        return$this->base($state['f'])+['operation'=>'recent_business_message_inventory','order'=>'archive_record_id_desc',
            'clock_basis'=>'stored_record_order_not_receipt_time','existing_row_edits_are_not_reordered'=>true,
            'latest_activity_inventory'=>false,'text_materialized'=>false,'selection_scope'=>'selected_records_only',
            'payload_admission'=>'checked_on_context_read',
            'recent_business_records'=>$records,'has_more'=>$more,'next_cursor'=>$next];
    }

    private function featureDescriptor(string$fence,string$key,int$admin,string$connection):array
    {
        return['operation'=>'lexical_feature_inventory','invoked'=>false,'explicit_stored_text_read'=>true,
            'pattern_set_version'=>ArchiveResearchFeatureExtractor::PATTERN_SET_VERSION,'timing_set_version'=>self::TIMING_SET_VERSION,
            'semantics'=>'lexical_candidates_not_events_or_probabilities','media_contents_read'=>false,'model_calls'=>0,
            'budgets'=>['max_chat_windows'=>50,'max_metadata_rows'=>self::FEATURE_METADATA_ROWS,
                'max_source_json_bytes'=>self::FEATURE_SOURCE_BYTES,'max_record_bytes'=>self::MAX_RECORD_BYTES,
                'max_output_bytes'=>self::MAX_RESPONSE_BYTES,'max_read_seconds'=>self::MAX_SECONDS,'max_statement_ms'=>5000,
                'max_pattern_text_bytes'=>ArchiveResearchFeatureExtractor::MAX_TEXT_BYTES,'max_matched_evidence'=>8,'max_control_samples'=>4,'max_fragment_bytes'=>384],
            'start_cursor'=>$this->encode(['v'=>1,'k'=>'feature_inventory','a'=>'0','c'=>'0','n'=>'0','f'=>$fence,
                'x'=>bin2hex(random_bytes(16)),'e'=>$this->now()+self::CURSOR_TTL],$key,$admin,$connection)];
    }

    /** Explicit opt-in: contextual rules, not model predictions or authoritative business state. */
    private function semanticDescriptor(string$fence,string$key,int$admin,string$connection,?array$checkpoint=null):array
    {
        $descriptor=['operation'=>'contextual_semantic_inventory','invoked'=>false,'explicit_stored_text_read'=>true,
            'label_set_version'=>ArchiveResearchSemanticExtractor::VERSION,'rule_revision'=>ArchiveResearchSemanticExtractor::RULE_REVISION,
            'semantics'=>'contextual_rule_candidates_not_verified_outcomes',
            'model_calls'=>0,'database_writes'=>0,'media_contents_read'=>false,
            'budgets'=>['max_chat_windows'=>50,'max_metadata_rows'=>self::FEATURE_METADATA_ROWS,'max_window_records'=>100,
                'max_source_json_bytes'=>self::FEATURE_SOURCE_BYTES,'max_output_bytes'=>self::MAX_RESPONSE_BYTES,
                'max_read_seconds'=>self::MAX_SECONDS,'max_statement_ms'=>5000,'max_state_bytes'=>1024,'max_evidence'=>12],
            'start_cursor'=>$this->encode(['v'=>1,'k'=>'semantic_inventory','a'=>'0','c'=>'0','n'=>'0','f'=>$fence,
                'x'=>bin2hex(random_bytes(16)),'s'=>ArchiveResearchSemanticExtractor::initialState(),
                'e'=>$this->now()+self::CURSOR_TTL],$key,$admin,$connection)];
        if(($checkpoint['k']??null)==='inventory'&&$checkpoint['a']!=='0'&&$checkpoint['c']!=='0'
            &&isset($this->accountAliases[$checkpoint['a']])){
            $bound=[$checkpoint['a'],$checkpoint['c']];
            $descriptor['resume_from_page']=['start_cursor'=>$this->encode(['v'=>1,'k'=>'semantic_inventory',
                'a'=>$bound[0],'c'=>$bound[1],'n'=>'0','f'=>$fence,'x'=>bin2hex(random_bytes(16)),
                's'=>ArchiveResearchSemanticExtractor::initialState(),'b'=>$bound,'e'=>$this->now()+self::CURSOR_TTL],$key,$admin,$connection),
                'range'=>'this_metadata_page_and_following_chats','whole_archive'=>false,'context'=>'fresh_at_next_chat'];
        }
        return$descriptor;
    }

    /** Explicit stored-text mode only. Cursor fields are positions, never authorization or cumulative counts. */
    private function featureInventory(array$state,int$limit,string$key,int$admin,string$connection):array
    {
        if($state['a']!=='0'&&!isset($this->accountAliases[$state['a']]))self::fail('forbidden');
        $semantic=$state['k']==='semantic_inventory';$semanticState=$semantic?$state['s']:null;
        $position=['a'=>$state['a'],'c'=>$state['c'],'n'=>$state['n']];$current=$state['a']!=='0';
        // The metadata checkpoint is exclusive; its chat's money/context must not enter the suffix.
        if($semantic&&isset($state['b'])&&$state['n']==='0'&&[$state['a'],$state['c']]===$state['b'])$current=false;
        $windows=[];$metadataRows=0;$sourceBytes=0;$processed=0;$evidenceLeft=8;$controlsLeft=4;$exhausted=false;
        $anchor=$current;$expiry=$this->now()+self::CURSOR_TTL;$semanticEvidenceLeft=12;
        while(count($windows)<$limit&&$metadataRows<self::FEATURE_METADATA_ROWS){
            if(!$current){
                $chat=$this->featureNextChat($state['f'],$position['a'],$position['c']);
                if($chat===null){$exhausted=true;break;}
                $metadataRows++;$position=['a'=>$chat['a'],'c'=>$chat['c'],'n'=>'0'];$current=true;$anchor=false;
                if($semantic)$semanticState=ArchiveResearchSemanticExtractor::initialState();
            }
            if($anchor){
                $this->stage='history_anchor';[$where,$values]=$this->scope($state['f'],true);
                $found=$this->q('SELECT 1 FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '
                    .$where.' AND m.account_id=? AND m.chat_id=? LIMIT 1',[...$values,$position['a'],$position['c']])->fetchColumn();
                if($found===false){$current=false;$anchor=false;continue;}
                $metadataRows++;$anchor=false;
            }
            $remaining=self::FEATURE_METADATA_ROWS-$metadataRows;if($remaining<1)break;
            $windowLimit=$semantic?min(100,$remaining):$remaining;
            $metadata=$this->featureMetadata($state['f'],$position,$windowLimit);$metadataRows+=count($metadata);
            if(!$metadata){$current=false;continue;}
            $selected=[];$fetch=[];$pageBytes=0;
            foreach($metadata as$row){
                $status=$this->metadataStatus($row);$size=(int)$row['bytes'];
                if($status==='available'&&$sourceBytes+$pageBytes+$size>self::FEATURE_SOURCE_BYTES)break;
                $row['read_status']=$status;$selected[]=$row;
                if($status==='available'){$fetch[]=(string)$row['id'];$pageBytes+=$size;}
            }
            if(!$selected)break;
            $raw=[];
            if($fetch){
                $this->stage='history_payload';[$where,$values]=$this->scope($state['f'],false);
                $rows=$this->q('SELECT m.id,m.record_json FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '
                    .$where.' AND m.account_id=? AND m.chat_id=? AND m.id IN ('.implode(',',array_fill(0,count($fetch),'?')).')',
                    [...$values,$position['a'],$position['c'],...$fetch])->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as$row)$raw[(string)$row['id']]=$row['record_json'];
                if(count($raw)!==count($fetch))self::fail('source_changed');
                foreach($selected as$row)if($row['read_status']==='available'
                    &&(!isset($raw[(string)$row['id']])||strlen($raw[(string)$row['id']])!==(int)$row['bytes']))self::fail('source_changed');
            }
            $complete=count($selected)===count($metadata)&&count($metadata)<$windowLimit;
            if($semantic){$window=$this->semanticWindow($selected,$raw,$position,$state['x'],$key,$complete,$semanticState,$semanticEvidenceLeft);}
            else{$window=$this->featureWindow($selected,$raw,$position,$state['x'],$key,$complete,$evidenceLeft,$controlsLeft);}
            $window['chat_ref']=$this->encode(['v'=>1,'k'=>'chat','a'=>$position['a'],'c'=>$position['c'],
                'f'=>$state['f'],'e'=>$expiry],$key,$admin,$connection);
            $sourceBytes+=$pageBytes;$processed+=count($selected);$last=$selected[count($selected)-1];
            $position=['a'=>(string)$last['account_id'],'c'=>(string)$last['chat_id'],'n'=>(string)$last['message_id']];
            $windows[]=['result'=>$window,'position'=>$position,'semantic_state'=>$semanticState];$current=!$complete;
            if(count($selected)<count($metadata)||$sourceBytes>=self::FEATURE_SOURCE_BYTES)break;
        }
        $make=function(array$items,bool$more)use($state,$key,$admin,$connection,$metadataRows,$sourceBytes,$processed,$expiry,$semantic):array{
            $last=$items?$items[count($items)-1]['position']:['a'=>$state['a'],'c'=>$state['c'],'n'=>$state['n']];
            $cursorState=$semantic?['s'=>$items?$items[count($items)-1]['semantic_state']:$state['s']]:[];
            if($semantic&&isset($state['b']))$cursorState['b']=$state['b'];
            $next=$more?$this->encode(['v'=>1,'k'=>$state['k'],...$last,'f'=>$state['f'],'x'=>$state['x'],
                ...$cursorState,'e'=>$expiry],$key,$admin,$connection):null;
            $returned=array_sum(array_map(static fn($w)=>$w['result']['source_records'],$items));
            return$this->base($state['f'])+($semantic?['scan_range'=>isset($state['b'])?'suffix_after_inventory_checkpoint':'from_archive_beginning']:[])
                +['operation'=>$semantic?'contextual_semantic_inventory':'lexical_feature_inventory',
                'label_set_version'=>$semantic?ArchiveResearchSemanticExtractor::VERSION:null,
                'rule_revision'=>$semantic?ArchiveResearchSemanticExtractor::RULE_REVISION:null,
                'pattern_set_version'=>ArchiveResearchFeatureExtractor::PATTERN_SET_VERSION,'timing_set_version'=>self::TIMING_SET_VERSION,
                'semantics'=>$semantic?'contextual_rule_candidates_not_verified_outcomes':'lexical_candidates_not_events_or_probabilities','stored_text_read'=>true,'model_calls'=>0,
                'media_contents_read'=>false,'media_observation'=>'stored_references_only; omitted_record_media_unknown',
                'candidate_flag_scope'=>$semantic?'versioned_contextual_rules; interpretation_not_probability':'whole_message_qualifiers_not_semantic_polarity_or_match_clause',
                'full_context_lookup'=>'read_archive_history_with_window_chat_ref',
                'scan_run_ref'=>hash_hmac('sha256',"archive-feature-run-v1\0".$state['x'],$key),
                'windows'=>array_column($items,'result'),'page_metadata_rows'=>$metadataRows,'page_source_json_bytes'=>$sourceBytes,
                'page_processed_records'=>$processed,'page_returned_records'=>$returned,'read_but_not_returned_records'=>$processed-$returned,
                'has_more'=>$more,'next_cursor'=>$next,'coverage_is_per_window'=>true,'cumulative_counts'=>null,
                'deduplication'=>'source_window_id_and_source_ref_with_record_version; tokens_are_not_attestations',
                'scan_traversal_exhausted_within_fence'=>!$more,'current_versions_may_change_between_pages'=>true,'semantic_review_complete'=>false,'contextual_rule_pass'=>$semantic,
                'persistent_annotations_written'=>false,'historical_prediction_availability'=>'unknown'];
        };
        $result=$make($windows,!$exhausted);
        // Preserve a returned prefix and its cursor when fixed per-window coverage reaches the wire cap.
        while(strlen(self::json($result))>self::MAX_RESPONSE_BYTES&&count($windows)>1){
            array_pop($windows);$result=$make($windows,true);
        }
        if(!$windows&&!$exhausted)self::fail('source_limit');
        return$result;
    }

    /** Bounded per-window projection; no text or aggregate counters are carried as trusted cursor state. */
    private function semanticWindow(array$selected,array$raw,array$position,string$run,string$key,bool$complete,array&$state,int&$evidenceLeft):array
    {
        $inputState=$state;$goals=ArchiveResearchSemanticExtractor::goalIds();$goalBits=array_flip($goals);
        $counts=[];$statuses=[];$labels=[];$flags=[];$boundaries=[];$episodes=[];$evidence=[];$versions=[];
        $sourceKinds=['desktop_import'=>0,'business_message'=>0];
        foreach($selected as$row){
            $this->budget();
            if(!array_key_exists($row['source_kind'],$sourceKinds))self::fail('unavailable');
            $sourceKinds[$row['source_kind']]++;
            $item=$this->project($row,$raw[(string)$row['id']]??null);
            $statuses[$item['read_status']]=($statuses[$item['read_status']]??0)+1;
            $versions[]=[$item['source_ref'],$item['native_message_id'],$item['version_at'],$item['read_status'],$item['record_sha256']??null];
            $result=ArchiveResearchSemanticExtractor::step($item,$state);$state=$result['state'];
            $labels[$result['status']]=($labels[$result['status']]??0)+1;
            foreach($result['flags']as$name=>$value)if($value===true)$flags[$name]=($flags[$name]??0)+1;
            $boundary=$result['episode_boundary'];
            if($boundary!==null)$boundaries[$boundary]=($boundaries[$boundary]??0)+1;
            $ordinal=$state['e'];
            if(!isset($episodes[$ordinal]))$episodes[$ordinal]=['ordinal'=>$ordinal,'goal_mask'=>0,
                'first_source_ref'=>$item['source_ref'],'last_source_ref'=>$item['source_ref'],
                'boundary'=>$boundary,'product'=>$state['p']];
            $episodes[$ordinal]['last_source_ref']=$item['source_ref'];$episodes[$ordinal]['product']=$state['p'];
            foreach($result['events']as$event){
                $goal=$event['goal'];if(!isset($goalBits[$goal]))self::fail('unavailable');
                $counts[$goal]=($counts[$goal]??0)+1;
                $episodes[$ordinal]['goal_mask']|=1<<$goalBits[$goal];
                if($evidenceLeft>0){
                    $evidence[]=$event+['created_at'=>$item['created_at'],'version_at'=>$item['version_at'],
                        'record_sha256'=>$item['record_sha256']??null,'role'=>$item['role'],
                        'fragment'=>$this->featureFragment($item['text']??'',0),'fragment_basis'=>'message_prefix_may_omit_matched_clause',
                        'media_contents_read'=>false];
                    $evidenceLeft--;
                }
            }
            $this->budget();
        }
        $chat=hash_hmac('sha256',"archive-semantic-chat-v1\0".$run."\0".$position['a']."\0".$position['c'],$key);
        return['chat_key'=>$chat,'account_alias'=>$this->accountAliases[$position['a']],
            'source_window_id'=>hash_hmac('sha256',"archive-semantic-window-v1\0".ArchiveResearchSemanticExtractor::VERSION."\0".ArchiveResearchSemanticExtractor::RULE_REVISION."\0".$run."\0".$chat."\0".self::json([$versions,$inputState,$state]),$key),
            'source_records'=>count($selected),'source_kind_counts'=>$sourceKinds,
            'read_status_counts'=>$statuses,'label_status_counts'=>$labels,'flag_counts'=>$flags,
            'goal_event_counts'=>$counts,'episode_boundary_counts'=>$boundaries,'episode_candidates_started'=>array_sum($boundaries),
            'goal_mask_order'=>$goals,'episode_windows'=>array_values($episodes),'chat_window_complete'=>$complete,
            'context_after'=>['episode_ordinal'=>$state['e'],'product'=>$state['p'],
                'linked_purchase_candidate'=>$state['linked'],'cancellation_observed'=>$state['cancelled']],
            'evidence'=>$evidence,'evidence_selection'=>'first_rule_events_in_returned_prefix_not_representative',
            'episode_identity'=>'candidate_within_chat_only; zero_means_unresolved',
            'zero_counts_mean'=>'no_rule_label_in_returned_window_not_negative_outcome'];
    }

    private function featureNextChat(string$fence,string$afterAccount,string$afterChat):?array
    {
        $this->stage='inventory_page';[$where,$values]=$this->scope($fence,true);
        $from='FROM telegram_dialogue_messages m'.($this->mysql?' FORCE INDEX (uq_telegram_dialogue_message)':'')
            .' JOIN projects p ON p.project_key=m.project_key ';
        foreach(array_keys($this->accountAliases)as$account){
            $account=(string)$account;if(self::decimalGreater($afterAccount,$account))continue;
            $after=$account===$afterAccount?$afterChat:'0';
            $row=$this->q('SELECT m.account_id,m.chat_id '.$from.'WHERE '.$where
                .' AND m.account_id=? AND m.chat_id>? ORDER BY m.chat_id,m.message_id LIMIT 1',
                [...$values,$account,$after])->fetch(PDO::FETCH_ASSOC);
            if($row!==false)return['a'=>(string)$row['account_id'],'c'=>(string)$row['chat_id']];
        }
        return null;
    }

    private function featureMetadata(string$fence,array$position,int$limit):array
    {
        $this->stage='history_metadata';[$where,$values]=$this->scope($fence,false);
        $jsonValid=$this->mysql?'JSON_VALID(m.record_json)':'json_valid(m.record_json)';
        $botType=$this->mysql?"JSON_TYPE(JSON_EXTRACT(m.record_json,'$.bot_key'))":"json_type(m.record_json,'$.bot_key')";
        $botValue=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT(m.record_json,'$.bot_key'))":"CAST(json_extract(m.record_json,'$.bot_key') AS TEXT)";
        $safe='CASE WHEN '.$this->jsonBytes().'<='.self::MAX_RECORD_BYTES.' THEN CASE WHEN '.$jsonValid.'=1 THEN ';
        return$this->q('SELECT m.id,m.account_id,m.chat_id,m.message_id,m.source_kind,m.version_at,m.is_deleted,'
            .$this->jsonBytes().' AS bytes,'.$safe.'1 ELSE 0 END ELSE 0 END AS valid_json,'
            .$safe.$botType." ELSE NULL END ELSE NULL END AS bot_type,"
            .$safe.'SUBSTR('.$botValue.',1,97) ELSE NULL END ELSE NULL END AS bot_value '
            .'FROM telegram_dialogue_messages m'.($this->mysql?' FORCE INDEX (uq_telegram_dialogue_message)':'')
            .' JOIN projects p ON p.project_key=m.project_key WHERE '.$where
            .' AND m.account_id=? AND m.chat_id=? AND m.message_id>? ORDER BY m.message_id LIMIT '.$limit,
            [...$values,$position['a'],$position['c'],$position['n']])->fetchAll(PDO::FETCH_ASSOC);
    }

    private function featureWindow(array$selected,array$raw,array$position,string$run,string$key,bool$complete,int&$evidenceLeft,int&$controlsLeft):array
    {
        $counts=[];$candidateFlags=[];$unforwarded=[];$sourceKinds=['desktop_import'=>0,'business_message'=>0];$statuses=[];$extractorStatuses=[];$flags=[];$evidence=[];$controls=[];$versions=[];$timing=[];
        $ids=ArchiveResearchFeatureExtractor::patternIds();
        foreach($selected as$row){
            $this->budget();if(!array_key_exists($row['source_kind'],$sourceKinds))self::fail('unavailable');$sourceKinds[$row['source_kind']]++;
            $item=$this->project($row,$raw[(string)$row['id']]??null);$status=$item['read_status'];
            $timing[]=['read_status'=>$status,'role'=>$item['role'],'role_verified'=>$item['role_verified'],
                'created_at'=>$item['created_at'],'source_ref'=>$item['source_ref']];
            $statuses[$status]=($statuses[$status]??0)+1;
            $versions[]=[$item['source_ref'],$item['native_message_id'],$item['version_at'],$status,(int)$row['bytes'],$item['record_sha256']??null];
            if($status!=='available'){$extractorStatuses['not_attempted_unavailable_record']=($extractorStatuses['not_attempted_unavailable_record']??0)+1;$this->budget();continue;}
            $features=ArchiveResearchFeatureExtractor::extract($item);
            $extractStatus=$features['status'];$extractorStatuses[$extractStatus]=($extractorStatuses[$extractStatus]??0)+1;
            foreach($features['flags']as$name=>$value)if($value===true)$flags[$name]=($flags[$name]??0)+1;
            $candidates=array_values(array_intersect($ids,$features['candidate_ids']));
            foreach($candidates as$id){$counts[$id]=($counts[$id]??0)+1;
                foreach($features['flags']as$name=>$value)if($value===true)$candidateFlags[$id][$name]=($candidateFlags[$id][$name]??0)+1;
                if($item['role_verified']===true&&$item['forwarded']===false)$unforwarded[$id]=($unforwarded[$id]??0)+1;}
            $match=$candidates!==[];$control=!$match&&$extractStatus==='scanned';
            if(($match&&$evidenceLeft>0)||($control&&$controlsLeft>0)){
                $span=$features['evidence'][0]??['offset_bytes'=>0];
                $proof=['source_ref'=>$item['source_ref'],'record_sha256'=>$item['record_sha256']??null,
                    'native_message_id'=>$item['native_message_id'],'version_at'=>$item['version_at'],'created_at'=>$item['created_at'],
                    'role'=>$features['actor_role'],'candidate_ids'=>$candidates,'flags'=>$features['flags'],
                    'fragment'=>$this->featureFragment($item['text'],(int)$span['offset_bytes']),
                    'fragment_basis'=>'projected_minimized_text','media_contents_read'=>false];
                if($match){$evidence[]=$proof;$evidenceLeft--;}else{$controls[]=$proof;$controlsLeft--;}
            }
            $this->budget();
        }
        $observed=self::timingWindow($timing);$this->budget();
        $chat=hash_hmac('sha256',"archive-feature-chat-v1\0".$run."\0".$position['a']."\0".$position['c'],$key);
        return['chat_key'=>$chat,'account_alias'=>$this->accountAliases[$position['a']],
            'source_window_id'=>hash_hmac('sha256',"archive-feature-window-v1\0".ArchiveResearchFeatureExtractor::PATTERN_SET_VERSION."\0".self::TIMING_SET_VERSION."\0".$run."\0".$chat."\0".self::json($versions),$key),
            'source_records'=>count($selected),'source_kind_counts'=>$sourceKinds,'pattern_message_counts'=>$counts,'candidate_flag_counts'=>$candidateFlags,
            'unforwarded_verified_role_counts'=>$unforwarded,
            'read_status_counts'=>$statuses,'extractor_status_counts'=>$extractorStatuses,'flag_counts'=>$flags,
            'chat_window_complete'=>$complete,'timing_window'=>$observed,'evidence'=>$evidence,'control_samples'=>$controls,
            'control_selection'=>'first_lexically_unmatched_scanned_records_in_returned_prefix',
            'zero_counts_mean'=>'no_match_in_this_returned_window; missing_or_skipped_text_remains_unknown'];
    }

    /** Observed role changes only. Reversed clocks start a new baseline; missing records break continuity. */
    private static function timingWindow(array$records):array
    {
        $roles=['customer'=>0,'manager'=>0,'unknown'=>0];$hist=['customer_response'=>[],'manager_response'=>[]];
        $gaps=['source_unavailable'=>0,'unknown_role'=>0,'unknown_time'=>0,'nonmonotonic_time'=>0];$first=null;$previous=null;
        foreach($records as$i=>$item){
            $role=($item['role_verified']??null)===true&&in_array($item['role']??null,['customer','manager'],true)?$item['role']:'unknown';
            $roles[$role]++;$time=$item['created_at']??null;
            if(($item['read_status']??null)!=='available'){$gaps['source_unavailable']++;$previous=null;continue;}
            if($role==='unknown'){$gaps['unknown_role']++;$previous=null;continue;}
            if(!is_int($time)||$time<=0||$time>253402300799){$gaps['unknown_time']++;$previous=null;continue;}
            $edge=['role'=>$role,'time'=>$time,'source_ref'=>$item['source_ref']];
            if($i===0)$first=$edge;
            if($previous!==null&&$time<$previous['time']){$gaps['nonmonotonic_time']++;$previous=null;}
            if($previous!==null&&$role!==$previous['role']){
                $delta=$time-$previous['time'];$bin='gt_604800';
                foreach([60,300,1800,7200,86400,604800]as$ceiling)if($delta<=$ceiling){$bin='le_'.$ceiling;break;}
                $name=$role.'_response';$hist[$name][$bin]=($hist[$name][$bin]??0)+1;
            }
            // Replacing same-role edges coalesces their run using its last observed timestamp.
            $previous=$edge;
        }
        return['version'=>self::TIMING_SET_VERSION,'verified_role_message_counts'=>$roles,
            'response_delta_histograms_seconds'=>$hist,'barriers'=>$gaps,'first_edge'=>$first,'last_edge'=>$previous,
            'basis'=>'native_message_order; observed_created_at_seconds; same_role_runs_use_last_time',
            'edge_scope'=>'first_and_last_source_records_only; bridge_adjacent_same_chat_windows',
            'interpretation'=>'observed_role_switches_only; no_question_interest_initiative_or_business_hours_inference'];
    }

    private function featureFragment(string$text,int$offset):string
    {
        $start=max(0,min(strlen($text),$offset)-96);
        while($start>0&&(ord($text[$start])&0xc0)===0x80)$start--;
        $fragment=substr($text,$start,384);
        while($fragment!==''&&preg_match('//u',$fragment)!==1)$fragment=substr($fragment,0,-1);
        return$fragment;
    }

    private function metadataStatus(array$row):string
    {
        if((int)$row['is_deleted']!==0)return'deleted';
        if((int)$row['bytes']>self::MAX_RECORD_BYTES)return'oversized_record';
        if((int)$row['valid_json']!==1)return'malformed_record';
        $type=strtolower((string)($row['bot_type']??''));if(!$this->mysql&&$type==='text')$type='string';$value=$row['bot_value']??null;
        if($type===''||$type==='null')return'available';
        if($type!=='string'||!is_string($value))return'permission_unverified';
        if($value==='')return'available';
        foreach($this->permissionRows as$p)if((string)$p['account_id']===(string)$row['account_id']&&$p['bot_key']===$value)return'available';
        return'permission_unverified';
    }

    private function project(array$row,?string$raw):array
    {
        $item=['source_ref'=>'archive_message:'.(string)$row['id'],'native_message_id'=>(string)$row['message_id'],
            'source_kind'=>$row['source_kind'],'read_status'=>$row['read_status'],'text'=>null,'role'=>'unknown',
            'direction'=>'unknown','role_verified'=>false,'created_at'=>null,'version_at'=>self::time($row['version_at']),
            'deleted'=>(int)$row['is_deleted']!==0,'forwarded'=>null,'media'=>null,'media_contents_read'=>false,
            'historical_first_available_at'=>null,'native_content'=>self::nativeContent([])];
        if($row['read_status']!=='available')return$item;
        try{$record=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable$e){$record=null;}
        if(!is_array($record)){$item['read_status']='malformed_record';return$item;}
        foreach(['account_id','chat_id','message_id']as$field)if(!isset($record[$field])||(string)$record[$field]!== (string)$row[$field]){$item['read_status']='malformed_record';return$item;}
        if(($record['source_kind']??null)!==$row['source_kind']||!array_key_exists('is_deleted',$record)
            ||!is_bool($record['is_deleted'])||$record['is_deleted']!==false
            ||(string)($record['version_at']??'')!==(string)($row['version_at']??'')){$item['read_status']='malformed_record';return$item;}
        if((string)($record['sender_id']??'')==='777000'){$item['read_status']='service_message';return$item;}
        if(!is_string($record['text']??null)||preg_match('//u',$record['text'])!==1){$item['read_status']='malformed_record';return$item;}
        $sender=(string)($record['sender_id']??'');$role=$record['sender_type']??'unknown';$direction=$record['direction']??'unknown';
        if(($role==='manager'&&$direction==='outbound'&&$sender===(string)$row['account_id'])
            ||($role==='customer'&&$direction==='inbound'&&$sender===(string)$row['chat_id'])){
            $item['role']=$role;$item['direction']=$direction;$item['role_verified']=true;
        }
        $item['created_at']=self::time($record['created_at']??null);
        $item['forwarded']=is_bool($record['is_forwarded']??null)?$record['is_forwarded']:null;
        $selection=false;$links=false;
        $item['text']=ManagerAiTextRedactor::redact($record['text'],$selection,$links);
        $item['text']=preg_replace('/(?<![\pL\pN_])@[A-Za-z][A-Za-z0-9_]{3,31}\b/u','[контакт скрыт]',$item['text'])??$item['text'];
        $item['text_minimized']=true;$item['selection_links_unread']=$selection;$item['links_redacted']=$links;
        $item['record_sha256']=hash('sha256',(string)$raw);
        $item['native_content']=self::nativeContent($record);
        $item['media']=[];
        foreach((array)($record['attachments']??[])as$attachment){
            if(!is_array($attachment)){$item['media'][]=['type'=>'unknown','availability'=>'unknown'];continue;}
            $type=$attachment['type']??'unknown';$availability=$attachment['availability']??'unknown';
            $item['media'][]=['type'=>in_array($type,['image','audio','video','file'],true)?$type:'unknown',
                'availability'=>in_array($availability,['not_checked','not_exported','provider_reference_only'],true)?$availability:'unknown'];
            if(count($item['media'])>=100){$item['media_truncated']=true;break;}
        }
        return$item;
    }

    /** Versioned producer metadata, not a reconstruction from wording and not Telegram authentication. */
    private static function nativeContent(array $record): array
    {
        $unknown=['schema_version'=>null,'producer'=>null,'source_format'=>'unknown','kind'=>'unknown','text_origin'=>'unknown'];
        $native=$record['native_content']??null;
        if(($record['source_kind']??null)!=='business_message'||!is_array($native)||count($native)!==5
            ||($native['schema_version']??null)!==1||($native['producer']??null)!=='TelegramDialogueNormalizer.v1'
            ||($native['source_format']??null)!=='telegram_bot_api_message'||($native['text_origin']??null)!=='unknown'
            ||!in_array($native['kind']??null,['text','caption','contact','media','unknown'],true))return$unknown;
        $text=$record['text']??null;$media=$record['attachments']??null;$kind=$native['kind'];
        if(!is_string($text)||!is_array($media))return$unknown;
        $file=$media[0]??null;
        $supportedMedia=count($media)===1&&is_array($file)&&count($file)===4
            &&in_array($file['type']??null,['image','audio','video','file'],true)
            &&($file['availability']??null)==='provider_reference_only'
            &&is_string($file['telegram_file_id']??null)&&$file['telegram_file_id']!==''&&preg_match('//u',$file['telegram_file_id'])===1
            &&array_key_exists('file_unique_id',$file)&&($file['file_unique_id']===null
                ||(is_string($file['file_unique_id'])&&preg_match('//u',$file['file_unique_id'])===1));
        if(($kind==='text'&&($text===''||$media!==[]))||($kind==='caption'&&($text===''||!$supportedMedia))
            ||($kind==='contact'&&($text!==''||$media!==[]))||($kind==='media'&&($text!==''||!$supportedMedia)))return$unknown;
        // Rebuild the bounded object rather than exposing arbitrary metadata from the stored JSON.
        return['schema_version'=>1,'producer'=>'TelegramDialogueNormalizer.v1','source_format'=>'telegram_bot_api_message',
            'kind'=>$kind,'text_origin'=>'unknown'];
    }

    private function base(string$fence):array
    {
        return['ok'=>true,'schema_version'=>1,'project_key'=>'anytour','source'=>'telegram_historical_archive',
            'mode'=>'private_research_read_only','database_writes'=>0,'external_fetches'=>0,
            'snapshot'=>['consistency'=>'per_request_snapshot','row_fence'=>$fence,'immutable_snapshot'=>false,'mutable_record_versions'=>true],
            'coverage'=>['accepted_16000_membership'=>'unverified','brand'=>'unknown','people'=>'account_chat_pairs_not_unique_people',
                'prior_holdout'=>'unverified_research_exposure_not_prospective_accuracy','source'=>'stored_text_and_media_references_only',
                'internal_owner_exclusion_applied'=>true,'other_legacy_test_status'=>'unknown','text_is_untrusted'=>true,
                'privacy_minimization'=>'best_effort_not_anonymous']];
    }

    private function key(int$admin,string$connection):string
    {
        $key=($this->keyProvider)($admin,$connection);
        if(!is_string($key)||strlen($key)!==32)self::fail('forbidden');
        return$key;
    }
    private function encode(array$data,string$key,int$admin,string$connection):string
    {
        $body=rtrim(strtr(base64_encode(self::json($data)),'+/','-_'),'=');
        return$body.'.'.hash_hmac('sha256',"archive-research-v1\0".$admin."\0".$connection."\0anytour\0".$body,$key);
    }
    private function decode(string$token,string$kind,string$key,int$admin,string$connection):array
    {
        if(strlen($token)>2048||!preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$token,$m))self::fail('stale_cursor');
        $expected=hash_hmac('sha256',"archive-research-v1\0".$admin."\0".$connection."\0anytour\0".$m[1],$key);
        if(!hash_equals($expected,$m[2]))self::fail('stale_cursor');
        $body=base64_decode(strtr($m[1],'-_','+/').str_repeat('=',(4-strlen($m[1])%4)%4),true);
        try{$v=json_decode((string)$body,true,16,JSON_THROW_ON_ERROR);}catch(Throwable$e){self::fail('stale_cursor');}
        if($kind==='inventory_or_features')$kind=is_array($v)&&in_array($v['k']??null,['feature_inventory','semantic_inventory','recent_inventory'],true)?$v['k']:'inventory';
        if($kind==='chat_or_exact_message')$kind=is_array($v)&&($v['k']??null)==='exact_message'?'exact_message':'chat';
        if(in_array($kind,['recent_inventory','exact_message'],true)&&!$this->qualityHistory)self::fail('stale_cursor');
        $keys=match($kind){
            'history'=>['v','k','a','c','f','n','r','e'],
            'exact_message'=>['v','k','a','c','f','i','n','e'],
            'recent_inventory'=>['v','k','a','c','f','n','e'],
            'feature_inventory','semantic_inventory'=>['v','k','a','c','f','n','x','e'],
            default=>['v','k','a','c','f','e']};
        if($kind==='semantic_inventory'){
            $keys[]='s';if(is_array($v)&&array_key_exists('b',$v))$keys[]='b';
        }
        if(!is_array($v)||count($v)!==count($keys)||array_diff(array_keys($v),$keys)||($v['v']??null)!==1
            ||($v['k']??null)!==$kind||!is_int($v['e'])||$v['e']<$this->now()||$v['e']>$this->now()+self::CURSOR_TTL)self::fail('stale_cursor');
        foreach(['a','c','f']as$field)if(!is_string($v[$field])||!self::decimal($v[$field],in_array($kind,['inventory','feature_inventory','semantic_inventory','recent_inventory'],true)||$field==='f'))self::fail('stale_cursor');
        if($kind==='recent_inventory'&&($v['a']!=='0'||$v['c']!=='0'||!is_string($v['n'])||!self::decimal($v['n'],true)
            ||self::decimalGreater($v['n'],$v['f'])))self::fail('stale_cursor');
        if($kind==='exact_message'&&(!is_string($v['i'])||!self::decimal($v['i'],false)||!is_string($v['n'])||!self::decimal($v['n'],false)
            ||self::decimalGreater($v['i'],$v['f'])))self::fail('stale_cursor');
        if($kind==='history'&&(!is_string($v['n'])||!self::decimal($v['n'],true)||!is_string($v['r'])||!preg_match('/\A[a-f0-9]{64}\z/D',$v['r'])))self::fail('stale_cursor');
        if(in_array($kind,['feature_inventory','semantic_inventory'],true)&&(!is_string($v['n'])||!self::decimal($v['n'],true)
            ||!is_string($v['x'])||!preg_match('/\A[a-f0-9]{32}\z/D',$v['x'])
            ||(($v['a']==='0'||$v['c']==='0')&&($v['a']!=='0'||$v['c']!=='0'||$v['n']!=='0'))))self::fail('stale_cursor');
        if($kind==='semantic_inventory'&&(!is_array($v['s'])||strlen(self::json($v['s']))>1024
            ||!ArchiveResearchSemanticExtractor::validState($v['s'])))self::fail('stale_cursor');
        if($kind==='semantic_inventory'&&array_key_exists('b',$v)){
            $bound=$v['b'];
            if(!is_array($bound)||!array_is_list($bound)||count($bound)!==2
                ||!is_string($bound[0])||!self::decimal($bound[0],false)
                ||!is_string($bound[1])||!self::decimal($bound[1],false)
                ||self::decimalGreater($bound[0],$v['a'])
                ||($bound[0]===$v['a']&&self::decimalGreater($bound[1],$v['c'])))self::fail('stale_cursor');
            if(!isset($this->accountAliases[$bound[0]]))self::fail('forbidden');
        }
        return$v;
    }
    private static function arguments(array$args,array$allowed,int$max):void
    {
        if(array_diff(array_keys($args),$allowed))self::fail('invalid_arguments');
        foreach(['cursor','chat_ref']as$field)if(isset($args[$field])&&(!is_string($args[$field])||$args[$field]===''||strlen($args[$field])>2048))self::fail('invalid_arguments');
        foreach(['cursor','chat_ref']as$field)if(array_key_exists($field,$args)&&$args[$field]===null)self::fail('invalid_arguments');
        if(isset($args['limit'])&&(!is_int($args['limit'])||$args['limit']<1||$args['limit']>$max))self::fail('invalid_arguments');
        if(array_key_exists('limit',$args)&&$args['limit']===null)self::fail('invalid_arguments');
    }
    private function q(string$sql,array$args=[]):PDOStatement
    {
        $this->budget();
        if(!str_starts_with($sql,'SELECT '))self::fail('unavailable');
        if($this->mysql)$sql='SELECT /*+ MAX_EXECUTION_TIME('.max(1,min(5000,(int)(($this->deadline-microtime(true))*1000))).') */ '.substr($sql,7);
        $q=$this->pdo->prepare($sql);$q->execute($args);$this->budget();return$q;
    }
    private static function databaseError(PDOException$error,string$stage):ArchiveResearchReadException
    {
        $info=is_array($error->errorInfo)?$error->errorInfo:[];
        $state=is_string($info[0]??null)&&preg_match('/\A[0-9A-Z]{5}\z/D',$info[0])?substr($info[0],0,2):null;
        $code=$info[1]??null;
        $code=(is_int($code)||(is_string($code)&&preg_match('/\A[0-9]{1,5}\z/D',$code)))?(int)$code:null;
        if($code!==null&&($code<0||$code>65535))$code=null;
        return new ArchiveResearchReadException(in_array($code,[3024,1969],true)?'time_limit':'unavailable',$stage,$state,$code);
    }
    private function jsonBytes():string{return $this->mysql?'LENGTH(m.record_json)':'LENGTH(CAST(m.record_json AS BLOB))';}
    private function budget():void{if(microtime(true)>$this->deadline)throw new ArchiveResearchReadException('time_limit',$this->stage);}
    private function now():int{return$this->clock?(int)($this->clock)():time();}
    private static function decimal(string$v,bool$zero):bool{return preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function decimalGreater(string$a,string$b):bool{return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function time(mixed$v):?int{return(is_int($v)||(is_string($v)&&preg_match('/\A[1-9][0-9]{0,11}\z/D',$v)))&&(int)$v>0&&(int)$v<=253402300799?(int)$v:null;}
    private static function json(array$v):string{return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private static function fail(string$reason):never{throw new ArchiveResearchReadException($reason);}
}
final class ArchiveResearchReadException extends RuntimeException
{
    public const STAGES=['unknown','key_before','transaction_begin','permissions_before','fence','summary','inventory_page',
        'history_stats','history_anchor','history_metadata','history_payload','rollback','key_after','permissions_after','response'];
    public const SQLSTATE_CLASSES=['08','22','23','28','40','42','HY'];
    public readonly string $stage;
    public readonly ?string $sqlstateClass;
    public readonly ?int $driverCode;
    public function __construct(public readonly string$reason,string$stage='unknown',?string$sqlstateClass=null,?int$driverCode=null)
    {
        $this->stage=in_array($stage,self::STAGES,true)?$stage:'unknown';
        $this->sqlstateClass=in_array($sqlstateClass,self::SQLSTATE_CLASSES,true)?$sqlstateClass:null;
        $this->driverCode=$driverCode!==null&&$driverCode>=0&&$driverCode<=65535?$driverCode:null;
        parent::__construct('archive_research_'.$reason);
    }
}
