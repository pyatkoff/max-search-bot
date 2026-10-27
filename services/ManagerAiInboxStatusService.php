<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerConversationAccessPolicy.php';
require_once __DIR__.'/ManagerAiDraftService.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/**
 * Saved preparation metadata for an already visible Inbox page. This is a
 * candidate indicator, not the full current-context validation used on opening
 * or inserting a reply. It never captures histories, reads proposal text,
 * generates/enqueues work, records feedback or changes a read marker.
 */
final class ManagerAiInboxStatusService
{
    public const MAX_ROWS = 200;
    private const TTL_SECONDS = 3600;

    public static function decorate(array $rows, int $viewer): array
    {
        // A stale input/projection must never carry a previously issued badge
        // through an access failure, missing table or unavailable status read.
        foreach ($rows as &$row) unset($row['ai_preparation_status'],$row['ai_preparation_expires_at']);
        unset($row);
        if ($viewer<=0 || !$rows) return $rows;
        try {
            $inputs=[];
            foreach ($rows as $row) {
                $id=(int)($row['id']??0);
                if ($id<=0 || ($row['delivery_failure_category']??null)==='suspended') continue;
                if (count($inputs)<self::MAX_ROWS || isset($inputs[$id])) $inputs[$id]=$row;
            }
            if (!$inputs) return $rows;
            ProjectAccessService::initializeReadOnly();
            $pdo=ConversationDb::connection();
            if ($pdo->inTransaction()) return $rows;
            $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!in_array($driver,['mysql','sqlite'],true)) return $rows;
            // Only result classification crosses this query. No draft, reason,
            // checks, transcript or source metadata are selected into PHP.
            $document='CONVERT(j.proposal_json USING utf8mb4)';
            $kind=$driver==='mysql'
                ? "CASE WHEN JSON_VALID({$document}) THEN CASE WHEN JSON_TYPE(JSON_EXTRACT({$document},'$.ok'))='BOOLEAN' AND JSON_TYPE(JSON_EXTRACT({$document},'$.should_draft'))='BOOLEAN' AND JSON_UNQUOTE(JSON_EXTRACT({$document},'$.ok'))='true' THEN CASE WHEN JSON_UNQUOTE(JSON_EXTRACT({$document},'$.should_draft'))='true' AND JSON_TYPE(JSON_EXTRACT({$document},'$.draft'))='STRING' THEN 'ready' WHEN JSON_UNQUOTE(JSON_EXTRACT({$document},'$.should_draft'))='false' AND JSON_TYPE(JSON_EXTRACT({$document},'$.draft'))='NULL' THEN 'recommendation' END END END"
                : "CASE WHEN json_valid(j.proposal_json) THEN CASE WHEN json_type(j.proposal_json,'$.ok')='true' THEN CASE WHEN json_type(j.proposal_json,'$.should_draft')='true' AND json_type(j.proposal_json,'$.draft')='text' THEN 'ready' WHEN json_type(j.proposal_json,'$.should_draft')='false' AND json_type(j.proposal_json,'$.draft')='null' THEN 'recommendation' END END END";
            $ids=array_keys($inputs);$in=implode(',',array_fill(0,count($ids),'?'));
            $now=time();$at=gmdate('Y-m-d H:i:s',$now);
            $instruction=hash('sha256',ManagerAiDraftService::instructions());
            $backgroundEligible="c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND ((c.status='manager' AND c.manager_id>0 AND o.is_active=1) OR (c.status='waiting_manager' AND c.manager_id IS NULL))";
            $q=$pdo->prepare("SELECT c.id,c.project_key,c.source_id,c.channel,c.status,c.manager_id,c.started_at,c.last_message_at,
                v.role AS observed_viewer_role,o.is_active AS owner_is_active,
                j.job_id,j.manager_id AS preparation_owner_id,j.project_key AS preparation_project,
                j.context_revision,j.instruction_revision,j.latest_message_id,j.status AS preparation_state,
                j.created_at,j.prepared_at,j.expires_at,j.purpose,j.requested_by_manager_id,j.viewer_access_revision,j.proposal_sha256,
                {$kind} AS proposal_kind
                FROM manager_prepared_replies j JOIN conversations c ON c.id=j.conversation_id
                JOIN projects p ON p.project_key=c.project_key AND p.is_active=1
                JOIN manager_projects vp ON vp.project_id=p.id AND vp.manager_id=?
                JOIN managers v ON v.id=vp.manager_id AND v.is_active=1
                LEFT JOIN managers o ON o.id=c.manager_id
                WHERE c.id IN ({$in}) AND j.project_key=c.project_key
                AND j.manager_id=COALESCE(c.manager_id,0)
                AND ((c.status='manager' AND c.manager_id=v.id) OR v.role='admin')
                AND j.status IN ('pending','ready') AND j.instruction_revision=? AND j.created_at<=? AND j.expires_at>?
                AND j.latest_message_id=(SELECT MAX(lm.id) FROM messages lm WHERE lm.conversation_id=c.id)
                AND ((j.purpose='open_chat' AND j.requested_by_manager_id=v.id)
                    OR (j.purpose='background' AND j.requested_by_manager_id IS NULL AND j.viewer_access_revision IS NULL AND {$backgroundEligible}))
                ORDER BY j.created_at DESC,j.job_id DESC LIMIT ".(self::MAX_ROWS*8));
            $q->execute(array_merge([$viewer],$ids,[$instruction,$at,$at]));
            $candidates=$q->fetchAll(PDO::FETCH_ASSOC);
            if (!$candidates) return $rows;
            // Resolve current capabilities once after the metadata read. A
            // later role/project/source revocation must withdraw the indicator.
            $manager=ManagerAuthService::byId($viewer);
            if ($manager===null) return $rows;
            $admin=ManagerAuthService::isAdmin($manager);$scopes=$admin?ManagerSupervisorAccessService::scopes($viewer):[];
            $scopeHash=$admin?hash('sha256',json_encode($scopes,JSON_THROW_ON_ERROR)):null;
            $current=[];
            foreach ($candidates as $candidate) $current[(int)$candidate['id']]=$candidate;
            $visible=[];
            if (!$admin) foreach (ManagerConversationAccessPolicy::visibleRows($viewer,array_values($current)) as $row) $visible[(int)$row['id']]=true;
            $found=[];
            foreach ($candidates as $candidate) {
                $id=(int)$candidate['id'];$input=$inputs[$id]??null;
                if ($input===null || !self::sameConversation($input,$candidate)
                    || (string)$candidate['observed_viewer_role']!==(string)$manager['role']) continue;
                if ($admin ? !ManagerSupervisorAccessService::permits($scopes,$candidate) : empty($visible[$id])) continue;
                if (!self::hash($candidate['job_id']) || !self::hash($candidate['context_revision'])
                    || !hash_equals($instruction,(string)$candidate['instruction_revision'])) continue;
                if ($candidate['purpose']==='open_chat') {
                    if ((int)$candidate['requested_by_manager_id']!==$viewer) continue;
                    $access=$candidate['viewer_access_revision'];
                    if ($access===null) {
                        if ((int)$candidate['manager_id']!==$viewer || $candidate['status']!=='manager') continue;
                    } elseif (!$admin || !self::hash($access) || !hash_equals($scopeHash,$access)) continue;
                } elseif (!in_array($candidate['status'],['manager','waiting_manager'],true)
                    || ((int)$candidate['manager_id']>0 && (int)$candidate['owner_is_active']!==1)) continue;
                $created=self::timestamp($candidate['created_at']);$expires=self::timestamp($candidate['expires_at']);
                if ($created===null || $expires===null || $created>$now || $expires<=$now) continue;
                $state='pending';
                if ($candidate['preparation_state']==='ready') {
                    $prepared=self::timestamp($candidate['prepared_at']);$state=$candidate['proposal_kind'];
                    if ($prepared===null || $prepared<$created || $prepared>$now || $expires!==$prepared+self::TTL_SECONDS
                        || !self::hash($candidate['proposal_sha256']) || !in_array($state,['ready','recommendation'],true)) continue;
                } elseif ($expires!==$created+self::TTL_SECONDS) continue;
                // A newer requested context awaiting work takes precedence over
                // an older saved result. No old draft is advertised as ready.
                $requested=$candidate['purpose']==='open_chat';$previous=$found[$id]??null;
                if ($previous===null || ($requested&&!$previous['requested'])
                    || ($requested===$previous['requested'] && ($created>$previous['created']
                        || ($created===$previous['created']&&$state==='pending'&&$previous['state']!=='pending')))) {
                    $found[$id]=['state'=>$state,'expires'=>$candidate['expires_at'],'created'=>$created,'requested'=>$requested];
                }
            }
            if (!$found) return $rows;
            // Capabilities and the current conversation tuple must still agree
            // after the metadata read, even if a transfer or source move raced it.
            $in=implode(',',array_fill(0,count($found),'?'));
            $q=$pdo->prepare("SELECT c.id,c.project_key,c.source_id,c.channel,c.status,c.manager_id,v.role,
                CASE WHEN {$backgroundEligible} THEN 1 ELSE 0 END AS background_eligible
                FROM conversations c JOIN projects p ON p.project_key=c.project_key AND p.is_active=1
                JOIN manager_projects vp ON vp.project_id=p.id AND vp.manager_id=?
                JOIN managers v ON v.id=vp.manager_id AND v.is_active=1
                LEFT JOIN managers o ON o.id=c.manager_id WHERE c.id IN ({$in})");
            $q->execute(array_merge([$viewer],array_keys($found)));$confirmed=[];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $current) {
                $id=(int)$current['id'];$status=$found[$id]??null;
                if ($status===null || (string)$current['role']!==(string)$manager['role']
                    || !self::sameConversation($inputs[$id],$current)
                    || (!$status['requested']&&(int)$current['background_eligible']!==1)) continue;
                $confirmed[$id]=$status;
            }
            foreach ($rows as &$row) {
                $status=$confirmed[(int)($row['id']??0)]??null;
                if ($status!==null) {$row['ai_preparation_status']=$status['state'];$row['ai_preparation_expires_at']=$status['expires'];}
            }
            unset($row);
        } catch (Throwable $ignored) { /* Missing/unavailable metadata never breaks the Inbox. */ }
        return $rows;
    }

    private static function sameConversation(array $input,array $current): bool
    {
        foreach (['project_key','channel','status'] as $key) if ((string)($input[$key]??'')!==(string)($current[$key]??'')) return false;
        foreach (['source_id','manager_id'] as $key) if ((int)($input[$key]??0)!==(int)($current[$key]??0)) return false;
        return true;
    }
    private static function hash($value): bool { return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1; }
    private static function timestamp($value): ?int
    {
        if (!is_string($value)) return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $date!==false&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():null;
    }
}
