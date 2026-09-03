<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/MigrationRunner.php';
require_once $baseDir.'/services/ManagerConversationService.php';
require_once $baseDir.'/services/ManagerDeliveryStateService.php';
require_once $baseDir.'/services/LeadTaskService.php';
require_once $baseDir.'/services/ManagerResponseHealth.php';
require_once $baseDir.'/services/ManagerPushHealth.php';
require_once $baseDir.'/services/HandoffIntegrityHealth.php';
require_once $baseDir.'/services/WebsiteAttributionHealth.php';
require_once $baseDir.'/services/AdminProjectAccessHealth.php';

function tableExists(PDO $pdo,string $table):bool{
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return(int)$q->fetchColumn()>0;
}
function rows(PDO $pdo,string $sql,array $args=[]):array{$q=$pdo->prepare($sql);$q->execute($args);return$q->fetchAll();}
function redactSnapshotText(string $text):string{
    $text=preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u','[phone-redacted]',$text)??$text;
    return preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu','[email-redacted]',$text)??$text;
}
function compactText(string $text,int $limit=500):string{$text=redactSnapshotText($text);$text=preg_replace('/\s+/u',' ',trim($text))??trim($text);if(function_exists('mb_strlen')&&mb_strlen($text,'UTF-8')>$limit)return mb_substr($text,0,$limit-3,'UTF-8').'...';return strlen($text)>$limit?substr($text,0,$limit-3).'...':$text;}
function recentStructuredEvents(string $path,string $component,string $event,int $limit=30):array{
    if(!is_file($path)||!is_readable($path))return[];
    $raw=(string)@shell_exec('tail -n 500 '.escapeshellarg($path).' 2>/dev/null');
    if($raw==='')return[];
    $matched=[];
    foreach(array_reverse(preg_split('/\R/u',trim($raw))?:[]) as $line){
        $row=json_decode($line,true);
        if(!is_array($row))continue;
        if((string)($row['component']??'')!==$component||(string)($row['event']??'')!==$event)continue;
        $matched[]=$row;
        if(count($matched)>=$limit)break;
    }
    return $matched;
}
function recentStructuredComponentEvents(string $path,string $component,int $limit=50):array{
    if(!is_file($path)||!is_readable($path))return[];
    $raw=(string)@shell_exec('tail -n 800 '.escapeshellarg($path).' 2>/dev/null');
    if($raw==='')return[];
    $matched=[];
    foreach(array_reverse(preg_split('/\R/u',trim($raw))?:[]) as $line){
        $row=json_decode($line,true);
        if(!is_array($row)||(string)($row['component']??'')!==$component)continue;
        $matched[]=$row;
        if(count($matched)>=$limit)break;
    }
    return $matched;
}
function managerLeadDetailFailure(Throwable $e):array{
    $failure=['exception'=>get_class($e),'code'=>(string)$e->getCode()];
    if($e instanceof PDOException&&is_array($e->errorInfo)){$failure['sqlstate']=$e->errorInfo[0]??null;$failure['driver_code']=$e->errorInfo[1]??null;}
    return$failure;
}
function managerLeadDetailHealth(PDO $pdo):array{
    $expected=[
        'conversations'=>['lead_stage_key','lead_outcome','lead_close_reason','lead_outcome_note','lead_sale_amount','lead_sale_date','lead_outcome_updated_at','lead_outcome_manager_id'],
        'lead_stages'=>['stage_key','display_name','color','sort_order','is_active','is_terminal','is_won'],
        'lead_tags'=>['id','tag_key','display_name','color','sort_order','is_active'],
        'conversation_lead_tags'=>['conversation_id','tag_id'],
        'lead_stage_history'=>['id','conversation_id','from_stage_key','to_stage_key','changed_by_manager_id','created_at'],
        'lead_tasks'=>['id','conversation_id','title','due_at_utc','status','is_pinned','assigned_manager_id','created_by_manager_id','completed_at_utc','reminder_attempted_at_utc','reminder_notified_at_utc','created_at','updated_at'],
        'lead_close_reasons'=>['reason_key','display_name','sort_order','is_active'],
    ];
    $missing=[];
    foreach($expected as$table=>$columns){
        if(!tableExists($pdo,$table)){$missing[$table]=['__table__'];continue;}
        $q=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);$actual=array_flip(array_map('strtolower',$q->fetchAll(PDO::FETCH_COLUMN)));
        foreach($columns as$column)if(!isset($actual[strtolower($column)]))$missing[$table][]=$column;
    }
    $ids=array_map('intval',array_column(rows($pdo,'SELECT id FROM conversations WHERE is_test=0 ORDER BY id DESC LIMIT 5'),'id'));
    $components=['pipeline'=>static fn(int$id)=>SalesPipelineService::conversationSnapshot($id),'tasks'=>static fn(int$id)=>LeadTaskService::listForConversation($id),'delivery_failure'=>static fn(int$id)=>ManagerDeliveryStateService::activeFailure($id)];
    $probes=[];
    foreach($components as$name=>$load){$failures=[];foreach($ids as$id){try{$load($id);}catch(Throwable $e){$failure=managerLeadDetailFailure($e);$key=json_encode($failure,JSON_UNESCAPED_SLASHES);$failures[$key]=($failures[$key]??0)+1;}}$probes[$name]=['ok'=>!$failures,'attempts'=>count($ids),'failures'=>array_map(static function($key,$count){return['count'=>$count]+(json_decode($key,true)?:[]);},array_keys($failures),array_values($failures))];}
    $ok=!$missing;foreach($probes as$probe)if(!$probe['ok'])$ok=false;
    return['ok'=>$ok,'sample_size'=>count($ids),'schema'=>['ok'=>!$missing,'missing'=>$missing],'components'=>$probes];
}

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
        'manager_response_health'=>[],
        'manager_push_health'=>[],
        'manager_lead_detail_health'=>[],
        'handoff_integrity_health'=>[],
        'admin_project_access_health'=>['ok'=>false,'error'=>'schema_missing'],
        'health'=>[
            'manager_visibility_ok'=>true,
            'manager_visibility_anomalies'=>[],
            'manager_response_ok'=>true,
            'manager_push_ok'=>true,
            'manager_lead_detail_ok'=>true,
            'handoff_integrity_ok'=>true,
            'admin_project_access_ok'=>false,
            'website_attribution_ok'=>true,
            'website_attribution_anomalies'=>[],
        ],
        'projects'=>[],
        'sources'=>[],
        'website_attribution'=>[],
        'recent_entry_attribution'=>[],
        'recent_manager_priority_events'=>[],
        'recent_manager_push_events'=>[],
        'conversation_status'=>[],
        'recent_admin_audit'=>[],
        'recent_messages'=>[],
        'recent_events'=>[],
        'recent_manager_delivery_failures'=>[],
    ];

    foreach(['customers','customer_channels','conversations','messages','managers','manager_assignments','conversation_events','admin_audit_log']as$table){
        if(tableExists($pdo,$table))$snapshot['stats'][$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
    }
    if(tableExists($pdo,'managers'))$snapshot['managers']=rows($pdo,'SELECT id,login,display_name,role,is_active,is_working,last_login_at FROM managers ORDER BY id');
    if(tableExists($pdo,'manager_assignments'))$snapshot['manager_usage']=rows($pdo,"SELECT m.id AS manager_id,m.login,COUNT(a.id) AS assignments_total,SUM(CASE WHEN a.id IS NOT NULL AND a.released_at IS NULL THEN 1 ELSE 0 END) AS assignments_open FROM managers m LEFT JOIN manager_assignments a ON a.manager_id=m.id GROUP BY m.id,m.login ORDER BY m.id");
    if(tableExists($pdo,'projects'))$snapshot['projects']=rows($pdo,'SELECT id,project_key,display_name,is_active FROM projects ORDER BY id');
    if(tableExists($pdo,'managers')&&tableExists($pdo,'projects')&&tableExists($pdo,'manager_projects')){
        $adminProjectAccessHealth=AdminProjectAccessHealth::collect($pdo);
        $snapshot['admin_project_access_health']=$adminProjectAccessHealth;
        $snapshot['health']['admin_project_access_ok']=$adminProjectAccessHealth['ok'];
    }
    if(tableExists($pdo,'conversation_sources')&&tableExists($pdo,'projects'))$snapshot['sources']=rows($pdo,'SELECT s.id,p.project_key,s.source_key,s.display_name,s.channel,s.is_active,s.primary_group_id,s.fallback_mode,s.fallback_group_id,s.fallback_after_minutes FROM conversation_sources s JOIN projects p ON p.id=s.project_id ORDER BY p.project_key,s.id');
    if(tableExists($pdo,'conversations')){
        $snapshot['conversation_status']=rows($pdo,'SELECT project_key,channel,status,COUNT(*) AS count FROM conversations GROUP BY project_key,channel,status ORDER BY project_key,channel,status');
        $snapshot['recent_entry_attribution']=rows($pdo,"SELECT id AS conversation_id,project_key,channel,source_id,entry_channel,attribution_region,attribution_campaign,status,manager_id,started_at,last_message_at FROM conversations WHERE entry_channel IS NOT NULL AND entry_channel<>'' ORDER BY id DESC LIMIT 50");
    }
    if(tableExists($pdo,'admin_audit_log'))$snapshot['recent_admin_audit']=rows($pdo,'SELECT id,actor_manager_id,action,entity_type,entity_id,project_key,created_at FROM admin_audit_log ORDER BY id DESC LIMIT 80');

    $snapshot['recent_manager_priority_events']=recentStructuredEvents($baseDir.'/structured_events.log','manager_priority','push_selected',30);
    $snapshot['recent_manager_push_events']=recentStructuredComponentEvents($baseDir.'/structured_events.log','manager_push',50);

    if(tableExists($pdo,'conversations')&&tableExists($pdo,'conversation_sources')&&tableExists($pdo,'projects')){
        $websiteRows=rows($pdo,"SELECT c.id AS conversation_id,c.project_key,c.source_id,c.status,c.manager_id,c.started_at,c.last_message_at,s.source_key,p.project_key AS source_project_key,s.channel AS source_channel FROM conversations c LEFT JOIN conversation_sources s ON s.id=c.source_id LEFT JOIN projects p ON p.id=s.project_id WHERE c.channel='website' ORDER BY c.id DESC LIMIT 50");
        $snapshot['website_attribution']=$websiteRows;
        $websiteHealth=WebsiteAttributionHealth::evaluate($websiteRows);
        $snapshot['health']['website_attribution_ok']=$websiteHealth['ok'];
        $snapshot['health']['website_attribution_anomalies']=$websiteHealth['anomalies'];
    }

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

    if(tableExists($pdo,'conversations')&&tableExists($pdo,'conversation_events')&&tableExists($pdo,'messages')){
        $managerResponseHealth=ManagerResponseHealth::collect($pdo);
        $snapshot['manager_response_health']=$managerResponseHealth;
        $snapshot['health']['manager_response_ok']=$managerResponseHealth['ok'];
    }

    if(tableExists($pdo,'conversations')&&tableExists($pdo,'conversation_events')&&tableExists($pdo,'manager_assignments')){
        $handoffIntegrity=HandoffIntegrityHealth::collect($pdo);
        $snapshot['handoff_integrity_health']=$handoffIntegrity;
        $snapshot['health']['handoff_integrity_ok']=$handoffIntegrity['ok'];
    }

    if(tableExists($pdo,'managers')){
        $managerPushHealth=ManagerPushHealth::collect($pdo);
        $snapshot['manager_push_health']=$managerPushHealth;
        $snapshot['health']['manager_push_ok']=$managerPushHealth['ok'];
    }

    if(tableExists($pdo,'conversations')){
        $managerLeadDetail=managerLeadDetailHealth($pdo);
        $snapshot['manager_lead_detail_health']=$managerLeadDetail;
        $snapshot['health']['manager_lead_detail_ok']=$managerLeadDetail['ok'];
    }

    if(tableExists($pdo,'messages')){
        $messages=rows($pdo,'SELECT id,conversation_id,direction,sender_type,channel,text,created_at FROM messages ORDER BY id DESC LIMIT 60');
        foreach($messages as&$m)$m['text']=compactText((string)$m['text']);unset($m);$snapshot['recent_messages']=$messages;
    }
    if(tableExists($pdo,'conversation_events')){
        $snapshot['recent_events']=rows($pdo,'SELECT id,conversation_id,event_type,actor_type,actor_id,created_at FROM conversation_events ORDER BY id DESC LIMIT 60');
        $failures=rows($pdo,"SELECT id,conversation_id,actor_id,payload_json,created_at FROM conversation_events WHERE event_type='manager_message_failed' ORDER BY id DESC LIMIT 30");
        foreach($failures as &$failure){
            $payload=json_decode((string)($failure['payload_json']??''),true);
            $failure['payload']=is_array($payload)?$payload:[];
            unset($failure['payload_json']);
        }
        unset($failure);
        $snapshot['recent_manager_delivery_failures']=$failures;
    }

    $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
    if($json===false)throw new RuntimeException('production_snapshot_json_encode_failed: '.json_last_error_msg());
    echo $json."\n";
}catch(Throwable $e){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");exit(1);
}
