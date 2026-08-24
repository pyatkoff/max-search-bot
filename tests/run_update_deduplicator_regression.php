<?php
require_once __DIR__ . '/../services/IncomingUpdateDeduplicator.php';

$tests = [];
function t($name, $fn) { global $tests; $tests[] = [$name, $fn]; }

t('callback key uses callback_id', function () {
    $u = ['update_type'=>'message_callback','callback'=>['callback_id'=>'abc123']];
    return IncomingUpdateDeduplicator::key($u) === 'callback:abc123';
});

t('message key uses message mid', function () {
    $u = ['update_type'=>'message_created','message'=>['body'=>['mid'=>'mid.1']]];
    return IncomingUpdateDeduplicator::key($u) === 'message:mid.1';
});

t('bot_started same event has stable key', function () {
    $u = ['update_type'=>'bot_started','timestamp'=>123,'user'=>['user_id'=>77],'payload'=>'yclid_key_test_1_campaign_2'];
    return IncomingUpdateDeduplicator::key($u) === IncomingUpdateDeduplicator::key($u);
});

t('same callback is processed once', function () {
    $file = sys_get_temp_dir() . '/max-search-dedupe-regression-' . getmypid() . '.json';
    @unlink($file);
    $u = ['update_type'=>'message_callback','callback'=>['callback_id'=>'repeat-me']];
    $first = IncomingUpdateDeduplicator::claim($u, $file);
    $second = IncomingUpdateDeduplicator::claim($u, $file);
    @unlink($file);
    return $first === true && $second === false;
});

t('different callbacks are both processed', function () {
    $file = sys_get_temp_dir() . '/max-search-dedupe-regression-' . getmypid() . '-2.json';
    @unlink($file);
    $a = ['update_type'=>'message_callback','callback'=>['callback_id'=>'a']];
    $b = ['update_type'=>'message_callback','callback'=>['callback_id'=>'b']];
    $ok = IncomingUpdateDeduplicator::claim($a, $file) && IncomingUpdateDeduplicator::claim($b, $file);
    @unlink($file);
    return $ok;
});

$pass = 0; $fail = 0;
foreach ($tests as [$name, $fn]) {
    try { $ok = (bool)$fn(); } catch (Throwable $e) { $ok = false; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}
echo 'TOTAL ' . count($tests) . ' | PASS ' . $pass . ' | FAIL ' . $fail . PHP_EOL;
exit($fail ? 1 : 0);
