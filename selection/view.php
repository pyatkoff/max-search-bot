<?php
declare(strict_types=1);
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title><?= $h($selection['title']??'Подборка недоступна') ?> · AnyTour</title><link rel="stylesheet" href="selection.css"></head>
<body><main class="selection"><header class="selectionHead"><a class="brand" href="https://anytoour.ru/" rel="noreferrer">AnyTour</a>
<?php if (!$selection): ?><h1>Подборка сейчас недоступна</h1><p>Уточните ссылку у менеджера.</p></header>
<?php else: ?><p class="eyebrow">Варианты для вашего путешествия</p><h1><?= $h($selection['title']) ?></h1>
<?php if ($selection['intro']!==''): ?><p class="intro"><?= nl2br($h($selection['intro'])) ?></p><?php endif; ?>
<p class="updated">Версия <?= $selection['version'] ?> · Обновлено <?= $h($selection['updated_at']) ?> UTC</p></header>
<div class="offers">
<?php foreach ($selection['offers'] as $offer): ?>
<article class="offer" id="variant-<?= $offer['index']+1 ?>">
<div class="photos"><?php foreach ($offer['photos'] as $i=>$photo): ?><figure><img src="<?= $h($photo) ?>" alt="<?= ['Вид отеля','Пляж или территория','Пример интерьера номера'][$i] ?>" loading="lazy" referrerpolicy="no-referrer" width="800" height="600"><?php if ($i===2): ?><figcaption>Пример интерьера номера</figcaption><?php endif; ?></figure><?php endforeach; ?></div>
<div class="offerBody"><p class="variant">Вариант <?= $offer['index']+1 ?><?= $offer['destination']!==''?' · '.$h($offer['destination']):'' ?></p><h2><?= $h($offer['hotel']) ?></h2>
<p class="price"><?= $h(number_format((float)$offer['amount'],2,',',' ')) ?> <?= $h($offer['currency']) ?></p><p class="party">За весь состав: взрослых <?= $offer['adults'] ?><?= $offer['children']?', детей '.$offer['children']:'' ?></p>
<dl><div><dt>Даты</dt><dd><?= $h($offer['date_from']) ?> · <?= $offer['nights'] ?> ночей</dd></div><div><dt>Вылет</dt><dd><?= $h($offer['departure']) ?></dd></div><div><dt>Номер</dt><dd><?= $h($offer['room']) ?></dd></div><div><dt>Питание</dt><dd><?= $h($offer['meal']) ?></dd></div></dl>
<?php foreach (['rationale'=>'Почему этот вариант','compromise'=>'Что учесть','flights'=>'Перелёт','inclusions'=>'Включено','constraints'=>'Условия и доплаты'] as $key=>$label): if ($offer[$key]==='') { $offer[$key]=['flights'=>'Рейсы и время вылета уточняются.','inclusions'=>'Состав пакета уточняется.','constraints'=>'Обязательные доплаты, топливо и багаж требуют проверки.'][$key]??''; if ($offer[$key]==='') continue; } ?><p class="detail"><strong><?= $label ?></strong><br><?= nl2br($h($offer[$key])) ?></p><?php endforeach; ?>
<p class="fareNotice">Цена и наличие требуют подтверждения менеджером.<?= $offer['observed_at']!==''?' Цена получена: '.$h($offer['observed_at']).'.':'' ?></p>
<p class="nextStep">Понравился вариант? Напишите его номер менеджеру в вашей переписке.</p></div></article>
<?php endforeach; ?></div>
<footer>Условия могут измениться. Менеджер уточнит актуальную стоимость и наличие перед оформлением.</footer>
<?php endif; ?></main></body></html>
