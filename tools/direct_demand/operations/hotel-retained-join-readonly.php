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

// One bounded read for private semantic mapping. No mutable queue operation.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$level = ob_get_level(); ob_start(static fn(string $ignored): string => '');
$pdo = null;
$out = ['operation'=>'hotel-retained-join-readonly-v1','state'=>'HOLD','reason'=>'join_unconfirmed',
    'data_writes'=>0,'provider_calls'=>0,'new_queue_created'=>false];
try {
    $appRoot = '/var/www/anytoour/data/www/app.anytoour.ru';
    if (PHP_SAPI !== 'cli' || count($argv) !== 1 || realpath(getcwd()) !== $appRoot
        || !function_exists('posix_geteuid') || posix_geteuid() !== 1012) throw new RuntimeException('target_unconfirmed');
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_public_encrypt')) throw new RuntimeException('sealing_unavailable');
    require $appRoot.'/config.php'; require_once $appRoot.'/services/ConversationDb.php';
    $pdo = ConversationDb::connection();
    if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) throw new RuntimeException('connection_unconfirmed');
    $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    $s = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,VERSION() AS version,@@version_comment AS comment,@@hostname AS host,@@port AS port,@@server_id AS server_id');
    if (!$s) throw new RuntimeException('connection_unconfirmed');
    $binding = $s->fetch(PDO::FETCH_ASSOC); if (!$s->closeCursor()) throw new RuntimeException('connection_unconfirmed');
    if (!is_array($binding) || !hash_equals('9c5a356696060c37a9ac581c9849f8c53e38a29296eedee1c536f9ce794b3389',hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR)))) throw new RuntimeException('target_unconfirmed');
    unset($binding);
    $s = $pdo->prepare('SELECT snapshot_bytes,original_state_sha256,receipt_bytes FROM hotel_work_retirements WHERE queue_id=? AND LENGTH(snapshot_bytes)<=4194304 AND LENGTH(receipt_bytes)<=4096');
    if (!$s || !$s->execute([HotelServerInputs::OLD_QUEUE])) throw new RuntimeException('read_unconfirmed');
    $rows = $s->fetchAll(PDO::FETCH_ASSOC); if (!$s->closeCursor() || count($rows)!==1) throw new RuntimeException('read_unconfirmed');
    $row=$rows[0]; $snapshotSha='c228ad5acb5cc4e33636a3bd62530b68de1b66fedae2ce5ed58d5f2431278585';
    $retirementSha='9ae2171a61e2da2c6df22cfad8f6498aee3bb8631f3404e17da9d0a85e6ee1dd';
    if (!hash_equals($snapshotSha,(string)$row['original_state_sha256']) || !hash_equals($snapshotSha,hash('sha256',$row['snapshot_bytes']))) throw new RuntimeException('snapshot_pin_mismatch');
    $accepted=HotelServerInputs::registry($row,$retirementSha);
    $quarantine=HotelServerInputs::quarantine($row,$retirementSha);
    $snapshot=json_decode($row['snapshot_bytes'],true,64,JSON_THROW_ON_ERROR);
    $queue=$snapshot['hotel_work_queues'][0]??[];
    if (($queue['queue_id']??null)!==HotelServerInputs::OLD_QUEUE || (int)($queue['accepted_batches']??-1)!==19 || (int)($queue['stage_index']??-1)!==41) throw new RuntimeException('snapshot_pin_mismatch');
    $receipts=[]; $resolutions=[];
    foreach ($snapshot['hotel_work_receipts']??[] as $r) $receipts[(int)$r['stage_index']]=$r;
    foreach ($snapshot['hotel_work_resolutions']??[] as $r) $resolutions[(int)$r['stage_index']]=$r;
    $priorRegistry=[]; $runs=[];
    foreach ($accepted as $r) {
        $i=$r['stage_index']; $original=$receipts[$i]; $effective=$resolutions[$i]??$original;
        $priorRegistry[]=['stage_index'=>$i,'stage_id_sha256'=>hash('sha256',$r['stage_id']),
            'original_receipt_sha256'=>$original['receipt_sha256'],'effective_receipt_sha256'=>$effective['receipt_sha256'],'packet_sha256'=>$r['packet_sha256']];
    }
    $registrySha=hash('sha256',json_encode($priorRegistry,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    if (!hash_equals('4cb40f7b6cd8cdc31bbc13be7c22159a2b9279e248a1c4a669ed0b8fe79a7207',$registrySha)) throw new RuntimeException('registry_pin_mismatch');
    foreach ($receipts as $i=>$r) {
        $effective=$resolutions[$i]??$r; $body=json_decode($effective['receipt_json'],true,64,JSON_THROW_ON_ERROR);
        $run=$body['run_id']??null;
        if ($run!==null && (!is_string($run)||strlen($run)>128||!preg_match('/\A[A-Za-z0-9_:-]+\z/D',$run))) throw new RuntimeException('projection_unconfirmed');
        $outcome=$body['outcome']??null;
        if (!is_string($outcome)||!in_array($outcome,['accepted_readback','unknown','terminal_blocked','skipped','abandoned'],true)) throw new RuntimeException('projection_unconfirmed');
        $packet=$body['packet_sha256']??null; if($packet!==null)HotelServerInputs::sha($packet);
        $runs[]=['stage_index'=>$i,'stage_id'=>$r['stage_id'],'receipt_sha256'=>$effective['receipt_sha256'],
            'packet_sha256'=>$packet,'run_id'=>$run,'outcome'=>$outcome];
    }
    usort($runs,static fn(array $a,array $b):int=>$a['stage_index']<=>$b['stage_index']);
    if (count($runs)>64 || count($quarantine)>32 || !$pdo->rollBack()) throw new RuntimeException('read_transaction_unconfirmed');
    $payload=['schema'=>'hotel-retained-join-registry-v1','queue_id'=>HotelServerInputs::OLD_QUEUE,
        'snapshot_sha256'=>$snapshotSha,'retirement_receipt_sha256'=>$retirementSha,'accepted_registry_sha256'=>$registrySha,
        'accepted'=>$accepted,'quarantine'=>$quarantine,'original_runs'=>$runs,
        'historical_current_stage'=>41,'historical_current_outcome'=>'UNKNOWN','new_acceptance_credit'=>0,
        'read_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'source_inputs_git_blob'=>'8acf8099e9974822016ae0606e8031e953ef0d5b'];
    $plain=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (strlen($plain)>65536) throw new RuntimeException('projection_unconfirmed');
    $public=<<<'HOTEL_JOIN_RECIPIENT'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEA2KDW+zWMm3sjer6sf1be
YDUKuXhXe91QJ1f47Q8rLLGlWMJazHngTlMFyTh9G8t8ox23rkk1r+12/aywWwvI
mRVey9Ul1F9ZKtmn6Nw/lCxG6oQj958BdftWSOM/Z7SqxdoCzn8BfwizxiCrVooH
S2K+kaQYibMUKgrRYLlnoOVSXQpQH+7/mjoxTqLYySIrzG8DQhC2mPg3UzYbwtCu
Hr+FBgPFJt95oEOvsl79jd92GZm7Plh5v7UfgkAfaZDyjK8fwdSGfG/q0lk04t1m
HEE6BV10LUj5LTlhLF6TmsocAsNd1m+6Zk1eyUSPauEX89srxgZSTOny3O5GLF7u
VwbvVxLZTcT6kNJVO6WRbQ+pQCyA7U3wc712W80kw9I+nmzRORlbCNzWKeqJYkE+
ehNet25tFd1RqPOu1CsDgOYkOI2jS0PEEIzwkSg485Icq8+ypXkRtidC7ntPpgYy
r1gw5YdmtFCiSkNWncve9rq6EGniiaVTO1S7HTuQQqBBAgMBAAE=
-----END PUBLIC KEY-----
HOTEL_JOIN_RECIPIENT;
    $public.="\n";
    if (!hash_equals('56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d',hash('sha256',$public))) throw new RuntimeException('sealing_unavailable');
    $key=random_bytes(32); $nonce=random_bytes(12); $tag='';
    $aad='hotel-retained-join-readonly-v1|56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d|'.$snapshotSha;
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    $wrapped='';
    if (!is_string($cipher) || strlen($tag)!==16 || !openssl_public_encrypt($key,$wrapped,$public,OPENSSL_PKCS1_OAEP_PADDING)) throw new RuntimeException('sealing_unavailable');
    $out=['operation'=>'hotel-retained-join-readonly-v1','state'=>'sealed','data_writes'=>0,'provider_calls'=>0,'new_queue_created'=>false,
        'algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>'56f838b25c67967d6261577bc1f9bd0e689f224c6f5b042de4a4292f9067678d','aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher),
        'ciphertext_sha256'=>hash('sha256',$cipher)];
    unset($plain,$payload,$key,$snapshot,$row,$rows,$receipts,$resolutions,$runs,$accepted,$quarantine);
} catch (Throwable $e) {
    $allowed=['target_unconfirmed','connection_unconfirmed','read_unconfirmed','snapshot_pin_mismatch','registry_pin_mismatch','projection_unconfirmed','read_transaction_unconfirmed','sealing_unavailable'];
    $out=['operation'=>'hotel-retained-join-readonly-v1','state'=>'HOLD','reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'join_unconfirmed','data_writes'=>0,'provider_calls'=>0,'new_queue_created'=>false];
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try { if (!$pdo->rollBack()) throw new RuntimeException('rollback'); }
        catch(Throwable $ignored) { $out=['operation'=>'hotel-retained-join-readonly-v1','state'=>'HOLD','reason'=>'read_transaction_unconfirmed','data_writes'=>0,'provider_calls'=>0,'new_queue_created'=>false]; }
    }
    while (ob_get_level()>$level) ob_end_clean();
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($out['state']==='sealed'?0:1);
