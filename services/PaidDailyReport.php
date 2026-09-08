<?php

declare(strict_types=1);

require_once __DIR__.'/LiveSessionAnalyzer.php';

/** Read-only advertising cohorts; no identifiers or message text leave this collector. */
final class PaidDailyReport
{
    private const FIELDS = ['needs_collected','tours_opened','site_opened','manager_requested','manager_replied'];

    public static function collect(
        PDO $pdo,
        string $baseDir,
        string $project,
        DateTimeImmutable $now,
        ?callable $paidChatResolver = null,
        string $attributionBasis = 'current_saved_traffic_yclid'
    ): array
    {
        $utc = new DateTimeZone('UTC');
        $local = $now->setTimezone(new DateTimeZone('Europe/Kaliningrad'));
        $start = $local->setTime(0, 0)->modify('-6 days');
        $until = $now->setTimezone($utc)->format('Y-m-d H:i:s');
        $report = ['ok'=>true, 'generated_at'=>$now->setTimezone($utc)->format('c'),
            'timezone'=>'Europe/Kaliningrad', 'channel'=>'max',
            'attribution_basis'=>$attributionBasis,
            'cohort_basis'=>'conversation_started_local_day',
            'outcome_window'=>'same_local_day_until_capture', 'days'=>[]];
        for ($day=$start; $day <= $local; $day=$day->modify('+1 day')) {
            $date=$day->format('Y-m-d');
            $report['days'][$date]=['date'=>$date, 'partial'=>$date===$local->format('Y-m-d'),
                'all_new'=>0, 'paid_new'=>0, 'without_saved_yclid'=>0];
            foreach (self::FIELDS as $field) $report['days'][$date][$field]=0;
        }
        $q=$pdo->prepare("SELECT id,channel,status,external_chat_id,started_at FROM conversations WHERE project_key=? AND channel='max' AND is_test=0 AND started_at>=? AND started_at<? ORDER BY started_at,id LIMIT 2001");
        $q->execute([$project, $start->setTimezone($utc)->format('Y-m-d H:i:s'), $until]);
        $conversations=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($conversations)>2000) throw new RuntimeException('paid_report_conversation_limit');
        $chatKeys=[];
        foreach($conversations as $conversation) $chatKeys[]=(string)$conversation['external_chat_id'];
        $chatKeys=array_values(array_unique($chatKeys));
        $paidChats=$paidChatResolver === null
            ? self::savedTrafficYclidMap($baseDir,$chatKeys)
            : self::normalizePaidChatMap($paidChatResolver($chatKeys),$chatKeys);
        $messages=$pdo->prepare('SELECT direction,sender_type,text,created_at FROM messages WHERE conversation_id=? AND created_at>=? AND created_at<? ORDER BY created_at,id LIMIT 1001');
        $events=$pdo->prepare('SELECT event_type,created_at FROM conversation_events WHERE conversation_id=? AND created_at>=? AND created_at<? ORDER BY created_at,id LIMIT 1001');
        foreach ($conversations as $conversation) {
            $began=new DateTimeImmutable($conversation['started_at'], $utc);
            $date=$began->setTimezone($local->getTimezone())->format('Y-m-d');
            $row=&$report['days'][$date];
            $row['all_new']++;
            if (empty($paidChats[(string)$conversation['external_chat_id']])) {
                $row['without_saved_yclid']++;
                unset($row);
                continue;
            }
            $row['paid_new']++;
            $dayEnd=$began->setTimezone($local->getTimezone())->setTime(0,0)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            $args=[$conversation['id'],$conversation['started_at'],min($until,$dayEnd)];
            $messages->execute($args); $messageRows=$messages->fetchAll(PDO::FETCH_ASSOC);
            $events->execute($args); $eventRows=$events->fetchAll(PDO::FETCH_ASSOC);
            if(count($messageRows)>1000 || count($eventRows)>1000) throw new RuntimeException('paid_report_evidence_limit');
            // Current status may reflect a later day; same-day evidence is authoritative here.
            $conversation['status']='';
            foreach($messageRows as &$message) $message['created_at'].=' UTC';
            unset($message);
            foreach($eventRows as &$event) $event['created_at'].=' UTC';
            unset($event);
            $session=LiveSessionAnalyzer::analyze($conversation,$messageRows,$eventRows);
            foreach(self::FIELDS as $field) if(!empty($session[$field])) $row[$field]++;
            unset($row);
        }
        $report['days']=array_values($report['days']);
        return $report;
    }

    private static function savedTrafficYclidMap(string $baseDir, array $chatKeys): array
    {
        if(!is_dir($baseDir.'/traffic') || !is_readable($baseDir.'/traffic')) throw new RuntimeException('paid_report_traffic_store_unavailable');
        $paid=[];
        foreach($chatKeys as $chat) {
            if(!preg_match('/\A-?[0-9]+\z/', $chat)) throw new RuntimeException('paid_report_invalid_chat_key');
            // Do not use TrafficAttributionService::get(): its path helper can create a directory.
            $path=rtrim($baseDir,'/').'/traffic/'.$chat.'.json';
            if(is_link($path)) throw new RuntimeException('paid_report_invalid_traffic_file');
            if(!file_exists($path)) continue;
            if(!is_file($path)||!is_readable($path)||filesize($path)>65536) throw new RuntimeException('paid_report_invalid_traffic_file');
            $raw=file_get_contents($path);
            $meta=json_decode($raw===false?'':$raw,true);
            if(!is_array($meta)) throw new RuntimeException('paid_report_invalid_traffic_file');
            $value=$meta['yclid']??'';
            if(is_scalar($value) && trim((string)$value)!=='') $paid[$chat]=true;
        }
        return $paid;
    }

    private static function normalizePaidChatMap($resolved, array $chatKeys): array
    {
        if(!is_array($resolved)) throw new RuntimeException('paid_report_invalid_attribution_result');
        $allowed=array_fill_keys($chatKeys,true);
        $paid=[];
        foreach($resolved as $chat=>$value) {
            $chat=(string)$chat;
            if(isset($allowed[$chat]) && $value) $paid[$chat]=true;
        }
        return $paid;
    }
}
