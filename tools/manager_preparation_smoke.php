<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ProjectAccessService.php';
require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';
$pdo=null;
try{
    $pdo=ConversationDb::connection();
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    ProjectAccessService::initializeReadOnly();
    $tables=['manager_prepared_replies','manager_preparation_oauth_clients','manager_preparation_oauth_grants','manager_preparation_oauth_codes','manager_preparation_oauth_tokens','manager_preparation_events'];
    foreach($tables as $table)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $resource=ManagerPreparationOAuth::resourceMetadata();$auth=ManagerPreparationOAuth::authorizationMetadata();
    if(($resource['authorization_servers']??null)!==[$auth['issuer']]||($resource['resource']??null)!==ManagerPreparationOAuth::RESOURCE)throw new RuntimeException('metadata_mismatch');
    $pdo->commit();
    echo json_encode(['ok'=>true,'schema_tables'=>count($tables),'provider_calls'=>0,'database_writes'=>0],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $ignored){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"manager_preparation_smoke_failed\n");exit(1);}
