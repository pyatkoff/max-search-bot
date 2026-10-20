<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
ini_set('log_errors','0');
$pdo=null;
try {
    $root=getcwd();
    if ($root!=='/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('scope');
    require_once $root.'/config.php';
    require_once $root.'/services/ProjectAccessService.php';
    require_once $root.'/services/TelegramArchiveBotConfig.php';
    if (ProjectConfig::projectId()!=='anytour') throw new RuntimeException('project');
    ProjectAccessService::initializeReadOnly();
    $pdo=ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('driver');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $q=$pdo->prepare('SELECT account_id,provider_enabled,approved,manager_id FROM telegram_archive_accounts WHERE project_key=? AND bot_key=? ORDER BY account_id LIMIT 101');
    $q->execute(['anytour',TelegramArchiveBotConfig::USERNAME]);
    $accounts=$q->fetchAll(PDO::FETCH_ASSOC);
    $summary=['registered'=>min(100,count($accounts)),'coverage_complete'=>count($accounts)<=100,'connected'=>0,'capture_enabled'=>0,'capture_pending'=>0,'connected_with_recent_live'=>0];
    $records=[]; $cutoff=time()-86400;
    $sql="SELECT COUNT(*) total,
        SUM(source_kind='desktop_import') imported,
        SUM(source_kind='business_message') live,
        SUM(source_kind='business_message' AND version_at>=?) live_24h,
        SUM(source_kind='business_message' AND version_at>=? AND JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.direction'))='inbound') inbound_24h,
        SUM(source_kind='business_message' AND version_at>=? AND JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.direction'))='outbound') outbound_24h,
        MAX(CASE WHEN source_kind='business_message' THEN version_at ELSE NULL END) latest_live
        FROM telegram_dialogue_messages
        WHERE project_key=? AND account_id=? AND is_deleted=0 AND
        (source_kind='desktop_import' OR (source_kind='business_message' AND JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.bot_key'))=?))";
    $q=$pdo->prepare($sql);
    foreach (array_slice($accounts,0,100) as $index=>$account) {
        $connected=(bool)$account['provider_enabled']; $enabled=$connected && (bool)$account['approved'];
        $q->execute([$cutoff,$cutoff,$cutoff,'anytour',$account['account_id'],TelegramArchiveBotConfig::USERNAME]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        $latest=$row['latest_live']; unset($row['latest_live']);
        $counts=array_map(static fn($value):int=>(int)$value,$row);
        $summary['connected']+=(int)$connected;
        $summary['capture_enabled']+=(int)$enabled;
        $summary['capture_pending']+=(int)($connected && !(bool)$account['approved']);
        $summary['connected_with_recent_live']+=(int)($connected && $counts['live_24h']>0);
        $records[]=['account_number'=>$index+1,'connected'=>$connected,'capture_enabled'=>$enabled,
            'shared'=>$account['manager_id']===null,'counts'=>$counts,
            'latest_live_message_utc'=>$latest===null ? null : gmdate('c',(int)$latest)];
    }
    $pdo->rollBack();
    echo json_encode(['ok'=>true,'checked_at_utc'=>gmdate('c'),'accounts'=>$summary,'records'=>$records],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    echo '{"ok":false,"error":"capture_inventory_unavailable"}'."\n";
    exit(1);
}
