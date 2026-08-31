<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/RuntimeBootstrap.php';
RuntimeBootstrap::boot((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));

header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__.'/metrika_offline_queue.csv';
if(!is_file($file)) {
	echo "Очередь пока пуста\n";
	exit;
}
readfile($file);
