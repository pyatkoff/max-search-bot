<?php

require_once __DIR__ . '/DiagnosticLogger.php';

/**
 * File-based analytics primitives used by the MAX Search project.
 * No Bitrix access and no knowledge of business exclusions/deduplication rules.
 */
class AnalyticsService
{
    public static function queueMetrika($baseDir, $chatID, $yclid, $target, array $meta = [])
    {
        $yclid = trim((string)$yclid);
        $target = trim((string)$target);
        if ($yclid === '' || $target === '') return false;

        $file = rtrim((string)$baseDir, '/') . '/metrika_offline_queue.csv';
        $new = !is_file($file) || @filesize($file) === 0;
        $fp = @fopen($file, 'ab');
        if (!$fp) return false;

        $ok = false;
        if (@flock($fp, LOCK_EX)) {
            if ($new) fputcsv($fp, ['Yclid', 'Target', 'DateTime']);
            $ok = fputcsv($fp, [$yclid, $target, date('Y-m-d H:i:s')]) !== false;
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        if (!$ok) return false;

        $event = [
            'chat_id' => $chatID,
            'yclid' => $yclid,
            'target' => $target,
            'region_id' => $meta['region_id'] ?? '',
            'campaign_id' => $meta['campaign_id'] ?? '',
        ];
        @file_put_contents(
            rtrim((string)$baseDir, '/') . '/metrika_events.log',
            date('d.m.Y H:i:s') . '--- ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        DiagnosticLogger::info('metrika', 'goal_queued', $chatID, $event);
        return true;
    }

    public static function funnel($baseDir, $chatID, $event, array $details = [], array $meta = [])
    {
        try {
            $event = trim((string)$event);
            if ($event === '') return false;

            $yclid = trim((string)($meta['yclid'] ?? ''));
            $file = rtrim((string)$baseDir, '/') . '/funnel.csv';
            $new = !is_file($file) || @filesize($file) === 0;
            $fp = @fopen($file, 'ab');
            if (!$fp) return false;

            $ok = false;
            if (@flock($fp, LOCK_EX)) {
                if ($new) fputcsv($fp, ['DateTime','ChatID','YclidText','RegionID','CampaignID','Event','Details']);
                $ok = fputcsv($fp, [
                    date('Y-m-d H:i:s'),
                    (string)$chatID,
                    $yclid !== '' ? "'" . $yclid : '',
                    (string)($meta['region_id'] ?? ''),
                    (string)($meta['campaign_id'] ?? ''),
                    $event,
                    json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]) !== false;
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);

            if ($ok) DiagnosticLogger::info('funnel', $event, $chatID, $details + ['traffic'=>$meta]);
            return $ok;
        } catch (\Throwable $e) {
            @file_put_contents(
                rtrim((string)$baseDir, '/') . '/funnel_errors.log',
                date('d.m.Y H:i:s') . '--- ' . (string)$event . ' --- ' . $e->getMessage() . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            DiagnosticLogger::error('funnel', 'write_failed', $chatID, ['event'=>$event,'error'=>$e->getMessage()]);
            return false;
        }
    }
}
