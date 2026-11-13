<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ModeledConversionPrimarySourceReader.php';

/** Synthetic SQLite/native-MySQL exercise. Never touches an application traffic directory. */
final class ForecastPrimarySourceObservedStatement extends PDOStatement
{
    public static bool $record=false;
    public static array $queries=[];
    protected function __construct(){}
    public function execute(?array $params=null):bool
    {
        if(self::$record)self::$queries[]=$this->queryString;
        return parent::execute($params);
    }
}

function forecastPrimarySourceAssertions(PDO $p,callable $check):void
{
    if($p->inTransaction())throw new RuntimeException('primary_fixture_outer_transaction');
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $tables=['modeled_conversion_value_export_settings','max_channel_conversion_outbox','modeled_conversion_profile_versions'];
    $renamed=[];$created=[];$root=null;
    $exists=static function(string $table)use($p,$mysql):bool{
        $q=$p->prepare($mysql?'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
            :"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute([$table]);$n=(int)$q->fetchColumn();$q->closeCursor();return $n===1;
    };
    $json=static fn(mixed $v):string=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $remove=static function(string $path)use(&$remove):void{
        if(is_link($path)||is_file($path)){unlink($path);return;}
        if(!is_dir($path))return;
        foreach(scandir($path)as$name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);
        rmdir($path);
    };
    try{
        foreach($tables as$table){
            $backup=$table.'_primary_fixture_backup';
            if($exists($backup))throw new RuntimeException('primary_fixture_backup_exists');
            if($exists($table)){$p->exec('ALTER TABLE '.$table.' RENAME TO '.$backup);$renamed[$table]=$backup;}
        }
        $p->exec('CREATE TABLE modeled_conversion_value_export_settings(project_key VARCHAR(40) PRIMARY KEY,counter_id VARCHAR(20),goal_id VARCHAR(20),goal_intent VARCHAR(20),contract_version VARCHAR(64),last_receipt_json '.($mysql?'JSON':'TEXT').')');
        $created[]=$tables[0];
        $p->exec('CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,observed_at_unix BIGINT,assessment_namespace CHAR(64),assessment_kind VARCHAR(8))');
        $created[]=$tables[2];
        $p->exec('CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,value_profile_id BIGINT,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,external_user_id VARCHAR(20),counter_id VARCHAR(20),target VARCHAR(100),transport_target VARCHAR(64),value_contract VARCHAR(64),event_unix BIGINT,value_rub DECIMAL(12,2),state VARCHAR(24),upload_id VARCHAR(40),value_api_accepted_at_unix BIGINT)');
        $created[]=$tables[1];
        $checkpoint=ModeledConversionValueVisitEvidence::CHECKPOINT;$now=$checkpoint+600;
        $namespace=ModeledConversionProfiles::assessmentNamespace();
        $profile=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour','max',?,?,?,'repair')");
        $event=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,?,'anytour','max',?,?,'98615635',?,?,?,?,?,'pending',NULL,NULL)");
        $frame=['v'=>ModeledConversionValueVisitEvidence::VERSION,'n'=>$namespace,'b'=>$checkpoint,'r'=>[]];
        $rows=[];
        foreach([17,23,31]as$i=>$value){
            $id=911+$i;$pid=901+$i;$conversation=980001+$i;$uid=(string)(770001+$i);$at=$checkpoint-60-$i;
            $row=['id'=>(string)$id,'value_profile_id'=>(string)$pid,'project_key'=>'anytour','platform'=>'max',
                'conversation_id'=>(string)$conversation,'external_user_id'=>$uid,'counter_id'=>'98615635',
                'target'=>ModeledConversionValueContract::predictionKey((string)$pid),'transport_target'=>ModeledConversionValueContract::TARGET,
                'value_contract'=>ModeledConversionValueContract::VERSION,'event_unix'=>$at,'value_rub'=>$value];
            $profile->execute([$pid,$conversation,$checkpoint-120,$namespace]);
            $event->execute([$id,$pid,$conversation,$uid,$row['target'],$row['transport_target'],$row['value_contract'],$at,$value]);
            $ref=ModeledConversionValueIdentityEvidence::reference($row,$namespace);
            if($ref===null)throw new RuntimeException('primary_fixture_reference');
            $frame['r'][]=[$ref,(string)$id,0,null,null,0,null,null,null,null,0,null,0,null,0,$at];
            $rows[]=$row;
        }
        $profile->closeCursor();$event->closeCursor();unset($profile,$event);
        $saved=['finite_visit_evidence'=>$frame];
        $q=$p->prepare("INSERT INTO modeled_conversion_value_export_settings VALUES('anytour','98615635','678187097','verified',?,?)");
        $q->execute([ModeledConversionValueContract::VERSION,$json($saved)]);$q->closeCursor();unset($q);
        $temp=sys_get_temp_dir().'/forecast-primary-source-'.bin2hex(random_bytes(8));
        if(!mkdir($temp,0700))throw new RuntimeException('primary_fixture_temp');
        $root=realpath($temp);if($root===false)throw new RuntimeException('primary_fixture_temp');
        if(!mkdir($root.'/traffic',0755))throw new RuntimeException('primary_fixture_temp');
        $paths=[];$data=[];
        foreach($rows as$i=>$row){
            $yclid=$i===1?'':'7712345678901234';
            $data[$i]=['chat_id'=>'-'.$row['external_user_id'],'yclid'=>$yclid,'region_id'=>'213','campaign_id'=>'123',
                'entry_channel'=>'max_anytour_fixture','raw'=>$yclid===''?'':$yclid.'_region_213_campaign_123',
                'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$row['event_unix']+($i===2?1:-60))];
            $paths[$i]=$root.'/traffic/'.$data[$i]['chat_id'].'.json';
            file_put_contents($paths[$i],$json($data[$i]));chmod($paths[$i],0644);
        }
        $db=static function()use($p,$tables,$json):string{
            $out=[];
            foreach($tables as$table){$q=$p->query('SELECT * FROM '.$table.' ORDER BY '.($table==='modeled_conversion_value_export_settings'?'project_key':'id'));
                $out[$table]=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();}
            return $json($out);
        };
        $fs=static function()use($paths,$json):string{
            $out=[];foreach($paths as$path){clearstatcache(true,$path);$s=lstat($path);
                $out[]=[hash_file('sha256',$path),$s['mode'],$s['uid'],$s['gid'],$s['size'],$s['mtime'],$s['ctime']];}
            return $json($out);
        };
        $read=static function(?callable $auth=null,?ModeledConversionStatusBudget $budget=null)use($p,$root,$now):array{
            $old=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
            $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastPrimarySourceObservedStatement::class]);
            ForecastPrimarySourceObservedStatement::$queries=[];ForecastPrimarySourceObservedStatement::$record=true;
            try{return (new ModeledConversionPrimarySourceReader($p,$auth??static function():void{},$budget??new ModeledConversionStatusBudget(),$root))->read($now);}
            finally{ForecastPrimarySourceObservedStatement::$record=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$old);}
        };
        $beforeDb=$db();$beforeFs=$fs();$got=$read();$sql=implode(' ',ForecastPrimarySourceObservedStatement::$queries);
        $check('primary source reader exercises exactly three bound file points with positive empty and late latest metadata',
            $got['available']&&$got['original_binding_verified']&&count($got['rows'])===3
            &&array_column($got['rows'],'event_ref')===array_column($frame['r'],0)
            &&$got['rows'][0]['status']==='saved_yclid_hint'&&$got['rows'][0]['yclid_matches_raw_payload']===true
            &&preg_match('/\A[a-f0-9]{64}\z/D',$got['rows'][0]['yclid_hint_sha256'])===1
            &&$got['rows'][1]['status']==='yclid_not_present_in_latest_file'&&$got['rows'][1]['yclid_hint_sha256']===null
            &&$got['rows'][2]['reason']==='after_event'&&$got['rows'][2]['yclid_hint_sha256']===null
            &&$got['source_counter_binding']==='not_recorded_in_file'&&!$got['history_complete']&&!$got['financial_permission']
            &&$got['visit_linkage']==='not_checked'&&$got['provider_requests']===0&&$got['model_calls']===0&&$got['conversion_uploads']===0
            &&$got['rows'][0]['first_saved_at']===null&&$got['rows'][0]['first_enriched_at']===null
            &&count(ForecastPrimarySourceObservedStatement::$queries)===2
            &&str_contains($sql,'primary_source_original_bindings')&&str_contains($sql,'LIMIT 4 OFFSET 0')
            &&!preg_match('/native_identity|advertising_visit_observations|max_sub_links|modeled_conversion_known_yclid_relations|profile_json/i',$sql)
            &&strlen($json($got))<=28000&&$db()===$beforeDb&&$fs()===$beforeFs);
        foreach(['770001','770002','770003','7712345678901234','_region_213_campaign_123','PRIVATE_']as$private)
            $check('primary source result does not expose raw identity payload or source path '.$private,!str_contains($json($got),$private));
        $check('primary source locator and content hash are stable saved-file observations',$read()['rows'][0]===$got['rows'][0]);

        foreach(['missing','malformed','oversize','symlink','wrong_chat','bad_time','invalid_yclid']as$kind){
            $original=$json($data[0]);
            try{
                if($kind==='missing')unlink($paths[0]);
                elseif($kind==='malformed')file_put_contents($paths[0],'{"PRIVATE_MALFORMED":');
                elseif($kind==='oversize')file_put_contents($paths[0],str_repeat('PRIVATE_OVERSIZE_',520));
                elseif($kind==='symlink'){unlink($paths[0]);symlink($paths[1],$paths[0]);}
                else{$bad=$data[0];
                    if($kind==='wrong_chat')$bad['chat_id']='-770099';
                    if($kind==='bad_time')$bad['updated_at']='2026-02-30T12:00:00Z';
                    if($kind==='invalid_yclid')$bad['yclid']='00123456';
                    file_put_contents($paths[0],$json($bad));}
                $bad=$read();$expected=['missing'=>'not_found','malformed'=>'malformed','oversize'=>'too_large','symlink'=>'unsafe_file'][$kind]??'malformed';
                $check('primary source rejects one unsafe or incomplete exact file without claiming a missing visit '.$kind,
                    $bad['available']&&!$bad['rows'][0]['available']&&$bad['rows'][0]['reason']===$expected
                    &&$bad['rows'][0]['yclid_hint_sha256']===null&&$bad['rows'][0]['current_file_sha256']===null
                    &&$bad['rows'][1]['status']==='yclid_not_present_in_latest_file'
                    &&!str_contains($json($bad),'PRIVATE_')&&$db()===$beforeDb);
            }finally{if(is_link($paths[0]))unlink($paths[0]);file_put_contents($paths[0],$original);chmod($paths[0],0644);}
        }
        $carried=$data[0];$carried['raw']='post1_fixture';
        file_put_contents($paths[0],$json($carried));$carriedView=$read()['rows'][0];
        $check('a carried latest Yclid is a retained hint rather than proof of the new raw start payload',
            $carriedView['status']==='saved_yclid_hint'&&$carriedView['yclid_matches_raw_payload']===false&&!$got['history_complete']);
        file_put_contents($paths[0],$json($data[0]));

        $checks=0;$race=$read(static function()use(&$checks,$paths,$data,$json):void{
            if(++$checks===5)file_put_contents($paths[0],$json($data[0]).' ');
        });
        $check('an exact file changed after copied bytes is unknown with no hint or historical promotion',
            $checks>=5&&$race['rows'][0]['reason']==='changed_during_read'&&$race['rows'][0]['yclid_hint_sha256']===null&&$db()===$beforeDb);
        file_put_contents($paths[0],$json($data[0]));
        $checks=0;$denied=false;
        try{$read(static function()use(&$checks):void{if(++$checks===5)throw new DomainException('synthetic_primary_revoked');});}
        catch(DomainException $e){$denied=$e->getMessage()==='synthetic_primary_revoked';}
        $check('primary source rechecks authorization after copied file bytes without returning a partial result',$denied&&$db()===$beforeDb);

        $directory=$root.'/traffic';rename($directory,$directory.'.real');symlink($directory.'.real',$directory);
        try{$unsafeDir=$read();$check('primary source refuses a symlink traffic directory without following it',
            count(array_filter($unsafeDir['rows'],static fn(array $r):bool=>$r['reason']==='source_directory_unavailable'))===3);}
        finally{unlink($directory);rename($directory.'.real',$directory);}

        foreach(['frame_namespace','duplicate_pk','missing_pk','profile_namespace','profile_conversation','profile_late','outbox_event','outbox_uid','outbox_value','outbox_counter','settings_project_alias','settings_counter_alias']as$kind){
            try{
                if($kind==='frame_namespace'||$kind==='duplicate_pk'||$kind==='missing_pk'){
                    $badSaved=$saved;
                    if($kind==='frame_namespace')$badSaved['finite_visit_evidence']['n']=str_repeat('0',64);
                    if($kind==='duplicate_pk')$badSaved['finite_visit_evidence']['r'][1][1]=$badSaved['finite_visit_evidence']['r'][0][1];
                    if($kind==='missing_pk')$badSaved['finite_visit_evidence']['r'][1][1]='999999';
                    $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?");$q->execute([$json($badSaved)]);$q->closeCursor();
                }elseif($kind==='profile_namespace')$p->exec("UPDATE modeled_conversion_profile_versions SET assessment_namespace='".str_repeat('0',64)."' WHERE id=901");
                elseif($kind==='profile_conversation')$p->exec('UPDATE modeled_conversion_profile_versions SET conversation_id=999999 WHERE id=901');
                elseif($kind==='profile_late')$p->exec('UPDATE modeled_conversion_profile_versions SET observed_at_unix='.($checkpoint+1).' WHERE id=901');
                elseif($kind==='outbox_event')$p->exec('UPDATE max_channel_conversion_outbox SET event_unix=event_unix+1 WHERE id=911');
                elseif($kind==='outbox_uid')$p->exec("UPDATE max_channel_conversion_outbox SET external_user_id='770099' WHERE id=911");
                elseif($kind==='outbox_value')$p->exec('UPDATE max_channel_conversion_outbox SET value_rub=value_rub+1 WHERE id=911');
                elseif($kind==='outbox_counter')$p->exec("UPDATE max_channel_conversion_outbox SET counter_id='98615635 ' WHERE id=911");
                elseif($kind==='settings_project_alias')$p->exec("UPDATE modeled_conversion_value_export_settings SET project_key='anytour '");
                else$p->exec("UPDATE modeled_conversion_value_export_settings SET counter_id='98615635 '");
                $bad=$read();$check('primary source fails closed before file results when immutable binding changes '.$kind,
                    !$bad['available']&&!isset($bad['rows'])&&!str_contains($json($bad),'PRIVATE_'));
            }finally{
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET project_key='anytour',counter_id='98615635',last_receipt_json=?");
                $q->execute([$json($saved)]);$q->closeCursor();
                $q=$p->prepare("UPDATE modeled_conversion_profile_versions SET assessment_namespace=?,conversation_id=?,observed_at_unix=? WHERE id=901");
                $q->execute([$namespace,$rows[0]['conversation_id'],$checkpoint-120]);$q->closeCursor();
                $q=$p->prepare("UPDATE max_channel_conversion_outbox SET external_user_id=?,event_unix=?,value_rub=?,counter_id='98615635' WHERE id=911");
                $q->execute([$rows[0]['external_user_id'],$rows[0]['event_unix'],$rows[0]['value_rub']]);$q->closeCursor();unset($q);
            }
        }
        $p->exec("UPDATE max_channel_conversion_outbox SET upload_id='' WHERE id=911");
        $emptyReceipt=$read();$check('canonical empty upload ID remains no receipt rather than a fabricated existing upload',$emptyReceipt['rows'][0]['status']==='saved_yclid_hint');
        $p->exec("UPDATE max_channel_conversion_outbox SET upload_id='889911' WHERE id=911");
        $receipt=$read();$check('an existing financial receipt is not reinterpreted by the primary file review',
            $receipt['rows'][0]['reason']==='existing_or_unknown_financial_receipt_not_rechecked'&&$receipt['rows'][0]['yclid_hint_sha256']===null);
        $p->exec('UPDATE max_channel_conversion_outbox SET upload_id=NULL WHERE id=911');
        $small=new ModeledConversionStatusBudget();$small->consumeBytes(60000);$limited=false;
        try{$read(null,$small);}catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='byte_limit';}
        $check('primary source reserves final authorization bytes before any SQL or file read',$limited&&ForecastPrimarySourceObservedStatement::$queries===[]);
        $limited=false;try{$read(null,new ModeledConversionStatusBudget(0.0,static fn():float=>6.0));}
        catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='time_limit';}
        $check('primary source shares the five second deadline without entering a reader on an exhausted budget',$limited&&ForecastPrimarySourceObservedStatement::$queries===[]);
        $p->beginTransaction();try{$outer=$read();$check('primary source does not take ownership of a caller transaction',
            !$outer['available']&&$outer['reason']==='outer_transaction'&&$p->inTransaction());}finally{$p->rollBack();}
        $check('all primary review exercises leave original synthetic DB bindings unchanged',$db()===$beforeDb);
    }finally{
        if($p->inTransaction())$p->rollBack();
        foreach(array_reverse($created)as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
        foreach($renamed as$table=>$backup)$p->exec('ALTER TABLE '.$backup.' RENAME TO '.$table);
        if(is_string($root))$remove($root);
    }
}
