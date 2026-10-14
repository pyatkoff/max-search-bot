<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
ManagerHttp::start();
if (ManagerHttp::managerId() <= 0) ManagerHttp::requireManager();
try {
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager();
    ManagerHttp::requireAdmin($manager);
    $csrf = ManagerHttp::csrf(true);
} catch (Throwable $e) { ManagerHttp::respond(['ok'=>false,'error'=>'post_channels_unavailable'],503); }
header('Cache-Control: no-store');
$css = (string)(@filemtime(__DIR__.'/assets/post-channels.css') ?: 1);
$js = (string)(@filemtime(__DIR__.'/assets/post-channels.js') ?: 1);
$httpJs = (string)(@filemtime(__DIR__.'/assets/manager-http-client.js') ?: 1);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AnyTour — публикации и каналы</title><link rel="stylesheet" href="assets/post-channels.css?v=<?=htmlspecialchars($css,ENT_QUOTES,'UTF-8')?>"></head>
<body><div class="pc-app" id="postChannelsApp" data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>">
<header class="pc-header"><a class="pc-brand" href="/manager2/">AnyTour <span>Кабинет команды</span></a><a class="pc-back" href="/manager2/">← Диалоги</a></header>
<main><div class="pc-heading"><div><p class="pc-eyebrow">Публикации</p><h1>Клиент пришёл из поста</h1><p>Подключите канал один раз. Новые посты получают свою кнопку, а менеджер видит исходное предложение в диалоге.</p></div><button id="pcRefresh" type="button" class="pc-secondary">Обновить</button></div>
<div id="pcLoadStatus" class="pc-notice" role="status" aria-live="polite">Загружаем подключённые каналы…</div>
<div class="pc-columns"><section class="pc-card"><div class="pc-card-heading"><h2>Подключённые каналы</h2><button id="pcNew" class="pc-secondary" type="button" disabled>Добавить</button></div><div id="pcChannels" class="pc-channel-list" aria-live="polite"><p class="pc-muted">Загружаем список…</p></div></section>
<section class="pc-card pc-editor"><div class="pc-card-heading"><div><p class="pc-eyebrow">Разовая настройка</p><h2 id="pcFormTitle">Новый канал</h2></div></div><form id="pcForm"><fieldset id="pcFields" disabled>
<div class="pc-grid"><label>Проект<select id="pcProject" required></select></label><label>Площадка<select id="pcPlatform" required><option value="max">MAX</option><option value="telegram">Telegram</option></select></label></div>
<label>Название канала<input id="pcName" maxlength="191" autocomplete="off" placeholder="Например, AnyTour Москва" required></label>
<label>ID канала<input id="pcChannelId" inputmode="text" autocomplete="off" placeholder="Например, -80095447388179" required><small>MAX принимает ID со знаком минус и без него. Telegram — отрицательный ID канала.</small></label>
<label>Источник обращения<select id="pcSource" required></select><small>Выберите существующий источник этого канала в режиме «Менеджер» или «Уточнить запрос».</small></label>
<div class="pc-source-meta" id="pcSourceMeta"></div>
<label>Надпись на кнопке<input id="pcButton" maxlength="80" value="Рассчитать на мои даты" required></label>
<div class="pc-bot"><span>Бот для обращения</span><a id="pcBot" href="#" target="_blank" rel="noopener noreferrer"></a><small>Используется настроенный бот проекта. Ссылка формируется автоматически.</small></div>
<label class="pc-check"><input id="pcEnabled" type="checkbox"><span><strong>Добавлять кнопки автоматически</strong><small>При включении проверим доступ бота к каналу и право редактирования. Сначала добавьте бота администратором.</small></span></label>
<div class="pc-actions"><button id="pcSave" type="submit">Сохранить канал</button><button id="pcCancel" type="button" class="pc-secondary">Отмена</button></div>
</fieldset></form><div id="pcSaveStatus" class="pc-form-status" role="status" aria-live="polite"></div>
</section></div>
<aside class="pc-explainer"><strong>СММ публикует как обычно</strong><p>Обрабатываются новые публикации после включения канала. История старых постов автоматически не загружается. В первом проходе поддерживаются текст и одиночные фотографии; альбомы и другие вложения могут требовать внимания.</p><p>Статус «Ждём первый пост» означает, что канал включён. «Требует внимания» означает, что результат обработки нужно проверить; повторное редактирование при неопределённом результате автоматически не запускается.</p></aside>
</main></div><script src="assets/manager-http-client.js?v=<?=htmlspecialchars($httpJs,ENT_QUOTES,'UTF-8')?>"></script><script src="assets/post-channels.js?v=<?=htmlspecialchars($js,ENT_QUOTES,'UTF-8')?>" defer></script></body></html>
