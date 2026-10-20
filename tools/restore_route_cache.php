<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/RouteCacheRestorer.php';
$args=array_slice($argv,1);
if (count($args)!==2 || $args[0]!=='--source' || !is_string($args[1]) || $args[1]==='') {
    fwrite(STDERR,"usage: restore_route_cache.php --source <staged-cache>\n");exit(2);
}
$result=RouteCacheRestorer::installAbsent($args[1],dirname(__DIR__).'/tourvisor_routes.json');
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($result['ok']?0:1);
