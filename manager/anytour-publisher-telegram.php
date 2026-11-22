<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/AnyTourPublisherBotConnection.php';

ManagerHttp::start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
try { ProjectAccessService::initializeReadOnly(); }
catch (Throwable $ignored) { ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503); }
$manager=ManagerHttp::requireManager();
ManagerHttp::requireAdmin($manager);
$actor=(int)$manager['id'];
$method=$_SERVER['REQUEST_METHOD']??'';
if (!in_array($method,['GET','POST'],true)) {
    header('Allow: GET, POST');
    ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$message='';
$status=null;
try {
    if (!ProjectAccessService::canAccess($actor,'anytour')) ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $connection=new AnyTourPublisherBotConnection();
    $csrf=ManagerHttp::csrf(true);
    if ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>4096) ManagerHttp::respond(['ok'=>false,'error'=>'request_too_large'],413);
        ManagerHttp::requireCsrf($_POST);
        if (array_diff(array_keys($_POST),['csrf','actor','version','token'])
            || ($_POST['actor']??null)!==(string)$actor
            || !is_string($_POST['token']??null)
            || !is_string($_POST['version']??null)
            || !preg_match('/\\A(?:0|[1-9][0-9]{0,15})\\z/D',$_POST['version']))
            ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
        $token=$_POST['token'];
        unset($_POST['token']);
        session_write_close();
        $connection->save((int)$_POST['version'],$token);
        unset($token);
        header('Location: /manager/anytour-publisher-telegram.php',true,303);
        exit;
    }
    $status=$connection->status();
} catch (Throwable $ignored) {
    // Do not leak provider responses or credentials into HTML, JSON or logs.
    $message='Не удалось подтвердить или сохранить подключение. Проверьте токен и обновите страницу.';
    http_response_code(422);
}
$esc=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AnyTour — бот публикаций Telegram</title><link rel="stylesheet" href="/manager/assets/clients.css"></head>
<body><header class="pageHead"><h1>AnyTour — бот публикаций Telegram</h1><a class="back" href="/manager2/admin.php">К настройкам</a></header>
<main><section class="card">
<h2>@ANYTOURBOT_bot</h2>
<p>Только безопасное сохранение доступа. Webhook, публикации и действующие подключения не меняются.</p>
<?php if ($message!==''): ?><p role="alert"><?=$esc($message)?></p><?php endif; ?>
<?php if ($status!==null): ?>
<p><?=$status['configured']?'Токен сохранён в зашифрованном хранилище.':'Токен ещё не подключён.'?></p>
<?php if ($status['updated_at']): ?><p>Последнее обновление: <?=$esc((string)$status['updated_at'])?></p><?php endif; ?>
<form method="post" action="/manager/anytour-publisher-telegram.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
<input type="hidden" name="actor" value="<?=$actor?>">
<input type="hidden" name="version" value="<?=$status['version']?>">
<label>Токен @ANYTOURBOT_bot из BotFather
<input type="password" name="token" required maxlength="220" spellcheck="false" autocomplete="new-password"></label>
<div class="actions"><button type="submit">Проверить и сохранить</button></div>
</form>
<p>Изменение кнопок Aloft №1678 потребует отдельного подтверждения.</p>
<?php else: ?><p>Подключение недоступно. Обновите страницу.</p><?php endif; ?>
</section></main></body></html>
