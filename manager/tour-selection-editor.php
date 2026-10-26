<?php
declare(strict_types=1);
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionEditorHost.php';
// Identity-free code shell, like /manager2/. Session requests use ManagerHttp::startJson()
// in tour-selection-editor-api.php; this GET must never issue/replace a cookie.
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: blob:; connect-src 'self'; frame-ancestors https://tours.anytoour.ru; base-uri 'none'; form-action 'none'");
if (!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) {http_response_code(405);exit;}
$installed=ManagerTourSelectionEditorHost::installed();
if (isset($_GET['asset'])) {
    $asset=$_GET['asset'];$package=$_GET['package']??null;
    $assetPackage=is_string($package)?ManagerTourSelectionEditorHost::assetPackage($package):null;
    if (!is_string($asset) || !isset(ManagerTourSelectionEditorHost::ASSETS[$asset]) || !$assetPackage) {http_response_code(404);exit;}
    header('Content-Type: '.ManagerTourSelectionEditorHost::ASSETS[$asset].'; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') readfile($assetPackage['directory'].'/'.$asset);
    exit;
}
if (!$installed) {http_response_code(503);exit('Редактор временно недоступен. Откройте подборку в Manager2.');}
$editorAsset=static fn(string $name):string=>'tour-selection-editor.php?asset='.rawurlencode($name).'&amp;package='.$installed['package_id'];
if (($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') require $installed['directory'].'/app.php';
