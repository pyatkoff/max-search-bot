<?php
declare(strict_types=1);

/**
 * Private, exact saved-profile reader for the NEW common goal. The two injected
 * adapters are internal test seams, never request-supplied authorization.
 * No tick/configure/reconcile/remember method of the old stream is invoked.
 */
final class Max2ModeledValueReaderV2
{
    public const VERSION = 'max2_saved_inquiry_projection_v2';
    private const MAX_PROFILE_BYTES = 131072;
    private const MAX_SNAPSHOT_BYTES = 65536;
    private PDO $pdo;
    private $allowed;
    private $candidate;
    private $adjust;
    private string $producerHash;
    private bool $mysql;

    public function __construct(PDO $pdo, callable $allowed, ?callable $candidate = null,
        ?callable $adjust = null, ?string $producerHash = null)
    {
        $this->pdo = $pdo; $this->allowed = $allowed;
        $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->candidate = $candidate ?? static function(array $w, array $s, int $floor, int $now): ?array {
            require_once __DIR__ . '/ModeledConversionValueContract.php';
            return ModeledConversionValueContract::candidate($w, $s, $floor, $now);
        };
        $this->adjust = $adjust ?? function(array $w, array $c, string $profileId): array {
            require_once __DIR__ . '/Max2CommonValuePriceReadV2.php';
            return (new Max2CommonValuePriceReadV2($this->pdo))->adjust($w, $c, $profileId);
        };
        $this->producerHash = $producerHash ?? (string)hash_file('sha256', __DIR__ . '/ModeledConversionProfiles.php');
        if (!self::sha($this->producerHash)) throw new InvalidArgumentException('PROFILE_PRODUCER_REQUIRED');
    }

    private static function id($v): bool { return is_string($v) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $v) === 1; }
    private static function sha($v): bool { return is_string($v) && preg_match('/\A[a-f0-9]{64}\z/D', $v) === 1; }
    private static function time($v): ?int {
        if (!is_string($v) || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D', $v) !== 1) return null;
        $n = strtotime($v);
        return $n !== false && $n >= 946684800 && gmdate('Y-m-d\TH:i:s\Z', $n) === $v ? $n : null;
    }
    private static function integer($v): ?int {
        if (is_int($v)) return $v >= 0 ? $v : null;
        if (!is_string($v) || preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $v) !== 1) return null;
        return (int)$v;
    }
    private static function same($a, array $b): bool {
        if (!is_array($a)) return false;
        foreach (['project_key','platform','customer_key','conversation_id'] as $k) if (($a[$k] ?? null) !== $b[$k]) return false;
        return true;
    }
    private static function hold(string $code): array { return ['status'=>'HOLD','reason'=>$code,'provider_calls'=>0,'writes'=>0]; }
    private function rows(string $sql, array $args): array {
        $sql = $this->mysql ? preg_replace('/\ASELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(250) */ ', $sql, 1) : $sql;
        $q = $this->pdo->prepare($sql); $q->execute($args); $rows = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor(); return $rows;
    }

    /** One exact profile; caller enumerates only its own prospective cursor. */
    public function read(string $profileId, int $activationAt, int $now, ?string $fixedInquiryJournal = null): array
    {
        if (!self::id($profileId) || $activationAt < 1795262676 || $activationAt > $now
            || ($fixedInquiryJournal !== null && !self::id($fixedInquiryJournal)) || $this->pdo->inTransaction()) return self::hold('READ_SCOPE_UNPROVED');
        $began = hrtime(true); $started = false;
        try {
            if ($this->mysql) {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('SET TRANSACTION READ ONLY');
            }
            $this->pdo->beginTransaction(); $started = true;
            $out = $this->inside($profileId, $activationAt, $now, $fixedInquiryJournal);
            if (hrtime(true) - $began > 2000000000) $out = self::hold('READ_TIME_BOUND');
            $this->pdo->rollBack(); $started = false;
            return $out;
        } catch (Throwable $ignored) {
            if ($started && $this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (Throwable $ignored) {}
            }
            return self::hold('SAVED_PROFILE_UNAVAILABLE');
        }
    }

    private function inside(string $id, int $floor, int $now, ?string $fixed): array
    {
        $len = $this->mysql ? 'OCTET_LENGTH(profile_json)' : 'LENGTH(CAST(profile_json AS BLOB))';
        $rows = $this->rows('SELECT id,project_key,platform,customer_key,conversation_id,observed_at_unix,input_sha256,'
            . 'CASE WHEN '.$len.'<='.self::MAX_PROFILE_BYTES.' THEN profile_json ELSE NULL END AS profile_json'
            . ' FROM modeled_conversion_profile_versions WHERE id=? LIMIT 1', [$id]);
        if (count($rows) !== 1) return self::hold('PROFILE_MISSING');
        $row = $rows[0]; $observed = self::integer($row['observed_at_unix']);
        if ((string)$row['id'] !== $id || $row['project_key'] !== 'anytour'
            || !in_array($row['platform'], ['max','telegram'], true)
            || !self::id((string)$row['customer_key']) || !self::id((string)$row['conversation_id'])
            || !self::sha($row['input_sha256']) || $observed === null || $observed < $floor || $observed > $now
            || !is_string($row['profile_json'])) return self::hold('PROFILE_SCOPE_OR_BOUND');
        $subject = ['project_key'=>'anytour','platform'=>$row['platform'],
            'customer_key'=>(string)$row['customer_key'],'conversation_id'=>(string)$row['conversation_id']];
        $w = json_decode($row['profile_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($w) || ($w['access_status'] ?? null) !== 'allowed' || !self::same($w['subject'] ?? null, $subject)
            || ($w['processing']['source_sha256'] ?? null) !== $this->producerHash
            || ($w['processing']['assessment_kind'] ?? null) !== 'live') return self::hold('PROFILE_NOT_CURRENT_ADMITTED_SOURCE');
        $binding = $this->rows('SELECT c.id,c.customer_id,c.customer_channel_id,c.project_key,c.channel,c.external_chat_id,c.is_test,'
            . 'cc.customer_id AS channel_customer,cc.project_key AS channel_project,cc.channel AS channel_platform,'
            . 'cc.external_user_id,cc.external_chat_id AS channel_chat'
            . ' FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id WHERE c.id=? LIMIT 1', [$subject['conversation_id']]);
        if (count($binding) !== 1) return self::hold('SUBJECT_BINDING_MISSING');
        $b = $binding[0]; $actor = $b['external_user_id']; $chat = $b['external_chat_id'];
        if ($b['project_key'] !== 'anytour' || $b['channel_project'] !== 'anytour'
            || $b['channel'] !== $subject['platform'] || $b['channel_platform'] !== $subject['platform']
            || (string)$b['id'] !== $subject['conversation_id'] || (string)$b['customer_id'] !== $subject['customer_key']
            || (string)$b['channel_customer'] !== $subject['customer_key'] || (string)$b['is_test'] !== '0'
            || !self::id((string)$b['customer_channel_id']) || !self::id($actor)
            || !is_string($chat) || $chat !== ($subject['platform'] === 'max' ? '-'.$actor : $actor)
            || $b['channel_chat'] !== $chat || ($this->allowed)($chat) !== true) return self::hold('SUBJECT_NOT_ADMITTED');
        $candidate = ($this->candidate)($w, $subject, $floor, $now);
        if (!is_array($candidate)) return self::hold('SAVED_VALUE_UNAVAILABLE');
        $decision = ($this->adjust)($w, $candidate, $id); $candidate = $decision['candidate'] ?? null;
        if (!is_array($candidate) || !is_int($candidate['value'] ?? null) || $candidate['value'] < 0 || $candidate['value'] > 100000
            || !is_int($candidate['event_unix'] ?? null) || $candidate['event_unix'] < $floor || $candidate['event_unix'] > $observed) return self::hold('SAVED_VALUE_UNPROVED');
        $anchor = self::anchor($w, $subject, $floor, $observed, $fixed);
        if ($anchor === null) return self::hold('INQUIRY_ANCHOR_UNPROVED');
        $snapLen = $this->mysql ? 'OCTET_LENGTH(j.snapshot_json)' : 'LENGTH(CAST(j.snapshot_json AS BLOB))';
        $native = $this->rows('SELECT j.id,j.project_key,j.source_kind,j.source_id,j.version_no,j.observed_at_unix,'
            . 'CASE WHEN '.$snapLen.'<='.self::MAX_SNAPSHOT_BYTES.' THEN j.snapshot_json ELSE NULL END AS snapshot_json,'
            . 'm.id AS native_id,m.conversation_id AS native_conversation,m.channel AS native_platform,m.direction,m.sender_type'
            . ' FROM modeled_conversion_stream_versions j LEFT JOIN messages m ON m.id=j.source_id WHERE j.id=? LIMIT 1', [$anchor['journal_id']]);
        if (count($native) !== 1 || !self::nativeMatches($native[0], $anchor, $subject)) return self::hold('INQUIRY_NATIVE_SOURCE_UNPROVED');
        $inquiry = ['subject'=>$subject,'journal_id'=>$anchor['journal_id'],'native_message_id'=>$anchor['native_message_id'],
            'kind'=>'substantive_interest','source_version'=>1,'verified_customer_message'=>true,'recorded_at_unix'=>$anchor['at']];
        return ['status'=>'ADMITTED_SAVED_VALUE','version'=>self::VERSION,'subject'=>$subject,'inquiry'=>$inquiry,
            'actor_context'=>['subject'=>$subject,'external_user_id'=>$actor,'external_chat_id'=>$chat,
                'customer_channel_id'=>(string)$b['customer_channel_id'],
                'subject_actor_binding_sha256'=>hash('sha256',json_encode($b,JSON_THROW_ON_ERROR))],
            'estimate'=>['admitted_saved_profile'=>true,'subject'=>$subject,'inquiry_journal_id'=>$anchor['journal_id'],
                'profile_id'=>$id,'profile_sha256'=>hash('sha256',$row['profile_json']),
                'model_version'=>'saved_v1_model_readonly_in_common_v2','currency'=>'RUB','value_rub'=>$candidate['value'],
                'input_cutoff_unix'=>$anchor['cutoff'],'event_unix'=>$candidate['event_unix'],'profile_observed_at_unix'=>$observed,
                'source_model_sha256'=>$w['semantic_prediction']['model_sha256'] ?? null,
                'price_continuity_basis_sha256'=>hash('sha256',json_encode($decision['basis'] ?? null,JSON_THROW_ON_ERROR))],
            'financial_delivery_permission'=>false,'provider_calls'=>0,'writes'=>0];
    }

    private static function anchor(array $w, array $subject, int $floor, int $observed, ?string $fixed): ?array
    {
        $c = $w['contextual_signals'] ?? null; $input = $w['profile']['observation_input'] ?? null;
        if (!is_array($c) || ($c['version'] ?? null) !== 'live_contextual_ordinal_v1'
            || ($c['extractor_version'] ?? null) !== 'ru_archive_contextual_v2'
            || !is_array($input) || !self::same($input['subject'] ?? null, $subject)) return null;
        $cutoff = self::time($c['input_cutoff_at'] ?? null);
        $prediction = self::time($input['prediction_at'] ?? null);
        if ($cutoff === null || $prediction === null || $cutoff > $prediction || $prediction > $observed
            || ($c['prediction_at'] ?? null) !== $input['prediction_at']) return null;
        foreach ([$c['events'] ?? null,$input['evidence'] ?? null,$w['source_references'] ?? null] as $list)
            if (!is_array($list) || !array_is_list($list) || count($list) > 1000) return null;
        $fence = $w['source_fence_journal_id'] ?? null;
        if (!self::id($fence)) return null;
        $candidates = [];
        foreach ($c['events'] as $event) {
            if (!is_array($event) || ($event['goal'] ?? null) !== 'substantive_interest'
                || !in_array($event['product'] ?? null, ['tour','unknown'], true)) continue;
            $ref = $event['source_ref'] ?? null;
            if (!is_string($ref) || preg_match('/\Ajournal:([1-9][0-9]{0,19})\z/D',$ref,$m) !== 1) return null;
            $jid = $m[1]; if ($fixed !== null && $jid !== $fixed) continue;
            if (strlen($jid) > strlen($fence) || (strlen($jid) === strlen($fence) && strcmp($jid,$fence) > 0)) return null;
            $es = array_values(array_filter($input['evidence'],static fn($e):bool=>is_array($e) && ($e['id'] ?? null) === 'message:'.$ref));
            $rs = array_values(array_filter($w['source_references'],static fn($r):bool=>is_array($r) && ($r['journal_id'] ?? null) === $jid));
            if (count($es) !== 1 || count($rs) !== 1 || isset($candidates[$jid])) return null;
            $e = $es[0]; $r = $rs[0]; $at = self::time($e['at'] ?? null); $available = self::time($e['available_at'] ?? null);
            if (($e['kind'] ?? null) !== 'customer_message' || !self::same($e['subject'] ?? null,$subject)
                || $at === null || $available === null || $at < $floor || $at > $available || $available > $cutoff
                || ($e['references'] ?? null) !== [['source'=>'message','id'=>$ref,'at'=>$e['at'],'available_at'=>$e['available_at']]]
                || ($r['source_kind'] ?? null) !== 'message' || !self::id($r['source_id'] ?? null)
                || ($r['source_version_no'] ?? null) !== 1 || ($r['source_status'] ?? null) !== 'present'
                || ($r['observed_at'] ?? null) !== $e['available_at']) return null;
            $candidates[$jid] = ['journal_id'=>$jid,'native_message_id'=>$r['source_id'],'at'=>$at,'available'=>$available,'cutoff'=>$cutoff];
        }
        if (!$candidates) return null;
        $list = array_values($candidates);
        usort($list,static fn(array $a,array $b):int=>$a['at']<=>$b['at'] ?: strlen($a['journal_id'])<=>strlen($b['journal_id']) ?: strcmp($a['journal_id'],$b['journal_id']));
        // This is the first observed anchor in this bounded profile, never a lifetime-first claim.
        return $list[0];
    }

    private static function nativeMatches(array $r, array $a, array $subject): bool
    {
        if ((string)$r['id'] !== $a['journal_id'] || $r['project_key'] !== 'anytour' || $r['source_kind'] !== 'message'
            || (string)$r['source_id'] !== $a['native_message_id'] || (string)$r['version_no'] !== '1'
            || self::integer($r['observed_at_unix']) !== $a['available'] || !is_string($r['snapshot_json'])
            || (string)$r['native_id'] !== $a['native_message_id'] || (string)$r['native_conversation'] !== $subject['conversation_id']
            || $r['native_platform'] !== $subject['platform'] || $r['direction'] !== 'inbound' || $r['sender_type'] !== 'customer') return false;
        $s = json_decode($r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        return is_array($s) && ($s['source_kind'] ?? null) === 'message' && (string)($s['source_id'] ?? '') === $a['native_message_id']
            && ($s['source_status'] ?? null) === 'present' && ($s['project_key'] ?? null) === 'anytour'
            && ($s['platform'] ?? null) === $subject['platform'] && (string)($s['customer_key'] ?? '') === $subject['customer_key']
            && (string)($s['conversation_id'] ?? '') === $subject['conversation_id']
            && in_array($s['source_timestamp_basis'] ?? null,['canonical_recording_unix_v1','database_session_timezone_to_unix'],true)
            && self::integer($s['source_created_at_unix'] ?? null) === $a['at']
            && ($s['payload']['direction'] ?? null) === 'inbound' && ($s['payload']['sender_type'] ?? null) === 'customer';
    }
}
