<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionShareService.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
$method=$_SERVER['REQUEST_METHOD']??'';
if (!in_array($method,['GET','HEAD'],true)) {header('Allow: GET, HEAD');http_response_code(405);exit;}
$token=is_string($_GET['t']??null)?$_GET['t']:'';
try {$selection=ManagerTourSelectionShareService::read($token);}
catch (Throwable $e) {$selection=null;http_response_code(503);}
if (!$selection && http_response_code()!==503) http_response_code(404);
if ($method==='HEAD') exit;
header('Content-Type: text/html; charset=utf-8');
function selectionEscape(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$h='selectionEscape';
require __DIR__.'/view.php';
