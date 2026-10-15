<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/ChannelPostRegistryService.php';
require_once dirname(__DIR__) . '/services/ChannelPostButtonTransport.php';
require_once __DIR__ . '/fixtures/channel_post_registry_fixture.php';

$memberPassed = 0;
function memberCheck(string $name, bool $ok): void {
    global $memberPassed;
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $memberPassed++;
    echo 'PASS ' . $name . "\n";
}
function memberMessage(string $group, string $mid, array $keyboard = [], string $caption = ''): array {
    $message = [
        'message_id' => $mid, 'media_group_id' => $group,
        'date' => 1791972000, 'chat' => ['id' => '-1001234567890', 'type' => 'channel', 'username' => 'anytour_test'],
        'photo' => [['file_id' => 'file-' . $mid, 'file_unique_id' => 'unique-' . $mid, 'width' => 100, 'height' => 100]],
    ];
    if ($caption !== '') $message['caption'] = $caption;
    if ($keyboard !== []) $message['reply_markup'] = ['inline_keyboard' => $keyboard];
    return $message;
}
function memberSnapshot(array $message, int $sequence, int $revision = 1791972000000): array {
    $snapshot = ChannelPostIncomingAdapter::telegramSnapshot($message);
    $snapshot['metadata']['source_event_id'] = 'member-' . $message['media_group_id'] . '-' . $message['message_id'] . '-' . $sequence;
    $snapshot['metadata']['source_revision_ms'] = (string)$revision;
    $snapshot['metadata']['source_sequence'] = (string)$sequence;
    $snapshot['metadata']['source_sequence_scope'] = 'telegram_update_id';
    return $snapshot;
}
function memberRow(array $rows, string $mid): array {
    foreach ($rows as $row) if ((string)($row['external_message_id'] ?? '') === $mid) return $row;
    throw new RuntimeException('missing member ' . $mid);
}
function memberRegister(ChannelPostRegistryService $registry, array $snapshot): array {
    return $registry->registerPublication('anytour', 'telegram', 'anyru_bot', '-1001234567890', 'group:' . $snapshot['metadata']['media_group_id'], $snapshot);
}
final class MemberPostProvider {
    public array $messages;
    public array $calls = [];
    public string $mode = 'success';
    public function __construct(array $messages) {
        foreach ($messages as $message) $this->messages[(string)$message['message_id']] = $message;
    }
    public function request(string $platform, string $method, string $path, array $query, ?array $body): array {
        $this->calls[] = compact('platform', 'method', 'path', 'query', 'body');
        if ($platform !== 'telegram' || $method !== 'POST') throw new RuntimeException('unexpected provider request');
        if ($path === 'getMe') return ['http'=>200,'data'=>['ok'=>true,'result'=>['id'=>900,'is_bot'=>true,'username'=>'anyru_bot']]];
        if ($path === 'getChatMember') return ['http'=>200,'data'=>['ok'=>true,'result'=>['user'=>['id'=>900,'is_bot'=>true,'username'=>'anyru_bot'],'status'=>'administrator','can_edit_messages'=>true]]];
        if ($path !== 'editMessageReplyMarkup' || !isset($this->messages[(string)($body['message_id'] ?? '')])) throw new RuntimeException('no getMessage or unrelated API is allowed');
        $mid = (string)$body['message_id'];
        if ($this->mode !== 'timeout_unapplied') $this->messages[$mid]['reply_markup'] = $body['reply_markup'];
        if ($this->mode !== 'success') return ['http'=>0,'data'=>null,'transport_error'=>true];
        return ['http'=>200,'data'=>['ok'=>true,'result'=>$this->messages[$mid]]];
    }
    public function writes(): array {
        return array_values(array_filter($this->calls, static fn(array $call): bool => $call['path'] === 'editMessageReplyMarkup'));
    }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
createChannelPostRegistryFixture($pdo);
$pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1);
INSERT INTO conversation_sources VALUES(12,1,'tg_anytour_msk','ТГ Москва','telegram',1,'manager');");
$registry = new ChannelPostRegistryService($pdo);
$registry->configureChannel(['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot',
    'channel_id'=>'-1001234567890','source_key'=>'tg_anytour_msk','display_name'=>'Durable member fixture',
    'bot_url'=>'https://t.me/anyru_bot','is_enabled'=>true]);
$members = $registry->memberDelivery();
$foreign = [[['text'=>'Комментарии','callback_data'=>'foreign-comment']]];
$anchor = memberMessage('cleanup', '101', $foreign, 'Турция — 10 фотографий');
$first = memberRegister($registry, memberSnapshot($anchor, 100));
$operation = $members->claim($first['publication_id']);
memberCheck('anchor claim uses one numeric member and an opaque durable lease', $operation !== null && $operation['external_message_id'] === '101'
    && $operation['button_mode'] === 'append_button' && !empty($operation['delivery_member_id']) && !empty($operation['delivery_observation_key'])
    && !empty($operation['delivery_claim_token']) && count($operation['snapshot']['attachments']) === 1);
$other = memberMessage('cleanup', '102', $foreign);
$assembled = memberRegister($registry, memberSnapshot($other, 101));
memberCheck('new group member cannot steal an existing member claim', $members->markStarted($operation) && !$members->markStarted($operation));
$provider = new MemberPostProvider([$anchor,$other]);
$transport = new ChannelPostButtonTransport([$provider,'request']);
// The durable start was already proven above; this exact provider call uses that lease.
$anchorResult = $transport->attempt($operation, static fn(): bool => true);
memberCheck('anchor response verifies only the exact original member', $anchorResult['state'] === 'sent'
    && count($provider->writes()) === 1 && (string)$provider->writes()[0]['body']['message_id'] === '101');
memberCheck('receipt remains valid across unrelated album assembly', $members->finish($operation, $anchorResult['state'], $anchorResult['reason'], $anchorResult['receipt']));
memberCheck('completed member receipt cannot run twice', !$members->finish($operation, 'unknown', 'late_receipt', []));
memberCheck('anchor success does not hide another member awaiting cleanup', $registry->publication($first['publication_id'])['state'] === 'pending' && memberRow($members->rows($first['publication_id']),'102')['state'] === 'pending');
$cleanup = $members->claim($first['publication_id']);
memberCheck('non-anchor foreign keyboard has its own numeric clear operation', $cleanup !== null && $cleanup['external_message_id'] === '102'
    && $cleanup['button_mode'] === 'clear_keyboard' && count($cleanup['snapshot']['attachments']) === 1
    && $cleanup['snapshot']['attachments'][0]['file_unique_id'] === 'unique-102');
$result = $transport->attempt($cleanup, static fn(): bool => $members->markStarted($cleanup));
memberCheck('non-anchor clear verifies the original media without another own button', $result['state'] === 'sent'
    && count($provider->writes()) === 2 && $provider->writes()[1]['body'] === [
        'chat_id'=>'-1001234567890','message_id'=>'102','reply_markup'=>['inline_keyboard'=>[]],
    ]);
memberCheck('clear receipt is independently durable', $members->finish($cleanup, $result['state'], $result['reason'], $result['receipt']));
$rows = $members->rows($first['publication_id']);
memberCheck('only one durable record exists for each album member', count($rows) === 2 && memberRow($rows,'101')['state'] === 'sent' && memberRow($rows,'102')['state'] === 'sent');
memberCheck('whole publication becomes sent only after both member operations succeed', $registry->publication($first['publication_id'])['state'] === 'sent' && $members->claim($first['publication_id']) === null);
memberCheck('Telegram cleanup uses only identity rights and markup edit calls', array_diff(array_column($provider->calls,'path'), ['getMe','getChatMember','editMessageReplyMarkup']) === []);
memberCheck('caption and all photo bytes remain untouched', $provider->messages['101']['caption'] === $anchor['caption']
    && $provider->messages['101']['photo'] === $anchor['photo'] && $provider->messages['102']['photo'] === $other['photo']);
$beforeDuplicate = $members->rows($first['publication_id']);
$duplicate = memberRegister($registry, memberSnapshot($other,101));
memberCheck('webhook replay cannot reset a completed member', !empty($duplicate['duplicate_event']) && $members->rows($first['publication_id']) === $beforeDuplicate);

$unknownAnchor = memberMessage('unknown-clear', '201', [], 'Неопределённый ответ');
$unknownFirst = memberRegister($registry,memberSnapshot($unknownAnchor,200));
$anchorOperation = $members->claim($unknownFirst['publication_id']);
memberCheck('second album anchor can be started independently', $anchorOperation !== null && $members->markStarted($anchorOperation));
memberCheck('anchor success is stored before another member is observed', $members->finish($anchorOperation,'sent','verified',['write_attempts'=>1,'content_preserved'=>true,'button_verified'=>true]));
$unknownOther = memberMessage('unknown-clear','202',$foreign);
memberRegister($registry,memberSnapshot($unknownOther,201));
$unknownOperation = $members->claim($unknownFirst['publication_id']);
$unknownProvider = new MemberPostProvider([$unknownAnchor,$unknownOther]);
$unknownProvider->mode = 'timeout_applied';
$unknownTransport = new ChannelPostButtonTransport([$unknownProvider,'request']);
$lost = $unknownTransport->attempt($unknownOperation, static fn(): bool => $members->markStarted($unknownOperation));
memberCheck('lost non-anchor clear response remains unknown after one write', $lost['state'] === 'unknown' && count($unknownProvider->writes()) === 1
    && $members->finish($unknownOperation,$lost['state'],$lost['reason'],$lost['receipt']));
$reopened = (new ChannelPostRegistryService($pdo))->memberDelivery();
$persisted = memberRow($reopened->rows($unknownFirst['publication_id']),'202');
memberCheck('restart retains unknown outcome and its single attempted write', $persisted['state'] === 'unknown' && (int)$persisted['write_attempts'] === 1);
memberRegister($registry,memberSnapshot(memberMessage('unknown-clear','203'),202));
$wrongNewer = memberRegister($registry,memberSnapshot($unknownOther,203));
$reopened->refresh($unknownFirst['publication_id']);
memberCheck('newer wrong markup on the same ambiguous member never authorizes a retry', memberRow($reopened->rows($unknownFirst['publication_id']),'202')['state'] === 'unknown'
    && (int)memberRow($reopened->rows($unknownFirst['publication_id']),'202')['write_attempts'] === 1
    && $reopened->claim($unknownFirst['publication_id']) === null);
memberCheck('unrelated later photo cannot make unknown cleanup writable', $reopened->claim($unknownFirst['publication_id']) === null
    && memberRow($reopened->rows($unknownFirst['publication_id']),'202')['state'] === 'unknown'
    && !$reopened->markStarted($unknownOperation) && count($unknownProvider->writes()) === 1);
$stale = memberRegister($registry,memberSnapshot(memberMessage('unknown-clear','202'),199));
memberCheck('older clean event cannot reconcile ambiguous newer member', !empty($stale['stale_observation'])
    && memberRow($members->rows($unknownFirst['publication_id']),'202')['state'] === 'unknown');
$clean = memberRegister($registry,memberSnapshot($unknownProvider->messages['202'],300,1791972001000));
memberCheck('new authenticated exact-member clean observation reconciles without a retry', memberRow($members->rows($unknownFirst['publication_id']),'202')['state'] === 'sent'
    && $registry->publication($unknownFirst['publication_id'])['state'] === 'sent' && count($unknownProvider->writes()) === 1);
memberCheck('late ambiguous receipt cannot undo authenticated clean evidence', !$members->finish($unknownOperation,'unknown','late_lost_receipt',[]) && memberRow($members->rows($unknownFirst['publication_id']),'202')['state'] === 'sent');
memberCheck('unknown reconciliation preserves all three immutable images and one token', count($clean['snapshot']['attachments']) === 3 && $clean['start_payload'] === $unknownFirst['start_payload']);

$startedFirst = memberRegister($registry,memberSnapshot(memberMessage('inflight-edit','251',$foreign),350));
$startedClaim = $members->claim($startedFirst['publication_id']);
memberCheck('anchor write starts under its original authenticated observation', $startedClaim !== null && $members->markStarted($startedClaim));
memberRegister($registry,memberSnapshot(memberMessage('inflight-edit','251',$foreign,'Новая подпись'),351,1791972001000));
memberCheck('newer wrong anchor event during write cannot reset its durable attempt', $members->claim($startedFirst['publication_id']) === null
    && memberRow($members->rows($startedFirst['publication_id']),'251')['state'] === 'unknown'
    && (int)memberRow($members->rows($startedFirst['publication_id']),'251')['write_attempts'] === 1);
$selfFirst = memberRegister($registry,memberSnapshot(memberMessage('self-before-receipt','261',$foreign),360));
$selfClaim = $members->claim($selfFirst['publication_id']);
memberCheck('early self-event fixture starts one exact member edit', $selfClaim !== null && $members->markStarted($selfClaim));
$selfMessage = memberMessage('self-before-receipt','261',[[['text'=>'Рассчитать на мои даты','url'=>ChannelPostButtonTransport::buttonUrl($selfFirst)]]]);
$selfObserved = memberRegister($registry,memberSnapshot($selfMessage,361,1791972001000));
$selfRows = $members->rows($selfFirst['publication_id']);
memberCheck('authenticated final markup before HTTP response confirms only the matching member', memberRow($selfRows,'261')['state'] === 'sent' && $selfObserved['state'] === 'sent');
memberCheck('verified API response after its own final event reports success without replacing newer evidence', $members->finish($selfClaim,'sent','verified',['write_attempts'=>1,'content_preserved'=>true,'button_verified'=>true])
    && $members->rows($selfFirst['publication_id']) === $selfRows);

$expiryFirst = memberRegister($registry,memberSnapshot(memberMessage('expiry','301'),400));
$expiryClaim = $members->claim($expiryFirst['publication_id']);
memberCheck('crash fixture durably records write start before losing its worker', $expiryClaim !== null && $members->markStarted($expiryClaim));
$expire = $pdo->prepare("UPDATE post_member_deliveries SET claimed_at='2000-01-01 00:00:00' WHERE id=?");
$expire->execute([$expiryClaim['delivery_member_id']]);
memberCheck('expired attempted write becomes unknown without another claim', $members->claim($expiryFirst['publication_id']) === null
    && memberRow($members->rows($expiryFirst['publication_id']),'301')['state'] === 'unknown'
    && (int)memberRow($members->rows($expiryFirst['publication_id']),'301')['write_attempts'] === 1);
memberCheck('expired worker cannot replace the durable unknown receipt', !$members->finish($expiryClaim,'sent','late',[]));
$unstartedFirst = memberRegister($registry,memberSnapshot(memberMessage('unstarted','401'),500));
$unstartedClaim = $members->claim($unstartedFirst['publication_id']);
$expire->execute([$unstartedClaim['delivery_member_id']]);
$replacement = $members->claim($unstartedFirst['publication_id']);
memberCheck('only an expired claim with no provider attempt can receive a new lease', $replacement !== null
    && $replacement['delivery_member_id'] === $unstartedClaim['delivery_member_id']
    && $replacement['delivery_claim_token'] !== $unstartedClaim['delivery_claim_token']
    && !$members->markStarted($unstartedClaim) && $members->markStarted($replacement));

echo 'OK ' . $memberPassed . " album member delivery checks\n";
