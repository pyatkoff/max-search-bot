<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ProjectAccessService.php';
require_once dirname(__DIR__).'/services/TelegramDialogueConversions.php';
require_once dirname(__DIR__).'/services/MetrikaOfflineClient.php';
try{
    $args=array_slice($argv,1);$action=$args[0]??'status';
    if(count($args)>1||!in_array($action,['status','collect','send','off'],true)||ProjectConfig::projectId()!=='anytour')throw new RuntimeException('invalid_operation');
    ProjectAccessService::initializeReadOnly();$p=ConversationDb::connection();$s=TelegramDialogueConversions::settings($p);
    if(!$s)throw new RuntimeException('telegram_migration_required');
    if(in_array($action,['collect','send'],true)){
        if(!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('metrika_config_missing');
        $api=new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN);
        if(!$api->goalsReady())throw new RuntimeException('existing_goals_not_verified');
    }
    $repo=new TelegramDialogueConversions($p);
    if($action!=='status')$s=$repo->activate($action,time());
    echo json_encode(['ok'=>true,'operation'=>$action,'settings'=>$s,'queue_counts'=>$repo->counts(),'conversion_requests'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){
    $safe=['invalid_operation','telegram_migration_required','metrika_config_missing','existing_goals_not_verified'];
    echo json_encode(['ok'=>false,'reason'=>in_array($e->getMessage(),$safe,true)?$e->getMessage():'operation_failed','conversion_requests'=>0]).PHP_EOL;exit(1);
}
