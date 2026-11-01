<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/manager-request-profile-fixture.php';

/** Isolated SQL/auth fixture with byte-exact production pages, API and assets. */
function managerRequestProfileHttpFixture(string $directory,bool $visual=false,string $mode='default'): array
{
    $directory=realpath($directory)?:'';
    if($directory===''||!str_starts_with(basename($directory),'manager-request-profile-')||count(array_diff(scandir($directory),['.','..']))!==0)throw new RuntimeException('fresh_local_fixture_directory_required');
    $root=dirname(__DIR__,2);$db=$directory.'/fixture.sqlite';$pdo=requestProfileFixture($db,$mode);
    if($visual){
        $view=ManagerRequestProfileService::read(1,1);$result=ManagerRequestProfileService::save(1,1,requestProfileCommand($view));
        if(empty($result['ok']))throw new RuntimeException('fixture_profile_save_failed');
        if($mode==='default')requestProfileMessage($pdo,12,'customer','На те же даты только на 3-х, 2 взрослых и один ребёнок 12 лет');
    }
    $web=$directory.'/web';mkdir($web.'/manager/lib',0700,true);mkdir($web.'/manager/assets',0700,true);mkdir($web.'/manager2',0700,true);mkdir($directory.'/sessions',0700);
    foreach(['manager/request-profile-api.php','manager/request-profile.php','manager2/request-profile.php','manager/lib/ManagerHttp.php','manager/assets/manager-http-client.js','manager/assets/manager-request-profile.js','manager/assets/manager-request-profile.css','manager/assets/manager-ai-usage.css'] as $file){if(!copy($root.'/'.$file,$web.'/'.$file))throw new RuntimeException('fixture_copy_failed');}
    if(!symlink($root.'/services',$web.'/services'))throw new RuntimeException('fixture_service_link_failed');
    $config="<?php\nrequire_once ".var_export($root.'/services/ManagerRequestProfileService.php',true).";\n";
    $config.='(new ReflectionProperty(ConversationDb::class,\'pdo\'))->setValue(null,new PDO('.var_export('sqlite:'.$db,true).",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));\n";
    $config.="ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);\n";file_put_contents($web.'/config.php',$config);
    session_save_path($directory.'/sessions');session_name('anytour_manager_panel');$sessions=[];
    foreach([1,2,3,4,5] as $actor){$sid=bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['manager_id'=>$actor,'csrf'=>'fixture-profile-csrf'];session_write_close();$sessions[(string)$actor]=$sid;}
    return ['root'=>$root,'webroot'=>$web,'database'=>$db,'sessions'=>$directory.'/sessions','session_ids'=>$sessions];
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__)echo json_encode(managerRequestProfileHttpFixture($argv[1]??'',($argv[2]??'')==='visual',$argv[3]??'default'),JSON_THROW_ON_ERROR);
