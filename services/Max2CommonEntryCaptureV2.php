<?php
declare(strict_types=1);
require_once __DIR__.'/Max2CommonEntryEvidenceV2.php';
require_once __DIR__.'/Max2CommonFrameV2.php';

/** Optional post-record observer; all failures leave the canonical customer flow intact. */
final class Max2CommonEntryCaptureV2
{
    public function __construct(private PDO $pdo, private array $activation, private string $mapFile) {}

    /** Called after the existing recordEntry transaction returns a NEW exact receipt. */
    public function captureCurrentHttp(array $record, array $conversation, array $resolved, string $eventKey, int $now): array
    {
        try {
            $u = Max2CommonFrameV2::currentRequest('bot_started');
            if (!is_array($u) || ($u['update_type'] ?? null) !== 'bot_started' || !class_exists('MaxIncomingAdapter')
                || !class_exists('ChannelPostEntryService') || ChannelPostEntryService::eventKey('max',$u) !== $eventKey) return self::hold('MAX_START_UNPROVED');
            $user = MaxIncomingAdapter::user($u); $actor = (string)($user['user_id'] ?? $user['id'] ?? ''); unset($u,$user);
            if (preg_match('/\A[1-9][0-9]{0,19}\z/D',$actor) !== 1) return self::hold('MAX_ACTOR_UNPROVED');
            return $this->captureAuthenticated($record,$conversation,$resolved,$eventKey,$actor,$now);
        } catch (Throwable $ignored) { return self::hold('FRAME_CAPTURE_UNAVAILABLE'); }
    }

    private static function hold(string $reason): array { return ['status'=>'HOLD','reason'=>$reason,'frames_added'=>0,'provider_calls'=>0]; }
    private function captureAuthenticated(array $record, array $conversation, array $resolved, string $eventKey, string $actor, int $now): array
    {
        $a = $this->activation;
        if (!Max2CommonFrameV2::active($a,$now)
            || ($record['duplicate'] ?? null) !== false || ($conversation['project_key'] ?? null) !== 'anytour'
            || ($conversation['channel'] ?? null) !== 'max' || ($resolved['project_key'] ?? null) !== 'anytour'
            || ($resolved['platform'] ?? null) !== 'max' || $this->pdo->inTransaction()) return self::hold('FRAME_CAPTURE_NOT_ADMITTED');
        $mutation = false;
        try {
            $this->pdo->beginTransaction();
            $e = (new Max2CommonEntryEvidenceV2($this->pdo,$this->mapFile))->read((string)($record['entry_id'] ?? ''),
                (string)($conversation['id'] ?? ''),$actor,$eventKey);
            if ($e === null || $e['publication_id'] !== (string)($resolved['publication_id'] ?? '')
                || $e['version_id'] !== (string)($resolved['version_id'] ?? '')
                || $e['version_id'] !== (string)($record['version_id'] ?? '')) {
                $this->pdo->rollBack(); return self::hold('EXACT_PUBLICATION_PAIR_UNPROVED');
            }
            $frameKey = hash('sha256','max2/common/entry-frame/v2|'.$e['entry_id'].'|'.$e['entry_key']);
            $q = $this->pdo->prepare('SELECT frame_key FROM max2_common_entry_frames_v2 WHERE entry_id=? LIMIT 1');
            $q->execute([$e['entry_id']]); $existing = $q->fetchColumn(); $q->closeCursor();
            if ($existing !== false) { $this->pdo->rollBack(); return ['status'=>'ALREADY_CAPTURED','frames_added'=>0,'provider_calls'=>0]; }
            $frame = ['version'=>1,'origin'=>'authenticated_max_post_entry_v2','frame_key'=>$frameKey,
                'observed_at_unix'=>$now,'activation_sha256'=>$a['activation_sha256'],'entry'=>$e];
            $json = Max2CommonFrameV2::canonical($frame);
            if (strlen($json)>16384) throw new RuntimeException('FRAME_BOUND');
            $q = $this->pdo->prepare('INSERT INTO max2_common_entry_frames_v2'
                . '(frame_key,project_key,platform,external_user_id,customer_channel_id,customer_key,conversation_id,entry_id,publication_id,version_id,entry_key,observed_at_unix,frame_json,frame_sha256,activation_sha256)'
                . ' VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $mutation = true;
            $q->execute([$frameKey,'anytour','max',$actor,$e['customer_channel_id'],$e['subject']['customer_key'],$e['subject']['conversation_id'],
                $e['entry_id'],$e['publication_id'],$e['version_id'],$e['entry_key'],$now,$json,hash('sha256',$json),$a['activation_sha256']]);
            if ($q->rowCount() !== 1) throw new RuntimeException('FRAME_WRITE_UNKNOWN');
            $this->pdo->commit();
            return ['status'=>'CAPTURED','frames_added'=>1,'provider_calls'=>0];
        } catch (Throwable $ignored) {
            if ($this->pdo->inTransaction()) { try { $this->pdo->rollBack(); } catch (Throwable $ignored) {} }
            return ['status'=>$mutation?'UNKNOWN':'HOLD','reason'=>'FRAME_CAPTURE_UNAVAILABLE','frames_added'=>null,'provider_calls'=>0];
        }
    }
}
