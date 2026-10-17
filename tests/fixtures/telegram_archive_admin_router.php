<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || getenv('TELEGRAM_ARCHIVE_HTTP_FIXTURE') !== '1') { http_response_code(404); exit; }
$root = (string)getenv('TELEGRAM_ARCHIVE_HTTP_ROOT');
$state = (string)getenv('TELEGRAM_ARCHIVE_HTTP_STATE');
require_once $root.'/config.php';
require_once $root.'/services/ConversationDb.php';
require_once $root.'/services/ProjectConfig.php';
$pdo = new PDO('sqlite:'.$state.'/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$property = new ReflectionProperty(ConversationDb::class, 'pdo'); $property->setValue(null, $pdo);
ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/__fixture_login') {
    require_once $root.'/manager/lib/ManagerHttp.php'; ManagerHttp::start();
    $role = (string)($_GET['role'] ?? 'admin');
    $_SESSION['manager_id'] = ['admin'=>11,'manager'=>12,'foreign'=>14,'inactive'=>13][$role] ?? 0;
    header('Content-Type: application/json'); echo json_encode(['csrf'=>ManagerHttp::csrf(true)]); session_write_close(); exit;
}

if ($path === '/__fixture_connect' || $path === '/__fixture_message') {
    require_once $root.'/services/TelegramArchiveBotRuntime.php';
    $config = (new TelegramArchiveBotConfig())->load();
    if (!$config) { http_response_code(409); exit; }
    $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $config['secret'];
    $update = $path === '/__fixture_connect'
        ? ['update_id'=>1,'business_connection'=>['id'=>'fixture-connection','user'=>['id'=>910001,'first_name'=>'Тестовый менеджер','username'=>'synthetic_manager'],'date'=>1792230000,'is_enabled'=>true]]
        : ['update_id'=>2,'business_message'=>['business_connection_id'=>'fixture-connection','message_id'=>1,'date'=>1792230001,'chat'=>['id'=>920001,'type'=>'private'],'from'=>['id'=>910001],'text'=>'SYNTHETIC ONLY']];
    header('Content-Type: application/json'); echo json_encode(TelegramArchiveBotRuntime::receive($update, $config)); exit;
}

if ($path === '/manager/telegram-archive-api.php') {
    $telegramArchiveFixtureRequest = static function(string $token, string $method, array $body) use ($state): array {
        $file = $state.'/provider.json';
        $provider = is_file($file) ? json_decode((string)file_get_contents($file), true) : ['url'=>'','allowed_updates'=>[],'has_custom_certificate'=>false,'max_connections'=>40];
        file_put_contents($state.'/calls.jsonl', json_encode(['method'=>$method])."\n", FILE_APPEND); chmod($state.'/calls.jsonl',0600);
        if ($method === 'getMe') return ['http'=>200,'ok'=>true,'result'=>['id'=>55555,'is_bot'=>true,'username'=>str_starts_with($token,'66666:') ? 'Different_bot' : 'Anytourtg_bot','can_connect_to_business'=>true]];
        if ($method === 'getWebhookInfo') return ['http'=>200,'ok'=>true,'result'=>$provider];
        if ($method === 'setWebhook') {
            $provider = ['url'=>$body['url'],'allowed_updates'=>$body['allowed_updates'],'max_connections'=>$body['max_connections'],'has_custom_certificate'=>false];
            file_put_contents($file,json_encode($provider)); chmod($file,0600);
            return ['http'=>200,'ok'=>true,'result'=>true];
        }
        return ['http'=>500,'ok'=>false];
    };
    $_SERVER['SCRIPT_NAME'] = $path; require $root.$path; exit;
}
if ($path === '/manager/telegram-archive.php' || $path === '/telegram_archive_webhook.php') {
    $_SERVER['SCRIPT_NAME'] = $path; require $root.$path; exit;
}
if (in_array($path, ['/manager/assets/manager-http-client.js','/manager/assets/telegram-archive.js','/manager/assets/telegram-archive.css'],true)) {
    header('Content-Type: '.(str_ends_with($path,'.js') ? 'application/javascript' : 'text/css')); readfile($root.$path); exit;
}
http_response_code(404);
