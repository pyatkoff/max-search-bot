<?php
declare(strict_types=1);
if (!class_exists('Max2CommonValueStateV2',false)) require_once __DIR__.'/Max2CommonValueStateV2.php';
if (!class_exists('Max2CommonValueRepositoryV2',false)) require_once __DIR__.'/Max2CommonValueRepositoryV2.php';
if (!class_exists('Max2ValueLookupClientV2',false)) require_once __DIR__.'/Max2ValueLookupClientV2.php';
if (!class_exists('Max2CommonValueMetrikaClientV2',false)) require_once __DIR__.'/Max2CommonValueMetrikaClientV2.php';

/** Internal signed-read gateway; a fresh nonce never grants another consumed intent. */
final class Max2CommonValueLookupGatewayV2
{
    private array $counts=['list'=>0,'recheck'=>0];
    private bool $listed=false;
    private bool $rechecked=false;
    private bool $uncertain=false;
    private bool $storageUnknown=false;
    private ?array $selected=null;
    private Closure $clock;
    public function __construct(private Max2CommonValueStateV2 $state,private Max2ValueLookupClientV2 $client,
        private string $tickKey,private string $profileId,callable $clock)
    { $this->clock=Closure::fromCallable($clock); }
    public function counts(): array { return $this->counts; }
    public function uncertain(): bool { return $this->uncertain; }
    public function storageUnknown(): bool { return $this->storageUnknown; }
    private function call(string $action,callable $request): ?array
    {
        $requestSha=null; $consumed=false;
        $before=function(string $hash) use($action,&$requestSha,&$consumed):bool {
            if ($requestSha!==null) { $this->uncertain=true; return false; }
            $requestSha=$hash;
            $intent=$this->state->consumeLookupIntent($this->tickKey,$this->profileId,$action,$hash,($this->clock)());
            if (($intent['status']??null)==='UNKNOWN') $this->storageUnknown=true;
            $consumed=($intent['status']??null)==='CONSUMED'&&($intent['call_permitted']??null)===true;
            if ($consumed) $this->counts[$action]++; else $this->uncertain=true;
            return $consumed;
        };
        try { $result=$request($before); } catch (Throwable $ignored) { $result=null; }
        if (!$consumed||$requestSha===null) { $this->uncertain=true; return null; }
        $valid=is_array($result)&&is_array($result['receipt']??null)
            &&Max2ValueBindingPolicyV2::digest($result['source_receipt_sha256']??null);
        $saved=$this->state->finishLookupIntent($this->tickKey,$this->profileId,$action,$requestSha,
            $valid?$result['source_receipt_sha256']:null,$valid?'observed':'unknown',($this->clock)());
        if (($saved['status']??null)==='UNKNOWN') $this->storageUnknown=true;
        if (!$valid||($saved['status']??null)!=='RECORDED') { $this->uncertain=true; return null; }
        $receipt=$result['receipt'];
        if (($receipt['complete_within_bound']??null)!==true||($receipt['possible_max2_unresolved']??null)!==false
            ||!in_array($receipt['status']??null,$action==='list'?['complete','complete_empty']:['match'],true)) {
            $this->uncertain=true; return null;
        }
        return $result;
    }
    public function lookup(string $actor,array $context): ?array
    {
        if ($this->listed) { $this->uncertain=true; return null; }
        $this->listed=true;
        $this->selected=$this->call('list',fn(callable $before):?array=>$this->client->lookup($actor,$context,$before));
        return $this->selected;
    }
    public function recheck(array $privateLookup,string $recordId): ?array
    {
        if ($this->rechecked||$this->selected===null||$privateLookup!==$this->selected) { $this->uncertain=true; return null; }
        $this->rechecked=true;
        return $this->call('recheck',fn(callable $before):?array=>$this->client->recheck($privateLookup,$recordId,$before));
    }
}

/**
 * One bounded prospective tick. No cron registration, token discovery or v1 tick.
 * The app's reviewed source adapters supply the page and projection callbacks.
 */
final class Max2CommonValueWorkerV2
{
    private Closure $ledgerFactory;
    private Closure $metrikaFactory;
    private Closure $page;
    private Closure $project;
    private Closure $clock;
    public function __construct(private Max2CommonValueStateV2 $state,callable $ledgerFactory,
        private Max2ValueLookupClientV2 $lookup,callable $metrikaFactory,callable $page,callable $project,
        ?callable $clock=null,private int $pageSize=1)
    {
        if ($pageSize<1||$pageSize>3) throw new InvalidArgumentException('V2_PAGE_BOUND');
        $this->ledgerFactory=Closure::fromCallable($ledgerFactory); $this->metrikaFactory=Closure::fromCallable($metrikaFactory);
        $this->page=Closure::fromCallable($page); $this->project=Closure::fromCallable($project);
        $this->clock=$clock===null?static fn():int=>time():Closure::fromCallable($clock);
    }
    private static function summary(): array {
        return array_fill_keys(['profiles_read','reserved','evaluated','held','unknown','lookup_list','lookup_recheck','goal_reads','send_attempts','provider_polls'],0);
    }
    private static function result(array $outcome,array $event): array
    {
        $kind=$outcome['outcome']??null; $http=$outcome['http_status']??null;
        if (!in_array($kind,['accepted','unknown','rejected'],true)||!is_int($http)||$http<0||$http>599
            ||($outcome['automatic_retry_permitted']??null)!==false) throw new UnexpectedValueException('CLIENT_RESULT_UNPROVED');
        $known=array_intersect_key($outcome,array_flip(['outcome','reason','upload_id','status','http_status','automatic_retry_permitted','visit_linkage_verified']));
        $code=$kind==='accepted'?'accepted_upload':($kind==='rejected'?'api_rejected':'transport_unknown');
        return ['state'=>$kind==='accepted'?'accepted':($kind==='rejected'?'failed':'unknown'),
            'csv_sha256'=>$event['csv_sha256'],'response_sha256'=>hash('sha256',json_encode($known,JSON_THROW_ON_ERROR)),
            'upload_id'=>$kind==='accepted'?($outcome['upload_id']??null):null,'http_status'=>$http,'code'=>$code];
    }
    public function tick(): array
    {
        $summary=self::summary(); $started=($this->clock)(); $monotonic=hrtime(true);
        $lease=$this->state->beginTick($started);
        if (($lease['status']??null)!=='ACQUIRED') return ['status'=>$lease['status']??'UNKNOWN','summary'=>$summary,'provider_calls'=>0,'old_stream_invoked'=>false];
        $alive=fn():bool=>($this->clock)()<$lease['lease_until_unix']&&hrtime(true)-$monotonic<120000000000;
        $stop=false;
        try {
            $activation=$lease['activation'];
            $ledger=($this->ledgerFactory)($activation);
            if (!$ledger instanceof Max2CommonValueRepositoryV2) throw new UnexpectedValueException('V2_LEDGER_REQUIRED');
            $rows=($this->page)($lease['cursor'],$this->pageSize,$activation['activated_at_unix']);
            if (!is_array($rows)||!array_is_list($rows)||count($rows)>$this->pageSize) throw new UnexpectedValueException('V2_PAGE_UNPROVED');
            $last=$lease['cursor'];
            // Validate the entire bounded page before acting on its first profile.
            foreach ($rows as $row) {
                if (!is_array($row)||!Max2ValueBindingPolicyV2::positiveId($row['profile_id']??null)
                    ||Max2CommonValueStateV2::compare($row['profile_id'],$last)<=0) throw new UnexpectedValueException('V2_PAGE_ORDER_UNPROVED');
                $last=$row['profile_id'];
            }
            foreach ($rows as $row) {
                if (!$alive()) { $stop=true; $summary['unknown']++; break; }
                $summary['profiles_read']++;
                $gateway=new Max2CommonValueLookupGatewayV2($this->state,$this->lookup,$lease['tick_key'],$row['profile_id'],$this->clock);
                try { $projection=($this->project)($row,$gateway,$activation); }
                catch (Throwable $ignored) { $projection=['status'=>'HOLD','reason'=>'PROJECTION_UNAVAILABLE']; }
                $counts=$gateway->counts(); $summary['lookup_list']+=$counts['list']; $summary['lookup_recheck']+=$counts['recheck'];
                if (!$alive()) { $stop=true; $summary['unknown']++; break; }
                if ($gateway->storageUnknown()) {
                    $outcome=['status'=>'UNKNOWN','reason'=>'LOOKUP_INTENT_COMMIT_UNCONFIRMED'];
                } elseif ($gateway->uncertain()) {
                    $outcome=['status'=>'HOLD','reason'=>'LOOKUP_OUTCOME_UNCONFIRMED'];
                } elseif (($projection['status']??null)==='READY') {
                    if (!is_array($projection['binding']??null)||!is_array($projection['estimate']??null)
                        ||!is_array($projection['identity']??null)||!is_array($projection['recheck']??null)
                        ||($projection['estimate']['profile_id']??null)!==$row['profile_id']) {
                        $outcome=['status'=>'HOLD','reason'=>'EXACT_PROFILE_PROJECTION_REQUIRED'];
                    } else {
                        $outcome=$ledger->reserve($projection['binding'],$projection['estimate'],$projection['identity'],$projection['recheck'],($this->clock)());
                    }
                } else $outcome=['status'=>'HOLD','reason'=>is_array($projection)?($projection['reason']??'PROJECTION_HELD'):'PROJECTION_HELD'];
                $kind=$outcome['status']??'UNKNOWN';
                if ($kind==='RESERVED') $summary['reserved']++;
                elseif (in_array($kind,['EVALUATED','ALREADY_RECORDED'],true)) $summary['evaluated']++;
                elseif ($kind==='HOLD') $summary['held']++;
                else { $summary['unknown']++; $stop=true; }
                $advanced=$this->state->advanceProfile($lease['tick_key'],$row['profile_id'],$outcome,($this->clock)());
                if (($advanced['status']??null)!=='ADVANCED') { $summary['unknown']++; $stop=true; }
                if ($stop) break;
            }
            if (!$stop&&$alive()&&$activation['mode']==='send') {
                $pending=$ledger->pendingReservations(($this->clock)(),1);
                $receipts=$ledger->pendingProviderReceipts(($this->clock)(),1);
                if (($pending['status']??null)!=='OBSERVED'||($receipts['status']??null)!=='OBSERVED') {
                    $summary['unknown']++; $stop=true;
                } elseif ($pending['events']!==[]||$receipts['receipts']!==[]) {
                    $client=($this->metrikaFactory)();
                    if (!$client instanceof Max2CommonValueMetrikaClientV2) throw new UnexpectedValueException('V2_CLIENT_REQUIRED');
                    if ($receipts['receipts']!==[]&&$alive()) {
                        $known=$receipts['receipts'][0]; $summary['provider_polls']++;
                        $receipt=$client->poll($known['upload_id']);
                        if (is_array($receipt)) {
                            $receipt['response_sha256']=hash('sha256',json_encode($receipt,JSON_THROW_ON_ERROR));
                            $recorded=$ledger->recordProviderStatus($known['event_key'],$known['attempt_key'],$receipt,($this->clock)());
                            if (($recorded['status']??null)==='UNKNOWN') { $summary['unknown']++; $stop=true; }
                            elseif (($recorded['status']??null)==='HOLD') $summary['held']++;
                        } else $summary['unknown']++;
                    }
                    if (!$stop&&$pending['events']!==[]&&$alive()) {
                        $summary['goal_reads']++;
                        if (!$client->goalReady()) $summary['held']++;
                        elseif ($alive()) {
                            $intent=$ledger->beginAttempt($pending['events'][0]['event_key'],($this->clock)());
                            if (($intent['status']??null)==='ATTEMPT_CLAIMED'&&($intent['delivery_permitted']??null)===true) {
                                $event=$intent['event']; $summary['send_attempts']++;
                                try { $outcome=$client->upload($event,($this->clock)()); }
                                catch (Throwable $ignored) { $outcome=['outcome'=>'unknown','http_status'=>0,'automatic_retry_permitted'=>false,'reason'=>'client_outcome_unknown']; }
                                try { $result=self::result($outcome,$event); }
                                catch (Throwable $ignored) { $result=['state'=>'unknown','csv_sha256'=>$event['csv_sha256'],
                                    'response_sha256'=>hash('sha256','unproved normalized client result'),'upload_id'=>null,'http_status'=>0,'code'=>'client_result_unproved']; }
                                $recorded=$ledger->recordAttemptResult($event['event_key'],$event['attempt_key'],$result,($this->clock)());
                                if (($recorded['status']??null)==='UNKNOWN'||$result['state']==='unknown') $summary['unknown']++;
                                elseif (($recorded['status']??null)==='HOLD') $summary['held']++;
                            } elseif (($intent['status']??null)==='UNKNOWN') { $summary['unknown']++; $stop=true; }
                            else $summary['held']++;
                        }
                    }
                }
            }
        } catch (Throwable $ignored) { $summary['unknown']++; $stop=true; }
        $finished=$this->state->finishTick($lease['tick_key'],$summary,($this->clock)());
        return ['status'=>($finished['status']??null)==='COMPLETED'&&!$stop?'COMPLETED':'UNKNOWN',
            'summary'=>$summary,'provider_calls'=>$summary['goal_reads']+$summary['send_attempts']+$summary['provider_polls'],
            'lookup_calls'=>$summary['lookup_list']+$summary['lookup_recheck'],'old_stream_invoked'=>false,
            'automatic_retry_performed'=>false];
    }
}
