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
cvmCheck('month payload',$buttons[0][0]['callback_data'],'month_click');
cvmCheck('back payload',$buttons[count($buttons)-1][0]['callback_data'],'back_nights');

$dates=[];
foreach($buttons as $row){foreach($row as $button){if(strpos((string)($button['callback_data']??''),'pick_date_')===0)$dates[]=$button['callback_data'];}}
cvmCheck('today selectable',in_array('pick_date_23.08.2026',$dates,true),true);
cvmCheck('past day hidden',in_array('pick_date_22.08.2026',$dates,true),false);

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
