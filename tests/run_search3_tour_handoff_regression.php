<?php
declare(strict_types=1);
require_once __DIR__.'/support/search3-tour-handoff.php';
$providerCalls=0;IntegrationRegistry::useMessenger(new TelegramMessengerAdapter(static function()use(&$providerCalls):bool{$providerCalls++;throw new RuntimeException('Provider forbidden in Search3 fixture');}));
try {
    $pdo=deliveryDb();search3FixtureSchema($pdo);[$secret,$keys]=search3Scenarios($pdo);
    deliveryCheck('all provider traffic remains forbidden',$providerCalls===0);
    require __DIR__.'/support/search3-tour-handoff-http.php';search3HttpScenarios($pdo,$secret,$keys);
    echo 'TOTAL SEARCH3 '.$checks.' PASS'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);}
