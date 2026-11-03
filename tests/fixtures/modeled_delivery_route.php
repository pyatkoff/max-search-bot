<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/run_modeled_conversion_delivery_registry_regression.php';
require_once dirname(__DIR__,2).'/services/ConversationDb.php';
require_once dirname(__DIR__,2).'/services/ProjectConfig.php';

final class ModeledDeliveryRouteFixturePDO extends PDO
{
    public bool $switchSession=false;
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if($this->switchSession&&str_contains($query,'FROM max_channel_conversion_outbox o')){
            $this->switchSession=false;
            if(session_status()!==PHP_SESSION_NONE||!session_start())throw new RuntimeException('fixture_session');
            $_SESSION['manager_id']=8;session_write_close();
        }
        return parent::prepare($query,$options);
    }
}
$case=$argv[1]??'admin';
if(!in_array($case,['admin','manager','inactive','no_project','foreign','post','parameters','session_changed','anonymous'],true))exit(2);
$repo=dirname(__DIR__,2);
$dir=sys_get_temp_dir().'/modeled_delivery_route_'.bin2hex(random_bytes(10));
if(!mkdir($dir,0700)||!mkdir($dir.'/manager',0700)||!mkdir($dir.'/sessions',0700))exit(2);
if(!symlink($repo.'/services',$dir.'/services')||!symlink($repo.'/manager/lib',$dir.'/manager/lib')
    ||!copy($repo.'/manager/assigned-value.php',$dir.'/manager/assigned-value.php'))exit(2);
$pdo=new ModeledDeliveryRouteFixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
deliveryRegistrySchema($pdo);
$now=time();deliveryRegistryFixtureEvent($pdo,101,1,$now-60,['name'=>'Синтетический турист: проверка доступа']);
foreach(['manager_projects','projects','managers']as$table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec('CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER)');
$pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1),(2,'other','Other',1)");
$pdo->exec('CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER)');
$pdo->exec('INSERT INTO manager_projects VALUES(7,1),(8,1),(9,1)');
$pdo->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER)');
$pdo->exec("INSERT INTO managers VALUES(7,'synthetic-admin','Синтетический администратор','admin','',1,0),
    (8,'synthetic-manager','Синтетический менеджер','manager','',1,0),
    (9,'synthetic-inactive','Синтетический отключённый','admin','',0,0),
    (10,'synthetic-no-project','Синтетический без проекта','admin','',1,0)");
$property=new ReflectionProperty(ConversationDb::class,'pdo');$property->setAccessible(true);$property->setValue(null,$pdo);
ProjectConfig::resetForTests(['id'=>$case==='foreign'?'other':'anytour','brand'=>['name'=>$case==='foreign'?'Other':'AnyTour']]);
$config="<?php\nrequire_once ".var_export($repo.'/services/ConversationDb.php',true).";\nrequire_once ".var_export($repo.'/services/ProjectConfig.php',true).";\n";
if(file_put_contents($dir.'/config.php',$config)===false)exit(2);
$before=deliveryRegistryDataDigest($pdo);
foreach(['customers','customer_channels','conversations','modeled_conversion_profile_versions','max_channel_conversion_outbox','projects','manager_projects','managers']as$table)
    foreach(['INSERT','UPDATE','DELETE']as$verb)
        $pdo->exec('CREATE TRIGGER fixture_no_'.strtolower($verb).'_'.$table.' BEFORE '.$verb.' ON '.$table." BEGIN SELECT RAISE(ABORT,'fixture_write_forbidden'); END");
$actor=match($case){'manager'=>8,'inactive'=>9,'no_project'=>10,'anonymous'=>0,default=>7};
session_save_path($dir.'/sessions');session_name('anytour_manager_panel');session_id('fixtureledger'.bin2hex(random_bytes(8)));
session_start();$_SESSION['manager_id']=$actor;session_write_close();
$_SERVER['SCRIPT_NAME']='/manager/assigned-value.php';$_SERVER['REQUEST_URI']='/manager/assigned-value.php?view=delivery';
$_SERVER['REQUEST_METHOD']=$case==='post'?'POST':'GET';
$_GET=['view'=>'delivery'];$_POST=[];
if($case==='parameters')$_GET['unexpected']='fixture';
if($case==='session_changed')$pdo->switchSession=true;
register_shutdown_function(static function()use($pdo,$before,$dir):void{
    $status=http_response_code();if($status===false)$status=200;
    echo "\nMDR-RESULT:".json_encode(['status'=>$status,'unchanged'=>deliveryRegistryDataDigest($pdo)===$before],JSON_THROW_ON_ERROR)."\n";
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $remove=static function(string $path)use(&$remove):void{
        if(is_link($path)||is_file($path)){unlink($path);return;}
        if(is_dir($path)){foreach(scandir($path)?:[]as$name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}
    };
    $remove($dir);
});
require $dir.'/manager/assigned-value.php';
