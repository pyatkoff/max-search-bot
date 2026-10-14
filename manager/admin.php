<?php
$baseDir=dirname(__DIR__);
require_once __DIR__.'/lib/ManagerHttp.php';
ManagerHttp::start();
$managerTimeVersion=(string)(@filemtime(__DIR__.'/assets/manager-time.js')?:1);
$httpClientVersion=(string)(@filemtime(__DIR__.'/assets/manager-http-client.js')?:1);
$adminJsVersion=(string)(@filemtime(__DIR__.'/assets/admin.js')?:1);
$adminEditSafetyVersion=(string)(@filemtime(__DIR__.'/assets/admin-edit-safety.js')?:1);
$adminCssVersion=(string)(@filemtime(__DIR__.'/assets/admin.css')?:1);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>AnyTour — администрирование</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?=htmlspecialchars($adminCssVersion,ENT_QUOTES,'UTF-8')?>">
</head>
<body>
<div class="wrap">
  <header class="head">
    <div class="adminHeading">
      <a class="adminBrand" href="/manager2/" aria-label="AnyTour — кабинет менеджера">Any<span>Tour</span></a>
      <div class="adminHeadingText">
        <div class="adminEyebrow">Рабочее место администратора</div>
        <h1>Команда и настройки</h1>
        <p class="muted">Доступы, проекты и приоритет заявок <span class="adminTimezone">· Время МСК</span></p>
      </div>
    </div>
    <nav class="nav" aria-label="Другие разделы кабинета">
      <a class="adminBack" href="/manager2/">← К диалогам</a>
      <a href="routing.php">Маршрутизация</a>
      <a href="pipeline-admin.php">Этапы и теги продаж</a>
    </nav>
  </header>

  <div id="denied" class="card adminAccessNotice hidden" role="status">Доступно только администратору.</div>

  <main id="app" class="hidden">
    <nav class="adminSections" aria-label="Разделы администрирования">
      <button type="button" class="adminSectionButton active" data-admin-section="managers" aria-controls="adminSection-managers" aria-pressed="true">Менеджеры</button>
      <button type="button" class="adminSectionButton" data-admin-section="projects" aria-controls="adminSection-projects" aria-pressed="false">Проекты</button>
      <button type="button" class="adminSectionButton" data-admin-section="priority" aria-controls="adminSection-priority" aria-pressed="false">Приоритет заявок</button>
      <button type="button" class="adminSectionButton" data-admin-section="audit" aria-controls="adminSection-audit" aria-pressed="false">Журнал изменений</button>
    </nav>

    <div id="adminWorkspaceStatus" class="formStatus adminWorkspaceStatus" role="status" aria-live="polite"></div>

    <section id="adminSection-managers" class="adminSection" aria-labelledby="adminManagersTitle">
      <div class="adminSectionLayout">
        <div class="adminSectionMain">
          <div class="adminSectionHead">
            <div><h2 id="adminManagersTitle">Менеджеры</h2><p class="muted">Сотрудники, доступ к проектам и состояние рабочего места.</p></div>
            <button type="button" class="btn adminCreate" data-admin-create="manager">+ Добавить менеджера</button>
          </div>
          <div class="card adminFilters">
            <div class="adminSearchField"><label for="managerSearch">Поиск по имени, логину или email</label><input id="managerSearch" type="search" placeholder="Найти сотрудника" autocomplete="off"></div>
            <div><label for="managerRoleFilter">Роль</label><select id="managerRoleFilter"><option value="">Все роли</option><option value="manager">Менеджеры</option><option value="admin">Администраторы</option></select></div>
            <div><label for="managerActiveFilter">Доступ</label><select id="managerActiveFilter"><option value="">Любой доступ</option><option value="1">Доступ включён</option><option value="0">Доступ отключён</option></select></div>
            <div><label for="managerProjectFilter">Проект</label><select id="managerProjectFilter"><option value="">Все проекты</option></select></div>
          </div>
          <div id="managerResults" class="adminResults muted" role="status" aria-live="polite"></div>
          <div id="managers" class="adminDirectory"><div class="card adminEmpty">Загружаем менеджеров…</div></div>
        </div>
        <aside id="managerEditor" class="card adminEditor hidden" data-admin-editor="manager" aria-labelledby="managerEditorTitle">
          <div class="adminEditorHead"><h3 id="managerEditorTitle">Новый менеджер</h3><button type="button" class="adminClose" data-admin-close="manager">Закрыть</button></div>
          <input id="managerId" type="hidden">
          <div class="grid">
            <div><label for="managerLogin">Логин</label><input id="managerLogin" autocomplete="username" placeholder="anna.anytour"></div>
            <div><label for="managerName">Имя сотрудника</label><input id="managerName" autocomplete="name" placeholder="Анна Иванова"></div>
            <div><label for="managerEmail">Email</label><input id="managerEmail" type="email" autocomplete="email" placeholder="anna@example.ru"></div>
            <div><label for="managerRole">Роль</label><select id="managerRole"><option value="manager">Менеджер</option><option value="admin">Администратор</option></select></div>
          </div>
          <label for="managerPassword">Пароль</label><input id="managerPassword" type="password" autocomplete="new-password" aria-describedby="managerPasswordHint"><p id="managerPasswordHint" class="adminFieldHint">Для нового сотрудника — минимум 8 символов. При редактировании оставьте пустым, чтобы сохранить пароль.</p>
          <label class="adminCheck" for="managerActive"><input id="managerActive" type="checkbox" checked><span>Доступ в кабинет включён</span></label>
          <p class="adminFieldHint">Доступ в кабинет и рабочая смена — разные настройки. Сотрудник управляет своей сменой в диалогах.</p>
          <fieldset class="adminFieldset"><legend>Доступ к проектам</legend><div id="managerProjects" class="checks"></div></fieldset>
          <label for="managerPriority">Базовый приоритет заявок</label><input id="managerPriority" type="number" value="0" aria-describedby="managerPriorityHint"><p id="managerPriorityHint" class="adminFieldHint">Большее число даёт преимущество среди доступных менеджеров на смене.</p>
          <button id="saveManager" type="button" class="btn">Сохранить менеджера</button>
          <div id="managerFormStatus" class="formStatus" role="status" aria-live="polite"></div>
        </aside>
      </div>
    </section>

    <section id="adminSection-projects" class="adminSection hidden" aria-labelledby="adminProjectsTitle">
      <div class="adminSectionLayout">
        <div class="adminSectionMain">
          <div class="adminSectionHead"><div><h2 id="adminProjectsTitle">Проекты</h2><p class="muted">Направления работы, к которым подключены сотрудники.</p></div><button type="button" class="btn adminCreate" data-admin-create="project">+ Добавить проект</button></div>
          <div id="projects" class="adminDirectory"><div class="card adminEmpty">Загружаем проекты…</div></div>
        </div>
        <aside id="projectEditor" class="card adminEditor hidden" data-admin-editor="project" aria-labelledby="projectEditorTitle">
          <div class="adminEditorHead"><h3 id="projectEditorTitle">Новый проект</h3><button type="button" class="adminClose" data-admin-close="project">Закрыть</button></div>
          <input id="projectId" type="hidden">
          <label for="projectKey">Код проекта</label><input id="projectKey" placeholder="anytour-spb" aria-describedby="projectKeyHint"><p id="projectKeyHint" class="adminFieldHint">Короткий технический код, например anytour-spb.</p>
          <label for="projectName">Название проекта</label><input id="projectName" placeholder="AnyTour Санкт-Петербург">
          <label class="adminCheck" for="projectActive"><input id="projectActive" type="checkbox" checked><span>Проект включён</span></label>
          <button id="saveProject" type="button" class="btn">Сохранить проект</button>
          <div id="projectFormStatus" class="formStatus" role="status" aria-live="polite"></div>
        </aside>
      </div>
    </section>

    <section id="adminSection-priority" class="adminSection hidden" aria-labelledby="adminPriorityTitle">
      <div class="adminSectionLayout">
        <div class="adminSectionMain">
          <div class="adminSectionHead"><div><h2 id="adminPriorityTitle">Приоритет заявок</h2><p class="muted">Кто получает преимущество при распределении подходящей заявки.</p></div><button type="button" class="btn adminCreate" data-admin-create="rule">+ Добавить правило</button></div>
          <div class="hint adminPriorityHint">Сначала проверяются доступ к проекту и рабочая смена. Затем для доступных менеджеров складываются базовый приоритет и надбавки по совпавшим правилам.</div>
          <details class="adminTechnical"><summary>Как задавать условия для MAX-каналов</summary><p>Для четырёх MAX-каналов используйте в старте <code>entry_max_1</code>, <code>entry_max_2</code>, <code>entry_max_3</code> или <code>entry_max_4</code>. В правиле выберите «MAX-канал входа» и значение <code>max_1</code>, <code>max_2</code>, <code>max_3</code> или <code>max_4</code>.</p></details>
          <div id="priorityRules" class="adminDirectory"><div class="card adminEmpty">Загружаем правила…</div></div>
        </div>
        <aside id="ruleEditor" class="card adminEditor hidden" data-admin-editor="rule" aria-labelledby="ruleEditorTitle">
          <div class="adminEditorHead"><h3 id="ruleEditorTitle">Новое правило</h3><button type="button" class="adminClose" data-admin-close="rule">Закрыть</button></div>
          <input id="ruleId" type="hidden">
          <label for="ruleManager">Менеджер</label><select id="ruleManager"></select>
          <label for="ruleType">Условие</label><select id="ruleType"></select>
          <label for="ruleValue">Значение условия</label><input id="ruleValue" placeholder="max_1">
          <label for="ruleBonus">Надбавка к приоритету</label><input id="ruleBonus" type="number" value="0" placeholder="30">
          <label for="ruleComment">Комментарий</label><input id="ruleComment" placeholder="Ведёт канал max_1">
          <label class="adminCheck" for="ruleActive"><input id="ruleActive" type="checkbox" checked><span>Правило включено</span></label>
          <button id="saveRule" type="button" class="btn">Сохранить правило</button>
          <div id="ruleFormStatus" class="formStatus" role="status" aria-live="polite"></div>
        </aside>
      </div>
    </section>

    <section id="adminSection-audit" class="adminSection hidden" aria-labelledby="adminAuditTitle">
      <div class="adminSectionHead"><div><h2 id="adminAuditTitle">Журнал изменений</h2><p class="muted">Последние 50 административных изменений · Время МСК</p></div></div>
      <div class="card adminAuditCard">
        <div class="adminAuditFilters"><label for="auditSearch">Поиск по сотруднику, действию или проекту</label><input id="auditSearch" type="search" placeholder="Найти изменение в последних 50 записях" autocomplete="off"></div>
        <div id="auditResults" class="adminResults muted" role="status" aria-live="polite"></div>
        <div id="audit" class="auditList"><div class="adminEmpty">Загружаем журнал изменений…</div></div>
      </div>
      <p class="adminAuditFootnote muted">Журнал содержит безопасные сведения об изменениях. Пароли и полные снимки данных здесь не показываются.</p>
    </section>
  </main>
</div>
<script src="assets/manager-time.js?v=<?=htmlspecialchars($managerTimeVersion,ENT_QUOTES,'UTF-8')?>"></script>
<script src="assets/manager-http-client.js?v=<?=htmlspecialchars($httpClientVersion,ENT_QUOTES,'UTF-8')?>"></script>
<script src="assets/admin.js?v=<?=htmlspecialchars($adminJsVersion,ENT_QUOTES,'UTF-8')?>"></script>
<script src="assets/admin-edit-safety.js?v=<?=htmlspecialchars($adminEditSafetyVersion,ENT_QUOTES,'UTF-8')?>"></script>
</body>
</html>
