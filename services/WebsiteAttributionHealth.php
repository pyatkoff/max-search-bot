<?php

class WebsiteAttributionHealth
{
    public static function evaluate(array $rows): array
    {
        $ok = true;
        $anomalies = [];
        foreach ($rows as $row) {
            $conversationId = (int)($row['conversation_id'] ?? 0);
            $projectKey = trim((string)($row['project_key'] ?? ''));
            $sourceId = (int)($row['source_id'] ?? 0);
            $sourceKey = trim((string)($row['source_key'] ?? ''));
            $sourceProjectKey = trim((string)($row['source_project_key'] ?? ''));
            $sourceChannel = strtolower(trim((string)($row['source_channel'] ?? '')));

            $reason = null;
            if ($sourceId <= 0 || $sourceKey === '') $reason = 'missing_source';
            elseif ($sourceChannel !== 'website') $reason = 'source_channel_mismatch';
            elseif ($sourceProjectKey === '' || $sourceProjectKey !== $projectKey) $reason = 'source_project_mismatch';

            if ($reason !== null) {
                $ok = false;
                $anomalies[] = [
                    'conversation_id' => $conversationId,
                    'reason' => $reason,
                    'project_key' => $projectKey,
                    'source_id' => $sourceId > 0 ? $sourceId : null,
                    'source_key' => $sourceKey !== '' ? $sourceKey : null,
                    'source_project_key' => $sourceProjectKey !== '' ? $sourceProjectKey : null,
                    'source_channel' => $sourceChannel !== '' ? $sourceChannel : null,
                ];
            }
        }
        return ['ok'=>$ok,'anomalies'=>$anomalies];
    }
}
