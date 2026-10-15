<?php
declare(strict_types=1);

/** Fixed, DB-only investigation. No provider client, worker or registry writes. */
final class MaxSpbPostDiagnostic
{
    public const EXPECTED_SHA = '5c4689c885f1b867614565a20dcb1387fafcd75c';
    public const ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
    private const PERMALINK = 'https://max.ru/c/-77309177991187/AaE-mekYJW0';
    private const REASONS = [
        '', 'invalid_attachment', 'invalid_keyboard', 'unsupported_media', 'image_stable_id_missing',
        'album_not_supported', 'attachment_limit', 'unsupported_post', 'provider_read_failed',
        'canonical_bot_identity_mismatch', 'membership_identity_mismatch', 'channel_admin_required',
        'channel_read_permission_required', 'channel_edit_permission_required', 'channel_type_mismatch',
        'invalid_message_id', 'current_post_invalid', 'post_changed_since_event', 'image_token_missing',
        'foreign_keyboard_owner', 'invalid_keyboard_row', 'invalid_keyboard_url', 'keyboard_row_limit',
        'conflicting_publication_button', 'write_claim_or_config_changed', 'provider_rejected_edit',
        'edit_outcome_unknown', 'post_identity_mismatch', 'request_budget_exhausted', 'write_budget_exhausted',
        'transport_failure', 'button_not_observed', 'receipt_storage_failed', 'channel_worker_unavailable',
        'claim_expired', 'verified', 'already_present', 'verified_after_unknown_response',
        'invalid_channel_id', 'channel_not_enabled', 'canonical_bot_url_mismatch', 'invalid_button',
    ];

    public static function blocked(string $reason): array
    {
        return ['ok'=>false, 'mode'=>'db_read_only', 'reason'=>$reason, 'provider_requests'=>0, 'writes'=>0];
    }

    private static function reason($value): string
    {
        return is_string($value) && in_array($value, self::REASONS, true) ? $value : 'unrecognized_reason';
    }

    /** Only approved scalars survive; raw snapshot/receipt content is never emitted. */
    public static function summarize(array $row): array
    {
        $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
        $receipt = json_decode((string)($row['receipt_json'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $receipt = is_array($receipt) ? $receipt : [];
        $states = ['pending','claimed','write_started','sent','unknown','requires_attention'];
        $out = [
            'version_number'=>(int)$row['version_number'],
            'state'=>in_array($row['state'] ?? '', $states, true) ? $row['state'] : 'unrecognized_state',
            'error_reason'=>self::reason($row['error_reason'] ?? ''),
            'attempt_count'=>(int)$row['attempt_count'], 'write_attempts'=>(int)$row['write_attempts'],
            'observation_count'=>(int)$row['observation_count'],
            'stored_permalink_available'=>is_string($snapshot['post_url'] ?? null) && $snapshot['post_url'] !== '',
            'target_permalink_match'=>($snapshot['post_url'] ?? '') === self::PERMALINK,
            'snapshot_capability'=>in_array($snapshot['metadata']['capability'] ?? '', ['supported','requires_attention'], true) ? $snapshot['metadata']['capability'] : 'unrecognized_capability',
            'snapshot_capability_reason'=>self::reason($snapshot['metadata']['capability_reason'] ?? ''),
            'media_count'=>count((array)($snapshot['attachments'] ?? [])),
            'media_types'=>[], 'keyboard_row_count'=>count((array)($snapshot['keyboard'] ?? [])),
            'has_media_group'=>!empty($snapshot['media_group_id']) || !empty($snapshot['metadata']['media_group_id']),
            'keyboard_shapes'=>array_map(static function ($row): array {
                $out = [];
                foreach (array_slice((array)$row, 0, 7) as $button) {
                    if (!is_array($button)) { $out[] = ['type'=>'invalid']; continue; }
                    $type = $button['type'] ?? '';
                    $out[] = [
                        'type'=>in_array($type, ['link','callback','message','open_app','request_contact','request_geo_location'], true) ? $type : 'other',
                        'has_url'=>is_string($button['url'] ?? null),
                        'has_payload'=>is_string($button['payload'] ?? null),
                        'has_contact_id'=>isset($button['contact_id']),
                    ];
                }
                return $out;
            }, array_slice((array)($snapshot['keyboard'] ?? []), 0, 30)),
            'receipt'=>[],
        ];
        foreach ((array)($snapshot['attachments'] ?? []) as $item) {
            $type = is_array($item) ? ($item['type'] ?? '') : '';
            $type = in_array($type, ['image','video','audio','file','sticker','contact','location','inline_keyboard'], true) ? $type : 'other';
            $out['media_types'][$type] = ($out['media_types'][$type] ?? 0) + 1;
        }
        foreach (['verified','reconciled','content_preserved','button_verified'] as $key) {
            if (array_key_exists($key, $receipt) && is_bool($receipt[$key])) $out['receipt'][$key] = $receipt[$key];
        }
        foreach (['request_count','write_attempts'] as $key) {
            if (isset($receipt[$key]) && is_int($receipt[$key]) && $receipt[$key] >= 0 && $receipt[$key] <= 5) $out['receipt'][$key] = $receipt[$key];
        }
        $out['receipt']['http_results'] = [];
        foreach (array_slice((array)($receipt['http_results'] ?? []), 0, 5) as $r) {
            if (!is_array($r) || !in_array($r['operation'] ?? '', ['bot_identity','channel_rights','channel_type','current_post','append_button','verify_post'], true)) continue;
            if (!is_int($r['http'] ?? null) || $r['http'] < 0 || $r['http'] > 599) continue;
            $out['receipt']['http_results'][] = ['operation'=>$r['operation'], 'http'=>$r['http'], 'transport_error'=>!empty($r['transport_error'])];
        }
        return $out;
    }

    public static function run(): array
    {
        $root = self::ROOT;
        $sha = trim((string)@shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
        if ($sha !== self::EXPECTED_SHA) return self::blocked('installed_sha_mismatch');
        foreach (['diff --quiet', 'diff --cached --quiet'] as $operation) {
            $status = 1;
            @exec('git -C '.escapeshellarg($root).' '.$operation.' 2>/dev/null', $ignored, $status);
            if ($status !== 0) return self::blocked('installed_checkout_dirty');
        }
        // Suppress all bootstrap output, including warnings; exceptions stay redacted.
        $bufferLevel = ob_get_level(); ob_start();
        try {
            require_once $root.'/config.php';
            require_once $root.'/services/ConversationDb.php';
            $pdo = ConversationDb::connection();
        } finally { while (ob_get_level() > $bufferLevel) ob_end_clean(); }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return self::blocked('mysql_required');
        // Fail closed if the server cannot enforce a transaction without writes.
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $channel = $pdo->query("SELECT pc.id,pc.is_enabled,pc.config_version,s.is_active AS source_active,p.is_active AS project_active,s.handling_mode FROM post_channels pc JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pc.source_id AND s.project_id=pc.project_id WHERE p.project_key='anytour' AND pc.platform='max' AND pc.bot_key='id9704048781_1_bot' AND pc.canonical_bot_username='id9704048781_1_bot' AND pc.channel_id='-77309177991187' AND s.source_key='max_anytour_spb' AND s.channel='max' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            if (count($channel) !== 1) return self::blocked('exact_channel_identity_missing');
            $c = $channel[0];
            $q = $pdo->prepare('SELECT COUNT(*) FROM post_publications WHERE post_channel_id=?');
            $q->execute([(int)$c['id']]); $count = (int)$q->fetchColumn();
            if ($count > 5) return self::blocked('publication_bound_exceeded');
            $q = $pdo->prepare('SELECT v.version_number,v.state,v.error_reason,v.attempt_count,v.write_attempts,v.snapshot_json,v.receipt_json,(SELECT COUNT(*) FROM post_observations o WHERE o.publication_id=pp.id) AS observation_count FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id=? ORDER BY pp.id ASC LIMIT 5');
            $q->execute([(int)$c['id']]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            return [
                'ok'=>true, 'mode'=>'db_read_only', 'installed_sha'=>self::EXPECTED_SHA,
                'provider_requests'=>0, 'writes'=>0, 'channel_enabled'=>(bool)$c['is_enabled'],
                'config_version'=>(int)$c['config_version'], 'source_active'=>(bool)$c['source_active'],
                'project_active'=>(bool)$c['project_active'],
                'source_handling_mode'=>in_array($c['handling_mode'], ['manager','ask'], true) ? $c['handling_mode'] : 'unsupported',
                'publication_count'=>$count, 'current_versions_returned'=>count($rows),
                'publications'=>array_map([self::class,'summarize'], $rows),
            ];
        } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    if (array_slice($argv,1) !== ['--expected-sha='.MaxSpbPostDiagnostic::EXPECTED_SHA]) $result=MaxSpbPostDiagnostic::blocked('invalid_arguments');
    else {
        try { $result=MaxSpbPostDiagnostic::run(); }
        catch (Throwable $ignored) { $result=MaxSpbPostDiagnostic::blocked('diagnostic_unavailable'); }
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($result['ok'] ? 0 : 1);
}
