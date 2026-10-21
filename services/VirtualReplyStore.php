<?php

declare(strict_types=1);

require_once __DIR__.'/VirtualReplyProtocol.php';
require_once __DIR__.'/VirtualReplySourceReader.php';

/** Private two-pass lifecycle. No schema creation, provider calls or message delivery. */
final class VirtualReplyStore
{
    public const DAILY_LIMIT = 200;
    public const REVIEW_DELAY_SECONDS = 60;
    private const RECENT_WINDOW_SECONDS = 172800;
    private PDO $pdo;
    private object $reader;
    private $clock;
    private string $driver;

    public function __construct(PDO $pdo, $reader=null, $clock=null)
    {
        $this->pdo=$pdo;
        $this->reader=$reader ?? new VirtualReplySourceReader($pdo);
        $this->clock=$clock ?? static fn(): int=>time();
        $this->driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver,['mysql','sqlite'],true) || !is_callable($this->clock)) {
            throw new InvalidArgumentException('invalid_virtual_reply_dependencies');
        }
    }

    public function capture(string $source,string $lane,int $limit): array
    {
        $this->outsideTransaction();
        $this->source($source);
        if (!in_array($lane,['fresh','history'],true)) throw new InvalidArgumentException('invalid_virtual_reply_lane');
        $limit=$this->limit($limit,100);
        $cursor=$this->cursor($source,$lane);
        $scan=$cursor===null && $lane==='fresh' ? $this->reader->recent($source,$limit)
            : $this->reader->scan($source,$cursor ?? 0,$limit);
        if (!is_array($scan) || !is_array($scan['source_ids'] ?? null) || !is_int($scan['last_id'] ?? null)) {
            throw new RuntimeException('virtual_reply_source_unavailable');
        }
        $result=['source'=>$source,'lane'=>$lane,'scanned'=>0,'captured'=>0,'unchanged'=>0,'invalidated'=>0,
            'cursor'=>$cursor ?? 0,'has_more'=>(bool)($scan['has_more'] ?? false)];
        foreach (array_slice($scan['source_ids'],0,$limit) as $id) {
            if (!is_int($id) || $id<1) throw new RuntimeException('virtual_reply_source_unavailable');
            $result['scanned']++;
            $snapshot=$this->snapshot($source,$id);
            if ($snapshot===null) {
                $old=$this->bySource($source,$id);
                if ($old!==null && $this->invalidate($old)) $result['invalidated']++;
                continue;
            }
            $stored=$this->storeSnapshot($snapshot);
            $result[$stored['captured']?'captured':'unchanged']++;
            $result['invalidated']+=(int)$stored['invalidated'];
        }
        $result['cursor']=$this->advanceCursor($source,$lane,max(0,$scan['last_id']));
        return $result;
    }

    /** Bounded rotation also revisits invalidated sources when permission returns. */
    public function reconcile(int $limit): array
    {
        $this->outsideTransaction();
        $limit=$this->limit($limit,100);
        $result=['checked'=>0,'invalidated'=>0,'recaptured'=>0,'expired'=>$this->expire()];
        $cursor=$this->cursor('_all','reconcile') ?? 0;
        $rows=$this->rows('SELECT * FROM virtual_reply_cases WHERE project_key=? AND id>? ORDER BY id LIMIT '.$limit,['anytour',$cursor]);
        if (!$rows && $cursor>0) $rows=$this->rows('SELECT * FROM virtual_reply_cases WHERE project_key=? ORDER BY id LIMIT '.$limit,['anytour']);
        foreach ($rows as $row) {
            $result['checked']++;
            $snapshot=$this->snapshot($row['source_kind'],(int)$row['source_id']);
            if ($snapshot===null) $result['invalidated']+=(int)$this->invalidate($row);
            else {
                $stored=$this->storeSnapshot($snapshot);
                $result['invalidated']+=(int)$stored['invalidated'];
                $result['recaptured']+=(int)$stored['captured'];
            }
            $cursor=(int)$row['id'];
        }
        // Rotation may intentionally move backwards after wrapping.
        $this->advanceCursor('_all','reconcile',$rows?$cursor:0,false);
        return $result;
    }

    public function claim(string $role,int $adminId,int $limit): array
    {
        $this->outsideTransaction();
        VirtualReplyProtocol::profile($role);
        $this->admin($adminId);
        $limit=$this->limit($limit,20);
        $this->expire();
        $now=$this->now(); $day=substr($now,0,10); $jobs=[];$unsupported=0;
        $recent=gmdate('Y-m-d\TH:i:s\Z',$this->at($now)-self::RECENT_WINDOW_SECONDS);
        $candidates=$this->rows('SELECT c.* FROM virtual_reply_cases c JOIN virtual_reply_jobs j ON j.case_id=c.case_id WHERE c.project_key=? AND c.state=? AND j.role=? AND j.status=? AND j.available_at<=? ORDER BY CASE WHEN c.reference_time>=? THEN 0 ELSE 1 END,j.available_at,c.id LIMIT 100',
            ['anytour','active',$role,'pending',$now,$recent]);
        foreach ($candidates as $candidate) {
            if (count($jobs)>=$limit) break;
            $fresh=$this->refresh($candidate);
            if ($fresh===null) continue;
            $contract=$this->job($fresh['case_id'],$role);
            if (!$contract||!$this->supportedContract($contract,$role)) {$unsupported++;continue;}
            $token=bin2hex(random_bytes(32));
            $claimed=$this->transaction(function() use ($fresh,$role,$adminId,$token,$now,$day): ?array {
                $this->insertIgnore('virtual_reply_quotas',['project_key'=>'anytour','day_utc'=>$day,'role'=>$role,'used_count'=>0,'updated_at'=>$now]);
                $quota=$this->one('SELECT used_count FROM virtual_reply_quotas WHERE project_key=? AND day_utc=? AND role=?'.$this->lock(),['anytour',$day,$role]);
                if (!$quota || (int)$quota['used_count']>=self::DAILY_LIMIT) return null;
                $case=$this->one('SELECT * FROM virtual_reply_cases WHERE case_id=? AND project_key=?'.$this->lock(),[$fresh['case_id'],'anytour']);
                $job=$this->job($fresh['case_id'],$role,true);
                if (!$case || $case['state']!=='active' || $case['source_revision']!==$fresh['source_revision']
                    || !$job || $job['status']!=='pending' || $job['available_at']>$now) return null;
                if ($role==='reviewer') {
                    $author=$this->job($fresh['case_id'],'author',true);
                    if (!$author || $author['status']!=='completed' || $author['completed_at']===null
                        || $this->at((string)$author['completed_at'])+self::REVIEW_DELAY_SECONDS>$this->at($now)) return null;
                }
                $expires=$this->date($this->at($now)+($role==='author'?900:1800));
                $this->execute('UPDATE virtual_reply_jobs SET status=?,claim_token_sha256=?,claimed_by=?,lease_expires_at=?,claimed_at=?,updated_at=? WHERE case_id=? AND role=? AND status=?',
                    ['running',hash('sha256',$token),$adminId,$expires,$now,$now,$fresh['case_id'],$role,'pending']);
                $this->execute('UPDATE virtual_reply_quotas SET used_count=used_count+1,updated_at=? WHERE project_key=? AND day_utc=? AND role=?',[$now,'anytour',$day,$role]);
                return ['case_id'=>$fresh['case_id'],'role'=>$role,'claim_token'=>$token,'lease_expires_at'=>$expires,
                    'profile'=>$this->decode($job['profile_json']),'instruction_revision'=>$job['instruction_revision']];
            });
            if ($claimed!==null) $jobs[]=$claimed;
            elseif ($this->used($role,$day)>=self::DAILY_LIMIT) break;
        }
        return ['role'=>$role,'jobs'=>$jobs,'daily_limit'=>self::DAILY_LIMIT,'daily_used'=>$this->used($role,$day),'unsupported_contracts'=>$unsupported];
    }

    public function context(string $caseId,string $role,string $token,int $adminId): array
    {
        $this->outsideTransaction();
        $this->credentials($caseId,$role,$token,$adminId);
        $case=$this->freshCase($caseId);
        $this->expire();
        $job=$this->job($caseId,$role);
        $this->ownedJob($job,$token,$adminId,false);
        if (!$this->supportedContract($job,$role)) throw new RuntimeException('virtual_reply_contract_unavailable');
        $payload=$this->decode($case['payload_json']);
        if ($role==='reviewer') {
            $author=$this->job($caseId,'author');
            if (!$author || $author['status']!=='completed' || $author['result_json']===null) throw new RuntimeException('virtual_reply_author_unavailable');
            $payload['author_result']=$this->decode($author['result_json']);
        }
        return ['case_id'=>$caseId,'role'=>$role,'profile'=>$this->decode($job['profile_json']),
            'instructions'=>$job['instructions'],'instruction_revision'=>$job['instruction_revision'],
            'result_schema'=>$this->decode($job['schema_json']),
            'payload'=>$payload,'evidence_message_ids'=>$this->decode($case['evidence_ids_json']),
            'source'=>$this->sourceMetadata($case)];
    }

    public function save(string $caseId,string $role,string $token,int $adminId,array $result,array $metadata=[]): array
    {
        $this->outsideTransaction();
        $this->credentials($caseId,$role,$token,$adminId);
        $case=$this->freshCase($caseId);
        $contract=$this->job($caseId,$role);
        if (!$contract||!$this->supportedContract($contract,$role)) throw new RuntimeException('virtual_reply_contract_unavailable');
        $canonical=VirtualReplyProtocol::validate($role,$result,$this->decode($case['evidence_ids_json']));
        if ($role==='author' && $canonical['history_incomplete']!==($this->decode($case['payload_json'])['history_incomplete'] ?? null)) {
            throw new InvalidArgumentException('virtual_reply_history_mismatch');
        }
        $now=$this->now();
        return $this->transaction(function() use ($caseId,$case,$role,$token,$adminId,$canonical,$metadata,$now): array {
            $current=$this->one('SELECT * FROM virtual_reply_cases WHERE case_id=? AND project_key=?'.$this->lock(),[$caseId,'anytour']);
            if (!$current || $current['state']!=='active' || $current['source_revision']!==$case['source_revision']) throw new RuntimeException('virtual_reply_source_changed');
            $job=$this->job($caseId,$role,true);
            $this->ownedJob($job,$token,$adminId,true);
            $technical=$this->metadata($metadata,$this->decode($job['profile_json']));
            $json=$this->encode($canonical); $technicalJson=$this->encode($technical);
            if ($job['status']==='completed') {
                if ($job['result_json']!==$json || $job['metadata_json']!==$technicalJson) throw new RuntimeException('virtual_reply_already_saved');
                return ['case_id'=>$caseId,'role'=>$role,'status'=>'completed','duplicate'=>true];
            }
            // Expiration is persisted outside this transaction by expire(); a
            // late save also commits unknown before reporting its error below.
            if ($job['lease_expires_at']===null || $job['lease_expires_at']<=$now) return ['_expired'=>true];
            $this->execute('UPDATE virtual_reply_jobs SET status=?,result_json=?,result_sha256=?,metadata_json=?,completed_at=?,updated_at=? WHERE case_id=? AND role=?',
                ['completed',$json,hash('sha256',$json),$technicalJson,$now,$now,$caseId,$role]);
            if ($role==='author') {
                $reviewer=$this->job($caseId,'reviewer',true);
                if (!$reviewer) throw new RuntimeException('virtual_reply_reviewer_contract_missing');
                $this->execute('UPDATE virtual_reply_jobs SET available_at=?,updated_at=? WHERE case_id=? AND role=? AND status=?',
                    [$this->date($this->at($now)+self::REVIEW_DELAY_SECONDS),$now,$caseId,'reviewer','pending']);
            }
            return ['case_id'=>$caseId,'role'=>$role,'status'=>'completed','duplicate'=>false];
        });
    }

    public function reviews(int $limit): array
    {
        $this->outsideTransaction();
        $limit=$this->limit($limit,100);
        $rows=$this->rows('SELECT c.* FROM virtual_reply_cases c JOIN virtual_reply_jobs j ON j.case_id=c.case_id WHERE c.project_key=? AND c.state=? AND j.role=? AND j.status=? ORDER BY CASE WHEN c.lesson_state=\'unresolved\' THEN 0 ELSE 1 END,j.completed_at DESC,c.id DESC LIMIT '.($limit+1),['anytour','active','reviewer','completed']);
        $reviews=[];
        foreach (array_slice($rows,0,$limit) as $row) {
            $fresh=$this->refresh($row);
            if ($fresh===null) continue;
            $author=$this->job($fresh['case_id'],'author'); $reviewer=$this->job($fresh['case_id'],'reviewer');
            if (!$author || !$reviewer || $reviewer['status']!=='completed' || $reviewer['result_json']===null) continue;
            $result=$this->decode($reviewer['result_json']);
            $reviews[]=['case_id'=>$fresh['case_id'],'source_kind'=>$fresh['source_kind'],'source_id'=>(int)$fresh['source_id'],
                'mode'=>$fresh['mode'],'reference_time'=>$fresh['reference_time'],'author_completed_at'=>$author['completed_at'],
                'review_completed_at'=>$reviewer['completed_at'],'verdict'=>$result['verdict'],
                'disposition'=>['status'=>$fresh['lesson_state'],'updated_at'=>$fresh['lesson_updated_at']]];
        }
        return ['reviews'=>$reviews,'has_more'=>count($rows)>$limit];
    }

    public function review(string $caseId): array
    {
        $this->outsideTransaction();
        $this->caseId($caseId);
        $case=$this->freshCase($caseId);
        $author=$this->job($caseId,'author'); $reviewer=$this->job($caseId,'reviewer');
        if (!$author || !$reviewer || $author['status']!=='completed' || $reviewer['status']!=='completed'
            || $author['result_json']===null || $reviewer['result_json']===null) throw new RuntimeException('virtual_reply_review_unavailable');
        return ['case_id'=>$caseId,'source'=>$this->sourceMetadata($case),'payload'=>$this->decode($case['payload_json']),
            'author_result'=>$this->decode($author['result_json']),'review_result'=>$this->decode($reviewer['result_json']),
            'author_metadata'=>$this->decode($author['metadata_json']),'review_metadata'=>$this->decode($reviewer['metadata_json']),
            'contracts'=>['author'=>$this->contractMetadata($author),'reviewer'=>$this->contractMetadata($reviewer)],
            'disposition'=>$case['lesson_decision_json']===null?['status'=>'unresolved']:$this->decode($case['lesson_decision_json'])];
    }

    /** Records an owner's resolution only; never edits instructions or approves code. */
    public function disposition(string $caseId,array $decision,int $adminId): array
    {
        $this->outsideTransaction();
        $this->caseId($caseId); $this->admin($adminId);
        if (array_is_list($decision) || count($decision)!==4
            || array_diff(array_keys($decision),['status','reason','change_url','validation'])!==[]
            || !in_array($decision['status'] ?? null,['rejected','duplicate','applied'],true)
            || !$this->boundedText($decision['reason'] ?? null,1000)
            || !$this->boundedText($decision['validation'] ?? null,2000)) throw new InvalidArgumentException('invalid_virtual_reply_disposition');
        $url=$decision['change_url'];
        if (($url!==null && (!is_string($url) || strlen($url)>200 || !preg_match('~\Ahttps://github\.com/pyatkoff/max-search-bot/pull/[1-9][0-9]*\z~D',$url)))
            || ($decision['status']==='applied' && $url===null)
            || ($decision['status']==='rejected' && $url!==null)) throw new InvalidArgumentException('invalid_virtual_reply_disposition');
        $canonical=['status'=>$decision['status'],'reason'=>$decision['reason'],'change_url'=>$url,'validation'=>$decision['validation']];
        $case=$this->freshCase($caseId); $now=$this->now();
        return $this->transaction(function() use ($caseId,$case,$canonical,$adminId,$now): array {
            $current=$this->one('SELECT * FROM virtual_reply_cases WHERE case_id=? AND project_key=?'.$this->lock(),[$caseId,'anytour']);
            $reviewer=$this->job($caseId,'reviewer',true);
            if (!$current || $current['state']!=='active' || $current['source_revision']!==$case['source_revision']
                || !$reviewer || $reviewer['status']!=='completed') throw new RuntimeException('virtual_reply_review_unavailable');
            if ($current['lesson_decision_json']!==null) {
                $stored=$this->decode($current['lesson_decision_json']);
                $comparison=$stored;unset($comparison['admin_id'],$comparison['recorded_at']);
                if ($comparison!==$canonical) throw new RuntimeException('virtual_reply_already_disposed');
                return ['case_id'=>$caseId,'disposition'=>$stored,'duplicate'=>true];
            }
            $stored=$canonical+['admin_id'=>$adminId,'recorded_at'=>$now];
            $this->execute('UPDATE virtual_reply_cases SET lesson_state=?,lesson_decision_json=?,lesson_updated_at=?,updated_at=? WHERE case_id=?',
                [$canonical['status'],$this->encode($stored),$now,$now,$caseId]);
            return ['case_id'=>$caseId,'disposition'=>$stored,'duplicate'=>false];
        });
    }

    private function freshCase(string $caseId): array
    {
        $row=$this->one('SELECT * FROM virtual_reply_cases WHERE case_id=? AND project_key=?',[$caseId,'anytour']);
        if (!$row || $row['state']!=='active') throw new RuntimeException('virtual_reply_case_unavailable');
        $fresh=$this->refresh($row);
        if ($fresh===null) throw new RuntimeException('virtual_reply_source_changed');
        return $fresh;
    }

    private function refresh(array $row): ?array
    {
        $snapshot=$this->snapshot($row['source_kind'],(int)$row['source_id']);
        if ($snapshot===null) {$this->invalidate($row);return null;}
        if ($snapshot['source_revision']!==$row['source_revision'] || $row['state']!=='active' || !$this->retainContracts($row['case_id'])) {
            $this->storeSnapshot($snapshot);return null;
        }
        $current=$this->one('SELECT * FROM virtual_reply_cases WHERE case_id=? AND project_key=?',[$row['case_id'],'anytour']);
        return $current && $current['state']==='active' && $current['source_revision']===$snapshot['source_revision'] ? $current : null;
    }

    private function snapshot(string $source,int $sourceId): ?array
    {
        $this->outsideTransaction();
        $value=$this->reader->snapshot($source,$sourceId);
        if ($value===null) return null;
        if (!is_array($value) || ($value['project_key'] ?? null)!=='anytour' || ($value['source_kind'] ?? null)!==$source
            || ($value['source_id'] ?? null)!==$sourceId || !is_string($value['source_revision'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/',$value['source_revision']) || !is_array($value['payload'] ?? null)
            || !is_bool($value['payload']['history_incomplete'] ?? null) || !is_array($value['evidence_message_ids'] ?? null)
            || !array_is_list($value['evidence_message_ids']) || count($value['evidence_message_ids'])>120
            || !in_array($value['mode'] ?? null,['live','historical_replay'],true)
            || !is_string($value['reference_time'] ?? null) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',$value['reference_time'])) {
            throw new RuntimeException('virtual_reply_source_unavailable');
        }
        $seen=[];
        foreach ($value['evidence_message_ids'] as $id) {
            if (!is_int($id) || $id<1 || isset($seen[$id])) throw new RuntimeException('virtual_reply_source_unavailable');
            $seen[$id]=true;
        }
        if (strlen($this->encode($value['payload']))>196608) throw new RuntimeException('virtual_reply_source_too_large');
        return $value;
    }

    /** One current source row; replacement destroys every previous private result. */
    private function storeSnapshot(array $snapshot): array
    {
        $now=$this->now();
        return $this->transaction(function() use ($snapshot,$now): array {
            $this->insertIgnore('virtual_reply_cases',['case_id'=>bin2hex(random_bytes(32)),'project_key'=>'anytour',
                'source_kind'=>$snapshot['source_kind'],'source_id'=>$snapshot['source_id'],'state'=>'invalidated','created_at'=>$now,'updated_at'=>$now]);
            $old=$this->one('SELECT * FROM virtual_reply_cases WHERE project_key=? AND source_kind=? AND source_id=?'.$this->lock(),['anytour',$snapshot['source_kind'],$snapshot['source_id']]);
            if (!$old) throw new RuntimeException('virtual_reply_storage_unavailable');
            if ($old['state']==='active' && $old['source_revision']===$snapshot['source_revision'] && $this->retainContracts($old['case_id'])) return ['captured'=>false,'invalidated'=>false,'case_id'=>$old['case_id']];
            $caseId=bin2hex(random_bytes(32));
            $this->execute('DELETE FROM virtual_reply_jobs WHERE case_id=?',[$old['case_id']]);
            $this->execute('UPDATE virtual_reply_cases SET case_id=?,source_revision=?,state=?,payload_json=?,evidence_ids_json=?,mode=?,reference_time=?,lesson_state=\'unresolved\',lesson_decision_json=NULL,lesson_updated_at=NULL,updated_at=? WHERE id=?',
                [$caseId,$snapshot['source_revision'],'active',$this->encode($snapshot['payload']),$this->encode($snapshot['evidence_message_ids']),$snapshot['mode'],$snapshot['reference_time'],$now,$old['id']]);
            foreach (['author','reviewer'] as $role) {
                // The reviewer contract is frozen now, while its availability
                // stays far in the future until a completed author is saved.
                $available=$role==='author'?$now:'9999-12-31 23:59:59';
                $this->execute('INSERT INTO virtual_reply_jobs (case_id,role,status,available_at,profile_json,schema_json,instructions,instruction_revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$caseId,$role,'pending',$available,$this->encode(VirtualReplyProtocol::profile($role)),$this->encode(VirtualReplyProtocol::schema($role)),VirtualReplyProtocol::instructions($role),VirtualReplyProtocol::revision($role),$now,$now]);
            }
            return ['captured'=>true,'invalidated'=>$old['state']==='active','case_id'=>$caseId];
        });
    }

    private function invalidate(array $row): bool
    {
        return $this->transaction(function() use ($row): bool {
            $current=$this->one('SELECT * FROM virtual_reply_cases WHERE case_id=? AND project_key=?'.$this->lock(),[$row['case_id'],'anytour']);
            if (!$current || $current['state']!=='active') return false;
            $now=$this->now();
            $this->execute('UPDATE virtual_reply_cases SET state=?,payload_json=NULL,evidence_ids_json=NULL,lesson_state=\'unresolved\',lesson_decision_json=NULL,lesson_updated_at=NULL,updated_at=? WHERE case_id=?',['invalidated',$now,$row['case_id']]);
            $this->execute('UPDATE virtual_reply_jobs SET status=?,claim_token_sha256=NULL,claimed_by=NULL,lease_expires_at=NULL,result_json=NULL,result_sha256=NULL,metadata_json=NULL,updated_at=? WHERE case_id=?',['invalidated',$now,$row['case_id']]);
            return true;
        });
    }

    private function metadata(array $metadata,array $profile): array
    {
        if (strlen($this->encode($metadata))>1024 || array_diff(array_keys($metadata),['model','reasoning_effort','service_tier'])!==[]) throw new InvalidArgumentException('invalid_virtual_reply_metadata');
        $expected=['model'=>$profile['model'],'reasoning_effort'=>$profile['reasoning']['effort'],'service_tier'=>$profile['service_tier']];
        $declared=[];
        foreach ($expected as $key=>$value) {
            if (!array_key_exists($key,$metadata)) continue;
            if (!is_string($metadata[$key]) || ($metadata[$key]!==$value
                && !($key==='service_tier'&&$metadata[$key]==='unknown'))) throw new InvalidArgumentException('virtual_reply_profile_mismatch');
            $declared[$key]=$metadata[$key];
        }
        return ['profile_source'=>'worker_declared','provider_verified'=>false,'requested_profile'=>$profile,'declared_profile'=>$declared];
    }

    private function ownedJob(?array $job,string $token,int $adminId,bool $allowCompleted): void
    {
        if (!$job || !in_array($job['status'],$allowCompleted?['running','completed']:['running'],true)
            || (int)$job['claimed_by']!==$adminId || !is_string($job['claim_token_sha256'])
            || !hash_equals($job['claim_token_sha256'],hash('sha256',$token))) throw new RuntimeException('virtual_reply_claim_unavailable');
    }

    private function expire(): int
    {
        return $this->execute('UPDATE virtual_reply_jobs SET status=?,updated_at=? WHERE status=? AND lease_expires_at<=?',
            ['unknown',$this->now(),'running',$this->now()]);
    }

    private function credentials(string $caseId,string $role,string $token,int $adminId): void
    {
        $this->caseId($caseId); VirtualReplyProtocol::profile($role); $this->admin($adminId);
        if (!preg_match('/\A[a-f0-9]{64}\z/',$token)) throw new InvalidArgumentException('invalid_virtual_reply_token');
    }
    private function currentContracts(string $caseId): bool {
        foreach (['author','reviewer'] as $role) {
            $job=$this->job($caseId,$role);
            if (!$job || $job['instruction_revision']!==VirtualReplyProtocol::revision($role)) return false;
        }
        return true;
    }
    private function retainContracts(string $caseId): bool {
        $author=$this->job($caseId,'author');
        if (!$author) return false;
        // A claimed attempt and its evidence are immutable even after new rules.
        // Only untouched pending work can be rebuilt under the current contract.
        if ($author['status']!=='pending'||$author['claimed_at']!==null) return true;
        return $this->currentContracts($caseId);
    }
    private function supportedContract(array $job,string $role): bool {
        return $this->decode($job['profile_json'])===VirtualReplyProtocol::profile($role)
            &&$this->decode($job['schema_json'])===VirtualReplyProtocol::schema($role);
    }
    private function contractMetadata(array $job): array {
        return ['profile'=>$this->decode($job['profile_json']),'schema'=>$this->decode($job['schema_json']),
            'instructions'=>$job['instructions'],'instruction_revision'=>$job['instruction_revision']];
    }
    private function outsideTransaction(): void {if ($this->pdo->inTransaction()) throw new RuntimeException('virtual_reply_source_recheck_in_transaction');}
    private function boundedText($value,int $maximum): bool {
        if (!is_string($value) || trim($value)==='' || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)) return false;
        $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);
        return is_int($length) && $length<=$maximum;
    }
    private function admin(int $id): void {if ($id<1) throw new InvalidArgumentException('invalid_virtual_reply_admin');}
    private function caseId(string $id): void {if (!preg_match('/\A[a-f0-9]{64}\z/',$id)) throw new InvalidArgumentException('invalid_virtual_reply_case');}
    private function source(string $source): void {if (!in_array($source,['conversation','telegram_archive'],true)) throw new InvalidArgumentException('invalid_virtual_reply_source');}
    private function limit(int $limit,int $maximum): int {if ($limit<1) throw new InvalidArgumentException('invalid_virtual_reply_limit');return min($maximum,$limit);}
    private function now(): string {return $this->date((int)($this->clock)());}
    private function date(int $at): string {return gmdate('Y-m-d H:i:s',$at);}
    private function at(string $at): int {return (int)strtotime($at.' UTC');}
    private function sourceMetadata(array $case): array {return ['kind'=>$case['source_kind'],'id'=>(int)$case['source_id'],'mode'=>$case['mode'],'reference_time'=>$case['reference_time']];}
    private function encode(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function decode($value): array {
        if (!is_string($value)) throw new RuntimeException('virtual_reply_storage_unavailable');
        $decoded=json_decode($value,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('virtual_reply_storage_unavailable');return $decoded;
    }
    private function lock(): string {return $this->driver==='mysql'?' FOR UPDATE':'';}
    private function bySource(string $source,int $id): ?array {return $this->one('SELECT * FROM virtual_reply_cases WHERE project_key=? AND source_kind=? AND source_id=?',['anytour',$source,$id]);}
    private function job(string $caseId,string $role,bool $lock=false): ?array {return $this->one('SELECT * FROM virtual_reply_jobs WHERE case_id=? AND role=?'.($lock?$this->lock():''),[$caseId,$role]);}
    private function used(string $role,string $day): int {$q=$this->one('SELECT used_count FROM virtual_reply_quotas WHERE project_key=? AND day_utc=? AND role=?',['anytour',$day,$role]);return $q?(int)$q['used_count']:0;}
    private function rows(string $sql,array $args=[]): array {$q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $args=[]): ?array {$q=$this->pdo->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;}
    private function execute(string $sql,array $args=[]): int {$q=$this->pdo->prepare($sql);$q->execute($args);return $q->rowCount();}
    private function insertIgnore(string $table,array $values): void {
        $this->execute(($this->driver==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE').' INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
    }
    private function cursor(string $source,string $lane): ?int {$row=$this->one('SELECT last_id FROM virtual_reply_cursors WHERE project_key=? AND source_kind=? AND lane=?',['anytour',$source,$lane]);return $row?(int)$row['last_id']:null;}
    private function advanceCursor(string $source,string $lane,int $id,bool $monotonic=true): int {
        return $this->transaction(function() use ($source,$lane,$id,$monotonic): int {
            $now=$this->now();
            $this->insertIgnore('virtual_reply_cursors',['project_key'=>'anytour','source_kind'=>$source,'lane'=>$lane,'last_id'=>0,'updated_at'=>$now]);
            $row=$this->one('SELECT last_id FROM virtual_reply_cursors WHERE project_key=? AND source_kind=? AND lane=?'.$this->lock(),['anytour',$source,$lane]);
            $next=$monotonic?max($id,(int)$row['last_id']):$id;
            $this->execute('UPDATE virtual_reply_cursors SET last_id=?,updated_at=? WHERE project_key=? AND source_kind=? AND lane=?',[$next,$now,'anytour',$source,$lane]);return $next;
        });
    }
    private function transaction(callable $callback) {
        if ($this->pdo->inTransaction()) throw new RuntimeException('virtual_reply_transaction_conflict');
        $this->pdo->beginTransaction();
        try {$value=$callback();$this->pdo->commit();}
        catch (Throwable $error) {if ($this->pdo->inTransaction()) $this->pdo->rollBack();throw $error;}
        if (is_array($value) && isset($value['_expired'])) {$this->expire();throw new RuntimeException('virtual_reply_claim_expired');}
        return $value;
    }
}
