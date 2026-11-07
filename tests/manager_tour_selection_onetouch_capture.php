<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/onetouch-public.php';
final class ManagerTourSelectionService{public static ?array $testContext=null;public static function sourceContext(int $a,int $c):array{return self::$testContext??['ok'=>true,'version'=>4,'context_hash'=>str_repeat('c',64)];}}
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
$fileReaderCalls=0;$fileWithReader=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['capture'=>$capture],static function($url)use(&$fileReaderCalls){$fileReaderCalls++;throw new RuntimeException('synthetic reader must not run');});capCheck($fileWithReader['ok']===true&&$fileReaderCalls===0,'explicit assigned native reader never overrides selected file capture');
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
// Different adult counts traverse the real file decoder and signed intake.
foreach([1,3,14] as $adults){
    $adultFixture=syntheticOneTouchSource($adults);$adultCapture=$capture;
    $adultCapture['reference']='SYNTHETIC_CAPTURE_ADULT_'.$adults;$adultCapture['url']=$adultFixture['url'];
    $adultCapture['prices_gzip_base64']=base64_encode(gzencode($adultFixture['response']));$adultCapture['prices_sha256']=hash('sha256',$adultFixture['response']);
    $adultDecoded=ManagerTourSelectionOneTouchCapture::read($adultCapture,$now);
    capCheck($adultDecoded['quotes'][0]['offer']['adults']===$adults&&$adultDecoded['quotes'][0]['raw']['party']===$adults.'AD','capture binds actual native party '.$adults);
    capCheck($adultDecoded['quotes'][0]['offer']['amount']==='123456.78'&&$adultDecoded['quotes'][0]['offer']['price_qualifier']==='from','capture does not rescale party minimum '.$adults);
    capCheck($adultDecoded['quotes'][0]['observed_at']===$capture['captured_at']&&$adultDecoded['quotes'][0]['raw']['capture_gallery_observed_at']==='2020-01-01T00:00:00Z','new party never refreshes source or gallery time '.$adults);
    $adultSearch=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['capture'=>$adultCapture]);
    capCheck($adultSearch['ok']===true&&$adultSearch['offers'][0]['offer']['adults']===$adults,'canonical signed search retains party '.$adults);
    $adultReady=ManagerTourSelectionOneTouchIntake::review(2,1,$key,['ticket'=>$adultSearch['offers'][0]['ticket'],'gallery_gzip_base64'=>base64_encode(gzencode($adultFixture['hotel']))]);
    $adultOpened=ManagerTourSelectionOneTouchIntake::open($adultReady['ticket'],$key,2,1,'ready');
    capCheck($adultReady['ok']===true&&$adultOpened['observation']['offer']['adults']===$adults&&$adultOpened['observation']['raw']['party']===$adults.'AD','ready ticket binds the same party and hotel '.$adults);
}
$mismatchedCapture=$adultCapture;$mismatchedCapture['url']=$f['url'];
capReject(fn()=>ManagerTourSelectionOneTouchCapture::read($mismatchedCapture,$now),'body party cannot be substituted by another query in the file');


// New direct app reader: actual executed URL, native revision and receive time.
$direct=syntheticOneTouchSource(3);$actualQuery=$direct['query'];$actualQuery['rev']='3187520523';
$actualUrl='https://b2b.onetouch.travel/search_tour?'.http_build_query($actualQuery,'','&',PHP_QUERY_RFC3986);
$received=gmdate('Y-m-d\TH:i:s\Z');$wireCalls=[];
$transport=static function(string $url)use(&$wireCalls,$direct,$actualUrl,$received):array{$wireCalls[]=$url;return ['url'=>$actualUrl,'form'=>$direct['form'],'response'=>$direct['response'],'observed_at'=>$received];};
$search=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['url'=>$direct['url']],$transport);
capCheck($search['ok']===true&&$wireCalls===[$direct['url']],'direct intent calls only the assigned reader once');
$native=ManagerTourSelectionOneTouchIntake::open($search['offers'][0]['ticket'],$key,2,1,'quote');
capCheck($native['observation']['url']===$actualUrl&&$native['observation']['observed_at']===$received,'ticket records actual fresh revision and actual response time');
capCheck($native['observation']['offer']['adults']===3&&$native['observation']['offer']['amount']==='123456.78'&&$native['observation']['offer']['price_qualifier']==='from','direct transport retains party amount and minimum qualifier');
capCheck(!isset($native['observation']['raw']['capture_mode'])&&$native['expires']===strtotime($received)+600,'direct source is not a counterfeit manager file');
$galleryReads=[];$review=ManagerTourSelectionOneTouchIntake::review(2,1,$key,['ticket'=>$search['offers'][0]['ticket']],static function(string $url)use(&$galleryReads,$direct):string{$galleryReads[]=$url;return $direct['hotel'];});
capCheck($review['ok']&&$galleryReads===['https://onetouch.travel/hotels/synthetic-hotel-1001/']&&$review['photos']===[$direct['photo']],'direct review reads exactly the signed hotel and returns native linked photos');
capCheck($review['source_reference']===['quote_id'=>$native['observation']['quote_id'],'url'=>$actualUrl],'direct review returns exact identity needed to match readonly receipt without offer id');
capCheck(ManagerTourSelectionOneTouchIntake::open($review['ticket'],$key,2,1,'ready')['expires']===$native['expires'],'direct review does not refresh quote expiry');
$wrongHotel=str_replace('Synthetic Fixture Hotel','Other Fixture Hotel',$direct['hotel']);
$wrong=ManagerTourSelectionOneTouchIntake::review(2,1,$key,['ticket'=>$search['offers'][0]['ticket']],static fn(string $url):string=>$wrongHotel);
capCheck(($wrong['error']??'')==='onetouch_photos_unavailable','different hotel cannot supply direct photos');
foreach(['ADULT'=>'2','NIGHTS_FROM'=>'8','CHECKIN_BEG'=>'20770108','STATEINC'=>'8','HOTELS'=>'1001'] as $field=>$value){
    $changed=$actualQuery;$changed[$field]=$value;$changedUrl='https://b2b.onetouch.travel/search_tour?'.http_build_query($changed,'','&',PHP_QUERY_RFC3986);
    $bad=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['url'=>$direct['url']],static fn(string $url):array=>['url'=>$changedUrl,'form'=>$direct['form'],'response'=>$direct['response'],'observed_at'=>$received]);
    capCheck(in_array($bad['error']??'',['onetouch_source_unavailable','onetouch_source_changed'],true),'executed URL cannot change business intent '.$field);
}
foreach([gmdate('Y-m-d\TH:i:s\Z',time()-61),gmdate('Y-m-d\TH:i:s\Z',time()+10)] as $time){
    $bad=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['url'=>$direct['url']],static fn(string $url):array=>['url'=>$actualUrl,'form'=>$direct['form'],'response'=>$direct['response'],'observed_at'=>$time]);
    capCheck(($bad['error']??'')==='onetouch_source_unavailable','old or future reader metadata cannot mint a fresh direct quote');
}
$noActual=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['url'=>$direct['url']],static fn(string $url):array=>['form'=>$direct['form'],'response'=>$direct['response'],'observed_at'=>$received]);
capCheck(($noActual['error']??'')==='onetouch_source_unavailable','actual executed URL is mandatory, not inferred from input revision');
ManagerTourSelectionService::$testContext=['ok'=>false,'error'=>'forbidden'];$forbiddenCalls=0;
$forbidden=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['url'=>$direct['url']],static function($url)use(&$forbiddenCalls){$forbiddenCalls++;return [];});
capCheck(($forbidden['error']??'')==='forbidden'&&$forbiddenCalls===0,'access denied before any source read');ManagerTourSelectionService::$testContext=null;
$changed=ManagerTourSelectionOneTouchIntake::search(2,1,$key,['url'=>$direct['url']],static function($url)use($transport){$r=$transport($url);ManagerTourSelectionService::$testContext=['ok'=>true,'version'=>5,'context_hash'=>str_repeat('c',64)];return $r;});
capCheck(($changed['error']??'')==='draft_changed','changed draft during direct search cannot receive old-context tickets');ManagerTourSelectionService::$testContext=null;
$changed=ManagerTourSelectionOneTouchIntake::review(2,1,$key,['ticket'=>$search['offers'][0]['ticket']],static function($url)use($direct){ManagerTourSelectionService::$testContext=['ok'=>true,'version'=>5,'context_hash'=>str_repeat('c',64)];return $direct['hotel'];});
capCheck(($changed['error']??'')==='draft_changed','changed context during hotel read cannot mint ready ticket');ManagerTourSelectionService::$testContext=null;

// Native form and read-policy branches run without DNS, cURL or external HTTP.
function directPrivate(string $method,...$args){return (new ReflectionMethod(ManagerTourSelectionOneTouchIntake::class,$method))->invoke(null,...$args);}
function directNativeForm(array $query):string{
    $html='<div class="samo_container" data-csrf-token="synthetic-no-real-session"></div><script src="/public/js/pack.main.js?rev=3187520523"></script><script src="/public/js/search_tour/search_tour.js?rev=3187520523"></script>';
    foreach($query as $name=>$value){
        if(in_array($name,['samo_action','PRICEPAGE','DYN_SEPARATE','rev','HOTELTYPES','PARTITION_PRICE'],true))continue;
        if(in_array($name,['TOWNS','HOTELS','STARS','MEALS','ROOMS'],true)){$html.='<div class="checklistbox" name="'.$name.'"></div>';continue;}
        if(in_array($name,['ADULT','CHILD','TOWNFROMINC','STATEINC','CURRENCY'],true)){$html.='<select class="'.$name.'" name="'.$name.'"><option value="'.$value.'" selected>'.($name==='CURRENCY'?'RUB':'Synthetic '.$name).'</option><option value="99" disabled>Disabled</option></select>';continue;}
        $date=in_array($name,['CHECKIN_BEG','CHECKIN_END'],true);if($date)$value=substr($value,6,2).'.'.substr($value,4,2).'.'.substr($value,0,4);
        $html.='<input name="'.$name.'" class="'.$name.($date?' date':'').'" value="'.$value.'">';
    }
    $html.='<input class="PARTITION_PRICE" type="checkbox" value="1">';return $html;
}
$nativeForm=directNativeForm($direct['query']);$facts=directPrivate('formContext',$nativeForm,$direct['query']);
capCheck($facts['rev']==='3187520523'&&$facts['csrf']==='synthetic-no-real-session','fresh native form supplies real observed revision and transient CSRF');
$formBads=[
    str_replace('class="ADULT"','class="ADULT" disabled',$nativeForm),
    str_replace('name="ADULT"','name="OTHER_ADULT"',$nativeForm),
    str_replace('name="CHECKIN_BEG"','name="MISSING_DATE"',$nativeForm),
    str_replace('value="07.01.2077"','value="08.01.2077"',$nativeForm),
    str_replace('class="FILTER" value="1"','class="FILTER" value="0"',$nativeForm),
    str_replace('search_tour.js?rev=3187520523','search_tour.js?rev=123',$nativeForm),
    str_replace('data-csrf-token="synthetic-no-real-session"','data-csrf-token="bad&#10;header"',$nativeForm),
    $nativeForm.'<input class="ADULT" name="ADULT" value="2">',
    $nativeForm.'<input class="COSTMIN" name="COSTMIN" value="100">',
    str_replace('<div class="checklistbox" name="HOTELS"></div>','<div class="checklistbox" name="HOTELS"><input type="checkbox" value="1001" checked></div>',$nativeForm),
    str_replace('class="PARTITION_PRICE" type="checkbox" value="1"','class="PARTITION_PRICE" type="checkbox" value="1" checked',$nativeForm)
];
foreach($formBads as $index=>$html)capReject(fn()=>directPrivate('formContext',$html,$direct['query']),'native form drift stops before PRICES '.$index);
$formUrl='https://b2b.onetouch.travel/search_tour?TOWNFROMINC=2&STATEINC=9&CHECKIN_BEG=20770107&CHECKIN_END=20770107&NIGHTS_FROM=7&NIGHTS_TILL=7&ADULT=3&CHILD=0&CURRENCY=1';
capCheck(directPrivate('readPolicy',$formUrl,'html')['host']==='b2b.onetouch.travel','bounded public form path admitted');
capCheck(directPrivate('readPolicy',$actualUrl,'prices')['types'][0]==='text/javascript','only explicit PRICES response kind admitted');
capCheck(directPrivate('readPolicy','https://onetouch.travel/hotels/synthetic-hotel-1001/','html')['host']==='onetouch.travel','canonical linked hotel page admitted');
foreach(['http://b2b.onetouch.travel/search_tour','https://b2b.onetouch.travel:443/search_tour','https://user@onetouch.travel/hotels/synthetic-hotel-1001/','https://onetouch.travel/hotels/synthetic-hotel-1001/?redirect=1','https://onetouch.travel/booking/1001','https://127.0.0.1/search_tour',$formUrl.'&samo_action=BOOK',$formUrl.'&ADULT=2'] as $badUrl)capReject(fn()=>directPrivate('readPolicy',$badUrl,'html'),'no generic public or internal URL reader');
foreach(['127.0.0.1','10.0.0.1','172.16.0.1','192.168.1.1','169.254.169.254','100.64.0.1','0.0.0.0','192.0.2.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','240.0.0.1','::1','::ffff:127.0.0.1','2130706433'] as $ip)capCheck(directPrivate('publicIp',$ip)===false,'non-public or ambiguous destination refused '.$ip);
capCheck(directPrivate('publicIp','93.184.216.34')===true,'ordinary public IPv4 address may be pinned without network');
$types=['text/html'];capCheck(directPrivate('responseAllowed',200,'text/html; charset=utf-8',100,100,$types,0,false,false),'complete bounded expected response admitted');
foreach([[302,'text/html',100,100,0,true,false],[403,'text/html',100,100,0,false,false],[200,'application/json',100,100,0,false,false],[200,'text/html',2097153,100,0,false,false],[200,'text/html',0,100,0,false,false],[200,'text/html',100,32769,0,false,false],[200,'text/html',100,100,1,false,false],[200,'text/html',100,100,0,false,true]] as [$status,$type,$bytes,$headerBytes,$age,$redirect,$encoding])capCheck(!directPrivate('responseAllowed',$status,$type,$bytes,$headerBytes,$types,$age,$redirect,$encoding),'redirect barrier wrongtype limits stale cache or compressed response rejected');

// Review F1: exercise the actual resolve -> public check -> reader ordering.
function directDnsName(string $name):string{$wire='';foreach(explode('.',$name) as $label)$wire.=chr(strlen($label)).$label;return $wire."\0";}
function directDnsPacket(array $answers,array $additional=[],string $host='b2b.onetouch.travel',int $id=12345,int $flags=0x8180):string{
    $wire=pack('nnnnnn',$id,$flags,1,count($answers),0,count($additional)).directDnsName($host).pack('nn',1,1);
    foreach(array_merge($answers,$additional) as [$owner,$type,$body]){$wire.=($owner==='@'?"\xc0\x0c":directDnsName($owner)).pack('nnNn',$type,1,60,strlen($body)).$body;}return $wire;
}
$dnsGood=directDnsPacket([['@',1,inet_pton('93.184.216.34')]]);
capCheck(directPrivate('dnsAddresses',$dnsGood,'b2b.onetouch.travel',12345)===['93.184.216.34'],'bounded answer binds exact A question');
$dnsAlias=directDnsPacket([['@',5,directDnsName('edge.synthetic.invalid')],['edge.synthetic.invalid',1,inet_pton('93.184.216.34')]]);
capCheck(directPrivate('dnsAddresses',$dnsAlias,'b2b.onetouch.travel',12345)===['93.184.216.34'],'one response resolves linked CNAME to public A without another query');
$unrelated=directDnsPacket([['@',1,inet_pton('93.184.216.34')]],[['b2b.onetouch.travel',1,inet_pton('127.0.0.1')]]);
capCheck(directPrivate('dnsAddresses',$unrelated,'b2b.onetouch.travel',12345)===['93.184.216.34'],'additional A is never selected');
$dnsBads=[
    directDnsPacket([['@',1,inet_pton('127.0.0.1')]]),
    directDnsPacket([['@',1,inet_pton('93.184.216.34')],['@',1,inet_pton('10.0.0.1')]]),
    directDnsPacket([['@',1,inet_pton('93.184.216.34')]],[],'b2b.onetouch.travel',12346),
    directDnsPacket([['@',1,inet_pton('93.184.216.34')]],[],'onetouch.travel'),
    directDnsPacket([['@',1,inet_pton('93.184.216.34')]],[],'b2b.onetouch.travel',12345,0x8380),
    directDnsPacket([['@',1,inet_pton('93.184.216.34')]],[],'b2b.onetouch.travel',12345,0x8182),
    directDnsPacket([['@',1,inet_pton('93.184.216.34')]],[],'b2b.onetouch.travel',12345,0x8980),
    directDnsPacket([['@',1,inet_pton('93.184.216.34')]],[],'b2b.onetouch.travel',12345,0x0180),
    substr($dnsGood,0,-1),
    pack('nnnnnn',12345,0x8180,1,1,0,0)."\xc0\x0c".pack('nn',1,1),
    pack('nnnnnn',12345,0x8180,1,33,0,0).substr($dnsGood,12),
    directDnsPacket([['@',5,directDnsName('edge.synthetic.invalid')],['edge.synthetic.invalid',5,directDnsName('b2b.onetouch.travel')]]),
    directDnsPacket([['@',5,directDnsName('missing.synthetic.invalid')]]),
    directDnsPacket([['unrelated.synthetic.invalid',1,inet_pton('93.184.216.34')]],[['b2b.onetouch.travel',1,inet_pton('93.184.216.35')]]),
    directDnsPacket([['@',5,"\xff\xff"]]),
    directDnsPacket([['@',1,'bad']])
];
$qEnd=12+strlen(directDnsName('b2b.onetouch.travel'));
$dnsBads[]=substr_replace($dnsGood,pack('nn',28,1),$qEnd,4);
foreach($dnsBads as $index=>$packet){
    $connections=0;$resolutions=0;
    capReject(function()use($formUrl,$packet,&$connections,&$resolutions){return directPrivate('withPublicAddress',$formUrl,'html',microtime(true)+15,static function($ip,$end)use(&$connections){$connections++;return 'connected';},static function($host,$end)use($packet,&$resolutions){$resolutions++;return directPrivate('dnsAddresses',$packet,$host,12345);});},'invalid DNS cannot reach connect/read '.$index);
    capCheck($resolutions===1&&$connections===0,'bad DNS has one resolution and no source connection or fallback '.$index);
}
foreach(["127.0.0.1 b2b.onetouch.travel\n","::1 b2b.onetouch.travel\n","93.184.216.34 b2b.onetouch.travel\n93.184.216.35 b2b.onetouch.travel\n"] as $hosts){
    $dnsCalls=0;$connections=0;
    capReject(function()use($formUrl,$hosts,&$dnsCalls,&$connections){return directPrivate('withPublicAddress',$formUrl,'html',microtime(true)+15,static function($ip,$end)use(&$connections){$connections++;},static function($host,$end)use($hosts,&$dnsCalls){$address=directPrivate('hostsAddress',$host,$hosts);if($address!==null)return $address;$dnsCalls++;return ['93.184.216.34'];});},'private unsupported or contradictory hosts override fails closed');
    capCheck($dnsCalls===0&&$connections===0,'explicit bad hosts override never falls back to DNS or source connect');
}
$connected=[];$resolved=[];$end=microtime(true)+15;
$value=directPrivate('withPublicAddress',$formUrl,'html',$end,static function($ip,$deadline)use(&$connected){$connected[]=[$ip,$deadline];return 'pinned-reader';},static function($host,$deadline)use(&$resolved){$resolved[]=[$host,$deadline];return directPrivate('hostsAddress',$host,"93.184.216.34 B2B.ONETOUCH.TRAVEL\n");});
capCheck($value==='pinned-reader'&&$resolved===[['b2b.onetouch.travel',$end]]&&$connected===[['93.184.216.34',$end]],'public hosts override pins before reader and preserves one common deadline');
capCheck(directPrivate('hostsAddress','b2b.onetouch.travel',"127.0.0.1 unrelated.invalid\n")===null,'unrelated hosts entry does not override source');
capCheck(directPrivate('assignedResolver',"# synthetic infrastructure\nnameserver 127.0.0.53\nnameserver 192.0.2.53\n")==='127.0.0.53','only first already assigned resolver is selected');
capReject(fn()=>directPrivate('assignedResolver','search synthetic.invalid'),'missing resolver has no guessed public fallback');
capReject(fn()=>directPrivate('assignedResolver',"nameserver resolver.example\n"),'resolver must be a literal address');
capReject(fn()=>directPrivate('assignedResolver',"nameserver 127.0.0.53\noptions use-vc\n"),'unsupported TCP-only DNS configuration is not bypassed');
capCheck(directPrivate('resolverPeer','127.0.0.53','127.0.0.53:53')&&directPrivate('resolverPeer','::1','[::1]:53'),'exact assigned IPv4 and IPv6 resolver endpoints admitted');
capCheck(!directPrivate('resolverPeer','127.0.0.53','127.0.0.1:53')&&!directPrivate('resolverPeer','127.0.0.53','127.0.0.53:54'),'foreign DNS sender or port rejected');
// F3: the real budget helper and phase guard share one end, below UI20s.
$constants=new ReflectionClass(ManagerTourSelectionOneTouchIntake::class);$clock=1000.0;$common=$clock+$constants->getConstant('SEARCH_SECONDS');
capCheck($common-$clock===15.0&&$constants->getConstant('GALLERY_SECONDS')===10,'source budgets retain response margin below client20s');
capCheck(directPrivate('remaining',$common,$clock)===8000&&directPrivate('remaining',$common,$clock+9)===6000&&directPrivate('remaining',$common,$clock+14.5)===500,'DNS form and PRICES consume one shrinking common budget');
capReject(fn()=>directPrivate('remaining',$common,$clock+15),'a later phase cannot receive another full timeout after expiry');
$fakeClock=1000.0;$connections=0;$resolutions=0;
capReject(function()use($formUrl,&$fakeClock,&$connections,&$resolutions){return directPrivate('withPublicAddress',$formUrl,'html',1015.0,static function($ip,$end)use(&$connections){$connections++;},static function($host,$end)use(&$fakeClock,&$resolutions){$resolutions++;$fakeClock=1015.0;return ['93.184.216.34'];},static function()use(&$fakeClock):float{return $fakeClock;});},'budget spent in resolution prevents connect/read without timing sleeps');
capCheck($resolutions===1&&$connections===0,'expired shared budget has no reader or retry');


echo json_encode(['checks'=>$passed,'syntheticOnly'=>true,'network'=>0,'imports'=>0,'operatorFareTTLAsserted'=>false],JSON_THROW_ON_ERROR)."\n";
