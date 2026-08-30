<?php

declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ProjectConfig.php';

final class TelegramStartSourceResolver
{
    public static function resolve(array $incoming, ?callable $existingSourceLookup = null): string
    {
        $default = (string)ProjectConfig::get('messenger.telegram.source_key', 'tg:anytour-main');
        $lookup = $existingSourceLookup ?: static fn(string $sourceKey): string => self::existingSource($sourceKey);
        $text = trim((string)($incoming['text'] ?? ''));
        if (!preg_match('~^/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]{1,64}))?\s*$~u', $text, $m)) {
            return $lookup($default) ?: $default;
        }

        $payload = trim((string)($m[1] ?? ''));
        if ($payload === '') return $lookup($default) ?: $default;

        foreach (self::candidateKeys($payload) as $candidate) {
            $resolved = (string)$lookup($candidate);
            if ($resolved !== '') return $resolved;
        }

        return $lookup($default) ?: $default;
    }

    public static function candidateKeys(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') return [];
        $candidates = [$payload];
        if (str_starts_with($payload, 'tg_')) {
            $tail = substr($payload, 3);
            if ($tail !== '') {
                $normalized = str_replace('_', '-', $tail);
                $candidates[] = 'tg:' . $normalized;
                $candidates[] = 'telegram:' . $normalized;
            }
        }
        return array_values(array_unique($candidates));
    }

    private static function existingSource(string $sourceKey): string
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '' || !ConversationDb::isConfigured()) return '';
        try {
            $projectId = ProjectAccessService::projectIdByKey(ProjectConfig::projectId());
            if ($projectId <= 0) return '';
            $q = ConversationDb::connection()->prepare(
                "SELECT source_key FROM conversation_sources WHERE project_id=? AND source_key=? AND channel='telegram' AND is_active=1 LIMIT 1"
            );
            $q->execute([$projectId, $sourceKey]);
            return (string)($q->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }
}
