<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/deploy-editor.php';
$root=dirname(__DIR__,3);$input=$argv[1]??'';
$tmp=sys_get_temp_dir().'/selection-editor-deploy-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
$backend=$tmp.'/backend';$site=$tmp.'/site';$releases=$tmp.'/private/selection-editor-releases';mkdir($backend,0700);mkdir($site,0700);mkdir($tmp.'/private',0700);
$checks=0;
function checkEditor(string $name,bool $ok):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);++$checks;echo 'PASS '.$name."\n";}
function removeEditorFixture(string $path):void {if(is_link($path)||is_file($path)){unlink($path);return;}foreach(array_diff(scandir($path),['.','..'])as$n)removeEditorFixture($path.'/'.$n);rmdir($path);}
function editorThrows(callable $fn,string $error):bool {try{$fn();return false;}catch(RuntimeException $e){return $e->getMessage()===$error;}}
try {
    $spec=ManagerTourSelectionEditorHost::json($root.'/modules/tour-selections/editor.json');
    foreach(array_unique(['modules/tour-selections/editor.json',...array_values($spec['files']),...$spec['backend_inputs']])as$path){if(!is_dir(dirname($backend.'/'.$path)))mkdir(dirname($backend.'/'.$path),0700,true);copy($root.'/'.$path,$backend.'/'.$path);}
    // The real code shell loads canonical helper dependencies, without config/session writes.
    foreach(glob($root.'/services/*.php')as$file)if(!file_exists($backend.'/services/'.basename($file)))symlink($file,$backend.'/services/'.basename($file));
    file_put_contents($site.'/index.php','synthetic existing public page');
    $deploy=new TourSelectionEditorDeployment($backend,$site,$releases);
    checkEditor('no standalone link before installation',ManagerTourSelectionEditorHost::entry($backend,$releases)===null);
    file_put_contents($site.'/editor.php','foreign');
    checkEditor('foreign editor route is never overwritten',editorThrows(fn()=>$deploy->install($input,str_repeat('a',40)),'foreign_editor_route')&&file_get_contents($site.'/editor.php')==='foreign');unlink($site.'/editor.php');
    $receipt=ManagerTourSelectionEditorHost::json($input.'/receipt.json');$source=$receipt['source_commit'];
    checkEditor('exact package installs',$deploy->install($input,$source)['changed']===true);
    checkEditor('staged package has no generated standalone link',ManagerTourSelectionEditorHost::entry($backend,$releases)===null);
    checkEditor('private code is compatible before activation',ManagerTourSelectionEditorHost::installed($backend,$releases)!==null);
    checkEditor('reserved operation cannot be repeated',editorThrows(fn()=>$deploy->install($input,$source),'unfinished_editor_operation'));
    $calls=0;$fail=false;
    $request=static function(string $origin,string $path,string $method,string $body)use(&$calls,&$fail,$backend,$releases):array {
        ++$calls;$installed=ManagerTourSelectionEditorHost::installed($backend,$releases);
        $headers="Cache-Control: private, no-store\nContent-Security-Policy: frame-src https://app.anytoour.ru; frame-ancestors https://tours.anytoour.ru";
        if($fail)return['status'=>200,'headers'=>$headers."\nSet-Cookie: forbidden",'body'=>'Manager2'];
        if($method==='POST')return['status'=>401,'headers'=>$headers,'body'=>'{"ok":false,"error":"unauthorized"}'];
        parse_str((string)parse_url($path,PHP_URL_QUERY),$query);
        if(isset($query['asset']))return['status'=>200,'headers'=>$headers,'body'=>file_get_contents($installed['directory'].'/'.$query['asset'])];
        return['status'=>200,'headers'=>$headers,'body'=>'Manager2 id="editorLauncher" https://app.anytoour.ru/manager/tour-selection-editor.php?conversation_id=1&amp;editor_actor=1'];
    };
    $fail=true;checkEditor('tours cookie leak fails smoke',editorThrows(fn()=>$deploy->activate($request),'editor_tours_smoke_failed'));
    checkEditor('failed activation cannot enable links',ManagerTourSelectionEditorHost::entry($backend,$releases)===null);
    checkEditor('initial rollback removes only owned editor route',$deploy->rollback()['rolled_back']===true&&!file_exists($site.'/editor.php')&&file_get_contents($site.'/index.php')==='synthetic existing public page');
    $fail=false;$deploy->install($input,$source);checkEditor('accepted smoke enables exact tours entry',$deploy->activate($request)['active']===true&&ManagerTourSelectionEditorHost::entry($backend,$releases)==='https://tours.anytoour.ru/editor.php');
    checkEditor('ordinary accepted release uses normal reconciliation',$deploy->commonStatus()===['ok'=>true,'mode'=>'normal']);
    checkEditor('ordinary release is not independent common-release authority',ManagerTourSelectionEditorHost::acceptedPrior($releases)===null);
    $before=$calls;checkEditor('unchanged package reuses installed receipt without another activation',$deploy->install($input,$source)['changed']===false&&$deploy->activate($request)['reused']===true&&$calls===$before);
    $active=file_get_contents($releases.'/active.json');$current=readlink($releases.'/current');$originalSeal=file_get_contents($current.'/deployment.json');
    // A complete synthetic second package exercises upgrade/rollback, never edits repository bytes.
    $upgrade=$tmp.'/upgrade';mkdir($upgrade);mkdir($upgrade.'/payload');
    foreach(array_diff(scandir($input.'/payload'),['.','..'])as$name)copy($input.'/payload/'.$name,$upgrade.'/payload/'.$name);
    $newSource=str_repeat('b',40);$release=ManagerTourSelectionEditorHost::json($upgrade.'/payload/release.json');
    // Only canonical editor JS/CSS are independent members. Private host CSS
    // now belongs to the explicit shared-host compatibility fingerprint.
    $name='editor.css';$bytes=file_get_contents($upgrade.'/payload/'.$name)."\n/* synthetic upgrade */\n";
    file_put_contents($upgrade.'/payload/'.$name,$bytes);file_put_contents($backend.'/'.$spec['files'][$name],$bytes);
    $release['files'][$name]=['sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)];unset($release['package_id']);$release['package_id']=hash('sha256',ManagerTourSelectionEditorHost::encoded($release));
    file_put_contents($upgrade.'/payload/release.json',ManagerTourSelectionEditorHost::encoded($release));
    $zip=proc_open(['python3','-c','import pathlib,sys,zipfile; p=pathlib.Path(sys.argv[1]); z=zipfile.ZipFile(p/"selection-editor.zip","w"); [z.write(f,f.name) for f in sorted((p/"payload").iterdir())]; z.close()',$upgrade],[['pipe','r'],['file',$tmp.'/zip.log','a'],['file',$tmp.'/zip.log','a']],$pipes);
    if(!is_resource($zip)||proc_close($zip)!==0)throw new RuntimeException('Synthetic archive build failed');foreach($pipes as$p)if(is_resource($p))fclose($p);
    file_put_contents($upgrade.'/receipt.json',json_encode(['release_eligible'=>true,'source_commit'=>$newSource,'source_tree'=>str_repeat('b',40),'archive_sha256'=>hash_file('sha256',$upgrade.'/selection-editor.zip'),'backend_fingerprint'=>ManagerTourSelectionEditorHost::fingerprint($backend),'package_id'=>$release['package_id']]));
    $deploy->install($upgrade,$newSource);$fail=true;
    checkEditor('failed upgrade smoke remains blocked',editorThrows(fn()=>$deploy->activate($request),'editor_tours_smoke_failed'));
    checkEditor('upgrade rollback restores exact prior pointer and active receipt',$deploy->rollback()['rolled_back']===true&&readlink($releases.'/current')===$current&&file_get_contents($releases.'/active.json')===$active);
    checkEditor('frontend-only failed upgrade restores a WORKING original package without source edits',ManagerTourSelectionEditorHost::entry($backend,$releases)!==null&&file_get_contents($backend.'/'.$spec['files'][$name])===$bytes&&file_get_contents($current.'/'.$name)!==$bytes);
    checkEditor('rollback never rewrites original source provenance',file_get_contents($current.'/deployment.json')===$originalSeal);
    $http=proc_open([PHP_BINARY,$root.'/tests/run_tour_selection_editor_private.php',$backend,$releases],[['pipe','r'],['file',$tmp.'/http.log','a'],['file',$tmp.'/http.log','a']],$pipes);
    $httpStatus=is_resource($http)?proc_close($http):-1;foreach($pipes as$p)if(is_resource($p))fclose($p);
    if($httpStatus!==0)fwrite(STDERR,(string)file_get_contents($tmp.'/http.log'));
    checkEditor('working rollback A shell/assets plus protected draft save/reload over actual HTTP',$httpStatus===0);
    $inputPath=$spec['backend_inputs'][0];$oldBackend=file_get_contents($backend.'/'.$inputPath);file_put_contents($backend.'/'.$inputPath,$oldBackend."\n// synthetic incompatible backend\n");
    checkEditor('actual backend-contract drift still blocks old artifact',ManagerTourSelectionEditorHost::entry($backend,$releases)===null);
    checkEditor('backend drift cannot expose accepted old assets through intrinsic seal validation',ManagerTourSelectionEditorHost::assetPackage($receipt['package_id'],$backend,$releases)===null);
    file_put_contents($backend.'/'.$inputPath,$oldBackend);
    checkEditor('restored backend contract accepts sealed A despite frontend B in source',ManagerTourSelectionEditorHost::entry($backend,$releases)!==null);
    $asset=$current.'/editor.js';$bytes=file_get_contents($asset);file_put_contents($asset,$bytes."\nforeign");
    checkEditor('payload drift invalidates active receipt',ManagerTourSelectionEditorHost::entry($backend,$releases)===null);file_put_contents($asset,$bytes);
    checkEditor('public page remains byte-identical',file_get_contents($site.'/index.php')==='synthetic existing public page');
    echo "editor deployment: $checks PASS\n";
}finally{removeEditorFixture($tmp);}
