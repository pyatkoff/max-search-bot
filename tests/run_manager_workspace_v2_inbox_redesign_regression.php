<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$filters=(string)file_get_contents($root.'/manager/assets/workspace-v2-filters.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$passed=0;$failed=0;
function inboxCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function inboxAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}

inboxCheck('workspace loads dedicated inbox stylesheet',inboxAssetLoaded($page,'workspace-v2-inbox.css'));
inboxCheck('workspace loads dedicated filter behavior module',inboxAssetLoaded($page,'workspace-v2-filters.js'));
inboxCheck('search is primary and advanced filters are collapsible',strpos($page,'id="leadSearch"')!==false&&strpos($page,'id="filtersToggle"')!==false&&strpos($page,'id="filtersPanel" class="filtersPanel hidden"')!==false);
inboxCheck('advanced filters preserve stage tag outcome and task semantics',strpos($page,'id="leadStageFilter"')!==false&&strpos($page,'id="leadTagFilter"')!==false&&strpos($page,'id="leadOutcomeFilter"')!==false&&strpos($page,'id="leadTaskFilter"')!==false);
inboxCheck('filter badge and reset are explicit',strpos($page,'id="filtersCount"')!==false&&strpos($page,'id="clearFilters"')!==false&&strpos($filters,'activeFilterCount')!==false&&strpos($filters,"S.leadTaskFilter=''")!==false);
inboxCheck('lead cards use compact identity trip preview action hierarchy',strpos($js,'leadPrimary')!==false&&strpos($js,'leadAvatar')!==false&&strpos($js,'leadTrip')!==false&&strpos($js,'leadPreview')!==false&&strpos($js,'leadActionRow')!==false);
inboxCheck('lead cards keep urgency unread stage outcome and task evidence',strpos($js,'unreadBadge')!==false&&strpos($js,'leadStageCompact')!==false&&strpos($js,'leadTaskCompact')!==false&&strpos($js,'leadWaitCompact')!==false&&strpos($js,'leadOutcomeCompact')!==false);
inboxCheck('lead cards expose last activity without another backend projection',strpos($js,'function formatLastActivity')!==false&&strpos($js,"c.last_message_at||c.started_at||''")!==false&&strpos($js,'leadActivityTime')!==false&&strpos($js,'Последняя активность')!==false);
inboxCheck('activity formatting is timezone-neutral and compact',strpos($js,'`${m[3]}.${m[2]} · ${m[4]}:${m[5]}`')!==false&&strpos($js,"new Date(value.replace(' ','T')")!==false);
inboxCheck('opened conversations can clear their local unread badge without reloading the list',strpos($js,'function markRead(id=S.current)')!==false&&strpos($js,"el.querySelector('.unreadBadge')?.remove()")!==false&&strpos($js,'markRead,waitUrgency')!==false);
inboxCheck('ordinary open outcome is not rendered as permanent visual noise',strpos($js,"outcome==='won'||outcome==='lost'")!==false);
inboxCheck('inbox refresh still preserves scroll after stability fix',strpos($js,'scrollTop=box.scrollTop')!==false&&strpos($js,'if(preserveScroll)box.scrollTop=scrollTop')!==false);
inboxCheck('mobile inbox has dedicated compact treatment',strpos($css,'@media(max-width:520px)')!==false&&strpos($css,'.inboxSearchRow')!==false&&strpos($css,'.filtersToggle:before')!==false&&strpos($css,'.leadPrimary')!==false);
inboxCheck('redesign remains presentation only',stripos($js.$filters.$pipeline.$css,'set_working')===false&&stripos($js.$filters.$pipeline.$css,'metrika')===false&&stripos($js.$filters.$pipeline.$css,'yclid')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);