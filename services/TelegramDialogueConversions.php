<?php
declare(strict_types=1);
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Telegram identity bridge into the existing conversion outbox and worker. */
final class TelegramDialogueConversions
{
    public const SIGNALS=['max_bot_activity_start'=>'customer_activity','max_manager_request'=>'manager_request','max_customer_reply_after_manager'=>'customer_reply'];
    private PDO $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}

    public static function settings(?PDO $pdo=null): ?array
    {
        try{
            if(ProjectConfig::projectId()!=='anytour'||(!$pdo&&!ConversationDb::isConfigured()))return null;
            $q=($pdo??ConversationDb::connection())->query("SELECT telegram_mode,telegram_activated_at_unix,telegram_signal_floor,telegram_signal_cursor FROM max_channel_attribution_settings WHERE project_key='anytour' AND counter_id='98615635' AND goal_set='shared'");
            $s=$q->fetch(PDO::FETCH_ASSOC);
            return $s&&in_array($s['telegram_mode'],['off','collect','send'],true)?$s:null;
        }catch(Throwable $ignored){return null;}
    }

    /** Explicit first activation is prospective; resume cannot reset its boundary. */
    public function activate(string $mode,int $now): array
    {
        if(!in_array($mode,['off','collect','send'],true)||$now<946684800||$this->pdo->inTransaction())throw new RuntimeException('invalid_telegram_transition');
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $s=$this->pdo->query("SELECT telegram_mode,telegram_activated_at_unix FROM max_channel_attribution_settings WHERE project_key='anytour' AND counter_id='98615635' AND goal_set='shared'".$lock)->fetch(PDO::FETCH_ASSOC);
            if(!$s)throw new RuntimeException('telegram_migration_required');
            if($mode!=='off'&&empty($s['telegram_activated_at_unix'])){
                $floor=(string)$this->pdo->query("SELECT COALESCE(MAX(id),0) FROM advertising_signals WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635'")->fetchColumn();
                $q=$this->pdo->prepare("UPDATE max_channel_attribution_settings SET telegram_mode=?,telegram_activated_at_unix=?,telegram_signal_floor=?,telegram_signal_cursor=? WHERE project_key='anytour'");$q->execute([$mode,$now,$floor,$floor]);
            }else{$q=$this->pdo->prepare("UPDATE max_channel_attribution_settings SET telegram_mode=? WHERE project_key='anytour'");$q->execute([$mode]);}
            $this->pdo->commit();return self::settings($this->pdo)??[];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** null preserves unrelated transports; managed goals never fall into legacy CSV. */
    public static function queueIfEligible($chat,string $target,?array $context): ?bool
    {
        if(!isset(self::SIGNALS[$target])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$chat))return null;
        $s=self::settings();if(!$s||empty($s['telegram_activated_at_unix']))return null;
        // Pausing this transport must not replay its managed stages through CSV.
        if($s['telegram_mode']==='off')return false;
        try{
            $p=ConversationDb::connection();$repo=new self($p);
            $sql="SELECT id FROM conversations WHERE project_key='anytour' AND channel='telegram' AND external_chat_id=? AND status<>'closed'";
            $args=[(string)$chat];
            if($context){$sql.=' AND id=?';$args[]=(string)($context['conversation_id']??'');}
            $q=$p->prepare($sql.' ORDER BY id DESC LIMIT 1');$q->execute($args);$conversation=$q->fetchColumn();
            if($conversation===false)return false;
            $q=$p->prepare("SELECT * FROM advertising_signals WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635' AND conversation_id=? AND signal_key=? AND id>? ORDER BY id LIMIT 1");
            $q->execute([(string)$conversation,self::SIGNALS[$target],$s['telegram_signal_floor']]);$signal=$q->fetch(PDO::FETCH_ASSOC);
            return $signal?$repo->capture($signal,$s,time()):false;
        }catch(Throwable $ignored){return false;}
    }

    /** Only immutable, previously admitted analytic signals; no archive/text capture. */
    public function collect(array $s,int $now): int
    {
        if($s['telegram_mode']==='off')return 0;
        $q=$this->pdo->prepare("SELECT * FROM advertising_signals WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635' AND id>? ORDER BY id LIMIT 50");
        $q->execute([$s['telegram_signal_cursor']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$count=0;
        foreach($rows as$r){
            if((int)$r['captured_at_unix']>$now)break;
            $this->capture($r,$s,$now);
            $u=$this->pdo->prepare("UPDATE max_channel_attribution_settings SET telegram_signal_cursor=? WHERE project_key='anytour' AND telegram_signal_cursor<?");$u->execute([$r['id'],$r['id']]);$count++;
        }
        return $count;
    }

    private function capture(array $r,array $s,int $now): bool
    {
        $target=array_search($r['signal_key'],self::SIGNALS,true);
        if($target===false||$r['project_key']!=='anytour'||$r['source_key']!=='tgmini'||$r['platform']!=='telegram'||$r['counter_id']!=='98615635'
            ||(int)$r['id']<=(int)$s['telegram_signal_floor']||(int)$r['occurred_at_unix']<(int)$s['telegram_activated_at_unix']
            ||(int)$r['occurred_at_unix']>$now||(int)$r['captured_at_unix']>$now||!$this->eligible($r))return false;
        $clock=$this->pdo->query('SELECT '.($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())':'0'))->fetchColumn();
        if((int)$clock!==0)throw new RuntimeException('conversation_clock_not_utc');
        $q=$this->pdo->prepare("SELECT platform FROM max_channel_conversion_outbox WHERE project_key='anytour' AND conversation_id=? AND target=?");$q->execute([$r['conversation_id'],$target]);$old=$q->fetchColumn();
        if($old!==false)return $old==='telegram';
        $q=$this->pdo->prepare('SELECT 1 FROM conversation_events WHERE conversation_id=? AND event_type=? LIMIT 1');$q->execute([$r['conversation_id'],'metrika_'.$target]);
        if($q->fetchColumn()!==false)return false; // Preserve previous legacy delivery/markers.
        if(class_exists('MaxSearchApi')&&method_exists('MaxSearchApi','metrikaGoalAllowed')&&!MaxSearchApi::metrikaGoalAllowed($r['external_user_id']))return false;
        $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
        $q=$this->pdo->prepare($insert." INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,source_signal_id) VALUES ('anytour',?,?,'98615635',?,?,?,'telegram','tgmini',?)");
        $q->execute([$r['conversation_id'],$r['external_user_id'],$target,$target,$r['occurred_at_unix'],$r['id']]);return true;
    }

    private function eligible(array $r): bool
    {
        if(!preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$r['external_user_id']))return false;
        $q=$this->pdo->prepare("SELECT c.external_chat_id,cc.external_user_id,cc.external_chat_id AS user_chat FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=? AND c.project_key='anytour' AND c.channel='telegram' AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c'));
        $q->execute([$r['conversation_id']]);$c=$q->fetch(PDO::FETCH_ASSOC);
        return $c&&(string)$c['external_chat_id']===(string)$r['external_user_id']&&(string)$c['external_user_id']===(string)$r['external_user_id']&&(string)$c['user_chat']===(string)$r['external_user_id'];
    }

    /** Unique verified ClientID only, frozen on first resolution; MAX policy is separate. */
    public function resolve(array $row,int $now): bool
    {
        if(($row['platform']??'')!=='telegram'||($row['identity_source_key']??'')!=='tgmini'||!$this->eligible($row))return false;
        $q=$this->pdo->prepare("SELECT client_id,MIN(id) AS observation_id FROM advertising_visit_observations WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635' AND external_user_id=? AND identity_status='verified' AND client_id<>'' AND visit_at_unix<=? AND observed_at_unix<=? AND received_at_unix<=? GROUP BY client_id LIMIT 2");
        $q->execute([$row['external_user_id'],$row['event_unix'],$now,$now]);$clients=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($clients)!==1||!MaxTrackerLookupProtocol::id((string)$clients[0]['client_id'],40)){
            $u=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error=? WHERE id=? AND platform='telegram' AND state='pending'");$u->execute([$now+900,count($clients)>1?'identity_ambiguous':'identity_not_found',$row['id']]);return false;
        }
        $u=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET client_id=?,identity_observation_id=?,state='ready',next_attempt_unix=0,last_error='' WHERE id=? AND platform='telegram' AND state='pending'");$u->execute([(string)$clients[0]['client_id'],$clients[0]['observation_id'],$row['id']]);return $u->rowCount()===1;
    }

    public function canSend(array $row): bool
    {
        if(($row['platform']??'')!=='telegram'||($row['identity_source_key']??'')!=='tgmini'||!$this->eligible($row))return false;
        if(class_exists('MaxSearchApi')&&method_exists('MaxSearchApi','metrikaGoalAllowed')&&!MaxSearchApi::metrikaGoalAllowed($row['external_user_id']))return false;
        return true;
    }

    public function counts(): array
    {
        $q=$this->pdo->query("SELECT state,COUNT(*) AS n FROM max_channel_conversion_outbox WHERE project_key='anytour' AND platform='telegram' GROUP BY state");$out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)$out[$r['state']]=(int)$r['n'];return $out;
    }
}
