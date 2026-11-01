<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerPreparedReplyService.php';
final class PreparedInputRacePdo extends PDO
{
    public string $signal='';
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if($query==='SELECT * FROM manager_prepared_replies WHERE job_id=? FOR UPDATE'&&$this->signal!=='')file_put_contents($this->signal,'ready');
        return parent::prepare($query,$options);
    }
}
try{
    $a=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)!==1)throw new RuntimeException();
    if(!is_dir(dirname($a['signal']))||!preg_match('/\Aprepared-input-race-[a-f0-9]{16}\z/D',basename(dirname($a['signal'])))||!in_array(basename($a['signal']),['a','b'],true))throw new RuntimeException();
    $pdo=new PreparedInputRacePdo($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$m[1])throw new RuntimeException();
    $pdo->exec('SET NAMES utf8mb4');$pdo->signal=$a['signal'];
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
    $r=ManagerPreparedReplyService::complete($a['job'],$a['revision'],$a['proposal'],$a['receipt'],1,str_repeat('a',32));
    echo json_encode($r,JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,"synthetic receipt race failed\n");exit(1);}
