<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerPreparedReplyService.php';
require_once __DIR__.'/LeadTaskService.php';

/** Own-manager work view. Reads do not generate, fetch media or change business state. */
final class ManagerAssistantService
{
    public const MAX_PAGE = 20;
    public const MAX_TASKS = 50;

    /** Cursor is a read-only ordering receipt, never an authorization capability. */
    public static function listing(int $actor, ?string $cursor = null, int $limit = self::MAX_PAGE): array
    {
        if ($actor <= 0 || $limit < 1 || $limit > self::MAX_PAGE
            || ($cursor !== null && preg_match('/\Av1\.([a-f0-9]{64})\.([1-9][0-9]{0,17})\z/D',$cursor,$binding)!==1)) return self::failure('invalid_request');
        try {
            self::initialize($actor);
            $state=self::feedState($actor);
            $offset=0;
            if ($cursor!==null) {
                $index=array_search((int)$binding[2],array_column($state['rows'],'id'),true);
                if (!hash_equals($state['revision'],$binding[1]) || $index===false) return self::failure('list_changed');
                $offset=$index+1;
            }
            $page=array_slice($state['rows'],$offset,$limit);
            $more=count($state['rows'])>$offset+count($page);
            $items=[];
            foreach($page as $row){$item=self::item($row['id'],$actor,$row);if($item!==null)$items[]=$item;}
            // Priority/access/ownership may move while saved AI is validated. Never
            // attach a continuation from a different authorized operational order.
            if (!hash_equals($state['revision'],self::feedState($actor)['revision'])) return self::failure('list_changed');
            return ['ok'=>true,'items'=>$items,'next_cursor'=>$more&&$page?'v1.'.$state['revision'].'.'.$page[count($page)-1]['id']:null,
                'has_more'=>$more,'coverage'=>['scope'=>'own_active','analysis'=>'current_prepared_only']];
        } catch (Throwable $ignored) { return self::failure('assistant_unavailable'); }
    }

    /** Full-scope metadata only; no transcript, prepared context or task body scan. */
    private static function feedState(int $actor): array
    {
        if(ManagerAuthService::byId($actor)===null)throw new RuntimeException('inactive_actor');
        $rows=self::operationalRows($actor);
        $rows=ManagerConversationAccessPolicy::visibleRows($actor,$rows);
        foreach($rows as &$row){
            $row['id']=(int)$row['id'];$row['awaiting_manager_reply']=(bool)$row['awaiting_manager_reply'];
            $row['due_task']=$row['due_at_utc']!==null;
            $row['priority']=$row['awaiting_manager_reply']?0:($row['due_task']?1:2);
        }unset($row);
        // Small fixed metadata for every eligible client is needed to bind mutable
        // ordering. Only the selected page hydrates names/tasks/optional saved AI.
        $revision=hash('sha256',json_encode(['assistant_work_v1',$actor,ProjectConfig::projectId(),array_map(static fn(array $row):array=>[$row['id'],(int)$row['source_id'],$row['channel'],$row['awaiting_manager_reply'],$row['due_task']],$rows)],JSON_THROW_ON_ERROR));
        return ['rows'=>$rows,'revision'=>$revision];
    }

    private static function operationalRows(int $actor,?int $id=null): array
    {
        $reply=ManagerConversationService::awaitingManagerReplySql('c');
        $due="(SELECT MIN(t.due_at_utc) FROM lead_tasks t WHERE t.conversation_id=c.id AND t.status='open' AND t.due_at_utc<=UTC_TIMESTAMP() AND (t.assigned_manager_id IS NULL OR t.assigned_manager_id=c.manager_id))";
        $sql="SELECT c.id,c.project_key,c.source_id,c.channel,c.status,c.manager_id,"
            .$reply.' AS awaiting_manager_reply,'.$due." AS due_at_utc FROM conversations c WHERE c.manager_id=? AND c.status='manager' AND c.is_test=0 AND c.project_key=?";
        $args=[$actor,ProjectConfig::projectId()];
        if($id!==null){$sql.=' AND c.id=?';$args[]=$id;}
        $q=ConversationDb::connection()->prepare($sql.' ORDER BY awaiting_manager_reply DESC,CASE WHEN '.$due.' IS NULL THEN 1 ELSE 0 END,c.id DESC');
        $q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function detail(int $actor, int $conversationId): array
    {
        if ($actor <= 0 || $conversationId <= 0) return self::failure('invalid_request');
        try {
            self::initialize($actor);
            $item = self::item($conversationId, $actor);
            return $item !== null ? ['ok'=>true, 'item'=>$item] : self::failure('not_found');
        } catch (Throwable $ignored) { return self::failure('assistant_unavailable'); }
    }

    /** Decision changes only private recommendation metadata, never task/message state. */
    public static function decide(int $actor, int $conversationId, string $revision, string $decision, ?string $expectedDecision = null): array
    {
        if ($actor <= 0 || $conversationId <= 0 || !self::revision($revision)
            || !in_array($decision, ['done','irrelevant','open'], true)
            || ($expectedDecision !== null && !in_array($expectedDecision, ['done','irrelevant','open'], true))) return self::failure('invalid_request');
        $pdo = null;
        $started = false;
        try {
            self::initialize($actor);
            if (self::ownConversation($conversationId, $actor) === null) return self::failure('not_found');
            $current = self::currentRecommendation($conversationId, $actor);
            if ($current === null || !hash_equals($current['revision'], $revision)) return self::failure('stale_context');
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return self::failure('assistant_unavailable');
            $pdo->beginTransaction();
            $started = true;
            // Recheck actual current ownership/access inside the write transaction.
            if (self::ownConversation($conversationId, $actor) === null) return self::failure('not_found');
            $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $q = $pdo->prepare('SELECT decision FROM manager_assistant_decisions WHERE manager_id=? AND conversation_id=? AND recommendation_revision=? AND project_key=?'.$suffix);
            $key = [$actor, $conversationId, $revision, ProjectConfig::projectId()];
            $q->execute($key);
            $stored = $q->fetchColumn();
            $previous = $stored === false ? null : (string)$stored;
            if ($previous === $decision) {
                $pdo->commit(); $started = false;
                return self::decisionResult($actor,$conversationId,$revision,$decision,true);
            }
            if ($previous !== $expectedDecision) return self::failure('decision_conflict');
            $now = gmdate('Y-m-d H:i:s');
            if ($previous === null) {
                $q = $pdo->prepare('INSERT INTO manager_assistant_decisions (manager_id,conversation_id,project_key,recommendation_revision,context_revision,instruction_revision,decision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
                $q->execute([$actor,$conversationId,ProjectConfig::projectId(),$revision,$current['context_revision'],$current['instruction_revision'],$decision,$now,$now]);
            } else {
                $q = $pdo->prepare('UPDATE manager_assistant_decisions SET decision=?,updated_at=? WHERE manager_id=? AND conversation_id=? AND recommendation_revision=? AND project_key=? AND decision=?');
                $q->execute(array_merge([$decision,$now],$key,[$previous]));
                if ($q->rowCount() !== 1) return self::failure('decision_conflict');
            }
            $pdo->commit(); $started = false;
            return self::decisionResult($actor,$conversationId,$revision,$decision,false);
        } catch (Throwable $ignored) { return self::failure('assistant_unavailable'); }
        finally { if ($started && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); }
    }

    private static function initialize(int $actor): void
    {
        ProjectAccessService::initializeReadOnly();
        if (ManagerAuthService::byId($actor) === null) throw new RuntimeException('inactive_actor');
    }

    private static function decisionResult(int $actor,int $conversationId,string $revision,string $decision,bool $idempotent): array
    {
        // Canonical snapshots own a fresh read transaction, so revalidate after
        // the short metadata commit. A concurrent transcript/access change is
        // reported as stale. Its old revision row can never hide a new proposal.
        $fresh=self::currentRecommendation($conversationId,$actor);
        if ($fresh===null || !hash_equals($fresh['revision'],$revision)) return self::failure('stale_context');
        return ['ok'=>true,'decision'=>$decision,'idempotent'=>$idempotent];
    }

    private static function ownConversation(int $id, int $actor): ?array
    {
        $q = ConversationDb::connection()->prepare("SELECT id FROM conversations WHERE id=? AND manager_id=? AND status='manager' AND is_test=0 AND project_key=?");
        $q->execute([$id,$actor,ProjectConfig::projectId()]);
        if ($q->fetchColumn() === false) return null;
        // An admin's broad edit permission never widens this own-manager view.
        return ManagerConversationService::visibleConversation($id, $actor);
    }

    private static function item(int $id, int $actor, ?array $facts=null): ?array
    {
        $fromFeed=$facts!==null;
        $conversation = self::ownConversation($id, $actor);
        if ($conversation === null) return null;
        $facts=$facts??(self::operationalRows($actor,$id)[0]??null);
        if($facts===null)return null;
        $recommendation = self::currentRecommendation($id, $actor);
        if ($recommendation !== null) {
            $q = ConversationDb::connection()->prepare('SELECT decision FROM manager_assistant_decisions WHERE manager_id=? AND conversation_id=? AND recommendation_revision=? AND project_key=?');
            $q->execute([$actor,$id,$recommendation['revision'],ProjectConfig::projectId()]);
            $decision = $q->fetchColumn();
            $recommendation['decision'] = $decision === false ? null : (string)$decision;
            unset($recommendation['context_revision'], $recommendation['instruction_revision']);
        }
        $tasks = [];
        foreach (LeadTaskService::listForConversation($id) as $task) {
            if (($task['status'] ?? '') !== 'open' || ($task['assigned_manager_id']!==null && (int)$task['assigned_manager_id']!==$actor)) continue;
            $tasks[] = ['id'=>(int)$task['id'], 'title'=>(string)$task['title'], 'due_at_utc'=>$task['due_at_utc'],
                'due_state'=>(string)$task['due_state'], 'status'=>'open', 'is_pinned'=>!empty($task['is_pinned']), 'overdue'=>!empty($task['overdue'])];
        }
        // Listing rechecks the complete authorized ordering once after the page,
        // replacing twenty repeated singleton rechecks. Detail keeps its own fence.
        if (!$fromFeed && self::ownConversation($id, $actor) === null) return null;
        return ['conversation_id'=>$id, 'display_name'=>(string)($conversation['display_name'] ?? 'Турист'),
            'project_key'=>(string)$conversation['project_key'], 'channel'=>(string)$conversation['channel'],
            'last_message_at'=>$conversation['last_message_at'],
            'operational'=>['awaiting_manager_reply'=>(bool)$facts['awaiting_manager_reply'],'due_task'=>$facts['due_at_utc']!==null], 'analysis_state'=>$recommendation === null ? 'unavailable' : 'current',
            'recommendation'=>$recommendation, 'tasks'=>array_slice($tasks,0,self::MAX_TASKS), 'tasks_truncated'=>count($tasks)>self::MAX_TASKS];
    }

    private static function currentRecommendation(int $id, int $actor): ?array
    {
        $instruction = hash('sha256', ManagerAiDraftService::instructions());
        // Avoid reading a transcript when there is no potentially current saved analysis.
        $q = ConversationDb::connection()->prepare("SELECT 1 FROM manager_prepared_replies WHERE conversation_id=? AND project_key=? AND status='ready' AND instruction_revision=? AND expires_at>? AND latest_message_id=(SELECT MAX(id) FROM messages WHERE conversation_id=?) LIMIT 1");
        $q->execute([$id,ProjectConfig::projectId(),$instruction,gmdate('Y-m-d H:i:s'),$id]);
        if ($q->fetchColumn() === false) return null;
        $snapshot = ManagerAiDraftContextService::snapshot($id, $actor);
        if (empty($snapshot['ok'])) return null;
        $prepared = ManagerPreparedReplyService::prepared($id, $actor, $snapshot);
        if (empty($prepared['prepared']) || !is_array($prepared['proposal'] ?? null)) return null;
        $proposal = $prepared['proposal'];
        // Context alone does not include instruction/proposal identity. Bind both.
        $revision = hash('sha256', json_encode(['manager_assistant_recommendation_v1',$prepared['job_id'],$prepared['revision'],$proposal],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return ['revision'=>$revision, 'context_revision'=>$prepared['revision'], 'instruction_revision'=>$instruction,
            'latest_message_id'=>(int)$prepared['latest_message_id'], 'prepared_at'=>$prepared['prepared_at'], 'expires_at'=>$prepared['expires_at'],
            'next_action'=>$proposal['next_action'], 'reason'=>$proposal['reason'], 'checks'=>$proposal['checks'],
            'should_draft'=>$proposal['should_draft'], 'history_incomplete'=>$proposal['history_incomplete'],
            'evidence_message_ids'=>$proposal['evidence_message_ids']];
    }

    private static function revision(string $value): bool { return preg_match('/\A[a-f0-9]{64}\z/D',$value) === 1; }
    private static function failure(string $error): array { return ['ok'=>false, 'error'=>$error]; }
}
