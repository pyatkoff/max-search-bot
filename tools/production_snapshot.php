<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/MigrationRunner.php';

function tableExists(PDO $pdo,string $table):bool{
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return(int)$q->fetchColumn()>0;
}
function rows(PDO $pdo,string $sql,array $args=[]):array{$q=$pdo->prepare($sql);$q->execute($args);return$q->fetchAll();}
function compactText(string $text,int $limit=500):string{$text=preg_replace('/\s+/u',' ',trim($text))??trim($text);if(function_exists('mb_strlen')&&mb_strlen($text,'UTF-8')>$limit)return mb_substr($text,0,$limit-3,'UTF-8').'...';return strlen($text)>$limit?substr($text,0,$limit-3).'...':$text;}

try{
    $pdo=ConversationDb::connection();
    $ping=ConversationDb::ping();
    $root=realpath($baseDir)?:$baseDir;
    $sha=trim((string)@shell_exec('cd '.escapeshellarg($root).' && git rev-parse HEAD 2>/dev/null'));
    $branch=trim((string)@shell_exec('cd '.escapeshellarg($root).' && git rev-parse --abbrev-ref HEAD 2>/dev/null'));
    $snapshot=[
        'ok'=>true,
        'generated_at'=>gmdate('c'),
        'production'=>['sha'=>$sha,'branch'=>$branch],
        'database'=>['name'=>$ping['database']??null,'host'=>$ping['host']??null,'latency_ms'=>$ping['latency_ms']??null],
        'migrations'=>(new MigrationRunner())->status(),
        'stats'=>[],
        'managers'=>[],
        'projects'=>[],
        'sources'=>[],
        'conversation_status'=>[],
        'recent_messages'=>[],
        'recent_events'=>[],
    ];

    foreach(['customers','customer_channels','conversations','messages','managers','manager_assignments','conversation_events']as$table){
        if(tableExists($pdo,$table))$snapshot['stats'][$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
    }
    if(tableExists($pdo,'managers'))$snapshot['managers']=rows($pdo,'SELECT id,login,display_name,role,is_active,is_working,last_login_at FROM managers ORDER BY id');
    if(tableExists($pdo,'projects'))$snapshot['projects']=rows($pdo,'SELECT id,project_key,display_name,is_active FROM projects ORDER BY id');
    if(tableExists($pdo,'conversation_sources')&&tableExists($pdo,'projects'))$snapshot['sources']=rows($pdo,'SELECT s.id,p.project_key,s.source_key,s.display_name,s.channel,s.is_active,s.primary_group_id,s.fallback_mode,s.fallback_group_id,s.fallback_after_minutes FROM conversation_sources s JOIN projects p ON p.id=s.project_id ORDER BY p.project_key,s.id');
    if(tableExists($pdo,'conversations'))$snapshot['conversation_status']=rows($pdo,'SELECT project_key,channel,status,COUNT(*) AS count FROM conversations GROUP BY project_key,channel,status ORDER BY project_key,channel,status');
    if(tableExists($pdo,'messages')){
        $messages=rows($pdo,'SELECT id,conversation_id,direction,sender_type,channel,text,created_at FROM messages ORDER BY id DESC LIMIT 60');
        foreach($messages as&$m)$m['text']=compactText((string)$m['text']);unset($m);$snapshot['recent_messages']=$messages;
    }
    if(tableExists($pdo,'conversation_events'))$snapshot['recent_events']=rows($pdo,'SELECT id,conversation_id,event_type,actor_type,actor_id,created_at FROM conversation_events ORDER BY id DESC LIMIT 60');

    echo json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");exit(1);
}
