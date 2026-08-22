<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__.'/metrika_offline_queue.csv';
if(!is_file($file)) {
	echo "Очередь пока пуста\n";
	exit;
}
readfile($file);
