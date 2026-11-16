<?php
declare(strict_types=1);
// Read only. Never includes application configuration or invokes any publisher.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
$handles = [];
function need(bool $ok, string $code): void { if (!$ok) throw new RuntimeException($code); }
function snapshot(string $path, int $maximum, array &$handles): array {
    need(!is_link($path) && is_file($path) && realpath($path) === $path, 'read_path_invalid');
    $f = fopen($path, 'rb'); need(is_resource($f), 'read_open_failed');
    $handles[] = $f;
    need(flock($f, LOCK_SH | LOCK_NB), 'read_busy_no_retry');
    $before = fstat($f); $named = lstat($path);
    need(is_array($before) && is_array($named) && $before['ino'] === $named['ino'] && $before['dev'] === $named['dev'], 'read_identity_changed');
    need($before['size'] > 0 && $before['size'] <= $maximum, 'read_size_invalid');
    $raw = stream_get_contents($f, $maximum + 1);
    need(is_string($raw) && strlen($raw) === $before['size'], 'read_size_changed');
    clearstatcache(true, $path); $after = fstat($f); $named = lstat($path);
    foreach (['ino', 'dev', 'size', 'mtime', 'ctime'] as $k) {
        need(($after[$k] ?? null) === $before[$k] && ($named[$k] ?? null) === $before[$k], 'read_changed');
    }
    return ['raw' => $raw, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
}
function projection(array $record): array {
    $out = [];
    foreach (['operation','event','type','state','phase','at','reason','error','complete','tourvisor_hotel_id','hotel_id','platform','publication_id','version_id','message_id','manifest_hash','manifest_sha256','text_sha256','old_text_sha256','new_text_sha256','photo_sha256','body_photo_button_verified','post_write_attempts','db_writes','message_sends','exit_code','child_exit_code'] as $key) {
        if (!array_key_exists($key, $record)) continue;
        $v = $record[$key];
        if (is_int($v) || is_bool($v) || $v === null) $out[$key] = $v;
        elseif (is_string($v) && strlen($v) <= 200 && preg_match('/\A[A-Za-z0-9_:.+\/-]*\z/D', $v) === 1) $out[$key] = $v;
    }
    foreach (['receipt','child_failure','terminal'] as $key) if (is_array($record[$key] ?? null)) $out[$key] = projection($record[$key]);
    return $out;
}
$out = ['ok'=>false,'mode'=>'read_only_pending_price_operation','checked_at'=>gmdate('c'),'server_writes'=>0,'post_writes'=>0,'feed_writes'=>0,'supplier_calls'=>0,'customer_messages'=>0];
try {
    $root = '/var/www/anytoour/data/channel-post-operations';
    need(!is_link($root) && realpath($root) === $root, 'private_root_changed');
    $global = snapshot($root.'/monitor-price-after-collection-v1.jsonl', 134217728, $handles);
    need(str_ends_with($global['raw'], "\n"), 'global_journal_incomplete');
    $lines = explode("\n", substr($global['raw'],0,-1));
    $state = json_decode(end($lines), true, 64, JSON_THROW_ON_ERROR);
    need(is_array($state) && array_key_exists('pending', $state), 'global_state_invalid');
    $out['global_journal'] = ['sha256'=>$global['sha256'],'bytes'=>$global['bytes'],'records'=>count($lines)];
    $out['completed_hotels'] = is_array($state['completed'] ?? null) ? count($state['completed']) : null;
    $out['held_hotels'] = is_array($state['holds'] ?? null) ? count($state['holds']) : null;
    $cycle = $state['nightly_cycle'] ?? null;
    $out['nightly_cycle'] = is_array($cycle) ? array_intersect_key($cycle, array_flip(['collector_run_id','state','night_date','started_at','finished_at','updated_pairs','unchanged_pairs','held_pairs','scanned_pairs','remaining_unscanned_pairs'])) : null;
    $pending = $state['pending'];
    need($pending === null || is_array($pending), 'pending_invalid');
    $out['pending'] = $pending === null ? null : projection($pending);
    if (is_array($pending)) {
        $op = $pending['operation'] ?? null;
        need(is_string($op) && preg_match('/\Acommercial-monitor-price-h[1-9][0-9]*-[a-f0-9]{64}\z/D', $op) === 1, 'pending_operation_invalid');
        $dir = $root.'/'.$op;
        need(!is_link($dir) && realpath($dir) === $dir, 'child_directory_invalid');
        $child = snapshot($dir.'/receipt.jsonl', 10000000, $handles);
        need(str_ends_with($child['raw'], "\n"), 'child_journal_incomplete');
        $rows = explode("\n", substr($child['raw'],0,-1)); need(count($rows) <= 1000, 'child_record_bound');
        $out['child_journal'] = ['sha256'=>$child['sha256'],'bytes'=>$child['bytes'],'records'=>count($rows),'entries'=>[]];
        foreach ($rows as $line) {
            $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR); need(is_array($record), 'child_record_invalid');
            $entry = projection($record); $entry['keys'] = array_slice(array_keys($record),0,50);
            $out['child_journal']['entries'][] = $entry;
        }
        $out['pending_feed_files'] = [];
        foreach (($pending['files'] ?? []) as $file) {
            need(is_array($file), 'pending_feed_file_invalid');
            $name = $file['name'] ?? null;
            need(in_array($name, ['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'],true), 'pending_feed_name_invalid');
            $actual = snapshot('/var/www/anytoour/data/www/app.anytoour.ru/feed/'.$name, 4000000, $handles);
            $out['pending_feed_files'][] = ['name'=>$name,'actual_sha256'=>$actual['sha256'],'expected_sha256'=>$file['sha256'] ?? null,'matches_expected'=>($file['sha256'] ?? null) === $actual['sha256']];
        }
    }
    $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = preg_match('/\A[a-z_]+\z/D',$e->getMessage()) === 1 ? $e->getMessage() : 'read_failed';
} finally {
    foreach (array_reverse($handles) as $f) { flock($f, LOCK_UN); fclose($f); }
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($out['ok'] ? 0 : 2);
