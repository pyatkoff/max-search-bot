<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/MigrationRunner.php';
require_once $baseDir.'/services/ManagerConversationService.php';

function tableExists(PDO $pdo,string $table):bool{
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return(int)$q->fetchColumn()>0;
}
function rows(PDO $pdo,string $sql,array $args=[]):array{$q=$pdo->prepare($sql);$q->execute($args);return$q->fetchAll();}
function redactSnapshotText(string $text):string{
    $text=preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u','[phone-redacted]',$text)??$text;
    return preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu','[email-redacted]',$text)??$text;
}
function compactText(string $text,int $limit=500):string{$text=redactSnapshotText($text);$text=preg_replace('/\s+/u',' ',trim($text))??trim($text);if(function_exists('mb_strlen')&&mb_strlen($text,'UTF-8')>$limit)return mb_substr($text,0,$limit-3,'UTF-8').'...';return strlen($text)>$limit?substr($text,0,$limit-3).'...':$text;}

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
        'manager_usage'=>[],
        'manager_visibility'=>[],
        'health'=>['manager_visibility_ok'=>true,'manager_visibility_anomalies'=>[]],
        'projects'=>[],
        'sources'=>[],
        'conversation_status'=>[],
        'recent_admin_audit'=>[],
        'recent_messages'=>[],
        'recent_events'=>[],
    ];

    foreach(['customers','customer_channels','conversations','messages','managers','manager_assignments','conversation_events','admin_audit_log']as$table){
        if(tableExists($pdo,$table))$snapshot['stats'][$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
    }
    if(tableExists($pdo,'managers'))$snapshot['managers']=rows($pdo,'SELECT id,login,display_name,role,is_active,is_working,last_login_at FROM managers ORDER BY id');
    if(tableExists($pdo,'manager_assignments'))$snapshot['manager_usage']=rows($pdo,"SELECT m.id AS manager_id,m.login,COUNT(a.id) AS assignments_total,SUM(CASE WHEN a.id IS NOT NULL AND a.released_at IS NULL THEN 1 ELSE 0 END) AS assignments_open FROM managers m LEFT JOIN manager_assignments a ON a.manager_id=m.id GROUP BY m.id,m.login ORDER BY m.id");
    if(tableExists($pdo,'projects'))$snapshot['projects']=rows($pdo,'SELECT id,project_key,display_name,is_active FROM projects ORDER BY id');
    if(tableExists($pdo,'conversation_sources')&&tableExists($pdo,'projects'))$snapshot['sources']=rows($pdo,'SELECT s.id,p.project_key,s.source_key,s.display_name,s.channel,s.is_active,s.primary_group_id,s.fallback_mode,s.fallback_group_id,s.fallback_after_minutes FROM conversation_sources s JOIN projects p ON p.id=s.project_id ORDER BY p.project_key,s.id');
    if(tableExists($pdo,'conversations'))$snapshot['conversation_status']=rows($pdo,'SELECT project_key,channel,status,COUNT(*) AS count FROM conversations GROUP BY project_key,channel,status ORDER BY project_key,channel,status');
    if(tableExists($pdo,'admin_audit_log'))$snapshot['recent_admin_audit']=rows($pdo,'SELECT id,actor_manager_id,action,entity_type,entity_id,project_key,created_at FROM admin_audit_log ORDER BY id DESC LIMIT 80');

    foreach($snapshot['managers'] as $manager){
        if(!(int)($manager['is_active']??0))continue;
        $id=(int)$manager['id'];
        $all=ManagerConversationService::list($id,'all',200,'*');
        $mine=ManagerConversationService::list($id,'mine',200,'*');
        $waiting=ManagerConversationService::list($id,'waiting',200,'*');
        $otherAssigned=0;
        foreach($all as $conversation){$assigned=(int)($conversation['manager_id']??0);if($assigned>0&&$assigned!==$id)$otherAssigned++;}
        $entry=[
            'manager_id'=>$id,
            'login'=>(string)($manager['login']??''),
            'role'=>(string)($manager['role']??'manager'),
            'is_working'=>(bool)($manager['is_working']??false),
            'all'=>count($all),
            'mine'=>count($mine),
            'waiting'=>count($waiting),
            'assigned_to_others'=>$otherAssigned,
        ];
        $snapshot['manager_visibility'][]=$entry;
        if($entry['is_working'] && $entry['waiting']>0 && $entry['all']<=$entry['mine']){
            $snapshot['health']['manager_visibility_ok']=false;
            $snapshot['health']['manager_visibility_anomalies'][]=[
                'manager_id'=>$id,'login'=>$entry['login'],'reason'=>'working_manager_all_collapsed_to_mine','all'=>$entry['all'],'mine'=>$entry['mine'],'waiting'=>$entry['waiting']
            ];
        }
    }

    if(tableExists($pdo,'messages')){
        $messages=rows($pdo,'SELECT id,conversation_id,direction,sender_type,channel,text,created_at FROM messages ORDER BY id DESC LIMIT 60');
        foreach($messages as&$m)$m['text']=compactText((string)$m['text']);unset($m);$snapshot['recent_messages']=$messages;
    }
    if(tableExists($pdo,'conversation_events'))$snapshot['recent_events']=rows($pdo,'SELECT id,conversation_id,event_type,actor_type,actor_id,created_at FROM conversation_events ORDER BY id DESC LIMIT 60');

    echo json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");exit(1);
}
