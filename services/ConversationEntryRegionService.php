<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ConversationRecordClock.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/TrafficAttributionService.php';
require_once __DIR__.'/YandexRegionCatalog.php';

/** Immutable advertising geography receipt; never applies a travel need. */
final class ConversationEntryRegionService
{
    private const SOURCES = ['max_start_payload','telegram_start_payload','website_entry','incoming_attribution'];
    private const FLAGS = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;

    /** Normalize an explicit entry, never a region mentioned in ordinary customer text. */
    public static function candidate(array $incoming): ?array
    {
        $platform = strtolower(trim((string)($incoming['platform'] ?? '')));
        if (!in_array($platform, ['max','telegram','website'], true)) return null;
        $payload = '';
        $source = 'incoming_attribution';
        $region = null;
        $explicit = $incoming['entry_attribution'] ?? null;
        if (is_array($explicit)) {
            $region = $explicit['region_id'] ?? $explicit['region'] ?? null;
            $hint = $explicit['source'] ?? '';
            if (is_string($hint) && in_array($hint, self::SOURCES, true)) $source = $hint;
        } elseif ($platform === 'max' && ($incoming['type'] ?? '') === 'bot_started') {
            $payload = $incoming['start_payload'] ?? '';
            if (!is_string($payload)) return null;
            $source = 'max_start_payload';
            $region = self::payloadRegion($payload);
        } elseif ($platform === 'telegram' && ($incoming['type'] ?? '') === 'message'
            && preg_match('~\A/start(?:@[A-Za-z0-9_]+)?\s+([A-Za-z0-9_-]{1,512})\s*\z~D', trim((string)($incoming['text'] ?? '')), $m)) {
            $payload = $m[1];
            $source = 'telegram_start_payload';
            $region = self::payloadRegion($payload);
        }
        $id = YandexRegionCatalog::normalizeId($region);
        if ($id === '') return null;
        $reference = '';
        $raw = is_array($incoming['raw'] ?? null) ? $incoming['raw'] : [];
        foreach ([$incoming['delivery_key'] ?? null, $incoming['message_id'] ?? null,
            $raw['update_id'] ?? null, $raw['timestamp'] ?? null] as $value) {
            if ((is_string($value) || is_int($value)) && (string)$value !== '') {
                $reference = substr((string)$value, 0, 256);
                break;
            }
        }
        return ['yandex_region_id'=>$id, 'source'=>$source, 'platform'=>$platform,
            'input_sha256'=>hash('sha256', json_encode([hash('sha256',$reference),hash('sha256',$payload),$id,$source], self::FLAGS))];
    }

    /** A missing/invalid entry is a no-op; unknown positive Yandex IDs are retained. */
    public static function record(int $conversationId, string $projectKey, array $incoming, ?PDO $pdo = null, ?int $unix = null): bool
    {
        $candidate = self::candidate($incoming);
        if ($candidate === null) return true;
        if ($conversationId <= 0 || !self::project($projectKey)) return false;
        $own = false;
        $savepoint = false;
        try {
            if ($pdo === null && !ConversationDb::isConfigured()) return false;
            $pdo = $pdo ?? ConversationDb::connection();
            $own = !$pdo->inTransaction();
            if ($own) $pdo->beginTransaction();
            else { $pdo->exec('SAVEPOINT entry_region_capture'); $savepoint = true; }
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $q = $pdo->prepare("SELECT metadata_json FROM conversations WHERE id=? AND project_key=? AND channel=? AND status<>'closed'".$lock);
            $q->execute([$conversationId,$projectKey,$candidate['platform']]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('entry_region_scope');
            $metadata = self::metadata($row['metadata_json'] ?? null);
            if ($metadata === null) throw new RuntimeException('entry_region_metadata');
            $digest = hash('sha256', json_encode([$conversationId,$projectKey,$candidate], self::FLAGS));
            $q = $pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='entry_region_observed' AND payload_json LIKE ? LIMIT 1");
            $q->execute([$conversationId,'%"receipt_sha256":"'.$digest.'"%']);
            if (!$q->fetchColumn()) {
                $at = $unix ?? time();
                ConversationRecordClock::stamp([], $at); // Validate the explicit recording instant.
                $region = YandexRegionCatalog::findByYandexId($candidate['yandex_region_id']);
                $receipt = ['version'=>1, 'yandex_region_id'=>$candidate['yandex_region_id'],
                    'name'=>(string)($region['name'] ?? ''), 'kind'=>'advertising',
                    'source'=>$candidate['source'], 'platform'=>$candidate['platform'],
                    'recorded_at_unix'=>$at, 'receipt_sha256'=>$digest];
                $metadata['entry_region'] = $receipt;
                if (!array_key_exists('first_entry_region', $metadata)) $metadata['first_entry_region'] = $receipt;
                $q = $pdo->prepare("UPDATE conversations SET metadata_json=?,attribution_region=? WHERE id=? AND project_key=? AND channel=? AND status<>'closed'");
                $q->execute([json_encode($metadata,self::FLAGS),$candidate['yandex_region_id'],$conversationId,$projectKey,$candidate['platform']]);
                $q = $pdo->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json) VALUES (?,?,?,?)');
                $q->execute([$conversationId,'entry_region_observed','system',json_encode(ConversationRecordClock::stamp($receipt,$at),self::FLAGS)]);
            }
            if ($own) $pdo->commit();
            elseif ($savepoint) $pdo->exec('RELEASE SAVEPOINT entry_region_capture');
            return true;
        } catch (Throwable $ignored) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                try {
                    if ($own) $pdo->rollBack();
                    elseif ($savepoint) { $pdo->exec('ROLLBACK TO SAVEPOINT entry_region_capture'); $pdo->exec('RELEASE SAVEPOINT entry_region_capture'); }
                } catch (Throwable $rollbackIgnored) {}
            }
            return false;
        }
    }

    /** The caller supplies its actual transport; ProjectConfig never substitutes MAX for Telegram. */
    public static function forChat($chatId, string $platform, ?string $projectKey = null, ?PDO $pdo = null): ?array
    {
        $projectKey = $projectKey ?? ProjectConfig::projectId();
        if (!self::project($projectKey) || !in_array($platform,['max','telegram','website'],true)
            || (!is_string($chatId) && !is_int($chatId)) || (string)$chatId === '') return null;
        try {
            if ($pdo === null && !ConversationDb::isConfigured()) return null;
            $q = ($pdo ?? ConversationDb::connection())->prepare("SELECT metadata_json FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1");
            $q->execute([$projectKey,$platform,(string)$chatId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            $metadata = $row ? self::metadata($row['metadata_json'] ?? null) : null;
            return self::receipt($metadata['entry_region'] ?? null, $platform);
        } catch (Throwable $ignored) { return null; }
    }

    private static function payloadRegion(string $payload): string
    {
        if (strlen($payload) > 512 || preg_match('/[\x00-\x20\x7f]/', $payload)) return '';
        return (string)(TrafficAttributionService::parseStartPayload($payload)['region_id'] ?? '');
    }

    /** Routing reads only the first immutable receipt, never the latest attribution. */
    public static function firstReceipt($metadataJson,string $actualPlatform): ?array
    {
        if(!in_array($actualPlatform,['max','telegram','website'],true))return null;
        $metadata=self::metadata($metadataJson);
        return self::receipt($metadata['first_entry_region']??null,$actualPlatform);
    }

    private static function metadata($json): ?array
    {
        if ($json === null || $json === '') return [];
        if (!is_string($json) || strlen($json) > 262144) return null;
        $value = json_decode($json, true, 32);
        return is_array($value) ? $value : null;
    }

    private static function receipt($value, string $platform): ?array
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || ($value['kind'] ?? '') !== 'advertising'
            || ($value['platform'] ?? '') !== $platform || !in_array($value['source'] ?? '',self::SOURCES,true)
            || !is_string($value['yandex_region_id'] ?? null) || YandexRegionCatalog::normalizeId($value['yandex_region_id']) !== $value['yandex_region_id']
            || $value['yandex_region_id'] === '' || !is_string($value['name'] ?? null) || strlen($value['name']) > 400
            || !is_int($value['recorded_at_unix'] ?? null) || $value['recorded_at_unix'] < 946684800 || $value['recorded_at_unix'] > 4102444800
            || !is_string($value['receipt_sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$value['receipt_sha256'])) return null;
        return array_intersect_key($value,array_flip(['version','yandex_region_id','name','kind','source','platform','recorded_at_unix','receipt_sha256']));
    }

    private static function project(string $value): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $value) === 1;
    }
}
