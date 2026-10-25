<?php
require_once __DIR__.'/lib/ManagerHttp.php';
if(($managerAiUsagePresentation??'')!=='manager2')ManagerHttp::start();
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><base href="/manager/"><title>Применения AI · AnyTour</title>
<link rel="stylesheet" href="assets/manager-ai-usage.css?v=<?=filemtime(__DIR__.'/assets/manager-ai-usage.css')?>">
<script src="assets/manager-http-client.js" defer></script><script src="assets/manager-time.js" defer></script><script src="assets/manager-ai-usage.js?v=<?=filemtime(__DIR__.'/assets/manager-ai-usage.js')?>" defer></script></head>
<body><header class="usageHeader"><a class="usageBrand" href="/manager2/">Any<span>Tour</span></a><a href="/manager2/analytics.php">Аналитика команды ↗</a></header>
<main><div class="usageIntro"><p class="usageEyebrow">Работа с ответами</p><h1>Как команда использует AI</h1><p>Предложение помощника и ответ менеджера рядом. Здесь подтверждённые применения AI в отправленных ответах.</p></div>
<p id="usageStatus" class="usageStatus" role="status" aria-live="polite">Проверяем доступ…</p><a id="usageLogin" href="/manager2/" hidden>Войти в кабинет</a>
<section id="usageApp" hidden><form id="usageFilters" class="usageFilters">
<div class="usageModes" role="group" aria-label="Просмотр применений"><button type="button" data-mode="new" aria-pressed="true">Непросмотренные</button><button type="button" data-mode="all" aria-pressed="false">Все применения</button></div>
<label>Проект<select id="usageProject"><option value="">Все доступные</option></select></label><label>Период<select id="usageDays"><option value="7">За 7 дней</option><option value="30">За 30 дней</option></select></label>
<label class="usageCheck"><input id="usageChanged" type="checkbox"> Только с правками</label><button type="submit" class="usageRefresh">Обновить</button></form>
<div class="usageListHeading"><h2 id="usageListTitle">Непросмотренные применения</h2><span id="usageCount"></span></div><div id="usageCases"></div><button id="usageMore" type="button" hidden>Показать ещё</button>
<p class="usageNote">Правки могут дополнять ответ и сами по себе не означают ошибку AI. Ответы без сохранённой связи с помощником здесь не учитываются. «Просмотрено» относится к этому сравнению и не меняет прочитанность переписки.</p><p id="usageAsOf" class="usageAsOf"></p></section></main></body></html>
