<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/services/AdvertisingSignalRepository.php';
try {
    $command=$argv[1]??'status';
    if (!in_array($command,['status','preview'],true)) throw new InvalidArgumentException('advertising_signal_command');
    $scope=[];$after='0';$limit=100;$options=[];
    foreach (array_slice($argv,2) as $arg) {
        if (!preg_match('/\A--(project_key|source_key|platform|counter_id|after_id|limit)=(.+)\z/D',$arg,$m) || isset($options[$m[1]])) throw new InvalidArgumentException('advertising_signal_argument');
        $options[$m[1]]=$m[2];
    }
    foreach (['project_key','source_key','platform','counter_id'] as $key) if (isset($options[$key])) $scope[$key]=$options[$key];
    $scope=AdvertisingVisitContract::scope($scope);
    if (isset($options['after_id'])) $after=$options['after_id'];
    if (isset($options['limit'])) { if (!preg_match('/\A[1-9][0-9]{0,2}\z/D',$options['limit'])) throw new InvalidArgumentException('advertising_signal_argument');$limit=(int)$options['limit']; }
    if ($scope['platform']!=='telegram' || !preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$after) || $limit>100 || ($command==='status' && (isset($options['after_id'])||isset($options['limit'])))) throw new InvalidArgumentException('advertising_signal_argument');
    $runtime=dirname(__DIR__).'/config.php';
    if (!is_readable($runtime)) throw new InvalidArgumentException('advertising_signal_runtime_unavailable');
    require_once $runtime;
    require_once dirname(__DIR__).'/services/ConversationDb.php';
    require_once dirname(__DIR__).'/services/ProjectConfig.php';
    if (ProjectConfig::projectId()!==$scope['project_key']) throw new InvalidArgumentException('advertising_signal_project');
    $repo=new AdvertisingSignalRepository(ConversationDb::connection(),$scope);
    $result=['status'=>'observed','database_writes'=>false,'conversion_uploads'=>0];
    if ($command==='status') $result['signals']=$repo->counts();
    else {
        $rows=$repo->preview($after,$limit,time());$counts=['missing'=>0,'unique'=>0,'ambiguous'=>0];
        foreach ($rows as $row) $counts[$row['identity_status']]++;
        $result+=['signals_read'=>count($rows),'next_id'=>$rows?$rows[count($rows)-1]['id']:$after,'identity'=>$counts,'assessment'=>'provisional_unique_client_v1'];
    }
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    $error=$e instanceof InvalidArgumentException && preg_match('/\Aadvertising_signal_[a-z_]+\z/D',$e->getMessage()) ? $e->getMessage() : 'advertising_signal_failed';
    fwrite(STDERR,json_encode(['status'=>'failed','error'=>$error]).PHP_EOL);exit(1);
}
