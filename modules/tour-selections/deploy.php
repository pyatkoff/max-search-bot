<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerTourSelectionPublicHost.php';

/** Fixed public presentation installation; never copies a config, DB or manager app. */
final class TourSelectionHostDeployment
{
    private const PLACEHOLDER='4059f8526d3605b52c389e030db4e2775106aa9070b5c508c2a9f905a03d0403';
    private const ASSETS=['selection.css','activity.js'];
    private const ROUTES=['index.php','photo.php','activity.php'];

    public function __construct(private string $backend,private string $site,private string $releases,private string $marker,private string $placeholderHash=self::PLACEHOLDER) {}

    private static function write(string $path,string $bytes,int $mode=0600): void
    {
        if (is_link($path)) throw new RuntimeException('unexpected_file_link');
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(8));
        if (file_put_contents($tmp,$bytes)!==strlen($bytes) || !chmod($tmp,$mode) || !rename($tmp,$path)) throw new RuntimeException('host_write_failed');
    }

    private static function json(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path)>16384) throw new RuntimeException('invalid_host_receipt');
        $data=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('invalid_host_receipt');
        return $data;
    }

    private static function link(string $target,string $path): void
    {
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(8));
        if (!symlink($target,$tmp) || !rename($tmp,$path)) throw new RuntimeException('host_activation_failed');
    }

    public function front(string $route): string
    {
        if (!in_array($route,self::ROUTES,true)) throw new RuntimeException('invalid_public_route');
        $controller=var_export($this->backend.'/selection/'.$route,true);
        $view=var_export($this->releases.'/current/view.php',true);
        return "<?php\ndeclare(strict_types=1);\nini_set('display_errors','0');\n".
            "header('Cache-Control: private, no-store, max-age=0');\nheader('X-Robots-Tag: noindex, nofollow, noarchive');\nheader('Referrer-Policy: no-referrer');\nheader('X-Content-Type-Options: nosniff');\n".
            "if ((\$_SERVER['HTTP_HOST']??'')!=='tours.anytoour.ru') {http_response_code(404);exit;}\n".
            "if (!is_readable($controller) || !is_readable($view)) {http_response_code(503);exit;}\n".
            "define('ANYTOUR_SELECTION_PUBLIC_ORIGIN','https://tours.anytoour.ru');\n".
            "define('ANYTOUR_SELECTION_PUBLIC_VIEW',$view);\nrequire $controller;\n";
    }

    private function guardSite(): void
    {
        if (is_link($this->site) || realpath($this->site)!==$this->site || !is_writable($this->site)) throw new RuntimeException('unavailable_owned_host');
        if (function_exists('posix_geteuid') && fileowner($this->site)!==posix_geteuid()) throw new RuntimeException('foreign_host_owner');
        $allowed=[...self::ROUTES,...self::ASSETS,'index.html','.well-known'];
        foreach (array_diff(scandir($this->site),['.','..']) as $name) {
            if (!in_array($name,$allowed,true)) throw new RuntimeException('foreign_site_content');
            $path=$this->site.'/'.$name;
            if ($name==='.well-known') continue;
            if ($name==='index.html') {
                if (is_link($path) || hash_file('sha256',$path)!==$this->placeholderHash) throw new RuntimeException('foreign_site_placeholder');
            } elseif (in_array($name,self::ROUTES,true)) {
                if (is_link($path) || file_get_contents($path)!==$this->front($name)) throw new RuntimeException('foreign_site_route');
            } elseif (!is_link($path) || readlink($path)!==$this->releases.'/current/'.$name) throw new RuntimeException('foreign_site_asset');
        }
        if (is_link($this->releases) || (file_exists($this->releases) && !is_dir($this->releases))) throw new RuntimeException('foreign_release_root');
        if (file_exists($this->releases.'/current') || is_link($this->releases.'/current')) $this->current();
    }

    private function current(): string
    {
        $path=$this->releases.'/current';
        if (!is_link($path)) throw new RuntimeException('missing_current_release');
        $target=readlink($path);
        if (!is_string($target) || !preg_match('~\A'.preg_quote($this->releases,'~').'/[a-f0-9]{64}-[a-f0-9]{64}\z~D',$target) || realpath($target)!==$target) throw new RuntimeException('foreign_current_release');
        return $target;
    }

    public function install(string $payload,string $source): array
    {
        if (!preg_match('/\A[a-f0-9]{40}\z/D',$source)) throw new RuntimeException('invalid_source');
        $this->guardSite();
        $public=self::json($payload.'/release.json');
        $id=$public['package_id']??'';
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D',$id)) throw new RuntimeException('invalid_package');
        foreach (['view.php',...self::ASSETS] as $name) {
            $file=$payload.'/'.$name;
            if (is_link($file) || !is_file($file) || filesize($file)>1048576
                || hash_file('sha256',$file)!==hash_file('sha256',$this->backend.'/selection/'.$name)
                || ($public['files'][$name]['sha256']??null)!==hash_file('sha256',$file)) throw new RuntimeException('package_source_mismatch');
        }
        $fingerprint=ManagerTourSelectionPublicHost::fingerprint($this->backend);
        $target=$this->releases.'/'.$id.'-'.$fingerprint;
        if (!is_dir($this->releases) && !mkdir($this->releases,0755,true)) throw new RuntimeException('release_root_unavailable');
        if (is_link($target)) throw new RuntimeException('foreign_release');
        if (is_file($this->releases.'/pending.json')) {
            $pending=self::json($this->releases.'/pending.json');
            if (($pending['target']??null)!==$target) throw new RuntimeException('unfinished_host_operation');
        } else {
            $previous=is_link($this->releases.'/current')?$this->current():null;
            $oldMarker=is_file($this->marker)?self::json($this->marker):null;
            if ($previous===$target && $oldMarker && ($oldMarker['fingerprint']??null)===$fingerprint) return ['ok'=>true,'changed'=>false,'package_id'=>$id];
            $pending=['target'=>$target,'previous'=>$previous,'marker'=>$oldMarker,'placeholder'=>is_file($this->site.'/index.html')];
            self::write($this->releases.'/pending.json',json_encode($pending,JSON_THROW_ON_ERROR));
        }
        if (!is_dir($target) && !mkdir($target,0755)) throw new RuntimeException('release_unavailable');
        foreach (['view.php',...self::ASSETS,'release.json'] as $name) {
            $bytes=(string)file_get_contents($payload.'/'.$name);
            if (is_file($target.'/'.$name) && file_get_contents($target.'/'.$name)!==$bytes) throw new RuntimeException('immutable_release_changed');
            self::write($target.'/'.$name,$bytes,in_array($name,self::ASSETS,true)?0644:0600);
        }
        self::write($target.'/deployment.json',json_encode(['contract'=>ManagerTourSelectionPublicHost::CONTRACT,'origin'=>ManagerTourSelectionPublicHost::ORIGIN,
            'fingerprint'=>$fingerprint,'package_id'=>$id,'source'=>$source],JSON_THROW_ON_ERROR));
        if (is_file($this->site.'/index.html') && !is_file($this->releases.'/original-index.html')) self::write($this->releases.'/original-index.html',(string)file_get_contents($this->site.'/index.html'));
        self::link($target,$this->releases.'/current');
        foreach (self::ROUTES as $route) self::write($this->site.'/'.$route,$this->front($route),0644);
        foreach (self::ASSETS as $asset) self::link($this->releases.'/current/'.$asset,$this->site.'/'.$asset);
        if (is_file($this->site.'/index.html') && !unlink($this->site.'/index.html')) throw new RuntimeException('placeholder_removal_failed');
        return ['ok'=>true,'changed'=>true,'package_id'=>$id,'fingerprint'=>$fingerprint];
    }

    /** The production caller supplies only the fixed strict-TLS implementation below. */
    public function activate(callable $request): array
    {
        $this->guardSite();$target=$this->current();$deployment=self::json($target.'/deployment.json');
        if (($deployment['fingerprint']??null)!==ManagerTourSelectionPublicHost::fingerprint($this->backend)) throw new RuntimeException('backend_changed');
        foreach (['/?t=bad'=>[404],'/photo.php?t=bad&v=1&card=0&photo=0'=>[404],'/activity.php'=>[405],'/config.php'=>[403,404],'/manager2/'=>[403,404]] as $path=>$codes) {
            $r=$request($path,'GET',[]);
            if (!in_array($r['status'],$codes,true) || $r['cookie']) throw new RuntimeException('host_route_smoke_failed');
            if ($path==='/?t=bad' && (!str_contains($r['headers'],'no-store') || !str_contains(strtolower($r['headers']),'x-robots-tag: noindex') || !str_contains($r['body'],'Подборка сейчас недоступна'))) throw new RuntimeException('host_page_smoke_failed');
        }
        foreach (self::ASSETS as $asset) {
            $r=$request('/'.$asset,'GET',[]);
            if ($r['status']!==200 || $r['cookie'] || hash('sha256',$r['body'])!==hash_file('sha256',$target.'/'.$asset)) throw new RuntimeException('host_asset_smoke_failed');
        }
        foreach (['https://app.anytoour.ru','https://other.invalid'] as $origin) {
            $r=$request('/activity.php','POST',['Origin: '.$origin,'Content-Type: application/json']);
            if ($r['status']!==403 || $r['cookie']) throw new RuntimeException('host_origin_smoke_failed');
        }
        $r=$request('/activity.php','POST',['Origin: '.ManagerTourSelectionPublicHost::ORIGIN,'Content-Type: application/json']);
        if ($r['status']!==422 || $r['cookie']) throw new RuntimeException('host_same_origin_smoke_failed');
        if (!is_dir(dirname($this->marker)) || is_link(dirname($this->marker))) throw new RuntimeException('host_marker_unavailable');
        self::write($this->marker,json_encode($deployment,JSON_THROW_ON_ERROR));
        if (is_file($this->releases.'/pending.json')) unlink($this->releases.'/pending.json');
        return ['ok'=>true,'active'=>true,'package_id'=>$deployment['package_id'],'source'=>$deployment['source']];
    }

    public function rollback(): array
    {
        $pendingFile=$this->releases.'/pending.json';
        if (!is_file($pendingFile)) return ['ok'=>true,'rolled_back'=>false];
        $pending=self::json($pendingFile);$this->guardSite();
        if (is_link($this->releases.'/current') && $this->current()!==$pending['target']) throw new RuntimeException('host_operation_changed');
        if (is_string($pending['previous'])) self::link($pending['previous'],$this->releases.'/current');
        else {
            foreach (self::ROUTES as $route) if (is_file($this->site.'/'.$route)) unlink($this->site.'/'.$route);
            foreach (self::ASSETS as $asset) if (is_link($this->site.'/'.$asset)) unlink($this->site.'/'.$asset);
            if (is_link($this->releases.'/current')) unlink($this->releases.'/current');
            if ($pending['placeholder'] && !is_file($this->site.'/index.html')) {
                $backup=$this->releases.'/original-index.html';
                if (!is_file($backup) || is_link($backup) || hash_file('sha256',$backup)!==$this->placeholderHash) throw new RuntimeException('placeholder_backup_unavailable');
                self::write($this->site.'/index.html',(string)file_get_contents($backup),0644);
            }
        }
        if (is_array($pending['marker'])) self::write($this->marker,json_encode($pending['marker'],JSON_THROW_ON_ERROR));
        elseif (is_file($this->marker) && !is_link($this->marker)) unlink($this->marker);
        unlink($pendingFile);
        return ['ok'=>true,'rolled_back'=>true];
    }

    public static function strictRequest(string $path,string $method,array $headers): array
    {
        $body='';$received='';
        $curl=curl_init(ManagerTourSelectionPublicHost::ORIGIN.$path);
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION=>static function($unused,string $line)use(&$received):int{$received.=$line;return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($unused,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>1048576)return 0;$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$ssl=(int)curl_getinfo($curl,CURLINFO_SSL_VERIFYRESULT);$errno=curl_errno($curl);curl_close($curl);
        if ($ok===false || $ssl!==0 || $errno!==0) throw new RuntimeException('strict_host_tls_failed');
        return ['status'=>$status,'body'=>$body,'headers'=>$received,'cookie'=>stripos($received,'Set-Cookie:')!==false];
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {
        $backend=dirname(__DIR__,2);require_once $backend.'/config.php';
        $deploy=new TourSelectionHostDeployment($backend,'/var/www/anytoour/data/www/tours.anytoour.ru','/var/www/anytoour/data/selection-public-releases',ManagerTourSelectionPublicHost::markerPath());
        $action=$argv[1]??'';
        $result=match($action) {
            'install'=>$deploy->install($argv[2]??'',$argv[3]??''),
            'activate'=>$deploy->activate([TourSelectionHostDeployment::class,'strictRequest']),
            'rollback'=>$deploy->rollback(),
            default=>throw new RuntimeException('invalid_host_action'),
        };
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) {fwrite(STDERR,'selection_host_error: '.$e->getMessage()."\n");exit(1);}
}
