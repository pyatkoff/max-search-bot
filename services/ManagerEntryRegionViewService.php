<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ConversationEntryRegionService.php';
require_once __DIR__.'/YandexRegionCatalog.php';

/** Read-only, data-minimized advertising geography for an authorized lead card. */
final class ManagerEntryRegionViewService
{
    private const MAX_METADATA_BYTES = 262144;

    /** The caller must first authorize this current conversation with visibleConversation. */
    public static function fromVisibleConversation(array $conversation, ?PDO $pdo = null): array
    {
        $empty = ['first_entry_region'=>null, 'latest_entry_region'=>null];
        $id = (int)($conversation['id'] ?? 0);
        $project = $conversation['project_key'] ?? null;
        $platform = $conversation['channel'] ?? null;
        if ($id <= 0 || !is_string($project) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $project)
            || !in_array($platform, ['max','telegram','website'], true)) return $empty;
        try {
            $pdo = $pdo ?? ConversationDb::connection();
            // MySQL LENGTH measures bytes; SQLite needs a BLOB cast for the
            // same transfer limit when the stored JSON contains Unicode.
            $bytes = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'LENGTH(CAST(metadata_json AS BLOB))' : 'LENGTH(metadata_json)';
            $q = $pdo->prepare('SELECT CASE WHEN '.$bytes.'<='.self::MAX_METADATA_BYTES.' THEN metadata_json ELSE NULL END AS metadata_json FROM conversations WHERE id=? AND project_key=? AND channel=? LIMIT 1');
            $q->execute([$id,$project,$platform]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) return $empty;
            $metadata = $row['metadata_json'] ?? null;
            return [
                'first_entry_region'=>self::project(ConversationEntryRegionService::firstReceipt($metadata,$platform)),
                'latest_entry_region'=>self::project(ConversationEntryRegionService::latestReceipt($metadata,$platform)),
            ];
        } catch (Throwable $ignored) { return $empty; }
    }

    private static function project(?array $receipt): ?array
    {
        if ($receipt === null) return null;
        $id = $receipt['yandex_region_id'];
        $region = YandexRegionCatalog::findByYandexId($id);
        $name = self::label($region['name'] ?? null);
        $known = $name !== '';
        $hierarchy = $known ? ($region['hierarchy'] ?? []) : [];
        if (!$known) $name = self::label($receipt['name'] ?? null);
        $label = $name !== '' ? $name : 'Регион не определён';
        // One nearby parent disambiguates same-named places without turning
        // the lead card's primary context into a full geography path.
        for ($i = count($hierarchy) - 1; $i >= 0; --$i) {
            if ($hierarchy[$i] !== $name) {
                $label .= ' · '.$hierarchy[$i];
                break;
            }
        }
        return ['yandex_region_id'=>$id, 'name'=>$name, 'label'=>$label,
            'hierarchy'=>$hierarchy, 'known'=>$known];
    }

    private static function label($value): string
    {
        if (!is_string($value) || strlen($value) > 400 || preg_match('/[\x00-\x1f\x7f]/', $value)) return '';
        return trim($value);
    }
}
