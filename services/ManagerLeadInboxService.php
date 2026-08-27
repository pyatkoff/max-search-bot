<?php
require_once __DIR__ . '/ConversationDb.php';

/**
 * Read-only projection for Manager Workspace V2 inbox cards.
 *
 * Keeps lead-card presentation data out of the legacy conversation list query:
 * contact/outcome are loaded in one batch and the latest confirmation summary is
 * derived from the preserved transcript without touching dialogue state.
 */
class ManagerLeadInboxService
{
    public static function decorate(array $rows): array
    {
        if (!$rows) return [];

        $ids = array_values(array_unique(array_filter(array_map(
            static fn($row) => (int)($row['id'] ?? 0),
            $rows
        ))));
        if (!$ids) return $rows;

        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo = ConversationDb::connection();

        $lead = [];
        $q = $pdo->prepare("SELECT c.id,c.lead_outcome,cu.phone,cu.email FROM conversations c JOIN customers cu ON cu.id=c.customer_id WHERE c.id IN ({$in})");
        $q->execute($ids);
        foreach ($q->fetchAll() as $item) {
            $lead[(int)$item['id']] = $item;
        }

        $summaries = [];
        $q = $pdo->prepare("SELECT conversation_id,text FROM messages WHERE conversation_id IN ({$in}) AND sender_type='ai' AND direction='outbound' AND text LIKE '%Готово! Проверьте параметры%' ORDER BY id DESC");
        $q->execute($ids);
        foreach ($q->fetchAll() as $message) {
            $id = (int)($message['conversation_id'] ?? 0);
            if ($id <= 0 || array_key_exists($id, $summaries)) continue;
            $summaries[$id] = self::cleanTripSummary((string)($message['text'] ?? ''));
        }

        foreach ($rows as &$row) {
            $id = (int)($row['id'] ?? 0);
            $meta = $lead[$id] ?? [];
            $row['lead_outcome'] = (string)($meta['lead_outcome'] ?: 'open');
            $row['contact_phone'] = $meta['phone'] ?? null;
            $row['contact_email'] = $meta['email'] ?? null;
            $row['trip_summary'] = $summaries[$id] ?? '';
            $row['origin_label'] = self::originLabel($row);
        }
        unset($row);

        return $rows;
    }

    public static function filter(array $rows, string $outcome = '', string $search = ''): array
    {
        $outcome = trim($outcome);
        if (!in_array($outcome, ['', 'open', 'won', 'lost'], true)) $outcome = '';
        $search = trim($search);

        return array_values(array_filter($rows, static function (array $row) use ($outcome, $search): bool {
            if ($outcome !== '' && (string)($row['lead_outcome'] ?? 'open') !== $outcome) return false;
            if ($search === '') return true;

            $haystack = implode(' ', array_filter([
                $row['display_name'] ?? '',
                $row['contact_phone'] ?? '',
                $row['contact_email'] ?? '',
                $row['origin_label'] ?? '',
                $row['manager_name'] ?? '',
                $row['last_text'] ?? '',
                $row['trip_summary'] ?? '',
            ], static fn($value) => $value !== null && $value !== ''));

            return self::contains($haystack, $search);
        }));
    }

    public static function outcomeLabel(string $outcome): string
    {
        return [
            'open' => 'В работе',
            'won' => 'Продажа',
            'lost' => 'Отказ',
        ][$outcome] ?? 'В работе';
    }

    private static function cleanTripSummary(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/^\s*✅\s*Готово!\s*Проверьте параметры\s*/ui', '', $text) ?? $text;
        $text = preg_replace('/\s*Что удобнее дальше\?\s*$/ui', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function originLabel(array $row): string
    {
        $channel = strtoupper(trim((string)($row['channel'] ?? '')));
        $source = trim((string)($row['source_name'] ?? ''));
        if ($source !== '' && strpos($source, ':') !== false) {
            [, $short] = explode(':', $source, 2);
            if (trim($short) !== '') $source = trim($short);
        }
        if ($source === '') $source = trim((string)($row['project_name'] ?? $row['project_key'] ?? ''));
        return trim($channel . ($channel !== '' && $source !== '' ? ' · ' : '') . $source);
    }

    private static function contains(string $haystack, string $needle): bool
    {
        if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        return stripos($haystack, $needle) !== false;
    }
}
