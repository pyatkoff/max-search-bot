<?php
// Presentation only. Existing controls are moved here before canonical boot.
?><link rel="stylesheet" href="/manager/assets/workspace-v2-post-context.css?v=<?=filemtime(dirname(__DIR__).'/manager/assets/workspace-v2-post-context.css')?>">
<script src="/manager/assets/workspace-v2-post-context.js?v=<?=filemtime(dirname(__DIR__).'/manager/assets/workspace-v2-post-context.js')?>" defer></script>
<div class="m2App">
<nav class="m2Rail" aria-label="Разделы кабинета">
  <a class="m2Logo" href="/manager2/" aria-label="AnyTour — кабинет менеджера">a<span>.</span></a>
  <button class="m2Nav active" type="button" data-m2-nav="dialogues" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 1-8 8H5l-3 3V11a9 9 0 0 1 18 0Z"/></svg><span>Диалоги</span></button>
  <button class="m2Nav" type="button" data-m2-nav="tasks"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 6 2 2 4-4M12 6h9M3 14l2 2 4-4M12 14h9M12 21h9"/></svg><span>Мой план</span></button>
  <button class="m2Nav" type="button" data-m2-nav="sales"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16M5 8h2m4 4h2m4-4h2"/></svg><span>Продажи</span></button>
  <button class="m2Nav" type="button" data-m2-nav="archive"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8v12h16V8M3 3h18v5H3zm6 10h6"/></svg><span>Архив</span></button>
  <div class="m2RailEnd"><a class="m2Legacy" href="/manager/" title="Открыть прежний кабинет">Прежний<br>кабинет ↗</a><div id="m2AdminSlot"></div></div>
</nav>
<header class="m2Topbar">
  <div class="m2Brand">anytour<span>.</span><i></i><small>Рабочее место</small></div>
  <div class="m2Account" id="m2Account"><span class="m2Identity" id="m2Identity"></span><span id="m2ShiftSlot"></span><time id="m2Clock" class="m2Clock"></time><button id="m2NotificationButton" class="m2IconButton" type="button" aria-label="Уведомления и звук" aria-haspopup="dialog"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="m2NotificationDot" aria-hidden="true"></span></button></div>
</header>
<dialog id="m2Notifications" class="m2Dialog" aria-labelledby="m2NotificationTitle"><div class="m2DialogHead"><div><h2 id="m2NotificationTitle">Уведомления и звук</h2><p>Для этого браузера и устройства</p></div><button id="m2NotificationsClose" class="m2IconButton" type="button" aria-label="Закрыть уведомления">✕</button></div><div id="m2NotificationSlot"></div></dialog>
