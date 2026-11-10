<?php
declare(strict_types=1);

/**
 * One installed, reviewed manifest. The production entry never accepts MCP fields,
 * paths, URLs, credentials or commands. Optional constructor inputs are backend
 * synthetic-test dependencies, like the existing provider sender.
 */
final class DevelopmentWorkServerAmendmentGate
{
    public const ROOT_TURN='01a1c0ae-c35d-7ce3-ae55-053adf84b1cc';
    public const ROOT_ARTIFACT='a1843db35c36c86fa63d7bdd4db99a37b2d4ad0084ec52e37eda712c72cb9c7b';
    public const ROOT_COMPLETED_AT=1794262181;
    public const ROOT_TERMINAL_READBACK='b758e8c712f83116f25a913f96c7ca92d72719344b77958ddb82c4617885da1b';
    public const FILE='../config/development_work_one_a02_amendment.json';
    public const MAX_MANIFEST_BYTES=8192;
    public const MAX_RELEASE_PROOF_BYTES=12288;
    private $readBudget=null;
    private array $canonicalReadDigests=[];
    private const REPOSITORY='pyatkoff/max-search-bot';
    public function __construct(private ?array $fixture=null,private $fetcher=null,private $installedSha=null) {}
    /** Exact fixed local bytes only. This observation never approves a manifest. */
    public function configurationReadback(): array
    {
        $file=__DIR__.'/'.self::FILE;
        if(!is_file($file))return ['available'=>false,'reason'=>'local_configuration_unavailable'];
        $raw=@file_get_contents($file,false,null,0,self::MAX_MANIFEST_BYTES+1);
        if(!is_string($raw))return ['available'=>false,'reason'=>'local_configuration_unavailable'];
        return self::configurationSummary($raw);
    }
    /** Pure bounded projection; never returns configuration bodies or arbitrary fields. */
    public static function configurationSummary(string $raw): array
    {
        $size=strlen($raw);
        if($size<1||$size>self::MAX_MANIFEST_BYTES)
            return ['available'=>false,'reason'=>'local_configuration_budget'];
        $proof=['file_sha256'=>hash('sha256',$raw),'bytes'=>$size];
        try{$m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}
        catch(JsonException $error){return $proof+['available'=>false,'reason'=>'local_configuration_invalid_json'];}
        if(!is_array($m)||($m['schema']??null)!=='development-one-a02-amendment-v1'||!is_bool($m['operative']??null))
            return $proof+['available'=>false,'reason'=>'local_configuration_invalid_header'];
        return $proof+['available'=>true,'operative'=>$m['operative']];
    }
    public function manifest(int $now): ?array
    {
        $m=$this->fixture;
        if($m===null){
            $file=__DIR__.'/'.self::FILE;
            if(!is_file($file)||filesize($file)>self::MAX_MANIFEST_BYTES)return null;
            $m=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        }
        if(!is_array($m)||($m['operative']??false)!==true)return null;
        self::ensure(count($m)===5&&!array_diff(['schema','operative','original','review','future'],array_keys($m)),'amendment_shape');
        self::ensure($m['schema']==='development-one-a02-amendment-v1','amendment_schema');
        $o=$m['original'];$r=$m['review'];$f=$m['future'];
        self::ensure(is_array($o)&&is_array($r)&&is_array($f),'amendment_shape');
        self::ensure(($o['queue_id']??null)===DevelopmentWorkServerContract::QUEUE
            &&($o['task_id']??null)===DevelopmentWorkServerContract::A,'amendment_scope');
        self::ensure(($r['reviewer_task_id']??null)===DevelopmentWorkServerContract::REVIEWER
            &&($r['artifact_sha256']??null)===($this->fixture===null?self::ROOT_ARTIFACT:str_repeat('e',64))
            &&($r['turn_id']??null)===($this->fixture===null?self::ROOT_TURN:'SYNTHETIC_ROOT_REVIEW_DONE')
            &&($r['state']??null)==='completed'&&array_key_exists('error',$r)&&$r['error']===null
            &&is_int($r['completed_at']??null)&&$r['completed_at']>=$o['producer_cron_at']&&$r['completed_at']<=$now
            &&($r['source_task_id']??null)===DevelopmentWorkServerContract::SOURCE
            &&preg_match('/\A[a-f0-9]{64}\z/D',$r['canonical_terminal_readback_sha256']??'')===1
            &&($r['frozen_stateless_contract_succeeded']??null)===false,'amendment_original_root_not_completed');
        self::ensure(($f['activation_window_seconds']??null)===86400&&($f['run_window_seconds']??null)===3600&&($f['max_total_calls']??null)===4
            &&($f['already_charged_calls']??null)===2&&($f['remaining_calls']??null)===2
            &&is_array($f['policy']??null)&&DevelopmentWorkServerContract::hash($f['policy'])===DevelopmentWorkServerContract::hash(DevelopmentWorkServerContract::futurePolicy())
            &&is_array($f['implementation']??null),'amendment_future_contract');
        if($this->fixture===null){
            self::ensure($r['completed_at']===self::ROOT_COMPLETED_AT
                &&$r['canonical_terminal_readback_sha256']===self::ROOT_TERMINAL_READBACK,'amendment_other_root_terminal');
            // The operative production document can only concern these actual saved originals.
            $fixed=['queue_id'=>'DEVELOPMENT-CONTROL-20261028',
                'task_id'=>'DEV-CONTROL-COMMISSION-20261109-02',
                'plan_sha256'=>'008035cd6e80a2d63219a1b095406a315ad5608abbc122461a0ecc388de7ed95',
                'spec_sha256'=>'1a7d498a05cc5764e6a00f0c7f732a6c8af399258561e3ceb0cc55367ca12de2',
                'input_sha256'=>'a35f7d2020587b364718c5664c7f5df7e148314d0fdad10bc964195243fcde58',
                'acceptance_contract_sha256'=>'863099f098c64d8fe59f12497f164baa319ca142fe37820f0f698fbc37825cda',
                'binding_sha256'=>'bc459fe29c3de9546228249c02b7c081c43a067df7b8dd5a03b0bafaec3753ad',
                'legacy_sha256'=>'25f86e0f0b99a5b2aefbdd0d22736ee94e95823c49783b1d98441499278922f5',
                'unknown_receipt_sha256'=>'40566206b557db9e5744bae0f84c497fc8c60847e2a05bf7e68047a75927397b',
                'old_deadline_at'=>1794263276,
                'producer_run_id'=>'resp_0a34730f0f880ef0016af23ae1c05887d2b52093614bdeeafd',
                'producer_cron_at'=>1794259681,
                'producer_cron_id'=>'b26e6197c01a69e6de264cf84f80c1e35a7fd8850cffe173d65b535d21f9b063',
                'producer_attempt_id'=>'0e89c8d406c350c26f175f518c3380b7cb3a08fa41e992455908f6db7476c085',
                'producer_job_sha256'=>'7a3dffb5f9ce6d8fbbbf905b7ee8b73b4eb8da41cf4bfdb8a501d690cbc20385',
                'producer_request_sha256'=>'6d0ab3396b00f69ad624cee9da751801de1f3c947062e052ca9a0e8c29bd69bf',
                'producer_response_sha256'=>'ba3a21f7b968e1600dee83bad096957ca1960b6d4b134c42d910f696c5d1ca72',
                'reviewer_attempt_id'=>'d0f6b924551c45f94b97ef1d2c28794e672e7bb43f2407189bcd65a9226093b3',
                'reviewer_job_sha256'=>'f0289200fd2491dc55a75a0202268cd2b234c0ad4d13f2beef692faea56e9e6f',
                'reviewer_request_sha256'=>'7f8893658a4f52b2ffea795b02d28825d53901f93dc62de27d0e38b06ae61270',
                'reviewer_response_sha256'=>'991d74350060693169fa2a3f31e7d9732925bc69693afd2a85728a0d2590d9cc',
                'producer_report_sha256'=>'d6af276602df70bafe9faf894bbe654fcdc89841bcd800b39ea20eccf4a61cb3'];
            foreach($fixed as $key=>$value)self::ensure(($o[$key]??null)===$value,'amendment_other_original');
        }
        self::ensure(strlen(DevelopmentWorkServerContract::json($m))<=self::MAX_MANIFEST_BYTES,'amendment_manifest_budget');
        return $m;
    }
    public function release(array $manifest,callable $readBudget): array
    {
        $this->readBudget=$readBudget;
        $sha=$this->sourceSha();
        self::ensure(is_string($sha)&&preg_match('/\A[a-f0-9]{40}\z/D',$sha)===1,'amendment_installed_source_unavailable');
        $status=$this->read('https://raw.githubusercontent.com/'.self::REPOSITORY.'/diagnostics/deploy_status.json');
        $r=$status['repository_release']??null;$closure=$r['closure']??null;$binding=$closure['binding']??null;
        self::ensure(is_array($r)&&($status['sha']??null)===$sha&&($r['kind']??null)==='installed'
            &&($status['schema']??null)===2&&($r['schema']??null)===1&&($r['phase']??null)==='pending_terminal'
            &&($r['application_sha']??null)===$sha&&($r['repository_sha']??null)===$sha
            &&($r['release']['event']??null)==='push'&&($r['release']['run_attempt']??null)===1
            &&($status['install_attempt']??null)===1&&($status['diagnostics_attempt']??null)===1
            &&(string)($r['release']['run_id']??'')===(string)($status['run_id']??''),'amendment_release_not_installed');
        foreach(['provenance','key','bundle','sync','migration','max_webhook','metrika_cron','smoke','install_checks','diagnostics'] as $stage)
            self::ensure(($status[$stage]??null)==='success'&&($r['stages'][$stage]??null)===['execution'=>'performed','result'=>'success'],
                'amendment_installation_incomplete');
        self::ensure(($binding['comment_id']??null)===6483628958&&($binding['owner_id']??null)===226193297
            &&is_int($binding['pr']??null)&&$binding['pr']>0
            &&($closure['job']??null)==='release-slot'&&($closure['run_attempt']??null)===1
            &&($closure['terminal_step']??null)==='Release only the admitted publisher after all original mandatory gates',
            'amendment_closure_unavailable');
        $required=['deploy.yml','retired-domain-guard.yml','max-tls-preflight.yml','max-production-health.yml',
            'telegram-production-health.yml','publish-conversation-diagnostics.yml','live-session-diagnostics.yml',
            'tour-selection-host.yml','modeled-conversion-monitor.yml','modeled-pipeline-monitor-activation.yml'];
        $gates=$closure['gates']??[];self::ensure(is_array($gates)&&count($gates)===count($required),'amendment_gates_missing');
        foreach($required as $path){
            $match=array_values(array_filter($gates,static fn($g):bool=>is_array($g)&&($g['path']??null)==='.github/workflows/'.$path));
            self::ensure(count($match)===1&&($match[0]['head_sha']??null)===$sha&&($match[0]['run_attempt']??null)===1
                &&(($match[0]['conclusion']??null)==='success'||($path==='modeled-pipeline-monitor-activation.yml'&&($match[0]['conclusion']??null)==='skipped')),
                'amendment_gate_not_successful');
        }
        $comment=$this->read('https://api.github.com/repos/'.self::REPOSITORY.'/issues/comments/6483628958');
        $terminal='PR'.$binding['pr'].': установлено, выпуск свободен';
        self::ensure(($comment['id']??null)===6483628958&&($comment['user']['id']??null)===226193297
            &&($comment['issue_url']??null)==='https://api.github.com/repos/'.self::REPOSITORY.'/issues/1122'
            &&in_array($comment['author_association']??null,['OWNER','MEMBER','COLLABORATOR'],true)
            &&($comment['body']??null)===$terminal&&($closure['expected_terminal']??null)===$terminal
            &&is_string($binding['updated_at']??null)&&is_string($comment['updated_at']??null)
            &&strtotime($comment['updated_at'])>strtotime($binding['updated_at']),'amendment_publisher_not_released');
        $provenance=$this->originalPublication($status,$sha);
        $proof=['schema'=>'development-amendment-original-release-proof-v1','installed_sha'=>$sha,'original_provenance'=>$provenance,
            'status'=>$status,'publisher'=>array_intersect_key($comment,array_flip(['id','issue_url','body','updated_at','author_association']))
                +['owner_id'=>$comment['user']['id']]];
        self::ensure(strlen(DevelopmentWorkServerContract::json($proof))<=self::MAX_RELEASE_PROOF_BYTES,'amendment_release_proof_budget');
        return $proof;
    }
    private static function positiveId(mixed $id): string
    {
        $s=is_int($id)?(string)$id:$id;
        self::ensure(is_string($s)&&preg_match('/\A[1-9][0-9]{0,15}\z/D',$s)===1
            &&(float)$s<=9007199254740991,'amendment_invalid_original_run');return $s;
    }
    /** Narrow original-attempt1 subset of deployment_release.js deploymentProof/authenticatePublication. */
    private function originalPublication(array $status,string $sha): array
    {
        $r=$status['repository_release'];$deployId=self::positiveId($r['release']['run_id']);
        $closerId=self::positiveId($r['closure']['run_id']??null);
        $root='https://api.github.com/repos/'.self::REPOSITORY.'/actions/runs/';
        $deploy=$this->read($root.$deployId);
        $deployment=$this->runEvidence($deploy,$deployId,$sha,'.github/workflows/deploy.yml','push');
        $jobs=$this->jobs($this->read($root.$deployId.'/attempts/1/jobs?per_page=100'));
        $required=['verify-inventory','verify-syntax','verify / application','verify / diagnostics','deploy','verify'];$installed=[];
        foreach($required as $name){
            $job=$this->job($jobs,$name,$deployId,$sha);$steps=[];
            $names=$name==='deploy'?['Build exact deployment bundle','Sync canonical production checkout',
                'Apply database migrations and fence the old hotel contour','Reconcile canonical MAX webhook ownership',
                'Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot']:
                ($name==='verify'?['Verify installed production checks','Download exact installed diagnostic snapshot','Publish verification stage status']:[]);
            foreach($names as $step)$steps[]=$this->step($job,$step);
            $installed[]=['id'=>$job['id'],'name'=>$name,'status'=>'completed','conclusion'=>'success','performed_steps'=>$steps];
        }
        $run=$this->read($root.$closerId);
        $closer=$this->runEvidence($run,$closerId,$sha,'.github/workflows/modeled-conversion-value-export.yml','workflow_run');
        self::ensure(($run['display_title']??null)==='deploy-proof:v1:'.$deployId.':1','amendment_original_closer_origin');
        $all=$this->read($root.$closerId.'/attempts/1/jobs?per_page=100');$rows=$this->jobs($all);
        $job=$this->job($rows,'release-slot',$closerId,$sha);
        // Canonical publication digest is SHA256(canonical JSON + LF), not plain JSON.
        $statusUrl='https://raw.githubusercontent.com/'.self::REPOSITORY.'/diagnostics/deploy_status.json';
        $digest=$this->canonicalReadDigests[$statusUrl]??throw new DomainException('amendment_original_digest_unavailable');
        $bound=$this->step($job,'Bind the terminal candidate to the original closer run — '.$digest);
        $terminal=$this->step($job,'Release only the admitted publisher after all original mandatory gates');
        self::ensure($bound['number']<$terminal['number'],'amendment_original_terminal_order');
        return ['contract'=>'canonical_original_a1_installation_and_closed_publication',
            'record_digest'=>$digest,'deployment'=>$deployment,'installation_jobs'=>$installed,'closer'=>$closer,
            'closure_job'=>['id'=>$job['id'],'name'=>$job['name'],'status'=>'completed','conclusion'=>'success',
                'binding_step'=>$bound,'terminal_step'=>$terminal],
            'original_closer_jobs_sha256'=>DevelopmentWorkServerContract::hash($all)];
    }
    private function runEvidence(array $run,string $id,string $sha,string $path,string $event): array
    {
        self::ensure((string)($run['id']??'')===$id&&($run['run_attempt']??null)===1
            &&($run['path']??null)===$path&&($run['event']??null)===$event
            &&($run['head_sha']??null)===$sha&&($run['head_branch']??null)==='main'
            &&($run['head_repository']['full_name']??null)===self::REPOSITORY
            &&($run['status']??null)==='completed'&&($run['conclusion']??null)==='success'
            &&is_string($run['created_at']??null)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}Z\z/D',$run['created_at'])===1,
            'amendment_original_workflow_unconfirmed');
        return array_intersect_key($run,array_flip(['id','run_attempt','path','event','head_sha','head_branch','status','conclusion','created_at']))
            +['original_metadata_sha256'=>DevelopmentWorkServerContract::hash($run)];
    }
    private function jobs(array $data): array
    {
        $rows=$data['jobs']??null;$total=$data['total_count']??null;
        self::ensure(is_array($rows)&&is_int($total)&&$total===count($rows)&&$total<100
            &&count(array_unique(array_column($rows,'id')))===count($rows),'amendment_original_jobs_incomplete');return $rows;
    }
    private function job(array $rows,string $name,string $run,string $sha): array
    {
        $found=array_values(array_filter($rows,static fn($j):bool=>is_array($j)&&($j['name']??null)===$name));
        self::ensure(count($found)===1,'amendment_original_job_missing');$j=$found[0];self::positiveId($j['id']??null);
        self::ensure((string)($j['run_id']??'')===$run&&($j['head_sha']??null)===$sha&&($j['status']??null)==='completed'
            &&($j['conclusion']??null)==='success'&&is_array($j['steps']??null),'amendment_original_job_unconfirmed');return $j;
    }
    private function step(array $job,string $name): array
    {
        $found=array_values(array_filter($job['steps'],static fn($s):bool=>is_array($s)&&($s['name']??null)===$name));
        self::ensure(count($found)===1,'amendment_original_step_missing');$s=$found[0];
        self::ensure(($s['status']??null)==='completed'&&($s['conclusion']??null)==='success'
            &&is_int($s['number']??null)&&$s['number']>0,'amendment_original_step_unconfirmed');
        return array_intersect_key($s,array_flip(['number','name','status','conclusion']));
    }
    public function sourceSha(): ?string
    {return $this->installedSha===null?self::checkoutSha():($this->installedSha)();}
    private function read(string $url): array
    {
        if($this->readBudget!==null)($this->readBudget)();
        if($this->fetcher!==null){$r=($this->fetcher)($url);self::ensure(is_array($r),'amendment_public_metadata_unavailable');
            $this->canonicalReadDigests[$url]=hash('sha256',self::canonicalValue($r)."\n");return $r;}
        if(!function_exists('curl_init'))throw new DomainException('amendment_public_metadata_unavailable');
        $bytes='';$h=curl_init($url);
        if($h===false)throw new DomainException('amendment_public_metadata_unavailable');
        try{
            self::ensure(curl_setopt_array($h,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json',
                'User-Agent: AnyTour-DEV-one-A02-amendment'],CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_WRITEFUNCTION=>static function($unused,string $chunk)use(&$bytes):int{
                    if(strlen($bytes)+strlen($chunk)>131072)return 0;$bytes.=$chunk;return strlen($chunk);
                }]),'amendment_public_metadata_unavailable');
            $ok=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);
            self::ensure($ok!==false&&curl_errno($h)===0&&$code===200,'amendment_public_metadata_unavailable');
        }finally{curl_close($h);}
        $this->canonicalReadDigests[$url]=hash('sha256',self::canonicalValue(json_decode($bytes,false,32,JSON_THROW_ON_ERROR))."\n");
        $r=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        self::ensure(is_array($r),'amendment_public_metadata_unavailable');return $r;
    }
    /** Preserve empty object versus array exactly as the canonical Node publication contract. */
    private static function canonicalValue(mixed $v): string
    {
        if($v instanceof stdClass){
            $vars=get_object_vars($v);ksort($vars,SORT_STRING);$parts=[];
            foreach($vars as $key=>$value)$parts[]=json_encode((string)$key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).':'.self::canonicalValue($value);
            return '{'.implode(',',$parts).'}';
        }
        if(is_array($v)){
            if(array_is_list($v))return '['.implode(',',array_map(self::canonicalValue(...),$v)).']';
            ksort($v,SORT_STRING);$parts=[];
            foreach($v as $key=>$value)$parts[]=json_encode((string)$key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).':'.self::canonicalValue($value);
            return '{'.implode(',',$parts).'}';
        }
        return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function checkoutSha(): ?string
    {
        $git=dirname(__DIR__).'/.git';$head=$git.'/HEAD';
        if(!is_file($head)||filesize($head)>128)return null;$value=trim((string)file_get_contents($head));
        if(preg_match('/\A[a-f0-9]{40}\z/D',$value))return $value;
        if($value!=='ref: refs/heads/main')return null;$ref=$git.'/refs/heads/main';
        if(is_file($ref)&&filesize($ref)<=128)return trim((string)file_get_contents($ref));
        $packed=$git.'/packed-refs';if(!is_file($packed)||filesize($packed)>262144)return null;
        foreach(file($packed,FILE_IGNORE_NEW_LINES)?:[] as $line)
            if(preg_match('/\A([a-f0-9]{40}) refs\/heads\/main\z/D',$line,$m))return $m[1];
        return null;
    }
    private static function ensure(bool $ok,string $reason): void
    {if(!$ok)throw new DomainException($reason);}
}


/** No callable MCP operation. Only the existing ordinary server tick invokes this. */
trait DevelopmentWorkServerAmendment
{
    private ?DevelopmentWorkServerAmendmentGate $serverAmendmentGate=null;
    private function serverAmendmentRow(array $q): ?array
    {return $this->one('SELECT * FROM development_work_server_amendments WHERE queue_id=? AND task_id=?',
        [$q['queue_id'],DevelopmentWorkServerContract::A]);}
    private function serverFutureImplementation(): array
    {
        $out=$this->serverImplementation();
        foreach(['DevelopmentWorkServerAmendment.php','DevelopmentWorkHealth.php','DevelopmentWorkCronVerifier.php',
            '../migrations/097_development_work_server_amendment.sql','../tools/deployment_release.js',
            '../tools/release_slot_close.js','../.github/workflows/deploy.yml','../.github/workflows/modeled-conversion-value-export.yml'] as $file){
            $hash=hash_file('sha256',__DIR__.'/'.$file);
            if(!is_string($hash))throw new DomainException('amendment_implementation_unavailable');$out['files'][$file]=$hash;
        }
        $s=$this->pdo->query('SELECT queue_id,task_id,manifest_sha256,future_binding_sha256,state FROM development_work_server_amendments WHERE 1=0');
        $s->closeCursor();$indexes=[];
        if($this->driver==='mysql'){
            foreach($this->pdo->query('SHOW INDEX FROM development_work_server_amendments')->fetchAll(PDO::FETCH_ASSOC) as $row)
                if((int)$row['Non_unique']===0)$indexes[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
        }else{
            foreach($this->pdo->query('PRAGMA index_list(development_work_server_amendments)')->fetchAll(PDO::FETCH_ASSOC) as $row){
                if((int)$row['unique']!==1||!preg_match('/\A[A-Za-z0-9_]+\z/D',$row['name']))continue;
                foreach($this->pdo->query('PRAGMA index_info('.$row['name'].')')->fetchAll(PDO::FETCH_ASSOC) as $col)
                    $indexes[$row['name']][(int)$col['seqno']]=$col['name'];
            }
        }
        $sets=[];foreach($indexes as $cols){ksort($cols);$sets[]=implode(',',array_values($cols));}
        foreach(['queue_id,task_id','manifest_sha256'] as $needed)
            if(!in_array($needed,$sets,true))throw new DomainException('amendment_unique_schema_required');
        $raw=$this->pdo->query('SELECT attempt_id,response_blob,actual_request_blob FROM development_work_server_raw_bytes WHERE 1=0');$raw->closeCursor();$types=[];$unique=[];
        if($this->driver==='mysql'){
            foreach($this->pdo->query('SHOW COLUMNS FROM development_work_server_raw_bytes')->fetchAll(PDO::FETCH_ASSOC) as $col)
                $types[$col['Field']]=strtolower($col['Type']);
            foreach($this->pdo->query('SHOW INDEX FROM development_work_server_raw_bytes')->fetchAll(PDO::FETCH_ASSOC) as $row)
                if((int)$row['Non_unique']===0)$unique[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
        }else{
            foreach($this->pdo->query('PRAGMA table_info(development_work_server_raw_bytes)')->fetchAll(PDO::FETCH_ASSOC) as $col)
                $types[$col['name']]=strtolower($col['type']);
            foreach($this->pdo->query('PRAGMA index_list(development_work_server_raw_bytes)')->fetchAll(PDO::FETCH_ASSOC) as $row){
                if((int)$row['unique']!==1||!preg_match('/\A[A-Za-z0-9_]+\z/D',$row['name']))continue;
                foreach($this->pdo->query('PRAGMA index_info('.$row['name'].')')->fetchAll(PDO::FETCH_ASSOC) as $col)
                    $unique[$row['name']][(int)$col['seqno']]=$col['name'];
            }
        }
        $sets=[];foreach($unique as $cols){ksort($cols);$sets[]=implode(',',array_values($cols));}
        if(($types['response_blob']??null)!=='mediumblob'||($types['actual_request_blob']??null)!=='mediumblob'
            ||!in_array('attempt_id',$sets,true))throw new DomainException('amendment_byte_storage_schema_required');
        $out['raw_storage_types']=['response_blob'=>'mediumblob','actual_request_blob'=>'mediumblob','unique_attempt_id'=>true];
        $out['schema']='097_development_work_server_amendment';$out['amendment_uniqueness']=true;
        $out['byte_exact_invalid_utf8_storage']='new_raw_blob_sidecar_old_TEXT_unchanged';return $out;
    }
    private function serverAmendmentEffective(array $q,array $c,array $original,bool $current): array
    {
        $a=$this->serverAmendmentRow($q);
        if($a===null||$a['state']!=='applied')return $original;
        if(hash('sha256',$a['manifest_bytes'])!==$a['manifest_sha256']
            ||!is_string($a['future_binding_bytes'])||hash('sha256',$a['future_binding_bytes'])!==$a['future_binding_sha256'])
            throw new DomainException('amendment_stored_binding_conflict');
        $m=json_decode($a['manifest_bytes'],true,32,JSON_THROW_ON_ERROR);
        $b=json_decode($a['future_binding_bytes'],true,32,JSON_THROW_ON_ERROR);
        if($m['original']['binding_sha256']!==$c['binding_sha256']||$m['original']['legacy_sha256']!==$c['legacy_sha256']
            ||$b['manifest_sha256']!==$a['manifest_sha256']||$b['original_binding_sha256']!==$c['binding_sha256']
            ||$b['applied_at']!==(int)$a['applied_at']||$b['deadline_at']!==(int)$a['applied_at']+3600
            ||$b['future_policy_sha256']!==self::hash($m['future']['policy'])
            ||$b['future_implementation_sha256']!==self::hash($m['future']['implementation']))
            throw new DomainException('amendment_stored_binding_conflict');
        if($current){
            $actual=($this->serverAmendmentGate??new DevelopmentWorkServerAmendmentGate())->manifest($this->timestamp());
            if($actual===null||self::hash($actual)!==$a['manifest_sha256']
                ||self::hash($this->serverFutureImplementation())!==$b['future_implementation_sha256']
                ||self::hash(DevelopmentWorkServerContract::futurePolicy())!==$b['future_policy_sha256'])
                throw new DomainException('amendment_current_binding_conflict');
        }
        // The original saved binding is never rewritten. This effective projection is B-only.
        $original['proposal']['implementation']=$m['future']['implementation'];
        $original['proposal']['policy']=$m['future']['policy'];
        $original['deadline_at']=$b['deadline_at'];$original['amendment_sha256']=$a['manifest_sha256'];
        $original['amendment_applied_at']=$b['applied_at'];
        return $original;
    }
    private function serverAmendmentOriginal(array $q,array $c,array $lane,array $m): array
    {
        $this->ownsLane($q,$lane);$this->project($q);$this->serverBinding($q,$c);
        $this->serverExecutionAuthority($q,$c['execution_connection_id']);$o=$m['original'];
        $item=$this->current($q,DevelopmentWorkServerContract::A,$o['spec_sha256']);
        if($q['state']!=='unknown'||$item['state']!=='unknown'||!is_string($item['claim_sha256'])||preg_match('/\\A[a-f0-9]{64}\\z/D',$item['claim_sha256'])!==1
            ||$q['plan_sha256']!==$o['plan_sha256']||$item['input_sha256']!==$o['input_sha256']
            ||$item['acceptance_contract_sha256']!==$o['acceptance_contract_sha256']
            ||$c['binding_sha256']!==$o['binding_sha256']||$c['legacy_sha256']!==$o['legacy_sha256']
            ||$this->serverLegacy($q)!==$o['legacy_sha256']||(int)$c['deadline_at']!==$o['old_deadline_at']
            ||(int)$c['reserved_calls']!==2||(int)$q['task_count']!==2||(int)$q['accepted_count']!==0
            ||(int)$q['blocked_count']!==1||(int)$q['retirement_count']!==1)
            throw new DomainException('amendment_original_state_conflict');
        $proof=$this->serverProof($q,$c);
        if(count($proof['attempts'])!==2||count($proof['receipts'])!==1
            ||$this->one('SELECT task_id FROM development_work_resolutions WHERE queue_id=? AND task_id=?',[$q['queue_id'],$item['task_id']]))
            throw new DomainException('amendment_original_history_conflict');
        $producer=$this->one('SELECT * FROM development_work_server_attempts WHERE attempt_id=?',[$o['producer_attempt_id']]);
        $failed=$this->one('SELECT * FROM development_work_server_attempts WHERE attempt_id=?',[$o['reviewer_attempt_id']]);
        foreach(['producer'=>$producer,'reviewer'=>$failed] as $role=>$row){
            if(!$row||$row['queue_id']!==$q['queue_id']||$row['task_id']!==$item['task_id']||$row['role']!==$role)
                throw new DomainException('amendment_original_attempt_conflict');
            foreach(['job','request','response'] as $part)
                if($row[$part.'_sha256']!==$o[$role.'_'.$part.'_sha256']
                    ||!is_string($row[$part.'_bytes'])||hash('sha256',$row[$part.'_bytes'])!==$row[$part.'_sha256'])
                    throw new DomainException('amendment_original_byte_conflict');
        }
        if($producer['state']!=='succeeded'||$producer['response_id']!==$o['producer_run_id']
            ||$item['current_run_id']!==$producer['response_id']||$producer['report_sha256']!==$o['producer_report_sha256']
            ||hash('sha256',$producer['report_bytes'])!==$producer['report_sha256']||(int)$producer['cron_at']!==$o['producer_cron_at']||$producer['cron_id']!==$o['producer_cron_id']
            ||$failed['state']!=='unknown'||$failed['response_id']!==null||$failed['report_bytes']!==null)
            throw new DomainException('amendment_original_result_conflict');
        $job=json_decode($producer['job_bytes'],true,32,JSON_THROW_ON_ERROR);
        $wire=json_decode($producer['request_bytes'],true,32,JSON_THROW_ON_ERROR);
        $payload=json_decode($wire['input'][1]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
        if($job['attempt_id']!==$producer['attempt_id']||$job['binding_sha256']!==$c['binding_sha256']
            ||$job['spec_sha256']!==$item['spec_sha256']||$job['input_sha256']!==$item['input_sha256']
            ||$job['cron_id']!==$producer['cron_id']||$job['event_id']!==$producer['event_id']
            ||$job['wire_sha256']!==$producer['request_sha256']||self::hash($payload['input'])!==$item['input_sha256'])
            throw new DomainException('amendment_original_job_lineage');
        $originalMeta=null;
        $parsed=ManagerDraftClient::parseHotelResponse($producer['response_bytes'],200,static function($meta)use(&$originalMeta):void{$originalMeta=$meta;});
        $oldBinding=json_decode($c['binding_bytes'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($originalMeta)||$originalMeta['status']!=='completed'||$originalMeta['response_id']!==$producer['response_id']
            ||$originalMeta['model']!==$oldBinding['proposal']['policy']['profile']['model']
            ||self::canonical($parsed)!==$producer['report_bytes'])
            throw new DomainException('amendment_original_completed_transport');
        DevelopmentWorkServerContract::validateReport($parsed,'producer','commissioning',$payload['facts'],null,$payload['evidence']);
        $observation=json_decode($failed['metadata_bytes'],true,32,JSON_THROW_ON_ERROR);
        if(($observation['http_status']??null)!==200||($observation['wire_complete']??null)!==false
            ||($observation['response_capture']??null)!=='prefix_truncated_at_budget'
            ||($observation['retained_response_is_complete']??null)!==false||($observation['provider']??null)!==null)
            throw new DomainException('amendment_failed_review_changed');
        $original=$this->verifiedReceipt($q,$item,false);
        if($original['record']['receipt_sha256']!==$o['unknown_receipt_sha256']||$original['receipt']['outcome']!=='unknown'
            ||$original['receipt']['run_id']!==$producer['response_id']||$original['receipt']['owned_claims_released']!==false)
            throw new DomainException('amendment_original_unknown_changed');
        $fingerprint=self::hash(['queue'=>$q,'control'=>$c,'item'=>$item,'producer'=>$producer,
            'failed_reviewer'=>$failed,'original_unknown'=>$original,'lane'=>$lane]);
        return [$item,$producer,$failed,$original,$fingerprint];
    }
    /** At most one public metadata pair per ordinary minute, and 30 total. No DB lock over HTTP. */
    private function serverApplyReviewedAmendment(array $origin): ?array
    {
        $gate=$this->serverAmendmentGate??new DevelopmentWorkServerAmendmentGate();$now=$this->timestamp();
        $m=$gate->manifest($now);if($m===null)return null;
        if($origin['started_at']>$now||$now-$origin['started_at']>30)
            return ['handled'=>true,'state'=>'hold','reason'=>'amendment_fresh_ordinary_cron_required','provider_calls'=>0];
        $hash=self::hash($m);$bytes=self::canonical($m);
        $prepared=$this->transaction(function()use($m,$hash,$bytes,$now):array{
            $lane=$this->lane();$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[DevelopmentWorkServerContract::QUEUE]);
            if(!$q||!($c=$this->serverControl($q)))return ['skip'=>true];
            $a=$this->serverAmendmentRow($q);
            if($a&&$a['manifest_sha256']!==$hash)throw new DomainException('amendment_manifest_conflict');
            if($a&&$a['state']==='applied')return ['skip'=>true];
            if($now>=$m['review']['completed_at']+$m['future']['activation_window_seconds'])
                return ['wait'=>'amendment_activation_expired'];
            [, , , ,$fingerprint]=$this->serverAmendmentOriginal($q,$c,$lane,$m);
            if($a&&$a['original_state_sha256']!==$fingerprint)throw new DomainException('amendment_retained_state_changed');
            if(self::hash($this->serverFutureImplementation())!==self::hash($m['future']['implementation']))
                throw new DomainException('amendment_current_implementation_conflict');
            if(!$a){
                $this->execute('INSERT INTO development_work_server_amendments(queue_id,task_id,manifest_sha256,manifest_bytes,state,original_state_sha256,metadata_reads,probe_count) VALUES(?,?,?,?,?,?,0,0)',
                    [$q['queue_id'],DevelopmentWorkServerContract::A,$hash,$bytes,'pending',$fingerprint]);
                $a=$this->serverAmendmentRow($q);
            }
            if($a['manifest_bytes']!==$bytes)throw new DomainException('amendment_manifest_conflict');
            $slot=intdiv($now,60);
            if((int)$a['probe_count']>=30)return ['wait'=>'amendment_public_metadata_budget'];
            if($a['probe_slot']!==null&&$slot<=(int)$a['probe_slot'])return ['wait'=>'amendment_waiting_next_cron'];
            $this->execute('UPDATE development_work_server_amendments SET probe_count=probe_count+1,probe_slot=? WHERE queue_id=? AND task_id=?',
                [$slot,$q['queue_id'],DevelopmentWorkServerContract::A]);
            return ['probe'=>true,'state_fingerprint'=>$fingerprint];
        });
        if(isset($prepared['skip']))return null;
        if(isset($prepared['wait']))return $this->serverAmendmentWait($prepared['wait']);
        $readBudget=function()use($hash):void{
            $this->transaction(function()use($hash):void{
                $lane=$this->lane();$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[DevelopmentWorkServerContract::QUEUE]);
                $row=$this->serverAmendmentRow($q);
                if(!$row||$row['manifest_sha256']!==$hash||$row['state']!=='pending'||(int)$row['metadata_reads']>=60)
                    throw new DomainException('amendment_public_metadata_read_budget');
                $this->execute('UPDATE development_work_server_amendments SET metadata_reads=metadata_reads+1 WHERE queue_id=? AND task_id=?',
                    [$q['queue_id'],DevelopmentWorkServerContract::A]);
            });
        };
        try{$release=$gate->release($m,$readBudget);}
        catch(DomainException|JsonException $error){return $this->serverAmendmentWait(
            preg_match('/\\Aamendment_[a-z_]+\\z/D',$error->getMessage())?$error->getMessage():'amendment_public_metadata_unavailable');}
        $releaseBytes=self::canonical($release);
        return $this->transaction(function()use($m,$hash,$origin,$releaseBytes,$release,$gate,$prepared):array{
            $lane=$this->lane();$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[DevelopmentWorkServerContract::QUEUE]);
            $c=$this->serverControl($q);$a=$this->serverAmendmentRow($q);
            if(!$a||$a['manifest_sha256']!==$hash)throw new DomainException('amendment_manifest_conflict');
            if($a['state']==='applied')return ['handled'=>true,'state'=>'waiting_next_cron','provider_calls'=>0];
            $now=$this->timestamp();
            if($now>=$m['review']['completed_at']+$m['future']['activation_window_seconds']
                ||$origin['started_at']>$now||$now-$origin['started_at']>30)
                return ['handled'=>true,'state'=>'hold','reason'=>'amendment_activation_expired_or_stale_cron','provider_calls'=>0];
            [$item,$producer,,, $fingerprint]=$this->serverAmendmentOriginal($q,$c,$lane,$m);
            if($fingerprint!==$prepared['state_fingerprint']||$a['original_state_sha256']!==$fingerprint)
                throw new DomainException('amendment_retained_state_changed');
            $local=($this->serverAmendmentGate??new DevelopmentWorkServerAmendmentGate())->manifest($now);
            if($gate->sourceSha()!==$release['installed_sha']||$local===null||self::hash($local)!==$hash
                ||self::hash($this->serverFutureImplementation())!==self::hash($m['future']['implementation'])
                ||self::hash(DevelopmentWorkServerContract::futurePolicy())!==self::hash($m['future']['policy']))
                throw new DomainException('amendment_before_commit_drift');
            $binding=['schema'=>'development-one-a02-future-binding-v1','manifest_sha256'=>$hash,
                'original_binding_sha256'=>$c['binding_sha256'],'future_policy_sha256'=>self::hash($m['future']['policy']),
                'future_implementation_sha256'=>self::hash($m['future']['implementation']),'applied_at'=>$now,
                'deadline_at'=>$now+$m['future']['run_window_seconds'],'max_total_calls'=>4,'already_charged_calls'=>2,'remaining_calls'=>2];
            $readback=self::hash(['original'=>$m['original'],'root_artifact'=>$m['review']['artifact_sha256'],
                'root_terminal'=>$m['review'],'release_proof'=>hash('sha256',$releaseBytes),'future_binding'=>$binding]);
            $acceptance=self::hash(['schema'=>'development-one-a02-supplementary-acceptance-v1','readback'=>$readback,
                'original_stateless_contract_succeeded'=>false,'manifest_sha256'=>$hash]);
            $receipt=['run_id'=>$producer['response_id'],'outcome'=>'accepted_readback','result_sha256'=>$producer['report_sha256'],
                'readback_sha256'=>$readback,'acceptance_sha256'=>$acceptance,
                'reviewer_run_id'=>$m['review']['turn_id'],'owned_claims_released'=>true];
            self::receipt($receipt);$json=self::canonical($receipt);$receiptHash=hash('sha256',$json);
            $resolution=self::hash([$q['queue_id'],$item['task_id'],$item['spec_sha256'],$m['original']['unknown_receipt_sha256'],$producer['response_id'],$receiptHash]);
            $this->execute('INSERT INTO development_work_resolutions(queue_id,task_id,unknown_receipt_sha256,resolution_sha256,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?,?)',
                [$q['queue_id'],$item['task_id'],$m['original']['unknown_receipt_sha256'],$resolution,$receiptHash,$json,$this->now()]);
            $bindingBytes=self::canonical($binding);
            $this->execute('UPDATE development_work_server_amendments SET future_binding_sha256=?,future_binding_bytes=?,state=?,release_proof_bytes=?,release_proof_sha256=?,acceptance_sha256=?,receipt_sha256=?,receipt_json=?,applied_cron_id=?,applied_at=? WHERE queue_id=? AND task_id=? AND state=? AND future_binding_sha256 IS NULL',
                [hash('sha256',$bindingBytes),$bindingBytes,'applied',$releaseBytes,hash('sha256',$releaseBytes),$acceptance,$receiptHash,$json,
                    $origin['cron_id'],$now,$q['queue_id'],$item['task_id'],'pending']);
            $saved=$this->serverAmendmentRow($q);
            if(!$saved||$saved['state']!=='applied'||$saved['future_binding_sha256']!==hash('sha256',$bindingBytes)
                ||$saved['receipt_sha256']!==$receiptHash)throw new DomainException('amendment_commit_cas_conflict');
            $this->execute('UPDATE development_work_queues SET last_resolution_task_id=? WHERE queue_id=?',[$item['task_id'],$q['queue_id']]);
            $next=$this->finish($q,$item,$receipt,$lane,$this->now());
            $this->execute('UPDATE development_work_server_events SET state=? WHERE queue_id=? AND task_id=?',['consumed',$q['queue_id'],$item['task_id']]);
            $this->serverMaterializeB($next,$origin['cron_id'],$now);
            $this->serverExecutionAuthority($q,$c['execution_connection_id']);
            $fresh=$this->owned($q['queue_id'],(int)$q['admin_id'],$q['oauth_connection_id']);
            $this->serverBinding($fresh,$this->serverControl($fresh),true);
            if($gate->sourceSha()!==$release['installed_sha'])throw new DomainException('amendment_source_before_commit_changed');
            $this->serverAssertFullReadback($fresh);
            return ['handled'=>true,'state'=>'ready','accepted_task'=>$item['task_id'],'acceptance_kind'=>'supplementary_original_ROOT_review',
                'receipt_sha256'=>$receiptHash,'provider_calls'=>0];
        });
    }
    /** Release waiting never fakes a successful work result or blocks the publication's schedule proof. */
    private function serverAmendmentWait(string $reason): array
    {
        return $this->transaction(function()use($reason):array{
            $lane=$this->lane();$q=$this->one('SELECT * FROM development_work_queues WHERE queue_id=?'.$this->lock(),[DevelopmentWorkServerContract::QUEUE]);
            $c=$this->serverControl($q);$result=$this->serverHeldObservation($q,$c,$lane);
            return $result+['amendment_wait_reason'=>$reason];
        });
    }
    private function serverAmendmentParent(array $q,array $a): array
    {
        $item=$this->item($q['queue_id'],DevelopmentWorkServerContract::A);$this->acceptedItemReceipt($q,$item);
        $resolved=$this->verifiedReceipt($q,$item,true);$r=$resolved['receipt'];
        $m=json_decode($a['manifest_bytes'],true,32,JSON_THROW_ON_ERROR);
        if($resolved['record']['receipt_sha256']!==$a['receipt_sha256']||$r['reviewer_run_id']!==$m['review']['turn_id']
            ||$r['acceptance_sha256']!==$a['acceptance_sha256']||$r['result_sha256']!==$m['original']['producer_report_sha256'])
            throw new DomainException('amendment_parent_lineage');
        return ['task_id'=>$item['task_id'],'receipt_sha256'=>$a['receipt_sha256'],'result_sha256'=>$r['result_sha256'],
            'review_sha256'=>$m['review']['artifact_sha256'],'acceptance_sha256'=>$r['acceptance_sha256'],
            'readback_sha256'=>$r['readback_sha256'],'producer_run_id'=>$r['run_id'],'reviewer_run_id'=>$r['reviewer_run_id'],
            'producer_cron_id'=>$m['original']['producer_cron_id'],'reviewer_cron_id'=>null,
            'acceptance_cron_id'=>$a['applied_cron_id'],'amendment_sha256'=>$a['manifest_sha256'],
            'reviewer_execution_kind'=>'completed_existing_native_ROOT_review','outcome'=>'accepted_readback'];
    }
    private function serverAmendmentProof(array $q): ?array
    {
        $a=$this->serverAmendmentRow($q);if(!$a)return null;
        foreach(['manifest','future_binding','release_proof','receipt'] as $part){
            $key=$part==='receipt'?'receipt_json':$part.'_bytes';$value=$a[$key];
            if($value!==null&&hash('sha256',$value)!==$a[$part.'_sha256'])
                throw new DomainException('amendment_original_readback_failed');
            $a[$part.'_base64']=$value===null?null:base64_encode($value);unset($a[$key]);
        }
        $a['original_frozen_stateless_acceptance']=false;$a['supplementary_acceptance_count']=$a['state']==='applied'?1:0;
        return $a;
    }
}
