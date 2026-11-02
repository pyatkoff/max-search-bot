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
