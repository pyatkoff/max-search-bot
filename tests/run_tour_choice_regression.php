<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/TourChoiceService.php';
require_once dirname(__DIR__).'/integrations/TelegramIncomingAdapter.php';
require_once dirname(__DIR__).'/integrations/MaxIncomingAdapter.php';

$checks = 0;
function tourChoiceCheck(string $name, bool $ok): void { global $checks; if (!$ok) throw new RuntimeException('FAIL '.$name); ++$checks; echo 'PASS '.$name.PHP_EOL; }
function tourChoiceDb(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE customer_channels (id INTEGER PRIMARY KEY, project_key TEXT, channel TEXT, external_user_id TEXT)');
    $pdo->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY, customer_channel_id INTEGER, project_key TEXT, channel TEXT, external_chat_id TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE conversation_events (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER, event_type TEXT, actor_type TEXT, actor_id TEXT, payload_json TEXT, created_at TEXT)');
    $pdo->exec("INSERT INTO customer_channels VALUES (1,'anytour','telegram','101'),(2,'anytour','max','202'),(3,'other','telegram','303')");
    $pdo->exec("INSERT INTO conversations VALUES (1,1,'anytour','telegram','101','manager'),(2,2,'anytour','max','-202','waiting_manager'),(3,3,'other','telegram','303','ai'),(4,1,'anytour','telegram','101','closed')");
    return $pdo;
}
function tourChoiceOffer(): array {
    return ['hotel'=>'Синтетический отель', 'departure'=>'Москва', 'date_from'=>'2026-11-03', 'date_to'=>'2026-11-10', 'nights'=>7, 'adults'=>2,
        'child_ages'=>[5,5], 'room'=>'Семейный номер', 'meal'=>'Всё включено', 'flights'=>['Туда 08:00–12:00','Обратно 15:00–19:00'],
        'amount'=>'180000.50', 'currency'=>'RUB', 'price_basis'=>'party', 'is_demo'=>true, 'rationale'=>'Песчаный пляж', 'compromise'=>'400 м до моря'];
}
function tourChoiceIncoming(string $channel, string $payload, string $mid='777'): array {
    if ($channel === 'telegram') {
        $incoming = TelegramIncomingAdapter::fromUpdate(['callback_query'=>['id'=>'callback-test', 'data'=>$payload, 'from'=>['id'=>101],
            'message'=>['message_id'=>$mid, 'chat'=>['id'=>101,'type'=>'private']]]]);
    } else {
        $incoming = MaxIncomingAdapter::fromUpdate(['update_type'=>'message_callback', 'callback'=>['callback_id'=>'callback-test', 'payload'=>$payload, 'user'=>['user_id'=>202]],
            'message'=>['body'=>['mid'=>$mid], 'recipient'=>['chat_id'=>'native-max-chat','chat_type'=>'dialog']]]);
    }
    $incoming['project_key'] = 'anytour';
    return $incoming;
}
function tourChoiceCount(PDO $pdo, string $type): int { $q=$pdo->prepare('SELECT COUNT(*) FROM conversation_events WHERE event_type=?'); $q->execute([$type]); return (int)$q->fetchColumn(); }

$pdo = tourChoiceDb();
$offer = tourChoiceOffer();
$first = TourChoiceService::prepare($pdo, 1, $offer);
tourChoiceCheck('private immutable offer prepared', $first['ok'] && $first['offer_id'] > 0 && strlen($first['callback_data']) <= 64);
tourChoiceCheck('text like and old preview callback are not recognized', !TourChoiceService::handles('Нравится') && !TourChoiceService::handles('owner_preview_like_20261021'));
tourChoiceCheck('prepared but not sent is absent from manager projection', TourChoiceService::forConversation($pdo, 1) === ['offers'=>[], 'limited'=>false]);
$incoming = tourChoiceIncoming('telegram', $first['callback_data']);
tourChoiceCheck('unsent offer cannot be liked', !TourChoiceService::like($pdo, $incoming)['ok']);
tourChoiceCheck('unsent refusal has no like side effect', tourChoiceCount($pdo, 'tour_offer_liked') === 0 && !$pdo->inTransaction());
tourChoiceCheck('telegram receipt must use actual private chat', !TourChoiceService::recordDelivered($pdo, $first['offer_id'], '777', 'wrong')['ok']);
tourChoiceCheck('verified delivery recorded separately', TourChoiceService::recordDelivered($pdo, $first['offer_id'], '777', '101') === ['ok'=>true, 'changed'=>true]);
tourChoiceCheck('delivery retry is idempotent', TourChoiceService::recordDelivered($pdo, $first['offer_id'], '777', '101') === ['ok'=>true, 'changed'=>false] && tourChoiceCount($pdo, 'tour_offer_delivered') === 1);
tourChoiceCheck('native receipt cannot be overwritten', TourChoiceService::recordDelivered($pdo, $first['offer_id'], 'other', '101')['error'] === 'receipt_mismatch');
$before = $pdo->query('SELECT payload_json FROM conversation_events WHERE id='.(int)$first['offer_id'])->fetchColumn();
$liked = TourChoiceService::like($pdo, $incoming);
tourChoiceCheck('private telegram callback records exact choice', $liked['ok'] && $liked['changed'] && $liked['conversation_id'] === 1 && $liked['offer']['snapshot']['hotel'] === $offer['hotel']);
tourChoiceCheck('duplicate child ages and whole-party price retained', $liked['offer']['snapshot']['child_ages'] === [5,5] && $liked['offer']['snapshot']['amount'] === '180000.50' && $liked['offer']['snapshot']['price_basis'] === 'party');
tourChoiceCheck('demo and unverified price stay explicit', $liked['offer']['snapshot']['is_demo'] === true && $liked['offer']['snapshot']['price_verified'] === false);
tourChoiceCheck('repeated callback does not create second choice', TourChoiceService::like($pdo, $incoming)['changed'] === false && tourChoiceCount($pdo, 'tour_offer_liked') === 1);
tourChoiceCheck('prepared snapshot not overwritten by delivery or like', $pdo->query('SELECT payload_json FROM conversation_events WHERE id='.(int)$first['offer_id'])->fetchColumn() === $before);
$projection = TourChoiceService::forConversation($pdo, 1);
tourChoiceCheck('manager gets chosen conditions and timestamps', count($projection['offers']) === 1 && $projection['offers'][0]['liked_at'] !== null && $projection['offers'][0]['delivered_at'] !== null);
tourChoiceCheck('manager projection contains no callback token/native identity', !str_contains(json_encode($projection), 'token') && !str_contains(json_encode($projection), $first['callback_data']) && !str_contains(json_encode($projection), 'native_mid') && !str_contains(json_encode($projection), 'external_user_id'));
tourChoiceCheck('conversation read does not return another conversation offers', TourChoiceService::forConversation($pdo, 3)['offers'] === []);

foreach (['project_key','platform','external_user_id','chat_id','mid','native_chat','group','raw_payload','raw_sender'] as $case) {
    $bad = $incoming;
    if ($case === 'project_key') $bad['project_key'] = 'other';
    elseif ($case === 'platform') $bad['platform'] = 'max';
    elseif ($case === 'external_user_id') $bad['user']['external_user_id'] = '303';
    elseif ($case === 'chat_id') $bad['user']['chat_id'] = '303';
    elseif ($case === 'mid') $bad['raw']['callback_query']['message']['message_id'] = '888';
    elseif ($case === 'native_chat') $bad['raw']['callback_query']['message']['chat']['id'] = '303';
    elseif ($case === 'group') $bad['raw']['callback_query']['message']['chat']['type'] = 'group';
    elseif ($case === 'raw_payload') $bad['raw']['callback_query']['data'] = 'different';
    elseif ($case === 'raw_sender') $bad['raw']['callback_query']['from']['id'] = 303;
    tourChoiceCheck('cross-context callback rejected: '.$case, !TourChoiceService::like($pdo, $bad)['ok'] && tourChoiceCount($pdo, 'tour_offer_liked') === 1);
}
$bad = $incoming; $bad['callback_data'] = substr($first['callback_data'], 0, -1).(substr($first['callback_data'], -1) === 'a' ? 'b' : 'a'); $bad['raw']['callback_query']['data'] = $bad['callback_data'];
tourChoiceCheck('forged random token rejected', !TourChoiceService::like($pdo, $bad)['ok']);
foreach (['Нравится', 'tour_like_01_'.str_repeat('a',24), 'tour_like_1_short', 'tour_like_1_'.str_repeat('a',24).'extra'] as $payload) {
    $bad = $incoming; $bad['callback_data'] = $payload;
    tourChoiceCheck('invalid callback rejected without writes', !TourChoiceService::like($pdo, $bad)['ok'] && tourChoiceCount($pdo, 'tour_offer_liked') === 1);
}
$pdo->exec("UPDATE conversations SET status='closed' WHERE id=1");
tourChoiceCheck('closed conversation rejects even previously valid callback', !TourChoiceService::like($pdo, $incoming)['ok']);
tourChoiceCheck('historical choice stays readable in closed conversation', TourChoiceService::forConversation($pdo, 1)['offers'][0]['liked_at'] !== null);
tourChoiceCheck('closed conversation cannot prepare new offers', !TourChoiceService::prepare($pdo, 1, $offer)['ok']);
$pdo->exec("UPDATE conversations SET status='manager' WHERE id=1");

$max = TourChoiceService::prepare($pdo, 2, $offer);
TourChoiceService::recordDelivered($pdo, $max['offer_id'], 'mid.synthetic', 'native-max-chat');
$maxIncoming = tourChoiceIncoming('max', $max['callback_data'], 'mid.synthetic');
tourChoiceCheck('MAX normalized callback records choice without taking handoff', TourChoiceService::like($pdo, $maxIncoming)['changed'] === true && $pdo->query('SELECT status FROM conversations WHERE id=2')->fetchColumn() === 'waiting_manager');
$bad = $maxIncoming; $bad['raw']['message']['recipient']['chat_id'] = 'wrong';
tourChoiceCheck('MAX private native chat bound to receipt', !TourChoiceService::like($pdo, $bad)['ok']);
$bad = $maxIncoming; $bad['raw']['message']['recipient']['chat_type'] = 'group';
tourChoiceCheck('MAX group callback rejected', !TourChoiceService::like($pdo, $bad)['ok']);
tourChoiceCheck('MAX duplicate like stays idempotent', TourChoiceService::like($pdo, $maxIncoming)['changed'] === false && tourChoiceCount($pdo, 'tour_offer_liked') === 2);

$newOffer = $offer; $newOffer['room'] = 'Другой номер'; $newOffer['amount'] = '195000';
$second = TourChoiceService::prepare($pdo, 1, $newOffer); TourChoiceService::recordDelivered($pdo, $second['offer_id'], '778', '101');
tourChoiceCheck('same hotel different conditions retained as independent offer', $second['offer_id'] !== $first['offer_id'] && count(TourChoiceService::forConversation($pdo, 1)['offers']) === 2);
tourChoiceCheck('older selected price does not change after newer offer', TourChoiceService::forConversation($pdo, 1)['offers'][0]['snapshot']['amount'] === '180000.50');
tourChoiceCheck('projection bound explicit and selected offer prioritized', count(TourChoiceService::forConversation($pdo, 1, 1)['offers']) === 1 && TourChoiceService::forConversation($pdo, 1, 1)['limited'] === true && TourChoiceService::forConversation($pdo, 1, 1)['offers'][0]['offer_id'] === $first['offer_id']);
for ($i=0; $i<25; $i++) { $more=TourChoiceService::prepare($pdo,1,$newOffer); TourChoiceService::recordDelivered($pdo,$more['offer_id'],'new-'.$i,'101'); }
tourChoiceCheck('many newer unliked offers never hide prior explicit choice', TourChoiceService::forConversation($pdo, 1, 1)['offers'][0]['offer_id'] === $first['offer_id'] && TourChoiceService::forConversation($pdo,1)['limited']);
$bad = $offer; $bad['price_verified'] = true;
tourChoiceCheck('service cannot silently claim verified live price', TourChoiceService::normalize($bad) === null);
foreach (['hotel'=>'', 'room'=>str_repeat('x',301), 'meal'=>"AI\nsecret", 'amount'=>'1e5', 'price_basis'=>'person', 'currency'=>'XYZ', 'date_from'=>'2026-02-30', 'nights'=>0, 'adults'=>'2', 'child_ages'=>[5,'5'], 'flights'=>[''], 'is_demo'=>'true'] as $key=>$value) {
    $bad = $offer; $bad[$key] = $value;
    tourChoiceCheck('invalid snapshot rejected: '.$key, TourChoiceService::normalize($bad) === null);
}
$bad = $offer; $bad['url'] = 'https://example.invalid/private'; $bad['token'] = 'DO_NOT_PROJECT';
tourChoiceCheck('unknown raw metadata does not enter snapshot', !str_contains(json_encode(TourChoiceService::normalize($bad)), 'DO_NOT_PROJECT') && !str_contains(json_encode(TourChoiceService::normalize($bad)), 'example.invalid'));
tourChoiceCheck('no conversation status/customer state writes', $pdo->query('SELECT status FROM conversations WHERE id=1')->fetchColumn() === 'manager' && $pdo->query('SELECT status FROM conversations WHERE id=2')->fetchColumn() === 'waiting_manager');
tourChoiceCheck('all transaction boundaries are closed', !$pdo->inTransaction());
echo 'OK '.$checks.' tour choice checks'.PHP_EOL;
