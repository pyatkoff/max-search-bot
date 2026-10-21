<?php
declare(strict_types=1);
define('OWNER_CHOICE_TRIAL_OFFLINE_TEST', true);
require_once dirname(__DIR__).'/tools/owner_tour_choice_trial.php';
$checks = 0; $directories = [];
function choiceAssert(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function choiceRow(string $channel): array {
    return $channel === 'telegram' ? ['id'=>'1','project_key'=>'anytour','channel'=>'telegram','status'=>'manager','external_user_id'=>'881001','conversation_chat_id'=>'881001','channel_chat_id'=>'881001','message_sender_id'=>'881001','external_message_id'=>'177']
        : ['id'=>'2','project_key'=>'anytour','channel'=>'max','status'=>'manager','external_user_id'=>'772001','conversation_chat_id'=>'772001','channel_chat_id'=>'772001','message_sender_id'=>'772001','external_message_id'=>'mid.owner_inbound','original_display_name'=>'Pavel'];
}
function choiceReceipt(string $directory, string $channel): array { return json_decode(file_get_contents($directory.'/owner-choice-trial-20261021-'.$channel.'.json'), true); }
function choiceNative(string $channel, string $callback): array {
    $body = ownerChoiceTrialBody($channel, $callback);
    return $channel === 'telegram' ? ['message_id'=>200, 'chat'=>['id'=>881001,'type'=>'private'], 'from'=>['id'=>881099,'is_bot'=>true],
        'rich_message'=>$body['rich_message'], 'reply_markup'=>$body['reply_markup']]
        : ['body'=>['mid'=>'mid.choice_trial','text'=>$body['text'],'attachments'=>$body['attachments']],
            'sender'=>['user_id'=>772099], 'recipient'=>['user_id'=>772001,'chat_id'=>-770001,'chat_type'=>'dialog']];
}
function choiceSends(array $calls): int { return count(array_filter($calls, static fn($c)=>($c[0] === 'telegram' && $c[1] === 'sendRichMessage') || ($c[0] === 'max' && $c[1] === 'POST'))); }
function choiceRun(string $failure = '', ?string $directory = null, array $changes = []): array {
    global $directories;
    if ($directory === null) { $directory = sys_get_temp_dir().'/owner-choice-'.bin2hex(random_bytes(8)); $directories[] = $directory; }
    $calls = []; $writes = [];
    $tg = static function($method, $body, $token, $images) use (&$calls, $directory, $failure): array {
        $calls[] = ['telegram',$method];
        if ($method === 'getMe') return ['data'=>['id'=>881099,'username'=>$failure === 'bot' ? 'other_bot' : 'anyru_bot','is_bot'=>true]];
        if ($method === 'getChat') return ['data'=>['id'=>881001,'type'=>$failure === 'chat' ? 'group' : 'private']];
        choiceAssert($method === 'sendRichMessage' && $body['chat_id'] === '881001' && $images === [], 'Only one text card targets the separately proved Telegram owner');
        $record = choiceReceipt($directory, 'telegram');
        choiceAssert($record['post_reserved'] === true && $record['state'] === 'post_reserved' && $record['offer_id'] === 1001, 'Snapshot and permanent reservation precede Telegram POST');
        choiceAssert($body['reply_markup']['inline_keyboard'][0][0]['callback_data'] === $record['callback_data'], 'Sent Telegram callback belongs to the durably prepared offer');
        if ($failure === 'tg_throw') throw new RuntimeException('PRIVATE_TOKEN');
        if (in_array($failure, ['tg_timeout','tg_reject'], true)) return ['data'=>null,'diagnostic'=>['http_status'=>$failure === 'tg_reject' ? 400 : 0,'error_category'=>'transport']];
        $native = choiceNative('telegram', $record['callback_data']);
        if ($failure === 'tg_text') $native['rich_message']['blocks'][0]['text'] = 'Wrong conditions';
        if ($failure === 'tg_owner') $native['chat']['id'] = 881002;
        return ['data'=>$native,'diagnostic'=>['http_status'=>200,'curl_code'=>0,'error_category'=>'none']];
    };
    $max = static function($method, $path, $query, $body, $token) use (&$calls, $directory, $failure): array {
        $calls[] = ['max',$method,$path];
        $diagnostic = ['http_status'=>200,'curl_code'=>0,'error_category'=>'none','api_code'=>null];
        if ($path === '/messages/mid.owner_inbound') return ['data'=>['body'=>['mid'=>'mid.owner_inbound'],'sender'=>['user_id'=>772001],
            'recipient'=>['user_id'=>772099,'chat_id'=>-770001,'chat_type'=>$failure === 'max_identity' ? 'chat' : 'dialog']],'diagnostic'=>$diagnostic];
        $record = choiceReceipt($directory, 'max');
        if ($method === 'GET') {
            choiceAssert($path === '/messages/mid.choice_trial', 'Known MAX MID allows only its exact readback');
            $native = choiceNative('max', $record['callback_data']);
            if ($failure === 'max_read') $native['body']['text'] = 'Changed conditions';
            return ['data'=>$native,'diagnostic'=>$diagnostic];
        }
        choiceAssert($method === 'POST' && $path === '/messages' && $query === ['user_id'=>'772001'], 'MAX creates only in the independently verified owner dialogue');
        choiceAssert($record['post_reserved'] === true && $record['state'] === 'post_reserved' && $record['offer_id'] === 1002, 'Snapshot and permanent reservation precede MAX POST');
        choiceAssert($body === ownerChoiceTrialBody('max', $record['callback_data']), 'MAX gets a payload-bearing callback, not the historical text preview button');
        if ($failure === 'max_timeout') return ['data'=>null,'diagnostic'=>['http_status'=>0,'error_category'=>'transport']];
        return ['data'=>['message'=>['body'=>['mid'=>'mid.choice_trial']]],'diagnostic'=>$diagnostic];
    };
    $targets = [];
    foreach (['telegram','max'] as $channel) { $row = array_replace(choiceRow($channel), $changes[$channel] ?? []); $targets[$channel] = static fn()=>$row; }
    $prepare = static function(int $conversationId, array $snapshot) use (&$writes, &$calls, $failure): array {
        $writes[] = ['prepare',$conversationId];
        choiceAssert(in_array(['telegram','getMe'], $calls, true) && in_array(['telegram','getChat'], $calls, true)
            && in_array(['max','GET','/messages/mid.owner_inbound'], $calls, true), 'Both native identities are proved before the first DB snapshot');
        choiceAssert($snapshot === ownerChoiceTrialSnapshot() && $snapshot['is_demo'] && !$snapshot['price_verified'], 'Only immutable fixed demo conditions are prepared');
        if ($failure === 'prepare') return ['ok'=>false];
        $id = 1000 + $conversationId;
        return ['ok'=>true,'offer_id'=>$id,'callback_data'=>'tour_like_'.$id.'_'.str_repeat('a', 24)];
    };
    $delivered = static function(int $offerId, string $mid, string $chat) use (&$writes, &$calls, $failure): array {
        $writes[] = ['delivered',$offerId,$mid,$chat];
        choiceAssert(($offerId === 1001 && $mid === '200' && $chat === '881001')
            || ($offerId === 1002 && $mid === 'mid.choice_trial' && $chat === '-770001'), 'Delivery binds the prepared offer to its exact native message/chat');
        if ($offerId === 1002) choiceAssert(in_array(['max','GET','/messages/mid.choice_trial'], $calls, true), 'MAX readback occurs before recording delivery');
        return ['ok'=>!($failure === 'record' && $offerId === 1001)];
    };
    $sha = str_repeat('a', 40);
    $out = ownerChoiceTrialRun($sha, $sha, 'anytour', ['telegram'=>'PRIVATE_TG_TOKEN','max'=>'PRIVATE_MAX_TOKEN'], $targets,
        ['telegram'=>$tg,'max'=>$max], $directory, $prepare, $delivered);
    choiceAssert(!str_contains(json_encode($out), 'PRIVATE_') && !str_contains(json_encode($out), '881001')
        && !str_contains(json_encode($out), '772001') && !str_contains(json_encode($out), 'tour_like_'), 'Public output excludes credentials, identities and callback capabilities');
    return [$out,$calls,$directory,$writes];
}
$sha = str_repeat('a', 40);
choiceAssert(ownerChoiceTrialArguments(['--expected-sha='.$sha,'--confirm-owner-choice']) === $sha, 'Exact owner choice CLI activation');
foreach ([[], ['--expected-sha='.$sha,'--confirm-owner-choice','--chat-id=1'], ['--expected-sha='.$sha,'--confirm-owner-choice','--confirm-owner-trio'],
    ['--expected-sha='.$sha,'--confirm-owner-send']] as $args) choiceAssert(ownerChoiceTrialArguments($args) === null, 'No arbitrary recipient, content or mixed confirmation');
$snapshot = ownerChoiceTrialSnapshot();
choiceAssert($snapshot['hotel'] === 'Delphin Palace 5★' && $snapshot['amount'] === '219600' && $snapshot['price_basis'] === 'party'
    && $snapshot['adults'] === 2 && $snapshot['child_ages'] === [] && $snapshot['is_demo'] && !$snapshot['price_verified'], 'Snapshot has a fixed whole-party demo price and composition');
foreach (['telegram','max'] as $channel) {
    $identity = $channel === 'telegram' ? ['user'=>'881001','bot'=>'881099','chat'=>'881001'] : ['user'=>'772001','bot'=>'772099','chat'=>'-770001'];
    $callback = 'tour_like_1001_'.str_repeat('a',24); $mid = $channel === 'telegram' ? '200' : 'mid.choice_trial';
    choiceAssert(ownerChoiceTrialMatches($channel, choiceNative($channel,$callback), $mid, $identity, $callback), 'Exact native text/owner/button verifies');
    choiceAssert(!ownerChoiceTrialMatches($channel, choiceNative($channel,$callback), $mid, $identity, 'tour_like_1001_'.str_repeat('b',24)), 'Different callback capability cannot verify');
    choiceAssert(!ownerChoiceTrialMatches($channel, choiceNative($channel,$callback), '201', $identity, $callback), 'Foreign native MID cannot verify');
}
[$out,$calls,$directory,$writes] = choiceRun();
choiceAssert($out['ok'] && choiceSends($calls) === 2 && count($out['cards']) === 2, 'Exactly two fixed native text messages are delivered');
choiceAssert(count(array_filter($writes, static fn($w)=>$w[0] === 'prepare')) === 2 && count(array_filter($writes, static fn($w)=>$w[0] === 'delivered')) === 2, 'Two snapshots and verified native bindings are stored');
[$again,$calls,$_,$writes] = choiceRun('', $directory);
choiceAssert($again['ok'] && choiceSends($calls) === 0 && count(array_filter($writes, static fn($w)=>$w[0] === 'prepare')) === 0, 'Rerun reuses exact offers without repeating prepare or message creation');
foreach (['tg_timeout','tg_reject','tg_throw','tg_text','tg_owner','max_timeout'] as $failure) {
    [$out,$calls,$directory] = choiceRun($failure);
    choiceAssert(!$out['ok'] && choiceSends($calls) === ($failure === 'max_timeout' ? 2 : 1), 'Uncertain/rejected/mismatched native creation stops the batch');
    [$again,$calls,$_,$writes] = choiceRun('', $directory);
    choiceAssert(!$again['ok'] && choiceSends($calls) === 0 && count(array_filter($writes, static fn($w)=>$w[0] === 'prepare')) === 0, 'Unknown or mismatched old native results never cause another POST or prepare');
}
foreach (['record','max_read'] as $failure) {
    [$out,$calls,$directory] = choiceRun($failure);
    choiceAssert(!$out['ok'], 'Failed DB binding/readback is not delivered');
    [$again,$calls,$_,$writes] = choiceRun('', $directory);
    choiceAssert($again['ok'] && choiceSends($calls) === ($failure === 'record' ? 1 : 0), 'Known MID recovers binding without repeating its native creation');
    choiceAssert(count(array_filter($writes, static fn($w)=>$w[0] === 'prepare')) === ($failure === 'record' ? 1 : 0), 'Existing offer is never re-prepared during known-MID recovery');
}
foreach (['flag_with_mid','prepared_with_mid','invalid_state','unprepared_with_diagnostic'] as $corruption) {
    [$out,$calls,$directory] = choiceRun();
    $path = $directory.'/owner-choice-trial-20261021-max.json'; $record = choiceReceipt($directory, 'max');
    if ($corruption === 'flag_with_mid') $record['post_reserved'] = false;
    elseif ($corruption === 'prepared_with_mid') { $record['post_reserved'] = false; $record['state'] = 'prepared'; }
    elseif ($corruption === 'invalid_state') $record['state'] = 'may_resend';
    else { foreach (['offer_id','callback_data','body_sha256','message_id','native_message'] as $key) unset($record[$key]); $record['post_reserved'] = false; $record['state'] = 'unprepared'; $record['diagnostic'] = ['http_status'=>200]; }
    file_put_contents($path, json_encode($record));
    [$again,$calls,$_,$writes] = choiceRun('', $directory);
    choiceAssert(!$again['ok'] && choiceSends($calls) === 0 && $writes === [], 'Corrupt send flags/state/attempt evidence cannot authorize a repeated POST');
}
foreach (['bot','chat','max_identity'] as $failure) { [$out,$calls,$_,$writes] = choiceRun($failure); choiceAssert(!$out['ok'] && choiceSends($calls) === 0 && $writes === [], 'Identity failures precede all DB writes and sends'); }
foreach (['telegram','max'] as $channel) { [$out,$calls,$_,$writes] = choiceRun('', null, [$channel=>['channel'=>'website']]); choiceAssert(!$out['ok'] && choiceSends($calls) === 0 && $writes === [], 'Source channel cannot be inferred or replaced'); }
[$out,$calls,$directory] = choiceRun();
file_put_contents($directory.'/owner-choice-trial-20261021-max.json', '{');
[$again,$calls,$_,$writes] = choiceRun('', $directory);
choiceAssert(!$again['ok'] && choiceSends($calls) === 0 && $writes === [], 'Corrupt later receipt stops both channels before any new business write');
[$out,$calls,$directory] = choiceRun(); $path = $directory.'/owner-choice-trial-20261021-max.json'; unlink($path); symlink('/does/not/exist', $path);
[$again,$calls,$_,$writes] = choiceRun('', $directory);
choiceAssert(!$again['ok'] && choiceSends($calls) === 0 && $writes === [], 'Receipt symlinks cannot redirect a write or authorize creation'); unlink($path);
$source = file_get_contents(dirname(__DIR__).'/tools/owner_tour_choice_trial.php');
choiceAssert(str_contains($source, "defined('MAX_SEARCH_TOKEN')") && !str_contains($source, "defined('MAX_BOT_TOKEN')"), 'CLI uses the canonical installed MAX credential');
choiceAssert(!str_contains($source, "'PUT'") && !str_contains($source, "'DELETE'") && !str_contains($source, 'unlink('), 'Trial cannot edit or delete historical native cards or once receipts');
foreach ($directories as $directory) if (is_dir($directory)) { foreach (glob($directory.'/*') ?: [] as $path) unlink($path); rmdir($directory); }
echo "OWNER TOUR CHOICE TRIAL: $checks checks PASSED\n";
