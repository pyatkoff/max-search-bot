<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0');
$root = '/var/www/anytoour/data/www/app.anytoour.ru';
function as39TelegramEmit(array $result): void {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}

try {
    if ($argc !== 2 || $argv[1] !== '--apply' || !chdir($root)) throw new RuntimeException('fixed_publish_only');
    exec('git rev-parse HEAD', $head, $code);
    if ($code !== 0 || $head !== ['eb7ab9472e6b556812dc3d209a155d291c2077b6']) throw new RuntimeException('installed_checkout_changed');
    exec('git status --porcelain --untracked-files=no', $dirty, $code);
    if ($code !== 0 || $dirty !== []) throw new RuntimeException('installed_checkout_changed');
    require_once $root.'/config.php';
    require_once $root.'/manager/lib/ManagerHttp.php';
    require_once $root.'/services/AppleService39PilotPublicationStore.php';
    require_once $root.'/services/AppleService39TelegramDevicePublisher.php';

    ProjectAccessService::initializeReadOnly();
    $db = ConversationDb::connection();
    $campaign = (new AppleService39PilotPublicationStore($db))->campaign();
    if (($campaign['project_key'] ?? null) !== 'appleservice39' || ($campaign['bot_key'] ?? null) !== 'id390521129062_bot')
        throw new RuntimeException('existing_project_binding_unavailable');
    $managerId = (int)($campaign['admin_manager_id'] ?? 0);
    $connection = AppleService39TelegramConnection::current();
    $status = $connection->status($managerId);
    $publisher = new AppleService39TelegramDevicePublisher($db);
    as39TelegramEmit(['stage'=>'preflight','configured'=>$status['configured'],'webhook_state'=>$status['webhook_state'],
        'posts'=>$publisher->status()]);
    $connection->requireReadyForPublication($managerId);
    $posts = $publisher->publish($managerId);
    $published = count(array_filter($posts, static fn(array $post): bool => $post['state'] === 'published'));
    as39TelegramEmit(['ok'=>$published === 4,'published'=>$published,'posts'=>$posts]);
    exit($published === 4 ? 0 : 1);
} catch (Throwable $error) {
    $message = $error->getMessage();
    as39TelegramEmit(['ok'=>false,'reason'=>in_array($message, [
        'fixed_publish_only','installed_checkout_changed','existing_project_binding_unavailable','forbidden','connection_required',
        'webhook_conflict','telegram_connection_required','apple_project_admin_required','telegram_read_failed',
        'telegram_identity_mismatch','telegram_channel_post_access_required','telegram_receipt_uncertain','storage_unavailable',
        'source_unavailable','context_changed','webhook_unavailable'], true) ? $message : 'publication_stopped']);
    exit(1);
}
