<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/AppleService39TelegramConnection.php';

ManagerHttp::start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
try { ProjectAccessService::initializeReadOnly(); }
catch (Throwable $unavailable) { ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503); }
$manager = ManagerHttp::requireManager();
ManagerHttp::requireAdmin($manager);
$actor = (int)$manager['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST');
    ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
}
try {
    $connection = AppleService39TelegramConnection::current();
    if (!$connection->mayConfigure($actor)) ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $csrf = ManagerHttp::csrf(true);
    $message = '';
    $posts = [];
    if ($method === 'POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) ManagerHttp::respond(['ok'=>false,'error'=>'request_too_large'],413);
        if (!is_string($_POST['csrf'] ?? null)) ManagerHttp::respond(['ok'=>false,'error'=>'csrf'],403);
        ManagerHttp::requireCsrf($_POST);
        if (($_POST['actor'] ?? null) !== (string)$actor) ManagerHttp::respond(['ok'=>false,'error'=>'identity_changed'],409);
        if (($_POST['action'] ?? null) === 'publish') {
            if (array_diff(array_keys($_POST), ['csrf','actor','action'])) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
            session_write_close();
            $connection->requireReadyForPublication($actor);
            require_once dirname(__DIR__).'/services/AppleService39TelegramDevicePublisher.php';
            (new AppleService39TelegramDevicePublisher())->publish($actor);
            header('Location: /manager/appleservice39-telegram.php',true,303);
            exit;
        }
        if (($_POST['action'] ?? null) === 'activate') {
            if (array_diff(array_keys($_POST), ['action','csrf','actor','version'])
                || !is_string($_POST['version'] ?? null) || !preg_match('/\A[1-9][0-9]{0,15}\z/D',$_POST['version']))
                ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
            session_write_close();
            $connection->activate($actor,(int)$_POST['version']);
            header('Location: /manager/appleservice39-telegram.php',true,303);
            exit;
        }
        if (($_POST['action'] ?? null) !== 'save'
            || array_diff(array_keys($_POST), ['action','csrf','actor','version','token'])
            || !is_string($_POST['token'] ?? null) || !is_string($_POST['version'] ?? null)
            || !preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $_POST['version'])) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
        $version = (int)$_POST['version'];
        $token = $_POST['token'];
        unset($_POST['token']);
        session_write_close();
        $connection->save($actor,$version,$token);
        unset($token);
        header('Location: /manager/appleservice39-telegram.php',true,303);
        exit;
    }
    $status = $connection->status($actor);
    if ($status['webhook_state'] === 'verified') {
        require_once dirname(__DIR__).'/services/AppleService39TelegramDevicePublisher.php';
        $posts = (new AppleService39TelegramDevicePublisher())->status();
    }
} catch (Throwable $error) {
    // No exception or provider body is rendered or logged on this credential path.
    $code = $error->getMessage();
    if ($code === 'forbidden') ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $messages = [
        'identity_mismatch'=>'Этот токен принадлежит другому боту.',
        'invalid_token'=>'Проверьте формат токена из BotFather.',
        'identity_unavailable'=>'Telegram не подтвердил токен. Проверьте токен и попробуйте снова.',
        'context_changed'=>'Подключение уже обновили. Откройте страницу заново.',
        'connection_required'=>'Сначала подключите приём личных сообщений Telegram.',
        'webhook_busy'=>'Предыдущий запрос ещё выполняется. Откройте страницу через две минуты.',
        'webhook_conflict'=>'У этого бота уже настроен другой адрес или собственный сертификат webhook. Регистрация сохранена. Для переноса согласуйте переключение с владельцем действующего подключения.',
        'webhook_unknown'=>'Telegram не подтвердил результат. Проверьте состояние, прежде чем повторно подключать бота.',
        'webhook_unavailable'=>'Не удалось сверить адрес webhook с Telegram. Откройте подключение и проверьте его ещё раз.',
        'source_unavailable'=>'Источник AppleService39 недоступен. Обратитесь к администратору.'
    ];
    $message = $messages[$code] ?? 'Не удалось подтвердить состояние подключения. Откройте страницу заново.';
    $status = null;
    http_response_code(in_array($code,['context_changed','connection_required','webhook_busy','webhook_conflict'],true) ? 409 : (isset($messages[$code]) ? 422 : 503));
}
$esc = static fn(string $value): string => htmlspecialchars($value,ENT_QUOTES,'UTF-8');
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Telegram AppleService39</title><link rel="stylesheet" href="/manager/assets/clients.css"></head>
<body><header class="pageHead"><h1>Telegram AppleService39</h1><a class="back" href="/manager2/admin.php">К настройкам</a></header>
<main><section class="card">
<?php if ($message !== ''): ?><p role="status"><?=$esc($message)?></p><?php endif; ?>
<?php if ($status !== null): ?>
<h2>@Appleservice39_bot</h2>
<p><?=$status['configured'] ? 'Токен бота сохранён. Здесь можно заменить его.' : 'Введите токен бота, полученный в BotFather.'?></p>
<form method="post" action="/manager/appleservice39-telegram.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="actor" value="<?=$actor?>">
<input type="hidden" name="action" value="save"><input type="hidden" name="version" value="<?=$status['version']?>">
<label>Новый токен Telegram<input type="password" name="token" required maxlength="220" spellcheck="false" autocomplete="new-password"></label>
<div class="actions"><button type="submit">Проверить и сохранить</button></div></form>
<?php if ($status['configured']): ?>
<h2>Личные обращения в Manager2</h2>
<?php
$hookStates = ['not_set'=>'Приём обращений ещё не подключён.','pending'=>'Telegram обрабатывает запрос.',
    'unknown'=>'Результат последнего подключения неизвестен. Можно повторить подключение.',
    'accepted'=>'Telegram принял настройки. Требуется проверить адрес.',
    'verified'=>'Telegram подтвердил адрес для доставки личных сообщений.'];
?><p><?=$esc($hookStates[$status['webhook_state']] ?? 'Требуется проверить подключение.')?></p>
<form method="post" action="/manager/appleservice39-telegram.php">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="actor" value="<?=$actor?>">
<input type="hidden" name="version" value="<?=$status['version']?>"><input type="hidden" name="action" value="activate">
<button type="submit"><?=$status['webhook_state']==='verified' ? 'Проверить подключение' : 'Подключить приём сообщений'?></button></form>
<?php if ($status['webhook_state']==='verified'): ?>
<p><?=$status['received'] ? 'Личное обращение сохранено в Manager2, бот отправил ответ.' : 'Для сквозной проверки откройте бота и нажмите «Старт». Затем обновите эту страницу.'?>
<a href="https://t.me/Appleservice39_bot">Открыть @Appleservice39_bot</a></p>
<h2>Публикации канала @appleservice39</h2>
<?php
$labels = ['device-iphone-18'=>'iPhone 18','device-iphone18'=>'iPhone 18','device-ipad'=>'iPad','device-macbook'=>'MacBook','device-apple-watch'=>'Apple Watch'];
$states = ['published'=>'Опубликовано','pending'=>'Ожидает публикации','unknown'=>'Нужно проверить публикацию','rejected'=>'Публикация отклонена'];
?>
<ul>
<?php foreach ($posts as $key=>$post):
    if (!is_array($post) || !isset($states[$post['state'] ?? ''])) continue;
    $url = is_string($post['url'] ?? null) && preg_match('~\Ahttps://t\.me/appleservice39/[1-9][0-9]*\z~iD',$post['url']) ? $post['url'] : null; ?>
<li><?=$esc($labels[$key] ?? (string)$key)?> — <?php if ($post['state']==='published' && $url): ?><a href="<?=$esc($url)?>">Открыть пост</a><?php else: ?><?=$esc($states[$post['state']])?><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<?php if (count(array_filter($posts,static fn($post)=>is_array($post) && ($post['state']??'')==='published'))<4): ?>
<form method="post" action="/manager/appleservice39-telegram.php">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="actor" value="<?=$actor?>">
<input type="hidden" name="action" value="publish"><button type="submit">Опубликовать 4 поста в Telegram</button>
</form>
<?php endif; endif; endif; ?>
<?php else: ?><a href="/manager/appleservice39-telegram.php">Открыть подключение заново</a><?php endif; ?>
</section></main></body></html>
