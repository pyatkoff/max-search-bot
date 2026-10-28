<?php
declare(strict_types=1);
require_once __DIR__.'/DiagnosticLogger.php';

/** Code/artifact readiness only. All private operations keep canonical Manager owners. */
final class ManagerTourSelectionEditorHost
{
    public const CONTRACT='selection-private-host-v1';
    public const ORIGIN='https://tours.anytoour.ru';
    public const APP='https://app.anytoour.ru';
    public const ASSETS=['editor.js'=>'application/javascript','editor.css'=>'text/css','manager2-host.js'=>'application/javascript','private-host.js'=>'application/javascript','private-host.css'=>'text/css','http.js'=>'application/javascript','time.js'=>'application/javascript'];
    public const FILES=['editor.js','editor.css','manager2-host.js','collector.zip','private-host.js','private-host.css','http.js','time.js','app.php','tours.php'];

    public static function encoded(array $value): string
    {
        $sort=static function(array $value)use(&$sort):array{if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$item)if(is_array($item))$value[$key]=$sort($item);return $value;};
        return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }

    public static function releaseRoot(): string
    {
        $log=DiagnosticLogger::filePath();
        if ($log==='') throw new RuntimeException('editor_private_path_unavailable');
        return dirname($log).'/selection-editor-releases';
    }

    public static function fingerprint(?string $root=null): string
    {
        $root??=dirname(__DIR__);
        $spec=self::json($root.'/modules/tour-selections/editor.json');
        $paths=array_unique($spec['backend_inputs']);sort($paths,SORT_STRING);$inputs=[];
        foreach ($paths as $path) {
            if (!is_string($path) || str_contains($path,'..') || str_contains($path,'\\') || str_starts_with($path,'/')) throw new RuntimeException('invalid_editor_input');
            $file=$root.'/'.$path;
            if (is_link($file) || !is_file($file)) throw new RuntimeException('editor_input_unavailable');
            $inputs[$path]=hash_file('sha256',$file);
        }
        // Frontend identity belongs to the sealed release, not backend compatibility.
        return hash('sha256',self::encoded(['schema'=>1,'contract'=>$spec['contract'],'backend_inputs'=>$inputs]));
    }

    public static function json(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path)>32768) throw new RuntimeException('invalid_editor_receipt');
        $value=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('invalid_editor_receipt');
        return $value;
    }

    /** Validates a sealed package against the current BACKEND, never current frontend bytes. */
    public static function package(string $root,string $releases,string $dir): ?array
    {
        try {
            $package=self::sealedPackage($releases,$dir);
            return $package && $package['fingerprint']===self::fingerprint($root) ? $package : null;
        } catch (Throwable $ignored) {return null;}
    }

    /** Intrinsic identity only. Never used to serve code without package() compatibility. */
    private static function sealedPackage(string $releases,string $dir): ?array
    {
        try {
            if (!preg_match('~\A'.preg_quote($releases,'~').'/[a-f0-9]{64}-[a-f0-9]{64}\z~D',$dir) || realpath($dir)!==$dir) return null;
            $receipt=self::json($dir.'/deployment.json');$release=self::json($dir.'/release.json');
            if (($receipt['contract']??null)!==self::CONTRACT || ($receipt['origin']??null)!==self::ORIGIN
                || !preg_match('/\A[a-f0-9]{64}\z/D',$receipt['fingerprint']??'') || ($receipt['package_id']??null)!==($release['package_id']??null)
                || ($release['contract']??null)!=='tour-selection-editor-host-v1'
                || $dir!==$releases.'/'.$release['package_id'].'-'.$receipt['fingerprint']
                || !preg_match('/\A[a-f0-9]{40}\z/D',$receipt['source']??'') || !preg_match('/\A[a-f0-9]{40}\z/D',$receipt['source_tree']??'')
                || !preg_match('/\A[a-f0-9]{64}\z/D',$receipt['archive_sha256']??'')) return null;
            $expected=[...self::FILES,'release.json'];$sealed=array_keys($receipt['payload_hashes']??[]);sort($expected);sort($sealed);
            if ($sealed!==$expected) return null;
            $listed=array_keys($release['files']??[]);$names=self::FILES;sort($listed);sort($names);
            if ($listed!==$names) return null;
            $identity=$release;unset($identity['package_id']);
            if (hash('sha256',self::encoded($identity))!==$release['package_id']) return null;
            foreach ($expected as $name) {
                $file=$dir.'/'.$name;
                if (is_link($file) || !is_file($file) || filesize($file)>1048576 || hash_file('sha256',$file)!==$receipt['payload_hashes'][$name]) return null;
                if ($name!=='release.json' && (($release['files'][$name]['sha256']??null)!==hash_file('sha256',$file) || ($release['files'][$name]['bytes']??null)!==filesize($file))) return null;
            }
            return $receipt+['directory'=>$dir];
        } catch (Throwable $ignored) {return null;}
    }

    /** CLI common-release admission may inspect an accepted prior seal after backend drift. */
    public static function acceptedPrior(string $releases): ?array
    {
        try {
            if (is_link($releases) || !is_link($releases.'/current') || is_link($releases.'/operations')) return null;
            $dir=readlink($releases.'/current');
            $prior=is_string($dir)?self::sealedPackage($releases,$dir):null;
            if (!$prior || ($prior['release_kind']??null)!=='independent'
                || !preg_match('/\A[a-f0-9]{40}\z/D',$prior['backend_source']??'')
                || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{7,95}\z/D',$prior['operation']??'')) return null;
            if (self::json($dir.'/accepted.json')!==['schema'=>1,'package_id'=>$prior['package_id'],'seal_sha256'=>hash_file('sha256',$dir.'/deployment.json')]
                || self::json($releases.'/active.json')!==array_diff_key($prior,['directory'=>true])) return null;
            $operation=self::json($releases.'/operations/'.$prior['operation'].'.json');
            if ($operation!==['state'=>'activated','package_id'=>$prior['package_id'],'source'=>$prior['source']]) return null;
            return $prior;
        } catch (Throwable $ignored) {return null;}
    }

    /** The pointer selects a private immutable package; HTTP cannot supply its path. */
    public static function installed(?string $root=null,?string $releases=null): ?array
    {
        try {
            $root??=dirname(__DIR__);$releases??=self::releaseRoot();
            if (is_link($releases) || !is_link($releases.'/current')) return null;
            $dir=readlink($releases.'/current');
            return is_string($dir)?self::package($root,$releases,$dir):null;
        } catch (Throwable $ignored) {return null;}
    }

    /** An already-rendered page may finish loading its accepted immutable bundle. */
    public static function assetPackage(string $id,?string $root=null,?string $releases=null): ?array
    {
        try {
            if (!preg_match('/\A[a-f0-9]{64}\z/D',$id)) return null;
            $root??=dirname(__DIR__);$releases??=self::releaseRoot();
            $current=self::installed($root,$releases);
            // The staged CURRENT package remains reachable for strict activation smoke.
            if (($current['package_id']??null)===$id) return $current;
            $dir=$releases.'/'.$id.'-'.self::fingerprint($root);
            $package=self::package($root,$releases,$dir);
            if (!$package || self::json($dir.'/accepted.json')!==['schema'=>1,'package_id'=>$id,'seal_sha256'=>hash_file('sha256',$dir.'/deployment.json')]) return null;
            return $package;
        } catch (Throwable $ignored) {return null;}
    }

    public static function entry(?string $root=null,?string $releases=null): ?string
    {
        try {
            $releases??=self::releaseRoot();$installed=self::installed($root,$releases);
            if (!$installed || self::json($releases.'/active.json')!==array_diff_key($installed,['directory'=>true])) return null;
            return self::ORIGIN.'/editor.php';
        } catch (Throwable $ignored) {return null;}
    }

    /** Only the three selection callsites may use this sealed asset mapping. */
    public static function workspaceAsset(string $name,array $installed): string
    {
        $map=['workspace-v2-tour-selection.js'=>'editor.js','workspace-v2-tour-selection.css'=>'editor.css','workspace-v2-tour-selection-host.js'=>'manager2-host.js'];
        if (!isset($map[$name]) || !preg_match('/\A[a-f0-9]{64}\z/D',$installed['package_id']??'')) throw new RuntimeException('invalid_editor_workspace_asset');
        return self::APP.'/manager/tour-selection-editor.php?asset='.$map[$name].'&package='.$installed['package_id'];
    }

    public static function front(string $backend): string
    {
        return "<?php\ndeclare(strict_types=1);\nini_set('display_errors','0');\n".
            "if ((\$_SERVER['HTTP_HOST']??'')!=='tours.anytoour.ru') {http_response_code(404);exit;}\n".
            "require_once ".var_export($backend.'/services/ManagerTourSelectionEditorHost.php',true).";\n".
            "\$installed=ManagerTourSelectionEditorHost::installed();\n".
            "if (!\$installed) {header('Cache-Control: no-store');http_response_code(503);exit('Редактор временно недоступен. Откройте подборку в Manager2.');}\n".
            "require \$installed['directory'].'/tours.php';\n";
    }
}
