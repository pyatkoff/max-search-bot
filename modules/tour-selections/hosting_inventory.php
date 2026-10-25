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

if (!defined('ANYTOUR_SELECTION_HOSTING_INVENTORY_LIBRARY')) {
    echo json_encode(selectionHostingInventory(
        ['/var/www/anytoour/data/www'],
        ['/etc/nginx/vhosts/anytoour','/etc/apache2/vhosts/anytoour','/etc/httpd/conf/vhosts/anytoour']
    ),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}
