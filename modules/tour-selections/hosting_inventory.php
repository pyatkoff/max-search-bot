<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}

/** Public names only. No bootstrap, secrets, certificate or customer-file reads. */
function selectionHostingInventory(array $webRoots,array $vhostDirectories):array {
    $domains=[];$rootsAvailable=0;$configDirectoriesAvailable=0;$truncated=false;
    $domain=static function(string $name)use(&$domains):?string {
        $name=strtolower(rtrim($name,'.'));
        if (!preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*anytoour\.ru\z/D',$name)) return null;
        $domains[$name]??=['domain'=>$name,'directory'=>false,'vhost'=>false];
        return $name;
    };
    foreach ($webRoots as $root) {
        if (!is_dir($root)||!is_readable($root)) continue;
        $rootsAvailable++;$entries=scandir($root);if ($entries===false) continue;
        if (count($entries)>130) $truncated=true;
        foreach (array_slice($entries,0,130) as $entry) {
            if ($entry==='.'||$entry==='..'||is_link($root.'/'.$entry)||!is_dir($root.'/'.$entry)) continue;
            $name=$domain($entry);if ($name!==null) $domains[$name]['directory']=true;
        }
    }
    foreach ($vhostDirectories as $root) {
        if (!is_dir($root)||!is_readable($root)) continue;
        $configDirectoriesAvailable++;$files=glob($root.'/*.conf')?:[];
        if (count($files)>64) $truncated=true;
        foreach (array_slice($files,0,64) as $file) {
            if (is_link($file)||!is_file($file)||!is_readable($file)||filesize($file)>131072) {$truncated=true;continue;}
            $text=file_get_contents($file);if ($text===false) continue;
            preg_match_all('/^\s*(?:server_name\s+([^;\r\n]+);|Server(?:Name|Alias)\s+([^\r\n]+))/mi',$text,$matches,PREG_SET_ORDER);
            foreach ($matches as $match) foreach (preg_split('/\s+/',trim(($match[1]??'')?:($match[2]??''))) as $entry) {
                $name=$domain($entry);if ($name!==null) $domains[$name]['vhost']=true;
            }
        }
    }
    ksort($domains);
    return ['schema'=>1,'ok'=>true,'web_roots_available'=>$rootsAvailable,
        'vhost_directories_available'=>$configDirectoriesAvailable,'truncated'=>$truncated,
        'domains'=>array_values($domains),'dns_checked'=>false,'tls_checked'=>false,
        'configuration_changed'=>false];
}

/** Inspect only the owner-created selection host; file bodies stay unread. */
function selectionHostingDetail(string $webRoot,array $vhostDirectories,bool $network=false):array {
    $host='d.anytoour.ru';$root=$webRoot.'/'.$host;$entries=[];$configs=[];
    if (is_dir($root)&&is_readable($root)) {
        foreach (array_slice(scandir($root)?:[],0,66) as $name) {
            if ($name===''||$name[0]==='.'||!preg_match('/\A[a-zA-Z0-9_.-]+\z/D',$name)) continue;
            $p=$root.'/'.$name;if (is_link($p)) continue;
            $entries[]=['name'=>$name,'directory'=>is_dir($p),'bytes'=>is_file($p)?filesize($p):null];
        }
    }
    foreach ($vhostDirectories as $directory) {
        $file=$directory.'/'.$host.'.conf';
        if (!is_file($file)||is_link($file)||!is_readable($file)||filesize($file)>131072) continue;
        $text=file_get_contents($file);if ($text===false) continue;
        preg_match_all('/^\s*(?:root\s+([^;\r\n]+);|DocumentRoot\s+([^\r\n]+))/mi',$text,$matches,PREG_SET_ORDER);
        $roots=[];
        foreach ($matches as $match) {
            $value=trim(($match[1]??'')?:($match[2]??'')," \t\"'");
            $roots[]=str_starts_with($value,rtrim($webRoot,'/').'/')?substr($value,strlen(rtrim($webRoot,'/'))+1):'outside_owned_web_root';
        }
        $configs[]=['document_roots'=>array_values(array_unique($roots)),
            'tls_configured'=>preg_match('/^\s*ssl_certificate\s/m',$text)===1,
            'proxy_configured'=>preg_match('/^\s*proxy_pass\s/m',$text)===1];
    }
    $result=['schema'=>1,'ok'=>true,'domain'=>$host,'directory'=>is_dir($root),
        'public_entries'=>$entries,'vhosts'=>$configs,'configuration_changed'=>false,
        'dns_checked'=>false,'tls_checked'=>false];
    if ($network) {
        $result['dns_checked']=true;$result['ipv4']=gethostbynamel($host)?:[];
        if (function_exists('curl_init')) {
            $c=curl_init('https://'.$host.'/');
            curl_setopt_array($c,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
            curl_exec($c);$result['https']=['http_code'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),
                'curl_errno'=>curl_errno($c),'ssl_verify_result'=>curl_getinfo($c,CURLINFO_SSL_VERIFYRESULT),
                'content_type'=>curl_getinfo($c,CURLINFO_CONTENT_TYPE)];
            $result['tls_checked']=true;curl_close($c);
        }
    }
    return $result;
}

if (!defined('ANYTOUR_SELECTION_HOSTING_INVENTORY_LIBRARY')) {
    if (in_array('--host-detail',$argv??[],true)) {
        echo json_encode(selectionHostingDetail('/var/www/anytoour/data/www',
            ['/etc/nginx/vhosts/anytoour','/etc/apache2/vhosts/anytoour','/etc/httpd/conf/vhosts/anytoour'],true),JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    }
    echo json_encode(selectionHostingInventory(
        ['/var/www/anytoour/data/www'],
        ['/etc/nginx/vhosts/anytoour','/etc/apache2/vhosts/anytoour','/etc/httpd/conf/vhosts/anytoour']
    ),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}
