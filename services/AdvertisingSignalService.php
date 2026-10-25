<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/AdvertisingSignalRepository.php';

/** Best-effort local observations. No network, source upload or conversion submission. */
final class AdvertisingSignalService
{
    public static function scope(): ?array
    {
        if (ProjectConfig::projectId() !== 'anytour') return null;
        return ['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'];
    }
    public static function incoming(array $incoming): void
    {
        if (strtolower(trim((string)($incoming['platform']??''))) !== 'telegram' || !in_array($incoming['type']??'', ['message','callback','contact'],true)) return;
        self::observe(static function(AdvertisingSignalRepository $repo) use ($incoming): void {
            $native=(string)($incoming['message_id']??'');
            if ($native==='' && $incoming['type']==='callback') $native=(string)($incoming['callback_id']??'');
            $repo->incoming((string)($incoming['user']['chat_id']??''),$native,$incoming['type'],time());
        });
    }
    public static function request(int $conversationId): void
    {
        if ($conversationId<=0) return;
        self::observe(static function(AdvertisingSignalRepository $repo) use ($conversationId): void { $repo->request($conversationId,time()); });
    }
    private static function observe(callable $call): void
    {
        try {
            $scope=self::scope();
            if (!$scope || !ConversationDb::isConfigured()) return;
            $call(new AdvertisingSignalRepository(ConversationDb::connection(),$scope));
        } catch (Throwable $e) {
            if (class_exists('DiagnosticLogger')) try { DiagnosticLogger::log('advertising_signal','capture_failed',['reason'=>'storage_unavailable'],null,'warning'); } catch (Throwable $ignored) {}
        }
    }
}
