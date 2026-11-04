<?php
declare(strict_types=1);
require_once __DIR__.'/HotelServerCorrection.php';

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

    /** Admit the immutable accepted payload before a separate catalogue write. */
    public function projectionPayload(string $campaign,string $receipt): array
    {
        HotelServerInputs::sha($receipt);
        return $this->locked(fn(array $lane):array=>$this->validatedProjection($lane,$campaign,$receipt));
    }

    /** Fixed projection acknowledgement only; retired campaign ownership is never restored. */
    public function acknowledgeProjection(string $campaign,string $receipt,string $payloadSha256): void
    {
        HotelServerInputs::sha($receipt);HotelServerInputs::sha($payloadSha256);
        $this->locked(function(array $lane) use($campaign,$receipt,$payloadSha256): void {
            $out=$this->validatedProjection($lane,$campaign,$receipt);
            if($out['payload_sha256']!==$payloadSha256)throw new DomainException('projection_ack_CAS');
            if($out['state']==='done')return;
            $s=$this->app->prepare("UPDATE hotel_server_catalog_outbox SET state='done' WHERE campaign_id=? AND receipt_sha256=? AND state='pending'");$s->execute([$campaign,$receipt]);
            if($s->rowCount()!==1)throw new DomainException('projection_ack_CAS');
        });
    }

    /** Shared by the pre-write admission and the later ACK under the HOTEL lock. */
    private function validatedProjection(array $lane,string $campaign,string $receipt): array
    {
        if($lane['queue_id']!==$campaign){
            $s=$this->app->prepare('SELECT state,enabled FROM hotel_server_campaigns WHERE campaign_id=?');$s->execute([$campaign]);$c=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
            if(!$c||$c['state']!=='complete'||(int)$c['enabled']!==0)throw new DomainException('hotel_lane_not_owned');
        }
        $s=$this->app->prepare('SELECT * FROM hotel_server_catalog_outbox WHERE campaign_id=? AND receipt_sha256=?');$s->execute([$campaign,$receipt]);$out=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
        $s=$this->app->prepare('SELECT receipt_bytes FROM hotel_server_receipts WHERE campaign_id=? AND receipt_sha256=?');$s->execute([$campaign,$receipt]);$raw=$s->fetchColumn();$s->closeCursor();
        if(!$out||!in_array($out['state'],['pending','done'],true)||!is_string($raw)||hash('sha256',$raw)!==$receipt)throw new DomainException('projection_ack_CAS');
        $r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!in_array($r['schema']??null,['hotel-server-acceptance-v1','hotel-server-correction-acceptance-v1'],true)
            ||($r['campaign_id']??null)!==$campaign)throw new DomainException('projection_ack_CAS');
        $baseline=HotelServerInputs::sha($r['baseline_sha256']??null);
        $s=$this->app->prepare('SELECT parent_sha256,document_bytes FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?');$s->execute([$campaign,$baseline]);$b=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
        if(!$b||$b['document_bytes']!==$out['payload_bytes']||hash('sha256',$b['document_bytes'])!==$baseline
            ||($r['previous_baseline_sha256']??null)!==$b['parent_sha256'])throw new DomainException('projection_ack_CAS');
        $d=json_decode($b['document_bytes'],true,64,JSON_THROW_ON_ERROR);
        if(($d['schema']??null)!=='hotel-server-baseline-v1'||($d['parent_sha256']??null)!==$b['parent_sha256']
            ||($d['candidate_sha256']??null)!==($r['candidate_sha256']??null))throw new DomainException('projection_ack_CAS');
        if($r['schema']==='hotel-server-correction-acceptance-v1')$this->verifyCorrectionProjection($campaign,$r,$raw,$b['document_bytes']);
        return ['payload_bytes'=>$out['payload_bytes'],'payload_sha256'=>$baseline,'state'=>$out['state']];
    }

    /** The new receipt is admitted only with its immutable correction and exact
     * saved negative-review lineage, not through a schema-name whitelist alone.
     * Called under the existing HOTEL lock; no new campaign/worker/history read.
     */
    private function verifyCorrectionProjection(string $campaign,array $receipt,string $receiptBytes,string $deltaBytes): void
    {
        $one=function(string $sql,array $args):array{
            $s=$this->app->prepare($sql);$s->execute($args);$row=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
            if(!$row)throw new DomainException('projection_correction_evidence_required');return $row;
        };
        $stage=$receipt['stage_index']??null;
        if(!is_int($stage)||$stage<0||$stage>=10)throw new DomainException('projection_ack_CAS');
        $c=$one('SELECT * FROM hotel_server_campaigns WHERE campaign_id=?',[$campaign]);
        $input=$one('SELECT package_bytes FROM hotel_server_inputs WHERE campaign_id=?',[$campaign]);
        $ledger=$one('SELECT * FROM hotel_server_corrections WHERE campaign_id=? AND stage_index=?',[$campaign,$stage]);
        $candidate=$one('SELECT * FROM hotel_server_candidates WHERE campaign_id=? AND stage_index=?',[$campaign,$stage]);
        $review=$one('SELECT * FROM hotel_server_reviews WHERE campaign_id=? AND stage_index=?',[$campaign,$stage]);
        $producer=$one('SELECT * FROM hotel_server_attempts WHERE attempt_id=?',[$candidate['producer_attempt']]);
        $reviewer=$one('SELECT * FROM hotel_server_attempts WHERE attempt_id=?',[$review['review_attempt']]);
        $c['baseline_sha256']=HotelServerInputs::sha($receipt['previous_baseline_sha256']??null);
        $package=json_decode($input['package_bytes'],true,64,JSON_THROW_ON_ERROR);
        $plan=json_decode($c['plan_bytes'],true,64,JSON_THROW_ON_ERROR);
        $correction=json_decode($ledger['correction_bytes'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($package)||!is_array($plan)||!is_array($correction))throw new DomainException('projection_ack_CAS');
        $v=HotelServerCorrection::prepare($c,$package,$plan,$producer,$reviewer,$candidate,$review,$correction);
        foreach(['correction_bytes','correction_sha256','candidate_bytes','candidate_sha256'] as $field)
            if($ledger[$field]!==$v[$field])throw new DomainException('projection_correction_ledger');
        $expectedDelta=HotelServerInputs::json(['schema'=>'hotel-server-baseline-v1','parent_sha256'=>$c['baseline_sha256'],
            'candidate_sha256'=>$v['candidate_sha256'],'records'=>$v['candidate']['records']]);
        $expected=HotelServerCorrection::receipt($c,$stage,$v,$candidate,$producer,$reviewer,hash('sha256',$expectedDelta),$package['retained_admission']??null);
        if($deltaBytes!==$expectedDelta||$receiptBytes!==HotelServerInputs::json($expected))throw new DomainException('projection_correction_receipt');
        foreach(['producer'=>$producer,'reviewer'=>$reviewer] as $role=>$attempt){
            $out=$one('SELECT state,attempt_id FROM hotel_server_outbox WHERE campaign_id=? AND stage_index=? AND role=?',[$campaign,$stage,$role]);
            if($out['state']!=='done'||$out['attempt_id']!==$attempt['attempt_id'])throw new DomainException('projection_correction_outbox');
        }
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

