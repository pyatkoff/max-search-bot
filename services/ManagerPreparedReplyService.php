<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerAiDraftContextService.php';
require_once __DIR__.'/ManagerAiDraftService.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ManagerDraftPilotCollector.php';

/** Private work metadata and reviewed proposals; never a transcript or delivery queue. */
final class ManagerPreparedReplyService
{
    public const MAX_BATCH = 20;
    public const MAX_PENDING = 200;
    public const TTL_SECONDS = 3600;
    public const MAX_PROPOSAL_BYTES = 32768;
    private const FIELDS = ['ok','should_draft','draft','next_action','reason','checks','evidence_message_ids','history_incomplete'];

    /** Rows come from the canonical bounded collector, but all bindings are rechecked here. */
    public static function enqueue(array $candidateRows, bool $renewExpired = false): array
    {
        if (!array_is_list($candidateRows) || count($candidateRows) > self::MAX_BATCH) return self::failure('invalid_batch');
        $out = ['ok'=>true,'inserted'=>0,'existing'=>0,'skipped'=>0,'job_ids'=>[]];
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return self::failure('queue_unavailable');
            foreach ($candidateRows as $candidate) {
                $cid = is_array($candidate) ? ($candidate['conversation_id'] ?? null) : null;
                $actor = is_array($candidate) ? ($candidate['assigned_manager_id'] ?? $candidate['manager_id'] ?? null) : null;
                $revision = is_array($candidate) ? ($candidate['revision'] ?? $candidate['context_revision'] ?? null) : null;
                $latest = is_array($candidate) ? ($candidate['latest_message_id'] ?? null) : null;
                $project = is_array($candidate) ? ($candidate['project_key'] ?? null) : null;
                if (!is_int($cid) || $cid <= 0 || !is_int($actor) || $actor < 0 || !self::revision($revision)
                    || !is_int($latest) || $latest <= 0 || !self::project($project)
                    || $project !== ProjectConfig::projectId()) { ++$out['skipped']; continue; }
                $snapshot = ManagerAiDraftContextService::preparationSnapshot($cid, $actor);
                if (!self::matches($snapshot, $revision, $latest) || self::projectFor($pdo, $cid, $actor) !== $project) {
                    ++$out['skipped']; continue;
                }
                $instruction = self::instructionRevision();
                $id = hash('sha256', json_encode(['manager_prepared_reply_v1',$cid,$actor,$project,$revision,$instruction], JSON_THROW_ON_ERROR));
                // A renewal is a new immutable work item, never an overwrite of
                // the old result/provenance. Only the scheduled collector opts in.
                $previous = self::latestBinding($pdo, $cid, $actor, $project, $revision, $instruction);
                if ($previous !== null) {
                    if (!$renewExpired || (self::timestamp($previous['expires_at']) ?? PHP_INT_MAX) > time()
                        || self::noContact($previous, $snapshot)) {
                        ++$out['existing']; $out['job_ids'][] = $previous['job_id']; continue;
                    }
                    $id = hash('sha256', json_encode(['manager_prepared_renewal_v1',$id,$previous['job_id']], JSON_THROW_ON_ERROR));
                }
                $now = time();
                try {
                    // An edited/new message must not wait behind its obsolete pending revision.
                    $q = $pdo->prepare("UPDATE manager_prepared_replies SET expires_at=? WHERE conversation_id=? AND project_key=? AND status='pending' AND job_id<>? AND expires_at>?");
                    $q->execute([self::utc($now),$cid,$project,$id,self::utc($now)]);
                    $q = $pdo->prepare("SELECT COUNT(*) FROM manager_prepared_replies WHERE project_key=? AND status='pending' AND expires_at>?");
                    $q->execute([$project,self::utc($now)]);
                    if ((int)$q->fetchColumn() >= self::MAX_PENDING) { ++$out['skipped']; continue; }
                    $q = $pdo->prepare("INSERT INTO manager_prepared_replies (job_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,status,created_at,expires_at) VALUES (?,?,?,?,?,?,?,'pending',?,?)");
                    $q->execute([$id,$cid,$actor,$project,$revision,$instruction,$latest,self::utc($now),self::utc($now+self::TTL_SECONDS)]);
                    ++$out['inserted']; $out['job_ids'][] = $id;
                } catch (Throwable $ignored) {
                    // A concurrent enqueue of the deterministic job is the same work item.
                    if (self::load($pdo, $id) !== null) { ++$out['existing']; $out['job_ids'][] = $id; }
                    else return self::failure('queue_unavailable');
                }
            }
            return $out;
        } catch (Throwable $ignored) { return self::failure('queue_unavailable'); }
    }

    /** Existing cron's bounded preparation pass; no model, send or task mutation. */
    public static function collectAndEnqueue(array $managerIds, ?string $capturedAtUtc = null): array
    {
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return self::failure('queue_unavailable');
            $project = ProjectConfig::projectId();
            if (!self::project($project)) return self::failure('queue_unavailable');
            $q = $pdo->prepare("SELECT lane,after_id,version FROM manager_preparation_cursors WHERE project_key=? AND lane IN ('waiting','due','active')");
            $q->execute([$project]);
            $states = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!in_array($row['lane'], ['waiting','due','active'], true)
                    || filter_var($row['after_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) === false
                    || filter_var($row['version'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) return self::failure('queue_unavailable');
                $states[$row['lane']] = ['after_id'=>(int)$row['after_id'],'version'=>(int)$row['version']];
            }
            $reconciliation = self::reconcilePending($pdo, $project);
            $candidates = 0; $rows = []; $pages = [];
            $capture = static function(string $lane, int $limit, bool $wrap) use ($pdo,$managerIds,$capturedAtUtc,&$pages,&$states,&$candidates,&$rows): void {
                $cursor = $pages[$lane]['next_cursor'] ?? $states[$lane]['after_id'] ?? 0;
                $page = ManagerDraftPilotCollector::collect($pdo,$managerIds,$limit,null,null,$capturedAtUtc,true,['lane'=>$lane,'after_id'=>$cursor]);
                if (empty($page['ok'])) throw new RuntimeException('collection_failed');
                if ($wrap && $cursor > 0 && $page['candidate_count'] === 0) {
                    $page = ManagerDraftPilotCollector::collect($pdo,$managerIds,$limit,null,null,$capturedAtUtc,true,['lane'=>$lane,'after_id'=>0]);
                    if (empty($page['ok'])) throw new RuntimeException('collection_failed');
                }
                $candidates += $page['candidate_count'];
                array_push($rows, ...$page['conversations']);
                $pages[$lane] = $page;
            };
            // Reserve progress for every lane even under a continuously busy
            // waiting queue. Reuse spare capacity without exceeding20 candidate captures.
            foreach (['waiting'=>10,'due'=>5,'active'=>5] as $lane=>$quota) $capture($lane,$quota,true);
            foreach (['waiting','due','active'] as $lane) {
                if ($candidates >= self::MAX_BATCH) break;
                if ($pages[$lane]['next_cursor'] > 0) $capture($lane,self::MAX_BATCH-$candidates,false);
            }
            // A race can move a client between lanes; enqueue each binding once.
            $unique = [];
            foreach ($rows as $row) $unique[$row['conversation_id']] = $row;
            $queued = self::enqueue(array_values($unique), true);
            if (empty($queued['ok'])) return $queued + ['stage'=>'enqueue'];
            foreach ($pages as $lane=>$page) {
                $old = $states[$lane] ?? null;
                if ($old === null) {
                    $ignore = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
                    $q = $pdo->prepare($ignore.' INTO manager_preparation_cursors (project_key,lane,after_id,version) VALUES (?,?,?,1)');
                    $q->execute([$project,$lane,$page['next_cursor']]);
                } else {
                    // Compare-and-set keeps a slower overlapping scan from
                    // moving a newer cursor backwards, including after wrap.
                    $q = $pdo->prepare('UPDATE manager_preparation_cursors SET after_id=?,version=version+1 WHERE project_key=? AND lane=? AND version=?');
                    $q->execute([$page['next_cursor'],$project,$lane,$old['version']]);
                }
            }
            return ['ok'=>true,'candidate_count'=>$candidates,'exported_count'=>count($unique),'queue'=>$queued,'reconciliation'=>$reconciliation];
        } catch (Throwable $ignored) { return self::failure('queue_unavailable'); }
    }

    /** Scheduled queue maintenance only; full snapshots never run in Inbox/pending reads. */
    private static function reconcilePending(PDO $pdo, string $project): array
    {
        $q=$pdo->prepare("SELECT after_id,version FROM manager_preparation_cursors WHERE project_key=? AND lane='verify'");
        $q->execute([$project]);$cursor=$q->fetch(PDO::FETCH_ASSOC);
        if ($cursor && (filter_var($cursor['after_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false
            || filter_var($cursor['version'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)) throw new RuntimeException('invalid_cursor');
        $after=(int)($cursor['after_id']??0);$now=self::utc(time());
        $read=static function(int $after) use($pdo,$project,$now): array {
            $q=$pdo->prepare("SELECT * FROM manager_prepared_replies WHERE project_key=? AND status='pending' AND created_at<=? AND expires_at>? AND conversation_id>? ORDER BY conversation_id,job_id LIMIT ".self::MAX_BATCH);
            $q->execute([$project,$now,$now,$after]);return $q->fetchAll(PDO::FETCH_ASSOC);
        };
        $rows=$read($after);if (!$rows && $after>0) $rows=$read(0);
        $stats=['checked'=>0,'retired'=>0,'renewed'=>0,'deferred'=>0];$next=0;
        foreach($rows as $row){
            ++$stats['checked'];$next=(int)$row['conversation_id'];
            if (!self::validRow($row)){++$stats['deferred'];continue;}
            $snapshot=ManagerAiDraftContextService::preparationSnapshot((int)$row['conversation_id'],(int)$row['manager_id']);
            $available=($snapshot['ok']??false)===true;
            if (!$available && !in_array($snapshot['error']??'', ['unauthorized','not_found','reply_not_allowed','suspended','invalid_conversation_id'],true)){
                // Storage/transport uncertainty is not proof of stale work.
                ++$stats['deferred'];continue;
            }
            $sameProject=$available && self::projectFor($pdo,(int)$row['conversation_id'],(int)$row['manager_id'])===$project;
            if ($sameProject && self::matches($snapshot,$row['context_revision'],(int)$row['latest_message_id'])
                && hash_equals(self::instructionRevision(),$row['instruction_revision'])) continue;
            // Never touch a ready result, newer binding or concurrent completion.
            $q=$pdo->prepare("UPDATE manager_prepared_replies SET expires_at=? WHERE job_id=? AND status='pending' AND context_revision=? AND instruction_revision=? AND expires_at=?");
            $q->execute([$now,$row['job_id'],$row['context_revision'],$row['instruction_revision'],$row['expires_at']]);
            if ($q->rowCount()!==1) continue;
            ++$stats['retired'];
            if (!$sameProject) continue;
            $renewed=self::enqueue([['conversation_id'=>(int)$row['conversation_id'],'manager_id'=>(int)$row['manager_id'],
                'project_key'=>$project,'context_revision'=>$snapshot['revision'],'latest_message_id'=>$snapshot['latest_message_id']]],true);
            if (empty($renewed['ok'])) throw new RuntimeException('reconciliation_failed');
            $stats['renewed']+=(int)$renewed['inserted'];
        }
        if (!$cursor){
            $ignore=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $q=$pdo->prepare($ignore." INTO manager_preparation_cursors (project_key,lane,after_id,version) VALUES (?,'verify',?,1)");$q->execute([$project,$next]);
        }else{
            $q=$pdo->prepare("UPDATE manager_preparation_cursors SET after_id=?,version=version+1 WHERE project_key=? AND lane='verify' AND version=?");
            $q->execute([$next,$project,(int)$cursor['version']]);
        }
        return $stats;
    }

    /** Worker inventory has no message text, customer identity, links or draft body. */
    public static function pending(int $limit = self::MAX_BATCH): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) return self::failure('invalid_limit');
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return self::failure('queue_unavailable');
            $sql = "SELECT j.job_id,j.conversation_id,j.manager_id,j.project_key,j.context_revision,j.instruction_revision,j.latest_message_id,j.created_at,j.expires_at FROM manager_prepared_replies j WHERE j.project_key=? AND j.status='pending' AND j.created_at<=? AND j.expires_at>? AND j.instruction_revision=?"
                ." AND EXISTS (SELECT 1 FROM conversations c LEFT JOIN managers m ON m.id=c.manager_id WHERE c.id=j.conversation_id AND c.project_key=j.project_key AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND ((c.manager_id=j.manager_id AND c.status='manager' AND m.is_active=1) OR (j.manager_id=0 AND c.manager_id IS NULL AND c.status='waiting_manager' AND EXISTS (SELECT 1 FROM managers a JOIN manager_projects ap ON ap.manager_id=a.id JOIN projects p ON p.id=ap.project_id WHERE a.is_active=1 AND a.role='admin' AND p.is_active=1 AND p.project_key=j.project_key))))"
                .' AND j.latest_message_id=(SELECT MAX(pm.id) FROM messages pm WHERE pm.conversation_id=j.conversation_id)';
            $now = self::utc(time());
            $params = [ProjectConfig::projectId(),$now,$now,self::instructionRevision()];
            $order = ' ORDER BY j.created_at,j.job_id LIMIT '.$limit;
            if ($limit < 3) {
                // One or two slots cannot reserve all three lanes. Preserve FIFO
                // rather than starving ordinary work in these explicit tiny reads.
                $q = $pdo->prepare($sql.$order);
                $q->execute($params);
                $selected = $q->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Match the collector's genuine-human/due-task classification at
                // read time: a reply or task edit can change priority after enqueue.
                $metadata = "CASE WHEN JSON_VALID(mc.metadata_json) THEN mc.metadata_json ELSE '{}' END";
                $type = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? "JSON_UNQUOTE(JSON_EXTRACT({$metadata},'$.type'))" : "JSON_EXTRACT({$metadata},'$.type')";
                $lane = "CASE WHEN COALESCE((SELECT MAX(mc.id) FROM messages mc WHERE mc.conversation_id=j.conversation_id AND mc.direction='inbound' AND mc.sender_type='customer' AND COALESCE({$type},'message') NOT IN ('callback','start','bot_started')),0)>COALESCE((SELECT MAX(mo.id) FROM messages mo WHERE mo.conversation_id=j.conversation_id AND mo.direction='outbound' AND mo.sender_type='manager'),0) THEN 'waiting' WHEN EXISTS (SELECT 1 FROM lead_tasks t WHERE t.conversation_id=j.conversation_id AND t.status='open' AND t.due_at_utc IS NOT NULL AND t.due_at_utc<=? AND (t.assigned_manager_id IS NULL OR t.assigned_manager_id=j.manager_id)) THEN 'due' ELSE 'active' END";
                $q = $pdo->prepare($sql.' AND ('.$lane.')=?'.$order);
                $batches = [];
                foreach (['waiting','due','active'] as $key) {
                    $q->execute(array_merge($params,[$now,$key]));
                    $batches[$key] = $q->fetchAll(PDO::FETCH_ASSOC);
                    $q->closeCursor();
                }
                $reserve = max(1,intdiv($limit,4));
                $selected = []; $seen = [];
                $take = static function(string $key,int $count) use (&$selected,&$seen,$batches): void {
                    foreach ($batches[$key] as $row) {
                        if ($count <= 0) break;
                        if (isset($seen[$row['job_id']])) continue;
                        $seen[$row['job_id']] = true; $selected[] = $row; --$count;
                    }
                };
                foreach (['waiting'=>$limit-2*$reserve,'due'=>$reserve,'active'=>$reserve] as $key=>$quota) $take($key,$quota);
                foreach (['waiting','due','active'] as $key) $take($key,$limit-count($selected));
            }
            $jobs = [];
            foreach ($selected as $row) {
                if (!self::validRow($row)) continue;
                foreach (['conversation_id','manager_id','latest_message_id'] as $field) $row[$field] = (int)$row[$field];
                $jobs[] = $row;
            }
            return ['ok'=>true,'jobs'=>$jobs];
        } catch (Throwable $ignored) { return self::failure('queue_unavailable'); }
    }

    /** Canonical authorization is repeated before exposing each private context. */
    public static function context(string $jobId, ?callable $selectionTransport = null): array
    {
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction() || !self::revision($jobId)) return self::failure('job_unavailable');
            $row = self::load($pdo, $jobId);
            if ($row === null || $row['status'] !== 'pending' || !self::currentRow($row)) return self::failure('job_unavailable');
            $snapshot = self::snapshotFor($pdo, $row);
            if ($snapshot === null) return self::failure('stale_context');
            $knowledge = $snapshot['company_knowledge'];
            // Only this explicit context read may fetch supported public cards.
            // snapshotFor has already committed its short read-only transaction.
            $input = ManagerAiDraftService::prepareInput($snapshot['context'], $knowledge,
                static fn(): ManagerSelectionSnapshot => ManagerSelectionSnapshot::read($snapshot, $selectionTransport),
                $snapshot['reference_context'] ?? null,
                isset($snapshot['photo_context'])?static fn(array $context):?array=>$snapshot['photo_context']->read($context):null);
            if (isset($input['error'])) return self::failure('context_unavailable');
            // Network I/O must not expose a now-revoked/edited/expired job.
            $fresh = self::load($pdo, $jobId);
            if ($fresh === null || $fresh['status'] !== 'pending' || !self::currentRow($fresh)) return self::failure('job_unavailable');
            if (self::snapshotFor($pdo, $fresh) === null) return self::failure('stale_context');
            return ['ok'=>true,'job_id'=>$jobId,'revision'=>$row['context_revision'],
                'preparation_scope'=>(int)$row['manager_id'] === 0 ? 'unassigned_waiting' : 'assigned_manager',
                'latest_message_id'=>(int)$row['latest_message_id'],
                'context'=>$input['context'],'image_inputs'=>$input['images']??[],
                'company_knowledge'=>['facts'=>$knowledge->facts(),'revision'=>$knowledge->revision()],
                'instructions'=>ManagerAiDraftService::instructions($input['context']),'instruction_revision'=>$row['instruction_revision']];
        } catch (Throwable $ignored) { return self::failure('job_unavailable'); }
    }

    /** Imported analysis is validated locally. This path never selects a provider transport. */
    public static function complete(string $jobId, string $expectedRevision, array $proposal): array
    {
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction() || !self::revision($jobId) || !self::revision($expectedRevision)) return self::failure('invalid_job');
            $row = self::load($pdo, $jobId);
            if ($row === null || !self::currentRow($row) || !hash_equals($row['context_revision'], $expectedRevision)) return self::failure('job_unavailable');
            $snapshot = self::snapshotFor($pdo, $row);
            if ($snapshot === null) return self::failure('stale_context');
            $json = self::validatedJson($proposal, $snapshot);
            if ($json === null) return self::failure('invalid_proposal');
            $hash = hash('sha256', $json);
            if ($row['status'] === 'ready') return self::completed($row, $hash);
            if ($row['status'] !== 'pending') return self::failure('job_unavailable');
            // Validation is pure, nevertheless use a new source snapshot before persistence.
            if (self::snapshotFor($pdo, $row) === null) return self::failure('stale_context');
            $now = time();
            $q = $pdo->prepare("UPDATE manager_prepared_replies SET status='ready',proposal_json=?,proposal_sha256=?,prepared_at=?,expires_at=? WHERE job_id=? AND status='pending' AND context_revision=? AND instruction_revision=? AND expires_at>?");
            $q->execute([$json,$hash,self::utc($now),self::utc($now+self::TTL_SECONDS),$jobId,$expectedRevision,self::instructionRevision(),self::utc($now)]);
            $fresh = self::load($pdo, $jobId);
            if ($fresh === null) return self::failure('queue_unavailable');
            $result = self::completed($fresh, $hash);
            if ($result['ok']) $result['idempotent'] = $q->rowCount() !== 1;
            return $result;
        } catch (Throwable $ignored) { return self::failure('queue_unavailable'); }
    }

    /** Read-only cache lookup: even an old caller snapshot cannot reveal a stale proposal. */
    public static function prepared(int $cid, int $actor, array $snapshot): array
    {
        $empty = ['ok'=>true,'prepared'=>false];
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction() || $cid <= 0 || $actor < 0) return $empty;
            $fresh = $actor>0 ? ManagerAiDraftContextService::snapshot($cid, $actor)
                : ManagerAiDraftContextService::viewerSnapshot($cid,(int)($snapshot['viewer_id']??0));
            if($actor===0&&((int)($fresh['owner_id']??-1)!==0||empty($fresh['owner_read'])))return $empty;
            if (!self::matches($fresh, $snapshot['revision'] ?? null, $snapshot['latest_message_id'] ?? null)) return $empty;
            $readProject=null;
            if($actor===0||ManagerAuthService::isAdmin(ManagerAuthService::byId($actor)))$readProject=$fresh['project_key']??null;
            elseif(!empty($snapshot['owner_read'])&&(int)($snapshot['viewer_id']??0)>0){
                $viewer=ManagerAiDraftContextService::viewerSnapshot($cid,(int)$snapshot['viewer_id']);
                if(empty($viewer['ok'])||empty($viewer['owner_read'])||(int)$viewer['owner_id']!==$actor
                    ||!self::matches($viewer,$snapshot['revision']??null,$snapshot['latest_message_id']??null)
                    ||($viewer['viewer_access_revision']??null)!==($snapshot['viewer_access_revision']??null))return $empty;
                $readProject=$fresh['project_key']??null;
            }
            $project = self::projectFor($pdo, $cid, $actor,$readProject);
            if ($project === null) return $empty;
            $q = $pdo->prepare("SELECT * FROM manager_prepared_replies WHERE conversation_id=? AND project_key=? AND instruction_revision=? AND latest_message_id=? AND status='ready' AND ((manager_id=? AND context_revision=?) OR (manager_id=0 AND context_revision=?)) ORDER BY prepared_at DESC LIMIT 1");
            $q->execute([$cid,$project,self::instructionRevision(),$fresh['latest_message_id'],$actor,$fresh['revision'],$fresh['preparation_revision'] ?? '']);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || !self::validRow($row) || !self::currentRow($row) || !self::readyTimes($row)) return $empty;
            $json = $row['proposal_json'] ?? null;
            if (!is_string($json) || strlen($json) > self::MAX_PROPOSAL_BYTES
                || !self::revision($row['proposal_sha256'] ?? null) || !hash_equals($row['proposal_sha256'], hash('sha256', $json))) return $empty;
            $proposal = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($proposal) || self::validatedJson($proposal, $fresh) !== $json) return $empty;
            return ['ok'=>true,'prepared'=>true,'proposal'=>$proposal,'job_id'=>$row['job_id'],
                'revision'=>$fresh['revision'],'latest_message_id'=>(int)$row['latest_message_id'],
                'prepared_at'=>$row['prepared_at'],'expires_at'=>$row['expires_at'],'preparation_source'=>'chatgpt_assistant'];
        } catch (Throwable $ignored) { return $empty; }
    }

    private static function completed(array $row, string $hash): array
    {
        if (($row['status'] ?? null) !== 'ready' || !self::revision($row['proposal_sha256'] ?? null)
            || !is_string($row['proposal_json'] ?? null) || strlen($row['proposal_json']) > self::MAX_PROPOSAL_BYTES
            || !hash_equals($row['proposal_sha256'], hash('sha256', $row['proposal_json']))
            || !hash_equals($row['proposal_sha256'], $hash) || !self::readyTimes($row)) return self::failure('completion_conflict');
        return ['ok'=>true,'job_id'=>$row['job_id'],'idempotent'=>true,'prepared_at'=>$row['prepared_at'],'expires_at'=>$row['expires_at']];
    }

    private static function validatedJson(array $proposal, array $snapshot): ?string
    {
        if (count($proposal) !== count(self::FIELDS) || array_diff(self::FIELDS, array_keys($proposal))) return null;
        $result = ManagerAiDraftService::generate($snapshot['context'], static fn(string $instructions, array $context): array => $proposal,
            $snapshot['company_knowledge'], null, $snapshot['reference_context'] ?? null);
        if (($result['ok'] ?? null) !== true) return null;
        $ordered = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $result) || $result[$field] !== $proposal[$field]) return null;
            $ordered[$field] = $result[$field];
        }
        $json = json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return strlen($json) <= self::MAX_PROPOSAL_BYTES ? $json : null;
    }

    private static function snapshotFor(PDO $pdo, array $row): ?array
    {
        $snapshot = ManagerAiDraftContextService::preparationSnapshot((int)$row['conversation_id'], (int)$row['manager_id']);
        return self::matches($snapshot, $row['context_revision'], (int)$row['latest_message_id'])
            && self::projectFor($pdo, (int)$row['conversation_id'], (int)$row['manager_id']) === $row['project_key'] ? $snapshot : null;
    }

    private static function projectFor(PDO $pdo, int $cid, int $actor,?string $readProject=null): ?string
    {
        $q = $pdo->prepare('SELECT c.project_key FROM conversations c WHERE c.id=? AND c.is_test=0 AND NOT '.InternalOwnerDialogueScope::conversationSql('c').' AND '.($actor === 0 ? "c.manager_id IS NULL AND c.status='waiting_manager'" : "c.manager_id=? AND c.status='manager'"));
        $q->execute($actor === 0 ? [$cid] : [$cid,$actor]);
        $project = $q->fetchColumn();
        return self::project($project) && $project === ($readProject??ProjectConfig::projectId()) ? $project : null;
    }

    private static function latestBinding(PDO $pdo, int $cid, int $actor, string $project, string $revision, string $instruction): ?array
    {
        $q = $pdo->prepare('SELECT * FROM manager_prepared_replies WHERE conversation_id=? AND manager_id=? AND project_key=? AND context_revision=? AND instruction_revision=? ORDER BY created_at DESC,job_id DESC LIMIT 1');
        $q->execute([$cid,$actor,$project,$revision,$instruction]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row && self::validRow($row) ? $row : null;
    }

    private static function noContact(array $row, array $snapshot): bool
    {
        $json = $row['proposal_json'] ?? null;
        if ($row['status'] !== 'ready' || !is_string($json) || strlen($json)>self::MAX_PROPOSAL_BYTES
            || !self::revision($row['proposal_sha256'] ?? null) || !hash_equals($row['proposal_sha256'],hash('sha256',$json))) return false;
        $proposal = json_decode($json,true);
        return is_array($proposal) && ($proposal['next_action'] ?? null) === 'no_contact'
            && self::validatedJson($proposal,$snapshot) === $json;
    }

    private static function load(PDO $pdo, string $id): ?array
    {
        $q = $pdo->prepare('SELECT * FROM manager_prepared_replies WHERE job_id=?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row && self::validRow($row) && $row['project_key'] === ProjectConfig::projectId() ? $row : null;
    }

    private static function validRow(array $row): bool
    {
        if (!self::revision($row['job_id'] ?? null) || !self::revision($row['context_revision'] ?? null)
            || !self::revision($row['instruction_revision'] ?? null) || !self::project($row['project_key'] ?? null)) return false;
        if (filter_var($row['manager_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]) === false) return false;
        foreach (['conversation_id','latest_message_id'] as $key) {
            if (filter_var($row[$key] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) return false;
        }
        return self::timestamp($row['created_at'] ?? null) !== null && self::timestamp($row['expires_at'] ?? null) !== null;
    }

    private static function currentRow(array $row): bool
    {
        $created = self::timestamp($row['created_at'] ?? null);
        $expires = self::timestamp($row['expires_at'] ?? null);
        return $created !== null && $expires !== null && $created <= time() && $expires > time()
            && (($row['status'] ?? null) !== 'pending' || $expires === $created + self::TTL_SECONDS)
            && hash_equals(self::instructionRevision(), $row['instruction_revision']);
    }

    private static function readyTimes(array $row): bool
    {
        $created = self::timestamp($row['created_at'] ?? null);
        $prepared = self::timestamp($row['prepared_at'] ?? null);
        $expires = self::timestamp($row['expires_at'] ?? null);
        return $created !== null && $prepared !== null && $expires !== null
            && $prepared >= $created && $prepared <= time() && $expires === $prepared + self::TTL_SECONDS && $expires > time();
    }

    private static function matches(array $snapshot, $revision, $latest): bool
    {
        return ($snapshot['ok'] ?? null) === true && self::revision($revision)
            && is_int($latest) && $latest > 0 && ($snapshot['latest_message_id'] ?? null) === $latest
            && self::revision($snapshot['revision'] ?? null) && hash_equals($revision, $snapshot['revision']);
    }

    private static function instructionRevision(): string { return hash('sha256', ManagerAiDraftService::instructions()); }
    private static function revision($value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1; }
    private static function project($value): bool { return is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', $value) === 1; }
    private static function failure(string $error): array { return ['ok'=>false,'error'=>$error]; }
    private static function utc(int $timestamp): string { return gmdate('Y-m-d H:i:s', $timestamp); }
    private static function timestamp($value): ?int
    {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : null;
    }
}
