<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/architecture_inventory.php').' --compact';
exec($cmd,$lines,$code);
if($code!==0)throw new RuntimeException('architecture_inventory_failed');
$data=json_decode(implode("\n",$lines),true);
if(!is_array($data)||empty($data['ok']))throw new RuntimeException('architecture_inventory_not_ok');
if(($data['schema_version']??null)!==1)throw new RuntimeException('architecture_inventory_schema');
foreach(['handlers','actions','services','integrations','manager','website','cron','migrations','tests','tools'] as $area){
    if(!isset($data['areas'][$area]))throw new RuntimeException('missing_area_'.$area);
    foreach(['files','bytes','code_lines'] as $metric)if(!array_key_exists($metric,$data['areas'][$area]))throw new RuntimeException('missing_metric_'.$area.'_'.$metric);
}
foreach(['runtime_ddl','schema_infrastructure_ddl','direct_sql_writes','authorization_mentions','validation_mentions'] as $signal){
    if(!isset($data['signals'][$signal])||!is_array($data['signals'][$signal]))throw new RuntimeException('missing_signal_'.$signal);
}
if(!is_array($data['hotspots']??null))throw new RuntimeException('missing_hotspots');
foreach($data['hotspots'] as $hotspot){
    foreach(['path','lines','bytes','severity'] as $key)if(!array_key_exists($key,$hotspot))throw new RuntimeException('hotspot_contract_'.$key);
}
if(in_array('migrations/001_conversations.sql',$data['signals']['runtime_ddl']??[],true))throw new RuntimeException('migrations_must_not_be_runtime_ddl');
if(in_array('services/MigrationRunner.php',$data['signals']['runtime_ddl']??[],true))throw new RuntimeException('migration_runner_must_not_be_business_runtime_ddl');
if(!in_array('services/MigrationRunner.php',$data['signals']['schema_infrastructure_ddl']??[],true))throw new RuntimeException('migration_runner_schema_infrastructure_missing');
foreach(['services/WebsiteSessionService.php','services/LeadTaskService.php','services/ClaimRepository.php'] as $path){
    if(in_array($path,$data['signals']['runtime_ddl']??[],true))throw new RuntimeException('unexpected_runtime_ddl_'.$path);
}

echo "ARCHITECTURE INVENTORY REGRESSION PASSED\n";
