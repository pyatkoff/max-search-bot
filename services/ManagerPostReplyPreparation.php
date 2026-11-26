<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerPreparationEligibility.php';
require_once __DIR__.'/ManagerRequestProfileService.php';
require_once __DIR__.'/ManagerPromiseTaskService.php';
require_once __DIR__.'/ConversationRecordClock.php';

/**
 * Optional post-send source lane for the EXISTING preparation tick.
 * No model calls, customer sends, task writes, or retrospective backfill.
 * All uncertain provider receipts stop cursor advancement rather than fabricate work.
 */
final class ManagerPostReplyPreparation
{
    public const SCAN_LIMIT = 12;
    public const REQUEST_LIMIT = 4;
    private const LANE = 'post_reply';

    public static function collect(PDO $pdo, string $project, array $managerIds, ?string $capturedAtUtc = null): array
    {
        $zero = ['ok'=>true,'state'=>'disabled','scanned'=>0,'requested'=>0,'provider_calls'=>0];
        if (getenv('MANAGER_POST_REPLY_PREPARATION') !== '1') return $zero;
        try {
            if ($pdo->inTransaction() || !self::fullCohort($pdo,$project,$managerIds)) return self::blocked('incomplete_cohort');
            [$oldest,$newest] = ManagerPreparationEligibility::window($capturedAtUtc);
            $q=$pdo->prepare('SELECT after_id,version FROM manager_preparation_cursors WHERE project_key=? AND lane=?');
            $q->execute([$project,self::LANE]);$cursor=$q->fetch(PDO::FETCH_ASSOC);
            if (!$cursor) {
                // Initialize at the observed project high-water mark: never replay old sends.
                $q=$pdo->prepare('SELECT COALESCE(MAX(m.id),0) FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.project_key=?');
                $q->execute([$project]);$high=(int)$q->fetchColumn();
                if ($high<0 || !self::fullCohort($pdo,$project,$managerIds)) return self::blocked('incomplete_cohort');
                $ignore=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
                $q=$pdo->prepare($ignore.' INTO manager_preparation_cursors (project_key,lane,after_id,version) VALUES (?,?,?,1)');
                $q->execute([$project,self::LANE,$high]);
                return ['ok'=>true,'state'=>$q->rowCount()===1?'initialized':'concurrent_initialization',
                    'scanned'=>0,'requested'=>0,'provider_calls'=>0];
            }
            if (filter_var($cursor['after_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false
                ||filter_var($cursor['version'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)
                return self::blocked('invalid_cursor');
            $after=(int)$cursor['after_id'];$version=(int)$cursor['version'];
            $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
            $sql="SELECT m.id,m.conversation_id,m.direction,m.sender_type,m.sender_id,m.channel,m.external_message_id,m.created_at,"
                ."SUBSTR(CAST(m.text AS ".$binary."),1,80001) AS text,"
                ."SUBSTR(CAST(m.metadata_json AS ".$binary."),1,8193) AS metadata,"
                ."c.status AS conversation_status,c.manager_id AS owner_id,c.is_test,c.channel AS conversation_channel,"
                ."ma.id AS known_author FROM messages m "
                ."JOIN conversations c ON c.id=m.conversation_id "
                ."LEFT JOIN managers ma ON ma.id=m.sender_id "
                ."WHERE c.project_key=? AND m.id>? AND m.direction='outbound' AND m.sender_type='manager' "
                ."ORDER BY m.id LIMIT ".self::SCAN_LIMIT;
            $q=$pdo->prepare($sql);$q->execute([$project,$after]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $scanned=0;$requested=0;$last=$after;$state='complete';
            foreach ($rows as $row) {
                ++$scanned;$id=(int)$row['id'];$cid=(int)$row['conversation_id'];$owner=(int)($row['owner_id']??0);
                $author=(int)($row['sender_id']??0);
                if ($id<=$last || $cid<=0) return self::blocked('invalid_source');
                // Known-ineligible records do not become work even if later reassigned.
                if ((int)$row['is_test']!==0 || $row['conversation_status']!=='manager'
                    || $owner<=0 || $owner!==$author || !in_array($owner,$managerIds,true)
                    || !in_array($row['channel'],['max','telegram'],true)
                    || $row['conversation_channel']!==$row['channel']) {
                    $last=$id;continue;
                }
                $raw=(string)($row['metadata']??'');$meta=json_decode($raw,true,32);
                if (!is_array($meta) || strlen($raw)>8192) {$state='unverified_source';break;}
                // An attachment preview is not an authored message. Keep the real File untouched.
                if (self::quotation($meta) || !empty($meta['attachments'])) {$last=$id;continue;}
                $source=ManagerRequestProfileService::messageSource($row);
                $evidence=ManagerPromiseTaskService::sourceEvidence($row,
                    ['channel'=>$row['conversation_channel']],$source,$row['known_author']!==null);
                $clock=ConversationRecordClock::read($raw);
                if ($evidence===null || $clock===null || ($evidence['author_manager_id']??0)!==$owner
                    || ($evidence['id']??0)!==$id) {$state='unverified_source';break;}
                $when=gmdate('Y-m-d H:i:s',$clock);
                if ($when>$newest) {$state='future_source';break;}
                if ($when<$oldest) {$last=$id;continue;}
                $latest=$pdo->prepare('SELECT MAX(id) FROM messages WHERE conversation_id=?');
                $latest->execute([$cid]);
                if ((int)$latest->fetchColumn()!==$id) {$last=$id;continue;}
                $view=ManagerAiDraftContextService::viewerSnapshot($cid,$owner);
                if (empty($view['ok'])) {
                    if (in_array($view['error']??'', ['reply_not_allowed','not_found','project_not_supported','suspended'],true)) {
                        $last=$id;continue;
                    }
                    $state='context_unavailable';break;
                }
                if (($view['project_key']??null)!==$project || (int)($view['owner_id']??0)!==$owner
                    || (int)($view['latest_message_id']??0)!==$id) {$last=$id;continue;}
                // Recheck exact source bytes after the authorized snapshot. An edit,
                // provider-id loss or newly quoted/forwarded message blocks this tick.
                $q=$pdo->prepare('SELECT text,metadata_json,sender_id,external_message_id,channel FROM messages WHERE id=? AND conversation_id=?');
                $q->execute([$id,$cid]);$again=$q->fetch(PDO::FETCH_ASSOC);
                if (!$again || (string)$again['text']!==(string)$row['text']
                    || (string)$again['metadata_json']!==$raw
                    || (string)$again['sender_id']!==(string)$row['sender_id']
                    || (string)$again['external_message_id']!==(string)$row['external_message_id']
                    || $again['channel']!==$row['channel']) {$state='source_changed';break;}
                $out=ManagerPreparedReplyService::requestOnOpen($cid,$owner,$view);
                if (empty($out['ok'])) {$state='queue_unavailable';break;}
                ++$requested;$last=$id;
                if ($requested>=self::REQUEST_LIMIT) {$state='budget';break;}
            }
            if (!self::fullCohort($pdo,$project,$managerIds)) return self::blocked('incomplete_cohort');
            if ($last>$after) {
                $q=$pdo->prepare('UPDATE manager_preparation_cursors SET after_id=?,version=version+1 WHERE project_key=? AND lane=? AND after_id=? AND version=?');
                $q->execute([$last,$project,self::LANE,$after,$version]);
                if ($q->rowCount()!==1) return self::blocked('cursor_conflict');
            }
            return ['ok'=>true,'state'=>$state,'scanned'=>$scanned,'requested'=>$requested,
                'cursor_advanced'=>$last>$after,'provider_calls'=>0];
        } catch (Throwable $ignored) {
            return self::blocked('queue_unavailable');
        }
    }

    private static function fullCohort(PDO $pdo,string $project,array $given): bool
    {
        if (!$given || count($given)>10000) return false;
        foreach ($given as $id) if (!is_int($id) || $id<=0) return false;
        $got=array_values(array_unique($given));sort($got,SORT_NUMERIC);
        $q=$pdo->prepare('SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.is_active=1 AND p.is_active=1 AND p.project_key=? ORDER BY m.id');
        $q->execute([$project]);$expected=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        return $expected!==[] && $got===$expected;
    }

    private static function quotation(array $metadata): bool
    {
        foreach ($metadata as $key=>$value) {
            if (is_string($key) && preg_match('/(?:quote|forward|reply_to|linked_message|original_message)/i',$key)
                && $value!==null && $value!==false && $value!=='') return true;
        }
        return false;
    }

    private static function blocked(string $reason): array
    {
        return ['ok'=>false,'state'=>$reason,'scanned'=>0,'requested'=>0,'provider_calls'=>0];
    }
}
