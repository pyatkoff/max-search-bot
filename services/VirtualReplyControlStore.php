<?php

declare(strict_types=1);

require_once __DIR__.'/VirtualReplyControlProtocol.php';
require_once __DIR__.'/VirtualReplySourceReader.php';
require_once __DIR__.'/VirtualReplySampling.php';

/** Explicit paired controls only. No provider, delivery, runtime DDL or ordinary-job writes. */
final class VirtualReplyControlStore
{
    private const DAILY_LIMIT = 200;
    private const REVIEW_DELAY = 60;
    private bool $enabled = false;
    private string $driver;
    private object $reader;
    private $clock;
    private ?VirtualReplySampling $sampling = null;

    public function __construct(private PDO $pdo,?object $reader=null,?callable $clock=null)
    {
        $this->driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver,['mysql','sqlite'],true)) throw new InvalidArgumentException('invalid_virtual_reply_control_dependencies');
        $this->reader=$reader??new VirtualReplySourceReader($pdo);
        $this->clock=$clock??static fn():int=>time();
        $tables=['virtual_reply_control_runs','virtual_reply_control_cases','virtual_reply_control_jobs'];$missing=0;
        foreach ($tables as $table) {
            try {$pdo->query('SELECT * FROM '.$table.' LIMIT 0');}
            catch (PDOException $error) {
                $state=(string)($error->errorInfo[0]??$error->getCode());
                $absent=$state==='42S02'||((int)($error->errorInfo[1]??0)===1&&str_contains($error->getMessage(),'no such table: '.$table));
                if (!$absent) throw new RuntimeException('virtual_reply_control_storage_unavailable');
                $missing++;
            }
        }
        if ($missing===count($tables)) return;
        if ($missing!==0) throw new RuntimeException('virtual_reply_control_storage_unavailable');
        $this->sampling=new VirtualReplySampling($pdo,$this->clock);
        if (!$this->sampling->available()) throw new RuntimeException('virtual_reply_control_storage_unavailable');
        $this->enabled=true;
    }

    public function available(): bool {return $this->enabled;}

    /** Routing only; neither reads private context nor reserves model work. */
    public function owns(string $jobId): bool
    {
        $this->id($jobId);
        return $this->enabled&&$this->job($jobId)!==null;
    }

    /** Explicit provider-free registration; two supported contracts freeze before allocation. */
    public function register(array $baseline,array $candidate,int $limit,int $adminId): array
    {
        $this->outside();$this->required();$this->admin($adminId);$limit=$this->limit($limit,20);
        $baseline=VirtualReplyControlProtocol::validateAuthorContract($baseline);
        $candidate=VirtualReplyControlProtocol::validateAuthorContract($candidate);
        if ($baseline['instruction_revision']===$candidate['instruction_revision']) throw new InvalidArgumentException('virtual_reply_control_identical_contracts');
        $reviewer=VirtualReplyControlProtocol::reviewerContract();$runId=$this->newId();$now=$this->now();
        $this->transaction(function() use ($runId,$baseline,$candidate,$reviewer,$adminId,$now):void {
            $this->execute('INSERT INTO virtual_reply_control_runs (run_id,project_key,baseline_contract_json,candidate_contract_json,reviewer_contract_json,registered_by,created_at) VALUES (?,?,?,?,?,?,?)',
                [$runId,'anytour',$this->encode($baseline),$this->encode($candidate),$this->encode($reviewer),$adminId,$now]);
        });
        $registered=0;$invalid=0;
        // Exclude neighboring prefixes before LIMIT, and never reuse a registered group.
        $better="(CASE WHEN ns.kind='substantive' THEN 0 ELSE 1 END<CASE WHEN s.kind='substantive' THEN 0 ELSE 1 END OR (ns.kind=s.kind AND (n.reference_time>c.reference_time OR (n.reference_time=c.reference_time AND n.id>c.id))))";
        $rows=$this->rows('SELECT c.* FROM virtual_reply_cases c JOIN virtual_reply_case_sampling s ON s.case_id=c.case_id JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key '
            .'WHERE c.project_key=? AND c.state=? AND s.legacy=0 AND s.identity_complete=1 AND g.cohort=? AND g.exposed_at IS NULL '
            .'AND NOT EXISTS (SELECT 1 FROM virtual_reply_control_cases old WHERE old.group_key=s.group_key) '
            .'AND NOT EXISTS (SELECT 1 FROM virtual_reply_cases n JOIN virtual_reply_case_sampling ns ON ns.case_id=n.case_id WHERE n.project_key=c.project_key AND n.state=? AND ns.group_key=s.group_key AND '.$better.') '
            .'ORDER BY c.reference_time DESC,c.id DESC LIMIT 100',['anytour','active','reserved','active']);
        foreach ($rows as $row) {
            if ($registered>=$limit) break;
            $snapshot=$this->snapshot($row['source_kind'],(int)$row['source_id']);
            $added=$this->transaction(function() use ($row,$snapshot,$runId,$baseline,$candidate,$reviewer,$now):bool {
                $source=$this->source($row['source_kind'],(int)$row['source_id'],true);
                if (!$this->sourceMatches($source,$snapshot,$row['source_revision'])) return false;
                $this->sampling->attach($source,$snapshot);
                $group=$this->group($source['case_id'],true);
                if (!$this->reserved($group)||$this->one('SELECT control_id FROM virtual_reply_control_cases WHERE group_key=?'.$this->lock(),[$group['group_key']])) return false;
                $author=$this->one('SELECT status,claimed_at FROM virtual_reply_jobs WHERE case_id=? AND role=?'.$this->lock(),[$source['case_id'],'author']);
                if (!$author||$author['status']!=='pending'||$author['claimed_at']!==null) return false;
                $controlId=$this->newId();$order=random_int(0,1)===0?['A'=>'baseline','B'=>'candidate']:['A'=>'candidate','B'=>'baseline'];
                $this->execute('INSERT INTO virtual_reply_control_cases (control_id,run_id,group_key,source_kind,source_id,source_revision,state,payload_json,evidence_ids_json,order_json,created_at,checked_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$controlId,$runId,$group['group_key'],$source['source_kind'],$source['source_id'],$source['source_revision'],'active',$source['payload_json'],$source['evidence_ids_json'],$this->encode($order),$now,$now,$now]);
                foreach (['A','B','review'] as $slot) {
                    $role=$slot==='review'?'reviewer':'author';$contract=$slot==='review'?$reviewer:($order[$slot]==='baseline'?$baseline:$candidate);
                    $this->execute('INSERT INTO virtual_reply_control_jobs (job_id,control_id,role,slot,status,available_at,contract_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$this->newId(),$controlId,$role,$slot,'pending',$role==='author'?$now:'9999-12-31 23:59:59',$this->encode($contract),$now,$now]);
                }
                return true;
            });
            if ($added) $registered++;else $invalid++;
        }
        return ['purpose'=>'control','run_id'=>$runId,'registered_cases'=>$registered,'requested_cases'=>$limit,
            'ineligible_candidates'=>$invalid,'has_more'=>count($rows)>$registered+$invalid,'sampling'=>$this->sampling->status()];
    }

    public function claim(string $role,int $adminId,int $limit): array
    {
        $this->outside();$this->required();$this->role($role);$this->admin($adminId);$limit=$this->limit($limit,20);$this->expire();
        $now=$this->now();$day=substr($now,0,10);$jobs=[];$unsupported=0;
        $rows=$this->rows('SELECT j.job_id,j.control_id FROM virtual_reply_control_jobs j JOIN virtual_reply_control_cases c ON c.control_id=j.control_id '
            .'WHERE c.state=? AND j.role=? AND j.status=? AND j.available_at<=? '
            .'AND NOT EXISTS (SELECT 1 FROM virtual_reply_control_jobs u WHERE u.control_id=j.control_id AND u.status=?) '
            .'ORDER BY j.available_at,c.created_at,j.job_id LIMIT 100',['active',$role,'pending',$now,'unknown']);
        foreach ($rows as $row) {
            if (count($jobs)>=$limit) break;
            if (!$this->refresh($row['control_id'])) continue;
            $job=$this->job($row['job_id']);
            if (!$job||!$this->supported($job)) {$unsupported++;continue;}
            $token=$this->newId();
            $claimed=$this->transaction(function() use ($row,$role,$adminId,$now,$day,$token):?array {
                $this->insertIgnore('virtual_reply_quotas',['project_key'=>'anytour','day_utc'=>$day,'role'=>$role,'used_count'=>0,'updated_at'=>$now]);
                $quota=$this->one('SELECT used_count FROM virtual_reply_quotas WHERE project_key=? AND day_utc=? AND role=?'.$this->lock(),['anytour',$day,$role]);
                if (!$quota||(int)$quota['used_count']>=self::DAILY_LIMIT) return null;
                $case=$this->lockedCase($row['control_id']);
                if (!$case) return null;
                $job=$this->job($row['job_id'],true);
                if (!$job||$job['role']!==$role||$job['status']!=='pending'||$job['available_at']>$now||!$this->supported($job)) return null;
                $siblings=$this->jobs($case['control_id'],true);
                foreach ($siblings as $sibling) if ($sibling['status']==='unknown') return null;
                if ($role==='reviewer'&&!$this->pairReady($siblings,$now)) return null;
                $expires=$this->date($this->at($now)+($role==='author'?900:1800));
                $this->execute('UPDATE virtual_reply_control_jobs SET status=?,claim_token_sha256=?,claimed_by=?,lease_expires_at=?,claimed_at=?,updated_at=? WHERE job_id=? AND status=?',
                    ['running',hash('sha256',$token),$adminId,$expires,$now,$now,$job['job_id'],'pending']);
                $this->execute('UPDATE virtual_reply_quotas SET used_count=used_count+1,updated_at=? WHERE project_key=? AND day_utc=? AND role=?',[$now,'anytour',$day,$role]);
                $contract=$this->contract($job);
                return ['case_id'=>$job['job_id'],'role'=>$role,'claim_token'=>$token,'lease_expires_at'=>$expires,'profile'=>$contract['profile']];
            });
            if ($claimed!==null) $jobs[]=$claimed;
            elseif ($this->used($role,$day)>=self::DAILY_LIMIT) break;
        }
        return ['purpose'=>'control','role'=>$role,'jobs'=>$jobs,'daily_limit'=>self::DAILY_LIMIT,'daily_used'=>$this->used($role,$day),'unsupported_contracts'=>$unsupported];
    }

    /** Exactly the frozen job fields; no version/order/arm or subsequent conversation. */
    public function context(string $jobId,string $role,string $token,int $adminId): array
    {
        $this->outside();$this->required();$this->credentials($jobId,$role,$token,$adminId);$this->expire();
        $job=$this->job($jobId);$this->owned($job,$role,$token,$adminId,false);
        if (!$this->refresh($job['control_id'])) throw new RuntimeException('virtual_reply_control_source_changed');
        $job=$this->job($jobId);$this->owned($job,$role,$token,$adminId,false);
        $case=$this->control($job['control_id']);$contract=$this->contract($job);$this->validateContract($contract,$role);
        $payload=$this->decode($case['payload_json']);$evidence=$this->decode($case['evidence_ids_json']);
        if ($role==='reviewer') {
            $siblings=$this->bySlot($this->jobs($case['control_id']));
            if (!$this->pairReady(array_values($siblings),$this->now())) throw new RuntimeException('virtual_reply_control_pair_unavailable');
            $payload=VirtualReplyControlProtocol::blindPayload($payload,$evidence,$this->decode($siblings['A']['result_json']),$this->decode($siblings['B']['result_json']));
        }
        return ['instructions'=>$contract['instructions'],'result_schema'=>$contract['schema'],'payload'=>$payload,'evidence_message_ids'=>$evidence,'profile'=>$contract['profile']];
    }

    public function save(string $jobId,string $role,string $token,int $adminId,array $result,array $metadata=[]): array
    {
        $this->outside();$this->required();$this->credentials($jobId,$role,$token,$adminId);$this->expire();
        $job=$this->job($jobId);$this->owned($job,$role,$token,$adminId,true);
        if (!$this->refresh($job['control_id'])) throw new RuntimeException('virtual_reply_control_source_changed');
        $case=$this->control($job['control_id']);$contract=$this->contract($job);$this->validateContract($contract,$role);
        $evidence=$this->decode($case['evidence_ids_json']);
        if ($role==='author') VirtualReplyProtocol::validate('author',$result,$evidence);else VirtualReplyControlProtocol::validate($result,$evidence);
        $canonical=$result; // Keep the exact decoded result, including object ordering.
        if ($role==='author'&&$canonical['history_incomplete']!==$this->decode($case['payload_json'])['history_incomplete']) throw new InvalidArgumentException('virtual_reply_control_history_mismatch');
        if (strlen(json_encode($canonical,JSON_THROW_ON_ERROR))>VirtualReplyControlProtocol::MAX_RESULT_BYTES) throw new InvalidArgumentException('invalid_virtual_reply_control_result');
        $technical=VirtualReplyControlProtocol::metadata($role,$metadata);$now=$this->now();
        return $this->transaction(function() use ($jobId,$role,$token,$adminId,$canonical,$technical,$now,$case):array {
            $current=$this->lockedCase($case['control_id']);
            if (!$current) return ['_source_changed'=>true];
            $job=$this->job($jobId,true);$this->owned($job,$role,$token,$adminId,true);
            $json=$this->encode($canonical);$metadataJson=$this->encode($technical);
            if ($job['status']==='completed') {
                if ($job['result_json']!==$json||$job['metadata_json']!==$metadataJson) throw new RuntimeException('virtual_reply_control_already_saved');
                return ['purpose'=>'control','case_id'=>$jobId,'role'=>$role,'status'=>'completed','duplicate'=>true];
            }
            if ($job['lease_expires_at']===null||$job['lease_expires_at']<=$now) return ['_expired'=>true];
            $siblings=$this->jobs($current['control_id'],true);
            if ($role==='reviewer'&&!$this->pairReady($siblings,$now)) throw new RuntimeException('virtual_reply_control_pair_unavailable');
            $this->execute('UPDATE virtual_reply_control_jobs SET status=?,result_json=?,result_sha256=?,metadata_json=?,completed_at=?,updated_at=? WHERE job_id=?',
                ['completed',$json,hash('sha256',$json),$metadataJson,$now,$now,$jobId]);
            if ($role==='author') {
                $siblings=$this->jobs($current['control_id'],true);$slots=$this->bySlot($siblings);
                if (($slots['A']['status']??null)==='completed'&&($slots['B']['status']??null)==='completed') {
                    $available=$this->date(max($this->at($slots['A']['completed_at']),$this->at($slots['B']['completed_at']))+self::REVIEW_DELAY);
                    $this->execute('UPDATE virtual_reply_control_jobs SET available_at=?,updated_at=? WHERE control_id=? AND role=? AND status=?',[$available,$now,$current['control_id'],'reviewer','pending']);
                }
            }
            return ['purpose'=>'control','case_id'=>$jobId,'role'=>$role,'status'=>'completed','duplicate'=>false];
        });
    }

    /** Aggregate-only view. Run reports revalidate all <=20 registered private cases. */
    public function status(?string $runId=null): array
    {
        $this->outside();
        if (!$this->enabled) return ['purpose'=>'control','status'=>'migration_unavailable'];
        $this->expire();
        if ($runId===null) {
            $recheck=$this->reconcile(100);$runs=[];
            foreach ($this->rows('SELECT r.run_id,r.created_at,COUNT(c.control_id) AS registered_cases FROM virtual_reply_control_runs r LEFT JOIN virtual_reply_control_cases c ON c.run_id=r.run_id WHERE r.project_key=? GROUP BY r.run_id,r.created_at ORDER BY r.created_at DESC,r.run_id DESC LIMIT 10',['anytour']) as $row) {
                $runs[]=['run_id'=>$row['run_id'],'created_at'=>$row['created_at'],'registered_cases'=>(int)$row['registered_cases']];
            }
            return ['purpose'=>'control','status'=>'enabled','runs'=>$runs,'reconciliation'=>$recheck,'quotas'=>$this->quotaStatus(),'sampling'=>$this->sampling->status()];
        }
        $this->id($runId);$run=$this->one('SELECT * FROM virtual_reply_control_runs WHERE run_id=? AND project_key=?',[$runId,'anytour']);
        if (!$run) throw new RuntimeException('virtual_reply_control_run_unavailable');
        $cases=$this->rows('SELECT * FROM virtual_reply_control_cases WHERE run_id=? ORDER BY control_id',[$runId]);
        if (count($cases)>20) throw new RuntimeException('virtual_reply_control_storage_unavailable');
        foreach ($cases as $case) if ($case['state']==='active') $this->refresh($case['control_id']);
        $rows=[];
        foreach ($this->rows('SELECT * FROM virtual_reply_control_cases WHERE run_id=? ORDER BY control_id',[$runId]) as $case) {
            $slots=$this->bySlot($this->jobs($case['control_id']));
            $rows[]=['case_id'=>$case['control_id'],'group_key'=>$case['group_key'],
                'author_statuses'=>['A'=>$slots['A']['status'],'B'=>$slots['B']['status']],'reviewer_status'=>$slots['review']['status'],
                'review'=>$slots['review']['result_json']===null?null:$this->decode($slots['review']['result_json']),
                'evidence_message_ids'=>$case['evidence_ids_json']===null?[]:$this->decode($case['evidence_ids_json']),
                'order'=>$case['order_json']===null?null:$this->decode($case['order_json'])];
        }
        return ['purpose'=>'control','status'=>'enabled','run_id'=>$runId,'registered_at'=>$run['created_at'],
            'baseline_revision'=>$this->decode($run['baseline_contract_json'])['instruction_revision'],
            'candidate_revision'=>$this->decode($run['candidate_contract_json'])['instruction_revision'],
            'reviewer_revision'=>$this->decode($run['reviewer_contract_json'])['instruction_revision'],
            'results'=>VirtualReplyControlProtocol::aggregate($rows),'quotas'=>$this->quotaStatus()];
    }

    public function reconcile(int $limit): array
    {
        $this->outside();$this->required();$limit=$this->limit($limit,100);$checked=0;$invalidated=0;
        foreach ($this->rows('SELECT control_id FROM virtual_reply_control_cases WHERE state=? ORDER BY checked_at,control_id LIMIT '.$limit,['active']) as $row) {
            $checked++;if (!$this->refresh($row['control_id'])) $invalidated++;
        }
        return ['checked'=>$checked,'invalidated'=>$invalidated];
    }

    /** Called before ordinary case-id replacement or source purge; no content is read. */
    public static function invalidateSource(PDO $pdo,string $ordinaryCaseId): void
    {
        $store=new self($pdo);
        if (!$store->available()) return;
        $store->id($ordinaryCaseId);
        $source=$store->one('SELECT source_kind,source_id FROM virtual_reply_cases WHERE project_key=? AND case_id=?',['anytour',$ordinaryCaseId]);
        if ($source!==null) $store->invalidateSourceScope($source['source_kind'],(int)$source['source_id']);
    }

    /** Call for target AND donor keys of a contaminated merge, before aliases disappear. */
    public static function invalidateGroup(PDO $pdo,string $groupKey): void
    {
        $store=new self($pdo);
        if (!$store->available()) return;
        $store->id($groupKey);
        $purge=function() use ($store,$groupKey):void {
            foreach ($store->rows('SELECT control_id FROM virtual_reply_control_cases WHERE group_key=? AND state=? ORDER BY control_id'.$store->lock(),[$groupKey,'active']) as $row) $store->purge($row['control_id']);
        };
        if ($pdo->inTransaction()) $purge();else $store->transaction($purge);
    }

    /** Existing transaction may own source locks; no nested transaction or quota refund. */
    private function invalidateSourceScope(string $source,int $sourceId): int
    {
        if (!$this->enabled) return 0;
        if (!in_array($source,['conversation','telegram_archive'],true)||$sourceId<1) throw new InvalidArgumentException('invalid_virtual_reply_control_source');
        $purge=function() use ($source,$sourceId):int {
            $count=0;
            foreach ($this->rows('SELECT control_id FROM virtual_reply_control_cases WHERE source_kind=? AND source_id=? AND state=? ORDER BY control_id'.$this->lock(),[$source,$sourceId,'active']) as $row) {
                $this->purge($row['control_id']);$count++;
            }
            return $count;
        };
        return $this->pdo->inTransaction()?$purge():$this->transaction($purge);
    }

    private function refresh(string $controlId): bool
    {
        $this->outside();$case=$this->control($controlId);
        if (!$case||$case['state']!=='active') return false;
        $snapshot=$this->snapshot($case['source_kind'],(int)$case['source_id']);
        return $this->transaction(function() use ($case,$snapshot):bool {
            // Canonical source -> sampling -> control order matches ordinary purge hooks.
            $source=$this->source($case['source_kind'],(int)$case['source_id'],true);
            $valid=$this->sourceMatches($source,$snapshot,$case['source_revision']);
            if ($valid) $this->sampling->attach($source,$snapshot);
            $group=$valid?$this->group($source['case_id'],true):null;
            $current=$this->control($case['control_id'],true);
            if (!$current||$current['state']!=='active') return false;
            if (!$valid||!$this->reserved($group)||$group['group_key']!==$current['group_key']
                ||$source['payload_json']!==$current['payload_json']||$source['evidence_ids_json']!==$current['evidence_ids_json']) {
                $this->purge($current['control_id']);return false;
            }
            $this->execute('UPDATE virtual_reply_control_cases SET checked_at=? WHERE control_id=?',[$this->now(),$current['control_id']]);
            return true;
        });
    }

    /** Source, cohort and revision fences inside the quota/save transaction. */
    private function lockedCase(string $controlId): ?array
    {
        $plain=$this->control($controlId);
        if (!$plain) return null;
        $source=$this->source($plain['source_kind'],(int)$plain['source_id'],true);
        $group=$source?$this->group($source['case_id'],true):null;
        $case=$this->control($controlId,true);
        if (!$case||$case['state']!=='active') return null;
        if (!$source||$source['state']!=='active'||$source['source_revision']!==$case['source_revision']
            ||!$this->reserved($group)||$group['group_key']!==$case['group_key']
            ||$source['payload_json']!==$case['payload_json']||$source['evidence_ids_json']!==$case['evidence_ids_json']) {
            $this->purge($controlId);return null;
        }
        return $case;
    }

    private function purge(string $controlId): void
    {
        $now=$this->now();
        $this->execute('UPDATE virtual_reply_control_cases SET state=?,payload_json=NULL,evidence_ids_json=NULL,order_json=NULL,checked_at=?,updated_at=? WHERE control_id=?',['invalidated',$now,$now,$controlId]);
        $this->execute('UPDATE virtual_reply_control_jobs SET status=?,claim_token_sha256=NULL,claimed_by=NULL,lease_expires_at=NULL,result_json=NULL,result_sha256=NULL,metadata_json=NULL,completed_at=NULL,updated_at=? WHERE control_id=?',['invalidated',$now,$controlId]);
    }

    private function snapshot(string $source,int $id): ?array
    {
        $this->outside();$value=$this->reader->snapshot($source,$id);
        if ($value===null) return null;
        if (!is_array($value)||($value['project_key']??null)!=='anytour'||($value['source_kind']??null)!==$source||($value['source_id']??null)!==$id
            ||!is_string($value['source_revision']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$value['source_revision'])!==1
            ||!is_array($value['payload']??null)||!is_bool($value['payload']['history_incomplete']??null)
            ||!is_array($value['evidence_message_ids']??null)||!array_is_list($value['evidence_message_ids'])||count($value['evidence_message_ids'])>120
            ||strlen($this->encode($value['payload']))>VirtualReplyControlProtocol::MAX_PAYLOAD_BYTES) throw new RuntimeException('virtual_reply_control_source_unavailable');
        $seen=[];foreach ($value['evidence_message_ids'] as $messageId) {
            if (!is_int($messageId)||$messageId<1||isset($seen[$messageId])) throw new RuntimeException('virtual_reply_control_source_unavailable');$seen[$messageId]=true;
        }
        return $value;
    }

    private function sourceMatches(?array $source,?array $snapshot,string $revision): bool
    {
        return $source!==null&&$snapshot!==null&&$source['state']==='active'&&$source['source_revision']===$revision&&$snapshot['source_revision']===$revision
            &&$source['payload_json']===$this->encode($snapshot['payload'])&&$source['evidence_ids_json']===$this->encode($snapshot['evidence_message_ids']);
    }
    private function reserved(?array $group): bool {return $group!==null&&$group['cohort']==='reserved'&&$group['exposed_at']===null&&(int)$group['legacy']===0&&(int)$group['identity_complete']===1;}
    private function group(string $caseId,bool $lock=false): ?array {return $this->one('SELECT s.group_key,s.legacy,s.identity_complete,g.cohort,g.exposed_at FROM virtual_reply_case_sampling s JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key WHERE s.case_id=?'.($lock?$this->lock():''),[$caseId]);}
    private function source(string $source,int $id,bool $lock=false): ?array {return $this->one('SELECT * FROM virtual_reply_cases WHERE project_key=? AND source_kind=? AND source_id=?'.($lock?$this->lock():''),['anytour',$source,$id]);}
    private function control(string $id,bool $lock=false): ?array {return $this->one('SELECT * FROM virtual_reply_control_cases WHERE control_id=?'.($lock?$this->lock():''),[$id]);}
    private function job(string $id,bool $lock=false): ?array {return $this->one('SELECT j.* FROM virtual_reply_control_jobs j JOIN virtual_reply_control_cases c ON c.control_id=j.control_id JOIN virtual_reply_control_runs r ON r.run_id=c.run_id WHERE j.job_id=? AND r.project_key=?'.($lock?$this->lock():''),[$id,'anytour']);}
    private function jobs(string $id,bool $lock=false): array {return $this->rows('SELECT * FROM virtual_reply_control_jobs WHERE control_id=? ORDER BY slot'.($lock?$this->lock():''),[$id]);}
    private function bySlot(array $rows): array {$slots=[];foreach ($rows as $row) $slots[$row['slot']]=$row;return $slots;}
    private function pairReady(array $rows,string $now): bool {
        $slots=$this->bySlot($rows);
        foreach (['A','B'] as $slot) if (($slots[$slot]['status']??null)!=='completed'||!is_string($slots[$slot]['completed_at']??null)||!is_string($slots[$slot]['result_json']??null)||$this->at($slots[$slot]['completed_at'])+self::REVIEW_DELAY>$this->at($now)) return false;
        return true;
    }
    private function contract(array $job): array {return $this->decode($job['contract_json']);}
    private function validateContract(array $contract,string $role): void {if ($role==='author') VirtualReplyControlProtocol::validateAuthorContract($contract);else VirtualReplyControlProtocol::validateReviewerContract($contract);}
    private function supported(array $job): bool {try {$this->validateContract($this->contract($job),$job['role']);return true;}catch (InvalidArgumentException $ignored) {return false;}}
    private function owned(?array $job,string $role,string $token,int $adminId,bool $completed): void {
        if (!$job||$job['role']!==$role||!in_array($job['status'],$completed?['running','completed']:['running'],true)||(int)$job['claimed_by']!==$adminId
            ||!is_string($job['claim_token_sha256'])||!hash_equals($job['claim_token_sha256'],hash('sha256',$token))) throw new RuntimeException('virtual_reply_control_claim_unavailable');
    }
    private function credentials(string $id,string $role,string $token,int $adminId): void {$this->id($id);$this->role($role);$this->admin($adminId);$this->id($token);}
    private function expire(): int {return $this->execute('UPDATE virtual_reply_control_jobs SET status=?,updated_at=? WHERE status=? AND lease_expires_at<=?',['unknown',$this->now(),'running',$this->now()]);}
    private function quotaStatus(): array {$day=substr($this->now(),0,10);return ['day_utc'=>$day,'limit_per_role'=>self::DAILY_LIMIT,'author_used'=>$this->used('author',$day),'reviewer_used'=>$this->used('reviewer',$day)];}
    private function used(string $role,string $day): int {$row=$this->one('SELECT used_count FROM virtual_reply_quotas WHERE project_key=? AND day_utc=? AND role=?',['anytour',$day,$role]);return $row?(int)$row['used_count']:0;}
    private function required(): void {if (!$this->enabled) throw new RuntimeException('virtual_reply_control_migration_unavailable');}
    private function outside(): void {if ($this->pdo->inTransaction()) throw new RuntimeException('virtual_reply_control_source_recheck_in_transaction');}
    private function id(string $id): void {if (preg_match('/\A[a-f0-9]{64}\z/D',$id)!==1) throw new InvalidArgumentException('invalid_virtual_reply_control_id');}
    private function admin(int $id): void {if ($id<1) throw new InvalidArgumentException('invalid_virtual_reply_control_admin');}
    private function role(string $role): void {VirtualReplyControlProtocol::profile($role);}
    private function limit(int $limit,int $maximum): int {if ($limit<1) throw new InvalidArgumentException('invalid_virtual_reply_control_limit');return min($maximum,$limit);}
    private function newId(): string {return bin2hex(random_bytes(32));}
    private function now(): string {return $this->date((int)($this->clock)());}
    private function date(int $at): string {return gmdate('Y-m-d H:i:s',$at);}
    private function at(string $at): int {return (int)strtotime($at.' UTC');}
    private function lock(): string {return $this->driver==='mysql'?' FOR UPDATE':'';}
    private function encode(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function decode(mixed $value): array {if (!is_string($value)) throw new RuntimeException('virtual_reply_control_storage_unavailable');$decoded=json_decode($value,true,64,JSON_THROW_ON_ERROR);if (!is_array($decoded)) throw new RuntimeException('virtual_reply_control_storage_unavailable');return $decoded;}
    private function rows(string $sql,array $args=[]): array {$q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $args=[]): ?array {$q=$this->pdo->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;}
    private function execute(string $sql,array $args=[]): int {$q=$this->pdo->prepare($sql);$q->execute($args);return $q->rowCount();}
    private function insertIgnore(string $table,array $values): void {$this->execute(($this->driver==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE').' INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));}
    private function transaction(callable $fn): mixed {
        if ($this->pdo->inTransaction()) throw new RuntimeException('virtual_reply_control_transaction_conflict');
        $this->pdo->beginTransaction();
        try {$result=$fn();$this->pdo->commit();}catch (Throwable $error) {if ($this->pdo->inTransaction()) $this->pdo->rollBack();throw $error;}
        if (is_array($result)&&isset($result['_expired'])) {$this->expire();throw new RuntimeException('virtual_reply_control_claim_expired');}
        if (is_array($result)&&isset($result['_source_changed'])) throw new RuntimeException('virtual_reply_control_source_changed');
        return $result;
    }
}
