<?php
declare(strict_types=1);

// Synthetic, isolated fixture only. No real source, campaign or provider is used.
function hscUrl(array $scope): string { return 'https://www.example.com/fixtures/'.rawurlencode($scope['hotel']); }
function hscRow(PDO $p,string $sql,array $args=[]): array
{
    $s=$p->prepare($sql);$s->execute($args);$r=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
    if(!$r)throw new RuntimeException('correction_fixture_row_missing');return $r;
}
function hscPolicyRevision(array $policy,array $profile,array $instructions): string
{ return hash('sha256',HotelServerInputs::json([$policy,$profile,$instructions['producer'],$instructions['reviewer']])); }
final class HotelServerCorrectionFixtureProvider implements HotelServerProvider
{
    public array $requests=[];public array $results=[];
    public bool $additionalFalse=false;public bool $missingChecks=false;public bool $badTargetQuote=false;
    public int $rejectedStage=3;public bool $loseAck=false;public bool $ignorePolicyExpiry=false;
    public ?array $policy=null;public array $profile=[];public array $instructionSha=[];public ?object $clock=null;
    private HotelServerFixtureProvider $inner;
    public function __construct(PDO $p){$this->inner=new HotelServerFixtureProvider($p);}
    public function ready(string $role,string $model): bool
    {return $this->policy===null||($model===$this->policy['model']&&($this->ignorePolicyExpiry||$this->clock->now<$this->policy['valid_until']));}
    public function principal(string $role): string
    {return $this->policy===null?$this->inner->principal($role):$this->policy['principals'][$role].':'.hscPolicyRevision($this->policy,$this->profile,$this->instructionSha);}
    public function send(array $r): array
    {
        $result=$this->inner->send($r);$this->requests[]=$r;
        if($r['role']==='producer'){
            foreach($result['candidate']['records'] as $i=>&$record){
                $record['evidence']=[['url'=>hscUrl($record['scope']),'quote'=>'Synthetic evidence only']];
                if($r['stage_index']===$this->rejectedStage&&$i===3){
                    $record['answer']='Unsupported synthetic negative assertion.';
                    if($this->badTargetQuote)$record['evidence'][0]['quote']='Invented quote absent from the assigned excerpt.';
                }
            }unset($record);
        }elseif($r['stage_index']===$this->rejectedStage){
            $result['approved']=false;$result['evidence']='The fourth answer contains an unsupported negative assertion; the other three checks approve.';
            $result['review_checks']=[];
            foreach($r['scopes'] as $i=>$scope)$result['review_checks'][]=['scope'=>$scope,'approved'=>$i!==3&&!($this->additionalFalse&&$i===0),
                'reason'=>$i===3?'Unsupported synthetic negative assertion.':'Matches its assigned synthetic evidence.'];
            if($this->missingChecks)array_pop($result['review_checks']);
        }
        if($this->policy!==null)$result+=['profile_sha256'=>hash('sha256',HotelServerInputs::json($this->profile)),
            'provider_policy_sha256'=>hscPolicyRevision($this->policy,$this->profile,$this->instructionSha),
            'budget_attestation_sha256'=>$this->policy['budget_attestation_sha256']];
        $this->results[]=$result;
        if($this->loseAck)throw new RuntimeException('synthetic_continuation_lost_ACK');return $result;
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
    $f['provider']=new HotelServerCorrectionFixtureProvider($f['p']);
    $f['provider']->rejectedStage=$options['stage']??3;
    if(($options['policy']??false)===true){
        $plan['models']=['producer'=>'synthetic-hotel','reviewer'=>'synthetic-hotel'];
        $f['provider']->profile=['model'=>'synthetic-hotel','max_output_tokens'=>100];
        $f['provider']->instructionSha=['producer'=>hash('sha256','fixture producer instruction'),'reviewer'=>hash('sha256','fixture reviewer instruction')];
        $f['provider']->policy=['model'=>'synthetic-hotel','profile_sha256'=>hash('sha256',HotelServerInputs::json($f['provider']->profile)),
            'principals'=>['producer'=>'fixture-hotel-producer','reviewer'=>'fixture-hotel-reviewer'],'source_mode'=>'assigned_retained_excerpts',
            'input_token_ceiling'=>100,'request_byte_ceiling'=>32768,'max_scopes'=>4,'reserved_micros_minimum'=>10000,
            'max_source_age_seconds'=>86400,'valid_until'=>$plan['limits']['deadline'],'budget_attestation_sha256'=>hash('sha256','fixture-original-budget')];
        $f['provider']->clock=$f['clock'];
    }
    $inputs=hsInputs(null,$contexts);$f['correction_plan']=$plan;$f['correction_inputs']=$inputs;
    foreach(['additionalFalse','missingChecks','badTargetQuote'] as $key)$f['provider']->{$key}=($options[$key]??false)===true;
    $clock=$f['clock'];$f['cycle']=new HotelServerCycle($f['p'],$f['lane'],$f['provider'],static fn():int=>$clock->now);
    $f['cycle']->bootstrap('NEW',$inputs,$plan,static fn():bool=>true);hsEnable($f);
    for($i=0;$i<3*$f['provider']->rejectedStage+2;$i++)$f['cycle']->tick('NEW');
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
    $stage=$f['provider']->rejectedStage;
    $c=hscRow($f['p'],"SELECT * FROM hotel_server_campaigns WHERE campaign_id='NEW'");
    $producer=hscRow($f['p'],"SELECT * FROM hotel_server_attempts WHERE campaign_id='NEW' AND stage_index=? AND role='producer'",[$stage]);
    $reviewer=hscRow($f['p'],"SELECT * FROM hotel_server_attempts WHERE campaign_id='NEW' AND stage_index=? AND role='reviewer'",[$stage]);
    $p=$f['correction_inputs']->package();$scope=$f['correction_plan']['stages'][$stage][3];
    return hscReviewed(['campaign_id'=>'NEW','stage_index'=>$stage,'bootstrap_sha256'=>$c['bootstrap_sha256'],
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

function hscContinuation(array $f): array
{
    $v=hscCorrection($f);$v['schema']='hotel-server-correction-v2';$p=$v['proposal'];$limits=$f['correction_plan']['limits'];
    $used=2*($p['stage_index']+1);$left=$limits['max_calls']-$used;
    $budget=['schema'=>'hotel-server-continuation-budget-v1','campaign_id'=>'NEW','bootstrap_sha256'=>$p['bootstrap_sha256'],
        'plan_sha256'=>$p['plan_sha256'],'correction_stage_index'=>$p['stage_index'],
        'consumed'=>['calls'=>$used,'tokens'=>$used*$limits['tokens_per_call'],'micros'=>$used*$limits['micros_per_call']],
        'remaining'=>['calls'=>$left,'tokens'=>$left*$limits['tokens_per_call'],'micros'=>$left*$limits['micros_per_call']],
        'limits'=>$limits,'valid_from'=>1800000200,'valid_until'=>1800000600,'authorization_ref'=>'synthetic-owner-same-budget-continuation'];
    $next=$f['provider']->policy;$next['valid_until']=$budget['valid_until'];$next['budget_attestation_sha256']=hash('sha256',HotelServerInputs::json($budget));
    $v['continuation_admission']=['schema'=>'hotel-server-continuation-admission-v1','proposal_sha256'=>hash('sha256',HotelServerInputs::json($p)),
        'budget_attestation'=>$budget,'previous_policy'=>$f['provider']->policy,'policy'=>$next,'profile'=>$f['provider']->profile,
        'instruction_sha256'=>$f['provider']->instructionSha];return $v;
}

// A first-stage correction carries one immutable admission for the six unused calls.
$f=hscFixture(['stage'=>0,'policy'=>true]);$writer=null;$fault=null;$projector=null;$other=null;
try{
    $v=hscContinuation($f);$before=hscSnapshot($f['p']);$clock=$f['clock'];$writer=new HotelServerCycle($f['p'],$f['lane'],null,static fn():int=>$clock->now);
    hsCheck($f['cycle']->status('NEW')['state']==='hold'&&count($f['provider']->requests)===2
        &&hsCount($f['p'],'hotel_server_records')===0,'nonfinal fixture has exactly the two known rejected-stage results');
    hsReject(fn()=>$writer->correctRejectedFinalStage('NEW',$v,static fn()=>true),'v1 entry point refuses a continuation document');
    hsReject(fn()=>$writer->correctRejectedStage('NEW',hscCorrection($f),static fn()=>true),'v1 document still refuses nonfinal stages');
    hsReject(fn()=>$writer->correctRejectedStage('NEW',$v,static fn()=>false),'continuation requires authenticated authorization');
    $badVariants=[];
    $bad=$v;$bad['continuation_admission']['proposal_sha256']=str_repeat('0',64);$badVariants['proposal pin']=$bad;
    $bad=$v;$bad['continuation_admission']['budget_attestation']['remaining']['calls']=8;$badVariants['extra calls']=$bad;
    $bad=$v;$bad['continuation_admission']['budget_attestation']['consumed']['calls']=0;$badVariants['reset reservations']=$bad;
    $bad=$v;$bad['continuation_admission']['budget_attestation']['limits']['max_micros']++;$badVariants['larger cost cap']=$bad;
    foreach(['model'=>'another-model','profile_sha256'=>str_repeat('0',64),'max_source_age_seconds'=>172800,'input_token_ceiling'=>200] as $key=>$value){
        $bad=$v;$bad['continuation_admission']['policy'][$key]=$value;$badVariants['changed policy '.$key]=$bad;
    }
    $bad=$v;$bad['continuation_admission']['instruction_sha256']['producer']=str_repeat('0',64);$badVariants['different original instruction']=$bad;
    $bad=$v;$a=&$bad['continuation_admission'];$a['budget_attestation']['valid_until']=1800086401;
    $a['policy']['valid_until']=$a['budget_attestation']['valid_until'];$a['policy']['budget_attestation_sha256']=hash('sha256',HotelServerInputs::json($a['budget_attestation']));unset($a);
    $badVariants['source-age extension']=$bad;
    foreach($badVariants as $label=>$bad){
        hsReject(fn()=>$writer->correctRejectedStage('NEW',$bad,static fn()=>true),'continuation refuses '.$label);
        hsCheck(hscSnapshot($f['p'])===$before,'invalid '.$label.' preserves every row');
    }
    $clock->now=1800000199;hsReject(fn()=>$writer->correctRejectedStage('NEW',$v,static fn()=>true),'new admission cannot start before its window','continuation_admission_expired');
    $clock->now=1800000600;hsReject(fn()=>$writer->correctRejectedStage('NEW',$v,static fn()=>true),'new admission cannot start after expiry','continuation_admission_expired');
    $clock->now=1800000200;
    $fault=new HotelServerCycle($f['p'],$f['lane'],null,static fn():int=>$clock->now,static function(string $at):void{
        if($at==='correction_before_commit')throw new RuntimeException('synthetic_nonfinal_correction_crash');});
    hsReject(fn()=>$fault->correctRejectedStage('NEW',$v,static fn()=>true),'nonfinal correction rolls back its new outbox too','synthetic_nonfinal_correction_crash');
    hsCheck(hscSnapshot($f['p'])===$before,'rollback preserves two consumed reservations and all original bytes');$fault=null;
    $done=$writer->correctRejectedStage('NEW',$v,static fn()=>true);$after=hscSnapshot($f['p']);
    hsCheck($done['state']==='ready'&&$done['stage_index']===1&&$done['new_accepted']===1
        &&hscUnchangedControls($before['hotel_server_campaigns'][0],$after['hotel_server_campaigns'][0])
        &&count($f['provider']->requests)===2,'network-free correction advances one stage without changing plan/bootstrap/caps/reservations');
    foreach(['hotel_server_inputs','hotel_server_attempts','hotel_server_candidates','hotel_server_reviews','hotel_server_exclusions'] as $table)
        hsCheck($before[$table]===$after[$table],'nonfinal correction retains exact original '.$table);
    hsCheck(count($after['hotel_server_corrections'])===1&&count($after['hotel_server_records'])===4
        &&count($after['hotel_server_outbox'])===3
        &&hscRow($f['p'],"SELECT state,attempt_id FROM hotel_server_outbox WHERE campaign_id='NEW' AND stage_index=1 AND role='producer'")===['state'=>'pending','attempt_id'=>null],
        'one correction appends four records and exactly one next-producer outbox');
    hsReject(fn()=>$f['cycle']->tick('NEW'),'expired original runtime policy cannot send');
    hsCheck(hscSnapshot($f['p'])===$after&&count($f['provider']->requests)===2,'unrenewed runtime does not reserve a call');
    $f['provider']->policy=$v['continuation_admission']['policy'];$f['provider']->policy['principals']['producer']='foreign-runtime-producer';
    hsReject(fn()=>$f['cycle']->tick('NEW'),'mismatched runtime principal is refused before reservation','continuation_runtime_policy_pin');
    hsCheck(hscSnapshot($f['p'])===$after,'runtime policy mismatch has no partial writes');
    $f['provider']->policy=$v['continuation_admission']['policy'];
    $f['cycle']->tick('NEW');$progress=hscSnapshot($f['p']);$replay=$writer->correctRejectedStage('NEW',$v,static fn()=>true);
    hsCheck($replay['already_applied']===true&&hscSnapshot($f['p'])===$progress&&count($f['provider']->requests)===3,'exact correction replay after producer progress creates no outbox or call');
    $r=$f['provider']->requests[2];
    hsCheck(HotelServerInputs::json($r['limits'])===HotelServerInputs::json($f['correction_plan']['limits'])&&$r['limits']['deadline']<$clock->now
        &&$r['continuation_admission_sha256']===hash('sha256',HotelServerInputs::json($v['continuation_admission'])),
        'new attempt keeps original expired limits and names its independently pinned renewed admission');
    $saved=hscRow($f['p'],'SELECT request_bytes,request_sha256 FROM hotel_server_attempts WHERE attempt_id=?',[$r['attempt_id']]);
    $forged=$r;$forged['continuation_admission_sha256']=str_repeat('0',64);$forged=HotelServerInputs::json($forged);
    $q=$f['p']->prepare('UPDATE hotel_server_attempts SET request_bytes=?,request_sha256=? WHERE attempt_id=?');
    $q->execute([$forged,hash('sha256',$forged),$r['attempt_id']]);$q->closeCursor();$corrupt=hscSnapshot($f['p']);
    hsReject(fn()=>$f['cycle']->tick('NEW'),'self-consistent request hash cannot substitute another admission before reviewer send','continuation_attempt_lineage');
    hsCheck(hscSnapshot($f['p'])===$corrupt&&count($f['provider']->requests)===3,'invalid admission reference creates no reviewer reservation');
    $q->execute([$saved['request_bytes'],$saved['request_sha256'],$r['attempt_id']]);$q->closeCursor();$q=null;
    $f['cycle']->tick('NEW');$f['cycle']->tick('NEW');$progress=hscSnapshot($f['p']);
    $writer->correctRejectedStage('NEW',$v,static fn()=>true);
    hsCheck(hscSnapshot($f['p'])===$progress&&$f['cycle']->status('NEW')['stage_index']===2,'replay also preserves a later accepted baseline');
    for($i=0;$i<6;$i++)$f['cycle']->tick('NEW');
    $complete=hscSnapshot($f['p']);$state=$f['cycle']->status('NEW');
    hsCheck($state['state']==='complete'&&$state['new_accepted']===4&&count($f['provider']->requests)===8
        &&count($complete['hotel_server_attempts'])===8&&count($complete['hotel_server_records'])===16
        &&(int)$complete['hotel_server_campaigns'][0]['reserved_calls']===8,'remaining three stages consume exactly the six original unused reservations');
    foreach($before['hotel_server_attempts'] as $row)hsCheck(in_array($row,$complete['hotel_server_attempts'],true),'original stage-zero request/result bytes remain unchanged at completion');
    $clock->now=1800086401;$f['cycle']->tick('NEW');$writer->correctRejectedStage('NEW',$v,static fn()=>true);
    hsCheck(count($writer->readAccepted('NEW')['records'])===35&&hscSnapshot($f['p'])===$complete
        &&count($f['provider']->requests)===8,'history and exact replay work after both renewed deadline and source age expire without a ninth send');
    $other=hsConnect($f['dsn']);$projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane']);$projector->project('NEW',$done['receipt_sha256']);
    hsCheck(hscRow($f['p'],'SELECT state FROM hotel_server_catalog_outbox WHERE receipt_sha256=?',[$done['receipt_sha256']])['state']==='done',
        'nonfinal correction projection revalidates the v2 evidence after expiry');
    $next=hsPlan(1);$next['stages'][0][0]['question']='synthetic-after-bounded-continuation';
    $seed=hsNext($f,'NEW','NEXT',$next);
    hsCheck($seed['state']==='disabled'&&count($f['cycle']->readAccepted('NEXT')['records'])===35
        &&count($f['provider']->requests)===8,'future handoff and reader prove every admitted attempt after expiry without provider readiness');
}finally{$writer=null;$fault=null;$projector=null;$other=null;hsCleanup($f);}

// An expired window or UNKNOWN cannot be converted into another continuation/send.
foreach(['expired','unknown'] as $kind){$f=hscFixture(['stage'=>0,'policy'=>true]);$writer=null;try{
    $v=hscContinuation($f);$clock=$f['clock'];$writer=new HotelServerCycle($f['p'],$f['lane'],null,static fn():int=>$clock->now);
    $writer->correctRejectedStage('NEW',$v,static fn()=>true);$f['provider']->policy=$v['continuation_admission']['policy'];
    if($kind==='expired'){$clock->now=1800000600;$f['provider']->ignorePolicyExpiry=true;}else $f['provider']->loseAck=true;
    $result=$f['cycle']->tick('NEW');
    if($kind==='expired')hsCheck($result['state']==='hold'&&$result['reason']==='budget_or_deadline'&&count($f['provider']->requests)===2,
        'core independently enforces the new deadline even if an adapter reports ready');
    $before=hscSnapshot($f['p']);$n=count($f['provider']->requests);
    $writer->correctRejectedStage('NEW',$v,static fn()=>true);
    $f['cycle']->tick('NEW');
    hsCheck(hscSnapshot($f['p'])===$before&&count($f['provider']->requests)===$n,
        $kind.' does not refund reset or repeat an attempt through correction replay');
}finally{$writer=null;hsCleanup($f);}}

// A later final-stage rejection may still use the unchanged zero-call v1 operation.
$f=hscFixture(['stage'=>0,'policy'=>true]);$writer=null;try{
    $v=hscContinuation($f);$clock=$f['clock'];$writer=new HotelServerCycle($f['p'],$f['lane'],null,static fn():int=>$clock->now);
    $writer->correctRejectedStage('NEW',$v,static fn()=>true);$f['provider']->policy=$v['continuation_admission']['policy'];$f['provider']->rejectedStage=3;
    for($i=0;$i<8;$i++)$f['cycle']->tick('NEW');
    hsCheck($f['cycle']->status('NEW')['state']==='hold'&&count($f['provider']->requests)===8,'continued final stage can retain its own known negative review');
    $last=hscCorrection($f);$clock->now=1800086401;$writer->correctRejectedFinalStage('NEW',$last,static fn()=>true);
    hsCheck($f['cycle']->status('NEW')['state']==='complete'&&count($writer->readAccepted('NEW')['records'])===35
        &&count($f['provider']->requests)===8&&hsCount($f['p'],'hotel_server_corrections')===2,
        'v1 final correction remains network-free after expiry and validates the earlier admission lineage');
}finally{$writer=null;hsCleanup($f);}

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
