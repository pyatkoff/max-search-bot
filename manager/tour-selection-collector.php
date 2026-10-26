<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionEditorHost.php';
ManagerHttp::start();
$manager=ManagerHttp::manager();
if (!$manager || ManagerHttp::managerId()<=0) {header('Location: ./');exit;}
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$config=['actor'=>(int)$manager['id'],'csrf'=>ManagerHttp::csrf(true),'conversation_id'=>(int)($_GET['conversation_id']??0),'editor_entry'=>ManagerTourSelectionEditorHost::entry()];
session_write_close();
$assetVersion=static fn(string $file):string=>htmlspecialchars((string)filemtime(__DIR__.'/assets/'.$file),ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AnyTour — сборщик предложений</title><link rel="stylesheet" href="assets/tour-selection-collector.css?v=<?=$assetVersion('tour-selection-collector.css')?>"></head><body>
<main class="collector"><a id="collectorOpenSelection" href="/manager2/">← В Manager2</a><p class="kicker">Конструктор подборок</p><h1>Сборщик предложений</h1>
<p>Предложения из браузера попадут только в выбранную здесь подборку. Отправка туристу выполняется отдельно в Manager2.</p>
<section id="collectorTarget" aria-label="Клиент и поездка"><h2>Загружаем подборку…</h2></section>
<label class="consent"><input id="collectorConfirm" type="checkbox"> Подтверждаю: это подборка текущей поездки клиента.</label>
<div class="actions"><button id="collectorConnect" disabled>Подключить эту подборку</button><button id="collectorRevoke" disabled>Отключить сборщик</button><button id="collectorRefresh">Обновить</button></div>
<p id="collectorStatus" role="status" aria-live="polite">Установите расширение AnyTour в Chrome или Яндекс Браузер и откройте его здесь.</p>
<p class="muted">Держите эту вкладку открытой, пока добавляете туры. При потере связи предложения останутся в очереди расширения. Откройте сборщик этой же поездки и продолжите сохранение. Если вход в Manager2 истёк, войдите снова. Расширение не получает пароль или cookies туроператора.</p>
<p><a href="../extensions/selection-collector/anytour-selection-collector.zip" download>Скачать расширение ZIP</a> · <a href="../extensions/selection-collector/README.md">Установка и поддерживаемые страницы</a></p>
</main><script id="collectorConfig" type="application/json"><?=json_encode($config,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><script src="assets/manager-http-client.js?v=<?=$assetVersion('manager-http-client.js')?>"></script><script src="assets/tour-selection-collector.js?v=<?=$assetVersion('tour-selection-collector.js')?>"></script></body></html>
