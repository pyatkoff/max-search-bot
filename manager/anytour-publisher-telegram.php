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
$operation=null;
$preview=null;
$publicationProblem='';
$action='view';
$service=static function (?callable $request=null): ChannelPostAutomationService {
    require_once dirname(__DIR__).'/services/ChannelPostAutomationService.php';
    return new ChannelPostAutomationService(null,new ChannelPostButtonTransport($request));
};
try {
    $connection=new AnyTourPublisherBotConnection();
    if (!$connection->mayConfigure($actor)) ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $csrf=ManagerHttp::csrf(true);
    if ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>4096) ManagerHttp::respond(['ok'=>false,'error'=>'request_too_large'],413);
        if (!is_string($_POST['csrf']??null)) ManagerHttp::respond(['ok'=>false,'error'=>'csrf'],403);
        ManagerHttp::requireCsrf($_POST);
        if (($_POST['actor']??null)!==(string)$actor) ManagerHttp::respond(['ok'=>false,'error'=>'identity_changed'],409);
        $action=$_POST['action']??'save';
        if (!is_string($action) || !in_array($action,['save','inspect','prepare','apply'],true))
            ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
        $allowed=['csrf','actor','version','action'];
        if ($action==='save') $allowed[]='token';
        if ($action==='apply') $allowed=array_merge($allowed,['publication_id','version_id','plan_digest','confirm']);
        if (array_diff(array_keys($_POST),$allowed)
            || !is_string($_POST['version']??null)
            || !preg_match('/\\A(?:0|[1-9][0-9]{0,15})\\z/D',$_POST['version'])
            || ($action==='save' && !is_string($_POST['token']??null)))
            ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
        $version=(int)$_POST['version'];
        if ($action==='save') {
            $token=$_POST['token'];
            unset($_POST['token'],$_REQUEST['token']);
            session_write_close();
            try { $connection->save($actor,$version,$token); }
            finally { unset($token); }
            header('Location: /manager/anytour-publisher-telegram.php',true,303);
            exit;
        }
        if ($action==='apply' && (($_POST['confirm']??null)!=='replace_aloft_1678_keyboard'
            || !is_string($_POST['publication_id']??null) || !preg_match('/\A[1-9][0-9]{0,15}\z/D',$_POST['publication_id'])
            || !is_string($_POST['version_id']??null) || !preg_match('/\A[1-9][0-9]{0,15}\z/D',$_POST['version_id'])
            || !is_string($_POST['plan_digest']??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$_POST['plan_digest'])))
            ManagerHttp::respond(['ok'=>false,'error'=>'exact_confirmation_required'],422);
        $publicationId=(int)($_POST['publication_id']??0);
        $versionId=(int)($_POST['version_id']??0);
        $planDigest=(string)($_POST['plan_digest']??'');
        session_write_close();
        $operation=$connection->withPublisher($actor,$version,
            static function (array $publisher,callable $request) use ($connection,$actor,$service,$action,$publicationId,$versionId,$planDigest): array {
                $worker=$service($request);
                if ($action==='inspect') return $worker->inspectManualTelegramButton($publisher);
                if ($action==='prepare') return $worker->prepareManualTelegramButton($publisher,
                    static fn():bool=>$connection->mayConfigure($actor,true));
                return $worker->applyManualTelegramButton($publisher,$publicationId,$versionId,$planDigest,
                    static fn():bool=>$connection->mayConfigure($actor,true));
            },$action==='apply');
        // Keep the fresh read/prepare receipt visible with the exact approval
        // form. A repeated confirmed POST cannot claim a second write.
        if (empty($operation['ok'])) http_response_code(($operation['state']??'')==='unknown'?503:409);
    }
    $status=$connection->status($actor);
    if ($status['configured']) {
        try { $preview=$operation['preview']??$service()->manualTelegramButtonStatus(); }
        catch (Throwable $ignored) { $publicationProblem='Не удалось прочитать состояние публикации. Подготовка и изменение недоступны до проверки реестра.'; }
    }
} catch (Throwable $error) {
    // Do not leak provider responses or credentials into HTML, JSON or logs.
    $code=$error->getMessage();
    if ($code==='forbidden') ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $messages=[
        'identity_mismatch'=>'Этот токен принадлежит другому боту. Нужен токен @ANYTOURBOT_bot.',
        'invalid_token'=>'Проверьте формат токена из BotFather.',
        'identity_unavailable'=>'Telegram не подтвердил токен. Проверьте токен и попробуйте снова.',
        'context_changed'=>'Подключение уже обновили. Откройте страницу заново перед продолжением.',
        'publisher_not_configured'=>'Сначала сохраните токен @ANYTOURBOT_bot.',
        'publisher_operation_forbidden'=>'Операция не соответствует подготовленной правке №1678.'
    ];
    $message=$action==='apply'
        ? 'Не удалось завершить проверку результата. Не повторяйте изменение: сначала откройте страницу заново и проверьте сохранённую квитанцию попытки.'
        : ($messages[$code]??'Не удалось подтвердить состояние защищённого хранилища. Откройте страницу заново и проверьте последнее сохранение.');
    http_response_code($code==='context_changed'?409:(isset($messages[$code])?422:503));
}
$esc=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
$updatedAt=$status!==null && $status['updated_at']!==null
    ? (new DateTimeImmutable($status['updated_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i').' МСК'
    : null;
$reasons=[
    'publisher_channel_rights_verified'=>'Telegram подтвердил бота и право редактирования сообщений канала. Возможность изменить именно №1678 проверяется только отдельной подтверждённой попыткой.',
    'manual_approval_required'=>'Подготовлена одна гостиничная кнопка. Сообщение Telegram ещё не изменялось.',
    'verified'=>'Telegram подтвердил изменение кнопки.',
    'manual_preview_changed'=>'Подготовленный вариант изменился. Операция остановлена; требуется новая сверка.',
    'public_post_changed'=>'Пост изменился после подготовки. Операция остановлена; требуется новая сверка.',
    'channel_edit_permission_required'=>'Telegram не подтвердил у нового бота право редактировать сообщения этого канала.',
    'provider_read_failed'=>'Не удалось получить ответ Telegram на проверку. Её результат не подтверждён.',
    'provider_rejected_edit'=>'Telegram отклонил изменение. Причина приведена ниже; повторная попытка не отправляется.',
    'manual_observation_changed'=>'Получена новая версия поста. Квитанция прежней попытки сохранена отдельно; новая версия требует сверки.',
    'edit_outcome_unknown'=>'Ответ на изменение не подтверждён. Повторная отправка заблокирована до сверки.',
    'manual_edit_verification_failed'=>'Telegram вернул сообщение после изменения, но локальная сверка не подтвердила все поля. Сохранённая квитанция доступна ниже; повторная отправка заблокирована.',
    'manual_write_already_attempted'=>'Для этой публикации уже зарегистрирована попытка. Автоматического повтора не будет.'
];
$nativeReasons=[
    'message_not_editable'=>'Это сообщение нельзя редактировать этим ботом.',
    'message_not_found'=>'Сообщение недоступно для редактирования подключённому боту.',
    'message_id_invalid'=>'Telegram не принял идентификатор сообщения для редактирования.',
    'insufficient_edit_rights'=>'Недостаточно прав для изменения сообщения.',
    'bot_not_in_channel'=>'Бот не состоит в канале.',
    'message_not_modified'=>'Telegram сообщил, что содержимое клавиатуры не изменилось.'
];
$operationReason=is_array($operation)?(string)($operation['reason']??''):'';
$proofFlag=static fn($value):string=>$value===true?'Да':($value===false?'Нет':'Нет данных');
$receiptLabels=[
    'verified'=>'Итоговая нативная сверка пройдена',
    'button_verified'=>'Сообщение и точная кнопка совпали',
    'public_caption_matched'=>'Подпись совпала с исходным публичным снимком',
    'publication_date_matched'=>'Исходная дата совпала точно',
    'media_type_duration_matched'=>'Тип, длительность и поля видео прошли проверку',
    'body_unchanged_by_method'=>'Метод изменял только клавиатуру',
    'native_media_identity_before_verified'=>'Сравнен исходный нативный идентификатор видео'
];
$canApply=$status!==null && $status['configured'] && is_array($preview)
    && !empty($preview['manual_button_repair']) && !empty($preview['plan_matches'])
    && $preview['state']==='requires_attention' && $preview['reason']==='manual_approval_required'
    && $preview['attempt_count']===0 && $preview['write_attempts']===0
    && ($preview['publisher']['username']??null)==='ANYTOURBOT_bot'
    && (int)($preview['publisher']['credential_version']??0)===(int)$status['version']
    && (string)($preview['publisher']['bot_id']??'')===(string)($status['bot_id']??'');
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Бот публикаций AnyTour</title><link rel="stylesheet" href="/manager/assets/clients.css"></head>
<body><header class="pageHead"><h1>Бот публикаций AnyTour</h1><a class="back" href="/manager2/admin.php">К настройкам</a></header>
<main class="columns"><section class="card">
<h2>@ANYTOURBOT_bot</h2>
<p>Введите токен из BotFather, чтобы сохранить доступ к боту, который публиковал сообщения канала.</p>
<?php if ($message!==''): ?><p role="alert"><?=$esc($message)?></p><?php endif; ?>
<?php if ($status!==null): ?>
<p><?=$status['configured']?'Telegram подтвердил бота @ANYTOURBOT_bot при сохранении. Токен хранится в зашифрованном виде.':'Токен ещё не подключён.'?></p>
<?php if ($updatedAt!==null): ?><p>Последнее сохранение: <?=$esc($updatedAt)?> · Версия <?=$status['version']?></p><?php endif; ?>
<p>Сохранённый доступ используется для проверки и отдельного изменения кнопки Aloft №1678.</p>
<?php if ($status['configured']): ?><details><summary>Заменить сохранённый токен</summary><?php endif; ?>
<form method="post" action="/manager/anytour-publisher-telegram.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
<input type="hidden" name="actor" value="<?=$actor?>">
<input type="hidden" name="version" value="<?=$status['version']?>">
<input type="hidden" name="action" value="save">
<label for="publisherToken"><?=$status['configured']?'Новый токен @ANYTOURBOT_bot':'Токен @ANYTOURBOT_bot из BotFather'?></label>
<input id="publisherToken" type="password" name="token" required maxlength="220" spellcheck="false" autocomplete="new-password" aria-describedby="tokenHint">
<p id="tokenHint" class="hint">Сохранённый токен не отображается. При сохранении проверяется, что он принадлежит именно этому боту.</p>
<div class="actions"><button type="submit"><?=$status['configured']?'Проверить и заменить токен':'Проверить и сохранить'?></button></div>
</form>
<?php if ($status['configured']): ?></details><?php endif; ?>
<p class="hint">Сохранение токена не переключает приём сообщений (webhook) и не изменяет посты. Изменение кнопки Aloft №1678 потребует отдельного подтверждения.</p>
<?php else: ?><p><a href="/manager/anytour-publisher-telegram.php">Открыть подключение заново</a></p><?php endif; ?>
</section>
<?php if ($status!==null && $status['configured']): ?>
<section class="card" aria-labelledby="aloftTitle">
<h2 id="aloftTitle">Кнопка Aloft №1678</h2>
<p><a href="https://t.me/anytour/1678" target="_blank" rel="noopener noreferrer">Открыть исходный пост</a></p>
<p>Издатель: <strong>@ANYTOURBOT_bot</strong>. Гостиничная кнопка ведёт в <strong>@anyru_bot</strong>, который принимает контекст Aloft Palm Jumeirah.</p>
<?php if ($publicationProblem!==''): ?><p role="alert"><?=$esc($publicationProblem)?></p><?php endif; ?>
<?php if ($operation!==null): ?>
<p role="status"><?=$esc($reasons[$operationReason]??(empty($operation['ok'])?'Операция остановлена. Проверьте причину ниже.':'Проверка выполнена.'))?></p>
<?php if (!isset($reasons[$operationReason])): ?><p>Причина: <code><?=$esc($operationReason)?></code></p><?php endif; ?>
<?php if (isset($operation['receipt']['write_attempts'])): ?><p>Попыток записи в этой операции: <?=(int)$operation['receipt']['write_attempts']?>.</p><?php endif; ?>
<?php if (isset($operation['receipt']['telegram_error_reason'])): $nativeReason=(string)$operation['receipt']['telegram_error_reason']; ?><p>Ответ Telegram: <?=$esc($nativeReasons[$nativeReason]??$nativeReason)?><?php if (isset($operation['receipt']['telegram_error_code'])): ?> · код <?=(int)$operation['receipt']['telegram_error_code']?><?php endif; ?></p><?php endif; ?>
<?php endif; ?>
<form method="post" action="/manager/anytour-publisher-telegram.php">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="actor" value="<?=$actor?>"><input type="hidden" name="version" value="<?=$status['version']?>">
<div class="actions"><button type="submit" name="action" value="inspect">Проверить права и пост</button>
<button type="submit" name="action" value="prepare">Подготовить кнопку без изменения поста</button></div>
</form>
<?php if (is_array($preview)): ?>
<p>Публикация в реестре: <?=(int)$preview['publication_id']?>, версия <?=(int)$preview['version_id']?>. Состояние: <code><?=$esc($preview['state'])?></code>. Попыток изменения этой версии: <?=(int)$preview['write_attempts']?>.</p>
<?php if (is_array($preview['original_attempt']) && (int)$preview['original_attempt']['version_id']!==(int)$preview['version_id']): $original=$preview['original_attempt']; ?>
<p>Исходная попытка относилась к версии <?=(int)$original['version_id']?>. Её состояние: <code><?=$esc((string)$original['state'])?></code>; попыток записи: <?=(int)$original['write_attempts']?>. Эта квитанция не подтверждает более поздние изменения поста.</p>
<?php endif; ?>
<?php if (!empty($preview['manual_button_repair'])): ?>
<?php
$savedAttempt=is_array($preview['original_attempt']??null)?$preview['original_attempt']:null;
$savedReceipt=$savedAttempt!==null && is_array($savedAttempt['receipt']??null)?$savedAttempt['receipt']:[];
?>
<?php if ($savedAttempt!==null): ?>
<details><summary>Сохранённая квитанция исходной попытки</summary>
<p>Версия <?=(int)$savedAttempt['version_id']?>. Состояние: <code><?=$esc((string)$savedAttempt['state'])?></code>. Попыток записи: <?=(int)$savedAttempt['write_attempts']?>.</p>
<?php if (is_string($savedAttempt['error_reason']??null) && $savedAttempt['error_reason']!==''): ?><p class="hint">Причина: <code><?=$esc($savedAttempt['error_reason'])?></code>.</p><?php endif; ?>
<dl class="wizardReview">
<?php foreach ($receiptLabels as $key=>$label): ?><div><dt><?=$esc($label)?></dt><dd><?=$proofFlag($savedReceipt[$key]??null)?></dd></div><?php endforeach; ?>
</dl>
<p class="hint">Это сохранённые результаты исходной попытки. Они не заменяют проверку более поздней версии. Отсутствие исходного нативного идентификатора видео само по себе не означает, что видео изменилось.</p>
<?php if (is_string($savedReceipt['provider_result_sha256']??null) && preg_match('/\A[a-f0-9]{64}\z/D',$savedReceipt['provider_result_sha256'])): ?><p class="hint">SHA256 полученного сообщения: <code><?=$esc($savedReceipt['provider_result_sha256'])?></code></p><?php endif; ?>
<?php foreach (array_slice(is_array($savedReceipt['http_results']??null)?$savedReceipt['http_results']:[],0,5) as $httpResult):
    if (!is_array($httpResult) || !in_array($httpResult['operation']??null,['bot_identity','channel_rights','channel_type','append_button'],true)
        || !is_int($httpResult['http']??null) || $httpResult['http']<0 || $httpResult['http']>599
        || !is_int($httpResult['transport_error']??null) || $httpResult['transport_error']<0 || $httpResult['transport_error']>1000) continue;
?><p class="hint">Этап <code><?=$esc($httpResult['operation'])?></code>: HTTP <?=(int)$httpResult['http']?>, код транспортной ошибки <?=(int)$httpResult['transport_error']?>.</p><?php endforeach; ?>
</details>
<?php endif; ?>
<h3>Точное изменение</h3>
<p>Заменить все кнопки под сообщением №1678 одной кнопкой:</p>
<p><strong><?=$esc($preview['button_text'])?></strong></p>
<p class="hint"><a href="<?=$esc($preview['button_url'])?>" target="_blank" rel="noopener noreferrer"><?=$esc($preview['button_url'])?></a></p>
<?php if ($preview['hotel_id']===61159): ?><p>Контекст: Aloft Palm Jumeirah, отель №61159. Источник: <?=$esc($preview['source_key'])?>.</p>
<?php else: ?><p>Гостиничный контекст текущей версии требует повторной сверки.</p><?php endif; ?>
<?php if ($preview['observation']['observation_kind']==='public_read'): ?><p>Адрес и дата публикации, видео 0:28 с обложкой и подпись сохраняются. Отправляется только изменение кнопок; новая публикация не создаётся.</p>
<?php else: ?><p>Получено нативное обновление сообщения. Перед продолжением нужно сверить его с исходной подготовкой; новый вариант не подтверждён.</p><?php endif; ?>
<?php $nativeDiagnostic=$preview['observation']['native_diagnostics']??null; if (is_array($nativeDiagnostic)): ?>
<details><summary>Диагностика текущей нативной версии</summary>
<p>Версия <?=(int)$preview['version_id']?>. Здесь показаны поля уже сохранённого обновления Telegram. Это отдельное наблюдение, а не квитанция исходной попытки.</p>
<dl class="wizardReview">
<?php foreach (['message_id'=>'Номер сообщения','chat_id'=>'Идентификатор канала','chat_type'=>'Тип чата','chat_username'=>'Публичное имя канала','date'=>'Исходная дата Unix','video_duration'=>'Длительность видео, секунд'] as $key=>$label): $field=is_array($nativeDiagnostic[$key]??null)?$nativeDiagnostic[$key]:[]; ?>
<div><dt><?=$esc($label)?></dt><dd><?=$esc(is_scalar($field['value']??null)?(string)$field['value']:'Нет значения')?> · тип <?=$esc((string)($field['type']??'missing'))?> · присутствует: <?=$proofFlag($field['present']??null)?></dd></div>
<?php endforeach; ?>
<?php $captionDiagnostic=is_array($nativeDiagnostic['caption']??null)?$nativeDiagnostic['caption']:[]; ?>
<div><dt>Подпись</dt><dd>Присутствует: <?=$proofFlag($captionDiagnostic['present']??null)?>; тип <?=$esc((string)($captionDiagnostic['type']??'missing'))?>; байт: <?=is_int($captionDiagnostic['byte_length']??null)?(int)$captionDiagnostic['byte_length']:'Нет данных'?></dd></div>
<div><dt>Отдельное поле text присутствует</dt><dd><?=$proofFlag($nativeDiagnostic['text_key_present']??null)?></dd></div>
<div><dt>Поле video</dt><dd>Присутствует: <?=$proofFlag($nativeDiagnostic['video_present']??null)?>; тип <?=$esc((string)($nativeDiagnostic['video_type']??'missing'))?></dd></div>
<?php foreach (['video_file_id'=>'Идентификатор файла видео','video_file_unique_id'=>'Уникальный идентификатор видео'] as $key=>$label): $field=is_array($nativeDiagnostic[$key]??null)?$nativeDiagnostic[$key]:[]; ?>
<div><dt><?=$esc($label)?></dt><dd>Присутствует: <?=$proofFlag($field['present']??null)?>; тип <?=$esc((string)($field['type']??'missing'))?>; байт: <?=is_int($field['byte_length']??null)?(int)$field['byte_length']:'Нет данных'?>; формат допустим: <?=$proofFlag($field['valid']??null)?></dd></div>
<?php endforeach; ?>
<div><dt>Дополнительные поля медиа</dt><dd><?=$esc(implode(', ',is_array($nativeDiagnostic['alternative_media_keys']??null)?$nativeDiagnostic['alternative_media_keys']:[])?:'Нет')?></dd></div>
<?php $keyboardDiagnostic=is_array($nativeDiagnostic['keyboard']??null)?$nativeDiagnostic['keyboard']:[]; ?>
<div><dt>Строки клавиатуры</dt><dd>Присутствует: <?=$proofFlag($keyboardDiagnostic['present']??null)?>; тип <?=$esc((string)($keyboardDiagnostic['type']??'missing'))?>; строк: <?=is_int($keyboardDiagnostic['row_count']??null)?(int)$keyboardDiagnostic['row_count']:'Нет данных'?>; список: <?=$proofFlag($keyboardDiagnostic['rows_are_list']??null)?></dd></div>
<div><dt>Единственная строка</dt><dd>Кнопок: <?=is_int($keyboardDiagnostic['single_row_button_count']??null)?(int)$keyboardDiagnostic['single_row_button_count']:'Нет данных'?>; список: <?=$proofFlag($keyboardDiagnostic['single_row_is_list']??null)?></dd></div>
<div><dt>Поля единственной кнопки</dt><dd><?=$esc(implode(', ',is_array($keyboardDiagnostic['only_button_keys']??null)?$keyboardDiagnostic['only_button_keys']:[])?:'Нет данных')?>; всего: <?=is_int($keyboardDiagnostic['only_button_key_count']??null)?(int)$keyboardDiagnostic['only_button_key_count']:'Нет данных'?>; иных полей: <?=is_int($keyboardDiagnostic['other_button_key_count']??null)?(int)$keyboardDiagnostic['other_button_key_count']:'Нет данных'?></dd></div>
</dl>
<?php if (is_string($captionDiagnostic['sha256']??null) && preg_match('/\A[a-f0-9]{64}\z/D',$captionDiagnostic['sha256'])): ?><p class="hint">SHA256 подписи: <code><?=$esc($captionDiagnostic['sha256'])?></code></p><?php endif; ?>
<p class="hint">Значения идентификаторов файлов и содержимое callback-полей не выводятся. Просмотр не изменяет сообщение, версии и результаты сверки.</p>
</details>
<?php endif; ?>
<details><summary>Исходная подпись и кнопки</summary>
<p><?=$esc($preview['observation']['published_at_moscow'])?></p>
<p><?=nl2br($esc($preview['observation']['caption']))?></p>
<?php foreach ($preview['observation']['keyboard'] as $row): if (!is_array($row)) continue; foreach ($row as $button): if (!is_array($button)) continue; ?>
<p><?=$esc((string)($button['text']??''))?> — <?=$esc((string)($button['url']??''))?></p>
<?php endforeach; endforeach; ?>
</details>
<?php if (!empty($preview['receipt']['verified'])): ?><p class="hint">Сохранена нативная квитанция этой версии: проверены одна кнопка, подпись, исходная дата и видео 0:28. Исходный идентификатор видео до правки не был доступен для сравнения.</p>
<?php else: ?><p class="hint">Подготовка основана на публичном просмотре поста. Возможность изменить №1678 подтверждается только успешной нативной квитанцией отдельной попытки.</p><?php endif; ?>
<?php if ($canApply): ?>
<form method="post" action="/manager/anytour-publisher-telegram.php">
<input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="actor" value="<?=$actor?>"><input type="hidden" name="version" value="<?=$status['version']?>">
<input type="hidden" name="action" value="apply"><input type="hidden" name="publication_id" value="<?=(int)$preview['publication_id']?>"><input type="hidden" name="version_id" value="<?=(int)$preview['version_id']?>">
<input type="hidden" name="plan_digest" value="<?=$esc($preview['plan_digest'])?>">
<p>Нажатие следующей кнопки подтверждает замену именно в №1678 на показанную кнопку и ссылку.</p>
<div class="actions"><button type="submit" name="confirm" value="replace_aloft_1678_keyboard">Подтвердить и заменить кнопки в №1678</button></div>
</form>
<?php else: ?><p>Подтверждение этого варианта недоступно. Сначала требуется сверка текущего состояния.</p><?php endif; ?>
<?php else: ?><p>Найденная запись требует сверки. Подготовленный вариант не создан.</p><?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>
</main></body></html>
