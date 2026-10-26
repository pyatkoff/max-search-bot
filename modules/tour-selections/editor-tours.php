<?php
declare(strict_types=1);
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-src https://app.anytoour.ru; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
if (!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) {http_response_code(405);exit;}
$id=$_GET['conversation_id']??'';$actor=$_GET['editor_actor']??'';
$valid=static fn($v):bool=>is_string($v)&&preg_match('/\A[1-9][0-9]{0,14}\z/D',$v)===1;
$keys=array_count_values(array_map(static fn(string $part):string=>urldecode(explode('=',$part,2)[0]),explode('&',$_SERVER['QUERY_STRING']??'')));
$src=$valid($id)&&$valid($actor)&&($keys['conversation_id']??0)===1&&($keys['editor_actor']??0)===1?'https://app.anytoour.ru/manager/tour-selection-editor.php?conversation_id='.$id.'&amp;editor_actor='.$actor:null;
if (($_SERVER['REQUEST_METHOD']??'GET')==='HEAD') exit;
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>AnyTour — подборка туров</title><style>html,body{margin:0;width:100%;height:100%;font:16px system-ui;color:#183b32;background:#f3f7f4}iframe{display:block;width:100%;height:100dvh;border:0}main{max-width:580px;margin:12vh auto;padding:24px;line-height:1.6}a{color:#185d48}</style></head><body>
<?php if($src): ?><iframe title="Защищённый редактор подборки AnyTour из Manager2" src="<?=$src?>" referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-downloads"></iframe>
<?php else: ?><main><h1>Подборка туров</h1><p>Выберите клиента в Manager2 и откройте его подборку отдельно. Ссылка определяет клиента, но доступ проверяется по вашему аккаунту.</p><a href="https://app.anytoour.ru/manager2/">Открыть Manager2</a></main><?php endif; ?></body></html>
