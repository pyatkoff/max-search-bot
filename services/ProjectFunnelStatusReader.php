<?php
declare(strict_types=1);
require_once __DIR__.'/ProjectFunnelConversions.php';

/** Saved Apple evidence only. This reader never collects, resolves, polls or uploads. */
final class ProjectFunnelStatusReader
{
    private const PROJECT='appleservice39';
    private const BOT='id390521129062_bot';
    private const COUNTER='42611469';
    private const ROW_LIMIT=100;
    private const BYTE_LIMIT=131072;
    private const STATES=['pending','ready','uploading','accepted','unknown','processed','unmatched','failed','expired','retired'];
    private const REASONS=['connection_unavailable','settings_unavailable','approved_policy_mismatch','public_policy_unavailable',
        'outer_transaction','storage_unsupported','query_timeout_unavailable','storage_unavailable','read_deadline','byte_limit','row_limit',
        'scope_unconfirmed','marked_test','historical_policy','record_invalid','history_limit','anchor_unconfirmed','estimate_unconfirmed',
        'recording_receipt_unconfirmed','identity_unconfirmed','stage_unconfirmed','api_receipt_inconsistent'];
    private string $engine='';
    private int $bytes=0;
    private float $deadline;

    public function __construct(private PDO $pdo,?float $deadline=null)
    {
        $this->deadline=min($deadline??INF,microtime(true)+5.0);
    }

    public static function unavailable(string $reason='storage_unavailable'):array
    {
        return ['schema_version'=>1,'scope'=>'appleservice39_stored_funnel','observed_at'=>gmdate('c'),
            'available'=>false,'complete'=>false,'reason'=>self::reason($reason),'approved_public_policy_matches'=>false,
            'outbox'=>null,'observations'=>null,'intake'=>null,'first_saved_forecast'=>null,
            'basis'=>['population'=>'stored_rows_include_test_marks_not_organic_proof',
                'receipt'=>'retained_worker_state_not_fresh_provider_confirmation','processing_time'=>'not_observed',
                'first_selection'=>'earliest_saved_forecast_id_before_evidence_validation'],
            'database_writes'=>0,'provider_requests'=>0,'conversion_requests'=>0];
    }

    private static function reason(string $reason):string
    {
        return in_array($reason,self::REASONS,true)?$reason:'storage_unavailable';
    }

    private static function fail(string $reason):never { throw new RuntimeException($reason); }

    private function remaining():int
    {
        $ms=(int)floor(($this->deadline-microtime(true))*1000);
        if($ms<25)self::fail('read_deadline');
        return min(2000,$ms-10);
    }

    private function rows(string $sql,array $args=[]):array
    {
        $ms=$this->remaining();
        if($this->engine==='mysql')$sql=preg_replace('/\ASELECT /','SELECT /*+ MAX_EXECUTION_TIME('.$ms.') */ ',$sql,1);
        elseif($this->engine==='mariadb')$sql='SET STATEMENT max_statement_time='.number_format($ms/1000,3,'.','').' FOR '.$sql;
        $q=$this->pdo->prepare($sql);$out=[];
        try{
            $q->execute($args);
            while($row=$q->fetch(PDO::FETCH_ASSOC)){
                $this->bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));
                if($this->bytes>self::BYTE_LIMIT)self::fail('byte_limit');
                $out[]=$row;if(count($out)>201)self::fail('row_limit');$this->remaining();
            }
        }finally{$q->closeCursor();}
        return $out;
    }

    private static function json($raw,int $limit=16384):array
    {
        if(!is_string($raw)||strlen($raw)>$limit)self::fail('record_invalid');
        $data=json_decode($raw,true,16);
        if(!is_array($data)||array_is_list($data))self::fail('record_invalid');
        return $data;
    }

    private static function id($value):bool
    {
        return (is_string($value)||is_int($value))&&preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$value)===1;
    }

    private static function clock($raw,int $now):int
    {
        $at=ConversationRecordClock::read($raw);
        if($at===null||$at>$now)self::fail('recording_receipt_unconfirmed');
        return $at;
    }

    private static function approved():array
    {
        $path=__DIR__.'/../resources/project-funnel/appleservice39.json';
        if(is_link($path)||!is_file($path)||filesize($path)>262144)self::fail('public_policy_unavailable');
        try{$settings=ProjectFunnelSettings::validate(json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR),self::PROJECT,self::BOT);}
        catch(Throwable $ignored){self::fail('public_policy_unavailable');}
        if($settings['metrika']!==['counter_id'=>self::COUNTER,'mode'=>'send','credential_source'=>'platform',
            'goals'=>['predictive_value'=>'predict'],'goal_ids'=>['predictive_value'=>'680570232'],'goal_match'=>['predictive_value'=>'exact']]
            ||($settings['prediction']['basis']??null)!=='owner_assumption'||($settings['prediction']['probability']??null)!==0.2
            ||$settings['source_key']!=='max:appleservice39-main'||$settings['advertising_source_key']!=='max-miniapp')self::fail('public_policy_unavailable');
        return $settings;
    }

    public function read(?ProjectMaxConnection $connection,?array $settings,int $now):array
    {
        $out=self::unavailable();$out['observed_at']=gmdate('c',$now);$began=false;$queryOnly=null;
        try{
            $this->remaining();
            if(!$connection||$connection->projectKey()!==self::PROJECT||$connection->botKey()!==self::BOT)self::fail('connection_unavailable');
            $approved=self::approved();$policy=ProjectFunnelConversions::policy($approved);
            if($settings===null)self::fail('settings_unavailable');
            try{$settings=ProjectFunnelSettings::validate($settings,self::PROJECT,self::BOT);}
            catch(Throwable $ignored){self::fail('settings_unavailable');}
            $effective=ProjectFunnelConversions::policy($settings);
            $out['approved_public_policy_matches']=$effective!==null&&$effective['sha256']===$policy['sha256']&&$effective['mode']==='send'
                &&$settings['source_key']===$approved['source_key']&&$settings['advertising_source_key']===$approved['advertising_source_key']
                &&array_map(static fn(array $p):string=>$p['offer_id'],$settings['posts'])===array_map(static fn(array $p):string=>$p['offer_id'],$approved['posts']);
            if($this->pdo->inTransaction())self::fail('outer_transaction');
            $driver=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if($driver==='mysql'){
                $version=(string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                if(stripos($version,'MariaDB')!==false){
                    if(!preg_match('/(?:\A|-)(10\.[0-9]+\.[0-9]+|11\.[0-9]+\.[0-9]+)[^-]*-MariaDB/i',$version,$m)||version_compare($m[1],'10.1.1','<'))self::fail('query_timeout_unavailable');
                    $this->engine='mariadb';
                }elseif(preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)/D',$version,$m)&&version_compare($m[1],'5.7.8','>='))$this->engine='mysql';
                else self::fail('query_timeout_unavailable');
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('SET TRANSACTION READ ONLY');
            }elseif($driver==='sqlite'){
                $this->engine='sqlite';$queryOnly=(int)$this->pdo->query('PRAGMA query_only')->fetchColumn();$this->pdo->exec('PRAGMA query_only=ON');
            }else self::fail('storage_unsupported');
            $this->pdo->beginTransaction();$began=true;
            $out['available']=true;$out['complete']=true;$out['reason']=$out['approved_public_policy_matches']?null:'approved_policy_mismatch';
            $first=null;
            foreach(['outbox','observations','intake']as$section){
                try{
                    if($section==='outbox')[$out[$section],$first]=$this->outbox($policy);
                    elseif($section==='observations')$out[$section]=$this->observations($now);
                    else $out[$section]=$this->intake(array_keys($approved['posts']));
                }catch(Throwable $error){$out[$section]=['available'=>false,'complete'=>false,'reason'=>self::reason($error->getMessage()),'counts'=>null];}
                if(!$out[$section]['complete'])$out['complete']=false;
            }
            if(($out['outbox']['available']??false)===true){
                $out['first_saved_forecast']=['observed'=>$first!==null,'verified'=>false,'reason'=>$first===null?null:'record_invalid',
                    'retained_api_receipt'=>null,'retained_processed_receipt'=>null,'manager_path'=>null];
                if($first!==null){
                    try{$out['first_saved_forecast']=$this->proof($first,$connection,$approved,$policy,$now);}
                    catch(Throwable $error){$out['first_saved_forecast']['reason']=self::reason($error->getMessage());$out['complete']=false;}
                }
            }
            $this->remaining();
        }catch(Throwable $error){$out['available']=false;$out['complete']=false;$out['reason']=self::reason($error->getMessage());}
        finally{
            try{if($began&&$this->pdo->inTransaction())$this->pdo->rollBack();if($queryOnly===0)$this->pdo->exec('PRAGMA query_only=OFF');}
            catch(Throwable $ignored){$out['available']=false;$out['complete']=false;$out['reason']='storage_unavailable';}
        }
        return $out;
    }

    private function outbox(array $policy):array
    {
        $rows=$this->rows("SELECT id,project_key,platform,counter_id,target,transport_target,state,funnel_policy_sha256 FROM max_channel_conversion_outbox WHERE project_key=? AND HEX(project_key)=HEX(?) AND target='funnel_predictive_value' ORDER BY id LIMIT 101",[self::PROJECT,self::PROJECT]);
        $first=$rows[0]['id']??null;$complete=count($rows)<=self::ROW_LIMIT;
        $out=['available'=>true,'complete'=>$complete,'reason'=>$complete?null:'row_limit','examined'=>min(count($rows),self::ROW_LIMIT),'limit'=>self::ROW_LIMIT,
            'population'=>'all_saved_forecast_rows_before_test_and_receipt_validation',
            'approved_policy_states'=>$complete?array_fill_keys(self::STATES,0):null,'other_policy_states'=>$complete?array_fill_keys(self::STATES,0):null,
            'telegram_approved_policy_states'=>$complete?array_fill_keys(self::STATES,0):null,
            'telegram_other_policy_states'=>$complete?array_fill_keys(self::STATES,0):null,
            'excluded_scope_rows'=>$complete?0:null,'unknown_state_rows'=>$complete?0:null];
        if($complete)foreach($rows as$row){
            if($row['project_key']!==self::PROJECT||!in_array($row['platform'],['max','telegram'],true)||$row['counter_id']!==self::COUNTER
                ||$row['target']!=='funnel_predictive_value'||$row['transport_target']!=='predict'){$out['excluded_scope_rows']++;continue;}
            if(!in_array($row['state'],self::STATES,true)){$out['unknown_state_rows']++;continue;}
            $prefix=$row['platform']==='telegram'?'telegram_':'';
            $out[$prefix.($row['funnel_policy_sha256']===$policy['sha256']?'approved_policy_states':'other_policy_states')][$row['state']]++;
        }
        return [$out,$first];
    }

    private function observations(int $now):array
    {
        $rows=$this->rows("SELECT project_key,source_key,platform,counter_id,identity_status,visit_at_unix,CASE WHEN client_id IS NOT NULL AND client_id<>'' THEN 1 ELSE 0 END AS client_present FROM advertising_visit_observations WHERE project_key=? AND HEX(project_key)=HEX(?) ORDER BY id DESC LIMIT 101",[self::PROJECT,self::PROJECT]);
        $complete=count($rows)<=self::ROW_LIMIT;$counts=['exact_scope_rows'=>0,'verified_rows'=>0,'verified_with_client_rows'=>0,'other_scope_rows'=>0];
        $moscow=new DateTimeZone('Europe/Moscow');
        $today=(new DateTimeImmutable('@'.$now))->setTimezone($moscow)->setTime(0,0);
        $days=[$today->format('Y-m-d')=>$today,$today->modify('-1 day')->format('Y-m-d')=>$today->modify('-1 day')];
        $telegramDays=$days;
        foreach(array_slice($rows,0,self::ROW_LIMIT)as$row){
            if($row['project_key']===self::PROJECT&&$row['source_key']==='apple-tg-miniapp'&&$row['platform']==='telegram'&&$row['counter_id']===self::COUNTER){
                $day=$today->setTimestamp((int)$row['visit_at_unix'])->setTime(0,0);
                $telegramDays[$day->format('Y-m-d')]=$day;
            }
            if($row['project_key']!==self::PROJECT||$row['source_key']!=='max-miniapp'||$row['platform']!=='max'||$row['counter_id']!==self::COUNTER){$counts['other_scope_rows']++;continue;}
            $counts['exact_scope_rows']++;
            if($row['identity_status']==='verified'){$counts['verified_rows']++;if((int)$row['client_present']===1)$counts['verified_with_client_rows']++;}
            $day=$today->setTimestamp((int)$row['visit_at_unix'])->setTime(0,0);
            $days[$day->format('Y-m-d')]=$day;
        }
        return ['available'=>true,'complete'=>$complete,'reason'=>$complete?null:'row_limit','examined'=>min(count($rows),self::ROW_LIMIT),'limit'=>self::ROW_LIMIT,
            'population'=>'observation_rows_not_unique_people_or_resolved_forecasts','counts'=>$complete?$counts:null,
            'click_tags'=>$complete?$this->clickTagCounts($days,'max-miniapp','max'):null,
            'telegram_click_tags'=>$complete?$this->clickTagCounts($telegramDays,'apple-tg-miniapp','telegram'):null];
    }

    /** Aggregate in SQL so even the private result rows carry no click identifiers. */
    private function clickTagCounts(array $days,string $source,string $platform):array
    {
        $ranges=['SELECT CAST(NULL AS SIGNED) AS starts_at, CAST(NULL AS SIGNED) AS ends_at'];
        $parameters=[];
        $labels=[];
        foreach($days as $label=>$day){
            $ranges[]='SELECT CAST(? AS SIGNED), CAST(? AS SIGNED)';
            $parameters[]=$day->getTimestamp();
            $parameters[]=$day->modify('+1 day')->getTimestamp();
            $labels[$day->getTimestamp()]=$label;
        }
        $rangeSql=implode(' UNION ALL ',$ranges);
        $rows=$this->rows("SELECT bounds.starts_at, COUNT(v.visit_at_unix) AS observation_rows,
                COUNT(NULLIF(v.yclid,'')) AS nonempty_rows, COUNT(DISTINCT NULLIF(v.yclid,'')) AS distinct_yclids
            FROM ($rangeSql) bounds LEFT JOIN (
                SELECT visit_at_unix,yclid FROM advertising_visit_observations
                WHERE project_key=? AND HEX(project_key)=HEX(?) AND HEX(source_key)=HEX(?)
                    AND HEX(platform)=HEX(?) AND HEX(counter_id)=HEX(?)
                ORDER BY id DESC LIMIT 101
            ) v ON bounds.starts_at IS NULL OR (v.visit_at_unix>=bounds.starts_at AND v.visit_at_unix<bounds.ends_at)
            GROUP BY bounds.starts_at ORDER BY bounds.starts_at",
            [...$parameters,self::PROJECT,self::PROJECT,$source,$platform,self::COUNTER]);
        $summary=['all_history'=>null,'by_moscow_visit_day'=>[]];
        foreach($rows as $row){
            $counts=['observation_rows'=>(int)$row['observation_rows'],'nonempty_rows'=>(int)$row['nonempty_rows'],'distinct_yclids'=>(int)$row['distinct_yclids']];
            if($row['starts_at']===null)$summary['all_history']=$counts;
            else $summary['by_moscow_visit_day'][$labels[(int)$row['starts_at']]]=$counts;
        }
        return $summary;
    }

    private function intake(array $catalogPosts):array
    {
        $rows=$this->rows("SELECT c.id,c.project_key,c.channel,c.is_test,CASE WHEN LENGTH(c.metadata_json)<=4096 THEN c.metadata_json ELSE NULL END AS metadata_json,
            CASE WHEN EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='project_funnel_entry') THEN 1 ELSE 0 END AS entry_recorded,
            CASE WHEN EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='waiting_manager') THEN 1 ELSE 0 END AS handoff_recorded,
            CASE WHEN EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='bot_started') THEN 1 ELSE 0 END AS bot_start_recorded,
            CASE WHEN EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='bot_started'
                AND INSTR(e.payload_json, '\"repair_entry_reply\":')>0) THEN 1 ELSE 0 END AS reviewed_start_recorded,
            -- The latest start is classified by finite tokens in its saved JSON.
            -- SELECT returns categories only, never payload, user, post, ClientID or text.
            (SELECT CASE
                WHEN INSTR(e.payload_json, '\"repair_start_class\":\"recognized\"')>0 THEN 'recognized'
                WHEN INSTR(e.payload_json, '\"repair_start_class\":\"empty\"')>0 THEN 'empty'
                WHEN INSTR(e.payload_json, '\"repair_start_class\":\"unrecognized_apple\"')>0 THEN 'unrecognized_apple'
                WHEN INSTR(e.payload_json, '\"repair_start_class\":\"other\"')>0 THEN 'other'
                ELSE 'historical' END
             FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='bot_started'
             ORDER BY e.id DESC LIMIT 1) AS latest_start_class,
            (SELECT CASE
                WHEN INSTR(e.payload_json, '\"repair_funnel_attempt\":\"recorded\"')>0 THEN 'recorded'
                WHEN INSTR(e.payload_json, '\"repair_funnel_attempt\":\"not_recorded\"')>0 THEN 'not_recorded'
                WHEN INSTR(e.payload_json, '\"repair_funnel_attempt\":\"publication_pending\"')>0 THEN 'publication_pending'
                WHEN INSTR(e.payload_json, '\"repair_funnel_attempt\":\"writer_error\"')>0 THEN 'writer_error'
                ELSE 'historical' END
             FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='bot_started'
             ORDER BY e.id DESC LIMIT 1) AS latest_funnel_attempt,
            CASE WHEN EXISTS(SELECT 1 FROM messages m WHERE m.conversation_id=c.id AND m.direction='outbound' AND m.sender_type='manager') THEN 1 ELSE 0 END AS manager_reply_recorded
            FROM conversations c WHERE c.project_key=? AND HEX(c.project_key)=HEX(?) AND c.channel='max' ORDER BY c.id DESC LIMIT 101",[self::PROJECT,self::PROJECT]);
        // Bounded, privacy-safe diagnosis: a reviewed bot start without a funnel event
        // needs investigation, not retroactive attribution or a fabricated conversion.
        $complete=count($rows)<=self::ROW_LIMIT;$counts=['unmarked_test_conversations'=>0,'marked_test_conversations'=>0,
            'entry_recorded'=>0,'handoff_recorded'=>0,'bot_start_recorded'=>0,'reviewed_start_recorded'=>0,
            'reviewed_start_without_entry'=>0,'reviewed_only_navigation_without_entry'=>0,
            'reviewed_only_catalog_without_entry'=>0,'reviewed_mixed_or_unconfirmed_without_entry'=>0,'no_saved_bot_start'=>0,'manager_reply_recorded'=>0,'unconfirmed_scope'=>0,
            'latest_start_recognized'=>0,'latest_start_empty'=>0,'latest_start_unrecognized_apple'=>0,
            'latest_start_other'=>0,'latest_start_historical'=>0,
            'latest_recognized_funnel_recorded'=>0,'latest_recognized_funnel_not_recorded'=>0,
            'latest_recognized_funnel_publication_pending'=>0,'latest_recognized_funnel_writer_error'=>0,
            'latest_recognized_funnel_historical'=>0];
        $gapIds=[];
        foreach(array_slice($rows,0,self::ROW_LIMIT)as$row){
            $meta=is_string($row['metadata_json'])?json_decode($row['metadata_json'],true):null;
            if($row['project_key']!==self::PROJECT||$row['channel']!=='max'||!is_array($meta)||($meta['max_bot_key']??null)!==self::BOT){$counts['unconfirmed_scope']++;continue;}
            if((string)$row['is_test']!=='0'){$counts['marked_test_conversations']++;continue;}
            $counts['unmarked_test_conversations']++;$counts['entry_recorded']+=(int)$row['entry_recorded'];$counts['handoff_recorded']+=(int)$row['handoff_recorded'];
            $start=(int)$row['bot_start_recorded']===1;$reviewed=(int)$row['reviewed_start_recorded']===1;$funnel=(int)$row['entry_recorded']===1;
            $counts['bot_start_recorded']+=(int)$start;
            $counts['reviewed_start_recorded']+=(int)$reviewed;
            if($reviewed&&!$funnel){$counts['reviewed_start_without_entry']++;$gapIds[]=(int)$row['id'];}
            $counts['no_saved_bot_start']+=(int)!$start;
            if($start){
                $class=(string)($row['latest_start_class']??'');
                if(!in_array($class,['recognized','empty','unrecognized_apple','other'],true))$class='historical';
                $counts['latest_start_'.$class]++;
                if($class==='recognized'){
                    $outcome=(string)($row['latest_funnel_attempt']??'');
                    if(!in_array($outcome,['recorded','not_recorded','publication_pending','writer_error'],true))$outcome='historical';
                    $counts['latest_recognized_funnel_'.$outcome]++;
                }
            }
            $counts['manager_reply_recorded']+=(int)((int)$row['manager_reply_recorded']===1);
        }
        if($complete&&$gapIds)$this->classifyReviewedGaps($gapIds,$catalogPosts,$counts);
        return ['available'=>true,'complete'=>$complete,'reason'=>$complete?null:'row_limit','examined'=>min(count($rows),self::ROW_LIMIT),'limit'=>self::ROW_LIMIT,
            'population'=>'is_test_zero_only_not_organic_evidence','counts'=>$complete?$counts:null];
    }


    /** Classify all native starts of each gap. Return only counts, including undecidable histories. */
    private function classifyReviewedGaps(array $gapIds,array $catalogPosts,array &$counts):void
    {
        $counts['reviewed_mixed_or_unconfirmed_without_entry']=count($gapIds);
        $marks=static fn(array $values):string=>implode(',',array_fill(0,count($values),'?'));
        $scalar=function(string $path):string{
            $value="JSON_EXTRACT(doc,'$.".$path."')";
            return $this->engine==='sqlite'?$value:"JSON_UNQUOTE(".$value.")";
        };
        $post=$scalar('repair_entry_reply.post_id');
        $rootKey=$scalar('delivery_key');$key=$scalar('repair_entry_reply.delivery_key');
        $bot=$scalar('bot_key');$replyBot=$scalar('repair_entry_reply.bot_key');
        $project=$scalar('repair_entry_reply.project_key');$conversation=$scalar('repair_entry_reply.conversation_id');
        // HEX comparisons stay case-sensitive with either database's default collation.
        // No capture, identifier, post name or customer text is projected into the report.
        $sql="SELECT conversation_id,CASE
            WHEN doc IS NULL THEN 'unconfirmed'
            WHEN JSON_EXTRACT(doc,'$.repair_entry_reply') IS NULL THEN 'skip'
            WHEN NOT COALESCE(HEX($project)=HEX(?) AND HEX($bot)=HEX(?) AND HEX($replyBot)=HEX($bot)
                AND CAST($conversation AS CHAR)=CAST(conversation_id AS CHAR)
                AND LENGTH($rootKey)=64 AND HEX($key)=HEX($rootKey),0) THEN 'unconfirmed'
            WHEN HEX($post) IN (?,?) THEN 'navigation'
            WHEN HEX($post) IN (".$marks($catalogPosts).") THEN 'catalog'
            ELSE 'unconfirmed' END AS post_kind
            FROM (SELECT e.id,e.conversation_id,CASE WHEN LENGTH(e.payload_json)<=32768 AND JSON_VALID(e.payload_json)=1
                THEN e.payload_json END AS doc FROM conversation_events e
                WHERE e.conversation_id IN (".$marks($gapIds).") AND e.event_type='bot_started'
                ORDER BY e.id LIMIT 201) captured";
        $hex=static fn(string $value):string=>strtoupper(bin2hex($value));
        try{$rows=$this->rows($sql,[self::PROJECT,self::BOT,$hex('models-index'),$hex('services-index'),...array_map($hex,$catalogPosts),...$gapIds]);}
        catch(PDOException $e){
            // Older MariaDB/SQLite installations may lack JSON functions. Keep their prior counts and leave these gaps undecided.
            if(!in_array((string)($e->errorInfo[1]??''),['1305','1'],true)||stripos($e->getMessage(),'function')===false)throw $e;
            return;
        }
        if(count($rows)>200)return;
        $kinds=[];
        foreach($rows as$row)if($row['post_kind']!=='skip')$kinds[(int)$row['conversation_id']][$row['post_kind']]=true;
        foreach($kinds as$seen){
            if(count($seen)!==1)continue;
            $kind=array_key_first($seen);
            if($kind==='navigation'||$kind==='catalog'){
                $counts['reviewed_only_'.$kind.'_without_entry']++;
                $counts['reviewed_mixed_or_unconfirmed_without_entry']--;
            }
        }
    }

    private function proof($id,ProjectMaxConnection $connection,array $settings,array $policy,int $now):array
    {
        $rows=$this->rows("SELECT id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,client_id,state,attempts,upload_id,upload_status,last_error,funnel_policy_sha256,value_rub,
            CASE WHEN LENGTH(funnel_context_json)<=16384 THEN funnel_context_json ELSE NULL END AS funnel_context_json
            FROM max_channel_conversion_outbox WHERE id=? LIMIT 2",[$id]);
        if(count($rows)!==1)self::fail('record_invalid');$row=$rows[0];
        if($row['project_key']!==self::PROJECT||!in_array($row['platform'],['max','telegram'],true)||$row['counter_id']!==self::COUNTER
            ||$row['target']!=='funnel_predictive_value'||$row['transport_target']!=='predict')self::fail('scope_unconfirmed');
        if($row['platform']==='telegram'){
            $binding=AppleService39TelegramConnection::forecastBinding($this->pdo,$this->rows(...));
            if(!$binding)self::fail('scope_unconfirmed');
            $identity=ProjectFunnelConversions::identity($this->pdo,$row,$settings,$now,$binding,$this->rows(...));
            if(!$identity||$row['client_id']!==$identity['client_id'])self::fail('identity_unconfirmed');
            [$api,$processed]=self::retainedReceipt($row);
            return ['observed'=>true,'verified'=>true,'reason'=>null,'platform'=>'telegram',
                'retained_api_receipt'=>$api,'retained_processed_receipt'=>$processed,'manager_path'=>null];
        }
        $cs=$this->rows("SELECT c.id,c.project_key,c.channel,c.customer_id,c.customer_channel_id,c.external_chat_id,c.is_test,
            CASE WHEN LENGTH(c.metadata_json)<=16384 THEN c.metadata_json ELSE NULL END AS metadata_json,
            cc.customer_id AS channel_customer,cc.project_key AS channel_project,cc.channel AS channel_kind,cc.external_user_id,cc.external_chat_id AS channel_chat
            FROM conversations c LEFT JOIN customer_channels cc ON cc.id=c.customer_channel_id WHERE c.id=? LIMIT 2",[$row['conversation_id']]);
        if(count($cs)!==1)self::fail('scope_unconfirmed');$c=$cs[0];$meta=self::json($c['metadata_json']);
        if($c['project_key']!==self::PROJECT||$c['channel']!=='max'||$c['channel_project']!==self::PROJECT||$c['channel_kind']!=='max'
            ||(string)$c['customer_id']!==(string)$c['channel_customer']||$c['external_user_id']!==$row['external_user_id']||!self::id($c['external_user_id'])
            ||$c['external_chat_id']!=='-'.$c['external_user_id']||$c['channel_chat']!==$c['external_chat_id']||($meta['max_bot_key']??null)!==self::BOT)self::fail('scope_unconfirmed');
        if((string)$c['is_test']!=='0')self::fail('marked_test');
        if($row['funnel_policy_sha256']!==$policy['sha256'])self::fail('historical_policy');
        if(!self::id($row['event_unix'])||(int)$row['event_unix']>$now)self::fail('record_invalid');$eventAt=(int)$row['event_unix'];
        $context=self::json($row['funnel_context_json']);$estimate=$context['estimate']??null;unset($context['estimate']);
        if(!is_array($estimate))self::fail('estimate_unconfirmed');
        $model=$settings['prediction'];
        foreach(['model_id'=>'model_id','model_version'=>'version','evidence_sha256'=>'evidence_sha256','approved_at_unix'=>'approved_at_unix','probability'=>'probability','price_basis'=>'price_basis']as$key=>$source)
            if(($estimate[$key]??null)!==$model[$source])self::fail('estimate_unconfirmed');
        if(($estimate['basis']??null)!=='owner_assumed_probability_not_payment'||($estimate['event_unix']??null)!==$eventAt
            ||!is_string($estimate['value_rub']??null)||!preg_match('/\A[0-9]{1,10}\.[0-9]{2}\z/D',$estimate['value_rub'])||(float)$estimate['value_rub']<=0
            ||$estimate['value_rub']!==$row['value_rub']||$model['approved_at_unix']>$eventAt)self::fail('estimate_unconfirmed');
        $price=$estimate['price']??null;$pc=$estimate['price_context']??null;
        if(!is_array($price)||!is_array($pc)||($price['currency']??null)!=='RUB')self::fail('estimate_unconfirmed');
        // Check the saved equation in integer kopecks; never replace it using a newer price.
        if(!is_string($price['amount_rub']??null)||!preg_match('/\A[0-9]{1,10}\.[0-9]{2}\z/D',$price['amount_rub']))self::fail('estimate_unconfirmed');
        $savedPrice=explode('.',$price['amount_rub']);$savedValue=explode('.',$estimate['value_rub']);
        if(intdiv((int)$savedPrice[0]*100+(int)$savedPrice[1]+2,5)!==(int)$savedValue[0]*100+(int)$savedValue[1])self::fail('estimate_unconfirmed');
        $matches=0;foreach($model['prices']as$approvedPrice){$match=true;foreach(['model','service','amount_rub','source_id']as$field)if(($price[$field]??null)!==$approvedPrice[$field])$match=false;if($match)$matches++;}
        if($matches!==1||($price['model']??null)!==($pc['model']??null)||($price['service']??null)!==($pc['service']??null))self::fail('estimate_unconfirmed');
        $events=$this->rows("SELECT id,event_type,actor_type,actor_id,CASE WHEN LENGTH(payload_json)<=65536 THEN payload_json ELSE NULL END AS payload_json FROM conversation_events
            WHERE conversation_id=? AND event_type IN ('bot_started','project_funnel_entry','waiting_manager','project_funnel_stage','manager_taken','manager_message','manager_message_failed') ORDER BY id LIMIT 201",[$c['id']]);
        if(count($events)>=201)self::fail('history_limit');
        $entries=[];$bots=[];
        foreach($events as$event){
            if(!in_array($event['event_type'],['bot_started','project_funnel_entry'],true))continue;
            $data=self::json($event['payload_json'],65536);$event['data']=$data;
            $key=$data[$event['event_type']==='bot_started'?'delivery_key':'entry_key']??null;
            if(!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key))self::fail('recording_receipt_unconfirmed');
            if($event['event_type']==='bot_started')$bots[$key][]=$event;else $entries[$key][]=$event;
        }
        $key=$context['entry_key']??null;
        if(!is_string($key)||count($entries[$key]??[])!==1||$entries[$key][0]['data']!==$context)self::fail('anchor_unconfirmed');
        $anchor=$entries[$key][0];$anchorAt=self::clock($anchor['payload_json'],$eventAt);
        self::entryScope($context,$c,$settings);$visit=self::visit($context);
        $original=self::botReceipt($bots[$key]??[],$anchor,$c,$connection,$eventAt);
        $selected=$original;$selectedEntry=$anchor;
        if(isset($pc['refinement'])){
            $ref=$pc['refinement'];
            if(!is_array($ref)||($ref['version']??null)!==1||($ref['basis']??null)!=='same_original_visit_explicit_model_service'
                ||$original['scope']!=='model'||$original['context']['service']!=='')self::fail('recording_receipt_unconfirmed');
            self::refinementReceipt($ref['original']??null,$anchor,$original);
            $selectedKey=$pc['entry_key']??null;
            if(!is_string($selectedKey)||count($entries[$selectedKey]??[])!==1)self::fail('recording_receipt_unconfirmed');
            $selectedEntry=$entries[$selectedKey][0];self::entryScope($selectedEntry['data'],$c,$settings);
            foreach(['project_key','bot_key','source_key','external_user_id','advertising_scope','post_id','offer_id']as$field)
                if(($selectedEntry['data'][$field]??null)!==($context[$field]??null))self::fail('recording_receipt_unconfirmed');
            if(self::visit($selectedEntry['data'])!==$visit)self::fail('identity_unconfirmed');
            $selected=self::botReceipt($bots[$selectedKey]??[],$selectedEntry,$c,$connection,$now);
            self::refinementReceipt($ref['selected']??null,$selectedEntry,$selected);
            if($selected['scope']!=='model_service'||$selected['context']['model']!==$original['context']['model']
                ||$selected['at']<$anchorAt||$selected['at']>$eventAt||(int)$selected['id']<=(int)$anchor['id'])self::fail('recording_receipt_unconfirmed');
        }
        if(($pc['model']??null)!==$selected['context']['model']||($pc['service']??null)!==$selected['context']['service']
            ||($pc['entry_code']??null)!==$selected['context']['entry_code']||($pc['entry_key']??null)!==$selectedEntry['data']['entry_key']
            ||($pc['post_id']??null)!==$context['post_id']||($pc['source_event_id']??null)!==(string)$selected['id']
            ||($pc['recorded_at_unix']??null)!==$selected['at'])self::fail('recording_receipt_unconfirmed');
        $this->identity($row,$context,$visit,$eventAt);
        $messages=$this->rows("SELECT direction,sender_type,CASE WHEN LENGTH(metadata_json)<=4096 THEN metadata_json ELSE NULL END AS metadata_json FROM messages WHERE conversation_id=? ORDER BY id LIMIT 101",[$c['id']]);
        if(count($messages)>self::ROW_LIMIT)self::fail('history_limit');
        $manager=['request_recorded'=>false,'taken_recorded'=>false,'outbound_message_recorded'=>false,'message_event_recorded'=>false,'failure_event_recorded'=>false];
        $stage=$estimate['stage']??null;$stageAt=null;$managerAt=null;
        foreach($messages as$message){
            $at=ConversationRecordClock::read($message['metadata_json']);if($at===null||$at<$anchorAt||$at>$now)continue;
            if($message['direction']==='outbound'&&$message['sender_type']==='manager'){$manager['outbound_message_recorded']=true;$managerAt=$managerAt??$at;}
            if($at>$eventAt||$message['direction']!=='inbound'||$message['sender_type']!=='customer')continue;
            $data=json_decode($message['metadata_json'],true);
            if(!in_array($data['type']??null,['message','callback','contact'],true))continue;
            if($stage==='customer_activity'||($stage==='customer_reply'&&$managerAt!==null&&$at>=$managerAt&&in_array($data['type'],['message','contact'],true)))$stageAt=$stageAt??$at;
        }
        foreach($events as$event){
            $at=ConversationRecordClock::read($event['payload_json']);if($at===null||$at<$anchorAt||$at>$now)continue;
            $type=$event['event_type'];$data=json_decode($event['payload_json'],true);
            if($type==='waiting_manager')$manager['request_recorded']=true;
            $flag=['manager_taken'=>'taken_recorded','manager_message'=>'message_event_recorded','manager_message_failed'=>'failure_event_recorded'][$type]??null;
            if($flag!==null)$manager[$flag]=$event['actor_type']==='manager'&&self::id($event['actor_id'])
                &&($type==='manager_taken'||(($data['project_key']??null)===self::PROJECT&&($data['channel']??null)==='max'))?true:null;
            if($at>$eventAt)continue;
            if($type==='waiting_manager'&&$stage==='manager_request')$stageAt=$stageAt??$at;
            if($type==='project_funnel_stage'&&in_array($stage,['working','offer_sent','booking'],true)
                &&($data['project_key']??null)===self::PROJECT&&($data['bot_key']??null)===self::BOT&&($data['stage']??null)===$stage)$stageAt=$stageAt??$at;
        }
        if($stageAt===null||$eventAt!==max($stageAt,$selected['at']))self::fail('stage_unconfirmed');
        [$api,$processed]=self::retainedReceipt($row);
        return ['observed'=>true,'verified'=>true,'reason'=>null,'retained_api_receipt'=>$api,'retained_processed_receipt'=>$processed,'manager_path'=>$manager];
    }

    private static function entryScope(array $entry,array $c,array $settings):void
    {
        if(($entry['version']??null)!==1||($entry['project_key']??null)!==self::PROJECT||($entry['bot_key']??null)!==self::BOT
            ||($entry['source_key']??null)!=='max:appleservice39-main'||($entry['external_user_id']??null)!==$c['external_user_id']
            ||($entry['advertising_scope']??null)!==ProjectFunnelEntry::scope($settings)||!is_string($entry['post_id']??null)
            ||!isset($settings['posts'][$entry['post_id']])||($entry['offer_id']??null)!==$settings['posts'][$entry['post_id']]['offer_id'])self::fail('scope_unconfirmed');
    }

    private static function visit(array $entry):string
    {
        $obs=$entry['observations']??null;
        if(($entry['attribution_state']??null)!=='matched'||!is_array($obs)||!array_is_list($obs)||count($obs)!==1||!is_array($obs[0])
            ||!is_string($obs[0]['visit_id']??null)||$obs[0]['visit_id']===''||strlen($obs[0]['visit_id'])>191||!self::id($obs[0]['source_cursor']??null))self::fail('identity_unconfirmed');
        return $obs[0]['visit_id'];
    }

    private static function botReceipt(array $rows,array $entry,array $c,ProjectMaxConnection $connection,int $now):array
    {
        if(count($rows)!==1)self::fail('recording_receipt_unconfirmed');$row=$rows[0];$data=$row['data'];$ctx=$data['repair_context']??null;$intent=$data['repair_entry_reply']??null;
        $at=self::clock($row['payload_json'],$now);$entryAt=self::clock($entry['payload_json'],$now);$key=$entry['data']['entry_key'];
        if((int)$row['id']>=(int)$entry['id']||$at>$entryAt||!is_array($ctx)||!is_array($intent)
            ||($data['delivery_key']??null)!==$key||($data['bot_key']??null)!==self::BOT||($data['source_key']??null)!=='max:appleservice39-main'
            ||($intent['delivery_key']??null)!==$key||($intent['project_key']??null)!==self::PROJECT||($intent['bot_key']??null)!==self::BOT
            ||($intent['conversation_id']??null)!==(int)$c['id']||($intent['customer_id']??null)!==(int)$c['customer_id']
            ||($intent['customer_channel_id']??null)!==(int)$c['customer_channel_id']||($intent['external_user_id']??null)!==$c['external_user_id']
            ||($intent['post_id']??null)!==$entry['data']['post_id']||!in_array($intent['state']??null,['pending','attempted','delivered','unknown','suppressed'],true)
            ||!is_string($intent['entry_code']??null)||($ctx['entry_code']??null)!==$intent['entry_code'])self::fail('recording_receipt_unconfirmed');
        $canonical=RepairProjectSettings::entry($connection,$intent['entry_code']);
        if(!$canonical||$canonical['post_id']!==$entry['data']['post_id']||AppleService39PilotCatalog::entryContext($canonical,$ctx,$intent['entry_code'])!==$ctx)self::fail('recording_receipt_unconfirmed');
        return ['id'=>$row['id'],'at'=>$at,'context'=>$ctx,'scope'=>$canonical['scope']??'repair'];
    }

    private static function refinementReceipt($receipt,array $entry,array $bot):void
    {
        $expected=['funnel_event_id'=>(string)$entry['id'],'entry_key'=>$entry['data']['entry_key'],
            'funnel_recorded_at_unix'=>ConversationRecordClock::read($entry['payload_json']),'bot_event_id'=>(string)$bot['id'],'bot_recorded_at_unix'=>$bot['at']];
        if($receipt!==$expected)self::fail('recording_receipt_unconfirmed');
    }

    private function identity(array $row,array $anchor,string $visit,int $eventAt):void
    {
        if(!is_string($row['client_id'])||preg_match('/\A[1-9][0-9]{0,39}\z/D',$row['client_id'])!==1)self::fail('identity_unconfirmed');
        $rows=$this->rows("SELECT project_key,source_key,platform,counter_id,source_cursor,visit_id,visit_at_unix,external_user_id,identity_status,client_id,post_ref,offer_ref FROM advertising_visit_observations
            WHERE project_key=? AND source_key='max-miniapp' AND platform='max' AND counter_id=? AND visit_id=? ORDER BY id LIMIT 101",[self::PROJECT,self::COUNTER,$visit]);
        if(count($rows)>self::ROW_LIMIT)self::fail('history_limit');$found=false;$original=false;
        foreach($rows as$observation){
            if($observation['project_key']!==self::PROJECT||$observation['source_key']!=='max-miniapp'||$observation['platform']!=='max'||$observation['counter_id']!==self::COUNTER
                ||$observation['visit_id']!==$visit||$observation['external_user_id']!==$row['external_user_id']||$observation['post_ref']!==$anchor['post_id']||$observation['offer_ref']!==$anchor['offer_id']
                ||$observation['identity_status']!=='verified'||!self::id($observation['visit_at_unix'])||(int)$observation['visit_at_unix']>$eventAt
                ||(int)$observation['visit_at_unix']<$eventAt-21*86400)self::fail('identity_unconfirmed');
            if((string)$observation['source_cursor']===(string)$anchor['observations'][0]['source_cursor'])$original=true;
            if($observation['client_id']==='')continue;
            if($observation['client_id']!==$row['client_id'])self::fail('identity_unconfirmed');$found=true;
        }
        if(!$found||!$original)self::fail('identity_unconfirmed');
    }

    private static function retainedReceipt(array $row):array
    {
        $state=$row['state'];$status=$row['upload_status'];$id=$row['upload_id'];
        if(!in_array($state,self::STATES,true))self::fail('api_receipt_inconsistent');
        if(($id===null||$id==='')&&($status===null||$status==='')){
            if(in_array($state,['accepted','processed','unmatched'],true))self::fail('api_receipt_inconsistent');
            return [false,false];
        }
        if(!self::id($id)||(int)$row['attempts']<1)self::fail('api_receipt_inconsistent');
        if($state==='processed'&&$status==='PROCESSED'&&$row['last_error']==='')return [true,true];
        if(($state==='accepted'&&in_array($status,['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'],true))
            ||($state==='unmatched'&&$status==='LINKAGE_FAILURE')||($state==='failed'&&$status==='PROCESSED'&&$row['last_error']==='api_row_rejected'))return [true,false];
        self::fail('api_receipt_inconsistent');
    }
}
