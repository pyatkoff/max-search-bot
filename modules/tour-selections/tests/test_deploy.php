<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/deploy.php';
$root=dirname(__DIR__,3);$payload=$argv[1]??'';
$dir=sys_get_temp_dir().'/selection-host-test-'.bin2hex(random_bytes(8));
mkdir($dir,0700);mkdir($dir.'/site',0755);mkdir($dir.'/logs',0700);
DiagnosticLogger::setFile($dir.'/logs/events.log');
$marker=ManagerTourSelectionPublicHost::markerPath();
$placeholder='Synthetic new site, no business data';
file_put_contents($dir.'/site/index.html',$placeholder);
$deploy=new TourSelectionHostDeployment($root,$dir.'/site',$dir.'/releases',$marker,hash('sha256',$placeholder));
$checks=0;
function hostCheck(bool $ok,string $name): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;echo 'PASS '.$name."\n";}
function hostThrows(callable $call,string $expected): void {try{$call();}catch(RuntimeException $e){hostCheck($e->getMessage()===$expected,$expected);return;}throw new RuntimeException('Expected '.$expected);}
try {
    $path='/selection/?t='.str_repeat('a',64);
    hostCheck(ManagerTourSelectionPublicHost::url($path)===null,'no new host URL before installed smoke');
    file_put_contents($dir.'/site/customer-file.txt','Synthetic existing file');
    hostThrows(fn()=>$deploy->install($payload,str_repeat('b',40)),'foreign_site_content');
    hostCheck(file_get_contents($dir.'/site/index.html')===$placeholder&&!file_exists($dir.'/releases'),'foreign content stops before writes');
    unlink($dir.'/site/customer-file.txt');
    $package=json_decode((string)file_get_contents($payload.'/release.json'),true);
    $release=$dir.'/releases/'.$package['package_id'].'-'.ManagerTourSelectionPublicHost::fingerprint($root);
    mkdir($release,0755,true);file_put_contents($release.'/view.php','Synthetic corrupt staging');
    hostThrows(fn()=>$deploy->install($payload,str_repeat('b',40)),'immutable_release_changed');
    hostCheck($deploy->rollback()['rolled_back']&&file_get_contents($dir.'/site/index.html')===$placeholder,'failure before backup never overwrites an untouched original site');
    unlink($release.'/view.php');
    $result=$deploy->install($payload,str_repeat('b',40));
    hostCheck($result['changed']&&!is_file($dir.'/site/index.html')&&is_link($dir.'/releases/current'),'initial package installs atomically behind the fixed routes');
    hostCheck(ManagerTourSelectionPublicHost::url($path)===null&&!is_file($marker),'staged host does not change generated links');
    foreach (['index.php','photo.php','activity.php'] as $route) hostCheck(file_get_contents($dir.'/site/'.$route)===$deploy->front($route),'only canonical '.$route.' adapter is installed');
    hostCheck(!is_file($dir.'/site/config.php')&&!is_dir($dir.'/site/manager')&&!is_dir($dir.'/site/services'),'no configuration or private app copied to public origin');
    $request=static function(string $path,string $method,array $headers)use($payload):array {
        $code=match($path){'/?t=bad','/photo.php?t=bad&v=1&card=0&photo=0','/config.php','/manager2/'=>404,'/activity.php'=>$method==='POST'?(in_array('Origin: https://tours.anytoour.ru',$headers,true)?422:403):405,default=>200};
        $body=in_array($path,['/selection.css','/activity.js'],true)?(string)file_get_contents($payload.$path):'Подборка сейчас недоступна';
        return ['status'=>$code,'body'=>$body,'headers'=>"Cache-Control: private, no-store\nX-Robots-Tag: noindex",'cookie'=>false];
    };
    hostThrows(fn()=>$deploy->activate(static fn()=>['status'=>500,'body'=>'','headers'=>'','cookie'=>false]),'host_route_smoke_failed');
    hostCheck(!is_file($marker),'failed hosted smoke cannot enable generated links');
    hostCheck($deploy->rollback()['rolled_back']&&file_get_contents($dir.'/site/index.html')===$placeholder&&!is_link($dir.'/releases/current'),'rollback restores the exact original placeholder');
    hostCheck(array_values(array_diff(scandir($dir.'/site'),['.','..']))===['index.html'],'rollback removes only the installed adapters');
    $deploy->install($payload,str_repeat('b',40));$activated=$deploy->activate($request);
    hostCheck($activated['active']&&ManagerTourSelectionPublicHost::url($path)==='https://tours.anytoour.ru/?t='.str_repeat('a',64),'only accepted host smoke enables the exact tours URL');
    $preview=$path.'&preview=1800000000.'.str_repeat('c',64);
    hostCheck(ManagerTourSelectionPublicHost::url($preview)==='https://tours.anytoour.ru/?'.substr($preview,strlen('/selection/?')),'signed preview retains its token and capability');
    foreach (['//evil.invalid','/selection/?t=bad',$path.'&unexpected=1',$path.'#x'] as $bad) hostCheck(ManagerTourSelectionPublicHost::url($bad)===null,'invalid public URL input is refused');
    $receipt=file_get_contents($marker);
    hostCheck(!$deploy->install($payload,str_repeat('b',40))['changed']&&file_get_contents($marker)===$receipt,'unchanged release reuses the installed receipt without reactivation');
    $forged=json_decode($receipt,true);$forged['origin']='https://evil.invalid';file_put_contents($marker,json_encode($forged));
    hostCheck(ManagerTourSelectionPublicHost::url($path)===null,'a marker cannot choose an arbitrary origin');
    $forged['origin']=ManagerTourSelectionPublicHost::ORIGIN;$forged['fingerprint']=str_repeat('0',64);file_put_contents($marker,json_encode($forged));
    hostCheck(ManagerTourSelectionPublicHost::url($path)===null,'backend or presentation drift falls back to existing app links');
    file_put_contents($marker,$receipt);
    file_put_contents($dir.'/site/index.php','Synthetic foreign edit');
    hostThrows(fn()=>$deploy->install($payload,str_repeat('b',40)),'foreign_site_route');
    hostCheck(file_get_contents($dir.'/site/index.php')==='Synthetic foreign edit'&&file_get_contents($marker)===$receipt,'foreign edits and active receipt are preserved');
    // Upgrade staging may fail either before or after replacing current. In both
    // cases restore A, without treating a third actor's pointer as our operation.
    $up=$dir.'/upgrade-backend';mkdir($up,0755);
    $spec=json_decode((string)file_get_contents($root.'/modules/tour-selections/module.json'),true);
    foreach (array_unique(['modules/tour-selections/module.json',...array_values($spec['files']),...$spec['backend_inputs']]) as $path) {
        if(!is_dir(dirname($up.'/'.$path)))mkdir(dirname($up.'/'.$path),0755,true);
        copy($root.'/'.$path,$up.'/'.$path);
    }
    mkdir($dir.'/upgrade-site',0755);file_put_contents($dir.'/upgrade-site/index.html',$placeholder);
    $upMarker=$dir.'/logs/upgrade.json';$upReleases=$dir.'/upgrade-releases';
    $upgrade=new TourSelectionHostDeployment($up,$dir.'/upgrade-site',$upReleases,$upMarker,hash('sha256',$placeholder));
    $upgrade->install($payload,str_repeat('b',40));$upgrade->activate($request);
    $a=readlink($upReleases.'/current');$aMarker=file_get_contents($upMarker);
    $bPayload=$dir.'/payload-b';mkdir($bPayload,0700);
    foreach(['view.php','selection.css','activity.js','release.json']as$name)copy($payload.'/'.$name,$bPayload.'/'.$name);
    file_put_contents($bPayload.'/selection.css',"\n/* Synthetic upgraded CSS */\n",FILE_APPEND);
    copy($bPayload.'/selection.css',$up.'/selection/selection.css');
    $b=json_decode((string)file_get_contents($bPayload.'/release.json'),true);$b['package_id']=hash('sha256','synthetic-upgrade-B');
    $b['files']['selection.css']=['sha256'=>hash_file('sha256',$bPayload.'/selection.css'),'bytes'=>filesize($bPayload.'/selection.css')];
    file_put_contents($bPayload.'/release.json',json_encode($b,JSON_THROW_ON_ERROR));
    $bTarget=$upReleases.'/'.$b['package_id'].'-'.ManagerTourSelectionPublicHost::fingerprint($up);
    mkdir($bTarget,0755);file_put_contents($bTarget.'/view.php','Synthetic interrupted staging');
    hostThrows(fn()=>$upgrade->install($bPayload,str_repeat('c',40)),'immutable_release_changed');
    hostCheck(readlink($upReleases.'/current')===$a&&file_get_contents($upMarker)===$aMarker,'failed upgrade staging leaves release A and its marker untouched');
    hostCheck($upgrade->rollback()['rolled_back']&&readlink($upReleases.'/current')===$a&&file_get_contents($upMarker)===$aMarker&&!is_file($upReleases.'/pending.json'),'upgrade rollback before pointer swap reconciles the untouched previous release');
    unlink($bTarget.'/view.php');
    $upgrade->install($bPayload,str_repeat('c',40));
    hostCheck(readlink($upReleases.'/current')===$bTarget,'valid upgrade staging swaps to B before smoke');
    hostThrows(fn()=>$upgrade->activate(static fn()=>['status'=>500,'body'=>'','headers'=>'','cookie'=>false]),'host_route_smoke_failed');
    hostCheck($upgrade->rollback()['rolled_back']&&readlink($upReleases.'/current')===$a&&file_get_contents($upMarker)===$aMarker,'upgrade rollback after pointer swap restores A and its exact receipt');
    $upgrade->install($bPayload,str_repeat('c',40));
    $third=$upReleases.'/'.str_repeat('d',64).'-'.str_repeat('e',64);mkdir($third,0755);
    unlink($upReleases.'/current');symlink($third,$upReleases.'/current');
    hostThrows(fn()=>$upgrade->rollback(),'host_operation_changed');
    hostCheck(readlink($upReleases.'/current')===$third&&is_file($upReleases.'/pending.json'),'rollback preserves an unrelated third pointer and the unresolved checkpoint');
    unlink($upReleases.'/current');symlink($bTarget,$upReleases.'/current');$upgrade->rollback();
    echo "host deployment checks: $checks PASS\n";
} finally {
    $remove=static function(string $path)use(&$remove):void {if(is_file($path)||is_link($path)){unlink($path);return;}foreach(scandir($path)?:[] as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);};
    $remove($dir);
}
