<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerAiDraftContextService.php';
require_once __DIR__.'/ManagerAiDraftService.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ManagerDraftPilotCollector.php';
require_once __DIR__.'/ManagerPreparationEligibility.php';

/** Private work metadata and reviewed proposals; never a transcript or delivery queue. */
final class ManagerPreparedReplyService
{
    public const MAX_BATCH = 20;
    public const MAX_PENDING = 200;
    public const TTL_SECONDS = 3600;
    public const MAX_PROPOSAL_BYTES = 32768;
    private const FIELDS = ['ok','should_draft','draft','next_action','reason','checks','evidence_message_ids','history_incomplete'];

    /** Opening one authorized chat requests work; it never calls a model or sends. */
    public static function requestOnOpen(int $cid, int $viewer, array $before): array
    {
        try {
            $pdo=ConversationDb::connection();
            if($pdo->inTransaction()||$cid<=0||$viewer<=0)return self::failure('queue_unavailable');
            $fresh=ManagerAiDraftContextService::viewerSnapshot($cid,$viewer);
            if(!self::openViewer($fresh,$viewer))return self::failure($fresh['error']??'reply_not_allowed');
            if(!self::sameViewerSnapshot($fresh,$before))return self::failure('context_stale');
            $owner=(int)$fresh['owner_id'];$project=$fresh['project_key']??null;
            if(!self::project($project)||$project!==ProjectConfig::projectId())return self::failure('reply_not_allowed');
            $available=self::prepared($cid,$owner,$fresh);
            if(!empty($available['prepared']))return ['ok'=>true,'requested'=>true,'preparation_state'=>'ready'];
            $instruction=self::instructionRevision();
            $q=$pdo->prepare("SELECT * FROM manager_prepared_replies WHERE conversation_id=? AND requested_by_manager_id=? AND purpose='open_chat' AND project_key=? AND manager_id=? AND context_revision=? AND instruction_revision=? AND (viewer_access_revision=? OR (viewer_access_revision IS NULL AND ? IS NULL)) ORDER BY created_at DESC,job_id DESC LIMIT 8");
            $access=$fresh['viewer_access_revision']??null;
            $q->execute([$cid,$viewer,$project,$owner,$fresh['revision'],$instruction,$access,$access]);$previous=null;$pending=false;
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                if(!self::validRow($row)||!self::openBindingMatches($row,$fresh))continue;
                if(self::currentRow($row)&&$row['status']==='pending')$pending=true;
                if($previous===null)$previous=$row;
            }
            // Cleanup has its own bounded lane; unrelated recent contexts must
            // never hide the current binding's deduplication/renewal identity.
            $q=$pdo->prepare("SELECT * FROM manager_prepared_replies WHERE conversation_id=? AND requested_by_manager_id=? AND purpose='open_chat' AND project_key=? AND status='pending' AND expires_at>? ORDER BY created_at DESC,job_id DESC LIMIT 8");
            $q->execute([$cid,$viewer,$project,self::utc(time())]);$obsolete=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)if(self::validRow($row)
                &&(!self::openBindingMatches($row,$fresh)||!hash_equals($instruction,$row['instruction_revision'])))$obsolete[]=$row;
            // Rows observed after the first capture may already belong to a
            // newer context. Recheck before even retiring those exact identities.
            $after=ManagerAiDraftContextService::viewerSnapshot($cid,$viewer);
            if(!self::openViewer($after,$viewer)||!self::sameViewerSnapshot($fresh,$after))return self::failure('context_stale');
            // A worker may have completed between the first cache miss and the
            // metadata selection. Reuse that immutable result, including advice
            // to send nothing, rather than renewing completed current work.
            $available=self::prepared($cid,$owner,$after);
            if(!empty($available['prepared']))return ['ok'=>true,'requested'=>true,'preparation_state'=>'ready'];
            $background=self::latestBinding($pdo,$cid,$owner,$project,$fresh['revision'],$instruction);
            if($background!==null&&$background['status']==='pending'&&self::currentRow($background)
                &&self::snapshotFor($pdo,$background)!==null)return ['ok'=>true,'requested'=>true,'preparation_state'=>'pending'];
            if($pending)return ['ok'=>true,'requested'=>true,'preparation_state'=>'pending'];
            $binding=['manager_open_prepared_reply_v1',$cid,$owner,$viewer,$project,$fresh['revision'],$instruction,$fresh['viewer_access_revision']??null];
            if($previous!==null)$binding[]=$previous['job_id'];
            $job=hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR));$now=time();
            // Retire only observed obsolete identities with an exact CAS. A slow
            // older opener must never cancel a newer request inserted meanwhile.
            foreach($obsolete as $old){
                $q=$pdo->prepare("UPDATE manager_prepared_replies SET expires_at=? WHERE job_id=? AND purpose='open_chat' AND requested_by_manager_id=? AND status='pending' AND context_revision=? AND instruction_revision=? AND expires_at=?");
                $q->execute([self::utc($now),$old['job_id'],$viewer,$old['context_revision'],$old['instruction_revision'],$old['expires_at']]);
            }
            $q=$pdo->prepare("SELECT COUNT(*) FROM manager_prepared_replies WHERE project_key=? AND status='pending' AND expires_at>?");$q->execute([$project,self::utc($now)]);
            if((int)$q->fetchColumn()>=self::MAX_PENDING)return self::failure('preparation_queue_full');
            $after=ManagerAiDraftContextService::viewerSnapshot($cid,$viewer);
            if(!self::openViewer($after,$viewer)||!self::sameViewerSnapshot($fresh,$after))return self::failure('context_stale');
            try{
                $q=$pdo->prepare("INSERT INTO manager_prepared_replies (job_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,status,created_at,expires_at,purpose,requested_by_manager_id,viewer_access_revision) VALUES (?,?,?,?,?,?,?,'pending',?,?,'open_chat',?,?)");
                $q->execute([$job,$cid,$owner,$project,$fresh['revision'],$instruction,$fresh['latest_message_id'],self::utc($now),self::utc($now+self::TTL_SECONDS),$viewer,$fresh['viewer_access_revision']??null]);
            }catch(Throwable $ignored){
                $row=self::load($pdo,$job);
                if($row===null||!self::currentRow($row)||!self::openBindingMatches($row,$after))return self::failure('queue_unavailable');
            }
            return ['ok'=>true,'requested'=>true,'preparation_state'=>'pending'];
        }catch(Throwable $ignored){return self::failure('queue_unavailable');}
    }

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
                if(self::projectFor($pdo,$cid,$actor)!==$project){++$out['skipped'];continue;}
                $input=ManagerPreparationEligibility::input($pdo,$cid);
                if($input===null){++$out['skipped'];continue;}
                $handled=ManagerPreparationEligibility::handled($pdo,$cid,$actor,$project,$input);
                if($handled!==null){++$out['existing'];$out['job_ids'][]=$handled;continue;}
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
                    self::rememberLegacyInput($pdo, $previous, $snapshot, $input);
                    if (!$renewExpired || (self::timestamp($previous['expires_at']) ?? PHP_INT_MAX) > time()
                        || $previous['status']==='ready') {
                        ++$out['existing']; $out['job_ids'][] = $previous['job_id']; continue;
                    }
                    $id = hash('sha256', json_encode(['manager_prepared_renewal_v1',$id,$previous['job_id']], JSON_THROW_ON_ERROR));
                }
                $now = time();
                try {
                    if(ManagerPreparationEligibility::input($pdo,$cid)!==$input){++$out['skipped'];continue;}
                    // An edited/new message must not wait behind its obsolete pending revision.
                    $q = $pdo->prepare("UPDATE manager_prepared_replies SET expires_at=? WHERE conversation_id=? AND project_key=? AND purpose='background' AND status='pending' AND job_id<>? AND expires_at>?");
                    $q->execute([self::utc($now),$cid,$project,$id,self::utc($now)]);
                    $q = $pdo->prepare("SELECT COUNT(*) FROM manager_prepared_replies WHERE project_key=? AND status='pending' AND expires_at>?");
                    $q->execute([$project,self::utc($now)]);
                    if ((int)$q->fetchColumn() >= self::MAX_PENDING) { ++$out['skipped']; continue; }
                    $q = $pdo->prepare("INSERT INTO manager_prepared_replies (job_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,status,created_at,expires_at,customer_input_revision) VALUES (?,?,?,?,?,?,?,'pending',?,?,?)");
                    $q->execute([$id,$cid,$actor,$project,$revision,$instruction,$latest,self::utc($now),self::utc($now+self::TTL_SECONDS),$input]);
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
            $q = $pdo->prepare("SELECT lane,after_id,version FROM manager_preparation_cursors WHERE project_key=? AND lane='waiting'");
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
                $page = ManagerDraftPilotCollector::collect($pdo,$managerIds,$limit,null,null,$capturedAtUtc,true,['lane'=>'fresh','after_id'=>$cursor]);
                if (empty($page['ok'])) throw new RuntimeException('collection_failed');
                if ($wrap && $cursor > 0 && $page['candidate_count'] === 0) {
                    $page = ManagerDraftPilotCollector::collect($pdo,$managerIds,$limit,null,null,$capturedAtUtc,true,['lane'=>'fresh','after_id'=>0]);
                    if (empty($page['ok'])) throw new RuntimeException('collection_failed');
                }
                $candidates += $page['candidate_count'];
                array_push($rows, ...$page['conversations']);
                $pages[$lane] = $page;
            };
            // Only recent unanswered input enters automatic work. Existing due
            // tasks and answered active chats are not a recurring archive sweep.
            $capture('waiting',self::MAX_BATCH,true);
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
            $opened=self::openRequest($row);
            if($opened){
                $snapshot=ManagerAiDraftContextService::viewerSnapshot((int)$row['conversation_id'],(int)$row['requested_by_manager_id']);
            }else{
                try{$input=ManagerPreparationEligibility::input($pdo,(int)$row['conversation_id']);}
                catch(Throwable $ignored){++$stats['deferred'];continue;}
                $snapshot=$input===null?['ok'=>false,'error'=>'reply_not_allowed']:ManagerAiDraftContextService::preparationSnapshot((int)$row['conversation_id'],(int)$row['manager_id']);
            }
            $available=($snapshot['ok']??false)===true;
            if (!$available && !in_array($snapshot['error']??'', ['unauthorized','not_found','reply_not_allowed','suspended','invalid_conversation_id'],true)){
                // Storage/transport uncertainty is not proof of stale work.
                ++$stats['deferred'];continue;
            }
            $sameProject=$available && ($opened ? ($snapshot['project_key']??null)===$project
                &&self::openViewer($snapshot,(int)$row['requested_by_manager_id'])&&self::openBindingMatches($row,$snapshot)
                : self::projectFor($pdo,(int)$row['conversation_id'],(int)$row['manager_id'])===$project);
            if ($sameProject && self::matches($snapshot,$row['context_revision'],(int)$row['latest_message_id'])
                && hash_equals(self::instructionRevision(),$row['instruction_revision'])) continue;
            // Never touch a ready result, newer binding or concurrent completion.
            $q=$pdo->prepare("UPDATE manager_prepared_replies SET expires_at=? WHERE job_id=? AND status='pending' AND context_revision=? AND instruction_revision=? AND expires_at=?");
            $q->execute([$now,$row['job_id'],$row['context_revision'],$row['instruction_revision'],$row['expires_at']]);
            if ($q->rowCount()!==1) continue;
            ++$stats['retired'];
            // An opening request can be retired, never automatically recreated
            // from the background collector's different eligibility policy.
            if($opened)continue;
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

    /** Worker inventory: one bounded metadata read, never archive/full context. */
    public static function pending(int $limit = self::MAX_BATCH): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) return self::failure('invalid_limit');
        try {
            $pdo=ConversationDb::connection();
            if($pdo->inTransaction())return self::failure('queue_unavailable');
            $now=self::utc(time());
            $base="SELECT j.job_id,j.conversation_id,j.manager_id,j.project_key,j.context_revision,j.instruction_revision,j.latest_message_id,j.created_at,j.expires_at,j.purpose,j.requested_by_manager_id,j.viewer_access_revision FROM manager_prepared_replies j WHERE j.project_key=? AND j.status='pending' AND j.created_at<=? AND j.expires_at>? AND j.instruction_revision=?"
                .' AND j.latest_message_id=(SELECT MAX(pm.id) FROM messages pm WHERE pm.conversation_id=j.conversation_id)';
            $background=$base." AND j.purpose='background' AND EXISTS (SELECT 1 FROM conversations c LEFT JOIN managers m ON m.id=c.manager_id WHERE c.id=j.conversation_id AND c.project_key=j.project_key AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND ((c.manager_id=j.manager_id AND c.status='manager' AND m.is_active=1) OR (j.manager_id=0 AND c.manager_id IS NULL AND c.status='waiting_manager' AND EXISTS (SELECT 1 FROM managers a JOIN manager_projects ap ON ap.manager_id=a.id JOIN projects p ON p.id=ap.project_id WHERE a.is_active=1 AND a.role='admin' AND p.is_active=1 AND p.project_key=j.project_key))))"
                .' AND '.ManagerPreparationEligibility::sql('j.conversation_id',(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            // Metadata-only current requester/project/source scope. Histories,
            // precise context and access revisions are rechecked in context/save.
            $opened=$base." AND j.purpose='open_chat' AND EXISTS (SELECT 1 FROM conversations c JOIN managers r ON r.id=j.requested_by_manager_id JOIN manager_projects rp ON rp.manager_id=r.id JOIN projects p ON p.id=rp.project_id WHERE c.id=j.conversation_id AND c.project_key=j.project_key AND p.project_key=c.project_key AND p.is_active=1 AND r.is_active=1 AND COALESCE(c.manager_id,0)=j.manager_id AND ((c.status='manager' AND c.manager_id=r.id) OR r.role='admin'))";
            $order=' ORDER BY j.created_at,j.job_id LIMIT '.$limit;
            // Each lane is bounded before union. Interleaving prevents a full
            // archive backlog hiding an opened chat, or opening chats starving
            // ordinary preparation. One empty lane fills from the other.
            // Access changes may invalidate the oldest coarse candidates. Read
            // at most the global pending cap, then stop canonical metadata checks
            // as soon as this lane has enough accepted current rows.
            $openOrder=' ORDER BY j.created_at,j.job_id LIMIT '.self::MAX_PENDING;
            $q=$pdo->prepare('SELECT * FROM ('.$opened.$openOrder.') AS opened_jobs UNION ALL SELECT * FROM ('.$background.$order.') AS background_jobs');
            $args=[ProjectConfig::projectId(),$now,$now,self::instructionRevision()];
            $q->execute(array_merge($args,$args,ManagerPreparationEligibility::window($now)));
            $lanes=['open_chat'=>[],'background'=>[]];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                if(!self::validRow($row))continue;
                if(self::openRequest($row)&&count($lanes['open_chat'])>=$limit)continue;
                if(self::openRequest($row)&&!self::openMetadataAllowed($row))continue;
                foreach(['conversation_id','manager_id','latest_message_id'] as $field)$row[$field]=(int)$row[$field];
                $lanes[$row['purpose']][]=$row;
            }
            $jobs=[];
            for($i=0;$i<$limit&&count($jobs)<$limit;$i++)foreach(['open_chat','background'] as $lane){
                if(isset($lanes[$lane][$i])&&count($jobs)<$limit)$jobs[]=$lanes[$lane][$i];
            }
            return ['ok'=>true,'jobs'=>$jobs];
        }catch(Throwable $ignored){return self::failure('queue_unavailable');}
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
                'preparation_scope'=>self::openRequest($row) ? 'opened_chat' : ((int)$row['manager_id'] === 0 ? 'unassigned_waiting' : 'assigned_manager'),
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
            $requested=self::requestedPrepared($pdo,$cid,$actor,$snapshot);
            if($requested!==null)return $requested;
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
            $q = $pdo->prepare("SELECT * FROM manager_prepared_replies WHERE conversation_id=? AND project_key=? AND purpose='background' AND instruction_revision=? AND latest_message_id=? AND status='ready' AND ((manager_id=? AND context_revision=?) OR (manager_id=0 AND context_revision=?)) ORDER BY prepared_at DESC LIMIT 1");
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
        if(self::openRequest($row)){
            $viewer=(int)$row['requested_by_manager_id'];
            $snapshot=ManagerAiDraftContextService::viewerSnapshot((int)$row['conversation_id'],$viewer);
            return self::openViewer($snapshot,$viewer)&&self::openBindingMatches($row,$snapshot) ? $snapshot : null;
        }
        $input=ManagerPreparationEligibility::input($pdo,(int)$row['conversation_id']);
        if($input===null||isset($row['customer_input_revision'])&&!hash_equals($row['customer_input_revision'],$input))return null;
        $snapshot = ManagerAiDraftContextService::preparationSnapshot((int)$row['conversation_id'], (int)$row['manager_id']);
        return self::matches($snapshot, $row['context_revision'], (int)$row['latest_message_id'])
            && self::projectFor($pdo, (int)$row['conversation_id'], (int)$row['manager_id']) === $row['project_key'] ? $snapshot : null;
    }

    /** Requested work is private to its current viewer, including internal demos. */
    private static function requestedPrepared(PDO $pdo,int $cid,int $owner,array $before): ?array
    {
        $viewer=(int)($before['viewer_id']??$owner);
        if($viewer<=0)return null;
        if(!self::matches($before,$before['revision']??null,$before['latest_message_id']??null)
            ||!self::project($before['project_key']??null))return null;
        $q=$pdo->prepare("SELECT * FROM manager_prepared_replies WHERE conversation_id=? AND requested_by_manager_id=? AND purpose='open_chat' AND project_key=? AND context_revision=? AND instruction_revision=? AND latest_message_id=? AND status='ready' ORDER BY prepared_at DESC,job_id DESC LIMIT 8");
        $q->execute([$cid,$viewer,$before['project_key'],$before['revision'],self::instructionRevision(),$before['latest_message_id']]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);if(!$rows)return null;
        $fresh=ManagerAiDraftContextService::viewerSnapshot($cid,$viewer);
        if(!self::openViewer($fresh,$viewer)||(int)$fresh['owner_id']!==$owner
            ||!self::sameViewerSnapshot($fresh,$before+['owner_id'=>$owner]))return null;
        foreach($rows as $row){
            if(!self::validRow($row)||!self::currentRow($row)||!self::readyTimes($row)||!self::openBindingMatches($row,$fresh))continue;
            $json=$row['proposal_json']??null;
            if(!is_string($json)||strlen($json)>self::MAX_PROPOSAL_BYTES||!self::revision($row['proposal_sha256']??null)
                ||!hash_equals($row['proposal_sha256'],hash('sha256',$json)))continue;
            $proposal=json_decode($json,true,16);
            if(!is_array($proposal)||self::validatedJson($proposal,$fresh)!==$json)continue;
            return ['ok'=>true,'prepared'=>true,'proposal'=>$proposal,'job_id'=>$row['job_id'],
                'revision'=>$fresh['revision'],'latest_message_id'=>(int)$row['latest_message_id'],
                'prepared_at'=>$row['prepared_at'],'expires_at'=>$row['expires_at'],'preparation_source'=>'chatgpt_assistant'];
        }
        return null;
    }

    private static function openRequest(array $row): bool { return ($row['purpose']??'background')==='open_chat'; }

    /** Reuse canonical source/fallback visibility without hydrating any history. */
    private static function openMetadataAllowed(array $row): bool
    {
        $viewer=(int)$row['requested_by_manager_id'];
        $ownerRead=($row['viewer_access_revision']??null)!==null;
        $conversation=ManagerConversationService::visibleConversation((int)$row['conversation_id'],$viewer,$ownerRead);
        if($conversation===null||$conversation['project_key']!==$row['project_key']
            ||(int)($conversation['manager_id']??0)!==(int)$row['manager_id'])return false;
        if(!$ownerRead)return (int)$row['manager_id']===$viewer&&$conversation['status']==='manager';
        if(!ManagerAuthService::isAdmin(ManagerAuthService::byId($viewer)))return false;
        $scopes=ManagerSupervisorAccessService::scopes($viewer);
        return ManagerSupervisorAccessService::permits($scopes,$conversation)
            &&hash_equals($row['viewer_access_revision'],hash('sha256',json_encode($scopes,JSON_THROW_ON_ERROR)));
    }

    private static function openViewer(array $snapshot,int $viewer): bool
    {
        return !empty($snapshot['ok'])&&isset($snapshot['owner_id'])
            && ((empty($snapshot['supervisor_read'])&&(int)$snapshot['owner_id']===$viewer)
                || !empty($snapshot['owner_read']))
            && self::matches($snapshot,$snapshot['revision']??null,$snapshot['latest_message_id']??null);
    }

    private static function sameViewerSnapshot(array $first,array $second): bool
    {
        return self::matches($first,$second['revision']??null,$second['latest_message_id']??null)
            && ($first['owner_id']??null)===($second['owner_id']??null)
            && ($first['viewer_access_revision']??null)===($second['viewer_access_revision']??null);
    }

    private static function openBindingMatches(array $row,array $snapshot): bool
    {
        return self::openRequest($row)&&self::matches($snapshot,$row['context_revision'],(int)$row['latest_message_id'])
            && (int)$row['manager_id']===(int)($snapshot['owner_id']??-1)
            && (int)$row['requested_by_manager_id']===(int)($snapshot['viewer_id']??$snapshot['owner_id']??0)
            && $row['project_key']===($snapshot['project_key']??null)
            && ($row['viewer_access_revision']??null)===($snapshot['viewer_access_revision']??null);
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
        $q = $pdo->prepare("SELECT * FROM manager_prepared_replies WHERE conversation_id=? AND manager_id=? AND project_key=? AND context_revision=? AND instruction_revision=? AND purpose='background' ORDER BY created_at DESC,job_id DESC LIMIT 1");
        $q->execute([$cid,$actor,$project,$revision,$instruction]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row && self::validRow($row) ? $row : null;
    }

    /** Learn only an exactly verified legacy cache binding, never guess from IDs/age. */
    private static function rememberLegacyInput(PDO $pdo, array $row, array $snapshot, string $input): void
    {
        if (($row['customer_input_revision'] ?? null) !== null || $row['status'] !== 'ready'
            || !self::matches($snapshot, $row['context_revision'], (int)$row['latest_message_id'])
            || !hash_equals(self::instructionRevision(), $row['instruction_revision'])) return;
        $created = self::timestamp($row['created_at'] ?? null);
        $prepared = self::timestamp($row['prepared_at'] ?? null);
        $expires = self::timestamp($row['expires_at'] ?? null);
        // Expired genuine completions remain handled, malformed lifetimes do not.
        if ($created === null || $prepared === null || $expires === null
            || $prepared < $created || $prepared > time() || $expires !== $prepared + self::TTL_SECONDS) return;
        $json = $row['proposal_json'] ?? null;
        if (!is_string($json) || strlen($json) > self::MAX_PROPOSAL_BYTES
            || !self::revision($row['proposal_sha256'] ?? null)
            || !hash_equals($row['proposal_sha256'], hash('sha256', $json))) return;
        $proposal = json_decode($json, true);
        if (!is_array($proposal) || self::validatedJson($proposal, $snapshot) !== $json
            || ManagerPreparationEligibility::input($pdo, (int)$row['conversation_id']) !== $input
            || self::projectFor($pdo, (int)$row['conversation_id'], (int)$row['manager_id']) !== $row['project_key']) return;
        // This nullable lookup hint is not proposal/provenance/preview freshness.
        // A concurrent completion/edit must not replace or extend any result.
        $q = $pdo->prepare("UPDATE manager_prepared_replies SET customer_input_revision=? WHERE job_id=? AND customer_input_revision IS NULL AND status='ready' AND context_revision=? AND instruction_revision=? AND latest_message_id=? AND proposal_sha256=? AND proposal_json=? AND expires_at=? AND created_at=? AND prepared_at=? AND conversation_id=? AND manager_id=? AND project_key=?");
        $q->execute([$input, $row['job_id'], $row['context_revision'], $row['instruction_revision'],
            $row['latest_message_id'], $row['proposal_sha256'], $json, $row['expires_at'], $row['created_at'], $row['prepared_at'],
            $row['conversation_id'], $row['manager_id'], $row['project_key']]);
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
        $purpose=$row['purpose']??'background';
        if(!in_array($purpose,['background','open_chat'],true))return false;
        if($purpose==='open_chat'){
            if(filter_var($row['requested_by_manager_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false
                ||($row['viewer_access_revision']??null)!==null&&!self::revision($row['viewer_access_revision']))return false;
        }elseif(($row['requested_by_manager_id']??null)!==null||($row['viewer_access_revision']??null)!==null)return false;
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


