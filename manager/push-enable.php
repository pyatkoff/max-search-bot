<?php
$baseDir=dirname(__DIR__);
require_once __DIR__.'/lib/ManagerHttp.php';
ManagerHttp::start();
if(ManagerHttp::managerId()<=0||!ManagerHttp::manager()){header('Location: ./');exit;}
$csrf=ManagerHttp::csrf(true);
$notificationVersion=(string)filemtime(__DIR__.'/assets/workspace-v2-notifications.js');
$notificationCssVersion=(string)filemtime(__DIR__.'/assets/workspace-v2-notifications.css');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AnyTour — Web Push</title><link rel="stylesheet" href="assets/workspace-v2-notifications.css?v=<?=htmlspecialchars($notificationCssVersion,ENT_QUOTES,'UTF-8')?>"><style>body{font:16px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f6f8;margin:0;padding:24px;color:#18202a}.card{max-width:520px;margin:40px auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 8px 30px #0001}button{border:0;border-radius:10px;padding:12px 16px;background:#111827;color:#fff;font:inherit}.muted{color:#737d89;margin-top:12px}a{color:#111827}#notificationStatus{margin-top:16px}#notificationStatus .notificationAction{display:none}</style></head><body><div class="card"><h2>Уведомления менеджера</h2><p>Включите Web Push на этом устройстве. После этого новые заявки и ответы клиентов смогут приходить даже при закрытой панели.</p><button id="enable" data-enable-push>Включить уведомления</button><div id="notificationStatus" class="muted" aria-live="polite"></div><p id="authNotice" hidden>Сессия истекла. Вернитесь в панель и войдите снова.</p><p><a href="./">← Вернуться в панель</a></p></div><script>
window.WorkspaceV2={S:{manager:{id:<?=json_encode(ManagerHttp::managerId())?>},authGeneration:0,csrf:<?=json_encode($csrf,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>},showAuthRecovery(){document.getElementById('enable').disabled=true;document.getElementById('authNotice').hidden=false;}};
document.addEventListener('DOMContentLoaded',()=>window.WorkspaceV2Notifications?.init(),{once:true});
</script><script defer src="assets/workspace-v2-notifications.js?v=<?=htmlspecialchars($notificationVersion,ENT_QUOTES,'UTF-8')?>"></script></body></html>
