<?php
declare(strict_types=1);

/** Private provider-read cursor. Publication write ownership stays in the registry. */
final class ChannelPostReconciliationState
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }

    /** Called inside the channel configuration transaction; re-enable starts now. */
    public function configured(int $channelId, bool $wasEnabled, bool $enabled, int $nowMs): void
    {
        $q=$this->pdo->prepare('SELECT post_channel_id FROM post_channel_reconciliation WHERE post_channel_id=?');
        $q->execute([$channelId]); $exists=$q->fetchColumn()!==false;
        if (!$exists) {
            $q=$this->pdo->prepare('INSERT INTO post_channel_reconciliation (post_channel_id,enabled_since_ms,completed_through_ms) VALUES (?,?,?)');
            $q->execute([$channelId,$nowMs,$nowMs]);
        } elseif ($enabled&&!$wasEnabled) {
            $q=$this->pdo->prepare("UPDATE post_channel_reconciliation SET enabled_since_ms=?,completed_through_ms=?,scan_until_ms=NULL,before_ms=NULL,claim_token=NULL,claimed_at=NULL,last_checked_at=NULL,error_reason='' WHERE post_channel_id=?");
            $q->execute([$nowMs,$nowMs,$channelId]);
        } else {
            $q=$this->pdo->prepare('UPDATE post_channel_reconciliation SET claim_token=NULL,claimed_at=NULL WHERE post_channel_id=?');
            $q->execute([$channelId]);
        }
    }

    /** One fair channel/page per invocation; expired reads never authorize an edit. */
    public function claim(string $project, string $bot, int $nowMs, bool $apply=true): ?array
    {
        if($apply)$this->pdo->beginTransaction();
        try {
            $lock=$apply&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$this->pdo->prepare("SELECT r.*,pc.channel_id,pc.config_version,pc.bot_key,pc.canonical_bot_username,pc.bot_url,pc.button_text,pc.is_enabled FROM post_channel_reconciliation r JOIN post_channels pc ON pc.id=r.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pc.source_id AND s.project_id=pc.project_id WHERE p.project_key=? AND pc.platform='max' AND pc.bot_key=? AND pc.is_enabled=1 AND p.is_active=1 AND s.is_active=1 AND s.channel='max' AND s.handling_mode IN ('manager','ask') AND (r.claim_token IS NULL OR r.claimed_at<?) ORDER BY COALESCE(r.last_checked_at,'1970-01-01 00:00:00'),r.post_channel_id LIMIT 1".$lock);
            $q->execute([$project,$bot,gmdate('Y-m-d H:i:s',(int)floor($nowMs/1000)-120)]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row) { if($apply)$this->pdo->commit(); return null; }
            $row['claim_token']=bin2hex(random_bytes(16));
            $row['scan_until_ms']=$row['scan_until_ms']===null?$nowMs:(int)$row['scan_until_ms'];
            $row['before_ms']=$row['before_ms']===null?$row['scan_until_ms']:(int)$row['before_ms'];
            // Provider visibility can lag its publication timestamp. Re-read the
            // last five minutes; registry identity prevents duplicate operations.
            $row['after_ms']=max((int)$row['enabled_since_ms'],(int)$row['completed_through_ms']-300000);
            if(!$apply)return $row;
            $q=$this->pdo->prepare('UPDATE post_channel_reconciliation SET claim_token=?,claimed_at=?,last_checked_at=?,scan_until_ms=?,before_ms=? WHERE post_channel_id=?');
            $at=gmdate('Y-m-d H:i:s',(int)floor($nowMs/1000));
            $q->execute([$row['claim_token'],$at,$at,$row['scan_until_ms'],$row['before_ms'],$row['post_channel_id']]);
            $this->pdo->commit(); return $row;
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function finish(array $claim, ?int $nextBefore, string $reason=''): bool
    {
        if(!preg_match('/\A[a-z_]{0,96}\z/',$reason))throw new InvalidArgumentException('invalid_reconciliation_reason');
        $complete=$reason===''&&$nextBefore===null;
        $sql=$complete
            ? "UPDATE post_channel_reconciliation SET completed_through_ms=?,scan_until_ms=NULL,before_ms=NULL,claim_token=NULL,claimed_at=NULL,error_reason='' WHERE post_channel_id=? AND claim_token=?"
            : 'UPDATE post_channel_reconciliation SET before_ms=?,claim_token=NULL,claimed_at=NULL,error_reason=? WHERE post_channel_id=? AND claim_token=?';
        $args=$complete?[(int)$claim['scan_until_ms'],$claim['post_channel_id'],$claim['claim_token']]:[$nextBefore??$claim['before_ms'],$reason,$claim['post_channel_id'],$claim['claim_token']];
        $q=$this->pdo->prepare($sql);$q->execute($args);return $q->rowCount()===1;
    }
}
