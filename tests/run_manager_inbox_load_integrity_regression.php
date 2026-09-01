<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$passed=0;$failed=0;
function inboxLoadCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

inboxLoadCheck('list failure no longer becomes an empty success result',strpos($js,"throw error")!==false&&strpos($js,"const error=new Error('inbox_list_failed')")!==false&&strpos($js,'return[]')===false);
inboxLoadCheck('successful empty result still renders the canonical empty state',strpos($js,"empty.textContent='Лидов по выбранным условиям нет'")!==false&&strpos($js,'if(!rows.length)')!==false);
inboxLoadCheck('Inbox load uses a monotonic request sequence',strpos($js,'let bound=false,loadSeq=0')!==false&&strpos($js,'const seq=++loadSeq')!==false&&substr_count($js,'if(seq!==loadSeq)return false')>=2);
inboxLoadCheck('stale response cannot repaint a newer filter result',strpos($js,'const rows=await fetchRows();if(seq!==loadSeq)return false')!==false&&strpos($js,"if(S.viewMode==='kanban')window.WorkspaceV2Kanban?.render(rows);else renderList(rows,options)")!==false);
inboxLoadCheck('load state is accessible and exposes explicit retry',strpos($js,"el.setAttribute('aria-live','polite')")!==false&&strpos($js,"b.textContent='Повторить'")!==false&&strpos($js,"b.onclick=()=>load({preserveScroll:true})")!==false);
inboxLoadCheck('refresh error preserves already rendered data',strpos($js,'const hasData=hasRenderedData()')!==false&&strpos($js,"Показаны последние загруженные данные")!==false&&strpos($js,'setDataStale(hasData)')!==false);
inboxLoadCheck('first-load error is distinct from real zero leads',strpos($js,'Список временно недоступен')!==false&&strpos($js,'Данные не были заменены пустым результатом.')!==false);
inboxLoadCheck('auth recovery remains canonical owner of unauthorized state',strpos($js,'if(!S.authExpired){setLoadStatus')!==false&&strpos($js,'showAuthRecovery')===false);
inboxLoadCheck('mine fallback remains available only after pipeline server failure and never drops source filtering',strpos($js,"S.queue==='mine'&&Number(j?.http_status||0)>=500&&!Number(S.leadSourceFilter||0)")!==false&&strpos($js,"api('list',{queue:'mine',project_key:S.leadProjectFilter||'*'})")!==false);
inboxLoadCheck('Inbox exposes aria busy while current request is active',strpos($js,"el.setAttribute('aria-busy',busy?'true':'false')")!==false&&strpos($js,'setInboxBusy(true)')!==false&&strpos($js,'if(seq===loadSeq)setInboxBusy(false)')!==false);
inboxLoadCheck('error banner has responsive production styling',strpos($css,'.inboxLoadStatus{')!==false&&strpos($css,'.inboxLoadStatus.error{')!==false&&strpos($css,'.inboxLoadStatus button{')!==false&&strpos($css,'@media(max-width:520px){.inboxLoadStatus')!==false);
inboxLoadCheck('slice does not touch protected analytics routing or lead delivery',stripos($js.$css,'metrika')===false&&stripos($js.$css,'routing_bonus')===false&&stripos($js.$css,'set_working')===false&&strpos($js.$css,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
