<?php
declare(strict_types=1);

/** Existing application PDO only. Lock order: HOTEL lane -> campaign -> attempt.
 * The callback shares this exact PDO/transaction. No locks are held over network.
 * Reservation precedes bootstrap; a crash leaves the lane busy, never available.
 */
final class HotelServerLane
{
    public function __construct(private PDO $app) {}
    public function connection(): PDO { return $this->app; }

    public function reserve(string $campaign, callable $authorized): void
    {
        if ($campaign===HotelServerInputs::OLD_QUEUE || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$campaign)) throw new DomainException('new_campaign_id_required');
        if ($authorized() !== true) throw new DomainException('campaign_bootstrap_not_authorized');
        $this->locked(function(array $lane) use ($campaign): void {
            if ($lane['queue_id'] !== null && $lane['queue_id'] !== $campaign) throw new DomainException('hotel_lane_busy');
            $s = $this->app->prepare('UPDATE hotel_work_lane SET queue_id=? WHERE lane_key=?'); $s->execute([$campaign,'HOTEL']);
        });
    }

    public function own(string $campaign, callable $work): mixed
    {
        return $this->locked(function(array $lane) use ($campaign,$work): mixed {
            if ($lane['queue_id'] !== $campaign) throw new DomainException('hotel_lane_not_owned');
            return $work();
        });
    }

    /** The callback validates the terminal predecessor and installs a disabled successor.
     * The ownership move and every callback write commit or roll back together.
     */
    public function handoff(string $predecessor, string $successor, callable $authorized, callable $work): mixed
    {
        if ($successor===HotelServerInputs::OLD_QUEUE || $successor===$predecessor
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$successor)) throw new DomainException('new_campaign_id_required');
        if ($authorized() !== true) throw new DomainException('campaign_bootstrap_not_authorized');
        return $this->locked(function(array $lane) use($predecessor,$successor,$work): mixed {
            if ($lane['queue_id'] !== $predecessor) throw new DomainException('hotel_lane_not_owned');
            $s=$this->app->prepare('UPDATE hotel_work_lane SET queue_id=? WHERE lane_key=? AND queue_id=?');
            $s->execute([$successor,'HOTEL',$predecessor]);
            if ($s->rowCount()!==1) throw new DomainException('hotel_lane_handoff_CAS');
            return $work();
        });
    }

    /** Fixed projection acknowledgement only; retired campaign ownership is never restored. */
    public function acknowledgeProjection(string $campaign,string $receipt,string $payloadSha256): void
    {
        HotelServerInputs::sha($receipt);HotelServerInputs::sha($payloadSha256);
        $this->locked(function(array $lane) use($campaign,$receipt,$payloadSha256): void {
            if($lane['queue_id']!==$campaign){
                $s=$this->app->prepare('SELECT state,enabled FROM hotel_server_campaigns WHERE campaign_id=?');$s->execute([$campaign]);$c=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
                if(!$c||$c['state']!=='complete'||(int)$c['enabled']!==0)throw new DomainException('hotel_lane_not_owned');
            }
            $s=$this->app->prepare('SELECT * FROM hotel_server_catalog_outbox WHERE campaign_id=? AND receipt_sha256=?');$s->execute([$campaign,$receipt]);$out=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
            $s=$this->app->prepare('SELECT receipt_bytes FROM hotel_server_receipts WHERE campaign_id=? AND receipt_sha256=?');$s->execute([$campaign,$receipt]);$raw=$s->fetchColumn();$s->closeCursor();
            if(!$out||!in_array($out['state'],['pending','done'],true)||!is_string($raw)||hash('sha256',$raw)!==$receipt
                ||hash('sha256',$out['payload_bytes'])!==$payloadSha256)throw new DomainException('projection_ack_CAS');
            $r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(($r['schema']??null)!=='hotel-server-acceptance-v1'||($r['campaign_id']??null)!==$campaign)throw new DomainException('projection_ack_CAS');
            $baseline=HotelServerInputs::sha($r['baseline_sha256']??null);
            $s=$this->app->prepare('SELECT parent_sha256,document_bytes FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?');$s->execute([$campaign,$baseline]);$b=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
            if(!$b||$b['document_bytes']!==$out['payload_bytes']||hash('sha256',$b['document_bytes'])!==$baseline
                ||($r['previous_baseline_sha256']??null)!==$b['parent_sha256'])throw new DomainException('projection_ack_CAS');
            $d=json_decode($b['document_bytes'],true,64,JSON_THROW_ON_ERROR);
            if(($d['schema']??null)!=='hotel-server-baseline-v1'||($d['parent_sha256']??null)!==$b['parent_sha256']
                ||($d['candidate_sha256']??null)!==($r['candidate_sha256']??null))throw new DomainException('projection_ack_CAS');
            $s=$this->app->prepare("UPDATE hotel_server_catalog_outbox SET state='done' WHERE campaign_id=? AND receipt_sha256=?");$s->execute([$campaign,$receipt]);
        });
    }

    private function locked(callable $work): mixed
    {
        if ($this->app->inTransaction()) throw new LogicException('foreign_lane_transaction');
        $this->app->beginTransaction();
        try {
            $sqlite = $this->app->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            if ($sqlite) $this->app->exec("UPDATE hotel_work_lane SET queue_id=queue_id WHERE lane_key='HOTEL'");
            $lane = $this->app->query("SELECT * FROM hotel_work_lane WHERE lane_key='HOTEL'" . ($sqlite ? '' : ' FOR UPDATE'))->fetch(PDO::FETCH_ASSOC);
            if (!$lane) throw new DomainException('shared_hotel_lane_missing');
            $result = $work($lane); $this->app->commit(); return $result;
        } catch (Throwable $e) { if ($this->app->inTransaction()) $this->app->rollBack(); throw $e; }
    }
}
