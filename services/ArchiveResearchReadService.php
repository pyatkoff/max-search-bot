<?php
declare(strict_types=1);

require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';
require_once __DIR__.'/ArchiveResearchFeatureExtractor.php';

/** Private historical research reader. No intake, model, send or read-marker owner. */
final class ArchiveResearchReadService
{
    public const MAX_OUTPUT_BYTES=65536;
    public const MAX_RESPONSE_BYTES=self::MAX_OUTPUT_BYTES;
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

    public function __construct(private PDO $pdo, private $clock=null, private $keyProvider=null)
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['mysql','sqlite'],true)) self::fail('unavailable');
        $this->mysql=$driver==='mysql';
    }

    public function inventory(int $admin,string $connection,array $args=[]): array
    {
        self::arguments($args,['cursor','limit'],50);
        return $this->read($admin,$connection,function(string $key) use($admin,$connection,$args):array {
            $state=isset($args['cursor'])?$this->decode($args['cursor'],'inventory_or_features',$key,$admin,$connection):null;
            if(($state['k']??null)==='feature_inventory'&&$state['a']!=='0'&&!isset($this->accountAliases[$state['a']]))self::fail('forbidden');
            $currentFence=$this->fence();$fence=$state['f']??$currentFence;
            if(self::decimalGreater($fence,$currentFence))self::fail('stale_cursor');
            if(($state['k']??null)==='feature_inventory')return$this->featureInventory($state,$args['limit']??25,$key,$admin,$connection);
            $expiry=$state['e']??($this->now()+self::CURSOR_TTL);
            $summary=$state===null?$this->summary($fence):null;
            $limit=$args['limit']??25;
            [$rows,$more]=$this->inventoryRows($fence,$state['a']??'0',$state['c']??'0',$limit);$chats=[];
            foreach($rows as$row){
                $a=(string)$row['account_id'];$c=(string)$row['chat_id'];
                $chats[]=['chat_ref'=>$this->encode(['v'=>1,'k'=>'chat','a'=>$a,'c'=>$c,'f'=>$fence,'e'=>$expiry],$key,$admin,$connection),
                    'account_alias'=>$this->accountAliases[$a],'message_records'=>(int)$row['records'],
                    'source_json_bytes'=>null,'source_json_bytes_status'=>'not_scanned','deleted_records'=>(int)$row['deleted_records'],
                    'brand'=>'unknown','trip_count'=>null,'person_identity'=>'unverified'];
            }
            $next=null;
            if($more&&$rows){$last=$rows[count($rows)-1];$next=$this->encode(['v'=>1,'k'=>'inventory','a'=>(string)$last['account_id'],
                'c'=>(string)$last['chat_id'],'f'=>$fence,'e'=>$expiry],$key,$admin,$connection);}
            return $this->base($fence)+['totals'=>$summary['totals']??null,'accounts'=>$summary['accounts']??null,
                'summary_not_recomputed'=>$state!==null,'feature_scan'=>$this->featureDescriptor($fence,$key,$admin,$connection),
                'chats'=>$chats,'has_more'=>$more,'next_cursor'=>$next];
        });
    }

    public function history(int $admin,string $connection,array $args): array
    {
        self::arguments($args,['chat_ref','cursor','limit'],100);
        if(!isset($args['chat_ref'])||!is_string($args['chat_ref'])||$args['chat_ref']==='')self::fail('invalid_arguments');
        return $this->read($admin,$connection,function(string$key)use($admin,$connection,$args):array {
            $chat=$this->decode($args['chat_ref'],'chat',$key,$admin,$connection);
            // Current account denial takes precedence over an older cursor's now-smaller fence.
            if(!isset($this->accountAliases[$chat['a']]))self::fail('forbidden');
            if(self::decimalGreater($chat['f'],$this->fence()))self::fail('stale_cursor');
            $cursor=isset($args['cursor'])?$this->decode($args['cursor'],'history',$key,$admin,$connection):null;
            $ref=hash('sha256',$args['chat_ref']);
            if($cursor&&($cursor['a']!==$chat['a']||$cursor['c']!==$chat['c']||$cursor['f']!==$chat['f']
                ||$cursor['r']!==$ref||$cursor['e']!==$chat['e']))self::fail('stale_cursor');
            [$where,$values]=$this->scope($chat['f'],false);
            $where.=' AND m.account_id=? AND m.chat_id=?';array_push($values,$chat['a'],$chat['c']);
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
            $after=$cursor['n']??'0';$limit=$args['limit']??50;
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
            $make=function(array$msgs,string$last,int$skipped,bool$more)use($chat,$ref,$args,$key,$admin,$connection,$stat,$bytes):array{
                $next=$more?$this->encode(['v'=>1,'k'=>'history','a'=>$chat['a'],'c'=>$chat['c'],'f'=>$chat['f'],
                    'n'=>$last,'r'=>$ref,'e'=>$chat['e']],$key,$admin,$connection):null;
                return $this->base($chat['f'])+['chat_ref'=>$args['chat_ref'],'account_alias'=>$this->accountAliases[$chat['a']],
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

    private function read(int$admin,string$connection,callable$operation):array
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        if($admin<1||$connection===''||strlen($connection)>128||!is_callable($this->keyProvider))self::fail('forbidden');
        $this->deadline=microtime(true)+self::MAX_SECONDS;$owned=false;$queryOnly=null;
        try{
            $this->stage='key_before';$key=$this->key($admin,$connection);
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
            $result=$operation($key);
            $this->stage='rollback';$this->pdo->rollBack();$owned=false;
            if($queryOnly!==null){$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));$queryOnly=null;}
            // Outside the old consistent snapshot: revocations cannot use its old permission rows.
            $this->stage='key_after';$freshKey=$this->key($admin,$connection);
            if(!hash_equals($key,$freshKey))self::fail('stale_cursor');
            $this->stage='permissions_after';if($original!==$this->permissions())self::fail('permission_changed');
            $this->stage='response';$this->budget();
            if(strlen(self::json($result))>self::MAX_RESPONSE_BYTES)self::fail('response_limit');
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
            .($historical?" AND m.source_kind='desktop_import'":" AND m.source_kind IN ('desktop_import','business_message')"),
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
            $rows=$this->q('SELECT m.account_id,m.chat_id,COUNT(*) AS records,'
                .'COALESCE(SUM(m.is_deleted<>0),0) AS deleted_records '.$from.'WHERE '.$where
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

    /** Explicit stored-text mode only. Cursor fields are positions, never authorization or cumulative counts. */
    private function featureInventory(array$state,int$limit,string$key,int$admin,string$connection):array
    {
        if($state['a']!=='0'&&!isset($this->accountAliases[$state['a']]))self::fail('forbidden');
        $position=['a'=>$state['a'],'c'=>$state['c'],'n'=>$state['n']];$current=$state['a']!=='0';
        $windows=[];$metadataRows=0;$sourceBytes=0;$processed=0;$evidenceLeft=8;$controlsLeft=4;$exhausted=false;
        $anchor=$current;$expiry=$this->now()+self::CURSOR_TTL;
        while(count($windows)<$limit&&$metadataRows<self::FEATURE_METADATA_ROWS){
            if(!$current){
                $chat=$this->featureNextChat($state['f'],$position['a'],$position['c']);
                if($chat===null){$exhausted=true;break;}
                $metadataRows++;$position=['a'=>$chat['a'],'c'=>$chat['c'],'n'=>'0'];$current=true;$anchor=false;
            }
            if($anchor){
                $this->stage='history_anchor';[$where,$values]=$this->scope($state['f'],true);
                $found=$this->q('SELECT 1 FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '
                    .$where.' AND m.account_id=? AND m.chat_id=? LIMIT 1',[...$values,$position['a'],$position['c']])->fetchColumn();
                if($found===false){$current=false;$anchor=false;continue;}
                $metadataRows++;$anchor=false;
            }
            $remaining=self::FEATURE_METADATA_ROWS-$metadataRows;if($remaining<1)break;
            $metadata=$this->featureMetadata($state['f'],$position,$remaining);$metadataRows+=count($metadata);
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
            $complete=count($selected)===count($metadata)&&count($metadata)<$remaining;
            $window=$this->featureWindow($selected,$raw,$position,$state['x'],$key,$complete,$evidenceLeft,$controlsLeft);
            $window['chat_ref']=$this->encode(['v'=>1,'k'=>'chat','a'=>$position['a'],'c'=>$position['c'],
                'f'=>$state['f'],'e'=>$expiry],$key,$admin,$connection);
            $sourceBytes+=$pageBytes;$processed+=count($selected);$last=$selected[count($selected)-1];
            $position=['a'=>(string)$last['account_id'],'c'=>(string)$last['chat_id'],'n'=>(string)$last['message_id']];
            $windows[]=['result'=>$window,'position'=>$position];$current=!$complete;
            if(count($selected)<count($metadata)||$sourceBytes>=self::FEATURE_SOURCE_BYTES)break;
        }
        $make=function(array$items,bool$more)use($state,$key,$admin,$connection,$metadataRows,$sourceBytes,$processed,$expiry):array{
            $last=$items?$items[count($items)-1]['position']:['a'=>$state['a'],'c'=>$state['c'],'n'=>$state['n']];
            $next=$more?$this->encode(['v'=>1,'k'=>'feature_inventory',...$last,'f'=>$state['f'],'x'=>$state['x'],
                'e'=>$expiry],$key,$admin,$connection):null;
            $returned=array_sum(array_map(static fn($w)=>$w['result']['source_records'],$items));
            return$this->base($state['f'])+['operation'=>'lexical_feature_inventory',
                'pattern_set_version'=>ArchiveResearchFeatureExtractor::PATTERN_SET_VERSION,'timing_set_version'=>self::TIMING_SET_VERSION,
                'semantics'=>'lexical_candidates_not_events_or_probabilities','stored_text_read'=>true,'model_calls'=>0,
                'media_contents_read'=>false,'media_observation'=>'stored_references_only; omitted_record_media_unknown',
                'candidate_flag_scope'=>'whole_message_qualifiers_not_semantic_polarity_or_match_clause',
                'full_context_lookup'=>'read_archive_history_with_window_chat_ref',
                'scan_run_ref'=>hash_hmac('sha256',"archive-feature-run-v1\0".$state['x'],$key),
                'windows'=>array_column($items,'result'),'page_metadata_rows'=>$metadataRows,'page_source_json_bytes'=>$sourceBytes,
                'page_processed_records'=>$processed,'page_returned_records'=>$returned,'read_but_not_returned_records'=>$processed-$returned,
                'has_more'=>$more,'next_cursor'=>$next,'coverage_is_per_window'=>true,'cumulative_counts'=>null,
                'deduplication'=>'source_window_id_and_source_ref_with_record_version; tokens_are_not_attestations',
                'scan_traversal_exhausted_within_fence'=>!$more,'current_versions_may_change_between_pages'=>true,'semantic_review_complete'=>false];
        };
        $result=$make($windows,!$exhausted);
        // Preserve a returned prefix and its cursor when fixed per-window coverage reaches the wire cap.
        while(strlen(self::json($result))>self::MAX_RESPONSE_BYTES&&count($windows)>1){
            array_pop($windows);$result=$make($windows,true);
        }
        if(!$windows&&!$exhausted)self::fail('source_limit');
        return$result;
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
            'historical_first_available_at'=>null];
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
        if($kind==='inventory_or_features')$kind=is_array($v)&&($v['k']??null)==='feature_inventory'?'feature_inventory':'inventory';
        $keys=$kind==='history'?['v','k','a','c','f','n','r','e']:($kind==='feature_inventory'?['v','k','a','c','f','n','x','e']:['v','k','a','c','f','e']);
        if(!is_array($v)||count($v)!==count($keys)||array_diff(array_keys($v),$keys)||($v['v']??null)!==1
            ||($v['k']??null)!==$kind||!is_int($v['e'])||$v['e']<$this->now()||$v['e']>$this->now()+self::CURSOR_TTL)self::fail('stale_cursor');
        foreach(['a','c','f']as$field)if(!is_string($v[$field])||!self::decimal($v[$field],in_array($kind,['inventory','feature_inventory'],true)||$field==='f'))self::fail('stale_cursor');
        if($kind==='history'&&(!is_string($v['n'])||!self::decimal($v['n'],true)||!is_string($v['r'])||!preg_match('/\A[a-f0-9]{64}\z/D',$v['r'])))self::fail('stale_cursor');
        if($kind==='feature_inventory'&&(!is_string($v['n'])||!self::decimal($v['n'],true)
            ||!is_string($v['x'])||!preg_match('/\A[a-f0-9]{32}\z/D',$v['x'])
            ||(($v['a']==='0'||$v['c']==='0')&&($v['a']!=='0'||$v['c']!=='0'||$v['n']!=='0'))))self::fail('stale_cursor');
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
