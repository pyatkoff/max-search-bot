<?php
declare(strict_types=1);

require_once __DIR__.'/ModeledConversionEarlyForecast.php';
require_once __DIR__.'/ModeledConversionAssignedValue.php';
require_once __DIR__.'/ModeledConversionPredictionFollowup.php';
require_once __DIR__.'/ModeledConversionDealValue.php';

/** Derived private profiles from the admitted journal; never queries native sources. */
final class ModeledConversionProfiles
{
    public const VERSION='admitted_journal_profiles_v1_2';
    public const MAX_SCOPES=8;
    private const REASSESSMENT_SECONDS=864000;
    private const REASSESSMENT_CANDIDATES=64;
    /** Exact source generation, shared with private delivery acceptance. No database/native reads. */
    public static function assessmentNamespace():string
    {
        $paths=['services/ModeledConversionProfiles.php','services/ModeledConversionEarlyForecast.php',
            'services/ModeledConversionSemanticPrediction.php','services/ModeledConversionQuestionnaireContext.php',
            'services/ArchiveResearchSemanticExtractor.php','services/ModeledConversionTextSignals.php',
            'services/ModeledConversionOfferReaction.php','services/ManagerAiTextRedactor.php',
            'services/ModeledConversionAssignedValue.php','services/ModeledConversionDealValue.php',
            'tools/profile_conversion_journal.py','tools/extract_conversion_profiles.py'];
        $sources=[];
        foreach($paths as$path){
            $file=dirname(__DIR__).'/'.$path;
            if(!is_file($file)||!is_string($hash=hash_file('sha256',$file)))self::fail('assessment_source');
            $sources[$path]=$hash;
        }
        return hash('sha256',self::json(['version'=>'profile_assessment_sources_v1','sources'=>$sources]));
    }
    private PDO $pdo;
    private bool $mysql;
    private array $cache=[];
    private array $sourceCache=[];
    private int $rowsRead=0;
    private int $bytesRead=0;
    public function __construct(PDO $pdo,private ?array $relativeWeights=null){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
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
        $this->cache=[];$this->sourceCache=[];$this->rowsRead=0;$this->bytesRead=0;$began=microtime(true);
        try{return $this->transaction(function()use($now,$beforeCommit,$began){
            $r=$this->state(true);
            if(!$r||!(bool)$r['enabled'])return ['status'=>'off','model_calls'=>0,'conversion_uploads'=>0];
            $last=$r['last_receipt_json']!==null?json_decode($r['last_receipt_json'],true,32,JSON_THROW_ON_ERROR):null;
            if($last&&$now<(int)$last['observed_at_unix'])self::fail('clock_regressed');
            $namespace=self::assessmentNamespace();
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
                if(count($next)>self::MAX_SCOPES){$limited=true;break;}
                $affected=$next;
                if($scope){
                    $values=[$scope['platform'],$scope['customer_key'],$scope['conversation_id'],$meta['id'],$status,'anytour',$meta['source_kind'],$meta['source_id']];
                    if($old)$this->q('UPDATE modeled_conversion_profile_sources SET platform=?,customer_key=?,conversation_id=?,journal_id=?,source_status=? WHERE project_key=? AND source_kind=? AND source_id=?',$values);
                    else $this->q('INSERT INTO modeled_conversion_profile_sources(platform,customer_key,conversation_id,journal_id,source_status,project_key,source_kind,source_id) VALUES (?,?,?,?,?,?,?,?)',$values);
                }
                $cursor=(string)$meta['id'];$processed++;
            }
            $cases=[];$assessmentKinds=[];
            foreach($affected as$scope){
                $cases[]=$this->buildCase($scope,$fence);
                $assessmentKinds[$this->scopeKey($scope)]='live';
            }
            $reassessment=['selected'=>0,'written'=>0,'deferred_budget'=>0,'awaiting_fresh_cursor'=>$cursor!==$fence];
            // Sources above the ordinary cursor have not all been indexed yet. Never rebuild
            // an apparently early prefix while captured later outcomes are still pending.
            if($cursor===$fence&&count($cases)<self::MAX_SCOPES){
                foreach($this->reassessmentScopes($now,$namespace,$fence)as$scope){
                    if(count($cases)>=self::MAX_SCOPES)break;
                    $key=$this->scopeKey($scope);
                    if(isset($assessmentKinds[$key]))continue;
                    $reassessment['selected']++;
                    // Temporary spare-budget exhaustion must not become a durable "done" profile.
                    if(!$this->reassessmentFits($scope,$fence)){$reassessment['deferred_budget']++;continue;}
                    $cases[]=$this->buildCase($scope,$fence);$assessmentKinds[$key]='repair';
                }
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
                    $packet=ModeledConversionEarlyForecast::freeze($case,$profile,$document,$first===false||$first===null?null:(string)$first,null,$this->relativeWeights,$this->relativeWeights===null);
                    $profile['contextual_signals']=$packet['contextual_signals'];$profile['early_forecast']=$packet['early_forecast'];
                    $profile['assigned_value']=ModeledConversionAssignedValue::profile($profile,$case);
                    $profile['semantic_prediction']=$packet['semantic_prediction'];
                    $profile['deal_value_prediction']=ModeledConversionDealValue::freeze($profile,$case);
                    $priorPrediction=$this->priorSemanticPrediction($scope,$profile['semantic_prediction']);
                    $profile['prediction_followup']=ModeledConversionPredictionFollowup::compare($profile['semantic_prediction'],$priorPrediction);
                    $forecastReceipt=ModeledConversionEarlyForecast::accumulate($forecastReceipt,$packet);
                    $profile['processing']=['version'=>self::VERSION,'source_sha256'=>hash_file('sha256',__FILE__),'journal_cursor'=>$cursor,'source_fence_journal_id'=>$fence,'assessment_namespace'=>$namespace,'assessment_kind'=>$assessmentKinds[$this->scopeKey($scope)]];
                    $json=self::json($profile);
                    $this->q('INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json,assessment_namespace,assessment_kind) VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [...array_values($scope),$cursor,strtotime($result['extracted_at']),hash('sha256',self::json($document)),$json,$namespace,$assessmentKinds[$this->scopeKey($scope)]]);$written++;
                    if($assessmentKinds[$this->scopeKey($scope)]==='repair')$reassessment['written']++;
                    foreach($profile['coverage']['errors']as$key=>$count)$errors[$key]=($errors[$key]??0)+$count;
                }
            }
            $pending=(int)$this->q('SELECT COUNT(*) FROM modeled_conversion_stream_versions WHERE project_key=? AND id>?',['anytour',$cursor])->fetchColumn();
            $receipt=['status'=>$processed||$written?'profiled':'idle','version'=>self::VERSION,'observed_at_unix'=>$now,
                'processed_revisions'=>$processed,'profiles_written'=>$written,'unbound_entry_revisions'=>$unbound,
                'journal_rows_read'=>$this->rowsRead,'payload_bytes'=>$this->bytesRead,'pending_revisions'=>$pending,
                'limit_reached'=>$limited||$pending>0,'coverage_errors'=>$errors,'trip_status'=>'unknown','model_calls'=>0,'conversion_uploads'=>0,
                'early_forecast'=>$forecastReceipt,'reassessment'=>$reassessment,
                'duration_ms'=>(int)round((microtime(true)-$began)*1000)];
            if($this->rowsRead>500||$this->bytesRead>1048576)self::fail('budget');
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

    /** Shared current-fence source authorization/window projection for live and repair work. */
    private function buildCase(array $scope,string $fence):array
    {
        $bind=array_values($scope);
        $stats=$this->q("SELECT COUNT(*) AS n,SUM(CASE WHEN source_status='scope_excluded' THEN 1 ELSE 0 END) AS denied FROM modeled_conversion_profile_sources WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=?",$bind)->fetch(PDO::FETCH_ASSOC);
        $denied=false;$restored=0;$rows=[];$window=(int)$stats['n']>50;
        $refs=$this->q("SELECT source_kind,source_id,journal_id,source_status FROM modeled_conversion_profile_sources WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=? ORDER BY (source_status='scope_excluded') DESC,journal_id DESC LIMIT 50",$bind)->fetchAll(PDO::FETCH_ASSOC);
        foreach($refs as$ref){
            if($this->rowsRead>=499){$window=true;break;}
            // Progress enumerates dirty scopes; the information fence defines available revisions.
            $entry=$this->readLatest($ref,$fence);
            if($entry===null){$window=true;continue;}
            $entry['progress_journal_id']=(string)$ref['journal_id'];
            $snap=$entry['snapshot'];$status=$snap['source_status']??'';
            if($status==='scope_excluded')$denied=true;
            if($ref['source_status']==='scope_excluded'&&$status==='present'&&$this->scope($snap)===$scope)$restored++;
            $rows[]=$entry;
        }
        // A bounded window cannot assume that an unchecked older exclusion was restored.
        if((int)$stats['denied']>$restored)$denied=true;
        return ['subject'=>$scope,'rows'=>$rows,'window_limited'=>$window,'access_denied'=>$denied];
    }

    /** Latest immutable old-model profiles only; no customer text or historical rewrite. */
    private function reassessmentScopes(int $now,string $namespace,string $fence):array
    {
        $safe="CASE WHEN JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
        $extract=function(string $path)use($safe):string{
            $v="JSON_EXTRACT(".$safe.",'$.semantic_prediction.".$path."')";
            return $this->mysql?'JSON_UNQUOTE('.$v.')':$v;
        };
        $model=$extract('model_sha256');
        $validModel=$this->mysql?$model." REGEXP '^[a-f0-9]{64}$'":'LENGTH('.$model.")=64 AND ".$model." NOT GLOB '*[^a-f0-9]*'";
        $same='s.project_key=p.project_key AND s.platform=p.platform AND s.customer_key=p.customer_key AND s.conversation_id=p.conversation_id';
        $latestBytes='(SELECT '.$this->length().' FROM modeled_conversion_stream_versions v WHERE v.project_key=s.project_key AND v.source_kind=s.source_kind AND v.source_id=s.source_id AND v.id<='.$fence.' ORDER BY v.id DESC LIMIT 1)';
        // Resource admission uses the same latest-50 window as buildCase, never
        // a new whole-history eligibility rule. Older exclusions remain checked there.
        $boundary=' FROM modeled_conversion_profile_sources b WHERE b.project_key=p.project_key AND b.platform=p.platform'
            .' AND b.customer_key=p.customer_key AND b.conversation_id=p.conversation_id'
            ." ORDER BY (b.source_status='scope_excluded') DESC,b.journal_id DESC LIMIT 1 OFFSET 49";
        $priority="(SELECT (b.source_status='scope_excluded')".$boundary.')';
        $journal='(SELECT b.journal_id'.$boundary.')';
        $selected=$same." AND ((s.source_status='scope_excluded'),s.journal_id)>=(COALESCE(".$priority.',0),COALESCE('.$journal.',0))';
        $sourceCount='(SELECT COUNT(*) FROM modeled_conversion_profile_sources s WHERE '.$selected.')';
        $sourceBytes='(SELECT COALESCE(SUM('.$latestBytes.'),0) FROM modeled_conversion_profile_sources s WHERE '.$selected.')';
        $sql='SELECT p.project_key,p.platform,p.customer_key,p.conversation_id,'.$extract('model_sha256').' AS model_sha256'
            .' FROM modeled_conversion_profile_versions p WHERE p.project_key=? AND p.platform IN (\'max\',\'telegram\')'
            .' AND p.observed_at_unix>=? AND p.observed_at_unix<=? AND p.assessment_namespace<>?'
            .' AND '.$extract('version').'=? AND '.$validModel.' AND '.$model.'<>?'
            .' AND NOT EXISTS(SELECT 1 FROM modeled_conversion_profile_versions newer WHERE newer.project_key=p.project_key'
            .' AND newer.platform=p.platform AND newer.customer_key=p.customer_key AND newer.conversation_id=p.conversation_id AND newer.id>p.id)'
            .' AND '.$sourceCount.' BETWEEN 1 AND '.min(50,max(0,498-$this->rowsRead)).' AND '.$sourceBytes.'<='.max(0,1048576-$this->bytesRead)
            .' ORDER BY p.id DESC LIMIT '.self::REASSESSMENT_CANDIDATES;
        $rows=$this->q($sql,['anytour',$now-self::REASSESSMENT_SECONDS,$now,$namespace,
            ModeledConversionSemanticPrediction::VERSION,hash_file('sha256',__DIR__.'/ModeledConversionSemanticPrediction.php')])->fetchAll(PDO::FETCH_ASSOC);
        $scopes=[];
        foreach($rows as$row){
            if(!is_string($row['model_sha256'])||preg_match('/\\A[a-f0-9]{64}\\z/D',$row['model_sha256'])!==1)continue;
            $scopes[]=$this->scope($row);
        }
        return $scopes;
    }

    /** Bounded scalar size preflight; deferred work remains eligible on a later natural tick. */
    private function reassessmentFits(array $scope,string $fence):bool
    {
        $rows=$this->q('SELECT (SELECT '.$this->length().' FROM modeled_conversion_stream_versions v'
            .' WHERE v.project_key=s.project_key AND v.source_kind=s.source_kind AND v.source_id=s.source_id AND v.id<=? ORDER BY v.id DESC LIMIT 1) AS bytes'
            .' FROM modeled_conversion_profile_sources s WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=? ORDER BY (source_status=\'scope_excluded\') DESC,journal_id DESC LIMIT 50',
            [$fence,...array_values($scope)])->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows||count($rows)>50||$this->rowsRead+count($rows)>=499)return false;
        $bytes=0;foreach($rows as$row){if($row['bytes']===null)return false;$bytes+=(int)$row['bytes'];}
        return $this->bytesRead+$bytes<=1048576;
    }

    /** Copy only one bounded immutable derived block; no old source/text read. */
    private function priorSemanticPrediction(array $scope,array $current):?array
    {
        $anchor=$current['current_request_anchor']??null;
        if(!is_string($anchor)||preg_match('/\\Ajournal:[1-9][0-9]{0,19}\\z/D',$anchor)!==1)return null;
        $safe="CASE WHEN JSON_VALID(profile_json) THEN profile_json ELSE '{}' END";
        $block="JSON_EXTRACT(".$safe.",'$.semantic_prediction')";
        $extract=function(string $path)use($safe):string{
            $v="JSON_EXTRACT(".$safe.",'$.semantic_prediction.".$path."')";
            return $this->mysql?'JSON_UNQUOTE('.$v.')':$v;
        };
        $length=$this->mysql?'OCTET_LENGTH('.$block.')':'LENGTH(CAST('.$block.' AS BLOB))';
        $sql='SELECT CASE WHEN '.$length.'<=16384 THEN '.$block.' ELSE NULL END AS prediction_json'
            .' FROM modeled_conversion_profile_versions WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=?'
            .' AND '.$extract('version').'=? AND '.$extract('status').'=? AND '.$extract('current_request_anchor').'=? ORDER BY id LIMIT 1';
        $q=$this->q($sql,[...array_values($scope),'semantic_purchase_heuristic_v1','predicted',$anchor]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $json=$row['prediction_json']??null;
        if(!is_string($json)||strlen($json)>16384)return ['prior_read_status'=>'bounded_block_unavailable'];
        try{$value=json_decode($json,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){return ['prior_read_status'=>'invalid_derived_block'];}
        return is_array($value)?$value:['prior_read_status'=>'invalid_derived_block'];
    }

    private function read(array $meta): ?array
    {
        $key=(string)$meta['id'];if(isset($this->cache[$key]))return $this->cache[$key];
        // Reserve space for JSON framing and derived references within the2MiB protocol.
        if($this->rowsRead>=499||$this->bytesRead+(int)$meta['bytes']>1048576)return null;
        $json=$this->q('SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE project_key=? AND id=?',['anytour',$key])->fetchColumn();$this->rowsRead++;
        return $this->remember($meta,$json);
    }

    /** One size-guarded read per reference; cached immutable revisions do not recopy payloads. */
    private function readLatest(array $ref,string $fence): ?array
    {
        $sourceKey=$ref['source_kind'].':'.$ref['source_id'];
        $cached=$this->sourceCache[$sourceKey]??'0';
        $remaining=max(0,1048576-$this->bytesRead);
        // The remaining-byte bound is an integer literal: SQLite otherwise compares PDO text binds as text.
        $meta=$this->q('SELECT id,source_kind,source_id,version_no,observed_at_unix,'.$this->length().' AS bytes,'
            .'CASE WHEN id=? THEN NULL WHEN '.$this->length().'<='.$remaining.' THEN snapshot_json ELSE NULL END AS snapshot_json'
            .' FROM modeled_conversion_stream_versions WHERE project_key=? AND source_kind=? AND source_id=? AND id<=? ORDER BY id DESC LIMIT 1',
            [$cached,'anytour',$ref['source_kind'],$ref['source_id'],$fence])->fetch(PDO::FETCH_ASSOC);
        $this->rowsRead++;
        if(!$meta)self::fail('missing_journal_revision');
        $key=(string)$meta['id'];$json=$meta['snapshot_json'];
        if(isset($this->cache[$key])){
            if(is_string($json)){
                $this->bytesRead+=strlen($json);
                if($this->bytesRead>1048576)self::fail('budget');
            }
            return $this->cache[$key];
        }
        if($json===null)return null;
        return $this->remember($meta,$json);
    }

    private function remember(array $meta,$json): array
    {
        if(!is_string($json)||strlen($json)!==(int)$meta['bytes'])self::fail('journal_snapshot');
        $this->bytesRead+=strlen($json);
        if($this->bytesRead>1048576)self::fail('budget');
        $key=(string)$meta['id'];
        $this->sourceCache[$meta['source_kind'].':'.$meta['source_id']]=$key;
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
