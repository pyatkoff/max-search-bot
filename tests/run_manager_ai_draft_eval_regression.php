<?php

declare(strict_types=1);

// Synthetic transport tests only: no production config, provider or database.
define('MANAGER_AI_DRAFT_EVAL_LIBRARY_ONLY', true);
require_once dirname(__DIR__).'/tools/manager_ai_draft_eval.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';

$passed=0; $failed=0;
function evalCheck(string $name, bool $condition): void
{
    global $passed,$failed;
    echo ($condition?'PASS  ':'FAIL  ').$name.PHP_EOL;
    $condition?$passed++:$failed++;
}
function evalCall(string $method, ...$arguments): mixed
{
    return (new ReflectionMethod(ManagerAiDraftEval::class,$method))->invoke(null,...$arguments);
}
function evalRejects(callable $operation): bool
{
    try { $operation(); return false; }
    catch (Throwable $ignored) { return true; }
}
function evalContext(): array
{
    return ['conversation_id'=>17,'as_of_message_id'=>103,'history_incomplete'=>false,
        'messages'=>[['id'=>103,'sender_type'=>'customer','text'=>'Нужен тур из Самары, на двоих.']]];
}
function evalInput(array $options, array $lease): array
{
    $cases=[];
    for ($i=1;$i<=30;$i++) $cases[]=['case_id'=>'synthetic_'.$i,'context'=>evalContext()];
    return ['schema'=>ManagerAiDraftEval::INPUT_SCHEMA,'repository'=>'pyatkoff/max-search-bot',
        'task_token'=>$options['task-token'],'production_sha'=>$options['production-sha'],
        'source_bundle_sha256'=>$options['bundle-sha256'],'lease_id'=>$lease['lease_id'],
        'output_recipient_sha256'=>$options['recipient-sha256'],
        'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>$lease['expires_at'],'cases'=>$cases];
}

if (!extension_loaded('sodium') || !function_exists('gzencode')) {
    fwrite(STDERR,"synthetic_eval_runtime_unavailable\n"); exit(1);
}
$token=bin2hex(random_bytes(24));
$options=['phase'=>'evaluate','task-token'=>$token,'production-sha'=>str_repeat('a',40),
    'bundle-sha256'=>str_repeat('b',64),'recipient-sha256'=>str_repeat('c',64)];
$arguments=['manager_ai_draft_eval.php'];
foreach ($options as $name=>$value) $arguments[]='--'.$name.'='.$value;
evalCheck('all explicit CLI options are preserved',evalCall('options',$arguments)===$options);
foreach ([
    'missing recipient binding'=>array_slice($arguments,0,-1),
    'unexpected option'=>array_merge($arguments,['--url=https://example.invalid']),
    'duplicate option'=>array_merge($arguments,['--phase=prepare']),
    'path traversal token'=>array_map(static fn($v)=>str_starts_with($v,'--task-token=')?'--task-token=../../other':$v,$arguments),
    'shell token'=>array_map(static fn($v)=>str_starts_with($v,'--task-token=')?'--task-token=$(id)':$v,$arguments),
    'unknown phase'=>array_map(static fn($v)=>$v==='--phase=evaluate'?'--phase=send':$v,$arguments),
    'nonfull source SHA'=>array_map(static fn($v)=>str_starts_with($v,'--production-sha=')?'--production-sha=abcdef':$v,$arguments),
] as $name=>$badArguments) {
    evalCheck('CLI rejects '.$name,evalRejects(static fn()=>evalCall('options',$badArguments)));
}
evalCheck('task directory is constrained to the fixed temporary prefix',
    evalCall('directory',$token)==='/tmp/max-search-bot-manager-ai-eval-'.$token);
evalCheck('directory guard rejects direct traversal',evalRejects(static fn()=>evalCall('directory','../other')));

$created=time();
$lease=['schema'=>'manager_ai_draft_eval_lease_v1','repository'=>'pyatkoff/max-search-bot',
    'task_token'=>$token,'production_sha'=>$options['production-sha'],
    'source_bundle_sha256'=>$options['bundle-sha256'],'output_recipient_sha256'=>$options['recipient-sha256'],
    'lease_id'=>bin2hex(random_bytes(16)),'created_at'=>gmdate('Y-m-d\TH:i:s\Z',$created),
    'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$created+ManagerAiDraftEval::TTL),
    'keypair'=>base64_encode(sodium_crypto_box_keypair())];
evalCheck('valid lease is accepted without provider access',!evalRejects(static fn()=>evalCall('validateLease',$lease,$options)));
foreach (['task_token','production_sha','source_bundle_sha256','output_recipient_sha256','lease_id'] as $field) {
    $badLease=$lease; $badLease[$field]='wrong';
    evalCheck('lease rejects altered '.$field,evalRejects(static fn()=>evalCall('validateLease',$badLease,$options)));
}
$badLease=$lease; $badLease['expires_at']=gmdate('Y-m-d\TH:i:s\Z',$created-1);
evalCheck('expired lease is rejected',evalRejects(static fn()=>evalCall('validateLease',$badLease,$options)));
$badLease=$lease; $badLease['expires_at']=gmdate('Y-m-d\TH:i:s\Z',$created+ManagerAiDraftEval::TTL+1);
evalCheck('lease cannot extend its fixed TTL',evalRejects(static fn()=>evalCall('validateLease',$badLease,$options)));
$badLease=$lease;
$badLease['created_at']=gmdate('Y-m-d\TH:i:s\Z',$created+120);
$badLease['expires_at']=gmdate('Y-m-d\TH:i:s\Z',$created+120+ManagerAiDraftEval::TTL);
evalCheck('future-dated lease is rejected',evalRejects(static fn()=>evalCall('validateLease',$badLease,$options)));

$input=evalInput($options,$lease);
evalCheck('exactly 30 unique synthetic cases validate',evalCall('validateInput',$input,$options,$lease)===$input['cases']);
foreach ([29,31] as $count) {
    $badInput=$input;
    $badInput['cases']=array_slice($input['cases'],0,$count);
    if ($count===31) $badInput['cases'][]=['case_id'=>'synthetic_31','context'=>evalContext()];
    evalCheck('input rejects '.$count.' cases',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
}
$badInput=$input; $badInput['cases'][1]['case_id']=$badInput['cases'][0]['case_id'];
evalCheck('duplicate case IDs are rejected',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
$badInput=$input; $badInput['cases'][0]['case_id']='case'."\n".'injected';
evalCheck('case IDs cannot inject output lines',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
$badInput=$input; $badInput['cases'][0]['extra']='unexpected';
evalCheck('unexpected per-case payload is rejected',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
foreach (['schema','repository','task_token','production_sha','source_bundle_sha256','lease_id','output_recipient_sha256'] as $field) {
    $badInput=$input; $badInput[$field]='wrong';
    evalCheck('input rejects altered '.$field,evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
}
$badInput=$input; $badInput['generated_at']=gmdate('Y-m-d\TH:i:s\Z',$created-ManagerAiDraftEval::TTL-10);
evalCheck('stale input is rejected',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
$badInput=$input; $badInput['generated_at']=gmdate('Y-m-d\TH:i:s\Z',$created+120);
evalCheck('future input is rejected',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
$badInput=$input; $badInput['expires_at']=gmdate('Y-m-d\TH:i:s\Z',$created-1);
evalCheck('expired input is rejected',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
$badInput=$input; $badInput['expires_at']=gmdate('Y-m-d\TH:i:s\Z',$created+ManagerAiDraftEval::TTL+1);
evalCheck('input expiry cannot exceed its lease',evalRejects(static fn()=>evalCall('validateInput',$badInput,$options,$lease)));
evalCheck('timestamp validation rejects impossible calendar dates',
    evalRejects(static fn()=>evalCall('timestamp','2026-02-30T12:00:00Z')));

// Exercise the actual sodium/gzip primitives used by the protected transport.
$plain=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$keypair=sodium_crypto_box_keypair();
$sealed=sodium_crypto_box_seal(gzencode($plain,9),sodium_crypto_box_publickey($keypair));
$opened=sodium_crypto_box_seal_open($sealed,$keypair);
evalCheck('sealed gzip preserves every synthetic case',is_string($opened)
    && gzdecode($opened,ManagerAiDraftEval::MAX_PLAINTEXT_BYTES)===$plain
    && strlen($sealed)<=ManagerAiDraftEval::MAX_CIPHERTEXT_BYTES);
$tampered=$sealed; $tampered[strlen($tampered)-1]=chr(ord($tampered[strlen($tampered)-1])^1);
evalCheck('ciphertext tampering fails authentication',sodium_crypto_box_seal_open($tampered,$keypair)===false);
$otherKeypair=sodium_crypto_box_keypair();
evalCheck('another prepare key cannot decrypt the input',sodium_crypto_box_seal_open($sealed,$otherKeypair)===false);
$oversized=gzencode(str_repeat('x',ManagerAiDraftEval::MAX_PLAINTEXT_BYTES+1),9);
$tooLarge=@gzdecode($oversized,ManagerAiDraftEval::MAX_PLAINTEXT_BYTES);
// zlib can return a slightly oversized buffer; the helper's explicit length
// guard is necessary even when a maximum is supplied to gzdecode().
evalCheck('decompression and explicit length guard detect oversized plaintext',
    !is_string($tooLarge) || strlen($tooLarge)>ManagerAiDraftEval::MAX_PLAINTEXT_BYTES);
sodium_memzero($keypair); sodium_memzero($otherKeypair);

// Validate the exact pure draft normalizer with no provider, config or DB.
$normalize=new ReflectionMethod(ManagerAiDraftService::class,'context');
evalCheck('every synthetic case passes the real draft context validator',
    !array_filter($input['cases'],static fn($case)=>isset($normalize->invoke(null,$case['context'])['error'])));
$invalidContext=evalContext(); $invalidContext['messages'][0]['id']=104;
evalCheck('future-message context is refused before a provider is relevant',
    isset($normalize->invoke(null,$invalidContext)['error']));

// All filesystem fixtures use fresh random tokens, never existing task paths.
$directory=evalCall('directory',$token);
$otherDirectory=sys_get_temp_dir().'/manager-ai-eval-test-target-'.bin2hex(random_bytes(12));
$symlinkToken=bin2hex(random_bytes(24));
$symlinkPath=evalCall('directory',$symlinkToken);
try {
    if (!mkdir($directory,0700) || !mkdir($otherDirectory,0700)) throw new RuntimeException('synthetic_fixture_failed');
    file_put_contents($directory.'/lease.json','{"synthetic":true}'); chmod($directory.'/lease.json',0600);
    file_put_contents($directory.'/cleanup.ready','SYNTHETIC_READY');
    $lock=fopen($directory.'/lease.json','r+');
    if ($lock===false || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('synthetic_fixture_failed');
    try {
        evalCheck('cleanup cannot delete another evaluator\'s locked lease',evalCall('cleanup',$token)===false
            && is_file($directory.'/lease.json') && is_file($directory.'/cleanup.ready'));
    } finally { flock($lock,LOCK_UN); fclose($lock); }
    evalCall('cleanup',$token);
    evalCheck('cleanup removes only its own lease and empty task directory',!file_exists($directory));
    file_put_contents($otherDirectory.'/lease.json','SYNTHETIC_UNRELATED_SENTINEL');
    if (!symlink($otherDirectory,$symlinkPath)) throw new RuntimeException('synthetic_fixture_failed');
    evalCall('cleanup',$symlinkToken);
    evalCheck('cleanup refuses a task-directory symlink',is_link($symlinkPath)
        && file_get_contents($otherDirectory.'/lease.json')==='SYNTHETIC_UNRELATED_SENTINEL');
    evalCall('cleanup',$token);
    evalCheck('cleanup of a consumed or absent lease is idempotent',!file_exists($directory));

    // The different cwd guarantees rejection before live config can be loaded.
    $command=array_merge([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',
        dirname(__DIR__).'/tools/manager_ai_draft_eval.php'],array_slice($arguments,1));
    $pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$otherDirectory);
    if (!is_resource($process)) throw new RuntimeException('synthetic_fixture_failed');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
    evalCheck('invalid production context exits without stdout or provider/config access',
        $exit===1 && $stdout==='' && $stderr==="manager_ai_eval_failed\n");
} finally {
    if (is_link($symlinkPath)) unlink($symlinkPath);
    if (is_file($otherDirectory.'/lease.json')) unlink($otherDirectory.'/lease.json');
    if (is_dir($otherDirectory)) rmdir($otherDirectory);
    if (is_file($directory.'/lease.json')) unlink($directory.'/lease.json');
    if (is_file($directory.'/cleanup.ready')) unlink($directory.'/cleanup.ready');
    if (is_dir($directory)) rmdir($directory);
}

// Exercise the standalone file through an actual HTTP SAPI. A web request must
// stop at the empty 404 guard before argument parsing, config or the provider.
$listener=stream_socket_server('tcp://127.0.0.1:0',$socketError,$socketMessage);
if ($listener===false) throw new RuntimeException('synthetic_http_fixture_failed');
$address=stream_socket_get_name($listener,false); fclose($listener);
if (!is_string($address)) throw new RuntimeException('synthetic_http_fixture_failed');
$httpPipes=[];
$httpProcess=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0','-S',$address,
    '-t',dirname(__DIR__).'/tools'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$httpPipes,
    sys_get_temp_dir());
if (!is_resource($httpProcess)) throw new RuntimeException('synthetic_http_fixture_failed');
$client=false;
try {
    fclose($httpPipes[0]);
    stream_set_blocking($httpPipes[1],false); stream_set_blocking($httpPipes[2],false);
    for ($attempt=0;$attempt<30;$attempt++) {
        $client=@stream_socket_client('tcp://'.$address,$socketError,$socketMessage,0.1);
        if (is_resource($client)) break;
        usleep(100000);
    }
    if (!is_resource($client)) throw new RuntimeException('synthetic_http_fixture_failed');
    stream_set_timeout($client,3);
    fwrite($client,"GET /manager_ai_draft_eval.php?phase=evaluate HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $response=stream_get_contents($client);
    [$httpHeaders,$httpBody]=array_pad(explode("\r\n\r\n",$response,2),2,null);
    evalCheck('standalone HTTP request is an empty 404 before main or config',
        preg_match('/\AHTTP\/1\.[01] 404\b/',$httpHeaders)===1 && $httpBody==='');
} finally {
    if (is_resource($client)) fclose($client);
    proc_terminate($httpProcess);
    foreach ([1,2] as $descriptor) if (is_resource($httpPipes[$descriptor])) fclose($httpPipes[$descriptor]);
    proc_close($httpProcess);
}
evalCheck('synthetic suite never loads production database classes',!class_exists('ConversationDb',false));
echo 'TOTAL '.($passed+$failed).' | PASS '.$passed.' | FAIL '.$failed.PHP_EOL;
exit($failed?1:0);
