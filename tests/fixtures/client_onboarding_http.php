<?php
declare(strict_types=1);
/** Called only by a disposable loopback checkout with synthetic config/session. */
function clientHttpBootstrap(string $directory): void
{
    if(PHP_SAPI!=='cli-server'||!is_file($directory.'/synthetic-client-fixture'))throw new RuntimeException('Not an isolated HTTP fixture');
    foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $name)define($name,'synthetic-client-http');
    require_once $directory.'/services/ManagerClientOnboardingService.php';
    class ClientHttpPdo extends PDO
    {
        public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace('INSERT IGNORE','INSERT OR IGNORE',$query),$options);}
    }
    $pdo=new ClientHttpPdo('sqlite:'.$directory.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectMaxConnection::class,'testRegistry'))->setValue(null,[]);
    (new ReflectionProperty(ProjectClientRegistry::class,'testStore'))->setValue(null,new ProjectClientRegistry($pdo,new ProjectClientCredentialStore($directory.'/private')));
    if(is_file($directory.'/read-only'))$pdo->exec('PRAGMA query_only=ON');
}
