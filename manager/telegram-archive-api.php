<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/TelegramArchiveBotSetup.php';
require_once dirname(__DIR__).'/services/TelegramArchiveAccountRegistry.php';

ManagerHttp::startJson();
try {
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager(); ManagerHttp::requireAdmin($manager);
    $project = ProjectConfig::projectId();
    if (!ProjectAccessService::canAccess((int)$manager['id'], $project)) ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET','POST'], true)) ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
    $data = $method === 'POST' ? ManagerHttp::body() : [];
    if ($method === 'POST') ManagerHttp::requireCsrf($data);
    session_write_close();
    $store = new TelegramArchiveBotConfig();
    // The optional callable is provided only by the CLI-server test router.
    $request = PHP_SAPI === 'cli-server' && getenv('TELEGRAM_ARCHIVE_HTTP_FIXTURE') === '1' ? ($telegramArchiveFixtureRequest ?? null) : null;
    $setup = new TelegramArchiveBotSetup($store, $request);
    $registry = new TelegramArchiveAccountRegistry();
    if ($method === 'POST') {
        $action = (string)($data['action'] ?? '');
        if ($action === 'status') { /* Read-only state via the shared admin transport. */ }
        elseif ($action === 'configure') $setup->configure(is_string($data['token'] ?? null) ? $data['token'] : '');
        elseif ($action === 'inspect') $setup->inspect();
        elseif ($action === 'approve' || $action === 'disable') {
            $id = TelegramDialogueNormalizer::id($data['account_id'] ?? null);
            $managerId = null;
            if ($action === 'approve') {
                $type = $data['account_type'] ?? 'manager';
                if (!in_array($type, ['manager','shared'], true) || ($type === 'shared' && isset($data['manager_id']))) throw new RuntimeException('archive_account_type_required');
                if ($type === 'manager') $managerId = (int)TelegramDialogueNormalizer::id($data['manager_id'] ?? null);
            }
            $registry->setCapture($project, TelegramArchiveBotConfig::USERNAME, $id, $action === 'approve', $managerId);
        } else ManagerHttp::respond(['ok'=>false,'error'=>'unsupported_action'],400);
    }
    ManagerHttp::respond(['ok'=>true,'bot'=>$store->publicStatus(),
        'accounts'=>$registry->listing($project, TelegramArchiveBotConfig::USERNAME), 'managers'=>$registry->managers($project)]);
} catch (Throwable $error) {
    $messages = [
        'invalid_bot_token'=>'Telegram не принял токен. Проверьте токен в BotFather.',
        'archive_bot_mismatch'=>'Нужен токен бота @'.TelegramArchiveBotConfig::USERNAME.'.',
        'archive_bot_has_other_webhook'=>'Этот бот уже подключён к другому сервису. Проверьте его подключение перед настройкой.',
        'archive_bot_has_custom_certificate'=>'Для этого бота нужна отдельная проверка сертификата подключения.',
        'private_config_unavailable'=>'Приватное хранилище настройки недоступно. Сообщите администратору.',
        'webhook_configuration_changed'=>'Настройки бота изменились во время проверки. Обновите состояние.',
        'webhook_requires_readback'=>'Результат настройки требует проверки. Нажмите «Проверить подключение»; повторная отправка настройки остановлена.',
        'telegram_unavailable'=>'Telegram сейчас не ответил. Проверьте подключение позже.',
        'archive_manager_not_allowed'=>'Выберите действующего менеджера этого проекта.',
        'archive_account_type_required'=>'Выберите общий аккаунт или конкретного менеджера.',
        'archive_account_not_found'=>'Аккаунт больше не найден. Обновите список.',
        'archive_account_disconnected'=>'Сначала подключите бота в настройках этого Telegram-аккаунта.',
        'invalid_telegram_identity'=>'Проверьте выбранный аккаунт и менеджера.',
    ];
    $code = $error->getMessage();
    ManagerHttp::respond(['ok'=>false,'error'=>isset($messages[$code]) ? $code : 'setup_unavailable',
        'message'=>$messages[$code] ?? 'Настройка временно недоступна. Обновите страницу позже.'], isset($messages[$code]) ? 409 : 503);
}
