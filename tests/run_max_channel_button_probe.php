<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/tools/max_channel_button_probe.php';

function probeAssert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

function probePost(): array
{
    return [
        'url' => MaxChannelButtonProbe::POST_URL,
        'recipient' => ['chat_id' => (int)MaxChannelButtonProbe::CHANNEL, 'chat_type' => 'channel', 'user_id' => null],
        'body' => [
            'mid' => MaxChannelButtonProbe::POST_MID, 'seq' => 101, 'text' => 'Проверочный пост — текст и фото.',
            'markup' => [['type' => 'strong', 'from' => 0, 'length' => 11]],
            'attachments' => [['type' => 'image', 'payload' => ['photo_id' => 123456, 'token' => 'PRIVATE_FIXTURE_MEDIA_TOKEN', 'url' => 'https://media.invalid/private-fixture-photo']]],
        ],
        'link' => null, 'stat' => ['views' => 500],
    ];
}

function probeButton(): array
{
    return ['type' => 'link', 'text' => MaxChannelButtonProbe::LABEL, 'url' => MaxChannelButtonProbe::POST_URL];
}

function runProbeFixture(array $options = [], bool $inspect = false): array
{
    $post = $options['post'] ?? probePost();
    $fresh = $options['fresh'] ?? $post;
    $after = $options['after'] ?? $fresh;
    if (!isset($options['after']) && ($options['accept'] ?? true)) {
        $found = false;
        foreach ($after['body']['attachments'] as &$a) {
            if (($a['type'] ?? '') === 'inline_keyboard') {
                $a['payload']['buttons'][] = [probeButton()];
                $found = true;
            }
        }
        unset($a);
        if (!$found) $after['body']['attachments'][] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[probeButton()]]]];
    }
    $calls = [];
    $putBody = null;
    $backupCount = 0;
    $directReads = 0;
    $transport = static function (string $method, string $path, array $query, ?array $body) use (&$calls, &$putBody, &$directReads, $options, $post, $fresh, $after): array {
        $calls[] = [$method, $path, $query];
        $me = ['user_id' => 10101, 'is_bot' => true, 'username' => MaxChannelButtonProbe::BOT];
        if ($path === '/me') return ['http' => 200, 'data' => $options['me'] ?? $me];
        if ($path === '/chats/' . MaxChannelButtonProbe::CHANNEL . '/members/me') {
            return ['http' => 200, 'data' => $options['member'] ?? ($me + ['is_admin' => true, 'permissions' => ['read_all_messages', 'edit']])];
        }
        if ($method === 'GET' && $path === '/messages') {
            probeAssert($query === ['chat_id' => MaxChannelButtonProbe::CHANNEL, 'count' => 20], 'bounded channel read');
            return ['http' => 200, 'data' => ['messages' => $options['list'] ?? [$post]]];
        }
        if ($method === 'GET' && $path === '/messages/' . MaxChannelButtonProbe::POST_MID) {
            $directReads++;
            return ['http' => 200, 'data' => $directReads === 1 ? $fresh : $after];
        }
        if ($method === 'PUT') {
            probeAssert($path === '/messages' && $query === ['message_id' => MaxChannelButtonProbe::POST_MID], 'exact mutation target');
            $putBody = $body;
            if (($options['timeout'] ?? false) === true) throw new RuntimeException('PRIVATE_REMOTE_ERROR_MUST_NOT_BE_LOGGED');
            return $options['put_response'] ?? ['http' => 200, 'data' => ['success' => true]];
        }
        throw new RuntimeException('Unexpected fixture request');
    };
    $backup = static function (array $m) use (&$backupCount, $fresh): bool {
        probeAssert($m === $fresh, 'backup preserves complete pre-edit message');
        $backupCount++;
        return true;
    };
    $result = (new MaxChannelButtonProbe($transport, $backup))->run($inspect);
    $serialized = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (['PRIVATE_FIXTURE_MEDIA_TOKEN', 'media.invalid', 'PRIVATE_REMOTE_ERROR', 'Проверочный пост'] as $secret) {
        probeAssert(strpos($serialized, $secret) === false, 'summary suppresses raw bodies/URL/token/error');
    }
    return ['result' => $result, 'calls' => $calls, 'put_body' => $putBody, 'backup_count' => $backupCount];
}

$tests = [];
$tests['wrong_bot_fails_before_write'] = static function (): void {
    $f = runProbeFixture(['me' => ['user_id' => 10101, 'is_bot' => true, 'username' => 'different_bot']]);
    probeAssert($f['result']['reason'] === 'bot_identity_mismatch' && $f['result']['write_attempts'] === 0 && count($f['calls']) === 1, 'wrong bot blocked');
};
$tests['wrong_chat_fails_before_write'] = static function (): void {
    $m = probePost(); $m['recipient']['chat_id'] = -999;
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'post_channel_mismatch' && $f['result']['write_attempts'] === 0, 'wrong chat blocked');
};
$tests['wrong_url_fails_before_write'] = static function (): void {
    $m = probePost(); $m['url'] .= '_different';
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'exact_post_not_unique_or_missing' && $f['result']['write_attempts'] === 0, 'wrong URL blocked');
};
$tests['duplicate_exact_urls_fails_before_write'] = static function (): void {
    $f = runProbeFixture(['list' => [probePost(), probePost()]]);
    probeAssert($f['result']['reason'] === 'exact_post_not_unique_or_missing' && $f['result']['write_attempts'] === 0, 'ambiguous URL blocked');
};
$tests['private_post_fixed_measured_mid_is_supported'] = static function (): void {
    $m = probePost(); $m['url'] = null;
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['status'] === 'verified' && $f['result']['write_attempts'] === 1, 'private post uses measured exact API mid');
};
$tests['private_post_different_mid_is_blocked'] = static function (): void {
    $m = probePost(); $m['url'] = null; $m['body']['mid'] = 'mid.other_post';
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'exact_post_not_unique_or_missing' && $f['result']['write_attempts'] === 0, 'no private post guessed from sole list row');
};
$tests['matching_url_different_mid_is_blocked'] = static function (): void {
    $m = probePost(); $m['body']['mid'] = 'mid.other_post';
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'exact_post_not_unique_or_missing' && $f['result']['write_attempts'] === 0, 'both fixed mid and compatible URL required');
};
$tests['expected_photo_missing_is_blocked'] = static function (): void {
    $m = probePost(); $m['body']['attachments'] = [];
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'expected_image_missing' && $f['result']['write_attempts'] === 0, 'owner supplied photo post');
};
$tests['missing_image_token_fails_before_write'] = static function (): void {
    $m = probePost(); unset($m['body']['attachments'][0]['payload']['token']);
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'image_token_missing' && $f['result']['write_attempts'] === 0 && $f['backup_count'] === 0, 'missing media token blocked');
};
$tests['inspect_missing_token_is_read_only'] = static function (): void {
    $m = probePost(); unset($m['body']['attachments'][0]['payload']['token']);
    $f = runProbeFixture(['post' => $m], true);
    probeAssert($f['result']['status'] === 'inspected' && !$f['result']['request_mapping_ready'] && $f['result']['write_attempts'] === 0 && count($f['calls']) === 4, 'inspect metadata without writes');
};
$tests['preserves_media_and_existing_keyboard'] = static function (): void {
    $m = probePost();
    $oldButton = ['type' => 'link', 'text' => 'Оригинальная кнопка', 'url' => 'https://example.invalid/original'];
    $m['body']['attachments'][] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[$oldButton]]]];
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['status'] === 'verified' && $f['result']['write_attempts'] === 1 && $f['backup_count'] === 1, 'one verified write');
    probeAssert($f['put_body'] === ['attachments' => [
        ['type' => 'image', 'payload' => ['token' => 'PRIVATE_FIXTURE_MEDIA_TOKEN']],
        ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[$oldButton], [probeButton()]]]],
    ]], 'request reuses media token and appends keyboard row, leaves text/format/link untouched');
    probeAssert($f['result']['text_hash_equal'] && $f['result']['markup_hash_equal'] && $f['result']['image_ids_equal'] && $f['result']['original_keyboard_equal'], 'preservation verified');
};
$tests['accepted_timeout_reconciles_without_retry'] = static function (): void {
    $f = runProbeFixture(['timeout' => true]);
    probeAssert($f['result']['status'] === 'verified_after_unknown_response' && $f['result']['write_response'] === 'unknown' && $f['result']['write_attempts'] === 1 && count($f['calls']) === 6, 'timeout readback verifies one write');
};
$tests['outright_reject_is_not_success'] = static function (): void {
    $f = runProbeFixture(['accept' => false, 'put_response' => ['http' => 403, 'data' => ['success' => false, 'message' => 'PRIVATE_REMOTE_ERROR']]]);
    probeAssert($f['result']['status'] === 'rejected' && $f['result']['write_attempts'] === 1 && !$f['result']['keyboard_verified'], 'rejection is reported honestly');
};
$tests['http200_success_false_is_not_success'] = static function (): void {
    $f = runProbeFixture(['accept' => false, 'put_response' => ['http' => 200, 'data' => ['success' => false]]]);
    probeAssert($f['result']['status'] === 'rejected', 'API semantic rejection despite HTTP200');
};
$tests['already_button_no_put'] = static function (): void {
    $m = probePost(); $m['body']['attachments'][] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[probeButton()]]]];
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['status'] === 'already_present' && $f['result']['write_attempts'] === 0 && $f['backup_count'] === 0, 'idempotent repeat no write');
};
$tests['concurrent_change_fails_before_write'] = static function (): void {
    $fresh = probePost(); $fresh['body']['text'] = 'Changed concurrently';
    $f = runProbeFixture(['fresh' => $fresh]);
    probeAssert($f['result']['reason'] === 'post_changed_during_read' && $f['result']['write_attempts'] === 0, 'read drift blocked');
};
$tests['image_loss_fails_readback'] = static function (): void {
    $after = probePost(); $after['body']['attachments'] = [['type' => 'inline_keyboard', 'payload' => ['buttons' => [[probeButton()]]]]];
    $f = runProbeFixture(['after' => $after]);
    probeAssert($f['result']['status'] === 'unverified' && !$f['result']['image_ids_equal'], 'image loss never verified');
};
$tests['unsupported_attachment_fails_before_write'] = static function (): void {
    $m = probePost(); $m['body']['attachments'][] = ['type' => 'contact', 'payload' => ['vcf_info' => 'PRIVATE_FIXTURE']];
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'unsupported_existing_attachment' && $f['result']['write_attempts'] === 0, 'no guessed attachment conversion');
};
$tests['foreign_callback_keyboard_fails_before_write'] = static function (): void {
    $m = probePost(); $m['body']['attachments'][] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[['type' => 'callback', 'text' => 'Чужая кнопка', 'payload' => 'foreign_action']]]]];
    $f = runProbeFixture(['post' => $m]);
    probeAssert($f['result']['reason'] === 'foreign_keyboard_button_rejected' && $f['result']['write_attempts'] === 0, 'foreign callback ownership preserved');
};

$passed = [];
try {
    foreach ($tests as $name => $test) { $test(); $passed[] = $name; }
    echo json_encode(['status' => 'passed', 'count' => count($passed), 'tests' => $passed], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'failed', 'count' => count($passed), 'test' => $name]) . "\n";
    exit(1);
}
