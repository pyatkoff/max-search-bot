<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ProjectAccessService.php';
require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpDiagnostics.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';
require_once dirname(__DIR__).'/services/VirtualReplyProtocol.php';
/** Only fixed public metadata and a deliberately invalid token are requested. */
function preparationSmokeRequest(string $url, ?string $post = null): array
{
    $headers=[];
    $handle=curl_init($url);
    curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'AnyTour-MCP-Header-Probe/1.0',
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$headers):int {
            $parts=explode(':',$line,2);
            if (count($parts)===2) $headers[strtolower(trim($parts[0]))]=trim($parts[1]);
            return strlen($line);
        }]);
    if ($post!==null) curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.str_repeat('S',43)]]);
    $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);$error=curl_errno($handle);
    curl_close($handle);
    return ['status'=>$status,'headers'=>$headers,'body'=>is_string($body)?json_decode($body,true):null,'curl_error'=>$error];
}

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
    $pdo->commit();
    $surfaces=[
        'legacy'=>['profile'=>ManagerPreparationOAuth::PROFILE_LEGACY,'scope'=>'queue:read prepared:write',
            'issuer'=>ManagerPreparationOAuth::ISSUER,'resource'=>ManagerPreparationOAuth::RESOURCE],
        'development'=>['profile'=>ManagerPreparationOAuth::PROFILE_DEVELOPMENT,'scope'=>ManagerPreparationOAuth::DEVELOPMENT_SCOPE,
            'issuer'=>ManagerPreparationOAuth::DEVELOPMENT_ISSUER,'resource'=>ManagerPreparationOAuth::DEVELOPMENT_RESOURCE],
    ];
    $ingress=[];
    foreach ($surfaces as $surface=>$definition) {
        $resource=ManagerPreparationOAuth::resourceMetadata($definition['profile']);
        $auth=ManagerPreparationOAuth::authorizationMetadata($definition['profile']);
        if (($resource['authorization_servers']??null)!==[$definition['issuer']]
            ||($auth['issuer']??null)!==$definition['issuer']||($resource['resource']??null)!==$definition['resource']) {
            throw new RuntimeException('metadata_mismatch');
        }
        $metadataUrl=$auth['issuer'].'?action=resource';
        $issuerPath=parse_url($auth['issuer'],PHP_URL_PATH);
        $discoveryUrl=ManagerPreparationOAuth::ORIGIN.'/.well-known/oauth-authorization-server'.$issuerPath;
        foreach ([$metadataUrl=>$resource,$auth['issuer'].'?action=metadata'=>$auth,$discoveryUrl=>$auth] as $url=>$expected) {
            $response=preparationSmokeRequest($url);
            if ($response['curl_error']!==0||$response['status']!==200||isset($response['headers']['location'])
                ||$response['body']!==$expected) throw new RuntimeException('mcp_public_metadata_failed');
        }
        // A canonical invalid bearer proves header delivery without creating a grant.
        $started=time();
        $response=preparationSmokeRequest($resource['resource'],'{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        $expectedChallenge='Bearer resource_metadata="'.$metadataUrl.'", scope="'.$definition['scope'].'"';
        if ($response['curl_error']!==0||$response['status']!==401||($response['body']['error']['code']??null)!==-32001
            ||isset($response['headers']['location'])||($response['headers']['www-authenticate']??null)!==$expectedChallenge) {
            throw new RuntimeException('mcp_header_probe_unexpected_response');
        }
        if (!ManagerPreparationMcpDiagnostics::headerProbeDelivered(ManagerPreparationMcpDiagnostics::snapshot(),$started,$surface)) {
            throw new RuntimeException('mcp_header_not_delivered');
        }
        $ingress[$surface]=['metadata_verified'=>true,'bearer_header_delivered'=>true,'invalid_bearer_rejected'=>true];
    }
    echo json_encode(['ok'=>true,'schema_tables'=>count($tables),'mcp_bearer_header_delivered'=>true,
        'invalid_bearer_rejected'=>true,'mcp_surfaces'=>$ingress,'provider_calls'=>0,'database_writes'=>0,'consultation_contract'=>$consultation],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $ignored){
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    $reason=in_array($ignored->getMessage(),['mcp_header_not_delivered','mcp_header_probe_unexpected_response','mcp_public_metadata_failed'],true)
        ?$ignored->getMessage():'manager_preparation_smoke_failed';
    fwrite(STDERR,$reason."\n");exit(1);
}
