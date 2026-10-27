<?php
declare(strict_types=1);

// REVIEW DRAFT. No bootstrap, remote launcher, stdout, or automatic execution.
// Caller must load the pinned canonical ProjectAccessService class definition.
// Return value is PRIVATE and must go straight into the approved encrypted transport.
function f1142ReadOnlyProjection(?PDO &$db): array
{
    $beginAttempted=false; $foreign=false; $failed=false; $rollbackFailed=false; $out=null;
    try {
        if (!$db instanceof PDO || $db!==ConversationDb::connection()) throw new RuntimeException('canonical_handle_required');
        if ($db->inTransaction()) { $foreign=true; throw new RuntimeException('existing_transaction'); }
        if ($db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION) throw new RuntimeException('exception_mode_required');
        $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['mysql','sqlite'],true)) throw new RuntimeException('driver_rejected');
        // The connection is dedicated to this finite audit. Never reuse it afterward.
        if ($driver==='mysql') $db->exec('SET SESSION TRANSACTION READ ONLY');
        else $db->exec('PRAGMA query_only=ON'); // Offline synthetic controls only.
        $beginAttempted=true;
        if (!$db->beginTransaction()) throw new RuntimeException('begin_failed');
        ProjectAccessService::initializeReadOnly();
        $q=$db->prepare("SELECT m.id AS manager_reference,m.display_name AS manager_name,s.source_key,
            CASE WHEN c.id=2 THEN 1 ELSE 0 END AS owner2,
            CASE WHEN c.project_key='anytour' THEN 1 ELSE 0 END AS project_anytour,
            CASE WHEN c.channel='max' THEN 1 ELSE 0 END AS channel_max,
            CASE WHEN p.is_active=1 THEN 1 ELSE 0 END AS active_project,
            CASE WHEN c.manager_id IS NOT NULL AND m.id IS NOT NULL THEN 1 ELSE 0 END AS assigned,
            CASE WHEN s.id IS NOT NULL AND s.is_active=1 THEN 1 ELSE 0 END AS source_match
            FROM conversations c
            JOIN customers cu ON cu.id=c.customer_id
            JOIN projects p ON p.project_key=c.project_key
            LEFT JOIN managers m ON m.id=c.manager_id
            LEFT JOIN conversation_sources s ON s.id=c.source_id AND s.project_id=p.id AND s.channel=c.channel
            WHERE c.id=? AND c.project_key=? AND c.channel=? AND cu.display_name=? LIMIT 1");
        $q->execute([2,'anytour','max','Pavel']);
        $r=$q->fetch(PDO::FETCH_ASSOC);
        $out=['state'=>'HOLD','matched'=>false,'manager_reference'=>null,'manager_name'=>null,'source_key'=>null,
            'owner2'=>false,'project_anytour'=>false,'channel_max'=>false,'active_project'=>false,'assigned'=>false,'source_match'=>false,
            'sampled_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'database_writes'=>0];
        if (is_array($r)) {
            $out['matched']=true;
            $out['manager_reference']=$r['manager_reference']===null?null:(int)$r['manager_reference'];
            $out['manager_name']=$r['manager_name']; $out['source_key']=$r['source_key'];
            foreach(['owner2','project_anytour','channel_max','active_project','assigned','source_match'] as $k) $out[$k]=(int)$r[$k]===1;
            // source_match means structural project/channel/active match ONLY.
            // It does NOT establish a campaign-specific expected source.
            if ($out['owner2']&&$out['project_anytour']&&$out['channel_max']&&$out['active_project']&&$out['assigned']&&$out['source_match']) $out['state']='PASS';
        }
    } catch (Throwable $e) {
        $failed=true; // Do not retain or chain raw driver/SQL/config exceptions.
    } finally {
        if ($beginAttempted) {
            // One rollback attempt only. ALL driver calls here are guarded.
            try {
                if (!$db->inTransaction() || !$db->rollBack() || $db->inTransaction()) $rollbackFailed=true;
            } catch (Throwable $ignored) { $rollbackFailed=true; }
        }
    }
    if ($rollbackFailed) { $db=null; throw new RuntimeException('audit_rollback_failed'); }
    if ($failed) {
        if (!$foreign) $db=null;
        throw new RuntimeException($foreign?'audit_existing_transaction':'audit_projection_failed');
    }
    $out['read_only_rollback']=true;
    return $out;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) {
    fwrite(STDERR,"review_draft_no_remote_launcher\n"); exit(64);
}
