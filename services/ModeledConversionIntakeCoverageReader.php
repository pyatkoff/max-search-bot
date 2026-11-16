<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionStream.php';
require_once __DIR__.'/ModeledConversionProfiles.php';
require_once __DIR__.'/ModeledConversionValueContract.php';

/** Exact current native bindings and canonical recording clocks; scalar SELECTs only. */
final class ModeledConversionIntakeCoverageReader
{
    public const LIMIT=10001;
    public const GRACE_SECONDS=900;
    private bool $mysql;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget)
    {
        $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if(!$this->mysql)$pdo->sqliteCreateFunction('modeled_hash',static fn($v)=>hash('sha256',(string)$v),1);
    }
    public function read(int $now):array
    {
        $this->auth();
        if($now<946684800)return $this->fail('invalid_clock');
        if($this->budget->remainingBytes()<12288||$this->budget->remainingMilliseconds()<1000)return $this->fail('budget_headroom');
        if($this->pdo->inTransaction())return $this->fail('outer_transaction');
        $owned=false;
        try{
            if($this->mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('SET TRANSACTION READ ONLY');}
            $this->pdo->beginTransaction();$owned=true;
            $doc="CASE WHEN JSON_VALID(s.state_json) AND ".($this->mysql?'OCTET_LENGTH':'LENGTH')."(s.state_json)<=8192 THEN s.state_json ELSE '{}' END";
            $meta=$this->query("SELECT s.enabled AS se,".$this->scalar($doc,'message.floor')." AS mf,"
                .$this->scalar($doc,'event.floor')." AS ef,p.enabled AS pe,p.journal_cursor AS pc,v.profile_cursor AS vc,v.mode AS vm,v.goal_id AS vg,v.contract_version AS ver"
                ." FROM modeled_conversion_stream_state s JOIN modeled_conversion_profiles_state p ON HEX(p.project_key)=HEX(s.project_key)"
                ." JOIN modeled_conversion_value_export_settings v ON HEX(v.project_key)=HEX(s.project_key)"
                ." WHERE HEX(s.project_key)=HEX('anytour') AND HEX(v.counter_id)=HEX('98615635') LIMIT 1");
            if(count($meta)!==1)return $this->fail('not_configured');
            $m=$meta[0];
            foreach(['mf','ef','pc','vc']as$k)if(preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',(string)($m[$k]??''))!==1)return $this->fail('invalid_state');
            if(!in_array((string)$m['se'],['0','1'],true)||!in_array((string)$m['pe'],['0','1'],true)
                ||!in_array($m['vm'],['send','off'],true)||$m['vg']!=='678187097'||$m['ver']!==ModeledConversionValueContract::VERSION)
                return $this->fail('invalid_state');
            $day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->setTime(0,0);$from=$day->getTimestamp();
            $namespace=ModeledConversionProfiles::assessmentNamespace();
            $rows=$this->query($this->sql($from,$now,$m),[$namespace]);
            if(count($rows)!==3)return $this->fail('invalid_projection');
            $out=['available'=>true,'basis'=>'current_native_recording_clock_and_saved_pipeline_metadata_v1',
                'date'=>$day->format('Y-m-d'),'timezone'=>'Europe/Moscow','as_of'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                'scope'=>'customer_inbound_messages_and_customer_actor_events_after_prospective_floors',
                'state'=>['capture_enabled'=>(string)$m['se']==='1','profiles_enabled'=>(string)$m['pe']==='1','delivery_mode'=>$m['vm']],
                'coverage'=>null,'all'=>null,'by_platform'=>[],
                'interpretation'=>['all_inputs_are_checked_not_only_accruals'=>true,'clockless_inputs_not_assigned_to_a_day'=>true,
                    'source_hash_and_current_native_binding_are_checked'=>true,'saved_profile_metadata_is_not_semantic_validation'=>true,'old_model_alone_is_not_a_missing_profile'=>true,
                    'changed_payload_without_new_recording_clock_has_unknown_capture_age'=>true,
                    'no_increment_does_not_prove_a_missed_conversion'=>true,'stored_identity_candidate_is_not_attribution_permission'=>true,
                    'messages_and_events_do_not_establish_trip_boundaries'=>true,'no_ids_text_provider_requests_or_source_writes'=>true],
                'provider_requests'=>0,'model_calls'=>0,'conversion_uploads'=>0];
            $keys=['inputs','conversations','captured','indexed','profiled','current_model_profiles','collected','wait_capture','wait_index','wait_profile','wait_collector','no_increment',
                'late_capture','late_profile','late_collector','goal_conversations','goal_events','goal_pending','goal_ready','goal_accepted','goal_processed','goal_other',
                'stored_identity_conversations','tg_no_observation','tg_unverified','tg_no_client','tg_later','tg_unavailable','tg_unique','tg_ambiguous'];
            $seen=[];
            foreach($rows as$r){
                $platform=$r['platform']??null;if(!in_array($platform,['all','max','telegram'],true)||isset($seen[$platform]))return $this->fail('invalid_projection');
                $seen[$platform]=true;$v=[];
                foreach($keys as$k)$v[$k]=$this->number($r[$k]??null,false);
                $v['goal_value_rub']=$this->number($r['goal_value_rub']??null,true);
                $native=$this->number($r['native_rows']??null,false);$goals=$this->number($r['goal_rows']??null,false);
                if($native>self::LIMIT||$goals>self::LIMIT||$v['conversations']>$v['inputs']
                    ||$v['wait_capture']+$v['wait_index']+$v['wait_profile']+$v['profiled']!==$v['conversations']
                    ||$v['wait_collector']+$v['collected']!==$v['profiled']
                    ||$v['captured']+$v['wait_capture']!==$v['conversations']
                    ||$v['indexed']+$v['wait_capture']+$v['wait_index']!==$v['conversations']
                    ||$v['goal_pending']+$v['goal_ready']+$v['goal_accepted']+$v['goal_processed']+$v['goal_other']!==$v['goal_events'])
                    return $this->fail('invalid_projection');
                if($r['oldest']!==null&&preg_match('/\A[1-9][0-9]{8,10}\z/D',(string)$r['oldest'])!==1)return $this->fail('invalid_projection');
                $v['oldest_unfinished_seconds']=$r['oldest']===null?null:$now-(int)$r['oldest'];
                if($v['oldest_unfinished_seconds']!==null&&($v['oldest_unfinished_seconds']<0||$v['oldest_unfinished_seconds']>86400))return $this->fail('invalid_projection');
                if($platform==='all'){
                    $out['all']=$v;
                    $out['coverage']=['max_native_inputs'=>self::LIMIT,'inspected_native_inputs'=>$native,
                        'native_clock_scope_complete'=>$native<self::LIMIT,'max_goal_rows'=>self::LIMIT,'inspected_goal_rows'=>$goals,
                        'goal_scope_complete'=>$goals<self::LIMIT,'grace_seconds'=>self::GRACE_SECONDS];
                }else $out['by_platform'][$platform]=$v;
            }
            foreach($keys as$k)if($out['all'][$k]!==$out['by_platform']['max'][$k]+$out['by_platform']['telegram'][$k])return $this->fail('invalid_projection');
            $this->pdo->rollBack();$owned=false;
            $this->auth();return $out;
        }catch(ModeledConversionStatusLimit|DomainException $e){throw $e;}
        catch(PDOException $ignored){return $this->fail('storage_unavailable');}
        catch(UnexpectedValueException $ignored){return $this->fail('invalid_projection');}
        finally{if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();}
    }
    private function sql(int $from,int $now,array $m):string
    {
        $cast=$this->mysql?'CHAR':'TEXT';
        $parts=[];
        foreach(['message'=>'messages','event'=>'conversation_events']as$kind=>$table){
            $clock=ConversationRecordClock::sql($kind==='message'?'s.metadata_json':'s.payload_json',$this->mysql);
            $peer=$this->mysql?"CONCAT('-',cc.external_user_id)":"'-'||cc.external_user_id";
            $scope="HEX(c.project_key)=HEX('anytour') AND HEX(c.channel) IN (HEX('max'),HEX('telegram')) AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')
                .' AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)'
                .' AND HEX(CAST(cc.external_chat_id AS '.$cast.'))=HEX(CAST(c.external_chat_id AS '.$cast.'))'
                ." AND HEX(CAST(c.external_chat_id AS ".$cast."))=HEX(CAST(CASE WHEN c.channel='max' THEN ".$peer.' ELSE cc.external_user_id END AS '.$cast.'))'
                .($kind==='message'?" AND HEX(s.channel)=HEX(c.channel) AND HEX(s.direction)=HEX('inbound') AND HEX(s.sender_type)=HEX('customer')":" AND HEX(s.actor_type)=HEX('customer')")
                ." AND s.id>".$m[$kind==='message'?'mf':'ef']." AND ($clock)>=".$from." AND ($clock)<=".$now;
            $parts[]="SELECT '".$kind."' AS kind,s.id,c.channel AS platform,c.id AS conversation_id,c.customer_id,cc.external_user_id,"
                .$clock.' AS persisted,'.ModeledConversionStream::payloadHashSql($this->mysql,$kind).' AS payload_hash'
                .' FROM '.$table.' s JOIN conversations c ON c.id=s.conversation_id'
                .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id JOIN customers cu ON cu.id=c.customer_id'
                .' WHERE '.$scope.' ORDER BY s.id DESC LIMIT '.self::LIMIT;
        }
        $native='SELECT * FROM ('.$parts[0].') native_messages UNION ALL SELECT * FROM ('.$parts[1].') native_events';
        $safe="CASE WHEN JSON_VALID(j.snapshot_json) AND ".($this->mysql?'OCTET_LENGTH':'LENGTH')."(j.snapshot_json)<=65536 THEN j.snapshot_json ELSE '{}' END";
        $get=fn(string $p):string=>$this->scalar($safe,$p);
        $bound="j.observed_at_unix BETWEEN n.persisted AND ".$now
            ." AND ".$get('source_status')."='present' AND ".$get('payload_status')."='copied'"
            ." AND HEX(".$get('project_key').")=HEX('anytour') AND HEX(".$get('platform').")=HEX(n.platform)"
            .' AND HEX('.$get('conversation_id').')=HEX(CAST(n.conversation_id AS '.$cast.'))'
            .' AND HEX('.$get('customer_key').')=HEX(CAST(n.customer_id AS '.$cast.'))'
            .' AND HEX('.$get('payload_sha256').')=HEX(n.payload_hash)';
        $profilePlatform=$this->mysql?'CONVERT(n.platform USING ascii) COLLATE ascii_bin':'n.platform';
        $profileCustomer=$this->mysql?'CONVERT(CAST(n.customer_id AS CHAR) USING ascii) COLLATE ascii_bin':'CAST(n.customer_id AS TEXT)';
        $profileConversation=$this->mysql?'CONVERT(CAST(n.conversation_id AS CHAR) USING ascii) COLLATE ascii_bin':'CAST(n.conversation_id AS TEXT)';
        $base="WITH native AS (SELECT * FROM (".$native.") native_both ORDER BY persisted DESC,kind,id DESC LIMIT ".self::LIMIT."),"
            ."captured AS (SELECT n.*,j.observed_at_unix AS j_witness,CASE WHEN ".$bound." THEN j.id ELSE NULL END AS jid,"
            ."CASE WHEN ".$bound." THEN j.observed_at_unix ELSE NULL END AS captured_at,"
            ."CASE WHEN ".$bound." AND i.journal_id=j.id AND HEX(i.source_status)=HEX('present')"
            ." AND HEX(i.platform)=HEX(n.platform) AND HEX(i.customer_key)=HEX(CAST(n.customer_id AS ".$cast."))"
            ." AND HEX(i.conversation_id)=HEX(CAST(n.conversation_id AS ".$cast.")) THEN 1 ELSE 0 END AS indexed_ok"
            ." FROM native n LEFT JOIN modeled_conversion_stream_sources s ON s.project_key='anytour' AND HEX(s.project_key)=HEX('anytour') AND s.source_kind=n.kind AND s.source_id=n.id"
            ." LEFT JOIN modeled_conversion_stream_versions j ON j.project_key=s.project_key AND j.source_kind=s.source_kind AND j.source_id=s.source_id AND j.version_no=s.version_no"
            ." LEFT JOIN modeled_conversion_profile_sources i ON i.project_key=s.project_key AND i.source_kind=s.source_kind AND i.source_id=s.source_id),"
            ."scopes AS (SELECT platform,conversation_id,customer_id,external_user_id,COUNT(*) AS inputs,MIN(persisted) AS first_at,MAX(persisted) AS last_at,"
            ."SUM(CASE WHEN jid IS NOT NULL THEN 1 ELSE 0 END) AS captures,SUM(indexed_ok) AS indexed_count,MAX(jid) AS last_jid,MAX(captured_at) AS last_capture,"
            ."MIN(CASE WHEN jid IS NULL AND (j_witness IS NULL OR persisted>j_witness) THEN persisted ELSE NULL END) AS missing_capture_at,"
            ."MIN(CASE WHEN jid IS NOT NULL AND indexed_ok=0 THEN captured_at ELSE NULL END) AS missing_index_at"
            ." FROM captured GROUP BY platform,conversation_id,customer_id,external_user_id),"
            ."selected AS (SELECT n.*,(SELECT p.id FROM modeled_conversion_profile_versions p WHERE p.project_key='anytour' AND HEX(p.project_key)=HEX('anytour')"
            ." AND p.platform=".$profilePlatform." AND p.customer_key=".$profileCustomer." AND p.conversation_id=".$profileConversation
            ." AND p.journal_cursor>=n.last_jid AND p.observed_at_unix>=n.last_capture"
            ." AND p.observed_at_unix<=".$now." ORDER BY p.id DESC LIMIT 1) AS profile_id FROM scopes n),"
            ."goal_rows AS (SELECT o.id,o.platform,o.conversation_id,o.state,o.value_rub,o.client_id FROM max_channel_conversion_outbox o JOIN scopes n"
            ." ON n.platform=o.platform AND n.conversation_id=o.conversation_id AND HEX(o.external_user_id)=HEX(n.external_user_id)"
            ." WHERE o.project_key='anytour' AND o.counter_id='98615635' AND o.transport_target='anytour_modeled_value_v1' AND o.value_contract='semantic_stage_value_25000_v1'"
            ." AND HEX(o.platform)=HEX(n.platform) AND HEX(o.project_key)=HEX('anytour') AND HEX(o.counter_id)=HEX('98615635')"
            ." AND HEX(o.transport_target)=HEX('anytour_modeled_value_v1') AND HEX(o.value_contract)=HEX('semantic_stage_value_25000_v1')"
            ." AND o.value_rub>0 AND o.value_rub<=".ModeledConversionValueContract::MAX_VALUE_RUB
            ." AND o.event_unix>=n.first_at AND o.event_unix<=".$now." ORDER BY o.id DESC LIMIT ".self::LIMIT."),"
            ."goals AS (SELECT platform,conversation_id,COUNT(*) AS events,SUM(value_rub) AS value_rub,"
            ."SUM(CASE WHEN client_id IS NOT NULL AND client_id<>'' THEN 1 ELSE 0 END) AS identities,"
            ."SUM(CASE WHEN state='pending' THEN 1 ELSE 0 END) AS pending,SUM(CASE WHEN state='ready' THEN 1 ELSE 0 END) AS ready,"
            ."SUM(CASE WHEN state IN ('accepted','uploading') THEN 1 ELSE 0 END) AS accepted,SUM(CASE WHEN state='processed' THEN 1 ELSE 0 END) AS processed,"
            ."SUM(CASE WHEN state NOT IN ('pending','ready','accepted','uploading','processed') OR state IS NULL THEN 1 ELSE 0 END) AS other"
            ." FROM goal_rows GROUP BY platform,conversation_id),"
            ."profiled AS (SELECT n.*,CASE WHEN HEX(p.assessment_namespace)=HEX(?) THEN 1 ELSE 0 END AS current_model_profile,p.observed_at_unix AS profile_at,COALESCE(g.events,0) AS goal_events,COALESCE(g.value_rub,0) AS goal_value_rub,"
            ."COALESCE(g.identities,0) AS identities,COALESCE(g.pending,0) AS goal_pending,COALESCE(g.ready,0) AS goal_ready,"
            ."COALESCE(g.accepted,0) AS goal_accepted,COALESCE(g.processed,0) AS goal_processed,COALESCE(g.other,0) AS goal_other,"
            ."CASE WHEN n.captures<n.inputs THEN 0 WHEN n.indexed_count<n.inputs THEN 1 WHEN n.profile_id IS NULL THEN 2"
            ." WHEN n.profile_id>".$m['vc']." THEN 3 ELSE 4 END AS step"
            ." FROM selected n LEFT JOIN modeled_conversion_profile_versions p ON p.id=n.profile_id"
            ." LEFT JOIN goals g ON g.platform=n.platform AND g.conversation_id=n.conversation_id),";
        $obs="o.project_key='anytour' AND HEX(o.project_key)=HEX('anytour') AND HEX(o.source_key)=HEX('tgmini')"
            ." AND HEX(o.platform)=HEX('telegram') AND HEX(o.counter_id)=HEX('98615635') AND HEX(o.external_user_id)=HEX(n.external_user_id)";
        $exists=static fn(string $c):string=>'EXISTS(SELECT 1 FROM advertising_visit_observations o WHERE '.$c.')';
        $verified=$obs." AND HEX(o.identity_status)=HEX('verified')";
        $client=$verified." AND o.client_id IS NOT NULL AND o.client_id<>''";
        $prior=$client.' AND o.visit_at_unix<=n.last_at';
        $eligible=$prior.' AND o.observed_at_unix<='.$now.' AND o.received_at_unix<='.$now;
        $base.="identified AS (SELECT n.*,CASE WHEN platform<>'telegram' THEN -1"
            ." WHEN NOT ".$exists($obs)." THEN 0 WHEN NOT ".$exists($verified)." THEN 1 WHEN NOT ".$exists($client)." THEN 2"
            ." WHEN NOT ".$exists($prior)." THEN 3 WHEN NOT ".$exists($eligible)." THEN 4"
            ." WHEN (SELECT COUNT(DISTINCT o.client_id) FROM advertising_visit_observations o WHERE ".$eligible.")=1 THEN 5 ELSE 6 END AS tg_reason"
            ." FROM profiled n),cohort_groups AS (SELECT 'all' AS platform UNION ALL SELECT 'max' UNION ALL SELECT 'telegram') ";
        $columns=["g.platform","COUNT(n.conversation_id) AS conversations","COALESCE(SUM(n.inputs),0) AS inputs",
            "(SELECT COUNT(*) FROM native) AS native_rows","(SELECT COUNT(*) FROM goal_rows) AS goal_rows"];
        $conditions=['captured'=>'step>=1','indexed'=>'step>=2','profiled'=>'step>=3','current_model_profiles'=>'step>=3 AND current_model_profile=1','collected'=>'step=4','wait_capture'=>'step=0',
            'wait_index'=>'step=1','wait_profile'=>'step=2','wait_collector'=>'step=3','no_increment'=>'step=4 AND goal_events=0',
            'late_capture'=>'step=0 AND missing_capture_at<='.($now-self::GRACE_SECONDS),
            'late_profile'=>'(step=1 AND missing_index_at<='.($now-self::GRACE_SECONDS).') OR (step=2 AND last_capture<='.($now-self::GRACE_SECONDS).')',
            'late_collector'=>'step=3 AND profile_at<='.($now-self::GRACE_SECONDS),
            'goal_conversations'=>'goal_events>0','stored_identity_conversations'=>'identities>0'];
        foreach(['tg_no_observation','tg_unverified','tg_no_client','tg_later','tg_unavailable','tg_unique','tg_ambiguous']as$i=>$k)$conditions[$k]='tg_reason='.$i;
        foreach($conditions as$k=>$c)$columns[]='COALESCE(SUM(CASE WHEN n.conversation_id IS NOT NULL AND ('.$c.') THEN 1 ELSE 0 END),0) AS '.$k;
        foreach(['goal_events','goal_value_rub','goal_pending','goal_ready','goal_accepted','goal_processed','goal_other']as$k)$columns[]='COALESCE(SUM(n.'.$k.'),0) AS '.$k;
        $columns[]='MIN(CASE WHEN step=0 THEN missing_capture_at WHEN step=1 THEN missing_index_at WHEN step=2 THEN last_capture WHEN step=3 THEN profile_at ELSE NULL END) AS oldest';
        return 'SELECT result.* FROM ('.$base.'SELECT '.implode(',',$columns).' FROM cohort_groups g LEFT JOIN identified n ON g.platform=\'all\' OR g.platform=n.platform GROUP BY g.platform) result';
    }
    private function scalar(string $json,string $path):string
    {
        $v="JSON_EXTRACT(".$json.",'$.".$path."')";return $this->mysql?'JSON_UNQUOTE('.$v.')':$v;
    }
    private function query(string $sql,array $bind=[]):array
    {
        $this->auth();
        if($this->mysql){$sql=preg_replace('/\ASELECT /','SELECT /*+ MAX_EXECUTION_TIME('.min(1500,$this->budget->remainingMilliseconds()).') */ ',$sql,1);$q=$this->pdo->prepare($sql);}
        else $q=$this->budget->prepare($this->pdo,$sql);
        $q->execute($bind);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $this->budget->consumeBytes(strlen(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
        $this->auth();return $rows;
    }
    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
    private function number(mixed $v,bool $money):int|float
    {
        if(!is_int($v)&&!is_float($v)&&!(is_string($v)&&preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D',$v)===1))throw new UnexpectedValueException();
        $n=(float)$v;$max=self::LIMIT*($money?ModeledConversionValueContract::MAX_VALUE_RUB:4);
        if(!is_finite($n)||$n<0||$n>$max||(!$money&&$n!==floor($n)))throw new UnexpectedValueException();
        return $money?round($n,2):(int)$n;
    }
    private function fail(string $reason):array{return ['available'=>false,'reason'=>$reason,'provider_requests'=>0,'conversion_uploads'=>0];}
}
