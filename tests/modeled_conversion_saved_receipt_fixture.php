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
        value_api_accepted_at_unix BIGINT NULL,value_processed_at_unix BIGINT NULL,
        target VARCHAR(100) NULL,conversation_id BIGINT NULL,platform VARCHAR(16) NULL,client_id VARCHAR(40) NULL,value_rub DECIMAL(12,2) NULL)");
    $readOnly=static function(callable $f)use($pdo,$mysql):mixed{
        if($mysql)$pdo->exec('SET SESSION TRANSACTION READ ONLY');
        try{return $f();}finally{if($mysql)$pdo->exec('SET SESSION TRANSACTION READ WRITE');}
    };
    try{
        $now=time();$target=ModeledConversionValueContract::TARGET;$contract=ModeledConversionValueContract::VERSION;
        $insert=$pdo->prepare('INSERT INTO max_channel_conversion_outbox(id,project_key,counter_id,transport_target,value_contract,upload_id,state,upload_status,event_unix,value_api_accepted_at_unix,value_processed_at_unix) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([1,'anytour','98615635',$target,$contract,'770001','processed','PROCESSED',$now-1000,$now-900,$now-800]);
        $insert->execute([2,'ANYTOUR','98615635',$target,$contract,'770001','unmatched','LINKAGE_FAILURE',$now-1000,$now-900,null]);
        $insert->execute([3,'anytour','98615635',$target,$contract,'770002','accepted','UPLOADED',$now-700,$now-600,null]);
        $snapshot=static fn():array=>$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $before=$snapshot();$auth=0;
        $authorize=static function()use(&$auth):void{++$auth;};
        $read=static function(string $ref)use($pdo,$authorize,$readOnly,$now):array{
            return $readOnly(static fn():array=>(new ModeledConversionValueDeliveryReader($pdo,$authorize,new ModeledConversionStatusBudget()))->savedReceiptStatus($ref,$now,true));
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
        // Exact primary receipt and its persisted event proof are independently reported.
        $pdo->prepare("UPDATE max_channel_conversion_outbox SET target=?,conversation_id=810001,platform='max',client_id='880001',value_rub=833 WHERE id=1")->execute([ModeledConversionValueContract::predictionKey('1')]);
        $raw=$pdo->query("SELECT id,conversation_id,platform,target,client_id,value_rub,event_unix,upload_id FROM max_channel_conversion_outbox WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $path=(new ReflectionMethod(ModeledConversionVisitProof::class,'eventPath'))->invoke(null,$raw);
        $events=dirname($path);$cache=dirname($events);$newCache=!is_dir($cache);$newEvents=!is_dir($events);
        if($newCache&&!mkdir($cache,0700))throw new RuntimeException('fixture_receipt_cache');
        if($newEvents&&!mkdir($events,0700))throw new RuntimeException('fixture_receipt_events');
        if(file_exists($path)||is_link($path))throw new RuntimeException('fixture_receipt_collision');
        $writer=new ReflectionMethod(ModeledConversionVisitProof::class,'storeAt');
        $at=gmdate('Y-m-d\TH:i:s\Z',$now-30);$visit=gmdate('Y-m-d\TH:i:s\Z',$now-1100);
        $proof=['version'=>ModeledConversionVisitProof::VERSION,'project_key'=>'anytour','counter_id'=>'98615635',
            'goal_id'=>'678187097','currency'=>'RUB','ledger_complete'=>true,'provider_write_requests'=>0,'source_writes'=>0,
            'checked_at'=>$at,'events'=>[['outbox_id'=>(string)$raw['id'],'fingerprint'=>ModeledConversionVisitProof::fingerprint($raw),
                'increment_rub'=>833,'cohort'=>true,'platform'=>'max','stage'=>ModeledConversionValueContract::STAGES[2],
                'upload_status'=>'PROCESSED','linkage'=>'unique_visit_reconciled','reason'=>'unique_goal_visit',
                'checked_at'=>$at,'visit_started_at'=>$visit,'visit_goal_reaches'=>1,'visit_goal_value_rub'=>833]]];
        try{
            $writer->invoke(null,$path,$proof);clearstatcache(true,$path);
            $hash=hash_file('sha256',$path);$mtime=filemtime($path);$beforeProof=$snapshot();$proven=$read($ref);
            $check('focused receipt reads its exact saved visit evidence with the original check clock and no money or identities',
                $proven['available']&&$proven['processed_upload_row']
                &&$proven['per_receipt_visit_linkage']==='unique_visit_reconciled'
                &&($proven['visit_evidence']['checked_at']??null)===$at
                &&!array_key_exists('reason',$proven['visit_evidence'])
                &&($proven['visit_evidence']['visit_started_at']??null)===$visit
                &&($proven['visit_evidence']['visit_goal_reaches']??null)===1
                &&!str_contains(json_encode($proven,JSON_THROW_ON_ERROR),'value_rub')
                &&!str_contains(json_encode($proven,JSON_THROW_ON_ERROR),'880001')
                &&$snapshot()===$beforeProof&&hash_file('sha256',$path)===$hash&&filemtime($path)===$mtime);
            if($rpcRead!==null){
                $rpcProof=$readOnly(static fn():array=>$rpcRead($ref));
                $check('the existing authenticated MCP receipt mode preserves saved per-event proof without uploads',
                    ($rpcProof['visit_evidence']['linkage']??null)==='unique_visit_reconciled'
                    &&($rpcProof['conversion_uploads']??-1)===0);
            }
            $calls=0;$deniedAfterProof=false;
            try{$readOnly(static function()use($pdo,$ref,$now,&$calls):array{
                $guard=static function()use(&$calls):void{if(++$calls===3)throw new DomainException('revoked_after_proof');};
                return (new ModeledConversionValueDeliveryReader($pdo,$guard,new ModeledConversionStatusBudget()))->savedReceiptStatus($ref,$now);
            });}catch(DomainException $expected){$deniedAfterProof=true;}
            $check('authorization revoked after saved proof suppresses the complete private result',$deniedAfterProof&&$calls===3);
            $unverified=$proof;
            $unverified['events'][0]['linkage']='unverified';
            $unverified['events'][0]['visit_started_at']=null;
            $unverified['events'][0]['visit_goal_reaches']=null;
            $safeReasons=true;
            foreach(['other_receipts_unavailable','visit_report_unavailable','no_unique_goal_visit',
                'goal_count_or_value_mismatch','outside_visit_window','private-fixture-880001',null]as$reason){
                if($reason===null)unset($unverified['events'][0]['reason']);
                else $unverified['events'][0]['reason']=$reason;
                $writer->invoke(null,$path,$unverified);clearstatcache(true,$path);
                $outcome=$read($ref);$evidence=$outcome['visit_evidence']??[];
                $expectReason=$reason!==null&&$reason!=='private-fixture-880001';
                $safeReasons=$safeReasons&&($outcome['processed_upload_row']??false)
                    &&($evidence['linkage']??null)==='unverified'&&($evidence['checked_at']??null)===$at
                    &&($expectReason?($evidence['reason']??null)===$reason:!array_key_exists('reason',$evidence))
                    &&!array_key_exists('visit_goal_value_rub',$evidence)
                    &&!str_contains(json_encode($outcome,JSON_THROW_ON_ERROR),'880001')
                    &&$outcome['provider_requests']===0&&$outcome['conversion_uploads']===0;
            }
            $check('an exact processed receipt returns only supported saved unverified reasons',$safeReasons);
            $proof['events'][0]['fingerprint']=str_repeat('e',64);$writer->invoke(null,$path,$proof);clearstatcache(true,$path);
            $changed=$read($ref);
            $check('a wrong saved fingerprint keeps primary processing but never invents visit linkage',
                $changed['available']&&$changed['processed_upload_row']
                &&$changed['per_receipt_visit_linkage']==='not_checked'
                &&($changed['visit_evidence']['available']??true)===false
                &&($changed['visit_evidence']['reason']??null)==='event_changed');
        }finally{
            if(is_file($path))unlink($path);
            if($newEvents&&is_dir($events))rmdir($events);
            if($newCache&&is_dir($cache))rmdir($cache);
        }
        for($i=4;$i<=1004;$i++)$insert->execute([$i,'anytour','98615635',$target,$contract,null,'pending',null,$now-1000,null,null]);
        $bounded=$read($ref);
        $check('the ledger lookup stops at1001 and never declares an omitted older receipt absent or processed',
            !$bounded['available']&&$bounded['reason']==='saved_scope_row_limit'&&!$bounded['coverage_complete']);
    }finally{$pdo->exec($mysql?'DROP TEMPORARY TABLE max_channel_conversion_outbox':'DROP TABLE IF EXISTS max_channel_conversion_outbox');}
}
