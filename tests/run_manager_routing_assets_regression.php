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
checkRouting('responsive routing styles remain extracted',strpos($css,'@media(max-width:700px)')!==false&&strpos($css,'.grid{grid-template-columns:1fr 1fr')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS $passed | FAIL $failed\n";
exit($failed?1:0);
