<?php
require_once __DIR__.'/lib/ManagerHttp.php';
if(($requestProfilePresentation??'')!=='manager2')ManagerHttp::start();
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="/manager/"><title>Профиль запроса · AnyTour</title>
<link rel="stylesheet" href="assets/manager-ai-usage.css?v=<?=filemtime(__DIR__.'/assets/manager-ai-usage.css')?>"><link rel="stylesheet" href="assets/manager-request-profile.css?v=<?=filemtime(__DIR__.'/assets/manager-request-profile.css')?>">
<script src="assets/manager-http-client.js" defer></script><script src="assets/manager-request-profile.js?v=<?=filemtime(__DIR__.'/assets/manager-request-profile.js')?>" defer></script></head><body>
<header class="usageHeader"><a class="usageBrand" href="/manager2/">Any<span>Tour</span></a><a id="profileConversation" href="/manager2/">К переписке ↗</a></header><main>
<p class="usageEyebrow">Текущая поездка</p><h1>Профиль запроса</h1><p class="profileIntro">Сверьте условия с сообщениями и зафиксируйте, что важно для этой поездки. Профиль помогает подготовить ответ; цены, наличие и услуги проверяются отдельно.</p>
<p id="profileStatus" class="usageStatus" role="status" aria-live="polite">Проверяем доступ…</p>
<button id="profileRetryLoad" class="usageReview" type="button" hidden>Повторить загрузку профиля</button>
<section id="profileApp" hidden><div id="profileState" class="profileNotice"></div><div id="profileSuggestion" class="profileNotice" hidden></div>
<form id="profileForm"><div class="profileHeaderFields"><label>Название запроса<input id="profileLabel" maxlength="120" required placeholder="Например, декабрьский отдых с ребёнком"></label><label>Начало обсуждения этой поездки<select id="profileBoundary" required><option value="">Выберите сообщение</option></select></label></div>
<label class="profileNew"><input type="checkbox" id="profileNewTrip"> Это другая поездка — начать отдельный профиль</label>
<p class="profileHelp">Для «слов туриста» укажите буквальный фрагмент его сообщения. Ваши выводы и уточнения отмечайте отдельно. Цена публикации не становится бюджетом туриста, а тарифный состав не заменяет фактическую семью.</p>
<div id="profileFields"></div><div class="profileActions"><button id="profileSave" class="usageRefresh" type="submit">Сохранить проверенный профиль</button><button id="profileRefresh" type="button" class="usageReview">Сверить с новыми сообщениями</button><button id="profileReplace" type="button" class="usageReview" hidden>Заменить черновик сохранённым профилем</button><button id="profileReconcile" type="button" class="usageReview" hidden>Проверить сохранение</button></div></form>
<section class="profileHistory"><h2>История уточнений</h2><div id="profileHistory"></div></section></section></main></body></html>
