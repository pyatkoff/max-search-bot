<?php

declare(strict_types=1);

if (!class_exists('MaxSearchApi')) {
    class MaxSearchApi {}
}

require_once __DIR__ . '/../actions/callbacks/ToursCallbackAction.php';

$passed=0;$failed=0;
function liveToursCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$source=(string)file_get_contents(__DIR__.'/../actions/callbacks/ToursCallbackAction.php');
liveToursCheck('live conversation 492 four-second repeat is suppressed',ToursCallbackAction::isDuplicateShowTours('show_tours',100.0,'show_tours',104.0));
liveToursCheck('live conversation 492 fifty-six-second retry remains allowed',!ToursCallbackAction::isDuplicateShowTours('show_tours',100.0,'show_tours',156.0));
liveToursCheck('different tours callback is not suppressed',!ToursCallbackAction::isDuplicateShowTours('show_tours',100.0,'tours_checked',104.0));
liveToursCheck('show tours uses shared interaction serialization',strpos($source,"InteractionGuard::synchronized(\$chatId, 'tours_show'")!==false);
liveToursCheck('duplicate show tours is observable',strpos($source,"reportSuppressed(\$chatId, \$q, 'duplicate', null, null, 'tours_show')")!==false&&strpos($source,'DUPLICATE_SHOW_TOURS_CALLBACK_SKIPPED')!==false);
liveToursCheck('guard state is persisted before outbound side effect',strpos($source,'fwrite($fp')!==false&&strpos($source,'MaxSearchApi::showToursChoice')!==false&&strpos($source,'fwrite($fp')<strpos($source,'MaxSearchApi::showToursChoice'));
liveToursCheck('finish callbacks keep existing behavior outside duplicate guard',strpos($source,"if (strpos(\$q, 'finish') === 0)")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
