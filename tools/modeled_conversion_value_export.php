<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ProjectAccessService.php';
require_once dirname(__DIR__).'/services/ModeledConversionValueExport.php';
try{
    $op=$argv[1]??'status';if(count($argv)!==2||!in_array($op,['status','create-goal','enable','disable','launch-v1'],true))throw new RuntimeException('invalid_operation');
    if(ProjectConfig::projectId()!=='anytour'||!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'
        ||!defined('METRIKA_OAUTH_TOKEN')||(string)METRIKA_OAUTH_TOKEN==='')throw new RuntimeException('value_config_unavailable');
    ProjectAccessService::initializeReadOnly();$p=ConversationDb::connection();$s=ModeledConversionValueExport::settings($p);
    if(!$s)throw new RuntimeException('value_migration_unavailable');
    $api=new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN);
    // Only technical goal/configuration/delivery state leaves the server.
    echo json_encode((new ModeledConversionValueExport($p))->configure($op,$api,time()),JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
    if(isset($p)&&$p->inTransaction())$p->rollBack();
    $allowed=['invalid_operation','value_config_unavailable','value_migration_unavailable','value_off_required','value_goal_reconciliation_required',
        'value_configuration_changed','value_goal_not_verified','modeled_goal_conflict','modeled_goal_create_unknown','modeled_goal_readback_conflict','goals_read_failed'];
    echo json_encode(['ok'=>false,'reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'value_operation_failed']).PHP_EOL;exit(1);
}
