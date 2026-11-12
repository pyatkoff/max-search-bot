<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ModeledConversionValueDeliveryReader.php';

/** Shared isolated SQLite/native-MySQL proof; never receives a production connection. */
function forecastSavedReceiptAssertions(PDO $pdo,callable $check,?callable $rpcRead=null):void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if(!$mysql){
        $pdo->sqliteCreateFunction('CONCAT',static fn(...$parts)=>in_array(null,$parts,true)?null:implode('',array_map('strval',$parts)),-1);
        $pdo->sqliteCreateFunction('SHA2',static fn($s,$bits)=>$s===null?null:($bits===256?hash('sha256',$s):null),2);
    }
    $pdo->exec("CREATE TEMPORARY TABLE max_channel_conversion_outbox(
        id INTEGER PRIMARY KEY,project_key VARCHAR(40),counter_id VARCHAR(40),transport_target VARCHAR(80),value_contract VARCHAR(80),
        upload_id VARCHAR(40),state VARCHAR(20),upload_status VARCHAR(24),event_unix BIGINT,
        value_api_accepted_at_unix BIGINT NULL,value_processed_at_unix BIGINT NULL)");
    $readOnly=static function(callable $f)use($pdo,$mysql):mixed{
        if($mysql)$pdo->exec('SET SESSION TRANSACTION READ ONLY');
        try{return $f();}finally{if($mysql)$pdo->exec('SET SESSION TRANSACTION READ WRITE');}
    };
    try{
        $now=time();$target=ModeledConversionValueContract::TARGET;$contract=ModeledConversionValueContract::VERSION;
        $insert=$pdo->prepare('INSERT INTO max_channel_conversion_outbox VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([1,'anytour','98615635',$target,$contract,'770001','processed','PROCESSED',$now-1000,$now-900,$now-800]);
        $insert->execute([2,'ANYTOUR','98615635',$target,$contract,'770001','unmatched','LINKAGE_FAILURE',$now-1000,$now-900,null]);
        $insert->execute([3,'anytour','98615635',$target,$contract,'770002','accepted','UPLOADED',$now-700,$now-600,null]);
        $snapshot=static fn():array=>$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $before=$snapshot();$auth=0;
        $authorize=static function()use(&$auth):void{++$auth;};
        $read=static function(string $ref)use($pdo,$authorize,$readOnly,$now):array{
            return $readOnly(static fn():array=>(new ModeledConversionValueDeliveryReader($pdo,$authorize,new ModeledConversionStatusBudget()))->savedReceiptStatus($ref,$now));
        };
        $ref=hash('sha256','modeled_value_upload_receipt_v1|770001');$row=$read($ref);
        $check('exact saved receipt reads its own processed row while excluding a differently cased project with the same upload',
            ($row['available']??false)&&$row['coverage_complete']&&$row['inspected_rows']===2
            &&$row['state']==='processed'&&$row['upload_status']==='PROCESSED'&&$row['processed_upload_row']
            &&$row['processed_at']===gmdate('Y-m-d\TH:i:s\Z',$now-800)
            &&$row['per_receipt_visit_linkage']==='not_checked'&&$row['provider_requests']===0
            &&$snapshot()===$before&&$auth>=2);
        if($rpcRead!==null){
            $rpcRow=$readOnly(static fn():array=>$rpcRead($ref));
            $check('the real native OAuth and MCP receipt branch reaches only its saved primary result',
                ($rpcRow['available']??false)&&($rpcRow['receipt_ref']??null)===$ref
                &&($rpcRow['processed_upload_row']??false)&&!isset($rpcRow['value_delivery'])
                &&($rpcRow['provider_requests']??-1)===0&&$snapshot()===$before);
        }
        $encoded=json_encode($row,JSON_THROW_ON_ERROR);
        $check('saved receipt result exposes no native locator money identity or query',
            !str_contains($encoded,'770001')&&!str_contains($encoded,'client_id')&&!str_contains($encoded,'value_rub')
            &&!array_key_exists('upload_id',$row)&&!array_key_exists('sql',$row));
        $waiting=$read(hash('sha256','modeled_value_upload_receipt_v1|770002'));
        $check('an uploaded saved receipt remains pending processing with a missing processed clock',
            $waiting['available']&&$waiting['upload_status']==='UPLOADED'&&!$waiting['terminal_processing']&&$waiting['processed_at']===null);
        $missing=$read(str_repeat('a',64));
        $check('unknown receipt has a complete bounded missing result without a provider request',
            !$missing['available']&&$missing['reason']==='receipt_not_found_in_saved_goal_scope'&&$missing['coverage_complete']);
        $count=$auth;try{$read('not-a-reference');$invalid=false;}catch(InvalidArgumentException $expected){$invalid=true;}
        $check('invalid receipt input fails before authorization or SQL',$invalid&&$auth===$count);
        $insert->execute([4,'anytour','98615635',$target,$contract,'770001','accepted','UPLOADED',$now-1000,$now-900,null]);
        $ambiguous=$read($ref);
        $check('a reused saved receipt is ambiguous rather than choosing a ledger row',
            !$ambiguous['available']&&$ambiguous['reason']==='ambiguous_saved_receipt'&&$ambiguous['matching_rows']===2);
        $pdo->exec('DELETE FROM max_channel_conversion_outbox WHERE id=4');
        $insert->execute([4,'anytour','98615635',$target,$contract,'770003','processed','PROCESSED',$now-1000,null,$now-800]);
        $legacy=$read(hash('sha256','modeled_value_upload_receipt_v1|770003'));
        $check('a terminal saved status does not invent its missing historical first API clock',
            $legacy['available']&&$legacy['processed_upload_row']&&$legacy['first_api_observed_at']===null
            &&!$legacy['first_api_clock_known']&&$legacy['processed_at']===gmdate('Y-m-d\TH:i:s\Z',$now-800));
        $pdo->exec('DELETE FROM max_channel_conversion_outbox WHERE id=4');
        $denialCalls=0;$denied=false;
        try{$readOnly(static function()use($pdo,$ref,$now,&$denialCalls):array{
            $guard=static function()use(&$denialCalls):void{if(++$denialCalls>1)throw new DomainException('revoked');};
            return (new ModeledConversionValueDeliveryReader($pdo,$guard,new ModeledConversionStatusBudget()))->savedReceiptStatus($ref,$now);
        });}catch(DomainException $expected){$denied=true;}
        $check('revocation after the saved SELECT suppresses its private result',$denied&&$denialCalls===2);
        for($i=4;$i<=1004;$i++)$insert->execute([$i,'anytour','98615635',$target,$contract,null,'pending',null,$now-1000,null,null]);
        $bounded=$read($ref);
        $check('the ledger lookup stops at1001 and never declares an omitted older receipt absent or processed',
            !$bounded['available']&&$bounded['reason']==='saved_scope_row_limit'&&!$bounded['coverage_complete']);
    }finally{$pdo->exec($mysql?'DROP TEMPORARY TABLE max_channel_conversion_outbox':'DROP TABLE IF EXISTS max_channel_conversion_outbox');}
}
