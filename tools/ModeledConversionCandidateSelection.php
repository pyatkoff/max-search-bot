<?php
declare(strict_types=1);

require_once __DIR__.'/../services/InternalOwnerDialogueScope.php';

/** Private dialogue-review planning only. No connection, source read or exposure write. */
final class ModeledConversionCandidateSelection
{
    public const VERSION='episode_selection_v1';

    /** Database-local persistence timestamps, normalized by the authorized caller. */
    public static function candidateQuery(string $from,string $through,string $platform='max'): array
    {
        foreach ([$from,$through] as $value) {
            $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
            self::need($d!==false&&$d->format('Y-m-d H:i:s')===$value,'invalid_query_time');
        }
        self::need($from<=$through&&in_array($platform,['max','telegram'],true),'invalid_query_scope');
        // Fence before MAX/ranking; old users and unassigned conversations qualify.
        // Text presence bounds dialogue review, not the population of all ad visitors.
        $sql="SELECT c.id AS conversation_id,c.customer_id,c.channel,c.started_at,MAX(m.created_at) AS last_activity_at
            FROM conversations c JOIN messages m ON m.conversation_id=c.id
            WHERE c.project_key='anytour' AND c.channel=? AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')."
            AND m.direction='inbound' AND m.sender_type='customer' AND LENGTH(TRIM(m.text))>0
            AND m.created_at>=? AND m.created_at<=?
            GROUP BY c.id,c.customer_id,c.channel,c.started_at
            ORDER BY last_activity_at DESC,c.id DESC LIMIT 100";
        return ['sql'=>$sql,'bindings'=>[$platform,$from,$through],'candidate_cap'=>100,
            'source_clock'=>'database_persistence_requires_caller_utc_normalization',
            'population'=>'dialogues_with_recorded_customer_text_not_all_visitors',
            'has_more'=>'unknown_at_cap'];
    }

    public static function groupKey(array $subject): string
    {
        self::subject($subject);
        return self::digest([$subject['project_key'],$subject['customer_key']]);
    }

    public static function episodeKey(array $subject): string
    {
        self::subject($subject);
        return self::digest(array_map(static fn($k)=>$subject[$k],
            ['project_key','platform','customer_key','conversation_id','episode_key']));
    }

    /**
     * Rows and ledgers are private, authorized producer assertions, not authentication.
     * Ledger absence is UNKNOWN. Counts cover ALL source message rows, before filtering.
     */
    public static function plan(array $rows,array $ledger,array $excludedPeople,string $from,string $cutoff): array
    {
        self::moment($from);self::moment($cutoff);
        self::need($from<=$cutoff&&count($rows)<=100,'candidate_limit_or_interval');
        self::fields($ledger,['episodes','people']);
        foreach ($ledger as $entries) {
            self::need(is_array($entries)&&count($entries)<=2000,'ledger_limit');
            foreach ($entries as $key=>$state) {
                self::need(is_string($key)&&preg_match('/\A[a-f0-9]{64}\z/D',$key)===1
                    &&in_array($state,['unseen','reviewed','unknown'],true),'invalid_ledger');
            }
        }
        self::need(count($excludedPeople)<=2000,'exclusion_limit');
        foreach ($excludedPeople as $key) self::need(is_string($key)&&preg_match('/\A[a-f0-9]{64}\z/D',$key)===1,'invalid_exclusion');
        $unique=[];
        foreach ($rows as $row) {
            self::need(is_array($row),'invalid_candidate');
            self::fields($row,['subject','anchor_id','context_from','last_activity_at','identity_status','trip_status','access_status','source_message_count']);
            self::subject($row['subject']);self::key($row['anchor_id']);
            self::moment($row['context_from']);self::moment($row['last_activity_at']);
            self::need($row['context_from']<=$row['last_activity_at'],'invalid_episode_interval');
            self::need(in_array($row['identity_status'],['bound','unknown'],true)
                &&in_array($row['trip_status'],['accepted','unknown'],true)
                &&in_array($row['access_status'],['allowed','denied','unknown'],true)
                &&is_int($row['source_message_count'])&&$row['source_message_count']>=0,'invalid_candidate_status');
            ksort($row['subject']);ksort($row);
            $id=self::episodeKey($row['subject']);
            self::need(!isset($unique[$id])||$unique[$id]===$row,'conflicting_episode');
            $unique[$id]=$row;
        }
        // Input iteration order cannot select the winner; exact duplicates are free.
        uasort($unique,static fn($a,$b)=>strcmp($b['last_activity_at'],$a['last_activity_at'])
            ?:strcmp(self::episodeKey($a['subject']),self::episodeKey($b['subject'])));
        $selected=[];$decisions=[];$messages=0;$reasons=[];
        foreach ($unique as $id=>$row) {
            $s=$row['subject'];$group=self::groupKey($s);
            $episodeExposure=$ledger['episodes'][$id]??'unknown';
            $personExposure=$ledger['people'][$group]??'unknown';
            $reason=match(true) {
                $s['project_key']!=='anytour'||!in_array($s['platform'],['max','telegram'],true)=>'outside_scope',
                $row['last_activity_at']>$cutoff=>'after_cutoff',
                $row['last_activity_at']<$from=>'outside_window',
                $row['access_status']!=='allowed'=>'access_not_established',
                $row['identity_status']!=='bound'=>'identity_unresolved',
                $row['trip_status']!=='accepted'=>'trip_boundary_unresolved',
                in_array($group,$excludedPeople,true)=>'explicit_person_exclusion',
                $episodeExposure==='reviewed'=>'episode_already_reviewed',
                $episodeExposure!=='unseen'=>'episode_exposure_unknown',
                $row['source_message_count']===0=>'no_source_message_rows',
                $row['source_message_count']>50=>'episode_message_cap',
                count($selected)>=5=>'episode_cap',
                $messages+$row['source_message_count']>250=>'total_message_cap',
                default=>'selected',
            };
            $decisions[]=['episode_key'=>$id,'group_key'=>$group,'reason'=>$reason,
                'episode_exposure'=>$episodeExposure,'person_exposure'=>$personExposure];
            $reasons[$reason]=($reasons[$reason]??0)+1;
            if ($reason==='selected') {
                $selected[]=['candidate'=>$row,'group_key'=>$group,'episode_key'=>$id,
                    'person_exposure'=>$personExposure,
                    'unseen_person_candidate'=>$personExposure==='unseen'];
                $messages+=$row['source_message_count'];
            }
        }
        return ['version'=>self::VERSION,'mode'=>'private_development_read_plan_not_capture',
            'selected'=>$selected,'decisions'=>$decisions,'reason_counts'=>$reasons,
            'metadata_rows_supplied'=>count($rows),'unique_episodes'=>count($unique),
            'metadata_cap_reached'=>count($rows)===100,'has_more'=>'not_asserted',
            'planned_episode_reads'=>count($selected),'planned_source_message_rows'=>$messages,
            'actual_source_operations'=>0,'actual_message_rows_read'=>0,
            'capture_authorized'=>false,'export_allowed'=>false];
    }

    private static function subject($s): void
    {
        self::need(is_array($s),'invalid_subject');
        self::fields($s,['project_key','platform','customer_key','conversation_id','episode_key']);
        foreach ($s as $v) self::key($v);
    }
    private static function fields(array $v,array $expected): void
    {
        $keys=array_keys($v);sort($keys);sort($expected);self::need($keys===$expected,'invalid_fields');
    }
    private static function key($v): void
    {
        self::need(is_string($v)&&preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_.-]{0,190}\z/D',$v)===1,'invalid_key');
    }
    private static function moment($v): void
    {
        self::need(is_string($v),'invalid_time');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new DateTimeZone('UTC'));
        self::need($d!==false&&$d->format('Y-m-d\TH:i:s\Z')===$v,'invalid_time');
    }
    private static function digest(array $v): string { return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR)); }
    private static function need(bool $ok,string $reason): void { if (!$ok) throw new InvalidArgumentException($reason); }
}
