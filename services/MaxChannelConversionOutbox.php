<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/MaxSubscriptionAttributionPolicy.php';

/** One durable first-stage event per conversation. No HTTP in customer requests. */
final class MaxChannelConversionOutbox
{
    public const TARGETS=['max_bot_activity_start','max_manager_request','max_customer_reply_after_manager'];
    // Retain the original stage key for receipt/comment reconciliation only.
    public const HISTORICAL_TARGETS=['max_bot_activity_start','max_manager_reply','max_customer_reply_after_manager','max_manager_request'];
    private PDO $pdo;
    private ?bool $platformReady=null;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}

    /** Compatibility while source is present before forward migration067. */
    public function hasPlatform(): bool
    {
        if($this->platformReady===null){
            try{$this->pdo->query('SELECT platform FROM max_channel_conversion_outbox WHERE 1=0');$this->platformReady=true;}
            catch(PDOException $e){
                if($e->getCode()!=='42S22'&&!($e->getCode()==='HY000'&&str_contains($e->getMessage(),'no such column: platform')))throw $e;
                $this->platformReady=false;
            }
        }
        return $this->platformReady;
    }
    private function platformWhere(string $platform): string
    {
        if(!in_array($platform,['max','telegram'],true))throw new InvalidArgumentException('invalid_conversion_platform');
        return $this->hasPlatform()?" AND platform='".$platform."'":($platform==='max'?'':' AND 1=0');
    }

    public static function settings(?PDO $pdo=null): ?array
    {
        if(!$pdo&&!ConversationDb::isConfigured())return null;
        try{
            $connection=$pdo??ConversationDb::connection();
            try{
                $q=$connection->prepare('SELECT project_key,mode,counter_id,activated_at_unix,message_cursor,goal_set,shared_goals_activated_at_unix,shared_goals_event_id,request_event_cursor,membership_receipts_activated_at_unix,membership_receipts_outbox_cursor FROM max_channel_attribution_settings WHERE project_key=?');
                $q->execute([ProjectConfig::projectId()]);
            }catch(Throwable $unmigrated){
                // Source can arrive before migration 038. Preserve the managed
                // scope with collection/POST held, rather than falling into the
                // legacy YCLID queue for the same channel event. No DDL here.
                $q=$connection->prepare("SELECT project_key,mode,counter_id,activated_at_unix,message_cursor,'channel' AS goal_set,NULL AS shared_goals_activated_at_unix,0 AS shared_goals_event_id,0 AS request_event_cursor,NULL AS membership_receipts_activated_at_unix,0 AS membership_receipts_outbox_cursor FROM max_channel_attribution_settings WHERE project_key=?");
                $q->execute([ProjectConfig::projectId()]);
            }
            $s=$q->fetch(PDO::FETCH_ASSOC);
            return is_array($s)&&$s['project_key']==='anytour'&&$s['counter_id']==='98615635'&&in_array($s['mode'],['off','collect','send'],true)&&in_array($s['goal_set'],['channel','shared'],true)?$s:null;
        }catch(Throwable $ignored){return null;}
    }

    /** null delegates to the existing YCLID path; false is a managed capture failure. */
    public static function queueIfEligible($chatId,string $target,?array $context): ?bool
    {
        if((!$context&&$target!=='max_manager_request')||!in_array($target,self::HISTORICAL_TARGETS,true)||!preg_match('/\A-[1-9][0-9]{0,19}\z/D',(string)$chatId))return null;
        $settings=self::settings();
        if(!$settings||$settings['mode']==='off')return null;
        try{
            $self=new self(ConversationDb::connection());
            if(!$context){
                $q=$self->pdo->prepare("SELECT id FROM conversations WHERE project_key=? AND channel='max' AND external_chat_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1");
                $q->execute([$settings['project_key'],(string)$chatId]);$context=['conversation_id'=>(string)$q->fetchColumn()];
            }
            $conversation=$self->conversation((string)($context['conversation_id']??''),(string)$chatId,$settings);
            if(!$conversation)return null;
            // No legacy YCLID fallback or new markers during the goal cutover.
            if(!self::sharedGoalsActive($settings))return false;
            if($target==='max_manager_reply')return true;
            // The legacy request call precedes dispatch. Its durable waiting
            // event, rather than an attempted greeting, owns channel capture.
            if($target==='max_manager_request'&&!$self->event($conversation,$target,$settings))return false;
            return $self->capture($conversation,$target,$settings,time());
        }catch(Throwable $ignored){return false;}
    }

    public static function sharedGoalsActive(array $settings): bool
    {
        return ($settings['goal_set']??'')==='shared'&&(int)($settings['shared_goals_activated_at_unix']??0)>0;
    }

    /** Explicit operator transition, after the three existing goals are verified. */
    public function activateSharedGoals(int $now): array
    {
        if($now<=0||$this->pdo->inTransaction())throw new RuntimeException('invalid_goal_transition');
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$this->pdo->prepare("SELECT * FROM max_channel_attribution_settings WHERE project_key='anytour' AND counter_id='98615635'".$lock);
            $q->execute();$s=$q->fetch(PDO::FETCH_ASSOC);
            if(!$s||empty($s['activated_at_unix']))throw new RuntimeException('collect_mode_required_first');
            if(self::sharedGoalsActive($s)){
                $this->pdo->commit();
                return ['shared_goals_activated_at_unix'=>(int)$s['shared_goals_activated_at_unix'],'switched'=>0,'retired'=>0];
            }
            if($s['mode']!=='collect')throw new RuntimeException('collect_mode_required_first');
            if(($s['goal_set']??'')!=='channel')throw new RuntimeException('invalid_goal_transition');
            $this->pdo->exec("UPDATE max_channel_conversion_outbox SET transport_target=CASE target WHEN 'max_bot_activity_start' THEN 'max_channel_activity_start' WHEN 'max_manager_reply' THEN 'max_channel_manager_reply' WHEN 'max_customer_reply_after_manager' THEN 'max_channel_customer_reply_after_manager' ELSE transport_target END WHERE project_key='anytour' AND counter_id='98615635' AND transport_target='' AND target IN ('max_bot_activity_start','max_manager_reply','max_customer_reply_after_manager')");
            // A claim may have reached the API without a receipt. Only rows
            // proven never attempted can change their frozen wire target.
            $unsent="project_key='anytour' AND counter_id='98615635' AND state IN ('pending','ready') AND attempts=0 AND (upload_id IS NULL OR upload_id='') AND (upload_status IS NULL OR upload_status='') AND (claim_token IS NULL OR claim_token='') AND (claimed_at_unix IS NULL OR claimed_at_unix=0)";
            $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET transport_target=target WHERE ".$unsent." AND target IN ('max_bot_activity_start','max_customer_reply_after_manager')");
            $q->execute();$switched=$q->rowCount();
            $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state='retired',last_error='goal_retired' WHERE ".$unsent." AND target='max_manager_reply'");
            $q->execute();$retired=$q->rowCount();
            $cursor=(string)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM conversation_events')->fetchColumn();
            $q=$this->pdo->prepare("UPDATE max_channel_attribution_settings SET goal_set='shared',shared_goals_activated_at_unix=?,shared_goals_event_id=?,request_event_cursor=? WHERE project_key='anytour' AND counter_id='98615635' AND goal_set='channel'");
            $q->execute([$now,$cursor,$cursor]);
            if($q->rowCount()!==1)throw new RuntimeException('invalid_goal_transition');
            $this->pdo->commit();
            return ['shared_goals_activated_at_unix'=>$now,'switched'=>$switched,'retired'=>$retired];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function conversation(string $id,string $chatId,array $settings): ?array
    {
        if(!preg_match('/\A[1-9][0-9]*\z/D',$id)||empty($settings['activated_at_unix']))return null;
        $base="SELECT c.id,c.external_chat_id,cc.external_user_id,";
        $scope=" FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel";
        $where=" WHERE c.id=? AND c.external_chat_id=? AND c.project_key=? AND c.channel='max' AND c.is_test=0";
        $args=[$id,$chatId,$settings['project_key']];
        $q=$this->pdo->prepare($base."MIN(e.created_at) AS attribution_started_at".$scope
            ." JOIN post_entries e ON e.conversation_id=c.id JOIN post_publications pp ON pp.id=e.publication_id"
            ." JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id".$where
            ." AND p.project_key=c.project_key AND pc.platform='max' AND pc.bot_key='id9704048781_1_bot'"
            ." GROUP BY c.id,c.external_chat_id,cc.external_user_id");
        $q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r){
            // The channel can also use an existing unique manager-source link,
            // without a registered post publication. Its accepted conversation
            // start anchors activity; source identity is never inferred from CID.
            $q=$this->pdo->prepare($base."c.started_at AS attribution_started_at".$scope
                ." JOIN conversation_sources s ON s.id=c.source_id AND s.channel=c.channel"
                ." JOIN projects p ON p.id=s.project_id AND p.project_key=c.project_key".$where." AND s.handling_mode='manager'");
            $q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);
        }
        if(!$r||!preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$r['external_user_id'])||$r['external_chat_id']!=='-'.(string)$r['external_user_id'])return null;
        return $r;
    }

    /** Select recorded event instants, never the time of queue/retry/enrichment. */
    public function event(array $c,string $target,array $settings): ?array
    {
        if(!in_array($target,self::HISTORICAL_TARGETS,true))return null;
        $clock=(int)$this->pdo->query('SELECT '.($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())':'0'))->fetchColumn();
        if($clock!==0)throw new RuntimeException('conversation_clock_not_utc');
        $since=max((int)$settings['activated_at_unix'],MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$c['attribution_started_at'],new DateTimeZone('UTC')));
        if($target==='max_manager_request'){
            if(!self::sharedGoalsActive($settings))return null;
            $since=max($since,(int)$settings['shared_goals_activated_at_unix']);
            $q=$this->pdo->prepare("SELECT id,created_at,payload_json AS metadata_json FROM conversation_events WHERE conversation_id=? AND event_type='waiting_manager' AND created_at>=? AND id>? ORDER BY created_at,id LIMIT 1");
            $q->execute([$c['id'],gmdate('Y-m-d H:i:s',$since),$settings['shared_goals_event_id']??0]);
            $request=$q->fetch(PDO::FETCH_ASSOC);return $request?:null;
        }
        $sinceText=gmdate('Y-m-d H:i:s',$since);
        $base='SELECT id,created_at,metadata_json FROM messages WHERE conversation_id=? AND channel=\'max\' AND created_at>=?';
        $q=$this->pdo->prepare($base." AND direction='outbound' AND sender_type='manager' ORDER BY created_at,id LIMIT 1");
        $q->execute([$c['id'],$sinceText]);$manager=$q->fetch(PDO::FETCH_ASSOC);
        if($target==='max_manager_reply')return $manager?:null;
        if($target==='max_customer_reply_after_manager'&&!$manager)return null;
        $sql=$base." AND direction='inbound' AND sender_type='customer'";
        $args=[$c['id'],$sinceText];
        if($target==='max_customer_reply_after_manager'){
            $sql.=' AND (created_at>? OR (created_at=? AND id>?))';array_push($args,$manager['created_at'],$manager['created_at'],$manager['id']);
        }
        $json="CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END";
        $type=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT($json,'$.type'))":"json_extract($json,'$.type')";
        $allowed=$target==='max_bot_activity_start'?"('message','callback','contact')":"('message','contact')";
        $q=$this->pdo->prepare($sql.' AND '.$type.' IN '.$allowed.' ORDER BY created_at,id LIMIT 1');$q->execute($args);
        $row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }

    public function capture(array $conversation,string $target,array $settings,int $now): bool
    {
        if(!self::sharedGoalsActive($settings)||!in_array($target,self::TARGETS,true))return false;
        $event=$this->event($conversation,$target,$settings);if(!$event)return false;
        $unix=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$event['created_at'],new DateTimeZone('UTC'));
        if($unix>$now||$unix<(int)$settings['activated_at_unix'])return false;
        $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
        $q=$this->pdo->prepare($insert.' INTO max_channel_conversion_outbox (project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix) VALUES (?,?,?,?,?,?,?)');
        return $q->execute([$settings['project_key'],$conversation['id'],(string)$conversation['external_user_id'],$settings['counter_id'],$target,$target,$unix]);
    }

    public function pending(int $now,int $limit=25,string $platform='max'): array
    {
        $limit=max(1,min(25,$limit));
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND state='pending' AND next_attempt_unix<=?".$this->platformWhere($platform)." ORDER BY id LIMIT ".$limit);
        $q->execute([$now]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Bounded cyclic pass over already-admitted prospective MAX people. */
    public function membershipCandidates(array $settings,int $now,int $limit=25): array
    {
        $activated=(int)($settings['membership_receipts_activated_at_unix']??0);
        if($activated<946684800||$limit<1)return ['cursor_before'=>0,'cursor_after'=>0,'bindings'=>[]];
        $cursor=(int)($settings['membership_receipts_outbox_cursor']??0);$storedCursor=$cursor;$limit=min(25,$limit);$since=gmdate('Y-m-d H:i:s',$activated);
        $select=function(int $after)use($since):array{
            $q=$this->pdo->prepare("SELECT id,conversation_id,external_user_id FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND transport_target=target AND target IN ('max_bot_activity_start','max_manager_request','max_customer_reply_after_manager') AND created_at>=? AND id>? ORDER BY id LIMIT 100");
            $q->execute([$since,$after]);return$q->fetchAll(PDO::FETCH_ASSOC);
        };
        $rows=$select($cursor);if(!$rows&&$cursor>0){$cursor=0;$rows=$select(0);}
        $out=[];$seen=[];$after=$cursor;
        foreach($rows as$row){
            $after=max($after,(int)$row['id']);$user=(string)$row['external_user_id'];
            if(!self::decimal($user)||isset($seen[$user]))continue;
            $seen[$user]=true;$out[]=['conversation_id'=>(string)$row['conversation_id'],'external_user_id'=>$user];
            if(count($out)>=$limit)break;
        }
        return ['cursor_before'=>$storedCursor,'cursor_after'=>$after,'bindings'=>$out];
    }

    public function advanceMembershipCursor(int $expected,int $next): void
    {
        if($expected<0||$next<0)throw new InvalidArgumentException('membership_cursor_invalid');
        $q=$this->pdo->prepare("UPDATE max_channel_attribution_settings SET membership_receipts_outbox_cursor=? WHERE project_key='anytour' AND counter_id='98615635' AND membership_receipts_outbox_cursor=?");
        $q->execute([$next,$expected]);
    }

    public function resolve(string $id,?array $record,int $now): void
    {
        if(!$record){$q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error='identity_not_found' WHERE id=? AND state='pending'".$this->platformWhere('max'));$q->execute([$now+900,$id]);return;}
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET client_id=?,tracker_record_id=?,tracker_observed_unix=?,state='ready',last_error='',next_attempt_unix=0 WHERE id=? AND state='pending' AND external_user_id=?".$this->platformWhere('max'));
        $q->execute([$record['client_id'],$record['record_id'],$record['observed_at_unix']??null,$id,$record['external_user_id']]);
    }

    private static function decimal($value): bool{return is_string($value)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)===1;}

    public function rows(string $state,int $now,int $limit,string $platform='max'): array
    {
        if(!in_array($state,['ready','accepted','unknown'],true))throw new InvalidArgumentException('invalid_state');
        $active=$state==='ready'?" AND target IN ('max_bot_activity_start','max_manager_request','max_customer_reply_after_manager') AND transport_target=target":'';
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND state=? AND next_attempt_unix<=?".$active.$this->platformWhere($platform)." ORDER BY id LIMIT ".max(1,min(10,$limit)));
        $q->execute([$state,$now]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function claim(string $id,int $now,string $platform='max'): ?string
    {
        $token=bin2hex(random_bytes(16));
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state='uploading',claim_token=?,claimed_at_unix=?,attempts=attempts+1 WHERE id=? AND state='ready' AND next_attempt_unix<=? AND project_key='anytour' AND counter_id='98615635' AND target IN ('max_bot_activity_start','max_manager_request','max_customer_reply_after_manager') AND transport_target=target".$this->platformWhere($platform));
        $q->execute([$token,$now,$id,$now]);return $q->rowCount()===1?$token:null;
    }

    public function claimedEvent(string $id,string $token,string $platform='max'): ?array
    {
        // Read after the atomic claim. A worker that selected this row before
        // cutover must use the persisted target selected by that transition.
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE id=? AND state='uploading' AND claim_token=? AND project_key='anytour' AND counter_id='98615635'".$this->platformWhere($platform));
        $q->execute([$id,$token]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }

    public function finish(string $id,string $token,array $result,int $now): void
    {
        $state=$result['outcome']??'unknown';
        if($state==='accepted'&&isset($result['upload_id'])){
            $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state='accepted',upload_id=?,upload_status=?,next_attempt_unix=?,last_error='',claim_token=NULL WHERE id=? AND state='uploading' AND claim_token=?");
            $q->execute([$result['upload_id'],$result['status']??'UPLOADED',$now+1800,$id,$token]);
        }else{
            $new=$state==='retry'?'ready':($state==='rejected'?'failed':'unknown');
            $reason=in_array($result['reason']??'', ['transport_not_sent','rate_limited','api_rejected','upload_outcome_unknown','identity_scope_unavailable'],true)?$result['reason']:'upload_outcome_unknown';
            $q=$this->pdo->prepare('UPDATE max_channel_conversion_outbox SET state=?,next_attempt_unix=?,last_error=?,claim_token=NULL WHERE id=? AND state=\'uploading\' AND claim_token=?');
            $q->execute([$new,$now+1800,$reason,$id,$token]);
        }
        if($q->rowCount()!==1)throw new RuntimeException('upload_receipt_storage_failed');
    }

    public function recover(int $now): void
    {
        // A process can crash after an accepted POST. Never release such a claim
        // back to ready; a GET-only reconciliation owns that unknown outcome.
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state='unknown',last_error='upload_outcome_unknown',next_attempt_unix=? WHERE state='uploading' AND claimed_at_unix<? AND project_key='anytour'");$q->execute([$now,$now-300]);
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state='expired',last_error='event_expired' WHERE state IN ('pending','ready') AND project_key='anytour' AND event_unix<?");$q->execute([$now-21*86400]);
    }

    public function status(array $row,array $status,int $now): void
    {
        $api=$status['status']??'';$valid=['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'];
        if(!in_array($api,$valid,true))return;
        $state=$api==='PROCESSED'?'processed':($api==='LINKAGE_FAILURE'?'unmatched':'accepted');
        if($api==='PROCESSED'&&((int)($status['source_quantity']??0)!==1||(int)($status['line_quantity']??0)!==1))$state='failed';
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state=?,upload_id=?,upload_status=?,next_attempt_unix=?,last_error=? WHERE id=? AND state IN ('accepted','unknown')");
        $q->execute([$state,$status['id'],$api,$now+1800,$state==='failed'?'api_row_rejected':'',$row['id']]);
    }

    public function defer(string $id,string $state,int $until): void
    {
        $q=$this->pdo->prepare('UPDATE max_channel_conversion_outbox SET next_attempt_unix=? WHERE id=? AND state=?');$q->execute([$until,$id,$state]);
    }

    public function collect(array $settings,callable $reconcile): int
    {
        if(!self::sharedGoalsActive($settings))return 0;
        $q=$this->pdo->prepare("SELECT m.id,m.conversation_id FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.id>? AND c.project_key=? AND c.channel='max' AND c.is_test=0 ORDER BY m.id LIMIT 50");
        $q->execute([$settings['message_cursor'],$settings['project_key']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$count=0;
        foreach($rows as $r){
            $reconcile((int)$r['conversation_id']);
            $u=$this->pdo->prepare('UPDATE max_channel_attribution_settings SET message_cursor=? WHERE project_key=? AND message_cursor<?');
            $u->execute([$r['id'],$settings['project_key'],$r['id']]);$count++;
        }
        // Requests are durable conversation events and can occur after the
        // last message cursor has advanced (or without a new customer message).
        $q=$this->pdo->prepare("SELECT e.id,e.conversation_id FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE e.id>? AND e.event_type='waiting_manager' AND c.project_key=? AND c.channel='max' AND c.is_test=0 ORDER BY e.id LIMIT 50");
        $q->execute([$settings['request_event_cursor'],$settings['project_key']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $reconcile((int)$r['conversation_id']);
            $u=$this->pdo->prepare('UPDATE max_channel_attribution_settings SET request_event_cursor=? WHERE project_key=? AND request_event_cursor<?');
            $u->execute([$r['id'],$settings['project_key'],$r['id']]);$count++;
        }
        return $count;
    }
}

