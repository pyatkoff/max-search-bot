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
<?php
$groups=[];
foreach ($selection['offers'] as $offer) {
    $key=isset($offer['hotel_group'])?'group:'.$offer['hotel_group']:'offer:'.$offer['index'];
    $groups[$key][]=$offer;
}
foreach ($groups as $variants): $cover=$variants[0];$grouped=count($variants)>1;
?>
<article class="offer"<?= $grouped?'':' id="variant-'.($cover['index']+1).'"' ?>>
<div class="photos<?= $grouped?' sharedPhotos':'' ?>"><?php foreach (($grouped?array_slice($cover['photos'],0,2):$cover['photos']) as $i=>$photo): ?><figure><img src="<?= $h($photo) ?>" alt="<?= ['Вид отеля','Пляж или территория','Пример интерьера номера'][$i] ?>" loading="lazy" referrerpolicy="no-referrer" width="800" height="600"><?php if ($i===2): ?><figcaption>Пример интерьера номера</figcaption><?php endif; ?></figure><?php endforeach; ?></div>
<div class="offerBody"><h2><?= $h($cover['hotel']) ?></h2>
<?php if ($grouped): ?><p class="groupHint">Несколько предложений одного отеля — сравните даты, номера и цены.</p><?php endif; ?>
<?php foreach ($variants as $offer): ?>
<section class="offerVariant"<?= $grouped?' id="variant-'.($offer['index']+1).'"':'' ?>>
<p class="variant">Вариант <?= $offer['index']+1 ?><?= $offer['destination']!==''?' · '.$h($offer['destination']):'' ?></p><?php if ($grouped): ?><h3>Вариант <?= $offer['index']+1 ?> · <?= $h($offer['room']) ?></h3>
<?php if ($offer['hotel']!==$cover['hotel']): ?><p class="detail">Название в предложении: <?= $h($offer['hotel']) ?></p><?php endif; ?>
<?php endif; ?>
<p class="price"><?= $h(number_format((float)$offer['amount'],2,',',' ')) ?> <?= $h($offer['currency']) ?></p><p class="party">За весь состав: взрослых <?= $offer['adults'] ?><?= $offer['children']?', детей '.$offer['children']:'' ?></p>
<?php if ($grouped): ?><figure class="variantRoom"><img src="<?= $h($offer['photos'][2]) ?>" alt="Пример интерьера номера этого варианта" loading="lazy" referrerpolicy="no-referrer" width="800" height="600"><figcaption>Пример интерьера номера</figcaption></figure><?php endif; ?>
<dl><div><dt>Даты</dt><dd><?= $h($offer['date_from']) ?> · <?= $offer['nights'] ?> ночей</dd></div><div><dt>Вылет</dt><dd><?= $h($offer['departure']) ?></dd></div><div><dt>Номер</dt><dd><?= $h($offer['room']) ?></dd></div><div><dt>Питание</dt><dd><?= $h($offer['meal']) ?></dd></div></dl>
<?php foreach (['rationale'=>'Почему этот вариант','compromise'=>'Что учесть','flights'=>'Перелёт','inclusions'=>'Включено','constraints'=>'Условия и доплаты'] as $key=>$label): if ($offer[$key]==='') { $offer[$key]=['flights'=>'Рейсы и время вылета уточняются.','inclusions'=>'Состав пакета уточняется.','constraints'=>'Обязательные доплаты, топливо и багаж требуют проверки.'][$key]??''; if ($offer[$key]==='') continue; } ?><p class="detail"><strong><?= $label ?></strong><br><?= nl2br($h($offer[$key])) ?></p><?php endforeach; ?>
<p class="fareNotice">Цена и наличие требуют подтверждения менеджером.<?= $offer['observed_at']!==''?' Цена получена: '.$h($offer['observed_at']).'.':'' ?></p>
<?php if (!empty($offer['bot_links'])): ?><div class="botActions"><?php foreach ($offer['bot_links'] as $platform=>$url): ?><a href="<?= $h($url) ?>" rel="noreferrer">Уточнить в <?= $platform==='telegram'?'Telegram':'MAX' ?></a><?php endforeach; ?></div><p class="nextStep">Передадим выбранный вариант и показанные условия в ваш диалог с ботом. Обращение сохранится после перехода и запуска бота.</p><?php else: ?><p class="nextStep">Понравился вариант? Напишите его номер менеджеру в вашей переписке.</p><?php endif; ?></section>
<?php endforeach; ?></div></article>
<?php endforeach; ?></div>
<footer>Условия могут измениться. Менеджер уточнит актуальную стоимость и наличие перед оформлением.</footer>
<?php endif; ?></main></body></html>
