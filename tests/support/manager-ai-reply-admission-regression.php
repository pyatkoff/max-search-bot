<?php

declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerAiReplyAdmission.php';
require_once dirname(__DIR__,2).'/services/ManagerPreparedReplyService.php';
require_once dirname(__DIR__,2).'/services/ManagerAiDraftFeedbackService.php';

/** Synthetic rows, real snapshots/SQL/archive reader and pure decision. No provider/HTTP. */
function managerAiReplyAdmissionChecks(bool $normalizationOnly=false): void
{
    $fixture=static function(array $options=[]):array{
        $pdo=draftContextFixture(3);
        $pdo->exec("UPDATE messages SET text='Сначала обсуждали 9+1 ночей за 150000' WHERE id=1;
UPDATE messages SET text='Нужен вылет из Москвы и питание всё включено' WHERE id=2;
UPDATE messages SET text='Теперь нужны 14 ночей. Какая цена и есть ли места?' WHERE id=3");
        if(!empty($options['human']))$pdo->exec("UPDATE messages SET direction='outbound',sender_type='manager',text='Проверю условия' WHERE id=1");
        if(!empty($options['callback']))$pdo->exec("UPDATE messages SET text='show_tours',metadata_json='{\"type\":\"callback\"}' WHERE id=3");
        if(!empty($options['thanks']))$pdo->exec("UPDATE messages SET text='Спасибо' WHERE id=3");
        if(($options['normalization']??null)==='selection')$pdo->exec("UPDATE messages SET text='Сравните https://qui-quo.ru/AAAA-0001' WHERE id=3");
        if(($options['normalization']??null)==='photo')$pdo->exec("UPDATE messages SET metadata_json='{\"attachments\":[{\"type\":\"image\"}]}' WHERE id=3");
        $before=ManagerAiDraftContextService::viewerSnapshot(1,1);if(empty($before['ok']))throw new RuntimeException('FAIL admission fixture snapshot');
        $selectionReader=($options['normalization']??null)!=='selection'?null:static fn():ManagerSelectionSnapshot=>ManagerSelectionSnapshot::read($before,static fn(array $urls):array=>array_fill_keys($urls,['status'=>200,'type'=>'text/html','html'=>'<div class="tour"><b class="name">Synthetic Hotel</b><span class="price">100 EUR</span></div>']));
        $photoReader=($options['normalization']??null)!=='photo'?null:static fn(array $context):?array=>$before['photo_context']->read($context,static function(array $source):array{
            $stream=fopen('php://memory','w+b');fwrite($stream,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=',true));rewind($stream);return ['stream'=>$stream];
        });
        $wire=ManagerAiDraftService::prepareInput($before['context'],$before['company_knowledge'],$selectionReader,$before['reference_context'],$photoReader);
        if(isset($wire['error']))throw new RuntimeException('FAIL admission fixture input');
        if(isset($options['extra']))$wire['context']+=$options['extra'];
        $proposal=['ok'=>true,'should_draft'=>true,'draft'=>$options['draft']??'Вылет из Москвы. Стоимость за 9+1 ночей 150000.',
            'next_action'=>'answer_question','reason'=>'Все условия проверены моделью.','checks'=>[],
            'evidence_message_ids'=>[3],'history_incomplete'=>false];
        if(!empty($options['no_contact']))$proposal=array_replace($proposal,['should_draft'=>false,'draft'=>null,'next_action'=>'no_contact']);
        $job=str_repeat('a',64);$instruction=hash('sha256',ManagerAiDraftService::instructions());
        $now=time();$prepared=gmdate('Y-m-d H:i:s',$now-10);$expires=gmdate('Y-m-d H:i:s',$now+3590);
        $json=json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $pdo->exec("CREATE TABLE manager_prepared_replies(job_id TEXT PRIMARY KEY,conversation_id INTEGER,manager_id INTEGER,project_key TEXT,context_revision TEXT,instruction_revision TEXT,latest_message_id INTEGER,purpose TEXT,status TEXT,proposal_json TEXT,proposal_sha256 TEXT,prepared_at TEXT,expires_at TEXT);
CREATE TABLE manager_prepared_reply_inputs(receipt_id TEXT PRIMARY KEY,job_id TEXT,admin_id INTEGER,connection_id TEXT,packet_json TEXT,packet_sha256 TEXT,issued_at TEXT);
CREATE TABLE manager_prepared_reply_input_links(job_id TEXT PRIMARY KEY,receipt_id TEXT);
CREATE TABLE manager_ai_drafts(draft_id TEXT PRIMARY KEY,conversation_id INTEGER,manager_id INTEGER,project_key TEXT,context_revision TEXT,instruction_revision TEXT,latest_message_id INTEGER,proposal_json TEXT,created_at TEXT,preparation_job_id TEXT,insert_requested_at TEXT);
CREATE TABLE manager_ai_draft_inputs(draft_id TEXT PRIMARY KEY,input_json TEXT,input_sha256 TEXT)");
        $q=$pdo->prepare('INSERT INTO manager_prepared_replies VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$job,1,1,'anytour',$before['revision'],$instruction,3,'background','ready',$json,hash('sha256',$json),$prepared,$expires]);
        $row=$pdo->query('SELECT * FROM manager_prepared_replies')->fetch(PDO::FETCH_ASSOC);
        $packet=['ok'=>true,'job_id'=>$job,'revision'=>$before['revision'],'preparation_scope'=>'assigned_manager','latest_message_id'=>3,
            'context'=>$wire['context'],'company_knowledge'=>['facts'=>$wire['context']['verified_company_facts'],'revision'=>$before['company_knowledge']->revision()],
            'instructions'=>ManagerAiDraftService::instructions($wire['context']),'instruction_revision'=>$instruction];
        $evidence=ManagerPreparedInputEvidence::capture($packet);if($evidence===null)throw new RuntimeException('FAIL admission fixture packet');
        $receipt=ManagerPreparedInputService::record($pdo,$row,$evidence,2,str_repeat('c',32));
        if(empty($receipt['captured']))throw new RuntimeException('FAIL admission fixture receipt');
        ManagerPreparedInputService::link($pdo,$job,$receipt['receipt_id']);
        $ready=['ok'=>true,'prepared'=>true,'job_id'=>$job,'revision'=>$before['revision'],'latest_message_id'=>3,'prepared_at'=>$prepared,'expires_at'=>$expires];
        return [$pdo,$before,$proposal,$ready,$evidence,$instruction,$now,$wire];
    };
    $evaluate=static fn(array $f,int $viewer=1):array=>ManagerPreparedReplyService::admission(1,$viewer,$f[1],$f[3],$f[2])['admission'];
    $closed=static fn(array $a):bool=>$a['decision']==='manager_review'&&$a['actual_send_allowed']===false
        &&$a['criteria']['last_customer_question']['status']==='unknown'&&$a['criteria']['answer_completeness']['status']==='unknown'
        &&$a['criteria']['material_conditions']['status']==='unknown';
    $state=static function(PDO $pdo):string{$v=[];foreach(['messages','conversations','manager_prepared_replies','manager_prepared_reply_inputs','manager_prepared_reply_input_links','manager_conversation_reads']as$table)$v[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));};

    // Exercise actual prepareInput normalization with only in-memory injected
    // selection transport/photo streams. Admission itself never invokes readers.
    foreach(['selection','photo']as$kind){
        $f=$fixture(['normalization'=>$kind]);$original=$state($f[0]);$a=$evaluate($f);$checks=$a['criteria']['context_currentness']['technical_checks'];
        $raw=$f[1]['context']['messages'][2];$normalized=$f[7]['context']['messages'][2];
        $transformed=$kind==='selection'?
            ($raw['selections_link_unread']===true&&$normalized['selections_link_unread']===false&&str_contains($normalized['text'],'[подборка прочитана]')&&($f[7]['context']['selection_snapshots'][0]['state']??null)==='observed'):
            ($raw['media_unread']===true&&$normalized['media_unread']===false&&($f[7]['context']['photo_evidence'][0]['status']??null)==='attached'&&count($f[7]['images'])===1);
        draftContextCheck('canonical '.$kind.' enrichment remains unknown rather than falsely stale',
            $transformed&&$closed($a)&&$checks['context_revision']['status']==='pass'&&$checks['input_envelope']['status']==='unknown'
            &&$checks['input_envelope']['reason']==='input_normalization_unverified'&&$a['criteria']['context_currentness']['status']==='unknown'
            &&$a['external_model_input']==='unknown'&&$original===$state($f[0])&&$f[0]->writeAttempts===0);
    }
    $f=$fixture();$row=$f[0]->query('SELECT * FROM manager_prepared_reply_inputs')->fetch();$packet=json_decode($row['packet_json'],true,64,JSON_THROW_ON_ERROR);$packet['packet']['context']['messages'][2]['text']='Другое значение в формально корректном envelope';$bytes=json_encode($packet,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$f[0]->prepare('UPDATE manager_prepared_reply_inputs SET packet_json=?,packet_sha256=?')->execute([$bytes,hash('sha256',$bytes)]);$a=$evaluate($f);
    draftContextCheck('an internally valid checksum does not bind different message content',$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['input_envelope']['status']==='fail');
    if($normalizationOnly)return;

    $f=$fixture();$original=$state($f[0]);$a=$evaluate($f);
    draftContextCheck('admission separates current technical binding from unproved semantic correctness',
        $a['criteria']['context_currentness']['status']==='pass'&&$closed($a)&&$a['input_provenance']==='server_issued_context'&&$a['external_model_input']==='unknown');
    draftContextCheck('admission checks do not write jobs receipts messages read state or conversation ownership',
        $original===$state($f[0])&&$f[0]->writeAttempts===0&&!$f[0]->inTransaction());
    draftContextCheck('a last-message citation and empty model checks cannot approve an answer about the previous 9+1 nights',
        $f[2]['evidence_message_ids']===[3]&&$f[2]['checks']===[]&&$closed($a)&&$a['criteria']['last_customer_question']['last_customer_input_id']===3);
    foreach(['Из Москвы вылет возможен.','Для 14 ночей нужна отдельная проверка цены и наличия.'] as $draft){$f=$fixture(['draft'=>$draft]);draftContextCheck('partial and plausible answers still require independent completeness and terms review',$closed($evaluate($f)));}
    $f=$fixture(['thanks'=>true]);$a=$evaluate($f);
    draftContextCheck('a latest thanks message does not prove older explicit questions resolved',$closed($a)&&$a['criteria']['last_customer_question']['customer_turn_ids_in_scope']===[1,2,3]&&$a['criteria']['last_customer_question']['open_question_resolution']==='not_independently_established');
    $f=$fixture(['callback'=>true]);$a=$evaluate($f);
    draftContextCheck('service callbacks never replace the last actual customer message',$a['criteria']['last_customer_question']['last_customer_input_id']===2&&$closed($a));

    foreach([
        ['edited last input',"UPDATE messages SET text='Нужен уже другой отель и только 14 ночей' WHERE id=3",'context_revision'],
        ['new input',"INSERT INTO messages VALUES(4,1,'inbound','customer','Ещё вопрос про багаж','2026-10-17 12:01:00','telegram',NULL,'synthetic','new')",'latest_input'],
        ['source changed',"UPDATE conversations SET source_id=99 WHERE id=1",'context_revision'],
        ['instruction changed',"UPDATE manager_prepared_replies SET instruction_revision='".str_repeat('b',64)."'",'instruction_revision'],
    ]as[$label,$sql,$check]){$f=$fixture();$f[0]->exec($sql);$a=$evaluate($f);draftContextCheck('admission rejects stale binding when '.$label,$closed($a)&&$a['criteria']['context_currentness']['technical_checks'][$check]['status']!=='pass');}
    $f=$fixture();$f[0]->exec("UPDATE conversations SET manager_id=2 WHERE id=1");
    $lost=ManagerPreparedReplyService::admission(1,1,$f[1],$f[3],$f[2]);
    draftContextCheck('newly observed access loss retains the canonical refusal rather than returning a private proposal',empty($lost['ok'])&&isset($lost['error']));
    draftContextCheck('reassignment cannot borrow the prior owners admission',$closed($evaluate($f))&&$evaluate($f)['criteria']['context_currentness']['status']!=='pass');
    $f=$fixture();$f[0]->exec("INSERT INTO manager_projects VALUES(1,2); UPDATE conversations SET project_key='other' WHERE id=1");$a=$evaluate($f);
    draftContextCheck('another project never inherits the saved proposal binding',$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['scope']['status']!=='pass');
    $f=$fixture();$a=$evaluate($f,2);
    draftContextCheck('an administrator reading another managers client is not the reply owner',$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['reply_owner']['status']!=='pass');
    $f=$fixture();$a=$evaluate($f,3);
    draftContextCheck('an account without the project scope gets no positive binding',$closed($a)&&$a['criteria']['context_currentness']['status']!=='pass');
    $f=$fixture();$changed=$f[2];$changed['draft']='Подменённый сохранённый ответ';$bytes=json_encode($changed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$f[0]->prepare('UPDATE manager_prepared_replies SET proposal_json=?,proposal_sha256=?')->execute([$bytes,hash('sha256',$bytes)]);$a=$evaluate($f);
    draftContextCheck('a different stored proposal cannot be attributed to the answer being displayed',$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['proposal_binding']['status']==='fail');
    foreach(['boundary','future','extended']as$mode){$f=$fixture();$now=time();if($mode==='boundary')$f[0]->prepare('UPDATE manager_prepared_replies SET expires_at=?')->execute([gmdate('Y-m-d H:i:s',$now)]);elseif($mode==='future')$f[0]->prepare('UPDATE manager_prepared_replies SET prepared_at=?,expires_at=?')->execute([gmdate('Y-m-d H:i:s',$now+10),gmdate('Y-m-d H:i:s',$now+3600)]);else$f[0]->prepare('UPDATE manager_prepared_replies SET expires_at=?')->execute([gmdate('Y-m-d H:i:s',$now+7200)]);$a=$evaluate($f);draftContextCheck('admission enforces original prepared TTL: '.$mode,$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['ttl']['status']==='fail');}
    $f=$fixture();$f[0]->exec('DELETE FROM manager_prepared_reply_input_links');$a=$evaluate($f);
    draftContextCheck('missing archived input remains unknown and is never rebuilt from live context',$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['input_envelope']['status']==='unknown'&&$a['input_provenance']==='unavailable');
    $f=$fixture(['extra'=>['semantic_review'=>['approved'=>true,'complete'=>true,'issuer'=>'self-supplied'],
        'hotel_reference'=>['price_authority'=>'none','facts'=>[['text'=>'У отеля есть бассейн']]],
        'selection_snapshots'=>[['nights'=>'9+1','current_price_verified'=>false,'amount'=>'150000']]]]);$a=$evaluate($f);
    draftContextCheck('self-reported approval old prices photos and hotel properties cannot prove current 14-night tour conditions',$closed($a)&&$a['external_model_input']==='unknown');
    $f=$fixture(['no_contact'=>true]);$a=$evaluate($f);
    draftContextCheck('no_contact never becomes an automatic reply or send permission',$closed($a)&&in_array('no_contact_is_not_permission',$a['reasons'],true));
    $f=$fixture(['human'=>true]);$a=$evaluate($f);
    draftContextCheck('a later customer message does not implicitly resume after a human reply',$closed($a)&&$a['send_pause']['state']==='requires_explicit_resume'&&$a['send_pause']['explicit_resume_proven']===false);

    $f=$fixture();$input=$f[4]->input();$id=ManagerAiDraftFeedbackService::record(1,1,$f[1],$f[2],null,$input);
    $before=$f[0]->query('SELECT * FROM manager_ai_drafts')->fetchAll();$a=ManagerAiReplyAdmission::manual(1,$f[1],$f[1],$f[2],$input,$f[5],$f[5],$id,time());
    draftContextCheck('manual proposal keeps its immutable eight fields and has no invented prepared TTL',
        $id!==null&&$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['ttl']['status']==='unknown'&&$before===$f[0]->query('SELECT * FROM manager_ai_drafts')->fetchAll());
    $a=ManagerAiReplyAdmission::manual(1,$f[1],$f[1],$f[2],$input,$f[5],str_repeat('d',64),$id,time());
    draftContextCheck('manual admission detects an instruction revision mismatch',$closed($a)&&$a['criteria']['context_currentness']['technical_checks']['instruction_revision']['status']==='fail');
    $a=ManagerAiReplyAdmission::manual(1,$f[1],$f[1],$f[2],null,$f[5],$f[5],null,time());
    draftContextCheck('missing manual capture or feedback persistence cannot create a positive admission',$closed($a)&&$a['criteria']['context_currentness']['status']!=='pass');
    draftContextCheck('unavailable evidence has the same explicit four-criterion closed decision',$closed(ManagerAiReplyAdmission::unavailable())&&count(ManagerAiReplyAdmission::unavailable()['criteria'])===4);
}
