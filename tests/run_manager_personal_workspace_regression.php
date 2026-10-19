<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerPersonalWorkspaceService.php';

/** Real SQL and services; only MySQL's SECOND scalar spelling is adapted. */
final class PersonalWorkspacePdo extends PDO
{
    public int $queries=0;
    public int $lists=0;
    public int $annotations=0;
    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('NOW',static fn():string=>'2026-10-14 12:00:00');
        $this->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2026-10-14 12:00:00');
        $this->sqliteCreateFunction('TIMESTAMPDIFF',static fn($unit,$from,$to)=>$from===null?null:strtotime((string)$to)-strtotime((string)$from));
        $this->sqliteCreateFunction('GREATEST',function($a,$b){$this->annotations++;return max((int)$a,(int)$b);});
        $this->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->queries++;
        if (str_contains($query,' AS unread_count ')) $this->lists++;
        return parent::prepare(str_replace('TIMESTAMPDIFF(SECOND,',"TIMESTAMPDIFF('SECOND',",$query),$options);
    }
}

$pdo=new PersonalWorkspacePdo();
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("CREATE TABLE manager_personal_workspace_states(manager_id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,state_json TEXT NOT NULL,updated_at TEXT);
CREATE TABLE manager_personal_workspace_operations(manager_id INTEGER,operation_id TEXT,request_hash TEXT,result_json TEXT,created_at TEXT,PRIMARY KEY(manager_id,operation_id));
CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT UNIQUE,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,is_test INTEGER,external_chat_id TEXT,lead_outcome TEXT DEFAULT 'open',lead_close_reason TEXT,lead_outcome_note TEXT,lead_sale_amount TEXT,lead_sale_date TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER,display_name TEXT,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,created_at TEXT,payload_json TEXT,actor_type TEXT);
CREATE INDEX event_conversation ON conversation_events(conversation_id,id);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,metadata_json TEXT,created_at TEXT);
CREATE INDEX message_conversation ON messages(conversation_id,id);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,PRIMARY KEY(manager_id,conversation_id));
CREATE TABLE lead_stages(stage_key TEXT PRIMARY KEY,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER,is_terminal INTEGER,is_won INTEGER);
CREATE TABLE lead_tags(id INTEGER PRIMARY KEY,tag_key TEXT,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER);
CREATE TABLE conversation_lead_tags(conversation_id INTEGER,tag_id INTEGER,PRIMARY KEY(conversation_id,tag_id));
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY,conversation_id INTEGER,created_at TEXT);
CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY,conversation_id INTEGER,title TEXT,due_at_utc TEXT,is_pinned INTEGER,status TEXT);
CREATE TABLE lead_close_reasons(reason_key TEXT,display_name TEXT,sort_order INTEGER,is_active INTEGER);
INSERT INTO projects VALUES(1,'anytour','Synthetic AnyTour',1),(2,'other','Synthetic Other',1);
INSERT INTO manager_projects VALUES(7,1),(8,1),(90,1),(9,2);
INSERT INTO managers VALUES(7,'one','First','manager','',1,1),(8,'two','Second','manager','',1,1),(90,'admin','Admin','admin','',1,1),(9,'other','Other','manager','',1,1),(10,'disabled','Disabled','admin','',0,1);
INSERT INTO customers VALUES(1,'Synthetic client','123','');
INSERT INTO conversation_sources VALUES(1,1,'Allowed',0,'none',0,0,1),(2,1,'Restricted',101,'none',0,0,1),(3,2,'Other',0,'none',0,0,1),(4,1,'Second allowed',0,'none',0,0,1),(5,1,'Inactive',0,'none',0,0,0);");
$insert=$pdo->prepare('INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,lead_stage_key,manager_id,started_at,last_message_at,is_test) VALUES(?,1,?,?,?,? ,\'new\',?,?,?,0)');
$message=$pdo->prepare("INSERT INTO messages VALUES(?,?,'inbound','customer',?,'{}',?)");
$pdo->beginTransaction();
for ($id=1;$id<=1001;$id++) {
    $at=gmdate('Y-m-d H:i:s',strtotime('2026-10-13 00:00:00')+$id);
    $insert->execute([$id,'anytour',1,$id%2===0?'telegram':'max','manager',7,$at,$at]);
    $message->execute([$id,$id,'Synthetic message '.$id,$at]);
}
$insert->execute([2001,'other',3,'max','manager',9,'2026-10-14 00:00:00','2026-10-14 00:00:00']);
$insert->execute([2002,'anytour',2,'max','manager',7,'2026-10-14 00:00:00','2026-10-14 00:00:00']);
$insert->execute([2003,'anytour',1,'max','closed',7,'2026-10-14 00:00:00','2026-10-14 00:00:00']);
$insert->execute([2004,'anytour',1,'max','waiting_manager',null,'2026-10-14 00:00:00','2026-10-14 00:00:00']);
$insert->execute([2005,'anytour',4,'telegram','manager',7,'2026-10-12 00:00:00','2026-10-12 00:00:00']);
$insert->execute([2006,'anytour',4,'website','closed',8,'2026-10-12 00:00:00','2026-10-12 00:00:00']);
$insert->execute([2007,'anytour',1,'max','manager',8,'2026-10-12 00:00:00','2026-10-12 00:00:00']);
$insert->execute([2008,'anytour',4,'telegram','waiting_manager',null,'2026-10-12 00:00:00','2026-10-12 00:00:00']);
$insert->execute([2009,'anytour',1,'max','manager',90,'2026-10-12 00:00:00','2026-10-12 00:00:00']);
$pdo->commit();
$passed=0;
function pwCheck(string $label,bool $ok): void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function pwState(int $actor=7): array {return ManagerPersonalWorkspaceService::snapshot($actor);}
function pwMut(string $action,array $input=[],int $actor=7): array {static $seq=0;$input+=['expected_version'=>pwState($actor)['version']??0,'operation_id'=>'synthetic-workspace-'.str_pad((string)++$seq,6,'0',STR_PAD_LEFT)];return ManagerPersonalWorkspaceService::mutate($actor,$action,$input);}
function pwBusiness(): array {global $pdo;$out=[];foreach (['managers','conversations','messages','conversation_events','manager_conversation_reads'] as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $out;}
$pdo->exec("INSERT INTO lead_tasks VALUES(1,1,'Synthetic followup','2026-10-15 12:00:00',1,'open')");
$before=pwBusiness();
$empty=pwState();
pwCheck('empty account read has defaults and no write',$empty['ok']&&$empty['version']===0&&$empty['state']['tabs']===[]&&$empty['state']['pins']===[]&&json_encode($empty['state']['members'])==='{}'&&$empty['state']['settings']===['start_tab'=>'waiting','density'=>'comfortable','font'=>'normal','ai_ready_notice'=>'show']&&(int)$pdo->query('SELECT COUNT(*) FROM manager_personal_workspace_states')->fetchColumn()===0);
$legacyState=['tabs'=>[],'labels'=>[],'members'=>[],'pins'=>[],'settings'=>['start_tab'=>'waiting','density'=>'comfortable','font'=>'normal'],'next_tab_id'=>1,'next_label_id'=>1];
$legacyJson=json_encode($legacyState);$legacyInsert=$pdo->prepare('INSERT INTO manager_personal_workspace_states VALUES(8,0,?,?)');$legacyInsert->execute([$legacyJson,'2026-10-19 10:00:00']);
pwCheck('old account rows receive readiness default without a write',pwState(8)['state']['settings']['ai_ready_notice']==='show'&&$pdo->query('SELECT state_json FROM manager_personal_workspace_states WHERE manager_id=8')->fetchColumn()===$legacyJson&&pwState(8)['version']===0);
$noticeHidden=pwMut('settings',['settings'=>['ai_ready_notice'=>'hide']],8);
pwCheck('AI notice preference belongs only to its account and survives fresh reads',$noticeHidden['ok']&&pwState(8)['state']['settings']['ai_ready_notice']==='hide'&&pwState(7)['state']['settings']['ai_ready_notice']==='show');
foreach ([true,1,'unknown'] as $invalidNotice) pwCheck('readiness preference rejects invalid type or value',pwMut('settings',['settings'=>['ai_ready_notice'=>$invalidNotice]],8)['error']==='invalid_settings');
pwMut('settings',['settings'=>['ai_ready_notice'=>'show']],8);
pwCheck('inactive, missing and zero actors fail before preference access',pwState(10)['error']==='unauthorized'&&pwState(0)['error']==='unauthorized'&&pwState(999)['error']==='unauthorized');
$label=pwMut('label_save',['name'=>'Любимые клиенты']);$labelId=$label['id'];
$tabInput=['name'=>'Моя подборка','queue'=>'all','channel'=>'max','source_id'=>1,'reply_needed'=>false,'label_id'=>$labelId,'pinned_only'=>false];
$stable=['id'=>0,'tab'=>$tabInput,'expected_version'=>$label['version'],'operation_id'=>'stable-tab-create-00001'];
$tab=pwMut('tab_save',$stable);$tabId=$tab['id'];
pwCheck('create label and named tab advances version and preserves exact filters',$tab['ok']&&$tab['version']===2&&$tab['state']['tabs'][0]===['id'=>$tabId]+$tabInput);
pwCheck('command retry after lost result allocates no duplicate',pwMut('tab_save',$stable)==$tab&&count(pwState()['state']['tabs'])===1);
$setting=pwMut('settings',['settings'=>['start_tab'=>'tab:'.$tabId,'density'=>'compact','font'=>'large']]);
$replayed=pwMut('tab_save',$stable);$recovered=ManagerPersonalWorkspaceService::operation(7,$stable['operation_id']);
pwCheck('old receipt and recovery include latest snapshot never overwrite newer preferences',$replayed['id']===$tabId&&$replayed['version']===$setting['version']&&$replayed['state']==$setting['state']&&$recovered['found']&&$recovered['result']['version']===$setting['version']&&$recovered['result']['state']==$setting['state']);
$changed=$stable;$changed['tab']['name']='Changed';
pwCheck('changed operation key fails409 semantics with current state',pwMut('tab_save',$changed)['error']==='operation_mismatch');
$shuffled=$stable;$shuffled['tab']=array_reverse($shuffled['tab'],true);
pwCheck('JSON object key ordering does not change semantic command identity',pwMut('tab_save',$shuffled)['ok']);
pwCheck('private state and receipt are isolated even for admin',pwState(90)['state']['tabs']===[]&&!ManagerPersonalWorkspaceService::operation(90,$stable['operation_id'])['found']&&pwState(8)['state']['labels']===[]);
$stale=pwMut('settings',['settings'=>['density'=>'comfortable'],'expected_version'=>0]);
pwCheck('concurrent stale update returns current snapshot and retains settings',$stale['error']==='conflict'&&$stale['version']===$setting['version']&&pwState()['state']['settings']['density']==='compact');
$v=pwState()['version'];$same=pwMut('settings',['settings'=>['density'=>'compact']]);
pwCheck('exact no-op does not advance revision',$same['version']===$v);
foreach ([['tab_save',['tab'=>['name'=>'Bad','queue'=>'deals']]],['tab_save',['tab'=>'not-array']],['tab_save',['tab'=>[]]],['label_save',['name'=>' ']],['label_save',['name'=>str_repeat('я',65)]],['label_save',['name'=>"a\0b"]],['settings',['settings'=>['font'=>'tiny']]],['settings',['settings'=>['start_tab'=>'tab:999']]],['settings',['settings'=>['manager_id'=>8]]],['pin',['conversation_id'=>'1','enabled'=>true]],['pin',['conversation_id'=>1,'enabled'=>1]] ] as [$action,$input]) pwCheck('malformed presentation input rejected without changing version',!pwMut($action,$input)['ok']&&pwState()['version']===$v);
pwCheck('canonical operation/version validation rejects malformed command',ManagerPersonalWorkspaceService::mutate(7,'settings',['operation_id'=>'short','expected_version'=>0])['error']==='invalid_operation_id'&&ManagerPersonalWorkspaceService::mutate(7,'settings',['operation_id'=>'valid-operation-key-0001','expected_version'=>'0'])['error']==='invalid_version');
$bad=pwMut('tab_save',['tab'=>['name'=>'Other source','source_id'=>3]]);
pwCheck('source selector requires current project access',$bad['error']==='forbidden');
$member=pwMut('label_membership',['conversation_id'=>1,'label_id'=>$labelId,'enabled'=>true]);
$second=pwMut('label_save',['name'=>'Вторая метка']);$secondId=$second['id'];
pwMut('label_membership',['conversation_id'=>1,'label_id'=>$secondId,'enabled'=>true]);
pwCheck('one client can belong to several account-owned labels',((array)pwState()['state']['members'])[$labelId]===[1]&&((array)pwState()['state']['members'])[$secondId]===[1]);
$pin=pwMut('pin',['conversation_id'=>1,'enabled'=>true]);$v=$pin['version'];
pwCheck('pin repeat is no-op and belongs only to account',pwMut('pin',['conversation_id'=>1,'enabled'=>true])['version']===$v&&pwState(8)['state']['pins']===[]);
pwCheck('fresh singleton authorization rejects restricted or other-project memberships',pwMut('pin',['conversation_id'=>2001,'enabled'=>true])['error']==='forbidden'&&pwMut('label_membership',['conversation_id'=>2002,'label_id'=>$labelId,'enabled'=>true])['error']==='forbidden');
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');
pwCheck('revoked project cannot add or remove markers even from saved state',pwMut('pin',['conversation_id'=>1,'enabled'=>false])['error']==='forbidden'&&pwMut('label_membership',['conversation_id'=>1,'label_id'=>$labelId,'enabled'=>false])['error']==='forbidden');
$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
$pdo->lists=0;$pdo->annotations=0;
$selected=ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId]);
pwCheck('label tab selects rare older matching client before newest200 cap',array_column($selected['conversations'],'id')===[1]&&$selected['bounded_count']===1&&!$selected['limited']&&$pdo->lists===1&&$pdo->annotations===1);
pwCheck('selected row preserves canonical contact/task/origin/edit projections',$selected['conversations'][0]['contact_phone']==='123'&&$selected['conversations'][0]['open_task_count']===1&&$selected['conversations'][0]['next_task_title']==='Synthetic followup'&&$selected['conversations'][0]['next_task_pinned']&&$selected['conversations'][0]['origin_label']==='MAX · Allowed'&&$selected['conversations'][0]['can_edit_pipeline']);
$pinned=ManagerPersonalWorkspaceService::list(7,['selection'=>'pinned']);
pwCheck('personal pinned queue reuses canonical access and shows only own IDs',array_column($pinned['conversations'],'id')===[1]);
pwCheck('conflicting transient source/channel filters intersect stored filter',ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId,'channel'=>'telegram'])['conversations']===[]&&ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId,'source_id'=>2])['conversations']===[]);
pwCheck('unknown source transient filter never broadens positive source tab',ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId,'source_id'=>-1])['conversations']===[]);
pwCheck('search is literal and only selected private collection',ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId,'search'=>'%'])['conversations']===[]&&count(ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId,'search'=>'Synthetic message 1'])['conversations'])===1);
pwCheck('unknown tab cannot become all queue',ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:999'])['error']==='not_found');
$tab2=pwMut('tab_save',['tab'=>['name'=>'Новая подборка','queue'=>'all']]);$tab2Id=$tab2['id'];
$limited=ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tab2Id]);
pwCheck('over200 selected collection reports bounded not total count',$limited['bounded_count']<=200&&$limited['limited']&&$limited['has_more']&&$limited['count_scope']==='loaded_visible'&&!isset($limited['candidate_count']));
pwCheck('saved tab retains unread projection and newest canonical order',(int)$limited['conversations'][0]['id']===2004&&(int)$limited['conversations'][1]['id']===1001&&(int)$limited['conversations'][1]['unread_count']===1);
$pdo->exec('UPDATE conversations SET source_id=2 WHERE id>=900 AND id<=1001');
$restricted=ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tab2Id]);
pwCheck('source access filtered after cap does not falsely claim full collection',count($restricted['conversations'])<200&&$restricted['limited']&&$restricted['count_scope']==='loaded_visible'&&!array_filter($restricted['conversations'],static fn(array $row):bool=>$row['source_id']===2));
$pdo->exec('UPDATE conversations SET source_id=1 WHERE id>=900 AND id<=1001');
$pdo->queries=0;$pdo->lists=0;ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId]);$oneQueries=$pdo->queries;
$pdo->queries=0;$pdo->lists=0;ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tab2Id,'source_id'=>1]);$manyQueries=$pdo->queries;
pwCheck('200row personal selection adds no per-row reads or each-tab list',$pdo->lists===1&&$manyQueries===$oneQueries);
echo "EVIDENCE synthetic selected_list queries_one={$oneQueries} queries_200={$manyQueries} one_list_request=true; real browser/server timing not measured\n";
$reply=pwMut('tab_save',['tab'=>['name'=>'Нужен ответ','queue'=>'all','reply_needed'=>true]]);
$replyRows=ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$reply['id']]);
pwCheck('reply filter uses latest real customer/manager predicate',!empty($replyRows['conversations'])&&!array_filter($replyRows['conversations'],static fn(array $row):bool=>!in_array($row['status'],['manager','waiting_manager'],true)));
$reorder=pwMut('tab_reorder',['ids'=>[$reply['id'],$tab2Id,$tabId]]);
pwCheck('reorder persists complete tab order',array_column($reorder['state']['tabs'],'id')===[$reply['id'],$tab2Id,$tabId]);
pwCheck('partial or duplicate reorder cannot lose tabs',pwMut('tab_reorder',['ids'=>[$tabId]])['error']==='conflict'&&pwMut('tab_reorder',['ids'=>[$tabId,$tabId]])['error']==='invalid_ids');
pwMut('label_delete',['id'=>$labelId]);
pwCheck('label deletion removes markers but never broadens dependent selection',!isset(((array)pwState()['state']['members'])[$labelId])&&pwState()['state']['tabs'][2]['label_id']===$labelId&&ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$tabId])['conversations']===[]&&(int)$pdo->query('SELECT COUNT(*) FROM conversations WHERE id=1')->fetchColumn()===1);
pwMut('tab_delete',['id'=>$tabId]);
pwCheck('deleting startup tab returns safe system default',pwState()['state']['settings']['start_tab']==='waiting');
$next=pwMut('tab_save',['tab'=>['name'=>'After delete']]);
pwCheck('deleted IDs never reused under account lock',$next['id']>$reply['id']);

// Multi-choice conditions are one bounded SQL selection: OR within each field,
// AND between fields and the external inbox filters. Legacy tabs stay scalar.
$third=pwMut('label_save',['name'=>'Несколько условий']);$thirdId=$third['id'];
foreach ([2,2003,2005,2006] as $conversationId) pwMut('label_membership',['conversation_id'=>$conversationId,'label_id'=>$thirdId,'enabled'=>true]);
$multiInput=['name'=>'MAX и Telegram, мои и закрытые','queues'=>['mine','closed'],'channels'=>['max','telegram'],'source_ids'=>[1,4],'label_ids'=>[$secondId,$thirdId],'reply_needed'=>false,'pinned_only'=>false];
$multi=pwMut('tab_save',['tab'=>$multiInput]);$multiId=$multi['id'];
$multiSaved=array_values(array_filter($multi['state']['tabs'],static fn(array $item):bool=>$item['id']===$multiId))[0];
pwCheck('plural selections roundtrip with compatibility aliases and stable order',$multi['ok']&&$multiSaved['queues']===['mine','closed']&&$multiSaved['channels']===['max','telegram']&&$multiSaved['source_ids']===[1,4]&&$multiSaved['label_ids']===[$secondId,$thirdId]&&$multiSaved['queue']==='all'&&$multiSaved['channel']===''&&$multiSaved['source_id']===0&&$multiSaved['label_id']===0);
$pdo->lists=0;$pdo->annotations=0;$multiRows=ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId]);
pwCheck('multi queue channel source and label unions intersect before one candidate cap',array_column($multiRows['conversations'],'id')===[2003,2,1,2005]&&$pdo->lists===1&&$pdo->annotations===4&&!$multiRows['limited']);
pwCheck('external channel and source intersect each saved multi-selection',array_column(ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId,'channel'=>'telegram','source_id'=>4])['conversations'],'id')===[2005]&&ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId,'channel'=>'website'])['conversations']===[]&&ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId,'source_id'=>-1])['conversations']===[]);
$multiInput['pinned_only']=true;pwMut('tab_save',['id'=>$multiId,'tab'=>$multiInput]);
pwCheck('pinned condition intersects the union of selected labels',array_column(ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId])['conversations'],'id')===[1]);
$multiInput['pinned_only']=false;pwMut('tab_save',['id'=>$multiId,'tab'=>$multiInput]);
$missingOnly=pwMut('tab_save',['tab'=>array_replace($multiInput,['name'=>'Только удалённая метка','label_ids'=>[$thirdId]])]);
pwMut('label_delete',['id'=>$thirdId]);
pwCheck('deleted selected label stays empty while surviving selected label still matches',array_column(ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId])['conversations'],'id')===[1]);
pwCheck('all deleted selected label branches remain empty rather than any',ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$missingOnly['id']])['conversations']===[]);
pwMut('tab_delete',['id'=>$missingOnly['id']]);
pwCheck('saving missing label in a multi-selection is rejected without broadening',pwMut('tab_save',['id'=>$multiId,'tab'=>$multiInput])['error']==='not_found');
$multiInput['label_ids']=[];pwMut('tab_save',['id'=>$multiId,'tab'=>$multiInput]);
$pdo->lists=0;$union=ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId]);
pwCheck('large queue union is one newest200 page with canonical ownership',$pdo->lists===1&&$union['limited']&&count($union['conversations'])<=200&&(int)$union['conversations'][0]['id']===2003&&!in_array(2007,array_column($union['conversations'],'id'),true));
$overlap=ManagerConversationService::list(7,'all',200,'*','','',0,['queues'=>['mine','all']]);
pwCheck('overlapping mine and all selectors never duplicate rows',count($overlap)===count(array_unique(array_column($overlap,'id'),SORT_REGULAR)));
$multiInput['queues']=['waiting','closed'];$multiInput['channels']=[];$multiInput['source_ids']=[];pwMut('tab_save',['id'=>$multiId,'tab'=>$multiInput]);
$waitingClosedIds=array_column(ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$multiId])['conversations'],'id');sort($waitingClosedIds);
pwCheck('empty optional selectors mean any and waiting plus closed includes both canonical projections',$waitingClosedIds===[2003,2004,2006,2008]);
$aliases=pwMut('tab_save',['id'=>$multiId,'tab'=>array_merge($multiInput,['queue'=>'mine','channel'=>'website','source_id'=>3,'label_id'=>999])]);
$aliasTab=array_values(array_filter($aliases['state']['tabs'],static fn(array $item):bool=>$item['id']===$multiId))[0];
pwCheck('authoritative plural empty selectors clear stale legacy aliases without narrowing or broadening queues',$aliases['ok']&&$aliasTab['queues']===['waiting','closed']&&$aliasTab['queue']==='all'&&$aliasTab['channel']===''&&$aliasTab['source_id']===0&&$aliasTab['label_id']===0);
pwCheck('admin union still cannot broaden Mine ownership',!in_array(2007,array_column(ManagerConversationService::list(90,'all',200,'*','','',0,['queues'=>['mine','waiting']]),'id'),true));
pwCheck('saved Mine intersects admin external manager filter while ordinary Mine remains compatible',array_column(ManagerConversationService::list(90,'all',200,'*','','',0,['queues'=>['mine']]),'id')===[2009]&&ManagerConversationService::list(90,'all',200,'*','7','',0,['queues'=>['mine']])===[]&&ManagerConversationService::list(90,'all',200,'*','unassigned','',0,['queues'=>['mine']])===[]&&array_column(ManagerConversationService::list(90,'mine',200,'*','7'),'id')===[2009]);
$pdo->exec("INSERT INTO conversation_events VALUES(30001,1,'waiting_manager','2026-10-11 00:00:00','{}','system'),(30002,2007,'waiting_manager','2026-10-11 00:00:00','{}','system'),(30003,2004,'waiting_manager','2026-10-11 00:00:00','{}','system')");
$requestedIds=array_column(ManagerConversationService::list(7,'requested',200,'*'),'id');sort($requestedIds);
pwCheck('canonical requested queue keeps own and unassigned and excludes another manager after predicate extraction',$requestedIds===[1,2004]);
$attentionIds=array_column(ManagerConversationService::list(7,'attention',200,'*'),'id');$adminAttentionIds=array_column(ManagerConversationService::list(90,'attention',200,'*'),'id');
pwCheck('canonical urgent queue preserves nonadmin ownership and admin oversight',in_array(1,$attentionIds,true)&&!in_array(2007,$attentionIds,true)&&in_array(2007,$adminAttentionIds,true));
$pdo->exec('DELETE FROM conversation_events WHERE id IN (30001,30002,30003)');
$badMulti=[['queues'=>[]],['queues'=>['mine','mine']],['queues'=>['mine',1]],['queues'=>['attention']],['channels'=>['max','max']],['channels'=>['max','']],['channels'=>'max'],['source_ids'=>['1']],['source_ids'=>[1,1]],['source_ids'=>[0]],['source_ids'=>range(1,101)],['label_ids'=>[$secondId,$secondId]],['label_ids'=>[true]],['label_ids'=>range(1,31)],['channels'=>['x'=>'max']]];
$v=pwState()['version'];
foreach ($badMulti as $invalid) pwCheck('invalid multi-choice type duplicate bound or value rejected atomically',pwMut('tab_save',['id'=>$multiId,'tab'=>array_replace($multiInput,$invalid)])['error']==='invalid_tab'&&pwState()['version']===$v);
pwCheck('every selected source needs active current project access',pwMut('tab_save',['id'=>$multiId,'tab'=>array_replace($multiInput,['source_ids'=>[1,3]])])['error']==='forbidden'&&pwMut('tab_save',['id'=>$multiId,'tab'=>array_replace($multiInput,['source_ids'=>[1,5]])])['error']==='forbidden');
pwMut('tab_delete',['id'=>$multiId]);

// Manual groups are explicit account preferences, never inferred from an old
// label-filter tab. One operation owns their row, marker and receipt.
$prior=pwState();$groupCommand=['name'=>'Подбор','expected_version'=>$prior['version'],'operation_id'=>'manual-group-create-once-0001'];
$group=pwMut('group_create',$groupCommand);$groupId=$group['id'];$groupLabel=$group['label_id'];
$groupTab=array_values(array_filter($group['state']['tabs'],static fn(array $row):bool=>$row['id']===$groupId))[0];
pwCheck('manual group creates one bound label and explicit sticky tab in one revision',$group['ok']&&$group['version']===$prior['version']+1&&count($group['state']['tabs'])===count($prior['state']['tabs'])+1&&count($group['state']['labels'])===count($prior['state']['labels'])+1&&$groupTab['kind']==='group'&&$groupTab['group_label_id']===$groupLabel&&$groupTab['label_ids']===[$groupLabel]&&$groupTab['queues']===['all','closed']&&!$groupTab['reply_needed']&&!$groupTab['pinned_only']);
pwCheck('lost group-create result retries exactly once and remains account-private',pwMut('group_create',$groupCommand)==$group&&pwState(8)['state']['tabs']===[]&&!ManagerPersonalWorkspaceService::operation(8,$groupCommand['operation_id'])['found']);
pwCheck('group-create operation key cannot be reused for another group',pwMut('group_create',array_replace($groupCommand,['name'=>'Другая группа']))['error']==='operation_mismatch');
pwCheck('legacy label-filter tab stays distinct from explicit group',!isset($prior['state']['tabs'][0]['kind'])&&pwMut('group_membership',['id'=>$prior['state']['tabs'][0]['id'],'conversation_id'=>1,'enabled'=>true])['error']==='not_found');
$groupVersion=pwState()['version'];
foreach ([['tab_save',['id'=>$groupId,'tab'=>['name'=>'Changed binding']]],['tab_delete',['id'=>$groupId]],['label_save',['id'=>$groupLabel,'name'=>'Changed binding']],['label_delete',['id'=>$groupLabel]]] as [$action,$command]) pwCheck('generic editors cannot break atomic group binding',pwMut($action,$command)['error']==='group_action_required'&&pwState()['version']===$groupVersion);
pwCheck('group name validation and namespace duplicates are atomic',pwMut('group_create',['name'=>' '])['error']==='invalid_name'&&pwMut('group_create',['name'=>str_repeat('я',65)])['error']==='invalid_name'&&pwMut('group_create',['name'=>'Подбор'])['error']==='duplicate_name'&&pwMut('group_rename',['id'=>$groupId,'name'=>'Вторая метка'])['error']==='duplicate_name'&&pwState()['version']===$groupVersion);
foreach ([1,2003,2004] as $conversationId) pwMut('group_membership',['id'=>$groupId,'conversation_id'=>$conversationId,'enabled'=>true]);
$groupSelection=['selection'=>'tab:'.$groupId];$pdo->lists=0;
$groupRows=ManagerPersonalWorkspaceService::list(7,$groupSelection);
pwCheck('group reads only selected IDs including closed and unassigned with one bounded query',array_column($groupRows['conversations'],'id')===[2003,2004,1]&&$pdo->lists===1&&!$groupRows['limited']);
$memberVersion=pwState()['version'];
pwCheck('group membership repeat is a receipt-backed no-op',pwMut('group_membership',['id'=>$groupId,'conversation_id'=>1,'enabled'=>true])['version']===$memberVersion);
$pdo->exec("UPDATE messages SET direction='outbound',sender_type='manager' WHERE id=1; INSERT INTO manager_conversation_reads VALUES(7,1,1)");
$afterReply=ManagerPersonalWorkspaceService::list(7,$groupSelection);
pwCheck('reply and read markers do not remove sticky manual membership',in_array(1,array_column($afterReply['conversations'],'id'),true)&&pwState()['version']===$memberVersion&&((array)pwState()['state']['members'])[$groupLabel]===[1,2003,2004]);
$pdo->exec("UPDATE messages SET direction='inbound',sender_type='customer' WHERE id=1; DELETE FROM manager_conversation_reads WHERE manager_id=7 AND conversation_id=1");
pwCheck('group transient filters intersect selected members',array_column(ManagerPersonalWorkspaceService::list(7,$groupSelection+['channel'=>'max','source_id'=>1])['conversations'],'id')===[2003,2004,1]&&ManagerPersonalWorkspaceService::list(7,$groupSelection+['channel'=>'telegram'])['conversations']===[]);
pwCheck('group mutation rechecks current source and project visibility',pwMut('group_membership',['id'=>$groupId,'conversation_id'=>2002,'enabled'=>true])['error']==='forbidden'&&pwMut('group_membership',['id'=>$groupId,'conversation_id'=>2001,'enabled'=>true])['error']==='forbidden');
$pdo->exec('UPDATE conversations SET source_id=2 WHERE id=1');
pwCheck('lost visibility hides group member and rejects even removal',!in_array(1,array_column(ManagerPersonalWorkspaceService::list(7,$groupSelection)['conversations'],'id'),true)&&pwMut('group_membership',['id'=>$groupId,'conversation_id'=>1,'enabled'=>false])['error']==='forbidden'&&in_array(1,((array)pwState()['state']['members'])[$groupLabel],true));
$pdo->exec('UPDATE conversations SET source_id=1 WHERE id=1');
$renamed=pwMut('group_rename',['id'=>$groupId,'name'=>'В работе']);
pwCheck('rename updates only the bound pair preserving membership',array_values(array_filter($renamed['state']['tabs'],static fn(array $row):bool=>$row['id']===$groupId))[0]['name']==='В работе'&&array_values(array_filter($renamed['state']['labels'],static fn(array $row):bool=>$row['id']===$groupLabel))[0]['name']==='В работе'&&((array)$renamed['state']['members'])[$groupLabel]===[1,2003,2004]);
$receipt=ManagerPersonalWorkspaceService::operation(7,$groupCommand['operation_id']);$oldReplay=pwMut('group_create',$groupCommand);
pwCheck('old group receipt recovers latest renamed state with original IDs',$receipt['found']&&$receipt['result']['id']===$groupId&&$receipt['result']['label_id']===$groupLabel&&$receipt['result']['version']===$renamed['version']&&$oldReplay['state']==$renamed['state']);
$conflict=pwMut('group_rename',['id'=>$groupId,'name'=>'Stale rename','expected_version'=>$groupVersion]);
pwCheck('stale group command cannot overwrite newer membership or name',$conflict['error']==='conflict'&&$conflict['state']==$renamed['state']);
$group2=pwMut('group_create',['name'=>'Обдумывают']);$mixedOrder=array_reverse(array_column($group2['state']['tabs'],'id'));
pwCheck('canonical reorder persists mixed groups and legacy tabs',array_column(pwMut('tab_reorder',['ids'=>$mixedOrder])['state']['tabs'],'id')===$mixedOrder);
$dependent=pwMut('tab_save',['tab'=>['name'=>'Legacy group-label reference','label_id'=>$groupLabel]]);
pwMut('settings',['settings'=>['start_tab'=>'tab:'.$groupId]]);
$deletedGroup=pwMut('group_delete',['id'=>$groupId]);
pwCheck('group deletion atomically removes bound marker memberships and startup but preserves tombstones',$deletedGroup['deleted_id']===$groupId&&$deletedGroup['label_id']===$groupLabel&&!in_array($groupId,array_column($deletedGroup['state']['tabs'],'id'),true)&&!in_array($groupLabel,array_column($deletedGroup['state']['labels'],'id'),true)&&!isset(((array)$deletedGroup['state']['members'])[$groupLabel])&&$deletedGroup['state']['settings']['start_tab']==='waiting'&&ManagerPersonalWorkspaceService::list(7,['selection'=>'tab:'.$dependent['id']])['conversations']===[]);
pwCheck('deleted group cannot become another selection or accept membership',ManagerPersonalWorkspaceService::list(7,$groupSelection)['error']==='not_found'&&pwMut('group_membership',['id'=>$groupId,'conversation_id'=>1,'enabled'=>true])['error']==='not_found');
$deletedVersion=pwState()['version'];$deletedReplay=pwMut('group_create',$groupCommand);
pwCheck('late create replay after deletion never recreates the old group',$deletedReplay['ok']&&$deletedReplay['id']===$groupId&&$deletedReplay['version']===$deletedVersion&&!in_array($groupId,array_column($deletedReplay['state']['tabs'],'id'),true));
pwMut('group_delete',['id'=>$group2['id']]);pwMut('tab_delete',['id'=>$dependent['id']]);

// Atomic rollback at receipt write prevents UNKNOWN being mistaken for success.
$pdo->exec("CREATE TRIGGER reject_workspace_receipt BEFORE UPDATE ON manager_personal_workspace_operations WHEN NEW.operation_id='rollback-operation-0001' BEGIN SELECT RAISE(FAIL,'Synthetic receipt failure'); END");
$initial=pwState();$input=['name'=>'Rollback label','expected_version'=>$initial['version'],'operation_id'=>'rollback-operation-0001'];$failed=false;
try {pwMut('label_save',$input);} catch (PDOException $e) {$failed=true;}
pwCheck('receipt failure rolls back preference state and allocation',$failed&&pwState()==$initial&&!ManagerPersonalWorkspaceService::operation(7,$input['operation_id'])['found']);
$groupRollback=['name'=>'Rollback group','expected_version'=>$initial['version'],'operation_id'=>'rollback-operation-0001'];$failed=false;
try {pwMut('group_create',$groupRollback);} catch (PDOException $e) {$failed=true;}
pwCheck('group receipt failure rolls back both allocations revision and UNKNOWN outcome',$failed&&pwState()==$initial&&!ManagerPersonalWorkspaceService::operation(7,$groupRollback['operation_id'])['found']);
$pdo->exec('DROP TRIGGER reject_workspace_receipt');
$retry=pwMut('label_save',$input);
pwCheck('same operation after genuine rollback commits exactly once',$retry['ok']&&pwMut('label_save',$input)['id']===$retry['id']);

// Capacity fixtures use ordinary runtime service for commands; direct synthetic
// seeding is restricted to existing account preference rows, never live data.
for ($i=count(pwState()['state']['tabs']);$i<12;$i++) pwMut('tab_save',['tab'=>['name'=>'Tab bound '.$i]]);
pwCheck('tab12 bound is explicit and persisted',pwMut('tab_save',['tab'=>['name'=>'Too many']])['error']==='tab_limit_exceeded'&&count(pwState()['state']['tabs'])===12);
$capacity=pwState();
pwCheck('group tab capacity leaves no orphan label or allocation',pwMut('group_create',['name'=>'Too many groups'])['error']==='tab_limit_exceeded'&&pwState()==$capacity);
for ($i=count(pwState()['state']['labels']);$i<30;$i++) pwMut('label_save',['name'=>'Label bound '.$i]);
pwCheck('label30 bound is explicit',pwMut('label_save',['name'=>'Too many'])['error']==='label_limit_exceeded');
pwMut('tab_delete',['id'=>pwState()['state']['tabs'][0]['id']]);$capacity=pwState();
pwCheck('group label capacity leaves no orphan tab or allocation',pwMut('group_create',['name'=>'No marker capacity'])['error']==='label_limit_exceeded'&&pwState()==$capacity);
$raw=json_decode((string)$pdo->query('SELECT state_json FROM manager_personal_workspace_states WHERE manager_id=7')->fetchColumn(),true);
$raw['pins']=range(1,200);$raw['members']=[(string)$secondId=>range(1,2000)];
$seed=$pdo->prepare('UPDATE manager_personal_workspace_states SET state_json=? WHERE manager_id=7');$seed->execute([json_encode($raw,JSON_THROW_ON_ERROR)]);
pwCheck('pin200 and membership2000 limits reject append not silently truncate',pwMut('pin',['conversation_id'=>201,'enabled'=>true])['error']==='pin_limit_exceeded'&&pwMut('label_membership',['conversation_id'=>2003,'label_id'=>$secondId,'enabled'=>true])['error']==='membership_limit_exceeded');
pwCheck('existing memberships and pins are safe no-ops at capacity',pwMut('pin',['conversation_id'=>1,'enabled'=>true])['ok']&&pwMut('label_membership',['conversation_id'=>1,'label_id'=>$secondId,'enabled'=>true])['ok']);
$raw['members']=[(string)$secondId=>range(1,2000)];$raw['padding']=str_repeat('x',65500);$seed->execute([json_encode($raw,JSON_THROW_ON_ERROR)]);
pwCheck('oversized state write rejected atomically',pwMut('settings',['settings'=>['density'=>'comfortable']])['error']==='state_limit_exceeded');
unset($raw['padding']);$seed->execute([json_encode($raw,JSON_THROW_ON_ERROR)]);
$pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');
pwCheck('fresh account deactivation blocks reads receipts and writes',pwState()['error']==='unauthorized'&&ManagerPersonalWorkspaceService::operation(7,$stable['operation_id'])['error']==='unauthorized'&&ManagerPersonalWorkspaceService::mutate(7,'settings',[])['error']==='unauthorized');
$pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
pwCheck('complete preference workflow never changes operational state transcript read markers or shifts',pwBusiness()===$before);
$pdo->exec('DROP TABLE manager_personal_workspace_states');$failed=false;
try {pwState();} catch (PDOException $e) {$failed=true;}
pwCheck('missing migration is storage failure never runtime DDL',$failed);

// Exercise the actual endpoint, ManagerHttp, session, CSRF and ManagerAuthService
// through loopback HTTP. Only config/database/session files are synthetic.
$httpRoot=sys_get_temp_dir().'/manager-personal-http-'.bin2hex(random_bytes(8));
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);
$process=null;$httpDb=null;
try {
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $schema) $httpDb->exec($schema);
    $httpDb->exec("CREATE TABLE manager_personal_workspace_states(manager_id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,state_json TEXT NOT NULL,updated_at TEXT);
        INSERT INTO projects VALUES(1,'personal-http-fixture','Synthetic workspace',1);
        INSERT INTO managers VALUES(101,'fixture-first','Synthetic first','manager','',1,0),(102,'fixture-second','Synthetic second','admin','',1,0),(103,'fixture-inactive','Synthetic inactive','manager','',0,0);
        INSERT INTO manager_projects VALUES(101,1),(102,1);");
    $sourceRoot=dirname(__DIR__);
    file_put_contents($httpRoot.'/config.php',str_replace('\\n',"\n",'<?php\nrequire_once '.var_export($sourceRoot.'/services/ManagerRequestContext.php',true).';\n'.
        '$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n'.
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);\n'.
        'ProjectConfig::resetForTests(["id"=>"personal-http-fixture","brand"=>["name"=>"Synthetic workspace"]]);\nProjectAccessService::initializeReadOnly();\n'));
    copy($sourceRoot.'/manager/personal-workspace-api.php',$httpRoot.'/manager/personal-workspace-api.php');
    symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');symlink($sourceRoot.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??101);$_SESSION["csrf"]="synthetic-personal-csrf";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate synthetic loopback fixture');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start synthetic loopback PHP');fclose($pipes[0]);
    $ready=false;for ($wait=0;$wait<200;$wait++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}
    if (!$ready) throw new RuntimeException('Synthetic loopback PHP not ready');
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST') use ($address):array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>3]]);
        $body=file_get_contents('http://'.$address.$path,false,$context);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';
        foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$header,$match)) $responseCookie=$match[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$body,true),'cookie'=>$responseCookie,'headers'=>$responseHeaders];
    };
    $endpoint='/manager/personal-workspace-api.php';
    $anonymous=$request($endpoint,['action'=>'snapshot']);
    pwCheck('real endpoint rejects anonymous access401',$anonymous['status']===401&&$anonymous['body']['error']==='unauthorized');
    $firstLogin=$request('/manager/fixture-session.php?id=101',[],'','GET');$firstCookie=$firstLogin['cookie'];
    $secondLogin=$request('/manager/fixture-session.php?id=102',[],'','GET');$secondCookie=$secondLogin['cookie'];
    pwCheck('real endpoint uses distinct canonical scoped session cookies',$firstCookie!==''&&$secondCookie!==''&&$firstCookie!==$secondCookie);
    $csrfFailure=$request($endpoint,['action'=>'snapshot'],$firstCookie);
    pwCheck('real snapshot requires existing CSRF403',$csrfFailure['status']===403&&$csrfFailure['body']['error']==='csrf');
    $csrf=['csrf'=>'synthetic-personal-csrf'];
    $snapshot=$request($endpoint,['action'=>'snapshot']+$csrf,$firstCookie);
    pwCheck('real authenticated snapshot uses defaults without initializing storage',$snapshot['status']===200&&$snapshot['body']['version']===0&&$snapshot['body']['state']['tabs']===[]&&(int)$httpDb->query('SELECT COUNT(*) FROM manager_personal_workspace_states')->fetchColumn()===0);
    pwCheck('real endpoint sends no-store JSON header',count(array_filter($snapshot['headers'],static fn(string $h):bool=>str_starts_with(strtolower($h),'cache-control: no-store')))===1);
    $get=$request($endpoint,[],$firstCookie,'GET');
    pwCheck('real authenticated endpoint rejects nonPOST405',$get['status']===405&&$get['body']['error']==='method_not_allowed');
    $create=['action'=>'label_save','name'=>'Synthetic own label','id'=>0,'expected_version'=>0,'operation_id'=>'http-personal-create-0001']+$csrf;
    $created=$request($endpoint,$create,$firstCookie);$replay=$request($endpoint,$create,$firstCookie);
    pwCheck('real own save and lost-response replay commit once',$created['status']===200&&$created['body']['ok']&&$created['body']['version']===1&&$replay['body']===$created['body']&&count($created['body']['state']['labels'])===1);
    $settings=$request($endpoint,['action'=>'settings','settings'=>['density'=>'compact','font'=>'large'],'expected_version'=>1,'operation_id'=>'http-personal-settings-001']+$csrf,$firstCookie);
    $oldReceipt=$request($endpoint,['action'=>'operation','operation_id'=>$create['operation_id']]+$csrf,$firstCookie);
    $oldReplay=$request($endpoint,$create,$firstCookie);
    pwCheck('real recovery and old replay return newest preference snapshot',$settings['status']===200&&$settings['body']['version']===2&&$oldReceipt['body']['found']&&$oldReceipt['body']['result']['version']===2&&$oldReceipt['body']['result']['state']===$settings['body']['state']&&$oldReplay['body']['version']===2&&$oldReplay['body']['state']===$settings['body']['state']);
    $conflict=$request($endpoint,['action'=>'settings','settings'=>['density'=>'comfortable'],'expected_version'=>1,'operation_id'=>'http-personal-conflict-001']+$csrf,$firstCookie);
    pwCheck('real stale command409 contains confirmed current state',$conflict['status']===409&&$conflict['body']['error']==='conflict'&&$conflict['body']['version']===2&&$conflict['body']['state']===$settings['body']['state']);
    $mismatch=$request($endpoint,array_replace($create,['name'=>'Changed command']),$firstCookie);
    pwCheck('real operation key mismatch409 does not repeat mutation',$mismatch['status']===409&&$mismatch['body']['error']==='operation_mismatch');
    $secondSnapshot=$request($endpoint,['action'=>'snapshot','manager_id'=>101,'actor'=>101]+$csrf,$secondCookie);
    $secondReceipt=$request($endpoint,['action'=>'operation','manager_id'=>101,'operation_id'=>$create['operation_id']]+$csrf,$secondCookie);
    pwCheck('real second account including admin cannot inject another actor',$secondSnapshot['status']===200&&$secondSnapshot['body']['version']===0&&$secondSnapshot['body']['state']['labels']===[]&&!$secondReceipt['body']['found']);
    $secondCreate=$request($endpoint,array_replace($create,['manager_id'=>101,'actor'=>101,'name'=>'Synthetic second label']),$secondCookie);
    $firstSnapshot=$request($endpoint,['action'=>'snapshot']+$csrf,$firstCookie);
    pwCheck('real injected actor is ignored for save and operation identity belongs to session',$secondCreate['status']===200&&$secondCreate['body']['version']===1&&$firstSnapshot['body']['version']===2&&$firstSnapshot['body']['state']['labels'][0]['name']==='Synthetic own label'&&(int)$httpDb->query('SELECT COUNT(*) FROM manager_personal_workspace_states')->fetchColumn()===2);
    $badTab=$request($endpoint,['action'=>'tab_save','tab'=>'invalid-array','expected_version'=>2,'operation_id'=>'http-personal-invalid-001']+$csrf,$firstCookie);
    $badVersion=$request($endpoint,['action'=>'settings','settings'=>[],'expected_version'=>'2','operation_id'=>'http-personal-invalid-002']+$csrf,$firstCookie);
    pwCheck('real malformed fields422 never become storage503',$badTab['status']===422&&$badTab['body']['error']==='invalid_tab'&&$badVersion['status']===422&&$badVersion['body']['error']==='invalid_version');
    $groupCreate=['action'=>'group_create','name'=>'Подбор','expected_version'=>2,'operation_id'=>'http-personal-group-create-01','manager_id'=>102];
    $groupCsrf=$request($endpoint,$groupCreate,$firstCookie);
    pwCheck('real group creation requires canonical CSRF before allocation',$groupCsrf['status']===403&&$groupCsrf['body']['error']==='csrf');
    $httpGroup=$request($endpoint,$groupCreate+$csrf,$firstCookie);$httpReplay=$request($endpoint,$groupCreate+$csrf,$firstCookie);
    $httpGroupId=$httpGroup['body']['id'];$httpGroupLabel=$httpGroup['body']['label_id'];
    $otherGroups=$request($endpoint,['action'=>'snapshot']+$csrf,$secondCookie);
    pwCheck('real group create and lost-response replay atomically allocate bound pair in session account',$httpGroup['status']===200&&$httpGroup['body']['version']===3&&$httpReplay['body']===$httpGroup['body']&&$httpGroup['body']['state']['tabs'][0]['kind']==='group'&&$httpGroup['body']['state']['tabs'][0]['group_label_id']===$httpGroupLabel&&count($httpGroup['body']['state']['labels'])===2&&$otherGroups['body']['state']['tabs']===[]);
    $groupBreak=$request($endpoint,['action'=>'label_delete','id'=>$httpGroupLabel,'expected_version'=>3,'operation_id'=>'http-personal-group-break-001']+$csrf,$firstCookie);
    pwCheck('real generic editor cannot split group binding',$groupBreak['status']===422&&$groupBreak['body']['error']==='group_action_required'&&$groupBreak['body']['version']===3);
    $httpRename=$request($endpoint,['action'=>'group_rename','id'=>$httpGroupId,'name'=>'В работе','expected_version'=>3,'operation_id'=>'http-personal-group-rename-01']+$csrf,$firstCookie);
    $groupRecovery=$request($endpoint,['action'=>'operation','operation_id'=>$groupCreate['operation_id']]+$csrf,$firstCookie);
    pwCheck('real group receipt recovers current renamed state and original allocated IDs',$httpRename['status']===200&&$httpRename['body']['version']===4&&$groupRecovery['body']['found']&&$groupRecovery['body']['result']['id']===$httpGroupId&&$groupRecovery['body']['result']['label_id']===$httpGroupLabel&&$groupRecovery['body']['result']['state']===$httpRename['body']['state']);
    $httpDelete=$request($endpoint,['action'=>'group_delete','id'=>$httpGroupId,'expected_version'=>4,'operation_id'=>'http-personal-group-delete-01']+$csrf,$firstCookie);
    $missingGroup=$request($endpoint,['action'=>'list','selection'=>'tab:'.$httpGroupId]+$csrf,$firstCookie);
    pwCheck('real deleted group selection returns404 and current snapshot rather than broad all list',$httpDelete['status']===200&&$httpDelete['body']['state']['tabs']===[]&&count($httpDelete['body']['state']['labels'])===1&&$missingGroup['status']===404&&$missingGroup['body']['error']==='not_found'&&$missingGroup['body']['version']===$httpDelete['body']['version']&&$missingGroup['body']['state']===$httpDelete['body']['state']&&!isset($missingGroup['body']['conversations']));
    $inactiveLogin=$request('/manager/fixture-session.php?id=103',[],'','GET');
    $inactive=$request($endpoint,['action'=>'snapshot']+$csrf,$inactiveLogin['cookie']);
    pwCheck('real canonical auth rejects disabled account401',$inactive['status']===401&&$inactive['body']['error']==='unauthorized');
    $httpDb->exec('UPDATE managers SET is_active=0 WHERE id=101');
    $revoked=$request($endpoint,['action'=>'operation','operation_id'=>$create['operation_id']]+$csrf,$firstCookie);
    pwCheck('real existing session rechecks active account for receipt recovery',$revoked['status']===401&&$revoked['body']['error']==='unauthorized');
    $httpDb->exec('UPDATE managers SET is_active=1 WHERE id=101');
    $httpDb->exec('DROP TABLE manager_personal_workspace_states');
    $storage=$request($endpoint,['action'=>'snapshot']+$csrf,$firstCookie);
    pwCheck('real missing migration returns503 never empty success',$storage['status']===503&&!$storage['body']['ok']&&$storage['body']['error']==='storage_unavailable'&&!isset($storage['body']['state']));
    $receiptStorage=$request($endpoint,['action'=>'operation','operation_id'=>$create['operation_id']]+$csrf,$firstCookie);
    pwCheck('real recovery missing storage returns503 never false current defaults',$receiptStorage['status']===503&&$receiptStorage['body']['error']==='storage_unavailable');
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}
    $httpDb=null;
    $remove=static function(string $path) use (&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $entry) if ($entry!=='.'&&$entry!=='..') $remove($path.'/'.$entry);rmdir($path);}};
    $remove($httpRoot);
}
echo "TOTAL {$passed} | PASS {$passed} | FAIL 0\n";
