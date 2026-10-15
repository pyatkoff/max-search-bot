<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/MetrikaOfflineClient.php';
try{
    $action=$argv[1]??'status';if(count($argv)>2||!in_array($action,['status','apply'],true))throw new RuntimeException('invalid_operation');
    $settings=MaxChannelConversionOutbox::settings();
    if(!$settings||$settings['project_key']!=='anytour'||$settings['mode']==='send')throw new RuntimeException('collect_or_off_required');
    if(!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('metrika_config_missing');
    $api=new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN);
    $result=$api->provisionChannelGoals($action==='apply');
    echo json_encode(['ok'=>true,'operation'=>$action]+$result,JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){
    $allowed=['invalid_operation','collect_or_off_required','metrika_config_missing','wrong_goal_counter','ambiguous_channel_goal','conflicting_channel_goal','goal_creation_requires_readback','existing_goal_changed','goals_read_failed'];
    echo json_encode(['ok'=>false,'reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'goal_operation_failed','conversion_requests'=>0])."\n";exit(1);
}
