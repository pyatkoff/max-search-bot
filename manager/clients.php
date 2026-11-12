<?php
declare(strict_types=1);
require_once __DIR__.'/lib/ManagerHttp.php';
require_once __DIR__.'/../services/ClientBusinessProfile.php';
if(($managerClientPresentation??'')!=='manager2')ManagerHttp::start();
$asset=static fn(string $name): string=>htmlspecialchars((string)(filemtime(__DIR__.'/assets/'.$name)?:1),ENT_QUOTES,'UTF-8');
$escape=static fn(string $text): string=>htmlspecialchars($text,ENT_QUOTES,'UTF-8');
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#17483d"><base href="/manager/"><title>Компании — кабинет</title><link rel="stylesheet" href="assets/clients.css?v=<?=$asset('clients.css')?>"></head>
<body>
<header class="pageHead"><a href="/manager2/" class="brand" aria-label="К диалогам">a<span>.</span></a><div><p class="eyebrow">Рабочее пространство</p><h1>Компании</h1></div><a href="/manager2/" class="back">← К диалогам</a></header>
<main>
  <div id="notice" role="status" aria-live="polite">Загружаем компании…</div>
  <div id="access" hidden><p>Войдите в кабинет, чтобы открыть свои компании.</p><a href="/manager2/">Открыть вход</a><button id="retry" type="button">Повторить загрузку</button></div>
  <div id="workspace" hidden>
    <section id="pending" class="card pending" aria-labelledby="pendingTitle" hidden><h2 id="pendingTitle">Проверяем результат операции</h2><p id="pendingText"></p><div class="actions"><button id="receipt" type="button">Узнать результат</button><button id="cancelOperation" class="secondary" type="button">Отменить до подключения</button></div></section>
    <div class="toolbar"><p id="intro">Каждая компания видит только свои обращения и материалы.</p><div class="actions"><button id="refresh" class="secondary" type="button">Обновить</button><button id="addClient" type="button" hidden>Добавить клиента</button></div></div>
    <section id="editor" class="card" aria-labelledby="editorTitle" hidden>
      <div class="sectionHead"><div><p class="eyebrow">Новая компания</p><h2 id="editorTitle" tabindex="-1">Подключение клиента</h2></div><button id="closeEditor" type="button" class="secondary">Свернуть</button></div>
      <p class="muted">Сначала сохраним компанию и сотрудника. Затем вы сможете проверить бота и отдельно активировать подключение.</p>
      <form id="clientForm" autocomplete="off"><fieldset id="clientFields">
        <div class="formGrid"><label>Название компании<input id="clientName" required maxlength="191" autocomplete="organization"></label><label>Профиль работы<select id="businessKind" aria-describedby="profileHint"><?php foreach(ClientBusinessProfile::choices() as $profile): ?><option value="<?=$escape($profile['kind'])?>" data-description="<?=$escape($profile['description'])?>"><?=$escape($profile['label'])?></option><?php endforeach ?></select><span id="profileHint" class="hint"></span></label></div>
        <h3>Бот MAX</h3><div class="formGrid"><label>Имя бота<input id="botKey" required maxlength="191" pattern="[A-Za-z0-9_]+" spellcheck="false" placeholder="company_bot"><span class="hint">Имя без @, указанное в настройках бота.</span></label><label>Токен бота<input id="botToken" type="password" required minlength="8" maxlength="4096" autocomplete="off"><span class="hint">Хранится зашифрованно. После сохранения не показывается.</span></label></div>
        <h3>Первый сотрудник</h3><p class="hint">Получит доступ только к этой компании. Рабочую смену включает самостоятельно.</p><div class="formGrid"><label>Имя сотрудника<input id="staffName" required maxlength="191" autocomplete="off"></label><label>Логин<input id="staffLogin" required minlength="3" maxlength="191" pattern="[A-Za-z0-9._@+\-]+" autocomplete="off" spellcheck="false"></label><label>Начальный пароль<input id="staffPassword" type="password" required minlength="8" maxlength="72" autocomplete="new-password"><span class="hint">От 8 до 72 байт. Передайте сотруднику лично.</span></label></div>
        <h3>Материалы компании</h3><label>Услуги, цены и правила<textarea id="materials" rows="7" maxlength="100000" placeholder="Например: услуги, стоимость диагностики, адрес и часы работы."></textarea></label><label class="fileLabel">Загрузить текстовый файл<input id="materialsFile" type="file" accept=".txt,.csv,text/plain,text/csv"><span class="hint">TXT или CSV, до 100 КБ. Текст появится в поле выше; сотрудники увидят его в карточке компании.</span></label>
        <p class="hint">Токен и пароль остаются только на этой странице до сохранения. Они не восстанавливаются после её закрытия.</p><button type="submit" id="saveClient">Сохранить клиента</button>
      </fieldset></form>
    </section>
    <div class="columns"><section class="card" aria-labelledby="listTitle"><h2 id="listTitle">Доступные компании</h2><p id="empty" hidden>Компаний пока нет.</p><div id="clients"></div></section>
      <section id="detail" class="card" aria-labelledby="detailTitle" hidden><p class="eyebrow">Карточка компании</p><h2 id="detailTitle" tabindex="-1"></h2><p id="detailBot" class="muted"></p><div id="connectionActions" hidden><p id="connectionState"></p><div class="actions"><button id="verifyClient" type="button" class="secondary">Проверить подключение</button><button id="activateClient" type="button" hidden>Активировать подключение</button></div></div>
        <details id="connectionEditor" hidden><summary>Исправить данные до активации</summary>
          <p class="hint">После сохранения подключение нужно проверить заново. Данные сотрудников сохранятся.</p>
          <form id="connectionForm" autocomplete="off"><fieldset id="connectionFields">
            <label>Имя бота MAX<input id="editBotKey" required maxlength="191" pattern="[A-Za-z0-9_]+" spellcheck="false" autocomplete="off"></label>
            <label>Новый токен бота<input id="editToken" type="password" minlength="8" maxlength="4096" autocomplete="new-password"><span class="hint">Оставьте пустым, чтобы сохранить текущий токен.</span></label>
            <label>Материалы компании<textarea id="editMaterials" rows="6"></textarea></label>
            <button id="saveConnection" type="submit">Сохранить исправления</button>
          </fieldset></form>
        </details>
        <p id="detailProfile" class="hint" hidden></p><h3>Сотрудники</h3><ul id="staff"></ul><h3>Материалы</h3><pre id="detailMaterials"></pre></section>
    </div>
  </div>
</main>
<script src="assets/manager-http-client.js?v=<?=$asset('manager-http-client.js')?>"></script><script src="assets/clients.js?v=<?=$asset('clients.js')?>"></script>
</body></html>
