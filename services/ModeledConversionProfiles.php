<?php
declare(strict_types=1);

require_once __DIR__.'/ModeledConversionEarlyForecast.php';

/** Derived private profiles from the admitted journal; never queries native sources. */
final class ModeledConversionProfiles
{
    public const VERSION='admitted_journal_profiles_v1_2';
    private PDO $pdo;
    private bool $mysql;
    private array $cache=[];
    private int $rowsRead=0;
    private int $bytesRead=0;
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    public function start(): array
    {
        return $this->transaction(function(){
            if((string)$this->q('SELECT enabled FROM modeled_conversion_stream_state WHERE project_key=?',['anytour'])->fetchColumn()!=='1')self::fail('intake_not_enabled');
            $r=$this->state(true);
            if(!$r)$this->q('INSERT INTO modeled_conversion_profiles_state(project_key,enabled,journal_cursor,ticks) VALUES (?,1,0,0)',['anytour']);
            else $this->q('UPDATE modeled_conversion_profiles_state SET enabled=1 WHERE project_key=?',['anytour']);
            return $this->status();
        });
    }
    public function stop(): array{return $this->transaction(function(){$this->state(true);$this->q('UPDATE modeled_conversion_profiles_state SET enabled=0 WHERE project_key=?',['anytour']);return $this->status();});}
    public function status(): array
    {
        $r=$this->state();
        return ['version'=>self::VERSION,'enabled'=>$r&&(bool)$r['enabled'],'ticks'=>$r?(int)$r['ticks']:0,
            'last_receipt'=>$r&&$r['last_receipt_json']!==null?json_decode($r['last_receipt_json'],true,32,JSON_THROW_ON_ERROR):null,
            'last_error'=>$r&&$r['last_error_at_unix']!==null?['at_unix'=>(int)$r['last_error_at_unix'],'category'=>$r['last_error_category']]:null,
            'model_calls'=>0,'conversion_uploads'=>0];
    }
    public function tick(int $now,?callable $beforeCommit=null): array
    {
        if($now<946684800)self::fail('clock');
        $this->cache=[];$this->rowsRead=0;$this->bytesRead=0;$began=microtime(true);
        try{return $this->transaction(function()use($now,$beforeCommit,$began){
            $r=$this->state(true);
            if(!$r||!(bool)$r['enabled'])return ['status'=>'off','model_calls'=>0,'conversion_uploads'=>0];
            $last=$r['last_receipt_json']!==null?json_decode($r['last_receipt_json'],true,32,JSON_THROW_ON_ERROR):null;
            if($last&&$now<(int)$last['observed_at_unix'])self::fail('clock_regressed');
            $cursor=(string)$r['journal_cursor'];
            $fence=(string)$this->q('SELECT COALESCE(MAX(id),0) FROM modeled_conversion_stream_versions WHERE project_key=?',['anytour'])->fetchColumn();
            $affected=[];$processed=0;$unbound=0;$limited=false;
            $page=$this->q('SELECT id,source_kind,source_id,version_no,observed_at_unix,'.$this->length().' AS bytes FROM modeled_conversion_stream_versions WHERE project_key=? AND id>? AND id<=? ORDER BY id LIMIT 40',['anytour',$cursor,$fence])->fetchAll(PDO::FETCH_ASSOC);
            $this->rowsRead+=count($page);
            foreach($page as$meta){
                if((int)$meta['observed_at_unix']>$now)self::fail('future_receipt');
                if($meta['source_kind']==='visit'){$cursor=(string)$meta['id'];$processed++;$unbound++;continue;}
                if(!in_array($meta['source_kind'],['message','event'],true))self::fail('kind');
                $entry=$this->read($meta);
                if($entry===null){$limited=true;break;}
                $snap=$entry['snapshot'];$status=$snap['source_status']??'';
                if(!in_array($status,['present','deleted','scope_excluded'],true))self::fail('source_status');
                $old=$this->q('SELECT * FROM modeled_conversion_profile_sources WHERE project_key=? AND source_kind=? AND source_id=?',['anytour',$meta['source_kind'],$meta['source_id']])->fetch(PDO::FETCH_ASSOC);
                $scope=$status==='present'?$this->scope($snap):($old?$this->scope($old):null);
                $next=$affected;
                if($old)$next[$this->scopeKey($this->scope($old))]=$this->scope($old);
                if($scope)$next[$this->scopeKey($scope)]=$scope;
                if(count($next)>4){$limited=true;break;}
                $affected=$next;
                if($scope){
                    $values=[$scope['platform'],$scope['customer_key'],$scope['conversation_id'],$meta['id'],$status,'anytour',$meta['source_kind'],$meta['source_id']];
                    if($old)$this->q('UPDATE modeled_conversion_profile_sources SET platform=?,customer_key=?,conversation_id=?,journal_id=?,source_status=? WHERE project_key=? AND source_kind=? AND source_id=?',$values);
                    else $this->q('INSERT INTO modeled_conversion_profile_sources(platform,customer_key,conversation_id,journal_id,source_status,project_key,source_kind,source_id) VALUES (?,?,?,?,?,?,?,?)',$values);
                }
                $cursor=(string)$meta['id'];$processed++;
            }
            $cases=[];
            foreach($affected as$scope){
                $bind=array_values($scope);
                $stats=$this->q("SELECT COUNT(*) AS n,SUM(CASE WHEN source_status='scope_excluded' THEN 1 ELSE 0 END) AS denied FROM modeled_conversion_profile_sources WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=?",$bind)->fetch(PDO::FETCH_ASSOC);
                $denied=false;$restored=0;$rows=[];$window=(int)$stats['n']>50;
                $refs=$this->q("SELECT source_kind,source_id,journal_id,source_status FROM modeled_conversion_profile_sources WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=? ORDER BY (source_status='scope_excluded') DESC,journal_id DESC LIMIT 50",$bind)->fetchAll(PDO::FETCH_ASSOC);
                foreach($refs as$ref){
                    if($this->rowsRead>=498){$window=true;break;}
                    // Progress enumerates dirty scopes; the information fence defines available revisions.
                    $meta=$this->q('SELECT id,source_kind,source_id,version_no,observed_at_unix,'.$this->length().' AS bytes FROM modeled_conversion_stream_versions WHERE project_key=? AND source_kind=? AND source_id=? AND id<=? ORDER BY id DESC LIMIT 1',['anytour',$ref['source_kind'],$ref['source_id'],$fence])->fetch(PDO::FETCH_ASSOC);$this->rowsRead++;
                    if(!$meta)self::fail('missing_journal_revision');
                    $entry=$this->read($meta);
                    if($entry===null){$window=true;continue;}
                    $entry['progress_journal_id']=(string)$ref['journal_id'];
                    $snap=$entry['snapshot'];$status=$snap['source_status']??'';
                    if($status==='scope_excluded')$denied=true;
                    if($ref['source_status']==='scope_excluded'&&$status==='present'&&$this->scope($snap)===$scope)$restored++;
                    $rows[]=$entry;
                }
                // A bounded window cannot assume that an unchecked older exclusion was restored.
                if((int)$stats['denied']>$restored)$denied=true;
                $cases[]=['subject'=>$scope,'rows'=>$rows,'window_limited'=>$window,'access_denied'=>$denied];
            }
            $document=['schema_version'=>1,'requested_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'journal_cursor'=>$cursor,'source_fence_journal_id'=>$fence,'cases'=>$cases];
            $errors=[];$written=0;$forecastReceipt=ModeledConversionEarlyForecast::emptyReceipt();
            if($cases){
                $result=$this->execute($document);
                $this->validate($result,$document);
                foreach($result['profiles']as$profile){
                    $scope=$this->scope($profile['subject']);
                    $case=null;foreach($cases as$inputCase)if($this->scopeKey($inputCase['subject'])===$this->scopeKey($scope)){$case=$inputCase;break;}
                    if($case===null)self::fail('forecast_case');
                    // Read only the prior scalar marker; private profile text is not loaded again.
                    $firstExpression=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT(profile_json,'$.early_forecast.first_snapshot_journal_cursor'))":"json_extract(profile_json,'$.early_forecast.first_snapshot_journal_cursor')";
                    $first=$this->q('SELECT '.$firstExpression.' AS first_cursor FROM modeled_conversion_profile_versions WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=? ORDER BY id DESC LIMIT 1',array_values($scope))->fetchColumn();
                    $packet=ModeledConversionEarlyForecast::freeze($case,$profile,$document,$first===false||$first===null?null:(string)$first);
                    $profile['contextual_signals']=$packet['contextual_signals'];$profile['early_forecast']=$packet['early_forecast'];
                    $forecastReceipt=ModeledConversionEarlyForecast::accumulate($forecastReceipt,$packet);
                    $profile['processing']=['version'=>self::VERSION,'source_sha256'=>hash_file('sha256',__FILE__),'journal_cursor'=>$cursor,'source_fence_journal_id'=>$fence];
                    $json=self::json($profile);
                    $this->q('INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES (?,?,?,?,?,?,?,?)',
                        [...array_values($scope),$cursor,strtotime($result['extracted_at']),hash('sha256',self::json($document)),$json]);$written++;
                    foreach($profile['coverage']['errors']as$key=>$count)$errors[$key]=($errors[$key]??0)+$count;
                }
            }
            $pending=(int)$this->q('SELECT COUNT(*) FROM modeled_conversion_stream_versions WHERE project_key=? AND id>?',['anytour',$cursor])->fetchColumn();
            $receipt=['status'=>$processed?'profiled':'idle','version'=>self::VERSION,'observed_at_unix'=>$now,
                'processed_revisions'=>$processed,'profiles_written'=>$written,'unbound_entry_revisions'=>$unbound,
                'journal_rows_read'=>$this->rowsRead,'payload_bytes'=>$this->bytesRead,'pending_revisions'=>$pending,
                'limit_reached'=>$limited||$pending>0,'coverage_errors'=>$errors,'trip_status'=>'unknown','model_calls'=>0,'conversion_uploads'=>0,
                'early_forecast'=>$forecastReceipt,
                'duration_ms'=>(int)round((microtime(true)-$began)*1000)];
            if($this->rowsRead>500||$this->bytesRead>2097152)self::fail('budget');
            if($beforeCommit)$beforeCommit($receipt);
            $this->q('UPDATE modeled_conversion_profiles_state SET journal_cursor=?,ticks=ticks+1,last_receipt_json=? WHERE project_key=?',[$cursor,self::json($receipt),'anytour']);
            // Goal and readiness counts stay in the private state receipt, outside runtime telemetry.
            $telemetry=$receipt;unset($telemetry['early_forecast']);$telemetry['early_forecast_version']=ModeledConversionEarlyForecast::VERSION;
            return $telemetry;
        });}catch(Throwable $e){
            if(!$this->pdo->inTransaction()){try{$this->q('UPDATE modeled_conversion_profiles_state SET last_error_at_unix=?,last_error_category=? WHERE project_key=?',[$now,'processing_failed','anytour']);}catch(Throwable $ignored){}}
            throw $e;
        }
    }
    private function read(array $meta): ?array
    {
        $key=(string)$meta['id'];if(isset($this->cache[$key]))return $this->cache[$key];
        // Reserve space for JSON framing and derived references within the2MiB protocol.
        if($this->rowsRead>=499||$this->bytesRead+(int)$meta['bytes']>1048576)return null;
        $json=$this->q('SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE project_key=? AND id=?',['anytour',$key])->fetchColumn();$this->rowsRead++;
        if(!is_string($json)||strlen($json)!==(int)$meta['bytes'])self::fail('journal_snapshot');
        $this->bytesRead+=strlen($json);
        return $this->cache[$key]=['journal_id'=>$key,'source_kind'=>$meta['source_kind'],'source_id'=>(string)$meta['source_id'],
            'source_version_no'=>(int)$meta['version_no'],'observed_at_unix'=>(int)$meta['observed_at_unix'],'snapshot'=>json_decode($json,true,64,JSON_THROW_ON_ERROR)];
    }
    private function scope(array $row): array
    {
        $p=$row['project_key']??'';$platform=$row['platform']??'';$customer=(string)($row['customer_key']??'');$conversation=(string)($row['conversation_id']??'');
        if($p!=='anytour'||!in_array($platform,['max','telegram'],true)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$customer)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$conversation))self::fail('scope');
        return ['project_key'=>$p,'platform'=>$platform,'customer_key'=>$customer,'conversation_id'=>$conversation];
    }
    private function scopeKey(array $s): string{return hash('sha256',self::json($s));}
    private function validate(array $result,array $input): void
    {
        if(($result['schema_version']??null)!==1||($result['export_allowed']??true)!==false||($result['model_calls']??-1)!==0||($result['conversion_uploads']??-1)!==0||($result['journal_cursor']??null)!==$input['journal_cursor']||($result['source_fence_journal_id']??null)!==$input['source_fence_journal_id'])self::fail('output_contract');
        $at=strtotime($result['extracted_at']??'');if($at===false||$at<strtotime($input['requested_at'])||$at>time())self::fail('semantic_clock');
        $expected=[];foreach($input['cases']as$c)$expected[$this->scopeKey($c['subject'])]=true;
        if(!is_array($result['profiles']??null)||count($result['profiles'])!==count($expected))self::fail('output_scopes');
        foreach($result['profiles']as$p){
            $key=$this->scopeKey($this->scope($p['subject']??[]));if(!isset($expected[$key]))self::fail('output_scopes');unset($expected[$key]);
            $v=$p['profile']??[];
            if(($v['trip_status']??'')!=='unknown'||($v['target_admission']['eligible']??true)!==false||($v['export_allowed']??true)!==false)self::fail('output_admission');
            foreach(['purchase_anywhere_probability','purchase_with_us_probability','expected_commission_rub','expected_value_rub']as$field)if(!array_key_exists($field,$v)||$v[$field]!==null)self::fail('monetary_output');
        }
    }
    private function execute(array $document): array
    {
        $input=self::json($document);if(strlen($input)>2097152)self::fail('input_budget');
        $dir=sys_get_temp_dir().'/anytour-profile-'.bin2hex(random_bytes(12));if(!mkdir($dir,0700))self::fail('temporary_storage');
        $proc=null;
        try{
            foreach(['input','output','error']as$name){if(file_put_contents($dir.'/'.$name,$name==='input'?$input:'')===false||!chmod($dir.'/'.$name,0600))self::fail('temporary_storage');}
            $proc=proc_open(['python3',dirname(__DIR__).'/tools/profile_conversion_journal.py',$dir.'/input'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/output','w'],2=>['file',$dir.'/error','w']],$pipes);
            if(!is_resource($proc))self::fail('interpreter_unavailable');
            $until=microtime(true)+5;$exit=null;
            do{
                $state=proc_get_status($proc);if(!$state['running']){$exit=$state['exitcode'];break;}
                clearstatcache(true,$dir.'/output');clearstatcache(true,$dir.'/error');
                if(filesize($dir.'/output')>2097152||filesize($dir.'/error')>65536||microtime(true)>$until){proc_terminate($proc,9);self::fail('interpreter_limit');}
                usleep(10000);
            }while(true);
            proc_close($proc);$proc=null;clearstatcache(true,$dir.'/output');
            if($exit!==0||filesize($dir.'/output')>2097152)self::fail('interpreter_failed');
            return json_decode((string)file_get_contents($dir.'/output'),true,128,JSON_THROW_ON_ERROR);
        }finally{
            if(is_resource($proc)){proc_terminate($proc,9);proc_close($proc);}
            foreach(['input','output','error']as$name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);
            rmdir($dir);
        }
    }
    private function transaction(callable $fn)
    {
        if($this->pdo->inTransaction())self::fail('outer_transaction');$lock=null;
        if($this->mysql){$lock='modeled_profiles:'.substr(hash('sha256',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);$v=$this->q('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn();if((string)$v==='0')return ['status'=>'busy','model_calls'=>0,'conversion_uploads'=>0];if((string)$v!=='1')self::fail('lock');}
        try{if($this->mysql)$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->beginTransaction();try{$r=$fn();$this->pdo->commit();return $r;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
        finally{if($lock!==null){try{$this->q('SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable $ignored){}}}
    }
    private function length(): string{return $this->mysql?'OCTET_LENGTH(snapshot_json)':'LENGTH(CAST(snapshot_json AS BLOB))';}
    private function state(bool $lock=false): ?array{return $this->q('SELECT * FROM modeled_conversion_profiles_state WHERE project_key=?'.($lock&&$this->mysql?' FOR UPDATE':''),['anytour'])->fetch(PDO::FETCH_ASSOC)?:null;}
    private function q(string $sql,array $bind): PDOStatement{$q=$this->pdo->prepare($sql);$q->execute($bind);return $q;}
    private static function json(array $v): string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function fail(string $r): void{throw new RuntimeException('modeled_profiles_'.$r);}
}
