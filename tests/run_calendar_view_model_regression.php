<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/CalendarViewModel.php';

$passed=0;$failed=0;
function cvmCheck(string $name,$actual,$expected): void {
    global $passed,$failed;
    if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$today=new DateTimeImmutable('2026-08-23');
$model=CalendarViewModel::build(8,2026,$today);
cvmCheck('current title',$model['title'],'Август 2026');
cvmCheck('previous payload',$model['previous'],'07.2026');
cvmCheck('next payload',$model['next'],'09.2026');
$buttons=CalendarViewModel::buttons($model);
cvmCheck('back payload',$buttons[count($buttons)-1][0]['callback_data'],'back_nights');

$dates=[];$dateLabels=[];$payloads=[];$maxRow=0;
foreach($buttons as $row){
    $maxRow=max($maxRow,count($row));
    foreach($row as $button){
        $payload=(string)($button['callback_data']??'');
        $payloads[]=$payload;
        if(strpos($payload,'pick_date_')===0){$dates[]=$payload;$dateLabels[$payload]=(string)($button['text']??'');}
    }
}
cvmCheck('today selectable',in_array('pick_date_23.08.2026',$dates,true),true);
cvmCheck('past day hidden',in_array('pick_date_22.08.2026',$dates,true),false);
cvmCheck('current month exposes only selectable dates',count($dates),9);
cvmCheck('date button shows day and month',$dateLabels['pick_date_23.08.2026']??null,'23.08');
cvmCheck('calendar rows stay compact for messenger',($maxRow<=4),true);
cvmCheck('dead month title callback removed',in_array('month_click',$payloads,true),false);
cvmCheck('dead weekday callback removed',in_array('day_click',$payloads,true),false);
cvmCheck('dead empty-cell callback removed',in_array('empty',$payloads,true),false);
$allActionable=true;
foreach($payloads as $payload){
    if($payload==='back_nights'||strpos($payload,'pick_date_')===0||strpos($payload,'month_change_')===0)continue;
    $allActionable=false;break;
}
cvmCheck('every rendered calendar callback is actionable',$allActionable,true);

$past=CalendarViewModel::build(7,2026,$today);
cvmCheck('past month clamps to current',$past['title'],'Август 2026');
$future=CalendarViewModel::build(9,2026,$today);
cvmCheck('future title',$future['title'],'Сентябрь 2026');
$futureButtons=CalendarViewModel::buttons($future);
cvmCheck('future previous',$futureButtons[count($futureButtons)-2][0]['callback_data'],'month_change_08.2026');
cvmCheck('future next',$futureButtons[count($futureButtons)-2][1]['callback_data'],'month_change_10.2026');

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed>0?1:0);
