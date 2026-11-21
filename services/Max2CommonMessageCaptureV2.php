<?php
declare(strict_types=1);
require_once __DIR__.'/Max2CommonFrameV2.php';

/** Records only an authenticated native message reference in the new private table. */
final class Max2CommonMessageCaptureV2
{
    public function __construct(private PDO $pdo, private array $activation) {}
    private static function hold(string $reason): array { return ['status'=>'HOLD','reason'=>$reason,'frames_added'=>0,'provider_calls'=>0]; }
    public function captureCurrentHttp(int $now): array
    {
        try {
            $u = Max2CommonFrameV2::currentRequest('message_created');
            if ($u === null || !class_exists('MaxIncomingAdapter')) return self::hold('AUTHENTICATED_MESSAGE_REQUIRED');
            $user = MaxIncomingAdapter::user($u); $actor = (string)($user['user_id'] ?? $user['id'] ?? '');
            $mid = $u['message']['body']['mid'] ?? null; unset($u,$user);
            if (!Max2CommonFrameV2::id($actor) || !is_string($mid) || preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,180}\z/D',$mid) !== 1)
                return self::hold('NATIVE_MESSAGE_IDENTITY_REQUIRED');
            return $this->captureAuthenticated($actor,$mid,$now);
        } catch (Throwable $ignored) { return self::hold('MESSAGE_FRAME_UNAVAILABLE'); }
    }
    private function captureAuthenticated(string $actor, string $mid, int $now): array
    {
        if (!Max2CommonFrameV2::active($this->activation,$now) || $this->pdo->inTransaction()) return self::hold('MESSAGE_CAPTURE_NOT_ADMITTED');
        $mutation = false;
        try {
            $this->pdo->beginTransaction();
            $hint = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '/*+ MAX_EXECUTION_TIME(250) */ ' : '';
            $q = $this->pdo->prepare('SELECT '.$hint.'m.id,m.channel,m.direction,m.sender_type,m.sender_id,m.external_message_id,'
                .'c.id AS conversation_id,c.project_key,c.customer_id,c.customer_channel_id,c.external_chat_id,c.is_test,'
                .'cc.external_user_id,cc.customer_id AS channel_customer,cc.project_key AS channel_project,cc.channel AS channel_platform,cc.external_chat_id AS channel_chat'
                .' FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN customer_channels cc ON cc.id=c.customer_channel_id'
                ." WHERE m.channel='max' AND m.direction='inbound' AND m.external_message_id=? AND c.project_key='anytour' AND c.channel='max' AND cc.external_user_id=? LIMIT 2");
            $q->execute([$mid,$actor]); $rows = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
            if (count($rows) !== 1) { $this->pdo->rollBack(); return self::hold('EXACT_NATIVE_MESSAGE_UNPROVED'); }
            $r = $rows[0];
            if (!Max2CommonFrameV2::id((string)$r['id']) || !Max2CommonFrameV2::id((string)$r['conversation_id'])
                || !Max2CommonFrameV2::id((string)$r['customer_id']) || !Max2CommonFrameV2::id((string)$r['customer_channel_id'])
                || $r['sender_type'] !== 'customer' || (string)$r['sender_id'] !== $actor || $r['external_message_id'] !== $mid
                || $r['channel_project'] !== 'anytour' || $r['channel_platform'] !== 'max'
                || (string)$r['channel_customer'] !== (string)$r['customer_id'] || (string)$r['is_test'] !== '0'
                || $r['external_user_id'] !== $actor || $r['external_chat_id'] !== '-'.$actor || $r['channel_chat'] !== '-'.$actor)
                throw new RuntimeException('NATIVE_MESSAGE_SCOPE');
            $s = ['project_key'=>'anytour','platform'=>'max','customer_key'=>(string)$r['customer_id'],'conversation_id'=>(string)$r['conversation_id']];
            $key = Max2CommonFrameV2::messageKey($s,(string)$r['id']);
            $q = $this->pdo->prepare('SELECT frame_key FROM max2_common_entry_frames_v2 WHERE frame_key=? LIMIT 1');
            $q->execute([$key]); $exists = $q->fetchColumn(); $q->closeCursor();
            if ($exists !== false) { $this->pdo->rollBack(); return ['status'=>'ALREADY_CAPTURED','frames_added'=>0,'provider_calls'=>0]; }
            // A complete bounded prospective entry set, frozen at this actual message.
            // More than one entry does not become a latest-entry guess.
            $q = $this->pdo->prepare('SELECT '.$hint.'id FROM post_entries WHERE conversation_id=? AND created_at>=? AND created_at<=? ORDER BY id ASC LIMIT 2');
            $q->execute([$s['conversation_id'],gmdate('Y-m-d H:i:s',$this->activation['activated_at_unix']),gmdate('Y-m-d H:i:s',$now)]);
            $entries = $q->fetchAll(PDO::FETCH_COLUMN); $q->closeCursor();
            $entryFrameKey = null;
            if (count($entries) === 1) {
                $q = $this->pdo->prepare('SELECT frame_key FROM max2_common_entry_frames_v2 WHERE entry_id=? AND activation_sha256=? AND observed_at_unix<=? LIMIT 1');
                $q->execute([(string)$entries[0],$this->activation['activation_sha256'],$now]); $v = $q->fetchColumn(); $q->closeCursor();
                if (Max2CommonFrameV2::sha($v)) $entryFrameKey = $v;
            }
            $actorContext = ['subject'=>$s,'external_user_id'=>$actor,'external_chat_id'=>'-'.$actor,
                'customer_channel_id'=>(string)$r['customer_channel_id'],
                'subject_actor_binding_sha256'=>hash('sha256',Max2CommonFrameV2::canonical([$s,(string)$r['customer_channel_id'],$actor,'-'.$actor]))];
            $frame = ['version'=>1,'origin'=>'authenticated_max_customer_message_v2','frame_key'=>$key,'native_message_id'=>(string)$r['id'],
                'native_external_message_sha256'=>hash('sha256',$mid),'observed_at_unix'=>$now,'activation_sha256'=>$this->activation['activation_sha256'],
                'actor'=>$actorContext,'entry_frame_key'=>$entryFrameKey,'prospective_entry_count_bound'=>count($entries)];
            $json = Max2CommonFrameV2::canonical($frame); if (strlen($json)>15000) throw new RuntimeException('FRAME_BOUND');
            $q = $this->pdo->prepare('INSERT INTO max2_common_entry_frames_v2 (frame_key,project_key,platform,external_user_id,customer_channel_id,customer_key,conversation_id,entry_id,publication_id,version_id,entry_key,observed_at_unix,frame_json,frame_sha256,activation_sha256) VALUES(?,?,?,?,?,?,?,NULL,NULL,NULL,NULL,?,?,?,?)');
            $mutation = true; $q->execute([$key,'anytour','max',$actor,(string)$r['customer_channel_id'],$s['customer_key'],$s['conversation_id'],
                $now,$json,hash('sha256',$json),$this->activation['activation_sha256']]);
            if ($q->rowCount() !== 1) throw new RuntimeException('FRAME_WRITE_UNKNOWN');
            $this->pdo->commit(); return ['status'=>'CAPTURED','frames_added'=>1,'provider_calls'=>0];
        } catch (Throwable $ignored) {
            if ($this->pdo->inTransaction()) { try { $this->pdo->rollBack(); } catch (Throwable $ignored) {} }
            return ['status'=>$mutation?'UNKNOWN':'HOLD','reason'=>'MESSAGE_FRAME_UNAVAILABLE','frames_added'=>null,'provider_calls'=>0];
        }
    }
}
