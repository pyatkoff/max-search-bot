<?php
declare(strict_types=1);
$appearance=$selection['appearance']??null;
$template=is_array($appearance)&&in_array($appearance['template']??null,['photo','compact','personal'],true)?$appearance['template']:null;
$palette=is_array($appearance)&&in_array($appearance['palette']??null,['forest','ocean','sunset'],true)?$appearance['palette']:null;
$appearanceClasses=($template?' selectionTemplate-'.$template:'').($palette?' selectionPalette-'.$palette:'');
$agency=$template&&is_string($appearance['agency_name']??null)?$appearance['agency_name']:'AnyTour';
$personal=$template==='personal';
$manager=$personal&&is_array($appearance['manager']??null)?$appearance['manager']:null;
$party=static function(array $offer):string {
    $text='Взрослых: '.(int)$offer['adults'];
    if (!empty($offer['children'])) {
        $text.=' · детей: '.(int)$offer['children'];
        if (!empty($offer['child_ages'])) $text.=' (возраст: '.implode(', ',array_map('intval',$offer['child_ages'])).')';
    }
    return $text;
};
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title><?= $h($selection['title']??'Подборка недоступна') ?> · AnyTour</title><link rel="stylesheet" href="selection.css"></head>
<body><main class="selection<?= $h($appearanceClasses) ?>"<?= isset($interest)&&$interest?' data-selection-interest="'.$h(json_encode($interest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)).'"':'' ?>><header class="selectionHead"><a class="brand" href="https://anytoour.ru/" rel="noreferrer"><?= $h($agency) ?></a>
<?php if (!$selection): ?><h1>Подборка сейчас недоступна</h1><p>Уточните ссылку у менеджера.</p></header>
<?php else: ?>
<?php if ($manager && $manager['name']!==''): ?><section class="personalManager" aria-label="Ваш менеджер"><p class="eyebrow">Ваш персональный менеджер</p><h2><?= $h($manager['name']) ?></h2><?php if ($manager['note']!==''): ?><p><?= nl2br($h($manager['note'])) ?></p><?php endif; ?><div class="managerContacts"><?php
$phone=$manager['phone'];$digits=preg_replace('/[^0-9]/','',$phone);
if ($phone!==''): ?><?php if (preg_match('/\A\+?[0-9 ()-]+\z/D',$phone)&&strlen($digits)>=10&&strlen($digits)<=15): ?><a href="tel:<?= $h((str_starts_with($phone,'+')?'+':'').$digits) ?>"><?= $h($phone) ?></a><?php else: ?><span><?= $h($phone) ?></span><?php endif; ?><?php endif; ?>
<?php if ($manager['email']!==''): ?><?php if (filter_var($manager['email'],FILTER_VALIDATE_EMAIL)): ?><a href="mailto:<?= $h(rawurlencode($manager['email'])) ?>"><?= $h($manager['email']) ?></a><?php else: ?><span><?= $h($manager['email']) ?></span><?php endif; ?><?php endif; ?></div></section><?php endif; ?>
<p class="eyebrow">Варианты для вашего путешествия</p><h1><?= $h($selection['title']) ?></h1>
<?php if ($selection['intro']!==''): ?><p class="intro"><?= nl2br($h($selection['intro'])) ?></p><?php endif; ?>
<p class="updated">Версия <?= $selection['version'] ?> · Обновлено <?= $h($selection['updated_at']) ?> UTC</p><?php if (($interest['bucket']??'')==='preview'): ?><p class="updated">Предпросмотр — отдельно от просмотров посетителей.</p><?php endif; ?></header>
<?php if ($personal&&count($selection['offers'])>1): ?><nav class="personalNavigation" aria-label="Навигация по подборке"><a href="#selection-compare">Сравнить варианты ↓</a></nav><?php endif; ?>
<div class="offers">
<?php
$groups=[];
foreach ($selection['offers'] as $offer) {
    $key=isset($offer['hotel_group'])&&($offer['photo_layout']??'')!=='gallery-v1'?'group:'.$offer['hotel_group']:'offer:'.$offer['index'];
    $groups[$key][]=$offer;
}
foreach ($groups as $variants): $cover=$variants[0];$grouped=count($variants)>1;
?>
<article class="offer"<?= $grouped?'':' id="variant-'.($cover['index']+1).'"' ?>>
<div class="photos<?= $grouped?' sharedPhotos':'' ?> photoCount<?= count($cover['photos']) ?>"><?php foreach (($grouped?array_slice($cover['photos'],0,2):$cover['photos']) as $i=>$photo): ?><figure><img src="<?= $h($photo) ?>" alt="<?= ($cover['photo_layout']??'')==='gallery-v1'?'Фото отеля '.($i+1):['Вид отеля','Пляж или территория','Пример интерьера номера'][$i] ?>" loading="lazy" referrerpolicy="no-referrer" width="800" height="600"><?php if ($i===2&&($cover['photo_layout']??'')!=='gallery-v1'): ?><figcaption>Пример интерьера номера</figcaption><?php endif; ?></figure><?php endforeach; ?></div>
<div class="offerBody"><h2 data-interest-hotel data-interest-card="<?= (int)$cover['index'] ?>"><?= $h($cover['hotel']) ?></h2>
<?php if ($grouped): ?><p class="groupHint">Несколько предложений одного отеля — сравните даты, номера и цены.</p><?php endif; ?>
<?php foreach ($variants as $offer): ?>
<section class="offerVariant"<?= $grouped?' id="variant-'.($offer['index']+1).'"':'' ?>>
<p class="variant">Вариант <?= $offer['index']+1 ?><?= $offer['destination']!==''?' · '.$h($offer['destination']):'' ?></p><?php if ($grouped): ?><h3>Вариант <?= $offer['index']+1 ?> · <?= $h($offer['room']) ?></h3>
<?php if ($offer['hotel']!==$cover['hotel']): ?><p class="detail">Название в предложении: <?= $h($offer['hotel']) ?></p><?php endif; ?>
<?php endif; ?>
<p class="price"><?= ($offer['price_qualifier']??null)==='from'?'от ':'' ?><?= $h(number_format((float)$offer['amount'],2,',',' ')) ?> <?= $h($offer['currency']) ?></p><p class="party">За весь состав: взрослых <?= $offer['adults'] ?><?= $offer['children']?', детей '.$offer['children']:'' ?></p>
<?php if ($grouped): ?><figure class="variantRoom"><img src="<?= $h($offer['photos'][2]) ?>" alt="Пример интерьера номера этого варианта" loading="lazy" referrerpolicy="no-referrer" width="800" height="600"><figcaption>Пример интерьера номера</figcaption></figure><?php endif; ?>
<?php if ($personal): ?><div class="personalSummary"><span><?= $h($offer['date_from']) ?> · <?= (int)$offer['nights'] ?> ночей</span><span><?= $h($party($offer)) ?></span><span><?= $h($offer['room']) ?></span><span><?= $h($offer['meal']) ?></span></div>
<?php if ($offer['rationale']!==''): ?><aside class="personalRecommendation"><strong>Мой комментарий</strong><p><?= nl2br($h($offer['rationale'])) ?></p></aside><?php endif; ?>
<?php if ($offer['compromise']!==''): ?><p class="detail personalCompromise"><strong>Что учесть</strong><br><?= nl2br($h($offer['compromise'])) ?></p><?php endif; ?>
<details class="personalDetails"><summary>Номер, питание, перелёт и условия</summary><div class="personalDetailsBody"><?php endif; ?>
<dl><div><dt>Даты</dt><dd><?= $h($offer['date_from']) ?> · <?= $offer['nights'] ?> ночей</dd></div><div><dt>Вылет</dt><dd><?= $h($offer['departure']) ?></dd></div><div><dt>Номер</dt><dd><?= $h($offer['room']) ?></dd></div><div><dt>Питание</dt><dd><?= $h($offer['meal']) ?></dd></div></dl>
<?php foreach (['rationale'=>'Почему этот вариант','compromise'=>'Что учесть','flights'=>'Перелёт','inclusions'=>'Включено','constraints'=>'Условия и доплаты'] as $key=>$label): if ($personal&&in_array($key,['rationale','compromise'],true)) continue; if ($offer[$key]==='') { $offer[$key]=['flights'=>'Рейсы и время вылета уточняются.','inclusions'=>'Состав пакета уточняется.','constraints'=>'Обязательные доплаты, топливо и багаж требуют проверки.'][$key]??''; if ($offer[$key]==='') continue; } ?><p class="detail"><strong><?= $label ?></strong><br><?= nl2br($h($offer[$key])) ?></p><?php endforeach; ?>
<?php if ($personal): ?></div></details><?php endif; ?>
<p class="fareNotice">Цена и наличие требуют подтверждения менеджером.<?= $offer['observed_at']!==''?' Цена получена: '.$h($offer['observed_at']).'.':'' ?></p>
<?php if (!empty($offer['bot_links'])): ?><div class="botActions"><?php foreach ($offer['bot_links'] as $platform=>$url): ?><a href="<?= $h($url) ?>" rel="noreferrer" data-interest-card="<?= (int)$offer['index'] ?>" data-interest-platform="<?= $h($platform) ?>">Уточнить в <?= $platform==='telegram'?'Telegram':'MAX' ?></a><?php endforeach; ?></div><p class="nextStep">Передадим выбранный вариант и показанные условия в ваш диалог с ботом. Обращение сохранится после перехода и запуска бота.</p><?php else: ?><p class="nextStep">Понравился вариант? Напишите его номер менеджеру в вашей переписке.</p><?php endif; ?></section>
<?php endforeach; ?></div></article>
<?php endforeach; ?></div>
<?php if ($personal&&count($selection['offers'])>1): ?><section class="personalComparison" id="selection-compare" aria-labelledby="selection-compare-title"><h2 id="selection-compare-title">Сравнить варианты</h2><p>Цена за весь указанный состав. Даты, номер и питание относятся к конкретному предложению.</p><div class="comparisonScroll" tabindex="0" role="region" aria-label="Сравнение вариантов — прокрутка по горизонтали"><table><thead><tr><th scope="col">Отель / номер</th><th scope="col">Цена</th><th scope="col">Даты / ночи</th><th scope="col">Туристы</th><th scope="col">Питание</th><th scope="col">Почему этот вариант / нюансы</th></tr></thead><tbody><?php foreach ($selection['offers'] as $row): ?><tr><th scope="row"><a href="#variant-<?= (int)$row['index']+1 ?>"><?= $h($row['hotel']) ?></a><span><?= $h($row['room']) ?></span></th><td><?= ($row['price_qualifier']??null)==='from'?'от ':'' ?><?= $h(number_format((float)$row['amount'],2,',',' ')) ?> <?= $h($row['currency']) ?></td><td><?= $h($row['date_from']) ?><br><?= (int)$row['nights'] ?> ночей<br><?= $h($row['departure']) ?></td><td><?= $h($party($row)) ?></td><td><?= $h($row['meal']) ?></td><td><?= $h($row['rationale']!==''?$row['rationale']:'Комментарий уточняется') ?><?php if ($row['compromise']!==''): ?><br><strong>Учесть:</strong> <?= $h($row['compromise']) ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php if ($personal): ?><section class="personalClosing"><h2>Какой вариант вам ближе?</h2><p>Обсудите понравившийся вариант с менеджером в вашей переписке. Уточним актуальную стоимость, наличие и условия перед оформлением.</p></section><?php endif; ?>
<footer>Условия могут измениться. Менеджер уточнит актуальную стоимость и наличие перед оформлением.</footer>
<?php endif; ?></main><?php if (isset($interest)&&$interest): ?><script src="activity.js" defer></script><?php endif; ?></body></html>
