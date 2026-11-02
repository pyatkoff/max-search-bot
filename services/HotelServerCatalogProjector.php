<?php
declare(strict_types=1);
require_once __DIR__.'/HotelServerLane.php';
require_once __DIR__.'/HotelServerInputs.php';

/** At-least-once projection, NOT a distributed acceptance transaction.
 * A committed catalog receipt followed by lost ACK is safely replayable by exact hash.
 * Provider attempts and acceptance are never replayed by this consumer.
 */
final class HotelServerCatalogProjector
{
    public function __construct(private PDO $app,private PDO $hotels,private HotelServerLane $lane,private $fault=null)
    { if ($app===$hotels || $app!==$lane->connection()) throw new InvalidArgumentException('separate_catalog_PDO_required'); }

    public function project(string $campaign,string $receipt): array
    {
        HotelServerInputs::sha($receipt);
        $s=$this->app->prepare('SELECT * FROM hotel_server_catalog_outbox WHERE campaign_id=? AND receipt_sha256=?'); $s->execute([$campaign,$receipt]); $out=$s->fetch(PDO::FETCH_ASSOC);
        $s->closeCursor();
        if (!$out) throw new DomainException('catalog_outbox_required');
        $hash=hash('sha256',$out['payload_bytes']);
        if ($this->hotels->inTransaction()) throw new LogicException('foreign_projection_transaction');
        $this->hotels->beginTransaction();
        try {
            $s=$this->hotels->prepare('SELECT * FROM hotel_server_catalog_documents WHERE receipt_sha256=?'); $s->execute([$receipt]); $old=$s->fetch(PDO::FETCH_ASSOC);
            $s->closeCursor();
            if ($old) {
                if ($old['payload_sha256']!==$hash || $old['document_bytes']!==$out['payload_bytes']) throw new DomainException('projection_hash_conflict');
            } else {
                $s=$this->hotels->prepare('INSERT INTO hotel_server_catalog_documents(receipt_sha256,payload_sha256,document_bytes) VALUES(?,?,?)'); $s->execute([$receipt,$hash,$out['payload_bytes']]);
            }
            $this->hotels->commit();
        } catch(Throwable $e) { if ($this->hotels->inTransaction()) $this->hotels->rollBack(); throw $e; }
        if ($this->fault!==null) ($this->fault)('projection_after_catalog_commit');
        $this->lane->own($campaign,function() use($receipt,$hash): void {
            $s=$this->app->prepare('SELECT payload_bytes FROM hotel_server_catalog_outbox WHERE receipt_sha256=?'); $s->execute([$receipt]);
            if (hash('sha256',(string)$s->fetchColumn())!==$hash) throw new DomainException('projection_ack_CAS');
            $s=$this->app->prepare("UPDATE hotel_server_catalog_outbox SET state='done' WHERE receipt_sha256=?"); $s->execute([$receipt]);
        });
        return ['state'=>'projected','receipt_sha256'=>$receipt];
    }
}
