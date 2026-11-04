<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/onetouch-public.php';
final class ManagerTourSelectionService{public static function sourceContext(int $a,int $c):array{return ['ok'=>true,'version'=>4,'context_hash'=>str_repeat('c',64)];}}
require_once __DIR__.'/../services/ManagerTourSelectionOneTouchIntake.php';
$f=syntheticOneTouchSource();$now=time();$passed=0;
function capCheck(bool $v,string $n):void{global $passed;if(!$v)throw new RuntimeException('FAIL '.$n);$passed++;}
function capReject(callable $f,string $n):void{try{$f();}catch(RuntimeException $e){capCheck(true,$n);return;}capCheck(false,$n);}
$capture=['schema'=>'onetouch-manager-capture-v1','reference'=>'SYNTHETIC_RF_FIXTURE','captured_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'url'=>$f['url'],'public_controls'=>['TOWNFROMINC'=>['value'=>'2','text'=>'Synthetic City'],'STATEINC'=>['value'=>'9','text'=>'Synthetic Country'],'CURRENCY'=>['value'=>'1','text'=>'RUB']],'prices_gzip_base64'=>base64_encode(gzencode($f['response'])),'prices_sha256'=>hash('sha256',$f['response']),'galleries'=>[['hotel'=>'1001','url'=>'https://onetouch.travel/hotels/synthetic-hotel-1001/','observed_at'=>'2020-01-01T00:00:00Z','html_sha256'=>hash('sha256',$f['hotel']),'images'=>[['url'=>$f['photo'],'sha256'=>hash('sha256','synthetic bytes, not a production photo')]]]]];
$decoded=ManagerTourSelectionOneTouchCapture::read($capture,$now);capCheck(count($decoded['quotes'])===1,'bound manager capture decoded');$q=$decoded['quotes'][0];
capCheck($q['observed_at']===$capture['captured_at'],'declared price time retained');capCheck($q['raw']['capture_gallery_observed_at']==='2020-01-01T00:00:00Z','cached gallery time not refreshed');capCheck($decoded['expires']===$now+600,'application policy bound to price capture');
$reordered=$capture;$reordered['public_controls']=array_reverse($capture['public_controls'],true);capCheck(ManagerTourSelectionOneTouchCapture::read($reordered,$now)===$decoded,'JSON object key order does not change capture');
$later=ManagerTourSelectionOneTouchCapture::read($capture,$now+590);capCheck($later['expires']===$decoded['expires'],'upload does not extend source admission');
capReject(fn()=>ManagerTourSelectionOneTouchCapture::read($capture,$now+601),'old price file cannot mint fresh preview');
$bad=$capture;$bad['captured_at']=gmdate('Y-m-d\TH:i:s\Z',$now+61);capReject(fn()=>ManagerTourSelectionOneTouchCapture::read($bad,$now),'future capture refused');
$bad=$capture;$bad['prices_sha256']=str_repeat('0',64);capReject(fn()=>ManagerTourSelectionOneTouchCapture::read($bad,$now),'body digest mismatch refused');
$bad=$capture;$bad['verified_rf']=true;capReject(fn()=>ManagerTourSelectionOneTouchCapture::read($bad,$now),'untrusted verification flag refused');
$state=[];$key=ManagerTourSelectionOneTouchIntake::sessionKey($state,str_repeat('a',64));$r=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['capture'=>$capture]);capCheck($r['ok']===true,'same canonical intake accepts file');
capCheck((ManagerTourSelectionOneTouchIntake::search(2,1,$key,['url'=>$f['url']])['error']??'')==='onetouch_capture_required','unassigned backend never starts HTTP');
$ticket=$r['offers'][0]['ticket'];$ready=ManagerTourSelectionOneTouchIntake::review(2,1,$key,['ticket'=>$ticket,'gallery_gzip_base64'=>base64_encode(gzencode($f['hotel']))]);capCheck($ready['ok']===true&&$ready['photos']===[$f['photo']],'cached hotel admitted without network');
$opened=ManagerTourSelectionOneTouchIntake::open($ready['ticket'],$key,2,1,'ready');capCheck($opened['expires']===$decoded['expires'],'review does not renew capture expiry');
capCheck(str_contains($opened['observation']['raw']['capture_mode'],'manager-file'),'provenance explicitly manager provided');
$bad=ManagerTourSelectionOneTouchIntake::review(2,1,$key,['ticket'=>$ticket,'gallery_gzip_base64'=>base64_encode(gzencode($f['hotel'].'changed'))]);capCheck(($bad['error']??'')==='onetouch_photos_unavailable','signed cached body binding cannot be swapped');
capCheck(!str_contains(file_get_contents(__DIR__.'/../services/ManagerTourSelectionOneTouchIntake.php'),'curl_'),'no unassigned network implementation');
$import=ManagerTourSelectionImportDraft::normalize($opened['observation']);$offer=$import['offer'];$offer['import_source']=ManagerTourSelectionImportDraft::source($import);
$photoInput=['kind'=>'cover','source_photo_url'=>$f['photo'],'image_sha256'=>$capture['galleries'][0]['images'][0]['sha256']];
capCheck(ManagerTourSelectionOneTouchCapture::photoBinding($offer,$photoInput),'automatic bytes bound to same saved source');
$bad=$photoInput;$bad['image_sha256']=str_repeat('0',64);capCheck(!ManagerTourSelectionOneTouchCapture::photoBinding($offer,$bad),'different bytes rejected');
$bad=$photoInput;$bad['kind']='room';capCheck(!ManagerTourSelectionOneTouchCapture::photoBinding($offer,$bad),'different photo slot rejected');
$bad=$photoInput;$bad['source_photo_url']='https://image.onetouch.travel/other.jpg';capCheck(!ManagerTourSelectionOneTouchCapture::photoBinding($offer,$bad),'other hotel source rejected');
$badOffer=$offer;$badOffer['import_source']['adapter']='biblio-public-v1';capCheck(!ManagerTourSelectionOneTouchCapture::photoBinding($badOffer,$photoInput),'foreign operator cannot take file binding');
capCheck(ManagerTourSelectionOneTouchCapture::photoBinding($offer,[]),'ordinary manager photo editing remains allowed');
require_once __DIR__.'/../services/ManagerTourSelectionPublicationDraft.php';
$offer['id']=str_repeat('a',32);$offer['photos']=[['kind'=>'cover','url'=>$f['photo'],'illustrative'=>true]];
$publication=ManagerTourSelectionPublicationDraft::build(['title'=>'Synthetic timestamp visibility','intro'=>'','offers'=>[$offer]]);
capCheck($publication['ok']===true,'captured offer produces private preview');
$entry=$publication['offers'][0];$card=TourOfferCardRenderer::render($entry['snapshot'],$entry['presentation'],'tour_like_1_'.str_repeat('0',24));
capCheck(str_contains($card['plain_text'],'Время цены в файле: '.gmdate('d.m.Y H:i:s',$now).' UTC')&&str_contains($card['plain_text'],'Время галереи в файле: 01.01.2020 00:00:00 UTC'),'frozen preview and PNG caption expose separate source times');
echo json_encode(['checks'=>$passed,'syntheticOnly'=>true,'network'=>0,'imports'=>0,'operatorFareTTLAsserted'=>false],JSON_THROW_ON_ERROR)."\n";
