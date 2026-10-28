<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerTourSelectionEditorHost.php';

/** One private code artifact and one identity-free tours front; no auth/config copy. */
final class TourSelectionEditorDeployment
{
    public function __construct(private string $backend,private string $site,private string $releases) {}

    private static function write(string $path,string $bytes): void
    {
        if (is_link($path)) throw new RuntimeException('foreign_editor_file');
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(8));
        if (file_put_contents($tmp,$bytes)!==strlen($bytes) || !chmod($tmp,0600) || !rename($tmp,$path)) throw new RuntimeException('editor_write_failed');
    }

    private function guard(): void
    {
        if (is_link($this->site) || realpath($this->site)!==$this->site || !is_writable($this->site)) throw new RuntimeException('editor_site_unavailable');
        if (function_exists('posix_geteuid') && fileowner($this->site)!==posix_geteuid()) throw new RuntimeException('foreign_editor_site');
        $front=$this->site.'/editor.php';
        if (is_link($front) || file_exists($front) && (!is_file($front) || file_get_contents($front)!==ManagerTourSelectionEditorHost::front($this->backend))) throw new RuntimeException('foreign_editor_route');
        if (is_link($this->releases) || file_exists($this->releases) && !is_dir($this->releases)) throw new RuntimeException('foreign_editor_releases');
    }

    private function current(): ?string
    {
        $path=$this->releases.'/current';
        if (!file_exists($path) && !is_link($path)) return null;
        if (!is_link($path)) throw new RuntimeException('foreign_editor_current');
        $target=readlink($path);
        if (!is_string($target) || !preg_match('~\A'.preg_quote($this->releases,'~').'/[a-f0-9]{64}-[a-f0-9]{64}\z~D',$target) || realpath($target)!==$target) throw new RuntimeException('foreign_editor_current');
        return $target;
    }

    private function acceptCode(array $installed): void
    {
        self::write($installed['directory'].'/accepted.json',json_encode(['schema'=>1,'package_id'=>$installed['package_id'],'seal_sha256'=>hash_file('sha256',$installed['directory'].'/deployment.json')],JSON_THROW_ON_ERROR));
    }

    private function point(string $target): void
    {
        $path=$this->releases.'/current';$tmp=$path.'.tmp-'.bin2hex(random_bytes(8));
        if (!symlink($target,$tmp) || !rename($tmp,$path)) throw new RuntimeException('editor_activation_failed');
    }

    private bool $locked=false;

    private function exclusive(callable $action): array
    {
        if ($this->locked) return $action();
        $this->guard();
        if (!is_dir($this->releases) && !mkdir($this->releases,0700,true)) throw new RuntimeException('editor_release_root_unavailable');
        $path=$this->releases.'/operation.lock';
        if (is_link($path)) throw new RuntimeException('foreign_editor_lock');
        $lock=fopen($path,'c+b');
        if (!$lock || !chmod($path,0600) || !flock($lock,LOCK_EX|LOCK_NB)) {if(is_resource($lock))fclose($lock);throw new RuntimeException('editor_operation_busy');}
        $this->locked=true;
        try {return $action();} finally {$this->locked=false;flock($lock,LOCK_UN);fclose($lock);}
    }

    public function install(string $output,string $source): array
    {
        return $this->exclusive(fn()=>$this->installPackage($output,$source,null));
    }

    /** CLI publisher authority supplies exact immutable source, never an HTTP/client grant. */
    public function installIndependent(string $output,string $candidate,string $backendSha,string $source,string $expectedCurrent,string $operation): array
    {
        return $this->exclusive(function()use($output,$candidate,$backendSha,$source,$expectedCurrent,$operation):array {
            return $this->installPackage($output,$source,$this->admission($output,$candidate,$backendSha,$source,$expectedCurrent,$operation,false));
        });
    }

    private function admission(string $output,string $candidate,string $backendSha,string $source,string $expectedCurrent,string $operation,bool $common): array
    {
            $args=['python3',$this->backend.'/modules/tour-selections/admit-editor.py',
                '--backend',$this->backend,'--candidate',$candidate,'--output',$output,'--backend-sha',$backendSha,
                '--source-sha',$source,'--current-package',$expectedCurrent,'--operation',$operation];
            if ($common) $args[]='--common-release';
            $process=proc_open($args,
                [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('editor_admission_unavailable');
            fclose($pipes[0]);$json=stream_get_contents($pipes[1],32769);fclose($pipes[1]);$error=stream_get_contents($pipes[2],4097);fclose($pipes[2]);$status=proc_close($process);
            if ($status!==0 || !is_string($json) || strlen($json)>32768) throw new RuntimeException('editor_source_not_admitted');
            $grant=json_decode($json,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($grant) || ($grant['kind']??null)!==($common?'selection-editor-common-release-v1':'selection-editor-source-admission-v1') || ($grant['source_sha']??null)!==$source
                || ($grant['backend_sha']??null)!==$backendSha || ($grant['expected_current_package']??null)!==$expectedCurrent || ($grant['operation']??null)!==$operation) throw new RuntimeException('invalid_editor_admission');
            return $grant;
    }

    private function noPending(): void
    {
        if (file_exists($this->releases.'/pending.json') || is_link($this->releases.'/pending.json')) throw new RuntimeException('unfinished_editor_operation');
    }

    /** A different operation ID cannot bypass a reservation whose result is unknown. */
    private function resolvedOperations(): void
    {
        $dir=$this->releases.'/operations';
        if (!file_exists($dir) && !is_link($dir)) return;
        if (is_link($dir) || !is_dir($dir)) throw new RuntimeException('foreign_editor_operations');
        $count=0;
        foreach (new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $file) {
            if (++$count>256 || $file->isLink() || !$file->isFile() || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{7,95}\.json\z/D',$file->getFilename())) throw new RuntimeException('invalid_editor_operation_ledger');
            $value=ManagerTourSelectionEditorHost::json($file->getPathname());
            if (!in_array($value['state']??null,['activated','rolled_back'],true)) throw new RuntimeException('unfinished_editor_operation');
        }
    }

    /** Metadata only: the publisher explicitly selects the common-release command. */
    public function commonStatus(): array
    {
        return $this->exclusive(function():array {
            $this->noPending();
            $current=$this->current();
            $seal=$current!==null?ManagerTourSelectionEditorHost::json($current.'/deployment.json'):null;
            if (($seal['release_kind']??null)!=='independent' || ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) return ['ok'=>true,'mode'=>'normal'];
            $prior=ManagerTourSelectionEditorHost::acceptedPrior($this->releases);
            if (!$prior) throw new RuntimeException('editor_prior_not_accepted');
            $this->resolvedOperations();
            return ['ok'=>true,'mode'=>'common','expected_current_package'=>$prior['package_id']];
        });
    }

    /** Only a newer exact main artifact may supersede an accepted incompatible independent release. */
    public function installCommon(string $output,string $source,string $expectedCurrent,string $operation): array
    {
        return $this->exclusive(function()use($output,$source,$expectedCurrent,$operation):array {
            $this->noPending();
            $this->resolvedOperations();
            $grant=$this->admission($output,$this->backend,$source,$source,$expectedCurrent,$operation,true);
            $prior=ManagerTourSelectionEditorHost::acceptedPrior($this->releases);
            $grant['expected_current_seal']=$prior?hash_file('sha256',$prior['directory'].'/deployment.json'):'';
            return $this->installPackage($output,$source,$grant);
        });
    }

    private function installPackage(string $output,string $source,?array $grant): array
    {
        $this->guard();
        if (!preg_match('/\A[a-f0-9]{40}\z/D',$source)) throw new RuntimeException('invalid_editor_source');
        $this->noPending();
        $common=($grant['kind']??null)==='selection-editor-common-release-v1';
        $existing=ManagerTourSelectionEditorHost::installed($this->backend,$this->releases);
        $current=$this->current();$priorSeal=$current!==null?ManagerTourSelectionEditorHost::json($current.'/deployment.json'):null;
        if ($grant===null && ($priorSeal['release_kind']??null)==='independent' && (!$existing || ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)===null)) throw new RuntimeException('editor_independent_backend_changed');
        if ($grant===null && ($existing['release_kind']??null)==='independent' && ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) return ['ok'=>true,'changed'=>false,'preserved_independent'=>true,'package_id'=>$existing['package_id'],'source'=>$existing['source']];
        $receipt=ManagerTourSelectionEditorHost::json($output.'/receipt.json');$release=ManagerTourSelectionEditorHost::json($output.'/payload/release.json');
        $spec=ManagerTourSelectionEditorHost::json($this->backend.'/modules/tour-selections/editor.json');
        if (($receipt['release_eligible']??null)!==true || ($receipt['source_commit']??null)!==$source || ($receipt['package_id']??null)!==($release['package_id']??null)
            || !preg_match('/\A[a-f0-9]{64}\z/D',$release['package_id']??'') || ($release['version']??null)!==($grant['version']??$spec['version']) || ($release['contract']??null)!==$spec['contract']
            || !preg_match('/\A[a-f0-9]{40}\z/D',$receipt['source_tree']??'') || !is_file($output.'/selection-editor.zip') || is_link($output.'/selection-editor.zip')
            || ($receipt['archive_sha256']??null)!==hash_file('sha256',$output.'/selection-editor.zip')) throw new RuntimeException('invalid_editor_package');
        $identity=$release;unset($identity['package_id']);
        if (hash('sha256',ManagerTourSelectionEditorHost::encoded($identity))!==$release['package_id']) throw new RuntimeException('invalid_editor_identity');
        $names=array_keys($spec['files']);$actual=array_values(array_diff(scandir($output.'/payload'),['.','..']));$expected=[...$names,'release.json'];sort($actual);sort($expected);
        if ($actual!==$expected) throw new RuntimeException('foreign_editor_payload');
        foreach ($spec['files'] as $name=>$path) {
            $file=$output.'/payload/'.$name;
            if (is_link($file) || !is_file($file) || filesize($file)>1048576 || (($grant===null || $common) && hash_file('sha256',$file)!==hash_file('sha256',$this->backend.'/'.$path))
                || ($release['files'][$name]['sha256']??null)!==hash_file('sha256',$file)) throw new RuntimeException('editor_source_mismatch');
        }
        $fingerprint=ManagerTourSelectionEditorHost::fingerprint($this->backend);$id=$release['package_id'];$target=$this->releases.'/'.$id.'-'.$fingerprint;
        if (($receipt['backend_fingerprint']??null)!==$fingerprint) throw new RuntimeException('editor_backend_changed');
        if ($grant!==null) {
            foreach (['source_tree','archive_sha256','package_id','backend_fingerprint'] as $key) if (($receipt[$key]??null)!==($grant[$key]??null)) throw new RuntimeException('editor_admission_changed');
            $op=$grant['operation'];
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{7,95}\z/D',$op)) throw new RuntimeException('invalid_editor_operation');
            if (($existing['operation']??null)===$op && ($existing['package_id']??null)===$id && ($existing['source']??null)===$source && ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) {
                if ($common && (($existing['release_kind']??null)!=='common' || ($existing['previous_package']??null)!==$grant['expected_current_package']
                    || ManagerTourSelectionEditorHost::json($this->releases.'/operations/'.$op.'.json')!==['state'=>'activated','package_id'=>$id,'source'=>$source])) throw new RuntimeException('editor_operation_unconfirmed');
                return ['ok'=>true,'changed'=>false,'package_id'=>$id,'operation'=>$op]+($common?['source'=>$source]:[]);
            }
            $prior=$common?ManagerTourSelectionEditorHost::acceptedPrior($this->releases):$existing;
            if (!$prior || (!$common && ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)===null) || $prior['package_id']!==$grant['expected_current_package']
                || ($common && hash_file('sha256',$prior['directory'].'/deployment.json')!==$grant['expected_current_seal'])) throw new RuntimeException('editor_current_package_changed');
            $currentRelease=ManagerTourSelectionEditorHost::json($prior['directory'].'/release.json');
            if ($common && (!preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D',$currentRelease['version']??'')
                || ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null)) throw new RuntimeException('editor_common_release_not_required');
            if (version_compare($grant['version'],$currentRelease['version'],'<=')) throw new RuntimeException('editor_version_not_newer_than_active');
            if ($common && file_exists($target.'/deployment.json') && (ManagerTourSelectionEditorHost::json($target.'/deployment.json')['operation']??null)!==$op) throw new RuntimeException('immutable_editor_operation_changed');
            $operations=$this->releases.'/operations';
            if (is_link($operations) || !is_dir($operations) && !mkdir($operations,0700)) throw new RuntimeException('foreign_editor_operations');
            if (file_exists($operations.'/'.$op.'.json') || is_link($operations.'/'.$op.'.json')) throw new RuntimeException('editor_operation_consumed');
            $intent=fopen($operations.'/'.$op.'.json','x+b');
            if (!$intent) throw new RuntimeException('editor_operation_consumed');
            try {$bytes=json_encode(['state'=>'reserved','admission'=>$grant],JSON_THROW_ON_ERROR);if(!chmod($operations.'/'.$op.'.json',0600)||fwrite($intent,$bytes)!==strlen($bytes)||!fflush($intent)||function_exists('fsync')&&!fsync($intent))throw new RuntimeException('editor_intent_failed');}finally{fclose($intent);}
        }
        $previous=$this->current();$old=is_file($this->releases.'/active.json')?ManagerTourSelectionEditorHost::json($this->releases.'/active.json'):null;
        if ($previous===$target && ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) return ['ok'=>true,'changed'=>false,'package_id'=>$id];
        if (!is_dir($this->releases) && !mkdir($this->releases,0700,true)) throw new RuntimeException('editor_release_root_unavailable');
        self::write($this->releases.'/pending.json',json_encode(['target'=>$target,'previous'=>$previous,'active'=>$old,'front'=>is_file($this->site.'/editor.php'),'operation'=>$grant['operation']??null,'release_kind'=>$grant===null?null:($common?'common':'independent')],JSON_THROW_ON_ERROR));
        try {
            if (is_link($target) || !is_dir($target) && !mkdir($target,0700)) throw new RuntimeException('foreign_editor_release');
            $hashes=[];
            foreach ([...$names,'release.json'] as $name) {
                $bytes=(string)file_get_contents($output.'/payload/'.$name);$file=$target.'/'.$name;
                if (is_file($file) && file_get_contents($file)!==$bytes) throw new RuntimeException('immutable_editor_changed');
                self::write($file,$bytes);
                $hashes[$name]=hash('sha256',$bytes);
            }
            if (file_exists($target.'/deployment.json')) {
                // Reinstalling identical bytes cannot relabel the original source/provenance.
                if (!ManagerTourSelectionEditorHost::package($this->backend,$this->releases,$target)) throw new RuntimeException('immutable_editor_seal_changed');
            } else self::write($target.'/deployment.json',json_encode(['contract'=>ManagerTourSelectionEditorHost::CONTRACT,'origin'=>ManagerTourSelectionEditorHost::ORIGIN,'package_id'=>$id,'fingerprint'=>$fingerprint,'source'=>$source,'source_tree'=>$receipt['source_tree'],'archive_sha256'=>$receipt['archive_sha256'],'payload_hashes'=>$hashes]+($grant===null?[]:['release_kind'=>$common?'common':'independent','backend_source'=>$grant['backend_sha'],'operation'=>$grant['operation']])+($common?['previous_package'=>$grant['expected_current_package'],'previous_seal_sha256'=>$grant['expected_current_seal']]:[]),JSON_THROW_ON_ERROR));
            if ($existing && ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) $this->acceptCode($existing);
            $this->point($target);self::write($this->site.'/editor.php',ManagerTourSelectionEditorHost::front($this->backend));chmod($this->site.'/editor.php',0644);
            return ['ok'=>true,'changed'=>true,'package_id'=>$id,'source'=>$source]+($common?['operation'=>$grant['operation']]:[]);
        } catch (Throwable $e) {$this->rollback();throw $e;}
    }

    public function activateIndependent(string $operation,callable $request): array
    {
        return $this->exclusive(function()use($operation,$request):array {
            $this->requireOperation($operation,true);
            return $this->activateLocked($request);
        });
    }

    public function rollbackIndependent(string $operation): array
    {
        return $this->exclusive(function()use($operation):array {
            $this->requireOperation($operation,false);
            return $this->rollbackLocked();
        });
    }

    public function activateCommon(string $operation,callable $request): array
    {
        return $this->exclusive(function()use($operation,$request):array {
            $this->requireOperation($operation,true,'common');
            return $this->activateLocked($request)+['operation'=>$operation];
        });
    }

    public function rollbackCommon(string $operation): array
    {
        return $this->exclusive(function()use($operation):array {
            $this->requireOperation($operation,false,'common');
            return $this->rollbackLocked();
        });
    }

    private function requireOperation(string $operation,bool $acceptActive,?string $kind=null): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{7,95}\z/D',$operation)) throw new RuntimeException('invalid_editor_operation');
        $pending=is_file($this->releases.'/pending.json')?ManagerTourSelectionEditorHost::json($this->releases.'/pending.json'):null;
        if ($pending!==null) {if (($pending['operation']??null)!==$operation || ($kind!==null && ($pending['release_kind']??null)!==$kind))throw new RuntimeException('editor_operation_changed');return;}
        $active=$acceptActive?ManagerTourSelectionEditorHost::installed($this->backend,$this->releases):null;
        if (($active['operation']??null)===$operation && ($kind===null || ($active['release_kind']??null)===$kind) && ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) return;
        throw new RuntimeException('editor_operation_unconfirmed');
    }

    public function activate(callable $request): array
    {
        return $this->exclusive(fn()=>$this->activateLocked($request));
    }

    private function activateLocked(callable $request): array
    {
        $this->guard();$installed=ManagerTourSelectionEditorHost::installed($this->backend,$this->releases);
        if (!$installed) throw new RuntimeException('editor_incompatible');
        if (ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) return ['ok'=>true,'active'=>true,'reused'=>true,'package_id'=>$installed['package_id'],'source'=>$installed['source']];
        foreach (['/editor.php','/editor.php?conversation_id=1&editor_actor=1'] as $path) {
            $r=$request(ManagerTourSelectionEditorHost::ORIGIN,$path,'GET','');
            if ($r['status']!==200 || stripos($r['headers'],'Set-Cookie:')!==false || !str_contains($r['headers'],'no-store')
                || !str_contains($r['headers'],"frame-src https://app.anytoour.ru") || !str_contains($r['body'],'Manager2')) throw new RuntimeException('editor_tours_smoke_failed');
            if (str_contains($path,'?') && !str_contains($r['body'],'https://app.anytoour.ru/manager/tour-selection-editor.php?conversation_id=1&amp;editor_actor=1')) throw new RuntimeException('editor_frame_smoke_failed');
        }
        $r=$request(ManagerTourSelectionEditorHost::APP,'/manager/tour-selection-editor.php','GET','');
        if ($r['status']!==200 || stripos($r['headers'],'Set-Cookie:')!==false || !str_contains($r['body'],'id="editorLauncher"') || !str_contains($r['headers'],'frame-ancestors https://tours.anytoour.ru')) throw new RuntimeException('editor_app_smoke_failed');
        foreach (ManagerTourSelectionEditorHost::ASSETS as $name=>$mime) {
            $r=$request(ManagerTourSelectionEditorHost::APP,'/manager/tour-selection-editor.php?asset='.$name.'&package='.$installed['package_id'],'GET','');
            if ($r['status']!==200 || stripos($r['headers'],'Set-Cookie:')!==false || hash('sha256',$r['body'])!==hash_file('sha256',$installed['directory'].'/'.$name)) throw new RuntimeException('editor_asset_smoke_failed');
        }
        $r=$request(ManagerTourSelectionEditorHost::APP,'/manager/tour-selection-editor-api.php','POST','{}');
        if ($r['status']!==401 || (json_decode($r['body'],true)['error']??null)!=='unauthorized') throw new RuntimeException('editor_auth_smoke_failed');
        $this->acceptCode($installed);
        $active=array_diff_key($installed,['directory'=>true]);self::write($this->releases.'/active.json',json_encode($active,JSON_THROW_ON_ERROR));
        $pending=ManagerTourSelectionEditorHost::json($this->releases.'/pending.json');
        if (is_string($pending['operation']??null)) self::write($this->releases.'/operations/'.$pending['operation'].'.json',json_encode(['state'=>'activated','package_id'=>$installed['package_id'],'source'=>$installed['source']],JSON_THROW_ON_ERROR));
        unlink($this->releases.'/pending.json');return ['ok'=>true,'active'=>true,'package_id'=>$installed['package_id'],'source'=>$installed['source']];
    }

    public function rollback(): array
    {
        return $this->exclusive(fn()=>$this->rollbackLocked());
    }

    private function rollbackLocked(): array
    {
        if (!is_file($this->releases.'/pending.json')) return ['ok'=>true,'rolled_back'=>false];
        $this->guard();$pending=ManagerTourSelectionEditorHost::json($this->releases.'/pending.json');$current=$this->current();
        if ($current!==$pending['target'] && $current!==$pending['previous']) throw new RuntimeException('editor_operation_changed');
        if (is_string($pending['previous'])) $this->point($pending['previous']);
        elseif (is_link($this->releases.'/current')) unlink($this->releases.'/current');
        if (is_array($pending['active'])) self::write($this->releases.'/active.json',json_encode($pending['active'],JSON_THROW_ON_ERROR));
        elseif (is_file($this->releases.'/active.json')) unlink($this->releases.'/active.json');
        if (!$pending['front'] && is_file($this->site.'/editor.php')) unlink($this->site.'/editor.php');
        if (is_string($pending['operation']??null)) self::write($this->releases.'/operations/'.$pending['operation'].'.json',json_encode(['state'=>'rolled_back','package_id'=>basename($pending['target'])],JSON_THROW_ON_ERROR));
        unlink($this->releases.'/pending.json');return ['ok'=>true,'rolled_back'=>true];
    }

    public static function strictRequest(string $origin,string $path,string $method,string $body): array
    {
        if (!in_array($origin,[ManagerTourSelectionEditorHost::ORIGIN,ManagerTourSelectionEditorHost::APP],true)) throw new RuntimeException('invalid_editor_origin');
        $bytes='';$headers='';$curl=curl_init($origin.$path);
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$headers):int{$headers.=$line;return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$bytes):int{if(strlen($bytes)+strlen($chunk)>1048576)return 0;$bytes.=$chunk;return strlen($chunk);}]);
        if ($method==='POST') curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$ssl=(int)curl_getinfo($curl,CURLINFO_SSL_VERIFYRESULT);$errno=curl_errno($curl);curl_close($curl);
        if ($ok===false || $ssl!==0 || $errno!==0) throw new RuntimeException('editor_strict_tls_failed');
        return ['status'=>$status,'headers'=>$headers,'body'=>$bytes];
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {
        $deploy=new TourSelectionEditorDeployment(dirname(__DIR__,2),'/var/www/anytoour/data/www/tours.anytoour.ru',ManagerTourSelectionEditorHost::releaseRoot());
        $result=match($argv[1]??'') {
            'install'=>$deploy->install($argv[2]??'',$argv[3]??''),
            'common-status'=>$deploy->commonStatus(),
            'install-common'=>$deploy->installCommon($argv[2]??'',$argv[3]??'',$argv[4]??'',$argv[5]??''),
            'activate-common'=>$deploy->activateCommon($argv[2]??'',[TourSelectionEditorDeployment::class,'strictRequest']),
            'rollback-common'=>$deploy->rollbackCommon($argv[2]??''),
            'install-independent'=>$deploy->installIndependent($argv[2]??'',$argv[3]??'',$argv[4]??'',$argv[5]??'',$argv[6]??'',$argv[7]??''),
            'activate-independent'=>$deploy->activateIndependent($argv[2]??'',[TourSelectionEditorDeployment::class,'strictRequest']),
            'rollback-independent'=>$deploy->rollbackIndependent($argv[2]??''),
            'activate'=>$deploy->activate([TourSelectionEditorDeployment::class,'strictRequest']),
            'rollback'=>$deploy->rollback(),
            default=>throw new RuntimeException('invalid_editor_action'),
        };
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) {fwrite(STDERR,'selection_editor_host_error: '.$e->getMessage()."\n");exit(1);}
}
