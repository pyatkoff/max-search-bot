<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/MaxSubscriptionAttributionPolicy.php';

/** One durable first-stage event per conversation. No HTTP in customer requests. */
final class MaxChannelConversionOutbox
{
    public const TARGETS=['max_bot_activity_start','max_manager_reply','max_customer_reply_after_manager'];
    private PDO $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}

    public static function settings(?PDO $pdo=null): ?array
    {
        if(!$pdo&&!ConversationDb::isConfigured())return null;
        try{
            $q=($pdo??ConversationDb::connection())->prepare('SELECT project_key,mode,counter_id,activated_at_unix,message_cursor FROM max_channel_attribution_settings WHERE project_key=?');
            $q->execute([ProjectConfig::projectId()]);$s=$q->fetch(PDO::FETCH_ASSOC);
            return is_array($s)&&$s['project_key']==='anytour'&&$s['counter_id']==='98615635'&&in_array($s['mode'],['off','collect','send'],true)?$s:null;
        }catch(Throwable $ignored){return null;}
    }

    /** null delegates to the existing YCLID path; false is a managed capture failure. */
    public static function queueIfEligible($chatId,string $target,?array $context): ?bool
    {
        if(!$context||!in_array($target,self::TARGETS,true)||!preg_match('/\A-[1-9][0-9]{0,19}\z/D',(string)$chatId))return null;
        $settings=self::settings();
        if(!$settings||$settings['mode']==='off')return null;
        try{
            $self=new self(ConversationDb::connection());
            $conversation=$self->conversation((string)($context['conversation_id']??''),(string)$chatId,$settings);
            if(!$conversation)return null;
            return $self->capture($conversation,$target,$settings,time());
        }catch(Throwable $ignored){return false;}
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
        $clock=(int)$this->pdo->query('SELECT '.($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())':'0'))->fetchColumn();
        if($clock!==0)throw new RuntimeException('conversation_clock_not_utc');
        $since=max((int)$settings['activated_at_unix'],MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$c['attribution_started_at'],new DateTimeZone('UTC')));
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
        if(!in_array($target,self::TARGETS,true))return false;
        $event=$this->event($conversation,$target,$settings);if(!$event)return false;
        $unix=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$event['created_at'],new DateTimeZone('UTC'));
        if($unix>$now||$unix<(int)$settings['activated_at_unix'])return false;
        $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
        $q=$this->pdo->prepare($insert.' INTO max_channel_conversion_outbox (project_key,conversation_id,external_user_id,counter_id,target,event_unix) VALUES (?,?,?,?,?,?)');
        return $q->execute([$settings['project_key'],$conversation['id'],(string)$conversation['external_user_id'],$settings['counter_id'],$target,$unix]);
    }

    public function pending(int $now,int $limit=25): array
    {
        $limit=max(1,min(25,$limit));
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND state='pending' AND next_attempt_unix<=? ORDER BY id LIMIT ".$limit);
        $q->execute([$now]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolve(string $id,?array $record,int $now): void
    {
        if(!$record){$q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error='identity_not_found' WHERE id=? AND state='pending'");$q->execute([$now+900,$id]);return;}
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET client_id=?,tracker_record_id=?,tracker_observed_unix=?,state='ready',last_error='',next_attempt_unix=0 WHERE id=? AND state='pending' AND external_user_id=?");
        $q->execute([$record['client_id'],$record['record_id'],$record['observed_at_unix']??null,$id,$record['external_user_id']]);
    }

    public function rows(string $state,int $now,int $limit): array
    {
        if(!in_array($state,['ready','accepted','unknown'],true))throw new InvalidArgumentException('invalid_state');
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND state=? AND next_attempt_unix<=? ORDER BY id LIMIT ".max(1,min(10,$limit)));
        $q->execute([$state,$now]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function claim(string $id,int $now): ?string
    {
        $token=bin2hex(random_bytes(16));
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state='uploading',claim_token=?,claimed_at_unix=?,attempts=attempts+1 WHERE id=? AND state='ready' AND next_attempt_unix<=?");
        $q->execute([$token,$now,$id,$now]);return $q->rowCount()===1?$token:null;
    }

    public function finish(string $id,string $token,array $result,int $now): void
    {
        $state=$result['outcome']??'unknown';
        if($state==='accepted'&&isset($result['upload_id'])){
            $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET state='accepted',upload_id=?,upload_status=?,next_attempt_unix=?,last_error='',claim_token=NULL WHERE id=? AND state='uploading' AND claim_token=?");
            $q->execute([$result['upload_id'],$result['status']??'UPLOADED',$now+1800,$id,$token]);
        }else{
            $new=$state==='retry'?'ready':($state==='rejected'?'failed':'unknown');
            $reason=in_array($result['reason']??'', ['transport_not_sent','rate_limited','api_rejected','upload_outcome_unknown'],true)?$result['reason']:'upload_outcome_unknown';
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
        $q=$this->pdo->prepare("SELECT m.id,m.conversation_id FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.id>? AND c.project_key=? AND c.channel='max' AND c.is_test=0 ORDER BY m.id LIMIT 50");
        $q->execute([$settings['message_cursor'],$settings['project_key']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$count=0;
        foreach($rows as $r){
            $reconcile((int)$r['conversation_id']);
            $u=$this->pdo->prepare('UPDATE max_channel_attribution_settings SET message_cursor=? WHERE project_key=? AND message_cursor<?');
            $u->execute([$r['id'],$settings['project_key'],$r['id']]);$count++;
        }
        return $count;
    }
}
