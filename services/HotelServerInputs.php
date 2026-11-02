<?php
declare(strict_types=1);

/** Private, immutable input ingestion. This class never writes old queue state. */
final class HotelServerInputs
{
    public const OLD_QUEUE = 'HOTEL-CONTINUOUS-20261027';
    public const REQUIRED = ['baseline','criteria','corpus','map','archives','instructions','reserves','accepted_mapping'];
    private function __construct(private array $package) {}
    public function package(): array { return $this->package; }

    public static function json(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v, SORT_STRING);
            foreach ($v as $k => $item) $v[$k] = $sort($item);
            return $v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    public static function sha(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/\A[a-f0-9]{64}\z/D', $v)) throw new DomainException('input_hash_required');
        return $v;
    }

    /** Semantic identity is the complete country/hotel/question/source tuple. */
    public static function key(array $scope): string
    {
        $fields = ['country','hotel','question','source'];
        $keys = array_keys($scope); sort($keys); $expected = $fields; sort($expected);
        if ($keys !== $expected) throw new DomainException('complete_semantic_scope_required');
        foreach ($fields as $f) {
            if (!is_string($scope[$f]) || trim($scope[$f]) !== $scope[$f] || $scope[$f] === '' || strlen($scope[$f]) > 256) {
                throw new DomainException('invalid_semantic_scope');
            }
        }
        if (!preg_match('/\A[A-Z]{2}\z/D', $scope['country'])) throw new DomainException('country_required');
        return hash('sha256', self::json($scope));
    }

    /** Derive acceptance only from original intake receipts and linked resolutions. */
    public static function registry(array $retirement, string $expectedReceipt): array
    {
        $snapshotBytes = $retirement['snapshot_bytes'] ?? '';
        $receiptBytes = $retirement['receipt_bytes'] ?? '';
        if (!hash_equals(self::sha($expectedReceipt), hash('sha256', $receiptBytes))
            || !hash_equals(self::sha($retirement['original_state_sha256'] ?? null), hash('sha256', $snapshotBytes))) {
            throw new DomainException('retirement_bytes_mismatch');
        }
        $receipt = json_decode($receiptBytes, true, 64, JSON_THROW_ON_ERROR);
        $snapshot = json_decode($snapshotBytes, true, 64, JSON_THROW_ON_ERROR);
        if (($receipt['queue_id'] ?? null) !== self::OLD_QUEUE || ($receipt['state'] ?? null) !== 'retired'
            || ($receipt['accepted_batches'] ?? null) !== 19 || ($receipt['acceptance_credit'] ?? null) !== 0
            || ($receipt['snapshot_sha256'] ?? null) !== $retirement['original_state_sha256']) throw new DomainException('retirement_required');
        $rows = [];
        foreach ($snapshot['hotel_work_receipts'] ?? [] as $row) {
            if (($row['queue_id'] ?? null) !== self::OLD_QUEUE) throw new DomainException('foreign_legacy_receipt');
            $index = filter_var($row['stage_index'] ?? null, FILTER_VALIDATE_INT);
            if ($index === false || $index < 0 || isset($rows[$index])) throw new DomainException('duplicate_legacy_stage');
            self::sha($row['stage_id'] ?? null);
            if (!hash_equals(self::sha($row['receipt_sha256'] ?? null), hash('sha256', $row['receipt_json'] ?? ''))) throw new DomainException('legacy_receipt_hash');
            $row['decoded'] = json_decode($row['receipt_json'], true, 64, JSON_THROW_ON_ERROR);
            $rows[$index] = $row;
        }
        $resolutions = [];
        foreach ($snapshot['hotel_work_resolutions'] ?? [] as $r) {
            $i = (int)($r['stage_index'] ?? -1); $old = $rows[$i] ?? null;
            if (!$old || isset($resolutions[$i]) || ($old['decoded']['outcome'] ?? null) !== 'unknown'
                || ($r['queue_id'] ?? null) !== self::OLD_QUEUE || ($r['stage_id'] ?? null) !== $old['stage_id']
                || ($r['unknown_receipt_sha256'] ?? null) !== $old['receipt_sha256']
                || !hash_equals(self::sha($r['receipt_sha256'] ?? null), hash('sha256', $r['receipt_json'] ?? ''))) throw new DomainException('unlinked_legacy_resolution');
            $resolutions[$i] = $r;
        }
        $effective = static fn(int $i): ?array => isset($rows[$i]) ? json_decode(($resolutions[$i] ?? $rows[$i])['receipt_json'], true, 64, JSON_THROW_ON_ERROR) : null;
        $accepted = []; $packets = [];
        foreach ($rows as $i => $row) {
            if ($i % 2 !== 1) continue; // research, UNKNOWN and skipped stages earn no intake credit
            $r = $effective($i);
            if (($r['outcome'] ?? null) !== 'accepted_readback') continue;
            $producer = $effective($i - 1); $packet = self::sha($r['packet_sha256'] ?? null);
            if (($producer['outcome'] ?? null) !== 'accepted_readback' || ($producer['packet_sha256'] ?? null) !== $packet || isset($packets[$packet])) throw new DomainException('legacy_producer_packet_mismatch');
            $packets[$packet] = true;
            $accepted[] = ['stage_index'=>$i,'stage_id'=>$row['stage_id'], 'packet_sha256'=>$packet,
                'receipt_sha256'=>($resolutions[$i] ?? $row)['receipt_sha256']];
        }
        usort($accepted, static fn(array $a,array $b): int => $a['stage_index'] <=> $b['stage_index']);
        if (count($accepted) !== 19) throw new DomainException('exact_19_accepted_intakes_required');
        return $accepted;
    }

    /** The caller supplies an existing read-only app PDO; the file root is private. */
    public static function load(PDO $app, string $expectedRetirement, string $root, string $manifestPath): self
    {
        $s = $app->prepare('SELECT * FROM hotel_work_retirements WHERE queue_id=?'); $s->execute([self::OLD_QUEUE]);
        $retirement = $s->fetch(PDO::FETCH_ASSOC);
        if (!$retirement) throw new DomainException('retirement_missing');
        $realRoot = realpath($root); $realManifest = realpath($manifestPath);
        if (!$realRoot || !$realManifest || !self::inside($realManifest, $realRoot)) throw new DomainException('private_manifest_missing');
        $public = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($public && ($realRoot === $public || self::inside($realRoot, $public))) throw new DomainException('private_inputs_under_document_root');
        $manifest = json_decode((string)file_get_contents($realManifest), true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== 'hotel-server-input-manifest-v1' || !is_array($manifest['files'] ?? null)) throw new DomainException('private_manifest_required');
        $bytes = [];
        foreach (self::REQUIRED as $name) {
            $item = $manifest['files'][$name] ?? null; $relative = $item['path'] ?? null;
            if (!is_string($relative) || !preg_match('~\A[a-zA-Z0-9_/-]+\.[a-zA-Z0-9]+\z~D', $relative)) throw new DomainException('private_file_path');
            $path = realpath($realRoot . '/' . $relative);
            if (!$path || !self::inside($path, $realRoot) || !is_file($path) || filesize($path) > 4194304) throw new DomainException('private_file_missing');
            $bytes[$name] = file_get_contents($path);
            if (!is_string($bytes[$name]) || !hash_equals(self::sha($item['sha256'] ?? null), hash('sha256', $bytes[$name]))) throw new DomainException('private_file_hash');
        }
        return self::validate($retirement, $expectedRetirement, $bytes);
    }

    /** Public to make fixture ingestion identical; no test bypass or live readiness claim. */
    public static function validate(array $retirement, string $expected, array $bytes): self
    {
        $registry = self::registry($retirement, $expected); $pins = [];
        foreach (self::REQUIRED as $name) {
            if (!is_string($bytes[$name] ?? null) || $bytes[$name] === '' || strlen($bytes[$name]) > 4194304) throw new DomainException('complete_private_inputs_required');
            $pins[$name] = hash('sha256', $bytes[$name]);
        }
        $mapping = json_decode($bytes['accepted_mapping'], true, 64, JSON_THROW_ON_ERROR);
        $reserves = json_decode($bytes['reserves'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($mapping) || !array_is_list($mapping) || count($mapping) !== 19 || !is_array($reserves) || !array_is_list($reserves)) throw new DomainException('complete_private_mapping_required');
        $byStage = []; $excluded = [];
        foreach ($mapping as $m) {
            if (!is_array($m) || isset($byStage[$m['stage_id'] ?? '']) || !is_array($m['scopes'] ?? null) || !array_is_list($m['scopes']) || !$m['scopes']) throw new DomainException('invalid_private_mapping');
            foreach ($m['scopes'] as $scope) $excluded[self::key($scope)] = 'historical';
            $byStage[$m['stage_id']] = $m;
        }
        foreach ($registry as $r) {
            $m = $byStage[$r['stage_id']] ?? [];
            foreach (['packet_sha256','receipt_sha256'] as $f) if (($m[$f] ?? null) !== $r[$f]) throw new DomainException('mapping_not_bound_to_accepted_receipt');
        }
        foreach ($reserves as $scope) $excluded[self::key($scope)] = 'protected_reserve';
        ksort($excluded); $identity = ['schema'=>'hotel-server-inputs-v1','retirement_sha256'=>$expected,'pins'=>$pins,'registry'=>$registry,'exclusions'=>$excluded];
        return new self($identity + ['inputs_sha256'=>hash('sha256', self::json($identity)), 'bytes'=>$bytes]);
    }

    private static function inside(string $path, string $root): bool
    { return str_starts_with(str_replace('\\','/',$path), rtrim(str_replace('\\','/',$root),'/') . '/'); }
}
