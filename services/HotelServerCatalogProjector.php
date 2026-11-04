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
    {
        if ($app===$hotels || $app!==$lane->connection()) throw new InvalidArgumentException('separate_catalog_PDO_required');
        if ($hotels->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION) throw new InvalidArgumentException('catalog_exception_mode_required');
    }

    public function project(string $campaign,string $receipt): array
    {
        HotelServerInputs::sha($receipt);
        if ($this->hotels->inTransaction()) throw new LogicException('foreign_projection_transaction');
        // Verify acceptance/correction evidence before any catalogue row can become visible.
        // Release the operational lock before opening the separate catalogue transaction.
        $out=$this->lane->projectionPayload($campaign,$receipt);
        $hash=$out['payload_sha256'];
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
        $this->lane->acknowledgeProjection($campaign,$receipt,$hash);
        return ['state'=>'projected','receipt_sha256'=>$receipt];
    }
}
