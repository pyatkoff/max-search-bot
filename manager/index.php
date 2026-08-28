<?php
$uriPath=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if(is_string($uriPath) && preg_match('~/index\.php$~',$uriPath)){
    $target=preg_replace('~/index\.php$~','/',$uriPath)?:'/max-search/manager/';
    header('Location: '.$target,true,302);
    exit;
}
require __DIR__.'/workspace-v2.php';
