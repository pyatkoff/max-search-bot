<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$notifications=(string)file_get_contents($root.'/manager/assets/workspace-v2-notifications.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2.css');
$api=(string)file_get_contents($root.'/manager/api.php');
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$passed=0;$failed=0;
function srCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

srCheck('manager API keeps explicit login and shared unauthorized boundaries',strpos($api,"if(\$action==='login')")!==false&&strpos($api,"error'=>'invalid_credentials'")!==false&&strpos($api,'ManagerHttp::requireManager();')!==false&&strpos($http,"'error'=>'unauthorized'")!==false);
srCheck('workspace turns 401 into in-place auth recovery',strpos($js,'if(r.status===401){showAuthRecovery()')!==false&&strpos($js,"S.authExpired=true")!==false&&strpos($js,'managerAuthRecovery')!==false);
srCheck('expired auth blocks further protected requests until recovery',strpos($js,'if(S.authExpired){showAuthRecovery();throw new Error(\'unauthorized\')}')!==false);
srCheck('recovery dialog asks for login and password accessibly',strpos($js,'id="managerAuthForm"')!==false&&strpos($js,'autocomplete="username"')!==false&&strpos($js,'autocomplete="current-password"')!==false&&strpos($js,'aria-modal="true"')!==false);
srCheck('login recovery uses same-origin API directly',strpos($js,"fetch('api.php'")!==false&&strpos($js,"action:'login',login,password")!==false&&strpos($js,"credentials:'same-origin'")!==false);
srCheck('invalid credentials stay inside recovery dialog',strpos($js,"r.status===401?'Неверный логин или пароль.'")!==false&&strpos($js,'managerAuthError')!==false);
srCheck('successful login restores csrf identity and workspace without reload',strpos($js,'S.authExpired=false;hideAuthRecovery();applyIdentity(me)')!==false&&strpos($js,'S.csrf=me.csrf')!==false&&strpos($js,'bindWorkspaceOnce()')!==false&&strpos($js,'WorkspaceV2Inbox?.load({preserveScroll:true})')!==false&&strpos($js,'location.reload')===false);
srCheck('successful login refreshes notification health after initialized service worker',strpos($js,'await window.WorkspaceV2Notifications?.init();await window.WorkspaceV2Notifications?.refresh();')!==false);
srCheck('notification 401 delegates to the canonical auth recovery owner',strpos($notifications,'window.WorkspaceV2?.showAuthRecovery?.()')!==false&&strpos($notifications,'workspace.S.authExpired=true')===false&&strpos($notifications,'showFatal')===false);
srCheck('notification auth handling never navigates or reloads the workspace',strpos($notifications,'location.href')===false&&strpos($notifications,'location.replace')===false&&strpos($notifications,'location.assign')===false&&strpos($notifications,'location.reload')===false);
srCheck('notification health fetch keeps manager session credentials explicit',strpos($notifications,"credentials:'same-origin'")!==false&&strpos($notifications,"cache:'no-store'")!==false);
srCheck('recovery avoids duplicate event binding after re-login',strpos($js,'workspaceBound:false')!==false&&strpos($js,'if(S.workspaceBound)return')!==false&&strpos($js,'S.workspaceBound=true')!==false);
srCheck('cold start failure is actionable instead of silent',strpos($js,'function showStartupFailure')!==false&&strpos($js,'id="managerStartupRetry"')!==false&&strpos($js,'Попробовать снова')!==false&&strpos($js,"if(!me?.ok){if(!S.authExpired)showStartupFailure();return}")!==false);
srCheck('cold start retry reuses boot without navigation',strpos($js,"retry.onclick=async()=>")!==false&&strpos($js,'await boot()')!==false&&strpos($js,'location.href')===false&&strpos($js,'location.assign')===false&&strpos($js,'location.replace')===false);
srCheck('cold start protects duplicate retries and keeps auth recovery separate',strpos($js,'booting:false')!==false&&strpos($js,'if(S.booting)return')!==false&&strpos($js,'if(!S.authExpired)showStartupFailure()')!==false);
srCheck('startup failure has mobile-safe visible retry presentation',strpos($css,'.startupFailure{')!==false&&strpos($css,'.startupFailure .actionBtn')!==false&&strpos($css,'@media(max-width:520px)')!==false&&strpos($css,'.startupFailure .actionBtn{width:100%')!==false);
srCheck('mobile recovery is full-screen safe-area aware and touch friendly',strpos($css,'.managerAuthRecovery{position:fixed;inset:0')!==false&&strpos($css,'env(safe-area-inset-bottom)')!==false&&strpos($css,'.managerAuthCard button{width:100%;min-height:46px')!==false&&strpos($css,'@media(max-width:520px)')!==false&&strpos($css,'.managerAuthRecovery{align-items:flex-end')!==false);
srCheck('expired-session copy no longer sends manager to an impossible manual refresh flow',strpos($js,'Обновите страницу после повторного входа')===false&&strpos($js,'Войти и продолжить')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
