<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $s):string=>'');$ok=false;
try{
    if(count($argv)!==3||$argv[1]!=='check'||preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$argv[2])!==1)throw new InvalidArgumentException();
    $checkpoint=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$argv[2],new DateTimeZone('UTC'));
    if(!$checkpoint||$checkpoint->format('Y-m-d\TH:i:s\Z')!==$argv[2])throw new InvalidArgumentException();
    require dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectAccessService.php';
    require_once dirname(__DIR__).'/services/MetrikaOfflineClient.php';
    require_once dirname(__DIR__).'/services/ModeledConversionVisitProof.php';
    if(ProjectConfig::projectId()!=='anytour'||!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException();
    ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $q=$pdo->query("SELECT goal_id,goal_intent,contract_version,activated_at_unix FROM modeled_conversion_value_export_settings WHERE project_key='anytour' AND counter_id='98615635' LIMIT 1");
    $settings=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(($settings['goal_id']??null)!=='678187097'||($settings['goal_intent']??null)!=='verified'||($settings['contract_version']??null)!==ModeledConversionValueContract::VERSION)throw new RuntimeException();
    $receipt=ModeledConversionVisitProof::verify(ModeledConversionVisitProof::ledger($pdo),
        new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN),(int)$settings['activated_at_unix'],$checkpoint->getTimestamp(),time());
    ModeledConversionVisitProof::save($receipt);$ok=true;
}catch(Throwable $ignored){}
while(ob_get_level()>$level)ob_end_clean();
echo json_encode(['ok'=>$ok,'private_verification_saved'=>$ok,'provider_write_requests'=>false,'source_writes'=>false],JSON_THROW_ON_ERROR)."\n";
exit($ok?0:1);
