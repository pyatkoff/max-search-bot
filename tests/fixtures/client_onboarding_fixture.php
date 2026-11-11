<?php
declare(strict_types=1);
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $name)if(!defined($name))define($name,'synthetic-client-fixture');
require_once dirname(__DIR__,2).'/services/ManagerClientOnboardingService.php';
require_once __DIR__.'/regional_access_schema.php';

final class ClientFixturePdo extends PDO
{
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        return parent::prepare(str_replace('INSERT IGNORE','INSERT OR IGNORE',$query),$options);
    }
}
function clientFixtureBind(PDO $pdo,string $private): ProjectClientRegistry
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach([ProjectAccessService::class,ManagerAuthService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
    ProjectMaxConnection::resetForTests([]);
    $registry=new ProjectClientRegistry($pdo,new ProjectClientCredentialStore($private));ProjectClientRegistry::resetForTests($registry);return $registry;
}
function clientFixtureSchema(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $id=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';$fk=$mysql?'BIGINT UNSIGNED':'INTEGER';$suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE projects(id $id,project_key VARCHAR(191) NOT NULL UNIQUE,display_name VARCHAR(191) NOT NULL,is_active INTEGER NOT NULL DEFAULT 1)".$suffix);
    $pdo->exec("CREATE TABLE managers(id $id,login VARCHAR(191) NOT NULL UNIQUE,password_hash VARCHAR(255),display_name VARCHAR(191),role VARCHAR(24) NOT NULL,email VARCHAR(191),is_active INTEGER NOT NULL DEFAULT 1,is_working INTEGER NOT NULL DEFAULT 0,priority INTEGER DEFAULT 0)".$suffix);
    $pdo->exec("CREATE TABLE manager_projects(manager_id $fk NOT NULL,project_id $fk NOT NULL,PRIMARY KEY(manager_id,project_id))".$suffix);
    $pdo->exec("CREATE TABLE conversation_sources(id $id,project_id $fk NOT NULL,source_key VARCHAR(191) NOT NULL UNIQUE,display_name VARCHAR(191),channel VARCHAR(24),handling_mode VARCHAR(24) DEFAULT 'ai',fallback_mode VARCHAR(24),is_active INTEGER DEFAULT 1)".$suffix);
    $pdo->exec("CREATE TABLE conversations(id $id,project_key VARCHAR(64) NOT NULL)".$suffix);
    regionalAccessFixtureSchema($pdo);
    if($mysql)$pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/migrations/101_client_onboarding.sql'));
    else{
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('CREATE TABLE project_client_connections(project_id INTEGER PRIMARY KEY REFERENCES projects(id),bot_key TEXT NOT NULL UNIQUE COLLATE NOCASE,token_fingerprint TEXT NOT NULL UNIQUE,settings_json TEXT NOT NULL,credentials_json TEXT NOT NULL,materials_text TEXT NOT NULL,registration_fingerprint TEXT,last_check_json TEXT,activation_operation_id TEXT,activation_actor_id INTEGER)');
        $pdo->exec('CREATE TABLE project_client_operations(actor_id INTEGER NOT NULL REFERENCES managers(id),operation_id TEXT NOT NULL,project_id INTEGER REFERENCES projects(id),operation_kind TEXT NOT NULL,payload_hash TEXT NOT NULL,state TEXT NOT NULL,mutation_claimed INTEGER NOT NULL DEFAULT 0,result_json TEXT,PRIMARY KEY(actor_id,operation_id))');
    }
    $pdo->exec("INSERT INTO projects(id,project_key,display_name) VALUES(1,'anytour','AnyTour')");
    $pdo->exec("INSERT INTO managers(id,login,display_name,role,is_active,is_working) VALUES(1,'platform','Platform','admin',1,1),(2,'legacy','Existing manager','manager',1,1),(3,'platform2','Other admin','admin',1,0)");
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1),(2,1),(3,1)');
}
function clientFixtureInput(string $letter='a'): array
{
    return ['operation_id'=>str_repeat($letter,32),'display_name'=>'Ремонт '.$letter,'business_kind'=>'device_repair','bot_key'=>'synthetic_'.$letter.'_bot','token'=>'synthetic-private-token-'.$letter,'materials'=>"Ремонт телефонов\nДиагностика — бесплатно",'staff'=>[['login'=>'staff_'.$letter,'name'=>'Сотрудник '.$letter,'password'=>'test-password-'.$letter]]];
}
function clientFixtureProvider(array &$subscriptions,int &$writes,?callable $hook=null): callable
{
    return static function(ProjectMaxConnection $owner,string $method,string $path,array $query,?array $body)use(&$subscriptions,&$writes,$hook): array{
        if($hook)$hook($owner,$method,$path);
        if($method==='GET'&&$path==='/me')return ['http'=>200,'transport_error'=>false,'data'=>['is_bot'=>true,'username'=>$owner->botKey(),'user_id'=>12345]];
        if($method==='GET'&&$path==='/subscriptions')return ['http'=>200,'transport_error'=>false,'data'=>['subscriptions'=>$subscriptions]];
        if($method==='POST'&&$path==='/subscriptions'){$writes++;$subscriptions=[['url'=>$body['url'],'update_types'=>$body['update_types']]];return ['http'=>200,'transport_error'=>false,'data'=>['success'=>true]];}
        throw new RuntimeException('Unexpected synthetic provider request');
    };
}
function clientFixtureProbe(): array {return ['http'=>200,'transport_error'=>false,'data'=>['ok'=>true,'result'=>'ignored']];}
