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
    $connection=new AnyTourPublisherBotConnection();
    if (!$connection->mayConfigure($actor)) ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $csrf=ManagerHttp::csrf(true);
    if ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>4096) ManagerHttp::respond(['ok'=>false,'error'=>'request_too_large'],413);
        if (!is_string($_POST['csrf']??null)) ManagerHttp::respond(['ok'=>false,'error'=>'csrf'],403);
        ManagerHttp::requireCsrf($_POST);
        if (($_POST['actor']??null)!==(string)$actor) ManagerHttp::respond(['ok'=>false,'error'=>'identity_changed'],409);
        if (array_diff(array_keys($_POST),['csrf','actor','version','token'])
            || !is_string($_POST['token']??null)
            || !is_string($_POST['version']??null)
            || !preg_match('/\\A(?:0|[1-9][0-9]{0,15})\\z/D',$_POST['version']))
            ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
        $version=(int)$_POST['version'];
        $token=$_POST['token'];
        unset($_POST['token'],$_REQUEST['token']);
        session_write_close();
        try { $connection->save($actor,$version,$token); }
        finally { unset($token); }
        header('Location: /manager/anytour-publisher-telegram.php',true,303);
        exit;
    }
    $status=$connection->status($actor);
} catch (Throwable $error) {
    // Do not leak provider responses or credentials into HTML, JSON or logs.
    $code=$error->getMessage();
    if ($code==='forbidden') ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $messages=[
        'identity_mismatch'=>'Этот токен принадлежит другому боту. Нужен токен @ANYTOURBOT_bot.',
        'invalid_token'=>'Проверьте формат токена из BotFather.',
        'identity_unavailable'=>'Telegram не подтвердил токен. Проверьте токен и попробуйте снова.',
        'context_changed'=>'Подключение уже обновили. Откройте страницу заново перед заменой токена.'
    ];
    $message=$messages[$code]??'Не удалось подтвердить состояние защищённого хранилища. Откройте страницу заново и проверьте последнее сохранение.';
    http_response_code($code==='context_changed'?409:(isset($messages[$code])?422:503));
}
$esc=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
$updatedAt=$status!==null && $status['updated_at']!==null
    ? (new DateTimeImmutable($status['updated_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i').' МСК'
    : null;
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Бот публикаций AnyTour</title><link rel="stylesheet" href="/manager/assets/clients.css"></head>
<body><header class="pageHead"><h1>Бот публикаций AnyTour</h1><a class="back" href="/manager2/admin.php">К настройкам</a></header>
<main><section class="card">
<h2>@ANYTOURBOT_bot</h2>
<p>Введите токен из BotFather, чтобы сохранить доступ к боту, который публиковал сообщения канала.</p>
<?php if ($message!==''): ?><p role="alert"><?=$esc($message)?></p><?php endif; ?>
<?php if ($status!==null): ?>
<p><?=$status['configured']?'Telegram подтвердил бота @ANYTOURBOT_bot при сохранении. Токен хранится в зашифрованном виде.':'Токен ещё не подключён.'?></p>
<?php if ($updatedAt!==null): ?><p>Последнее сохранение: <?=$esc($updatedAt)?> · Версия <?=$status['version']?></p><?php endif; ?>
<p>Редактирование публикаций ещё не подключено. Возможность изменить кнопку Aloft №1678 проверяется отдельно.</p>
<form method="post" action="/manager/anytour-publisher-telegram.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
<input type="hidden" name="actor" value="<?=$actor?>">
<input type="hidden" name="version" value="<?=$status['version']?>">
<label for="publisherToken"><?=$status['configured']?'Новый токен @ANYTOURBOT_bot':'Токен @ANYTOURBOT_bot из BotFather'?></label>
<input id="publisherToken" type="password" name="token" required maxlength="220" spellcheck="false" autocomplete="new-password" aria-describedby="tokenHint">
<p id="tokenHint" class="hint">Сохранённый токен не отображается. При сохранении проверяется, что он принадлежит именно этому боту.</p>
<div class="actions"><button type="submit"><?=$status['configured']?'Проверить и заменить токен':'Проверить и сохранить'?></button></div>
</form>
<p class="hint">Сохранение токена не переключает приём сообщений (webhook) и не изменяет посты. Изменение кнопки Aloft №1678 потребует отдельного подтверждения.</p>
<?php else: ?><p><a href="/manager/anytour-publisher-telegram.php">Открыть подключение заново</a></p><?php endif; ?>
</section></main></body></html>
