<?php
declare(strict_types=1);
require_once __DIR__.'/HotelWorkQueue.php';

/** Internal server wiring only. No MCP argument can enable or disable this. */
final class HotelWorkQueueFactory
{
    public static function create(PDO $pdo, $clock = null, ?bool $journalProtection = null): HotelWorkQueue
    {
        // This compatibility switch cannot disable the intrinsic sticky policy.
        // Enrollment is an explicit internal operation, never factory construction.
        return new HotelWorkQueue($pdo, $clock);
    }

    /** Caller already holds lane then queue locks and has authenticated its
     * method. A queue remains protected across stage changes and handovers.
     * FOR UPDATE is a current read after waiting, not a stale MySQL snapshot.
     * This extra bridge is retained for internal compatibility, not enforcement.
     */
    public static function journalGuard(PDO $pdo): callable
    {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        return static function (int $admin, string $connection, string $queue, string $stage) use ($pdo, $lock): void {
            $q = $pdo->prepare('SELECT stage_id FROM hotel_journal_heads WHERE queue_id=? ORDER BY stage_id LIMIT 1'.$lock);
            $q->execute([$queue]);
            if ($q->fetchColumn() !== false) throw new HotelWorkQueueConflict('generation_required_legacy_mutation');
        };
    }
}
