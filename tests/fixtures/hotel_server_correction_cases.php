<?php
declare(strict_types=1);

// Synthetic, isolated fixture only. No real source, campaign or provider is used.
function hscUrl(array $scope): string { return 'https://www.example.com/fixtures/'.rawurlencode($scope['hotel']); }
function hscRow(PDO $p,string $sql,array $args=[]): array
{
    $s=$p->prepare($sql);$s->execute($args);$r=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
    if(!$r)throw new RuntimeException('correction_fixture_row_missing');return $r;
}
final class HotelServerCorrectionFixtureProvider implements HotelServerProvider
{
    public array $requests=[];public array $results=[];
    public bool $additionalFalse=false;public bool $missingChecks=false;public bool $badTargetQuote=false;
    private HotelServerFixtureProvider $inner;
    public function __construct(PDO $p){$this->inner=new HotelServerFixtureProvider($p);}
    public function ready(string $role,string $model): bool {return true;}
    public function principal(string $role): string {return $this->inner->principal($role);}
    public function send(array $r): array
    {
        $result=$this->inner->send($r);$this->requests[]=$r;
        if($r['role']==='producer'){
            foreach($result['candidate']['records'] as $i=>&$record){
                $record['evidence']=[['url'=>hscUrl($record['scope']),'quote'=>'Synthetic evidence only']];
                if($r['stage_index']===3&&$i===3){
                    $record['answer']='Unsupported synthetic negative assertion.';
                    if($this->badTargetQuote)$record['evidence'][0]['quote']='Invented quote absent from the assigned excerpt.';
                }
            }unset($record);
        }elseif($r['stage_index']===3){
            $result['approved']=false;$result['evidence']='The fourth answer contains an unsupported negative assertion; the other three checks approve.';
            $result['review_checks']=[];
            foreach($r['scopes'] as $i=>$scope)$result['review_checks'][]=['scope'=>$scope,'approved'=>$i!==3&&!($this->additionalFalse&&$i===0),
                'reason'=>$i===3?'Unsupported synthetic negative assertion.':'Matches its assigned synthetic evidence.'];
            if($this->missingChecks)array_pop($result['review_checks']);
        }
        $this->results[]=$result;return $result;
    }
}
function hscFixture(array $options=[]): array
{
    $f=hsFixture();$plan=hsPlan(4);$plan['research_mode']='assigned_retained_excerpts';$contexts=[];
    for($stage=0;$stage<4;$stage++){
        $plan['stages'][$stage]=[];
        for($i=0;$i<4;$i++){
            $scope=hsScope('correction-'.$stage.'-'.$i);$plan['stages'][$stage][]=$scope;
            $deps=array_fill_keys(['archives','corpus','criteria','instructions','map'],'Assigned synthetic correction projection');
            $deps['archives']=HotelServerInputs::json(['schema'=>'hotel-assigned-source-slice-v1','sources'=>[['kind'=>'retained_excerpt',
                'url'=>hscUrl($scope),'excerpt'=>'Synthetic evidence only','sha256'=>hash('sha256','Synthetic evidence only'),'captured_at'=>1800000000]]]);
            $contexts[]=['scope'=>$scope,'dependencies'=>$deps];
        }
    }
    $plan['limits']['deadline']=1800000100;$plan['limits']['max_calls']=8;
    $plan['limits']['max_tokens']=8000;$plan['limits']['max_micros']=80000;
    $inputs=hsInputs(null,$contexts);$f['correction_plan']=$plan;$f['correction_inputs']=$inputs;
    $f['provider']=new HotelServerCorrectionFixtureProvider($f['p']);
    foreach(['additionalFalse','missingChecks','badTargetQuote'] as $key)$f['provider']->{$key}=($options[$key]??false)===true;
    $clock=$f['clock'];$f['cycle']=new HotelServerCycle($f['p'],$f['lane'],$f['provider'],static fn():int=>$clock->now);
    $f['cycle']->bootstrap('NEW',$inputs,$plan,static fn():bool=>true);hsEnable($f);
    for($i=0;$i<11;$i++)$f['cycle']->tick('NEW');
    $f['clock']->now=1800000200;return $f;
}
function hscReviewed(array $proposal): array
{
    return ['schema'=>'hotel-server-correction-v1','proposal'=>$proposal,'review'=>['schema'=>'hotel-server-correction-review-v1',
        'principal'=>'independent-fixture-correction-reviewer','proposal_sha256'=>hash('sha256',HotelServerInputs::json($proposal)),
        'approved'=>true,'evidence'=>'Reviewed only the assigned synthetic excerpt and exact proposed replacement. The original rejection is retained.',
        'review_reference'=>'synthetic-correction-review-1']];
}
function hscCorrection(array $f): array
{
    $c=hscRow($f['p'],"SELECT * FROM hotel_server_campaigns WHERE campaign_id='NEW'");
    $producer=hscRow($f['p'],"SELECT * FROM hotel_server_attempts WHERE campaign_id='NEW' AND stage_index=3 AND role='producer'");
    $reviewer=hscRow($f['p'],"SELECT * FROM hotel_server_attempts WHERE campaign_id='NEW' AND stage_index=3 AND role='reviewer'");
    $p=$f['correction_inputs']->package();$scope=$f['correction_plan']['stages'][3][3];
    return hscReviewed(['campaign_id'=>'NEW','stage_index'=>3,'bootstrap_sha256'=>$c['bootstrap_sha256'],
        'inputs_sha256'=>$c['inputs_sha256'],'baseline_sha256'=>$c['baseline_sha256'],'plan_sha256'=>hash('sha256',$c['plan_bytes']),
        'producer_attempt'=>$producer['attempt_id'],'producer_request_sha256'=>$producer['request_sha256'],'producer_result_sha256'=>$producer['result_sha256'],
        'review_attempt'=>$reviewer['attempt_id'],'review_request_sha256'=>$reviewer['request_sha256'],'review_result_sha256'=>$reviewer['result_sha256'],
        'scope'=>$scope,'answer'=>'The assigned excerpt states: Synthetic evidence only.',
        'context_sha256'=>hash('sha256',HotelServerInputs::json($p['stage_contexts'][HotelServerInputs::key($scope)])),
        'criteria_sha256'=>$p['pins']['criteria'],'source_url'=>hscUrl($scope),'source_sha256'=>hash('sha256','Synthetic evidence only'),
        'author_principal'=>'fixture-correction-author','authorization_ref'=>'synthetic-owner-correction-authorization']);
}
function hscSnapshot(PDO $p): array
{
    $tables=['hotel_work_lane'=>'lane_key','hotel_server_campaigns'=>'campaign_id','hotel_server_inputs'=>'campaign_id',
        'hotel_server_attempts'=>'attempt_id','hotel_server_candidates'=>'campaign_id,stage_index','hotel_server_reviews'=>'campaign_id,stage_index',
        'hotel_server_baselines'=>'campaign_id,baseline_sha256','hotel_server_receipts'=>'campaign_id,stage_index','hotel_server_records'=>'semantic_key',
        'hotel_server_outbox'=>'campaign_id,stage_index,role','hotel_server_catalog_outbox'=>'receipt_sha256',
        'hotel_server_exclusions'=>'campaign_id,semantic_key','hotel_server_corrections'=>'campaign_id,stage_index'];$out=[];
    foreach($tables as $table=>$order){$q=$p->query('SELECT * FROM '.$table.' ORDER BY '.$order);$out[$table]=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();}
    return $out;
}
function hscUnchangedControls(array $before,array $after): bool
{
    foreach(['state','stage_cursor','new_accepted','baseline_sha256'] as $allowed){unset($before[$allowed],$after[$allowed]);}
    return $before===$after;
}

// Expired and fully reserved is a real boundary: correction does not reserve or send a ninth call.
$f=hscFixture();$fault=null;$other=null;$projector=null;
try{
    $before=hscSnapshot($f['p']);$held=$f['cycle']->status('NEW');$correction=hscCorrection($f);$calls=count($f['provider']->requests);
    hsCheck($held['state']==='hold'&&$held['stage_index']===3&&$held['new_accepted']===3&&$calls===8
        &&hsCount($f['p'],'hotel_server_records')===12,'correction starts from three accepted stages and a known rejected four-record final stage');
    hsCheck((int)$before['hotel_server_campaigns'][0]['reserved_calls']===8
        &&$f['clock']->now>$f['correction_plan']['limits']['deadline'],'correction fixture has exhausted calls and expired deadline');
    hsReject(fn()=>$f['cycle']->correctRejectedFinalStage('NEW',$correction,static fn():bool=>false),'correction requires explicit authorization');
    hsCheck(hscSnapshot($f['p'])===$before,'authorization refusal makes no write');
    foreach(['bootstrap_sha256','inputs_sha256','baseline_sha256','plan_sha256','producer_attempt','producer_request_sha256','producer_result_sha256',
        'review_attempt','review_request_sha256','review_result_sha256','context_sha256','criteria_sha256','source_sha256'] as $pin){
        $bad=$correction['proposal'];$bad[$pin]=str_repeat('0',64);$bad=hscReviewed($bad);
        hsReject(fn()=>$f['cycle']->correctRejectedFinalStage('NEW',$bad,static fn():bool=>true),'correction refuses stale '.$pin);
        hsCheck(hscSnapshot($f['p'])===$before,'stale '.$pin.' is atomic no-op');
    }
    $variants=[];
    $p=$correction['proposal'];$p['stage_index']=2;$variants['nonfinal stage']=hscReviewed($p);
    $p=$correction['proposal'];$p['campaign_id']='FOREIGN';$variants['foreign campaign']=hscReviewed($p);
    $p=$correction['proposal'];$p['scope']=$f['correction_plan']['stages'][3][0];$variants['already approved scope']=hscReviewed($p);
    $p=$correction['proposal'];$p['source_url']='https://www.example.com/foreign-source';$variants['unassigned source']=hscReviewed($p);
    $p=$correction['proposal'];$p['answer']='Unsupported synthetic negative assertion.';$variants['unchanged rejected answer']=hscReviewed($p);
    $p=$correction['proposal'];$p['author_principal']=$correction['review']['principal'];$variants['same author and reviewer']=hscReviewed($p);
    $p=$correction['proposal'];unset($p['authorization_ref']);$variants['missing authorization reference']=hscReviewed($p);
    $p=$correction['proposal'];$p['extra']='not admitted';$variants['extra proposal field']=hscReviewed($p);
    $bad=$correction;$bad['review']['approved']=false;$variants['negative correction review']=$bad;
    $bad=$correction;$bad['review']['principal']=$correction['proposal']['author_principal'].' ';$variants['same author with whitespace alias']=$bad;
    $bad=$correction;$bad['review']['principal']=$f['provider']->principal('producer');$variants['producer self review']=$bad;
    $bad=$correction;$bad['review']['proposal_sha256']=str_repeat('0',64);$variants['review of another proposal']=$bad;
    $bad=$correction;$bad['review']['evidence']='';$variants['missing correction review evidence']=$bad;
    foreach($variants as $label=>$bad){
        hsReject(fn()=>$f['cycle']->correctRejectedFinalStage('NEW',$bad,static fn():bool=>true),'correction refuses '.$label);
        hsCheck(hscSnapshot($f['p'])===$before,$label.' leaves complete original snapshot unchanged');
    }
    $fault=new HotelServerCycle($f['p'],$f['lane'],null,null,static function(string $at):void{
        if($at==='correction_before_commit')throw new RuntimeException('synthetic_correction_crash');});
    hsReject(fn()=>$fault->correctRejectedFinalStage('NEW',$correction,static fn():bool=>true),'crash after correction writes rolls back','synthetic_correction_crash');
    hsCheck(hscSnapshot($f['p'])===$before,'correction rollback covers ledger candidate records baseline receipt state and outbox');$fault=null;

    // This writer has no provider object at all; it cannot launch or reconcile a provider attempt.
    $writer=new HotelServerCycle($f['p'],$f['lane']);
    $done=$writer->correctRejectedFinalStage('NEW',$correction,static fn():bool=>true);$after=hscSnapshot($f['p']);
    hsCheck($done['state']==='complete'&&$done['stage_index']===4&&$done['new_accepted']===4,'valid separately reviewed correction earns exactly the final acceptance');
    hsCheck(hscUnchangedControls($before['hotel_server_campaigns'][0],$after['hotel_server_campaigns'][0])
        &&$after['hotel_work_lane']===$before['hotel_work_lane'],'correction preserves enabled plan input bootstrap reservations historical credit and lane owner');
    foreach(['hotel_server_inputs','hotel_server_attempts','hotel_server_candidates','hotel_server_reviews','hotel_server_outbox','hotel_server_exclusions'] as $table)
        hsCheck($before[$table]===$after[$table],'correction preserves original '.$table.' byte for byte');
    foreach(['hotel_server_baselines','hotel_server_receipts','hotel_server_records','hotel_server_catalog_outbox'] as $table){
        $rows=array_map([HotelServerInputs::class,'json'],$after[$table]);
        foreach($before[$table] as $row)hsCheck(in_array(HotelServerInputs::json($row),$rows,true),'all prior '.$table.' rows are retained');
    }
    hsCheck(count($f['provider']->requests)===$calls&&count($after['hotel_server_outbox'])===8
        &&count($after['hotel_server_attempts'])===8&&count($after['hotel_server_corrections'])===1,'correction adds no attempt or dispatch outbox and makes no provider call');
    $originalReview=hscRow($f['p'],"SELECT * FROM hotel_server_reviews WHERE campaign_id='NEW' AND stage_index=3");
    hsCheck((int)$originalReview['approved']===0&&json_decode($originalReview['review_bytes'],true,64,JSON_THROW_ON_ERROR)['approved']===false,'original independent rejection remains false');
    $receipt=hscRow($f['p'],"SELECT * FROM hotel_server_receipts WHERE campaign_id='NEW' AND stage_index=3");
    $receiptBody=json_decode($receipt['receipt_bytes'],true,64,JSON_THROW_ON_ERROR);
    hsCheck($receiptBody['schema']==='hotel-server-correction-acceptance-v1'
        &&$receiptBody['correction_sha256']===hash('sha256',HotelServerInputs::json($correction))
        &&$receiptBody['correction_review_sha256']===hash('sha256',HotelServerInputs::json($correction['review']))
        &&$receiptBody['original_candidate_sha256']===$originalReview['candidate_sha256']
        &&$receiptBody['review_attempt']===$originalReview['review_attempt']
        &&$after['hotel_server_corrections'][0]['correction_sha256']===$receiptBody['correction_sha256'],'new receipt binds original rejected candidate, saved review and supplemental approval separately');
    $accepted=$writer->readAccepted('NEW');$target=HotelServerInputs::key($correction['proposal']['scope']);$found=[];
    foreach($accepted['records'] as $record)if(HotelServerInputs::key($record['scope'])===$target)$found[]=$record;
    hsCheck(count($accepted['records'])===35&&count($found)===1&&$found[0]['answer']===$correction['proposal']['answer'],'accepted reader exposes exactly one corrected answer with all earlier facts');
    $original=json_decode(hscRow($f['p'],"SELECT candidate_bytes FROM hotel_server_candidates WHERE campaign_id='NEW' AND stage_index=3")['candidate_bytes'],true,64,JSON_THROW_ON_ERROR);
    $effective=json_decode($after['hotel_server_corrections'][0]['candidate_bytes'],true,64,JSON_THROW_ON_ERROR);
    for($i=0;$i<3;$i++)hsCheck($effective['records'][$i]===$original['records'][$i],'unchanged positively reviewed scope '.$i.' retains exact bytes');
    hsCheck($effective['records'][3]['scope']===$original['records'][3]['scope']
        &&$effective['records'][3]['evidence']===$original['records'][3]['evidence'],'corrected record retains original scope and evidence');
    $same=$writer->correctRejectedFinalStage('NEW',$correction,static fn():bool=>true);
    hsCheck($same['state']==='complete'&&hscSnapshot($f['p'])===$after,'exact correction replay is idempotent with no extra credit');
    $different=$correction['proposal'];$different['answer']='Another reviewed synthetic wording.';$different=hscReviewed($different);
    hsReject(fn()=>$writer->correctRejectedFinalStage('NEW',$different,static fn():bool=>true),'a conflicting correction cannot replace the first');
    hsReject(fn()=>$f['p']->exec("UPDATE hotel_server_corrections SET candidate_bytes='tampered'"),'correction evidence is immutable on UPDATE');
    hsReject(fn()=>$f['p']->exec('DELETE FROM hotel_server_corrections'),'correction evidence is immutable on DELETE');
    $f['cycle']->tick('NEW');hsCheck(count($f['provider']->requests)===$calls&&hsCount($f['p'],'hotel_server_outbox')===8,'ordinary completed tick cannot launch another provider call');

    // Catalog commit and lost acknowledgement must preserve exact correction provenance.
    $other=hsConnect($f['dsn']);$lost=true;
    $projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane'],static function()use(&$lost):void{if($lost){$lost=false;throw new RuntimeException('correction_projection_ACK_lost');}});
    hsReject(fn()=>$projector->project('NEW',$receipt['receipt_sha256']),'corrected catalog commit can lose ACK','correction_projection_ACK_lost');
    hsCheck(hsCount($other,'hotel_server_catalog_documents')===1,'corrected catalog payload commits once before ACK');
    $payload=hscRow($f['p'],"SELECT payload_bytes FROM hotel_server_catalog_outbox WHERE receipt_sha256=?",[$receipt['receipt_sha256']])['payload_bytes'];
    hsReject(fn()=>$f['lane']->acknowledgeProjection('NEW',$receipt['receipt_sha256'],str_repeat('0',64)),'corrected projection refuses another payload hash');
    $projector->project('NEW',$receipt['receipt_sha256']);$projector->project('NEW',$receipt['receipt_sha256']);
    hsCheck(hsCount($other,'hotel_server_catalog_documents')===1
        &&hscRow($f['p'],"SELECT state FROM hotel_server_catalog_outbox WHERE receipt_sha256=?",[$receipt['receipt_sha256']])['state']==='done','corrected projection ACK is verified and idempotent');
    $next=hsPlan(1);$next['stages'][0][0]['question']='synthetic-after-correction';
    $seed=hsNext($f,'NEW','NEXT',$next);
    hsCheck($seed['state']==='disabled'&&count($f['cycle']->readAccepted('NEXT')['records'])===35
        &&count($f['provider']->requests)===$calls,'synthetic future handoff independently proves corrected terminal lineage without sending');
    $f['lane']->acknowledgeProjection('NEW',$receipt['receipt_sha256'],hash('sha256',$payload));
    hsCheck($f['cycle']->status('NEXT')['new_accepted']===0,'corrected old projection ACK does not credit a successor');
    $writer=null;
}finally{$writer=null;$fault=null;$projector=null;$other=null;hsCleanup($f);}

foreach(['additionalFalse','missingChecks','badTargetQuote'] as $kind){$f=hscFixture([$kind=>true]);try{
    $before=hscSnapshot($f['p']);$correction=hscCorrection($f);
    hsReject(fn()=>$f['cycle']->correctRejectedFinalStage('NEW',$correction,static fn():bool=>true),'correction refuses original '.$kind);
    hsCheck(hscSnapshot($f['p'])===$before&&count($f['provider']->requests)===8,$kind.' is not silently approved or resent');
}finally{hsCleanup($f);}}

foreach(['unknown','unknown-attempt','tampered-result','outbox','disabled','not-final'] as $kind){$f=hscFixture();try{
    $correction=hscCorrection($f);
    if($kind==='unknown'){
        $f['p']->exec("UPDATE hotel_server_attempts SET state='unknown' WHERE campaign_id='NEW' AND stage_index=3 AND role='reviewer'");
        $f['p']->exec("UPDATE hotel_server_campaigns SET state='unknown' WHERE campaign_id='NEW'");
    }elseif($kind==='unknown-attempt')$f['p']->exec("UPDATE hotel_server_attempts SET state='unknown' WHERE campaign_id='NEW' AND stage_index=3 AND role='reviewer'");
    elseif($kind==='tampered-result'){
        $r=hscRow($f['p'],"SELECT result_bytes FROM hotel_server_attempts WHERE campaign_id='NEW' AND stage_index=3 AND role='reviewer'");
        $q=$f['p']->prepare("UPDATE hotel_server_attempts SET result_bytes=? WHERE campaign_id='NEW' AND stage_index=3 AND role='reviewer'");$q->execute([$r['result_bytes'].' ']);$q->closeCursor();$q=null;
    }elseif($kind==='outbox')$f['p']->exec("UPDATE hotel_server_outbox SET state='pending' WHERE campaign_id='NEW' AND stage_index=3 AND role='reviewer'");
    elseif($kind==='disabled')$f['p']->exec("UPDATE hotel_server_campaigns SET enabled=0 WHERE campaign_id='NEW'");
    else $f['p']->exec("UPDATE hotel_server_campaigns SET stage_cursor=2 WHERE campaign_id='NEW'");
    $before=hscSnapshot($f['p']);
    hsReject(fn()=>$f['cycle']->correctRejectedFinalStage('NEW',$correction,static fn():bool=>true),'correction refuses '.$kind.' state');
    hsCheck(hscSnapshot($f['p'])===$before&&count($f['provider']->requests)===8,$kind.' cannot acquire new credit or a replacement attempt');
}finally{hsCleanup($f);}}

// Corruption is deliberately fixture-only: reader/ACK must not trust a self-consistent forged correction receipt.
$f=hscFixture();try{
    $correction=hscCorrection($f);$f['cycle']->correctRejectedFinalStage('NEW',$correction,static fn():bool=>true);
    $receipt=hscRow($f['p'],"SELECT * FROM hotel_server_receipts WHERE campaign_id='NEW' AND stage_index=3");
    $body=json_decode($receipt['receipt_bytes'],true,64,JSON_THROW_ON_ERROR);$body['correction_sha256']=str_repeat('0',64);
    $raw=HotelServerInputs::json($body);$sha=hash('sha256',$raw);
    $f['p']->exec('DROP TRIGGER hotel_server_receipts_immutable_update');
    $q=$f['p']->prepare("UPDATE hotel_server_receipts SET receipt_sha256=?,receipt_bytes=? WHERE campaign_id='NEW' AND stage_index=3");$q->execute([$sha,$raw]);$q->closeCursor();
    $q=$f['p']->prepare('UPDATE hotel_server_catalog_outbox SET receipt_sha256=? WHERE receipt_sha256=?');$q->execute([$sha,$receipt['receipt_sha256']]);$q->closeCursor();
    $payload=hscRow($f['p'],'SELECT payload_bytes FROM hotel_server_catalog_outbox WHERE receipt_sha256=?',[$sha])['payload_bytes'];
    hsReject(fn()=>$f['lane']->acknowledgeProjection('NEW',$sha,hash('sha256',$payload)),'forged correction lineage cannot ACK even with matching receipt/payload hashes');
    $next=hsPlan(1);$next['stages'][0][0]['question']='cannot-inherit-forged-correction';
    hsReject(fn()=>hsNext($f,'NEW','NEXT',$next),'future handoff rejects forged correction receipt');
    hsCheck(hsCount($f['p'],'hotel_server_campaigns')===1&&count($f['provider']->requests)===8,'forged correction receipt creates no successor or provider call');
}finally{$q=null;hsCleanup($f);}

// Two independent processes compete for the same fixed correction; the loser observes the original commit.
$f=hscFixture();$children=[];try{
    $correction=hscCorrection($f);file_put_contents($f['dir'].'/correction.json',HotelServerInputs::json($correction));
    for($i=0;$i<2;$i++){
        $cmd=[PHP_BINARY];if(php_ini_loaded_file())array_push($cmd,'-c',php_ini_loaded_file());
        array_push($cmd,dirname(__DIR__).'/run_hotel_server_cycle_regression.php','--correction-race',$f['dsn'],$f['dir'].'/correction-go',$f['dir'].'/correction-out'.$i,$f['dir'].'/correction.json');
        $pipes=[];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('correction_race_process');fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $until=microtime(true)+12;while((!is_file($f['dir'].'/correction-out0.ready')||!is_file($f['dir'].'/correction-out1.ready'))&&microtime(true)<$until)usleep(1000);
    hsCheck(is_file($f['dir'].'/correction-out0.ready')&&is_file($f['dir'].'/correction-out1.ready'),'both correction processes are ready');
    file_put_contents($f['dir'].'/correction-go','1');
    foreach($children as [$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        hsCheck(proc_close($process)===0&&$stdout===''&&$stderr==='','correction race child exits clean');}
    $children=[];
    for($i=0;$i<2;$i++)hsCheck(json_decode((string)file_get_contents($f['dir'].'/correction-out'.$i),true,16,JSON_THROW_ON_ERROR)['state']==='complete','correction contender observes the committed completion');
    hsCheck(hsCount($f['p'],'hotel_server_corrections')===1&&hsCount($f['p'],'hotel_server_receipts')===4
        &&hsCount($f['p'],'hotel_server_records')===16&&hsCount($f['p'],'hotel_server_attempts')===8
        &&$f['cycle']->status('NEW')['new_accepted']===4,'concurrent corrections give one ledger, one final credit and no replacement attempts');
}finally{foreach($children as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}}hsCleanup($f);}
