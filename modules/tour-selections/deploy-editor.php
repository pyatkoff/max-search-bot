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

    private function point(string $target): void
    {
        $path=$this->releases.'/current';$tmp=$path.'.tmp-'.bin2hex(random_bytes(8));
        if (!symlink($target,$tmp) || !rename($tmp,$path)) throw new RuntimeException('editor_activation_failed');
    }

    public function install(string $output,string $source): array
    {
        $this->guard();
        if (!preg_match('/\A[a-f0-9]{40}\z/D',$source)) throw new RuntimeException('invalid_editor_source');
        if (file_exists($this->releases.'/pending.json') || is_link($this->releases.'/pending.json')) throw new RuntimeException('unfinished_editor_operation');
        $receipt=ManagerTourSelectionEditorHost::json($output.'/receipt.json');$release=ManagerTourSelectionEditorHost::json($output.'/payload/release.json');
        $spec=ManagerTourSelectionEditorHost::json($this->backend.'/modules/tour-selections/editor.json');
        if (($receipt['release_eligible']??null)!==true || ($receipt['source_commit']??null)!==$source || ($receipt['package_id']??null)!==($release['package_id']??null)
            || !preg_match('/\A[a-f0-9]{64}\z/D',$release['package_id']??'') || ($release['version']??null)!==$spec['version'] || ($release['contract']??null)!==$spec['contract']
            || !preg_match('/\A[a-f0-9]{40}\z/D',$receipt['source_tree']??'') || !is_file($output.'/selection-editor.zip') || is_link($output.'/selection-editor.zip')
            || ($receipt['archive_sha256']??null)!==hash_file('sha256',$output.'/selection-editor.zip')) throw new RuntimeException('invalid_editor_package');
        $identity=$release;unset($identity['package_id']);
        if (hash('sha256',ManagerTourSelectionEditorHost::encoded($identity))!==$release['package_id']) throw new RuntimeException('invalid_editor_identity');
        $names=array_keys($spec['files']);$actual=array_values(array_diff(scandir($output.'/payload'),['.','..']));$expected=[...$names,'release.json'];sort($actual);sort($expected);
        if ($actual!==$expected) throw new RuntimeException('foreign_editor_payload');
        foreach ($spec['files'] as $name=>$path) {
            $file=$output.'/payload/'.$name;
            if (is_link($file) || !is_file($file) || filesize($file)>1048576 || hash_file('sha256',$file)!==hash_file('sha256',$this->backend.'/'.$path)
                || ($release['files'][$name]['sha256']??null)!==hash_file('sha256',$file)) throw new RuntimeException('editor_source_mismatch');
        }
        $fingerprint=ManagerTourSelectionEditorHost::fingerprint($this->backend);$id=$release['package_id'];$target=$this->releases.'/'.$id.'-'.$fingerprint;
        if (($receipt['backend_fingerprint']??null)!==$fingerprint) throw new RuntimeException('editor_backend_changed');
        $previous=$this->current();$old=is_file($this->releases.'/active.json')?ManagerTourSelectionEditorHost::json($this->releases.'/active.json'):null;
        if ($previous===$target && ManagerTourSelectionEditorHost::entry($this->backend,$this->releases)!==null) return ['ok'=>true,'changed'=>false,'package_id'=>$id];
        if (!is_dir($this->releases) && !mkdir($this->releases,0700,true)) throw new RuntimeException('editor_release_root_unavailable');
        self::write($this->releases.'/pending.json',json_encode(['target'=>$target,'previous'=>$previous,'active'=>$old,'front'=>is_file($this->site.'/editor.php')],JSON_THROW_ON_ERROR));
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
            } else self::write($target.'/deployment.json',json_encode(['contract'=>ManagerTourSelectionEditorHost::CONTRACT,'origin'=>ManagerTourSelectionEditorHost::ORIGIN,'package_id'=>$id,'fingerprint'=>$fingerprint,'source'=>$source,'source_tree'=>$receipt['source_tree'],'archive_sha256'=>$receipt['archive_sha256'],'payload_hashes'=>$hashes],JSON_THROW_ON_ERROR));
            $this->point($target);self::write($this->site.'/editor.php',ManagerTourSelectionEditorHost::front($this->backend));chmod($this->site.'/editor.php',0644);
            return ['ok'=>true,'changed'=>true,'package_id'=>$id,'source'=>$source];
        } catch (Throwable $e) {$this->rollback();throw $e;}
    }

    public function activate(callable $request): array
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
        $active=array_diff_key($installed,['directory'=>true]);self::write($this->releases.'/active.json',json_encode($active,JSON_THROW_ON_ERROR));
        unlink($this->releases.'/pending.json');return ['ok'=>true,'active'=>true,'package_id'=>$installed['package_id'],'source'=>$installed['source']];
    }

    public function rollback(): array
    {
        if (!is_file($this->releases.'/pending.json')) return ['ok'=>true,'rolled_back'=>false];
        $this->guard();$pending=ManagerTourSelectionEditorHost::json($this->releases.'/pending.json');$current=$this->current();
        if ($current!==$pending['target'] && $current!==$pending['previous']) throw new RuntimeException('editor_operation_changed');
        if (is_string($pending['previous'])) $this->point($pending['previous']);
        elseif (is_link($this->releases.'/current')) unlink($this->releases.'/current');
        if (is_array($pending['active'])) self::write($this->releases.'/active.json',json_encode($pending['active'],JSON_THROW_ON_ERROR));
        elseif (is_file($this->releases.'/active.json')) unlink($this->releases.'/active.json');
        if (!$pending['front'] && is_file($this->site.'/editor.php')) unlink($this->site.'/editor.php');
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
            'activate'=>$deploy->activate([TourSelectionEditorDeployment::class,'strictRequest']),
            'rollback'=>$deploy->rollback(),
            default=>throw new RuntimeException('invalid_editor_action'),
        };
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) {fwrite(STDERR,'selection_editor_host_error: '.$e->getMessage()."\n");exit(1);}
}
