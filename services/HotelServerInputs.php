<?php
declare(strict_types=1);

/** Private, immutable input ingestion. This class never writes old queue state. */
final class HotelServerInputs
{
    public const OLD_QUEUE = 'HOTEL-CONTINUOUS-20261027';
    public const REQUIRED = ['baseline','criteria','corpus','map','archives','instructions','reserves','accepted_mapping','quarantine_mapping','accepted_context','stage_contexts'];
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


    /** Every unpaired producer remains excluded, including completed-but-unaccepted TR40. */
    public static function quarantine(array $retirement, string $expected): array
    {
        $accepted = array_fill_keys(array_column(self::registry($retirement,$expected),'packet_sha256'),true);
        $snapshot = json_decode($retirement['snapshot_bytes'],true,64,JSON_THROW_ON_ERROR);
        $resolutions=[]; foreach($snapshot['hotel_work_resolutions']??[] as $r)$resolutions[(int)$r['stage_index']]=$r;
        $out=[];
        foreach($snapshot['hotel_work_receipts']??[] as $r){
            $i=(int)$r['stage_index']; if($i%2!==0)continue;
            $effective=$resolutions[$i]??$r; $body=json_decode($effective['receipt_json'],true,64,JSON_THROW_ON_ERROR);
            $packet=$body['packet_sha256']??null;
            if($packet!==null)self::sha($packet);
            if($packet!==null&&isset($accepted[$packet]))continue;
            $out[]=['stage_index'=>$i,'stage_id'=>$r['stage_id'],'packet_sha256'=>$packet,'receipt_sha256'=>$effective['receipt_sha256']];
        }
        usort($out,static fn(array $a,array $b):int=>$a['stage_index']<=>$b['stage_index']);
        return $out;
    }

    private static function mapRegistry(array $rows, mixed $mapping, array &$excluded, string $kind): array
    {
        if(!is_array($mapping)||!array_is_list($mapping)||count($mapping)!==count($rows))throw new DomainException('complete_'.$kind.'_mapping_required');
        $by=[];
        foreach($mapping as $m){
            if(!is_array($m)||!is_string($m['stage_id']??null)||isset($by[$m['stage_id']])||!is_array($m['scopes']??null)||!array_is_list($m['scopes'])||!$m['scopes'])throw new DomainException('invalid_private_mapping');
            $by[$m['stage_id']]=$m;
        }
        $scopeBindings=[];
        foreach($rows as $r){
            $m=$by[$r['stage_id']]??null;if(!$m)throw new DomainException('mapping_stage_missing');
            foreach(['packet_sha256','receipt_sha256'] as $field)if(!array_key_exists($field,$m)||$m[$field]!==$r[$field])throw new DomainException('mapping_not_bound_to_accepted_receipt');
            foreach($m['scopes'] as $scope){
                $key=self::key($scope);
                if(isset($scopeBindings[$key])&&$scopeBindings[$key]!==$r['stage_id'])throw new DomainException('ambiguous_legacy_scope');
                $scopeBindings[$key]=$r['stage_id'];$excluded[$key]=$kind;
            }
        }
        return $by;
    }

    /** The caller supplies an existing read-only app PDO; the file root is private. */
    public static function load(PDO $app, string $expectedRetirement, string $root, string $manifestPath): self
    {
        $s = $app->prepare('SELECT * FROM hotel_work_retirements WHERE queue_id=?'); $s->execute([self::OLD_QUEUE]);
        $retirement = $s->fetch(PDO::FETCH_ASSOC);
        if (!$retirement) throw new DomainException('retirement_missing');
        $realRoot = realpath($root); $realManifest = realpath($manifestPath);
        if (!$realRoot || !$realManifest || !self::inside($realManifest, $realRoot)) throw new DomainException('private_manifest_missing');
        $public = realpath(dirname(__DIR__));
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
        $mapping = json_decode($bytes['accepted_mapping'],true,64,JSON_THROW_ON_ERROR);
        $reserves = json_decode($bytes['reserves'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($reserves)||!array_is_list($reserves))throw new DomainException('complete_private_mapping_required');
        $excluded=[];
        $byStage=self::mapRegistry($registry,$mapping,$excluded,'historical');
        $quarantine=self::quarantine($retirement,$expected);
        self::mapRegistry($quarantine,json_decode($bytes['quarantine_mapping'],true,64,JSON_THROW_ON_ERROR),$excluded,'quarantined');
        foreach($reserves as $scope)$excluded[self::key($scope)]='protected_reserve';
        $approved=json_decode($bytes['accepted_context'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($approved)||!array_is_list($approved))throw new DomainException('approved_baseline_projection_required');
        $expectedScopes=[];
        foreach($registry as $row)foreach($byStage[$row['stage_id']]['scopes'] as $scope)$expectedScopes[self::key($scope)]=$row;
        $seen=[];
        foreach($approved as $record){
            if(!is_array($record))throw new DomainException('approved_baseline_projection_required');
            $keys=array_keys($record);sort($keys);
            if($keys!==['answer','packet_sha256','receipt_sha256','scope'])throw new DomainException('strict_accepted_projection_required');
            $key=self::key($record['scope']??[]);$r=$expectedScopes[$key]??null;
            if(!$r||isset($seen[$key])||!is_string($record['answer']??null)||$record['answer']===''||strlen($record['answer'])>32768)throw new DomainException('unaccepted_baseline_projection');
            foreach(['packet_sha256','receipt_sha256'] as $field)if(($record[$field]??null)!==$r[$field])throw new DomainException('baseline_projection_lineage');
            $seen[$key]=true;
        }
        if(count($seen)!==count($expectedScopes))throw new DomainException('incomplete_accepted_projection');
        $contexts=json_decode($bytes['stage_contexts'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($contexts)||!array_is_list($contexts)||!$contexts)throw new DomainException('scoped_dependency_projection_required');
        $scoped=[];
        foreach($contexts as $view){
            if(!is_array($view))throw new DomainException('strict_stage_context_required');
            $outer=array_keys($view);sort($outer);
            if($outer!==['dependencies','scope'])throw new DomainException('strict_stage_context_required');
            $key=self::key($view['scope']??[]);
            if(isset($scoped[$key])||!is_array($view['dependencies']??null))throw new DomainException('scoped_dependency_projection_required');
            $deps=$view['dependencies'];$keys=array_keys($deps);sort($keys);$required=['archives','corpus','criteria','instructions','map'];
            if($keys!==$required)throw new DomainException('complete_scoped_dependencies_required');
            foreach($deps as $text)if(!is_string($text)||$text===''||strlen($text)>8192)throw new DomainException('bounded_scoped_dependency_required');
            $scoped[$key]=['scope'=>$view['scope'],'dependencies'=>$deps];
        }
        ksort($excluded);
        $identity=['schema'=>'hotel-server-inputs-v1','retirement_sha256'=>$expected,'pins'=>$pins,'registry'=>$registry,'quarantine'=>$quarantine,'exclusions'=>$excluded,'accepted_context'=>$approved,'stage_contexts'=>$scoped];
        return new self($identity+['inputs_sha256'=>hash('sha256',self::json($identity)),'bytes'=>$bytes]);
    }

    private static function inside(string $path, string $root): bool
    { return str_starts_with(str_replace('\\','/',$path), rtrim(str_replace('\\','/',$root),'/') . '/'); }
}
