<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerDialogueAnalyticsService.php';
require_once dirname(__DIR__,2).'/services/ManagerAiDraftFeedbackService.php';

/** Same isolated real-service suite runs on SQLite and the existing native MySQL gate. */
function managerSupervisorRegression(): void
{
    $p=managerSupervisorFixture();
    $check=static fn(string $name,bool $ok)=>preparedReplyCheck('supervisor '.$name,$ok);
    $scopeBefore=$p->query('SELECT * FROM managers ORDER BY id')->fetchAll();$projectBefore=$p->query('SELECT * FROM manager_projects ORDER BY manager_id,project_id')->fetchAll();
    $check('no implicit grant',ManagerSupervisorAccessService::scopes(4)===[]&&!ManagerAiDraftContextService::viewerSnapshot(101,4)['ok']);
    $check('ordinary colleague cannot read foreign AI',!ManagerAiDraftContextService::viewerSnapshot(101,5)['ok']);
    $check('nonadmin cannot grant',ManagerSupervisorAccessService::set(4,4,1,[11],false,true,0)['error']==='forbidden');
    $check('foreign project admin cannot grant',ManagerSupervisorAccessService::set(3,4,1,[11],false,true,0)['error']==='project_access_required');
    $check('source from another project rejected',ManagerSupervisorAccessService::set(2,4,1,[21],false,true,0)['error']==='source_access_denied');
    $grant=ManagerSupervisorAccessService::set(2,4,1,[11],false,true,0);
    $check('explicit audited grant succeeds',!empty($grant['ok'])&&$grant['version']===1&&(int)$p->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===1);
    $check('readback retains exact project/source and inactive shift',$scopeBefore===$p->query('SELECT * FROM managers ORDER BY id')->fetchAll()&&$projectBefore===$p->query('SELECT * FROM manager_projects ORDER BY manager_id,project_id')->fetchAll()&&array_column(ManagerSupervisorAccessService::scopes(4)[0]['sources'],'id')===[11]);
    $owner=ManagerAiDraftContextService::snapshot(101,1);$viewer=ManagerAiDraftContextService::viewerSnapshot(101,4);
    $check('viewer uses canonical actual owner revision',!empty($owner['ok'])&&!empty($viewer['ok'])&&$viewer['owner_id']===1&&$viewer['supervisor_read']&&$viewer['revision']===$owner['revision']);
    $check('read scope never widens original mutation visibility',ManagerConversationService::visibleConversation(101,4)===null&&ManagerConversationService::visibleConversation(101,4,true)!==null&&ManagerConversationService::lifecycleState(101,4)===null);
    $job=preparedReplyQueue($owner);$proposal=preparedReplyProposal();$done=ManagerPreparedReplyService::complete($job,$owner['revision'],$proposal);
    $read=ManagerPreparedReplyService::prepared(101,$viewer['owner_id'],$viewer);
    $check('ready lookup retains owner/revision/TTL',!empty($done['ok'])&&!empty($read['prepared'])&&$read['job_id']===$job&&$read['revision']===$owner['revision']);
    $check('viewer id cannot substitute for ready owner',empty(ManagerPreparedReplyService::prepared(101,4,$viewer)['prepared']));
    $beforeRead=$p->query('SELECT * FROM manager_conversation_reads ORDER BY manager_id,conversation_id')->fetchAll();$business=managerSupervisorBusiness($p);
    $detail=ManagerConversationService::detail(101,4);
    $check('default detail is readonly for supervisor',$detail!==null&&$detail['supervisor_read']&&$beforeRead===$p->query('SELECT * FROM manager_conversation_reads ORDER BY manager_id,conversation_id')->fetchAll());
    $check('supervisor preview is not foreign feedback',ManagerAiDraftFeedbackService::record(101,4,$viewer,$proposal)===null&&$business===managerSupervisorBusiness($p));
    $check('unknown grant is readback/CAS not replay',ManagerSupervisorAccessService::set(2,4,1,[11],false,true,0)['error']==='access_conflict'&&(int)$p->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===1);
    $p->beforeSupervisorAudit=static fn()=>throw new RuntimeException('synthetic_audit_failure');
    $check('failed audit rolls back revoke',ManagerSupervisorAccessService::set(2,4,1,[11],false,false,1)['error']==='access_unavailable'&&count(ManagerSupervisorAccessService::scopes(4))===1);
    $check('explicit revoke removes AI access',!empty(ManagerSupervisorAccessService::set(2,4,1,[11],false,false,1)['ok'])&&empty(ManagerAiDraftContextService::viewerSnapshot(101,4)['ok']));
    $check('regrant advances version',ManagerSupervisorAccessService::set(2,4,1,[11],false,true,2)['version']===3);
    $p->mutate("UPDATE messages SET text='Changed conditions' WHERE id=2");$fresh=ManagerAiDraftContextService::viewerSnapshot(101,4);
    $check('source edits invalidate ready result',!empty($fresh['ok'])&&empty(ManagerPreparedReplyService::prepared(101,1,$fresh)['prepared']));
    $p->mutate('UPDATE conversations SET manager_id=6');$new=ManagerAiDraftContextService::viewerSnapshot(101,4);
    $check('transfer resolves new owner without impersonation',!empty($new['ok'])&&$new['owner_id']===6&&$new['revision']!==$fresh['revision']&&empty(ManagerPreparedReplyService::prepared(101,6,$new)['prepared']));
    $p->mutate('UPDATE conversations SET source_id=12');
    $check('excluded source denied',empty(ManagerAiDraftContextService::viewerSnapshot(101,4)['ok']));
    $p->mutate('UPDATE conversations SET source_id=11,manager_id=1');
    managerSupervisorAnalyticsFixture($p);
    $filters=['from'=>'2026-10-22','to'=>'2026-10-22'];$business=managerSupervisorBusiness($p);$begin=microtime(true);$sql=count($p->executed);$r=ManagerDialogueAnalyticsService::read(4,$filters);$queries=count($p->executed)-$sql;
    $check('complete server cohort exceeds Inbox cap',!empty($r['ok'])&&$r['totals']['conversations']===206&&count($r['rows'])===50&&$r['has_more']);
    $check('funnel has consistent full denominators',$r['totals']['requested']===205&&$r['totals']['replied']===103&&$r['totals']['continued']===52&&$r['first_reply']['n']===103&&$r['first_reply']['p50_seconds']===60&&$r['first_reply']['p95_seconds']===3600);
    $check('current waiting includes repeated handoff',$r['totals']['waiting']===155);
    $check('period replies to old requests are separate',$r['period_events']['reply_messages']===104&&$r['period_events']['requests']===206&&$r['period_events']['delivery_failures']===1);
    $check('default sales stage not treated as sale',$r['totals']['won']===1&&$r['totals']['lost']===0&&$r['totals']['recorded_stage']===1);
    $check('timezone boundary is Moscow half-open UTC',$r['period']['start_utc']==='2026-10-21 21:00:00'&&$r['period']['end_exclusive_utc']==='2026-10-22 21:00:00');
    $check('AI candidate coverage never claims verified freshness',$r['coverage']['verified_fresh_ai']===null);
    $seen=array_column($r['rows'],'id');$pages=1;while($r['has_more']){$r=ManagerDialogueAnalyticsService::read(4,$filters+['cursor'=>$r['next_cursor']]);$check('drilldown page authorized and bounded',!empty($r['ok'])&&count($r['rows'])<=50);array_push($seen,...array_column($r['rows'],'id'));if(++$pages>10)throw new RuntimeException('FAIL supervisor pagination loop');}
    $check('all 206 rows visited exactly once',count($seen)===206&&count(array_unique($seen))===206);
    $r=ManagerDialogueAnalyticsService::read(4,$filters+['manager_id'=>6]);$check('current owner and actual first replier distinct',$r['totals']['conversations']===1&&$r['rows'][0]['manager_id']===6&&(int)$r['rows'][0]['first_reply_manager_id']===1);
    $r=ManagerDialogueAnalyticsService::read(4,$filters+['source_id'=>12]);$check('source filter cannot broaden grant',$r['ok']&&$r['totals']['conversations']===0);
    $r=ManagerDialogueAnalyticsService::read(4,$filters+['project_key'=>'other']);$check('project filter cannot broaden grant',$r['ok']&&$r['totals']['conversations']===0);
    $check('ordinary manager denied analytics',ManagerDialogueAnalyticsService::read(5,$filters)['error']==='forbidden');
    $check('invalid dates and overlong period refused',ManagerDialogueAnalyticsService::read(4,['from'=>'2026-02-30','to'=>'2026-10-22'])['error']==='invalid_period');
    $check('read model never changes business data',$business===managerSupervisorBusiness($p)&&$p->sourceWriteAttempts===0);
    $p->mutate('DELETE FROM manager_projects WHERE manager_id=4');$check('project revocation invalidates analytics and AI',ManagerDialogueAnalyticsService::read(4,$filters)['error']==='forbidden'&&empty(ManagerAiDraftContextService::viewerSnapshot(101,4)['ok']));
    echo 'MEASURE synthetic full-cohort206 initial_sql='.$queries.' elapsed_ms='.round((microtime(true)-$begin)*1000,3).' (includes pagination/filter checks; not browser/production latency)' ."\n";
}

function managerSupervisorBusiness(PDO $p): array
{
    $out=[];foreach(['managers','manager_projects','conversations','messages','conversation_events','manager_assignments','manager_conversation_reads','lead_stage_history','manager_prepared_replies','manager_preparation_cursors'] as $table)$out[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $out;
}

function managerSupervisorAnalyticsFixture(PreparedReplyPdo $p): void
{
    $p->guard=false;
    try {
        $c=$p->prepare("INSERT INTO conversations (id,customer_id,project_key,source_id,channel,status,lead_stage_key,manager_id,started_at,is_test) VALUES (?,10,'anytour',11,'telegram','manager','new',1,?,0)");
        $e=$p->prepare("INSERT INTO conversation_events(id,conversation_id,event_type,created_at) VALUES (?,?,'waiting_manager','2026-10-22 08:01:00')");
        $m=$p->prepare('INSERT INTO messages(id,conversation_id,direction,sender_type,text,created_at,channel,metadata_json,sender_id) VALUES (?,?,?,?,?,?,?,?,?)');
        for($i=0;$i<205;$i++){$id=1000+$i;$mid=10000+$i*4;$c->execute([$id,'2026-10-22 08:00:00']);$e->execute([10000+$i,$id]);$m->execute([$mid,$id,'inbound','customer','Synthetic question','2026-10-22 08:00:00','telegram','{}','customer']);if($i%2===0)$m->execute([$mid+1,$id,'outbound','manager','Synthetic reply',$i%20===0?'2026-10-22 09:01:00':'2026-10-22 08:02:00','telegram','{}','1']);if($i%4===0)$m->execute([$mid+2,$id,'inbound','customer','Synthetic continuation',$i%20===0?'2026-10-22 09:02:00':'2026-10-22 08:03:00','telegram','{}','customer']);}
        $c->execute([1205,'2026-10-21 21:00:00']);$c->execute([1206,'2026-10-21 20:59:59']);
        foreach([1400,1401,1402] as $id)$c->execute([$id,'2026-10-22 08:00:00']);
        $p->exec("UPDATE conversations SET manager_id=6 WHERE id=1204;UPDATE conversations SET is_test=1 WHERE id=1400;UPDATE conversations SET source_id=12 WHERE id=1401;UPDATE conversations SET project_key='other',source_id=21 WHERE id=1402;UPDATE conversations SET lead_outcome='won',lead_outcome_updated_at='2026-10-22 08:05:00',lead_stage_key='sold' WHERE id=1000;INSERT INTO lead_stage_history VALUES(1,1000,'sold');INSERT INTO conversation_events(id,conversation_id,event_type,created_at) VALUES(20000,1002,'waiting_manager','2026-10-22 08:05:00'),(20001,1001,'manager_message_failed','2026-10-22 08:06:00'),(20002,101,'waiting_manager','2026-10-19 10:00:00');");
        $m->execute([20000,101,'outbound','manager','Reply to old request','2026-10-22 09:00:00','telegram','{}','1']);
    } finally {$p->guard=true;}
}

function managerSupervisorFixture(): PreparedReplyPdo
{
    $p=preparedReplyFixture();$p->guard=false;
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$p->exec(file_get_contents(dirname(__DIR__,2).'/migrations/056_manager_supervision.sql'));
    else $p->exec('CREATE TABLE manager_supervision(manager_id INTEGER,project_id INTEGER,enabled INTEGER,include_unsourced INTEGER,version INTEGER,updated_at TEXT,PRIMARY KEY(manager_id,project_id));CREATE TABLE manager_supervision_sources(manager_id INTEGER,project_id INTEGER,source_id INTEGER,PRIMARY KEY(manager_id,project_id,source_id));');
    $p->exec('CREATE TABLE admin_audit_log(id INTEGER PRIMARY KEY'.($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' AUTO_INCREMENT':'').',actor_manager_id INTEGER,action VARCHAR(64),entity_type VARCHAR(64),entity_id VARCHAR(191),project_key VARCHAR(64),before_json TEXT,after_json TEXT);CREATE TABLE manager_assignments(id INTEGER PRIMARY KEY,conversation_id INTEGER,manager_id INTEGER,assigned_at VARCHAR(30));CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY,conversation_id INTEGER,to_stage_key VARCHAR(64));');
    $p->exec('ALTER TABLE conversation_sources ADD COLUMN project_id INTEGER;ALTER TABLE conversation_sources ADD COLUMN channel VARCHAR(32);ALTER TABLE conversations ADD COLUMN lead_outcome VARCHAR(32);ALTER TABLE conversations ADD COLUMN lead_outcome_updated_at VARCHAR(30);');
    $p->exec("INSERT INTO managers VALUES(4,'reviewer-account','Supervisor','manager','',1,0),(5,'colleague','Colleague','manager','',1,1),(6,'new-owner','New owner','manager','',1,1);INSERT INTO manager_projects VALUES(4,1),(5,1),(6,1);INSERT INTO conversation_sources VALUES(11,'Allowed source',1,9,'none',NULL,0,1,'telegram'),(12,'Excluded source',1,10,'none',NULL,0,1,'telegram'),(21,'Other project',1,11,'none',NULL,0,2,'telegram');INSERT INTO manager_group_members VALUES(1,9),(5,9),(6,9);UPDATE conversations SET source_id=11;");
    $p->guard=true;$p->supervisorWrites=true;
    return $p;
}
