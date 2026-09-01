<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/routing.php');
$js=(string)file_get_contents($root.'/manager/assets/routing.js');
$css=(string)file_get_contents($root.'/manager/assets/routing.css');
$passed=0;$failed=0;
function checkRouting(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  $name\n";$passed++;}else{echo "FAIL  $name\n";$failed++;}}
checkRouting('routing shell loads extracted css',strpos($page,'assets/routing.css?v=')!==false&&strpos($page,'<style>')===false);
checkRouting('routing shell loads extracted js',strpos($page,'assets/routing.js?v=')!==false&&strpos($page,'const S=')===false);
checkRouting('source save validation remains in routing module',strpos($js,"missing_source_key")!==false&&strpos($js,"duplicate_source_key")!==false&&strpos($js,"fallback_group_required")!==false);
checkRouting('routing API actions remain unchanged',strpos($js,"api('routing_snapshot'")!==false&&strpos($js,"api('save_group'")!==false&&strpos($js,"api('save_source'")!==false);
checkRouting('group save has inline status surface',strpos($page,'id="groupStatus"')!==false&&strpos($page,'aria-live="polite"')!==false&&strpos($js,"groupStatus('Группа сохранена.','success')")!==false);
checkRouting('group save preserves form on transport failure',strpos($js,"try{j=await api('save_group'")!==false&&strpos($js,"j={ok:false,error:'network_error'}")!==false&&strpos($js,'Данные формы сохранены — повторите попытку.')!==false);
checkRouting('source save recovers button after transport failure',strpos($js,"try{j=await api('save_source'")!==false&&substr_count($js,'btn.disabled=false;btn.textContent=oldText')>=2&&strpos($js,'sourceErrorText(j.error)')!==false);
checkRouting('routing save buttons reject duplicate submits',substr_count($js,'if(btn.disabled)return')>=2);
checkRouting('responsive routing styles remain extracted',strpos($css,'grid-template-columns:1fr 1fr')!==false&&strpos($css,'@media(max-width:700px){.grid{grid-template-columns:1fr}')!==false&&strpos($css,'.wrap{padding:12px}')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS $passed | FAIL $failed\n";
exit($failed?1:0);
