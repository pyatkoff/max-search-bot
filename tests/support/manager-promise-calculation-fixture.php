<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/manager-promise-selection-fixture.php';
require_once dirname(__DIR__,2).'/services/ManagerPromiseCalculationService.php';
require_once dirname(__DIR__,2).'/services/ManagerSendAttemptService.php';

function promiseCalculationSchema(PDO $pdo,bool $mysql=false): void
{
    $sql=(string)file_get_contents(dirname(__DIR__,2).'/migrations/110_manager_promise_calculations.sql');
    if($mysql){$pdo->exec($sql);return;}
    // Apply the real column/constraint inventory; only dialect syntax changes.
    $sql=preg_replace('/^--.*$/m','',$sql);
    $sql=preg_replace('/\s+CHARACTER SET \w+\s+COLLATE \w+/i','',$sql);
    $sql=preg_replace('/\b(?:BIGINT|INT|TINYINT)\s+UNSIGNED\b/i','INTEGER',$sql);
    $sql=preg_replace('/\b(?:MEDIUMTEXT|LONGTEXT)\b/i','TEXT',$sql);
    $sql=preg_replace('/^\s*KEY\s+\w+\s*\([^\n]+\),?\n/m','',$sql);
    $sql=preg_replace('/UNIQUE KEY\s+\w+\s*\(/i','UNIQUE (',$sql);
    $sql=preg_replace('/\)\s*ENGINE=InnoDB[^;]*;/i',');',$sql);
    $pdo->exec($sql);
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_send_attempts(manager_id INTEGER NOT NULL,operation_id TEXT NOT NULL,conversation_id INTEGER NOT NULL,context_hash TEXT NOT NULL,request_hash TEXT,state TEXT NOT NULL,result_json TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,operation_kind TEXT NOT NULL DEFAULT \'text\',PRIMARY KEY(manager_id,operation_id))');
}

function promiseCalculationFixture(?string $databasePath=null,string $channel='telegram'): PDO
{
    $pdo=promiseSelectionFixture(null,$channel);
    $pdo->exec("DELETE FROM manager_task_promise_operations; DELETE FROM manager_task_promises;
UPDATE messages SET text='Пришлю расчёт по согласованной поездке.' WHERE id=12;
UPDATE lead_tasks SET title='Отправить расчёт по поездке' WHERE id=101;");
    promiseCalculationSchema($pdo);
    $context=ManagerPromiseTaskService::context(7,1,101,12,'send_calculation');
    if(empty($context['ok']))throw new RuntimeException('calculation_context: '.json_encode($context));
    $r=ManagerPromiseTaskService::bind(7,1,['operation_id'=>str_repeat('c',32),'task_id'=>101,'kind'=>'send_calculation',
        'trip_id'=>$context['profile']['trip_id'],'profile_version'=>$context['profile']['version'],'profile_sha256'=>$context['profile']['sha256'],
        'profile_context_sha256'=>$context['profile']['context_sha256'],'source_message_id'=>12,'source_message_revision'=>$context['source']['revision'],'deadline'=>null]);
    if(empty($r['ok']))throw new RuntimeException('calculation_bind: '.json_encode($r));
    if($databasePath!==null){
        if(is_file($databasePath))throw new RuntimeException('calculation_export_requires_new_path');
        $pdo->exec('VACUUM INTO '.$pdo->quote($databasePath));
        $pdo=new PDO('sqlite:'.$databasePath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');promiseSelectionSqlFunctions($pdo);
        $pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    }
    return $pdo;
}

function promiseCalculationIds(PDO $pdo): array
{
    return ['promise_id'=>(string)$pdo->query('SELECT promise_id FROM manager_task_promises WHERE task_id=101')->fetchColumn(),'task_id'=>101];
}

function promiseCalculationArm(PDO $pdo,array $composition,?string $operation=null): array
{
    $ids=promiseCalculationIds($pdo);$preview=ManagerPromiseCalculationService::preview(7,1,101,$ids['promise_id'],$composition);
    if(empty($preview['ok']))throw new RuntimeException('calculation_preview: '.json_encode($preview));
    return ManagerPromiseCalculationService::arm(7,1,$ids+['operation_id'=>$operation??bin2hex(random_bytes(16)),'composition'=>$composition,'preview_sha256'=>$preview['preview_sha256']]);
}

/** A synthetic successful response from the actual adapter call, not an HTTP input. */
function promiseCalculationNative(PDO $pdo,string $text,string $mid='7001',?array $attachment=null): array
{
    $c=ManagerConversationService::visibleConversation(1,7);
    if(!$c)throw new RuntimeException('calculation_fixture_scope');
    $metadata=['project_key'=>'anytour',ManagerReplyQueueService::SEND_BOUNDARY=>ManagerReplyQueueService::captureSendBoundary(1)];
    if($attachment!==null)$metadata['attachments']=[$attachment];
    $stored=htmlspecialchars(trim($text),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    if($attachment!==null&&$stored==='')$stored=ConversationRecorder::attachmentPreview([['type'=>$attachment['type']]]);
    return ['channel'=>$c['channel'],'native_mid'=>$mid,'identity'=>ManagerPromiseCalculationService::senderIdentity($c),'text'=>$stored,'metadata'=>$metadata,'initial_ai_text'=>null];
}
