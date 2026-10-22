<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ProjectAccessService.php';
require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpDiagnostics.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';
require_once dirname(__DIR__).'/services/VirtualReplyProtocol.php';
$pdo=null;
try{
    // Pure installed-code check: no model invocation, source read or instruction text in logs.
    $policy=TourConsultationPolicy::instructions();
    $consumers=[ManagerAiDraftService::instructions(),VirtualReplyProtocol::instructions('author'),VirtualReplyProtocol::instructions('reviewer')];
    foreach($consumers as $instruction)if(substr_count($instruction,$policy)!==1)throw new RuntimeException('consultation_contract_mismatch');
    $consultation=['policy_sha256'=>hash('sha256',$policy),'author_revision'=>VirtualReplyProtocol::revision('author'),
        'reviewer_revision'=>VirtualReplyProtocol::revision('reviewer'),'consumers'=>count($consumers)];
    $pdo=ConversationDb::connection();
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    ProjectAccessService::initializeReadOnly();
    $tables=['manager_prepared_replies','manager_preparation_oauth_clients','manager_preparation_oauth_grants','manager_preparation_oauth_codes','manager_preparation_oauth_tokens','manager_preparation_events'];
    foreach($tables as $table)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $resource=ManagerPreparationOAuth::resourceMetadata();$auth=ManagerPreparationOAuth::authorizationMetadata();
    if(($resource['authorization_servers']??null)!==[$auth['issuer']]||($resource['resource']??null)!==ManagerPreparationOAuth::RESOURCE)throw new RuntimeException('metadata_mismatch');
    $pdo->commit();
    // Exercise the production host's own HTTPS ingress, independently of an
    // external debugging proxy. This known-invalid token must never authorize.
    $started=time();$h=curl_init(ManagerPreparationOAuth::RESOURCE);
    curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.str_repeat('S',43)],
        CURLOPT_USERAGENT=>'AnyTour-MCP-Header-Probe/1.0',CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);
    $reply=is_string($body)?json_decode($body,true):null;
    if ($status!==401||($reply['error']['code']??null)!==-32001) throw new RuntimeException('mcp_header_probe_unexpected_response');
    $delivered=false;
    foreach (ManagerPreparationMcpDiagnostics::snapshot()['recent_attempts'] as $attempt) {
        if (($attempt['client_kind']??'')==='header_probe'&&strtotime($attempt['at'])>=$started
            &&$attempt['credential_header']==='bearer'&&$attempt['http_status']===401&&$attempt['stage']==='credentials') {$delivered=true;break;}
    }
    if (!$delivered) throw new RuntimeException('mcp_header_not_delivered');
    echo json_encode(['ok'=>true,'schema_tables'=>count($tables),'mcp_bearer_header_delivered'=>true,
        'invalid_bearer_rejected'=>true,'provider_calls'=>0,'database_writes'=>0,'consultation_contract'=>$consultation],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $ignored){
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    $reason=in_array($ignored->getMessage(),['mcp_header_not_delivered','mcp_header_probe_unexpected_response'],true)
        ?$ignored->getMessage():'manager_preparation_smoke_failed';
    fwrite(STDERR,$reason."\n");exit(1);
}
