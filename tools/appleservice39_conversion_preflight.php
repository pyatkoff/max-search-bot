<?php
declare(strict_types=1);

/** Finite Apple-only observation. No worker, provider, credential accessor or write path is called. */
final class As39ConversionPreflight
{
    private PDO $pdo;
    private float $deadline;
    private string $engine;
    private int $identityReads = 0;
    private const STAGES = ['customer_activity','manager_request','customer_reply','working','offer_sent','booking','won'];
    private const STATES = ['pending','ready','uploading','accepted','unknown','processed','unmatched','failed','expired','retired','other'];

    public function __construct(PDO $pdo, float $deadline)
    {
        $this->pdo=$pdo; $this->deadline=$deadline;
        if ($pdo->inTransaction()) throw new RuntimeException('storage_busy');
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver==='mysql') {
            $version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (stripos($version,'MariaDB')!==false) $this->engine='mariadb';
            elseif (preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)/D',$version,$match)===1
                && version_compare($match[1],'5.7.8','>=')) $this->engine='mysql';
            else throw new RuntimeException('query_timeout_unavailable');
            $this->bound();
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } elseif ($driver==='sqlite' && defined('AS39_CONVERSION_PREFLIGHT_LIBRARY_ONLY')) {
            $this->engine='sqlite_fixture';
            $pdo->exec('PRAGMA query_only=ON');
        } else throw new RuntimeException('storage_unsupported');
        $this->remaining();
        $pdo->beginTransaction();
    }

    private function remaining(): int
    {
        $remaining=(int)floor(($this->deadline-microtime(true))*1000);
        if ($remaining<25) throw new RuntimeException('read_deadline');
        return min(2000,$remaining-10);
    }

    private function bound(): void
    {
        $ms=$this->remaining();
        if ($this->engine==='mariadb') $this->pdo->exec('SET SESSION max_statement_time='.number_format($ms/1000,3,'.',''));
        elseif ($this->engine==='mysql') $this->pdo->exec('SET SESSION MAX_EXECUTION_TIME='.$ms);
    }

    private function rows(string $sql, array $args=[]): array
    {
        $this->bound();
        $q=$this->pdo->prepare($sql); $q->execute($args);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
        $this->remaining();
        if (count($rows)>1001) throw new RuntimeException('read_limit');
        return $rows;
    }

    private static function page(array &$rows, int $limit): array
    {
        $truncated=count($rows)>$limit;
        $rows=array_slice($rows,0,$limit);
        return ['examined'=>count($rows),'limit'=>$limit,'truncated'=>$truncated,
            'coverage'=>$truncated?'bounded_sample':'complete_scope','order'=>'id_desc'];
    }

    private static function age($unix, int $now): string
    {
        $at=(int)$unix;
        if ($at<946684800 || $at>4102444800) return 'invalid';
        return $at>$now?'future':($at<$now-21*86400?'older_than_21_days':'within_21_days');
    }

    private function identity(array $row, array $settings, int $now): ?array
    {
        if ($this->identityReads>=100) throw new RuntimeException('identity_limit');
        $this->bound(); $this->identityReads++;
        $identity=ProjectFunnelConversions::identity($this->pdo,$row,$settings,$now);
        $this->remaining();
        return $identity;
    }

    private function dedup(): string
    {
        try {
            if ($this->engine==='sqlite_fixture') {
                $rows=$this->rows("SELECT i.seqno AS sequence,i.name AS column_name,l.[unique] AS is_unique FROM pragma_index_list('max_channel_conversion_outbox') l JOIN pragma_index_info(l.name) i WHERE l.name='uq_max_channel_stage' ORDER BY i.seqno LIMIT 5");
            } else {
                $rows=$this->rows("SELECT SEQ_IN_INDEX AS sequence,COLUMN_NAME AS column_name,1-NON_UNIQUE AS is_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_conversion_outbox' AND INDEX_NAME='uq_max_channel_stage' ORDER BY SEQ_IN_INDEX LIMIT 5");
            }
            return count($rows)===3 && array_column($rows,'column_name')===['project_key','conversation_id','target']
                && count(array_filter($rows,static fn($r)=>(string)$r['is_unique']==='1'))===3 ? 'present' : 'not_confirmed';
        } catch (Throwable $error) {
            $this->remaining();
            return 'unavailable';
        }
    }

    public function read(array $settings, bool $override, int $now): array
    {
        $this->remaining();
        if (!$this->pdo->inTransaction()) throw new RuntimeException('readonly_transaction_missing');
        $metric=$settings['metrika']??[];
        if (($settings['project_key']??null)!=='appleservice39' || ($settings['bot_key']??null)!=='id390521129062_bot'
            || ($settings['source_key']??null)!=='max:appleservice39-main' || ($settings['advertising_source_key']??null)!=='max-miniapp'
            || ($metric['counter_id']??null)!=='42611469' || !in_array($metric['mode']??null,['off','collect','send'],true)
            || ($metric['credential_source']??null)!=='platform' || ($metric['goals']??null)!==['predictive_value'=>'predict']
            || ($metric['goal_ids']??null)!==['predictive_value'=>'680570232']
            || ($metric['goal_match']??null)!==['predictive_value'=>'exact']) throw new RuntimeException('policy_changed');
        try { $settings=ProjectFunnelSettings::validate($settings,'appleservice39','id390521129062_bot'); }
        catch (InvalidArgumentException $error) { throw new RuntimeException('settings_invalid'); }
        $collect=$settings; $collect['metrika']['mode']='collect';
        $send=$settings; $send['metrika']['mode']='send';
        $policy=ProjectFunnelConversions::policy($collect);
        $sendPolicy=ProjectFunnelConversions::policy($send);
        if (!$policy || !$sendPolicy || $policy['sha256']!==$sendPolicy['sha256']) throw new RuntimeException('mode_policy_changed');
        $model=$settings['prediction']??null;
        $modelStatus=$model===null?'missing':($model['validated_at_unix']>$now?'not_yet_valid':($model['valid_until_unix']<$now?'expired':'current'));
        $report=['ok'=>true,'scope'=>'appleservice39_conversion_preflight','schema_version'=>1,
            'settings'=>['source'=>$override?'connection_override':'repository_profile','mode'=>$metric['mode'],
                'fixed_scope_matches'=>true,'collect_send_policy_invariant'=>true,
                'policy_comparison'=>'same_settings_with_collect',
                'prediction'=>['present'=>$model!==null,'status'=>$modelStatus,'current'=>$modelStatus==='current',
                    'evidence_present'=>$model!==null && preg_match('/\A[a-f0-9]{64}\z/D',$model['evidence_sha256'])===1,
                    'stage_probability_count'=>$model===null?0:count($model['stage_probability'])]],
            'read_only_transaction'=>true,'query_timeout_engine'=>$this->engine,
            'database_writes'=>0,'provider_reads'=>0,'provider_mutations'=>0,'conversion_requests'=>0,'customer_messages'=>0];

        $stored=$this->rows("SELECT project_key,mode,counter_id,message_cursor,activated_at_unix FROM max_channel_attribution_settings WHERE project_key='appleservice39' LIMIT 2");
        if (count($stored)>1) throw new RuntimeException('settings_row_ambiguous');
        $row=$stored[0]??null;
        $report['stored_settings']=['present'=>$row!==null,'counter_matches'=>$row!==null && (string)$row['counter_id']==='42611469',
            'mode'=>$row===null?'missing':(in_array($row['mode'],['off','collect','send'],true)?$row['mode']:'other'),
            'mode_matches_effective'=>$row!==null && $row['mode']===$metric['mode'],
            'cursor_present'=>$row!==null && $row['message_cursor']!==null,
            'cursor_nonzero'=>$row!==null && (int)$row['message_cursor']>0,
            'activation_present'=>$row!==null && $row['activated_at_unix']!==null];
        $report['dedup_index']=$this->dedup();
        $report['outbox']=$this->outbox($settings,$policy,$now);
        $report['visits']=$this->visits($now);
        $report['conversations']=$this->conversations($settings,$policy,$now);
        $report['identity_checks']=['performed'=>$this->identityReads,'limit'=>100];
        $this->remaining();
        return $report;
    }

    private function outbox(array $settings, array $policy, int $now): array
    {
        $rows=$this->rows("SELECT id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,state,next_attempt_unix,funnel_policy_sha256,client_id,attempts,claimed_at_unix,
            CASE WHEN LENGTH(funnel_context_json)<=16384 THEN funnel_context_json ELSE NULL END AS funnel_context_json,
            CASE WHEN LENGTH(funnel_context_json)>16384 THEN 1 ELSE 0 END AS context_oversize,
            CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN 1 ELSE 0 END AS receipt_present,
            CASE WHEN claim_token IS NOT NULL AND claim_token<>'' THEN 1 ELSE 0 END AS claim_present,upload_status
            FROM max_channel_conversion_outbox WHERE project_key='appleservice39' ORDER BY id DESC LIMIT 1001");
        $out=self::page($rows,1000)+['states'=>array_fill_keys(self::STATES,0),
            'event_age'=>array_fill_keys(['within_21_days','older_than_21_days','future','invalid'],0),
            'foreign_scope_rows'=>0,'policy_matching_rows'=>0,'policy_mismatch_rows'=>0,'target_matching_rows'=>0,
            'receipt_present_rows'=>0,'claim_present_rows'=>0,'attempted_rows'=>0,'never_attempted_rows'=>0,
            'stale_uploading_rows'=>0,'unknown_read_reconciliation_only'=>0,'accepted_poll_only'=>0,
            'accepted_without_receipt_rows'=>0,'context_oversize_rows'=>0,
            'receipt_status'=>array_fill_keys(['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE','missing','other'],0),
            'due_pending_ready_rows'=>0,'identity_checked_rows'=>0,'identity_qualified_rows'=>0,
            'ready_client_matches_rows'=>0,'ready_client_mismatch_rows'=>0,'identity_checks_truncated'=>false];
        foreach ($rows as $row) {
            $state=in_array($row['state'],self::STATES,true)?$row['state']:'other'; $out['states'][$state]++;
            $age=self::age($row['event_unix'],$now); $out['event_age'][$age]++;
            $scope=$row['project_key']==='appleservice39' && $row['platform']==='max' && (string)$row['counter_id']==='42611469';
            $target=$row['target']==='funnel_predictive_value' && $row['transport_target']==='predict';
            if (!$scope) $out['foreign_scope_rows']++;
            if ($target) $out['target_matching_rows']++;
            $policyMatches=$row['funnel_policy_sha256']===$policy['sha256'];
            $out[$policyMatches?'policy_matching_rows':'policy_mismatch_rows']++;
            $out['receipt_present_rows']+=(int)$row['receipt_present']; $out['claim_present_rows']+=(int)$row['claim_present'];
            $out[(int)$row['attempts']>0?'attempted_rows':'never_attempted_rows']++;
            $out['context_oversize_rows']+=(int)$row['context_oversize'];
            if ($state==='uploading' && $row['claimed_at_unix']!==null && (int)$row['claimed_at_unix']<$now-300) $out['stale_uploading_rows']++;
            if ($state==='unknown') $out['unknown_read_reconciliation_only']++;
            if ($state==='accepted') { $out['accepted_poll_only']++; if (!(int)$row['receipt_present']) $out['accepted_without_receipt_rows']++; }
            $status=$row['upload_status']===null || $row['upload_status']===''?'missing':$row['upload_status'];
            $out['receipt_status'][array_key_exists($status,$out['receipt_status'])?$status:'other']++;
            if (!$scope || !$target || !$policyMatches || !in_array($state,['pending','ready'],true)
                || $age!=='within_21_days' || (int)$row['next_attempt_unix']>$now) continue;
            $out['due_pending_ready_rows']++;
            if ($this->identityReads>=100) { $out['identity_checks_truncated']=true; continue; }
            $observation=$this->identity($row,$settings,$now); $out['identity_checked_rows']++;
            if ($observation!==null) {
                $out['identity_qualified_rows']++;
                if ($state==='ready') $out[$observation['client_id']===$row['client_id']?'ready_client_matches_rows':'ready_client_mismatch_rows']++;
            }
        }
        return $out;
    }

    private function visits(int $now): array
    {
        $rows=$this->rows("SELECT project_key,source_key,platform,counter_id,identity_status,visit_at_unix,
            CASE WHEN client_id IS NOT NULL AND client_id<>'' THEN 1 ELSE 0 END AS client_present
            FROM advertising_visit_observations WHERE project_key='appleservice39' ORDER BY id DESC LIMIT 1001");
        $out=self::page($rows,1000)+['exact_scope_rows'=>0,'counter_mismatch_rows'=>0,'other_scope_rows'=>0,
            'verified_rows'=>0,'client_present_rows'=>0,'verified_client_fresh_exact_rows'=>0,
            'visit_age'=>array_fill_keys(['within_21_days','older_than_21_days','future','invalid'],0)];
        foreach ($rows as $row) {
            $scope=$row['project_key']==='appleservice39' && $row['source_key']==='max-miniapp'
                && $row['platform']==='max' && (string)$row['counter_id']==='42611469';
            $age=self::age($row['visit_at_unix'],$now); $out['visit_age'][$age]++;
            $out[$scope?'exact_scope_rows':'other_scope_rows']++;
            if ((string)$row['counter_id']!=='42611469') $out['counter_mismatch_rows']++;
            $verified=$row['identity_status']==='verified'; if ($verified) $out['verified_rows']++;
            $out['client_present_rows']+=(int)$row['client_present'];
            if ($scope && $verified && (int)$row['client_present']===1 && $age==='within_21_days') $out['verified_client_fresh_exact_rows']++;
        }
        return $out;
    }

    private function conversations(array $settings, array $policy, int $now): array
    {
        $rows=$this->rows("SELECT c.id,c.project_key,cc.external_user_id,
            CASE WHEN LENGTH(c.metadata_json)<=16384 THEN c.metadata_json ELSE NULL END AS metadata_json,
            CASE WHEN cc.id IS NOT NULL THEN 1 ELSE 0 END AS channel_scope_matches,
            CASE WHEN EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='project_funnel_entry') THEN 1 ELSE 0 END AS has_entry
            FROM conversations c LEFT JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel AND cc.external_chat_id=c.external_chat_id
            WHERE c.project_key='appleservice39' AND c.channel='max' AND c.is_test=0 ORDER BY c.id DESC LIMIT 101");
        $out=self::page($rows,100)+['non_test_max_only'=>true,'with_entry'=>0,'without_entry'=>0,
            'exact_bot_rows'=>0,'other_or_missing_bot_rows'=>0,'channel_scope_missing_rows'=>0,
            'detail_limit'=>25,'detail_examined'=>0,'detail_truncated'=>false,'history_incomplete_cases'=>0,
            'eligible_anchor_cases'=>0,'anchor_missing_cases'=>0,'metadata_oversize_cases'=>0,
            'observed_stage_cases'=>array_fill_keys(self::STAGES,0),'calibrated_estimate_cases'=>0,
            'estimate_possible'=>false,'identity_checked_cases'=>0,'identity_qualified_cases'=>0,
            'identity_qualified_estimate_cases'=>0,'identity_checks_truncated'=>false];
        foreach ($rows as $row) {
            $out[(int)$row['has_entry']===1?'with_entry':'without_entry']++;
            $metadata=json_decode((string)$row['metadata_json'],true);
            $bot=is_array($metadata) && ($metadata['max_bot_key']??null)==='id390521129062_bot';
            $out[$bot?'exact_bot_rows':'other_or_missing_bot_rows']++;
            if (!(int)$row['channel_scope_matches']) $out['channel_scope_missing_rows']++;
            if (!$bot || !(int)$row['channel_scope_matches'] || !(int)$row['has_entry'] || $row['project_key']!=='appleservice39') continue;
            if ($out['detail_examined']>=25) { $out['detail_truncated']=true; continue; }
            $out['detail_examined']++;
            $entries=$this->rows("SELECT CASE WHEN LENGTH(payload_json)<=16384 THEN payload_json ELSE NULL END AS payload_json,CASE WHEN LENGTH(payload_json)>16384 THEN 1 ELSE 0 END AS oversize FROM conversation_events WHERE conversation_id=? AND event_type='project_funnel_entry' ORDER BY id LIMIT 101",[$row['id']]);
            if (count($entries)>=101) { $out['history_incomplete_cases']++; continue; }
            if (array_sum(array_column($entries,'oversize'))>0) { $out['metadata_oversize_cases']++; $out['history_incomplete_cases']++; continue; }
            $anchor=null; $since=null;
            foreach ($entries as $entry) {
                $data=json_decode((string)$entry['payload_json'],true); $at=ConversationRecordClock::read($entry['payload_json']);
                if (is_array($data) && ($data['attribution_state']??null)==='matched'
                    && ($data['advertising_scope']??null)===ProjectFunnelEntry::scope($settings)
                    && ($data['external_user_id']??null)===(string)$row['external_user_id']
                    && ($data['bot_key']??null)==='id390521129062_bot' && $at!==null && $at>=$now-21*86400 && $at<=$now) {
                    $anchor=$data; $since=$at; break;
                }
            }
            if ($anchor===null) { $out['anchor_missing_cases']++; continue; }
            $out['eligible_anchor_cases']++;
            $messages=$this->rows('SELECT direction,sender_type,CASE WHEN LENGTH(metadata_json)<=16384 THEN metadata_json ELSE NULL END AS metadata_json,CASE WHEN LENGTH(metadata_json)>16384 THEN 1 ELSE 0 END AS oversize FROM messages WHERE conversation_id=? ORDER BY id LIMIT 501',[$row['id']]);
            $events=$this->rows("SELECT event_type,CASE WHEN LENGTH(payload_json)<=16384 THEN payload_json ELSE NULL END AS payload_json,CASE WHEN LENGTH(payload_json)>16384 THEN 1 ELSE 0 END AS oversize FROM conversation_events WHERE conversation_id=? AND event_type IN ('waiting_manager','project_funnel_stage') ORDER BY id LIMIT 501",[$row['id']]);
            if (count($messages)>=501 || count($events)>=501) { $out['history_incomplete_cases']++; continue; }
            if (array_sum(array_column($messages,'oversize'))+array_sum(array_column($events,'oversize'))>0) {
                $out['metadata_oversize_cases']++; $out['history_incomplete_cases']++; continue;
            }
            $stages=[]; $managerAt=null;
            foreach ($messages as $message) {
                $at=ConversationRecordClock::read($message['metadata_json']); if ($at===null || $at<$since || $at>$now) continue;
                $meta=json_decode((string)$message['metadata_json'],true);
                if ($message['direction']==='outbound' && $message['sender_type']==='manager') $managerAt=$managerAt??$at;
                if ($message['direction']==='inbound' && $message['sender_type']==='customer' && in_array($meta['type']??null,['message','callback','contact'],true)) {
                    $stages['customer_activity']=$stages['customer_activity']??$at;
                    if ($managerAt!==null && $at>=$managerAt && in_array($meta['type'],['message','contact'],true)) $stages['customer_reply']=$stages['customer_reply']??$at;
                }
            }
            foreach ($events as $event) {
                $at=ConversationRecordClock::read($event['payload_json']); if ($at===null || $at<$since || $at>$now) continue;
                $data=json_decode((string)$event['payload_json'],true);
                if ($event['event_type']==='waiting_manager') $stages['manager_request']=$stages['manager_request']??$at;
                elseif (($data['project_key']??null)==='appleservice39' && ($data['bot_key']??null)==='id390521129062_bot'
                    && in_array($data['stage']??null,['working','offer_sent','booking','won'],true)) $stages[$data['stage']]=$stages[$data['stage']]??$at;
            }
            foreach ($stages as $stage=>$at) $out['observed_stage_cases'][$stage]++;
            $estimate=ProjectFunnelConversions::estimate($policy,$stages,$now);
            if ($estimate!==null) { $out['calibrated_estimate_cases']++; $out['estimate_possible']=true; }
            if (!$stages) continue;
            if ($this->identityReads>=100) { $out['identity_checks_truncated']=true; continue; }
            // This is an in-memory candidate only. It is never inserted or uploaded.
            $candidate=['funnel_context_json'=>json_encode($anchor,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                'external_user_id'=>(string)$row['external_user_id'],'event_unix'=>$estimate['event_unix']??min($stages)];
            $observation=$this->identity($candidate,$settings,$now); $out['identity_checked_cases']++;
            if ($observation!==null) { $out['identity_qualified_cases']++; if ($estimate!==null) $out['identity_qualified_estimate_cases']++; }
        }
        return $out;
    }
}

function as39ConversionPreflightMain(array $argv): int
{
    $root='/var/www/anytoour/data/www/app.anytoour.ru'; $expected=$argv[1]??'';
    $deadline=microtime(true)+25; $pdo=null;
    try {
        if (PHP_SAPI!=='cli' || count($argv)!==2 || preg_match('/\A[a-f0-9]{40}\z/D',$expected)!==1) throw new RuntimeException('invalid_operation');
        $readGit=static function(array $args) use ($root,$deadline): string {
            $process=proc_open(['git','-C',$root,...$args],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('checkout_unavailable');
            fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false); $out=''; $exit=null;
            try {
                do {
                    if (microtime(true)>=$deadline) throw new RuntimeException('read_deadline');
                    $out.=stream_get_contents($pipes[1],16385); stream_get_contents($pipes[2],16385);
                    if (strlen($out)>16384) throw new RuntimeException('checkout_unavailable');
                    $status=proc_get_status($process);
                    if (!$status['running']) { $exit=$status['exitcode']; break; }
                    usleep(10000);
                } while (true);
                $out.=stream_get_contents($pipes[1],16385);
                if ($exit!==0 || strlen($out)>16384) throw new RuntimeException('checkout_unavailable');
                return trim($out);
            } finally {
                if (($status['running']??true)===true) proc_terminate($process,9);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
            }
        };
        if ($readGit(['rev-parse','HEAD'])!==$expected || $readGit(['status','--porcelain','--untracked-files=no'])!=='') throw new RuntimeException('checkout_changed');
        require_once $root.'/config.php';
        require_once $root.'/services/ConversationDb.php';
        $pdo=ConversationDb::connection(); $audit=new As39ConversionPreflight($pdo,$deadline);
        require_once $root.'/services/ProjectFunnelConversions.php';
        $connection=ProjectMaxConnection::resolve('appleservice39');
        $settings=$connection?ProjectFunnelSettings::load($connection):null;
        if (!$settings) throw new RuntimeException('project_unavailable');
        $result=$audit->read($settings,$connection->settings()->get('sales_funnel')!==null,time());
        $pdo->rollBack();
        if ($readGit(['rev-parse','HEAD'])!==$expected || $readGit(['status','--porcelain','--untracked-files=no'])!=='') throw new RuntimeException('checkout_changed_after_read');
        $result['application_sha']=$expected; $result['observed_at']=gmdate('c');
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        return 0;
    } catch (Throwable $error) {
        try { if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignored) {}
        $safe=['invalid_operation','checkout_unavailable','checkout_changed','checkout_changed_after_read','storage_busy',
            'storage_unsupported','query_timeout_unavailable','read_deadline','read_limit','identity_limit',
            'readonly_transaction_missing','project_unavailable','policy_changed','settings_invalid','mode_policy_changed','settings_row_ambiguous'];
        echo json_encode(['ok'=>false,'scope'=>'appleservice39_conversion_preflight','schema_version'=>1,
            'reason'=>in_array($error->getMessage(),$safe,true)?$error->getMessage():'read_unavailable',
            'database_writes'=>0,'provider_reads'=>0,'provider_mutations'=>0,'conversion_requests'=>0,'customer_messages'=>0],JSON_UNESCAPED_SLASHES).PHP_EOL;
        return 1;
    }
}

if (!defined('AS39_CONVERSION_PREFLIGHT_LIBRARY_ONLY')) {
    ini_set('display_errors','0'); ini_set('log_errors','0');
    exit(as39ConversionPreflightMain($argv));
}
