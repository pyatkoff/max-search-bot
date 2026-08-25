<?php
require_once __DIR__ . '/../integrations/WebsiteIncomingAdapter.php';
require_once __DIR__ . '/../integrations/WebsiteMessengerAdapter.php';

$tests = 0; $failed = 0;
function check_web($ok, $name) { global $tests, $failed; $tests++; if ($ok) echo "PASS {$name}\n"; else { $failed++; echo "FAIL {$name}\n"; } }

$session = str_repeat('a', 48);
$chatId = -1700000001;

$start = WebsiteIncomingAdapter::fromPayload(['action'=>'start','message_id'=>'m1'], $session, $chatId);
check_web(($start['platform'] ?? '') === 'website', 'start platform');
check_web(($start['text'] ?? '') === '/start', 'start maps to slash start');
check_web(($start['user']['external_user_id'] ?? '') === $session, 'website external user is session id');
check_web(($start['user']['chat_id'] ?? 0) === $chatId, 'website internal chat id preserved');

$message = WebsiteIncomingAdapter::fromPayload(['action'=>'message','text'=>'Хочу в Турцию','message_id'=>'m2'], $session, $chatId);
check_web(($message['text'] ?? '') === 'Хочу в Турцию', 'text message normalized');

$callback = WebsiteIncomingAdapter::fromPayload(['action'=>'callback','data'=>'ai_start','message_id'=>'c1'], $session, $chatId);
check_web(($callback['type'] ?? '') === 'callback', 'callback type normalized');
check_web(($callback['callback_data'] ?? '') === 'ai_start', 'callback payload normalized');

$invalid = WebsiteIncomingAdapter::fromPayload(['action'=>'message','text'=>'   '], $session, $chatId);
check_web($invalid === null, 'blank messages rejected');

$messenger = new WebsiteMessengerAdapter();
check_web($messenger->send($chatId, 'Привет'), 'website send succeeds');
check_web($messenger->sendWithButtons($chatId, 'Выберите', [[['text'=>'AI','callback_data'=>'ai_start']]]), 'website buttons send succeeds');
$out = $messenger->drain();
check_web(count($out) === 2, 'website outbound messages captured');
check_web(($out[0]['text'] ?? '') === 'Привет', 'website outbound text preserved');
check_web(($out[1]['buttons'][0][0]['callback_data'] ?? '') === 'ai_start', 'website callback button preserved');
check_web(count($messenger->drain()) === 0, 'drain clears request buffer');

check_web($messenger->sendContactRequest($chatId, 'Телефон?', 'manual_phone', 'back'), 'website contact request succeeds');
$contact = $messenger->drain();
check_web(($contact[0]['buttons'][0][0]['callback_data'] ?? '') === 'manual_phone', 'website contact falls back to manual phone');

$sessionService = (string)file_get_contents(__DIR__ . '/../services/WebsiteSessionService.php');
$migration = (string)file_get_contents(__DIR__ . '/../migrations/008_website_chat_sessions.sql');
check_web(strpos($sessionService, 'CREATE TABLE IF NOT EXISTS website_chat_sessions') === false, 'website request path does not run schema DDL');
check_web(strpos($sessionService, 'information_schema.tables') !== false, 'website session service verifies migrated schema');
check_web(strpos($migration, 'CREATE TABLE IF NOT EXISTS website_chat_sessions') !== false, 'website session table is versioned migration');
check_web(strpos($migration, 'uq_website_chat_token') !== false && strpos($migration, 'uq_website_chat_user') !== false, 'website session uniqueness preserved in migration');

printf("TOTAL %d | PASS %d | FAIL %d\n", $tests, $tests-$failed, $failed);
exit($failed ? 1 : 0);
