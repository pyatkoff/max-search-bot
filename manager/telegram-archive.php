<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/TelegramArchiveBotConfig.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
ManagerHttp::start();
$authorized = false; $csrf = '';
try {
    ProjectAccessService::initializeReadOnly();
    $actor = ManagerHttp::manager();
    $authorized = $actor && ManagerHttp::isAdmin($actor) && ProjectAccessService::canAccess((int)$actor['id'], ProjectConfig::projectId());
    if ($authorized) $csrf = ManagerHttp::csrf(true);
} catch (Throwable $error) { http_response_code(503); }
session_write_close();
$scriptVersion = (string)(@filemtime(__DIR__.'/assets/telegram-archive.js') ?: 1);
function telegramArchiveHtml(string $value): string { return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram-диалоги · AnyTour</title><link rel="stylesheet" href="assets/telegram-archive.css"></head>
<body data-csrf="<?=telegramArchiveHtml($csrf)?>">
<main class="archiveSetup">
<header class="pageHead"><a class="back" href="/manager2/">← Кабинет менеджера</a><p class="eyebrow">AnyTour · рабочие переписки</p><h1>Telegram-диалоги</h1><p class="intro">Подключите рабочего бота, затем выберите рабочие аккаунты для сбора переписок.</p></header>
<?php if (!$authorized): ?>
<section class="panel"><h2>Нужен вход администратора</h2><p>Откройте кабинет под администратором проекта, затем вернитесь на эту страницу.</p><a class="button" href="/manager2/">Открыть кабинет</a></section>
<?php else: ?>
<p id="feedback" class="feedback" role="status" aria-live="polite" hidden></p>
<section class="panel" aria-labelledby="botHeading"><div class="sectionHead"><span class="step">1</span><div><h2 id="botHeading">Подключить бота</h2><p>@<?=telegramArchiveHtml(TelegramArchiveBotConfig::USERNAME)?></p></div><span id="botState" class="badge">Проверяем…</span></div>
<form id="tokenForm"><label for="botToken">Токен из BotFather</label><div class="tokenRow"><input id="botToken" name="botToken" type="password" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="240" required placeholder="Вставьте токен"><button class="button" id="saveToken" type="submit">Подключить</button></div><p class="hint">Токен сохраняется приватно на сервере и больше не показывается на странице.</p></form>
<div id="botDetails" class="botDetails" hidden><p id="botExplanation"></p><button id="inspectBot" class="button secondary" type="button">Проверить подключение</button> <button id="changeToken" class="textButton" type="button">Заменить токен</button></div>
<p id="secretaryHelp" class="notice" hidden>Включите <strong>Secretary Mode / режим секретаря</strong> для этого бота в <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">BotFather</a>, затем проверьте подключение ещё раз.</p>
</section>
<section class="panel" aria-labelledby="connectHeading"><div class="sectionHead"><span class="step">2</span><div><h2 id="connectHeading">Добавить в рабочий аккаунт</h2><p>Настройки Telegram → Telegram для бизнеса → Чат-боты</p></div></div>
<p>Выберите <strong>@<?=telegramArchiveHtml(TelegramArchiveBotConfig::USERNAME)?></strong> и разрешите доступ к рабочим личным чатам. Исключите личную переписку.</p>
<p class="hint">После подключения аккаунт появится ниже. Выберите «Общий аккаунт» или конкретного менеджера и нажмите «Включить сбор».</p>
</section>
<section class="panel" aria-labelledby="accountsHeading"><div class="sectionHead"><span class="step">3</span><div><h2 id="accountsHeading">Рабочие аккаунты</h2><p>Общие аккаунты и аккаунты отдельных менеджеров</p></div><button id="refreshAccounts" class="textButton" type="button">Обновить</button></div>
<div id="accounts" aria-live="polite"><p class="empty">Загружаем список…</p></div>
</section>
<p class="footnote">Старую историю можно добавить из JSON-экспорта Telegram Desktop. Новые сообщения и изменения будут поступать через подключённого бота.</p>
<script src="assets/manager-http-client.js" defer></script><script src="assets/telegram-archive.js?v=<?=telegramArchiveHtml($scriptVersion)?>" defer></script>
<?php endif; ?>
</main></body></html>
