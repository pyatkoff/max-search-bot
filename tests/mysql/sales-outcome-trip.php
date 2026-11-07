<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

/** Called by the existing disposable MySQL suite after migration 080. */
function nativeOutcomeTripMigrationChecks(PDO $pdo): void
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->inTransaction()
        ||preg_match('/\Amaxbot_prepared_test(?:_[a-zA-Z0-9_]+)?\z/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn())!==1)
        throw new RuntimeException('unsafe_outcome_trip_fixture_database');
    $migration=file_get_contents(dirname(__DIR__,2).'/migrations/094_lead_outcome_trip_bindings.sql');
    $profilesBefore=$pdo->query('SELECT * FROM manager_request_profiles ORDER BY conversation_id,version')->fetchAll();
    $conversationsBefore=$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll();
    $pdo->exec($migration);
    if((int)$pdo->query('SELECT COUNT(*) FROM lead_outcome_trip_bindings')->fetchColumn()!==0
        ||$pdo->query('SELECT * FROM manager_request_profiles ORDER BY conversation_id,version')->fetchAll()!==$profilesBefore
        ||$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll()!==$conversationsBefore)
        throw new RuntimeException('native_outcome_trip_migration_backfilled_history');
    echo "PASS native MySQL094 creates empty binding history without rewriting trips or CRM\n";

    $pdo->exec('INSERT INTO conversations(id) VALUES(940),(941)');
    $pdo->exec('INSERT INTO managers(id) VALUES(2)');
    $profileJson=json_encode(['schema_version'=>1,'label'=>'Синтетическая поездка 🧳','fields'=>[]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $profileHash=hash('sha256',$profileJson);
    $profileInsert=$pdo->prepare('INSERT INTO manager_request_profiles(conversation_id,version,trip_id,boundary_message_id,manager_id,operation_id,request_sha256,context_sha256,profile_json,profile_sha256,changed_fields_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach([[940,1,'1'],[941,2,'2']]as[$cid,$version,$digit])
        $profileInsert->execute([$cid,$version,str_repeat($digit,32),2,1,str_repeat('9',31).$digit,str_repeat('a',64),str_repeat('b',64),$profileJson,$profileHash,'[]',gmdate('Y-m-d H:i:s')]);
    $outcome=json_encode(['outcome'=>'won','sale_amount'=>'250000.00','note'=>"Синтетический исход 🧳 e\u{0301}\nБез оплаты."],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $scope=json_encode(['project_key'=>'anytour','channel'=>'max'],JSON_THROW_ON_ERROR);
    $insert=$pdo->prepare('INSERT INTO lead_outcome_trip_bindings(conversation_id,manager_id,operation_id,request_sha256,trip_id,profile_version,profile_sha256,outcome_json,outcome_sha256,scope_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $values=static fn(int $cid,int $actor,string $op,?string $trip,?int $version):array=>[
        $cid,$actor,$op,hash('sha256',$cid.':'.$actor.':'.$op),$trip,$version,$version===null?null:$profileHash,
        $outcome,hash('sha256',$outcome),$scope,gmdate('Y-m-d H:i:s'),
    ];
    $first=$values(940,1,str_repeat('a',32),str_repeat('1',32),1);$insert->execute($first);
    $unbound=$values(941,1,str_repeat('b',32),null,null);$insert->execute($unbound);
    $before=$pdo->query('SELECT * FROM lead_outcome_trip_bindings ORDER BY id')->fetchAll();
    $bytes=$pdo->query('SELECT outcome_json,SHA2(outcome_json,256) AS digest FROM lead_outcome_trip_bindings ORDER BY id LIMIT 1')->fetch();
    if($bytes['outcome_json']!==$outcome||$bytes['digest']!==hash('sha256',$outcome)
        ||$before[1]['trip_id']!==null||$before[1]['profile_version']!==null||$before[1]['profile_sha256']!==null)
        throw new RuntimeException('native_outcome_trip_snapshot_or_null_binding_changed');
    $pdo->exec($migration);
    if($pdo->query('SELECT * FROM lead_outcome_trip_bindings ORDER BY id')->fetchAll()!==$before)
        throw new RuntimeException('native_outcome_trip_migration_retry_changed_history');
    echo "PASS native MySQL094 retains exact UTF-8 outcome bytes, explicit unbound NULLs and history on DDL retry\n";

    $rejected=static function(array $params,int $code)use($insert):bool{
        try{$insert->execute($params);return false;}
        catch(PDOException $error){return (int)($error->errorInfo[1]??0)===$code;}
    };
    if(!$rejected($values(941,1,str_repeat('a',32),null,null),1062)
        ||!$rejected($values(941,1,str_repeat('c',32),str_repeat('1',32),1),1452)
        ||!$rejected($values(942,1,str_repeat('d',32),null,null),1452))
        throw new RuntimeException('native_outcome_trip_identity_constraint_missing');
    // Operation IDs are manager-owned, not a single global namespace.
    $insert->execute($values(941,2,str_repeat('a',32),null,null));
    if((int)$pdo->query('SELECT COUNT(*) FROM lead_outcome_trip_bindings')->fetchColumn()!==3)
        throw new RuntimeException('native_outcome_trip_actor_namespace_failed');
    echo "PASS native MySQL094 enforces manager operation uniqueness and same-conversation profile FK\n";

    // Use the real CRM table and native transaction engine, without invoking
    // providers or changing the disposable suite's later positional inserts.
    $pdo->exec("ALTER TABLE conversations ADD lead_outcome VARCHAR(16) NOT NULL DEFAULT 'open'");
    $before=$pdo->query('SELECT * FROM lead_outcome_trip_bindings ORDER BY id')->fetchAll();
    $pdo->beginTransaction();
    $pdo->exec("UPDATE conversations SET lead_outcome='lost' WHERE id=940");
    $duplicate=$rejected($first,1062);
    $pdo->rollBack();
    if(!$duplicate||$pdo->query('SELECT lead_outcome FROM conversations WHERE id=940')->fetchColumn()!=='open'
        ||$pdo->query('SELECT * FROM lead_outcome_trip_bindings ORDER BY id')->fetchAll()!==$before)
        throw new RuntimeException('native_outcome_trip_failed_insert_not_atomic');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE conversations SET lead_outcome='won' WHERE id=940");
    $insert->execute($values(940,1,str_repeat('e',32),str_repeat('1',32),1));
    $pdo->rollBack();
    if($pdo->query('SELECT lead_outcome FROM conversations WHERE id=940')->fetchColumn()!=='open'
        ||$pdo->query('SELECT * FROM lead_outcome_trip_bindings ORDER BY id')->fetchAll()!==$before)
        throw new RuntimeException('native_outcome_trip_rollback_changed_state');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE conversations SET lead_outcome='won' WHERE id=940");
    $insert->execute($values(940,1,str_repeat('f',32),str_repeat('1',32),1));
    $pdo->commit();
    if($pdo->query('SELECT lead_outcome FROM conversations WHERE id=940')->fetchColumn()!=='won'
        ||(int)$pdo->query('SELECT COUNT(*) FROM lead_outcome_trip_bindings')->fetchColumn()!==count($before)+1
        ||$pdo->query('SELECT * FROM lead_outcome_trip_bindings ORDER BY id LIMIT 1')->fetch()!==$before[0])
        throw new RuntimeException('native_outcome_trip_commit_changed_prior_history');
    $pdo->exec('ALTER TABLE conversations DROP COLUMN lead_outcome');
    echo "PASS native MySQL094 commits CRM plus binding together and rolls both back after failed or abandoned writes\n";

    $insert->execute($values(940,1,str_repeat('0',32),null,null));
    $pdo->exec('DELETE FROM manager_request_profiles WHERE conversation_id=940 AND version=1');
    $remaining=$pdo->query('SELECT trip_id,profile_version FROM lead_outcome_trip_bindings WHERE conversation_id=940')->fetchAll();
    if($remaining!==[['trip_id'=>null,'profile_version'=>null]])throw new RuntimeException('native_outcome_trip_profile_retention_failed');
    $otherBefore=$pdo->query('SELECT * FROM lead_outcome_trip_bindings WHERE conversation_id=941 ORDER BY id')->fetchAll();
    $pdo->exec('DELETE FROM conversations WHERE id=940');
    if((int)$pdo->query('SELECT COUNT(*) FROM lead_outcome_trip_bindings WHERE conversation_id=940')->fetchColumn()!==0
        ||$pdo->query('SELECT * FROM lead_outcome_trip_bindings WHERE conversation_id=941 ORDER BY id')->fetchAll()!==$otherBefore)
        throw new RuntimeException('native_outcome_trip_conversation_retention_failed');
    $pdo->exec('DELETE FROM conversations WHERE id=941');$pdo->exec('DELETE FROM managers WHERE id=2');
    if((int)$pdo->query('SELECT COUNT(*) FROM lead_outcome_trip_bindings')->fetchColumn()!==0
        ||$pdo->query('SELECT * FROM manager_request_profiles ORDER BY conversation_id,version')->fetchAll()!==$profilesBefore
        ||$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll()!==$conversationsBefore)
        throw new RuntimeException('native_outcome_trip_retention_affected_other_records');
    echo "PASS native MySQL094 cascades bound and unbound retention only within their owning profile or conversation\n";
}
