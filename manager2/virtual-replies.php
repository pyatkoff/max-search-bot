<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$version=(string)max(filemtime(__DIR__.'/assets/virtual-replies.css'),filemtime(__DIR__.'/assets/virtual-replies.js'));
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AnyTour — разборы ответов</title>
<link rel="stylesheet" href="assets/virtual-replies.css?v=<?=htmlspecialchars($version,ENT_QUOTES,'UTF-8')?>"></head>
<body><main><header><a href="/manager2/">← К диалогам</a><p class="eyebrow">ANYTOUR · ЛИЧНАЯ ПРОВЕРКА</p><h1>Разборы ответов</h1><p>Переписка → точный виртуальный ответ → оценка Astra. Эти ответы туристам не отправлялись.</p></header>
<section class="toolbar"><label for="verdict">Оценка Astra<select id="verdict"><option value="all">Все оценки</option><option value="revise">Есть замечания</option><option value="pass">Без замечаний</option><option value="insufficient_context">Не хватает контекста</option></select></label><button id="refresh" type="button">Обновить</button></section>
<p id="status" role="status" aria-live="polite">Загружаем разборы…</p><p id="login" hidden><a href="/manager2/">Войти в кабинет под администратором AnyTour</a></p>
<div class="layout"><section aria-label="Список разборов"><div id="reviews"></div><nav class="pages" aria-label="Страницы разборов"><button id="previous" type="button" disabled>← Новее</button><button id="next" type="button" disabled>Старше →</button></nav></section><section id="detail" aria-label="Выбранный разбор" tabindex="-1"><p class="muted">Выберите разбор, чтобы увидеть переписку и ответ.</p></section></div>
<p class="footer">Показаны обычные завершённые разборы, доступные сейчас. Скрытые контрольные диалоги сюда не входят. При изменении или удалении источника разбор может стать недоступен.</p></main><script src="assets/virtual-replies.js?v=<?=htmlspecialchars($version,ENT_QUOTES,'UTF-8')?>" defer></script></body></html>
