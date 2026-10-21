<?php

declare(strict_types=1);

/** Private whole-dialogue sampling. No payloads, provider calls or runtime DDL. */
final class VirtualReplySampling
{
    private const SOCIAL_DAILY_LIMIT = 20;
    private bool $enabled = false;
    private string $driver;

    public function __construct(private PDO $pdo, private $clock)
    {
        $this->driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $missing=0;
        $tables=['virtual_reply_sampling_groups','virtual_reply_sampling_aliases','virtual_reply_case_sampling','virtual_reply_sampling_claims'];
        foreach ($tables as $table) {
            try { $pdo->query('SELECT * FROM '.$table.' LIMIT 0'); }
            catch (PDOException $error) {
                $state=(string)($error->errorInfo[0]??$error->getCode());
                $absent=$state==='42S02' || ((int)($error->errorInfo[1]??0)===1 && str_contains($error->getMessage(),'no such table: '.$table));
                if (!$absent) throw new RuntimeException('virtual_reply_sampling_unavailable');
                $missing++;
            }
        }
        // Compatibility requires the entire optional migration to be absent.
        // A partial/broken 051 schema is a technical failure, not compatibility.
        if ($missing===count($tables)) return;
        if ($missing!==0) throw new RuntimeException('virtual_reply_sampling_unavailable');
        $this->enabled=true;
    }

    public function available(): bool { return $this->enabled; }

    public function status(): array
    {
        if (!$this->enabled) return ['status'=>'migration_unavailable'];
        $counts=['development'=>0,'reserved'=>0,'contaminated'=>0];
        foreach ($this->rows('SELECT cohort,COUNT(*) AS total FROM virtual_reply_sampling_groups WHERE project_key=? GROUP BY cohort',['anytour']) as $row) $counts[$row['cohort']]=(int)$row['total'];
        $pending=$this->legacyPending();$unresolved=$this->unresolvedLegacyExposure();
        $day=substr($this->now(),0,10);
        return ['status'=>'enabled','legacy_pending'=>$pending,'legacy_unresolved_exposure'=>$unresolved,'reservation_blocked_reason'=>$unresolved>0?'legacy_unresolved_exposure':($pending>0?'legacy_backfill_pending':null),'author_blocked_reason'=>$pending>0?'legacy_backfill_pending':null,
            'groups'=>$counts,'author_groups_claimed_today'=>(int)$this->one('SELECT COUNT(*) AS total FROM virtual_reply_sampling_claims WHERE day_utc=?',[$day])['total'],
            'social_groups_claimed_today'=>$this->socialUsed($day),'social_daily_limit'=>self::SOCIAL_DAILY_LIMIT,
            'enrollment_started_at'=>$this->enrollmentStart()];
    }

    /** Metadata attachment belongs to the caller's existing case transaction. */
    public function attach(array $case,array $snapshot): void
    {
        if (!$this->enabled) return;
        if (!$this->pdo->inTransaction()) throw new RuntimeException('virtual_reply_sampling_transaction_required');
        $sample=$this->sample($snapshot);$now=$this->now();
        $this->insertIgnore('virtual_reply_case_sampling',['case_id'=>$case['case_id'],'legacy'=>0,'kind'=>$sample['kind'],'identity_complete'=>(int)$sample['identity_complete']]);
        $meta=$this->one('SELECT * FROM virtual_reply_case_sampling WHERE case_id=?'.$this->lock(),[$case['case_id']]);
        if (!$meta) throw new RuntimeException('virtual_reply_sampling_unavailable');
        $author=$this->one('SELECT status,claimed_at FROM virtual_reply_jobs WHERE case_id=? AND role=?',[$case['case_id'],'author']);
        $claimed=is_string($author['claimed_at']??null)?$author['claimed_at']:null;
        $exposed=(int)$meta['legacy']===1 || $claimed!==null || ($author!==null && $author['status']!=='pending');
        $keys=$sample['identity_keys'];$groups=[];
        if (is_string($meta['group_key']??null)) $groups[]=$meta['group_key'];
        foreach ($this->aliases($keys) as $alias) $groups[]=$alias['group_key'];
        $groups=array_values(array_unique($groups));sort($groups,SORT_STRING);
        if (!$groups) {
            $group=hash('sha256','virtual_reply_sampling_group_v1|'.$keys[0]);
            $reserve=$this->reserveEligible($case,$snapshot,$sample,$exposed,$now)
                && hexdec(substr($group,0,8))%5===0;
            $this->insertIgnore('virtual_reply_sampling_groups',['group_key'=>$group,'project_key'=>'anytour','cohort'=>$reserve?'reserved':'development',
                'identity_complete'=>(int)$sample['identity_complete'],'created_at'=>$now,'updated_at'=>$now]);
            $groups=[$group];
        }
        $target=$groups[0];
        foreach ($keys as $key) $this->insertIgnore('virtual_reply_sampling_aliases',['project_key'=>'anytour','identity_key'=>$key,'group_key'=>$target]);
        foreach ($this->aliases($keys) as $alias) $groups[]=$alias['group_key'];
        $groups=array_values(array_unique($groups));sort($groups,SORT_STRING);
        $eligible=$this->reserveEligible($case,$snapshot,$sample,$exposed,$now);
        $target=$this->merge($groups,$exposed,$sample['identity_complete'],$eligible,$now);
        $this->execute('UPDATE virtual_reply_case_sampling SET group_key=?,kind=?,identity_complete=? WHERE case_id=?',
            [$target,$sample['kind'],(int)$sample['identity_complete'],$case['case_id']]);
        if ($claimed!==null) $this->importClaim($target,substr($claimed,0,10),$sample['kind']==='social',$claimed);
    }

    public function pendingBackfill(int $limit): array
    {
        if (!$this->enabled) return [];
        $limit=min(100,max(1,$limit));
        return $this->rows('SELECT c.* FROM virtual_reply_cases c LEFT JOIN virtual_reply_case_sampling s ON s.case_id=c.case_id WHERE c.project_key=? AND c.state=? AND (s.case_id IS NULL OR s.group_key IS NULL) ORDER BY c.id LIMIT '.$limit,['anytour','active']);
    }

    public function authorCandidates(string $now,string $recent,int $limit): array
    {
        if (!$this->enabled || $this->legacyPending()>0) return [];
        $limit=min(100,max(1,$limit));$day=substr($now,0,10);
        // Representative and cohort/daily fences are before LIMIT, so hundreds
        // of neighboring prefixes cannot crowd out another eligible dialogue.
        $fresh="CASE WHEN c.reference_time>=? THEN 0 ELSE 1 END";
        $otherFresh="CASE WHEN n.reference_time>=? THEN 0 ELSE 1 END";
        $kind="CASE WHEN s.kind='substantive' THEN 0 ELSE 1 END";
        $otherKind="CASE WHEN ns.kind='substantive' THEN 0 ELSE 1 END";
        $better='('.$otherFresh.'<'.$fresh.' OR ('.$otherFresh.'='.$fresh.' AND ('.$otherKind.'<'.$kind.' OR ('.$otherKind.'='.$kind.' AND (n.reference_time>c.reference_time OR (n.reference_time=c.reference_time AND n.id>c.id))))))';
        $sql='SELECT c.* FROM virtual_reply_cases c JOIN virtual_reply_jobs j ON j.case_id=c.case_id '
            .'JOIN virtual_reply_case_sampling s ON s.case_id=c.case_id JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key '
            .'WHERE c.project_key=? AND c.state=? AND j.role=? AND j.status=? AND j.available_at<=? AND g.cohort<>? '
            .'AND NOT EXISTS (SELECT 1 FROM virtual_reply_sampling_claims d WHERE d.group_key=s.group_key AND d.day_utc=?) '
            .'AND (s.kind<>? OR (SELECT COUNT(*) FROM virtual_reply_sampling_claims sd WHERE sd.day_utc=? AND sd.social=1)<?) '
            .'AND NOT EXISTS (SELECT 1 FROM virtual_reply_cases n JOIN virtual_reply_jobs nj ON nj.case_id=n.case_id '
            .'JOIN virtual_reply_case_sampling ns ON ns.case_id=n.case_id WHERE n.project_key=c.project_key AND n.state=? '
            .'AND nj.role=? AND nj.status=? AND nj.available_at<=? AND ns.group_key=s.group_key AND '.$better.') '
            .'ORDER BY '.$fresh.','.$kind.',g.last_author_claimed_at,c.reference_time DESC,c.id DESC LIMIT '.$limit;
        $args=['anytour','active','author','pending',$now,'reserved',$day,'social',$day,self::SOCIAL_DAILY_LIMIT,
            'active','author','pending',$now,$recent,$recent,$recent,$recent,$recent];
        return $this->rows($sql,$args);
    }

    /** Caller already holds the shared author quota lock. */
    public function claimAuthor(array $case,string $now): bool
    {
        if (!$this->enabled) return true;
        if (!$this->pdo->inTransaction()) throw new RuntimeException('virtual_reply_sampling_transaction_required');
        $meta=$this->one('SELECT s.*,g.cohort FROM virtual_reply_case_sampling s JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key WHERE s.case_id=?'.$this->lock(),[$case['case_id']]);
        if (!$meta || $meta['cohort']==='reserved') return false;
        $day=substr($now,0,10);
        if ($this->one('SELECT group_key FROM virtual_reply_sampling_claims WHERE group_key=? AND day_utc=?'.$this->lock(),[$meta['group_key'],$day])!==null) return false;
        if ($meta['kind']==='social' && $this->socialUsed($day)>=self::SOCIAL_DAILY_LIMIT) return false;
        if ($this->insertIgnore('virtual_reply_sampling_claims',['group_key'=>$meta['group_key'],'day_utc'=>$day,'social'=>(int)($meta['kind']==='social'),'claimed_at'=>$now])!==1) return false;
        $this->execute('UPDATE virtual_reply_sampling_groups SET exposed_at=COALESCE(exposed_at,?),last_author_claimed_at=?,updated_at=? WHERE group_key=?',[$now,$now,$now,$meta['group_key']]);
        return true;
    }

    public function readable(string $caseId): bool
    {
        if (!$this->enabled) return true;
        $row=$this->one('SELECT g.cohort FROM virtual_reply_case_sampling s JOIN virtual_reply_sampling_groups g ON g.group_key=s.group_key WHERE s.case_id=?',[$caseId]);
        return $row!==null && $row['cohort']!=='reserved';
    }

    public function reviewPredicate(string $caseAlias): string
    {
        // Unmapped pre-migration review rows remain readable after their normal
        // source refresh attaches conservative legacy/development metadata.
        return !$this->enabled?'1=1':"NOT EXISTS (SELECT 1 FROM virtual_reply_case_sampling rs JOIN virtual_reply_sampling_groups rg ON rg.group_key=rs.group_key WHERE rs.case_id=$caseAlias.case_id AND rg.cohort='reserved')";
    }

    private function sample(array $snapshot): array
    {
        if (!array_key_exists('sampling',$snapshot)) return ['identity_keys'=>[hash('sha256','virtual_reply_sampling_legacy_v1|'.$snapshot['source_kind'].'|'.($snapshot['source_scope']??'').'|'.$snapshot['source_id'])],
            'identity_complete'=>false,'kind'=>'substantive'];
        $s=$snapshot['sampling'];
        if (!is_array($s) || !is_array($s['identity_keys']??null) || !array_is_list($s['identity_keys']) || count($s['identity_keys'])<1 || count($s['identity_keys'])>8
            || !is_bool($s['identity_complete']??null) || !in_array($s['kind']??null,['substantive','social'],true)) throw new RuntimeException('virtual_reply_sampling_unavailable');
        foreach ($s['identity_keys'] as $key) if (!is_string($key)||preg_match('/\A[a-f0-9]{64}\z/D',$key)!==1) throw new RuntimeException('virtual_reply_sampling_unavailable');
        $s['identity_keys']=array_values(array_unique($s['identity_keys']));sort($s['identity_keys'],SORT_STRING);return $s;
    }

    private function aliases(array $keys): array
    {
        return $this->rows('SELECT identity_key,group_key FROM virtual_reply_sampling_aliases WHERE project_key=? AND identity_key IN ('.implode(',',array_fill(0,count($keys),'?')).') ORDER BY identity_key'.$this->lock(),['anytour',...$keys]);
    }

    private function merge(array $keys,bool $exposed,bool $complete,bool $eligible,string $now): string
    {
        $groups=$this->rows('SELECT * FROM virtual_reply_sampling_groups WHERE group_key IN ('.implode(',',array_fill(0,count($keys),'?')).') ORDER BY group_key'.$this->lock(),$keys);
        if (!$groups) throw new RuntimeException('virtual_reply_sampling_unavailable');
        $target=$groups[0]['group_key'];$reserved=false;$contaminated=false;$development=$exposed||!$complete||!$eligible||count($keys)>1;$first=$groups[0]['created_at'];$last=null;$seen=$exposed?$now:null;
        foreach ($groups as $group) {
            $reserved=$reserved||$group['cohort']==='reserved';
            $contaminated=$contaminated||$group['cohort']==='contaminated';
            $complete=$complete&&(bool)$group['identity_complete'];
            $development=$development||$group['cohort']!=='reserved'||$group['exposed_at']!==null||!$complete;
            $first=min($first,$group['created_at']);
            if ($group['last_author_claimed_at']!==null) $last=$last===null?$group['last_author_claimed_at']:max($last,$group['last_author_claimed_at']);
            if ($group['exposed_at']!==null) $seen=$seen===null?$group['exposed_at']:min($seen,$group['exposed_at']);
        }
        $cohort=$contaminated||($development&&$reserved)?'contaminated':($development?'development':'reserved');
        foreach ($groups as $group) {
            $donor=$group['group_key'];if ($donor===$target) continue;
            foreach ($this->rows('SELECT * FROM virtual_reply_sampling_claims WHERE group_key=?',[$donor]) as $claim) {
                $at=$claim['claimed_at'];$last=$last===null?$at:max($last,$at);$seen=$seen===null?$at:min($seen,$at);
                $this->importClaim($target,$claim['day_utc'],(bool)$claim['social'],$at);
            }
            $this->execute('UPDATE virtual_reply_sampling_aliases SET group_key=? WHERE group_key=?',[$target,$donor]);
            $this->execute('UPDATE virtual_reply_case_sampling SET group_key=? WHERE group_key=?',[$target,$donor]);
            $this->execute('DELETE FROM virtual_reply_sampling_groups WHERE group_key=?',[$donor]);
        }
        $this->execute('UPDATE virtual_reply_sampling_groups SET cohort=?,identity_complete=?,exposed_at=?,last_author_claimed_at=?,created_at=?,updated_at=? WHERE group_key=?',
            [$cohort,(int)$complete,$seen,$last,$first,$now,$target]);
        if ($cohort!=='reserved') {
            require_once __DIR__.'/VirtualReplyControlStore.php';
            foreach ($keys as $key) VirtualReplyControlStore::invalidateGroup($this->pdo,$key);
        }
        return $target;
    }

    private function importClaim(string $group,string $day,bool $social,string $at): void
    {
        $this->insertIgnore('virtual_reply_sampling_claims',['group_key'=>$group,'day_utc'=>$day,'social'=>(int)$social,'claimed_at'=>$at]);
        $old=$this->one('SELECT social,claimed_at FROM virtual_reply_sampling_claims WHERE group_key=? AND day_utc=?',[$group,$day]);
        if (!$old) throw new RuntimeException('virtual_reply_sampling_unavailable');
        $this->execute('UPDATE virtual_reply_sampling_claims SET social=?,claimed_at=? WHERE group_key=? AND day_utc=?',[(int)($social||(bool)$old['social']),min($at,$old['claimed_at']),$group,$day]);
        $g=$this->one('SELECT exposed_at,last_author_claimed_at FROM virtual_reply_sampling_groups WHERE group_key=?',[$group]);
        $this->execute('UPDATE virtual_reply_sampling_groups SET exposed_at=?,last_author_claimed_at=? WHERE group_key=?',
            [$g['exposed_at']===null?$at:min($at,$g['exposed_at']),$g['last_author_claimed_at']===null?$at:max($at,$g['last_author_claimed_at']),$group]);
    }

    private function legacyPending(?string $exceptCase=null): int
    {
        $except=$exceptCase===null?'':' AND c.case_id<>?';
        $args=['anytour','active'];if ($exceptCase!==null) $args[]=$exceptCase;
        return (int)$this->one('SELECT COUNT(*) AS total FROM virtual_reply_cases c LEFT JOIN virtual_reply_case_sampling s ON s.case_id=c.case_id WHERE c.project_key=? AND c.state=? AND (s.case_id IS NULL OR s.group_key IS NULL)'.$except,$args)['total'];
    }
    /** Revoked/deleted legacy tombstones cannot be assumed unseen. */
    private function unresolvedLegacyExposure(?string $exceptCase=null): int
    {
        $except=$exceptCase===null?'':' AND c.case_id<>?';
        $args=['anytour'];if ($exceptCase!==null) $args[]=$exceptCase;
        return (int)$this->one('SELECT COUNT(*) AS total FROM virtual_reply_cases c JOIN virtual_reply_case_sampling s ON s.case_id=c.case_id WHERE c.project_key=? AND s.legacy=1 AND s.group_key IS NULL'.$except,$args)['total'];
    }
    private function reserveEligible(array $case,array $snapshot,array $sample,bool $exposed,string $now): bool
    {
        $start=$this->enrollmentStart()??$now;
        $firstMessage=$this->prefixStart($snapshot);
        $reference=$this->timestamp($snapshot['reference_time']??null);
        return $sample['identity_complete'] && !$exposed && $this->legacyPending($case['case_id'])===0
            && $this->unresolvedLegacyExposure($case['case_id'])===0
            && ($snapshot['mode']??null)==='live' && ($snapshot['payload']['history_incomplete']??null)===false
            && ($snapshot['payload']['historical_context_reconstructed']??null)===false
            && $reference!==null && $reference>=$start && $firstMessage!==null && $firstMessage>=$start;
    }
    private function timestamp($at): ?string
    {
        if (!is_string($at)) return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$at,new DateTimeZone('UTC'));
        return $date&&$date->format('Y-m-d\TH:i:s\Z')===$at?$date->format('Y-m-d H:i:s'):null;
    }
    private function prefixStart(array $snapshot): ?string {
        $messages=$snapshot['payload']['messages']??null;
        if (!is_array($messages)||!$messages) return null;
        $first=null;
        foreach ($messages as $message) {
            $at=$message['created_at']??null;
            $value=$this->timestamp($at);if ($value===null) return null;
            $first=$first===null?$value:min($first,$value);
        }
        return $first;
    }
    private function socialUsed(string $day): int {return (int)$this->one('SELECT COUNT(*) AS total FROM virtual_reply_sampling_claims WHERE day_utc=? AND social=1',[$day])['total'];}
    private function enrollmentStart(): ?string {return $this->one('SELECT MIN(created_at) AS started FROM virtual_reply_sampling_groups WHERE project_key=?',['anytour'])['started']??null;}
    private function now(): string {return gmdate('Y-m-d H:i:s',(int)($this->clock)());}
    private function lock(): string {return $this->driver==='mysql'?' FOR UPDATE':'';}
    private function rows(string $sql,array $args=[]): array {$q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $args=[]): ?array {$rows=$this->rows($sql,$args);return $rows[0]??null;}
    private function execute(string $sql,array $args=[]): int {$q=$this->pdo->prepare($sql);$q->execute($args);return $q->rowCount();}
    private function insertIgnore(string $table,array $values): int {return $this->execute(($this->driver==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE').' INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));}
}
