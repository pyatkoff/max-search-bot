<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$file=__DIR__.'/funnel.csv';
$today=date('Y-m-d');

echo "MAX SEARCH FUNNEL — ".$today."\n\n";

if(!is_file($file)) {
    echo "Нет данных.\n";
    exit;
}

$fp=fopen($file,'rb');
$header=fgetcsv($fp);
if(!$header) exit("Нет данных.\n");

$idx=array_flip($header);
$events=[];
$unique=[];
$phones=[];
$rows=0;

while(($r=fgetcsv($fp))!==false) {
    $dt=$r[$idx['DateTime']] ?? '';
    if(strpos($dt,$today)!==0) continue;

    $event=$r[$idx['Event']] ?? '';
    $chat=$r[$idx['ChatID']] ?? '';
    if($event==='') continue;

    $events[$event]=($events[$event] ?? 0)+1;
    if($chat!=='') $unique[$chat]=true;
    if($event==='phone_received' && $chat!=='') $phones[$chat]=true;
    $rows++;
}
fclose($fp);

$order=[
    'bot_started',
    'ai_text',
    'step_started',
    'search_ready',
    'show_tours',
    'site_open',
    'followup_sent',
    'manager_request',
    'phone_received',
    'tours_found',
    'channel_click'
];

echo "Уникальных пользователей: ".count($unique)."\n";
echo "Событий: ".$rows."\n\n";

foreach($order as $e) {
    echo str_pad($e,24).' '.(int)($events[$e] ?? 0)."\n";
}

echo "\nУникальных телефонов/лидов: ".count($phones)."\n";
