<?php

declare(strict_types=1);

// Isolate real entry/controller/view behavior from the envelope-only checks below.
// Only storage and transport are synthetic; every dialogue decision is production code.
if (in_array('--start-parity-fixture', $argv ?? [], true)) {
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/dialogue-start-parity-' . bin2hex(random_bytes(8));
    mkdir($tmp, 0700);
    $chats = [];
    $passed = 0;
    $failed = 0;
    $check = static function (string $name, $actual, $expected) use (&$passed, &$failed): void {
        if ($actual === $expected) { echo 'PASS  ' . $name . PHP_EOL; $passed++; return; }
        echo 'FAIL  ' . $name . ' expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $failed++;
    };
    try {
        foreach (['services', 'handlers', 'integrations', 'actions', 'contracts', 'ai'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                $destination = $tmp . substr($file->getPathname(), strlen($root));
                if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
                copy($file->getPathname(), $destination);
            }
        }
        foreach (['project.php', 'DepartureRouteResolver.php', 'DepartureRouteAdvisor.php'] as $name) {
            copy($root . '/' . $name, $tmp . '/' . $name);
        }
        // Never connect to a configured host or persist diagnostics outside this fixture.
        file_put_contents($tmp . '/services/ConversationDb.php', <<<'PHP'
<?php
class ConversationDb {
    public static function isConfigured(): bool { return false; }
    public static function connection(): PDO { throw new RuntimeException('Fixture forbids database connections'); }
}
PHP
        );
        file_put_contents($tmp . '/services/DiagnosticLogger.php', <<<'PHP'
<?php
class DiagnosticLogger {
    public static array $events = [];
    public static function log(...$args): void { self::$events[] = $args; }
    public static function error(...$args): void { self::$events[] = $args; }
}
PHP
        );
        file_put_contents($tmp . '/services/AiRuntimeLogger.php', <<<'PHP'
<?php
class AiRuntimeLogger {
    public static function debug(string $message): bool { return true; }
    public static function error(string $message): bool { return true; }
}
PHP
        );
        class DialogueStartAiReached extends RuntimeException {}
        class MaxSearchApi {
            public static $statusStart=64, $statusCityChoose=65, $statusContryChoose=66, $statusAdults=67,
                $statusChild=68, $statusAge=69, $statusStars=70, $statusMeal=71, $statusNights=72,
                $statusDate=73, $statusCheck=74, $statusPhone=75, $statusAi=76;
            public static array $statuses=[], $needs=[], $edits=[], $followups=[], $writes=[], $events=[], $searchIntros=[];
            public static string $testBaseDir='';
            public static ?int $resetFailureChat=null;
            public static bool $probeAi=false;
            public static function cancelToursFollowup($chat): void { unset(self::$followups[$chat]); }
            public static function deleteAllStatus($chat): void {
                if (self::$resetFailureChat === $chat) throw new RuntimeException('Synthetic reset failure');
                unset(self::$statuses[$chat], self::$needs[$chat]);
            }
            public static function setEditMode($chat, $field=''): void { self::$edits[$chat] = $field; }
            public static function getEditMode($chat): string { return self::$edits[$chat] ?? ''; }
            public static function getSavedData($chat): array { return self::$needs[$chat] ?? []; }
            public static function getCurentStatus($chat) { return self::$statuses[$chat] ?? 0; }
            public static function setStatus($chat, $status, $message=false): void {
                self::$writes[] = [$chat, $status]; self::$statuses[$chat] = $status;
            }
            public static function getTrafficMeta($chat): array { return TrafficAttributionService::get(self::$testBaseDir, $chat); }
            public static function getAiSearchContext($chat): array { return []; }
            public static function getAiMissingFields($chat): array { return ['city']; }
            public static function deletePrevMessage(...$args): void {}
            public static function funnelLog($chat, $event, $details=[]): void {
                self::$events[] = [$chat, $event, $details];
                if (self::$probeAi && $event === 'ai_text') throw new DialogueStartAiReached((string)($details['text'] ?? ''));
            }
            public static function scheduleSearchIntroFollowup($chat, $delay=60): bool {
                self::$searchIntros[] = [$chat, $delay]; return true;
            }
            public static function addYclid(...$args): void { throw new RuntimeException('Unexpected legacy attribution write'); }
        }
        define('AI_SHADOW_V2', false);
        MaxSearchApi::$testBaseDir = $tmp;
        require_once $tmp . '/handlers/MaxUpdateHandler.php';
        require_once $tmp . '/handlers/TelegramWebhookHandler.php';
        $config = ['id'=>'anytour', 'messenger'=>['provider'=>'max', 'miniapp_bot_url'=>'https://max.ru/fixture_bot',
            'telegram'=>['source_key'=>'telegram:fixture']], 'state'=>['v2_store_dir'=>'runtime/trip_state']];
        ProjectConfig::resetForTests($config);
        $sent = [];
        $sendOk = true;
        $maxMessenger = new MaxMessengerAdapter(
            static function ($chat, string $text) use (&$sent, &$sendOk): bool {
                $sent[] = ['chat'=>$chat, 'text'=>$text, 'buttons'=>[]]; return $sendOk;
            },
            static function ($chat, string $text, array $buttons) use (&$sent, &$sendOk): bool {
                $sent[] = ['chat'=>$chat, 'text'=>$text, 'buttons'=>$buttons]; return $sendOk;
            }, 'ai', null, false
        );
        $telegramMessenger = new TelegramMessengerAdapter(
            static function (string $method, array $payload) use (&$sent, &$sendOk): bool {
                if ($method !== 'sendMessage') throw new RuntimeException('Unexpected Telegram transport method ' . $method);
                $markup = json_decode((string)($payload['reply_markup'] ?? '{}'), true);
                $sent[] = ['chat'=>$payload['chat_id'], 'text'=>$payload['text'], 'buttons'=>$markup['inline_keyboard'] ?? []];
                return $sendOk;
            }, 'ai', false
        );
        $chatBase = 8000000000 + random_int(100000, 999999);
        $seed = static function (int $chat) use (&$chats): void {
            $chats[] = $chat;
            MaxSearchApi::$needs[$chat] = [MaxSearchApi::$statusContryChoose=>'4', MaxSearchApi::$statusDate=>'14.12.2030'];
            MaxSearchApi::$statuses[$chat] = MaxSearchApi::$statusCheck;
            MaxSearchApi::$edits[$chat] = 'date';
            MaxSearchApi::$followups[$chat] = 'old-followup';
            AiDateHandler::rememberMonth($chat, 12, 2030);
            EditFlowService::captureSnapshot($chat, true);
        };
        $enter = static function (string $platform, int $external, string $payload='') use ($maxMessenger, $telegramMessenger): void {
            if ($platform === 'max') {
                IntegrationRegistry::useMessenger($maxMessenger);
                MaxUpdateHandler::handleStarted(['update_type'=>'bot_started', 'payload'=>$payload, 'user'=>['user_id'=>$external, 'first_name'=>'Synthetic']]);
                return;
            }
            TelegramWebhookHandler::dispatchUpdate(['update_id'=>$external, 'message'=>[
                'message_id'=>1, 'from'=>['id'=>$external, 'first_name'=>'Synthetic'], 'chat'=>['id'=>$external],
                'text'=>'/start' . ($payload !== '' ? ' ' . $payload : ''),
            ]], null, $telegramMessenger);
        };
        $welcomes = [];
        foreach (['max', 'telegram'] as $index=>$platform) {
            $external = $chatBase + $index;
            $chat = $platform === 'max' ? -$external : $external;
            $seed($chat);
            $check($platform . ' fixture starts with a remembered month', PendingMonthStore::get($chat), ['month'=>12, 'year'=>2030]);
            $before = count($sent); $writesBefore = count(MaxSearchApi::$writes);
            $enter($platform, $external);
            $check($platform . ' organic start sends one welcome', count($sent), $before + 1);
            $welcome = $sent[$before] ?? [];
            unset($welcome['chat']); $welcomes[] = $welcome;
            $check($platform . ' organic start offers the same search actions', array_column(array_merge(...($welcome['buttons'] ?? [])), 'callback_data'), ['ai_start', 'start_search']);
            $check($platform . ' organic start writes only the delivered start state', array_slice(MaxSearchApi::$writes, $writesBefore), [[$chat, MaxSearchApi::$statusStart]]);
            $check($platform . ' organic start clears old trip values', MaxSearchApi::getSavedData($chat), []);
            $check($platform . ' organic start clears edit mode', MaxSearchApi::getEditMode($chat), '');
            $check($platform . ' organic start cancels old followup', isset(MaxSearchApi::$followups[$chat]), false);
            $check($platform . ' organic start clears pending month', PendingMonthStore::get($chat), []);
            $check($platform . ' short day cannot inherit the previous trip month', AiDateHandler::resolvePendingShortDate($chat, '14'), '');
            $check($platform . ' organic start clears previous edit snapshot', EditFlowService::captureSnapshot($chat), []);
            EditFlowService::clearSnapshot($chat);

            // Both real incoming adapters must reach the canonical AI handler before a mode button.
            $phrase = 'Хочу отдохнуть у моря'; $reached = null; $messagesBefore = count($sent);
            MaxSearchApi::$probeAi = true;
            try {
                if ($platform === 'max') {
                    $incoming = MaxIncomingAdapter::fromUpdate(['update_type'=>'message_created', 'message'=>[
                        'sender'=>['user_id'=>$external], 'body'=>['mid'=>'fixture-text', 'text'=>$phrase],
                    ]]);
                    (new IncomingUpdateDispatcher())->dispatch($incoming);
                } else {
                    TelegramWebhookHandler::dispatchUpdate(['update_id'=>$external + 100, 'message'=>[
                        'message_id'=>2, 'from'=>['id'=>$external], 'chat'=>['id'=>$external], 'text'=>$phrase,
                    ]], null, $telegramMessenger);
                }
            } catch (DialogueStartAiReached $e) { $reached = $e->getMessage(); }
            finally { MaxSearchApi::$probeAi = false; }
            $check($platform . ' immediate free text reaches real AI unchanged', $reached, $phrase);
            $check($platform . ' free text needs no intermediate chooser response', count($sent), $messagesBefore);
        }
        $check('MAX and Telegram organic welcome text and buttons match', $welcomes[0], $welcomes[1]);

        $paidChat = -($chatBase + 10); $seed($paidChat);
        $before = count($sent);
        $enter('max', -$paidChat, '1234567890123456_region_213_campaign_42');
        $check('paid MAX entry retains exact Mini App attribution', $sent[$before]['buttons'][0][0]['url'] ?? null,
            'https://max.ru/fixture_bot?startapp=1234567890123456_region_213_campaign_42');
        $check('paid MAX entry retains subscription-first screen', count($sent[$before]['buttons'] ?? []), 1);
        $check('paid MAX entry retains delayed search introduction', end(MaxSearchApi::$searchIntros), [$paidChat, 60]);
        $check('paid MAX entry also clears remembered date', PendingMonthStore::get($paidChat), []);

        $payloadChat = $chatBase + 20; $seed($payloadChat);
        $enter('telegram', $payloadChat, 'tg_fixture');
        $check('Telegram payload start clears remembered date', PendingMonthStore::get($payloadChat), []);
        $check('Telegram payload start clears old edit snapshot', EditFlowService::captureSnapshot($payloadChat), []);
        EditFlowService::clearSnapshot($payloadChat);

        $sendOk = false;
        foreach (['max', 'telegram'] as $index=>$platform) {
            $external = $chatBase + 30 + $index; $chat = $platform === 'max' ? -$external : $external;
            $seed($chat); $writesBefore = count(MaxSearchApi::$writes);
            $enter($platform, $external);
            $check($platform . ' failed welcome does not write start state', count(MaxSearchApi::$writes), $writesBefore);
            $check($platform . ' failed welcome leaves no false active start state', MaxSearchApi::getCurentStatus($chat), 0);
        }
        $sendOk = true;
        $failureChat = $chatBase + 40; $chats[] = $failureChat; MaxSearchApi::$resetFailureChat = $failureChat;
        $before = count($sent);
        $enter('telegram', $failureChat);
        $check('Telegram reset failure still delivers welcome', count($sent), $before + 1);
        $check('Telegram reset failure remains observable', count(array_filter(DiagnosticLogger::$events,
            static fn(array $event): bool => ($event[1] ?? '') === 'reset_failed')), 1);
        MaxSearchApi::$resetFailureChat = null;
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL  start parity fixture: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    } finally {
        if (class_exists('EditFlowService')) foreach ($chats as $chat) EditFlowService::clearSnapshot($chat);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($tmp);
    }
    echo "DIALOGUE START PARITY: PASS {$passed} FAIL {$failed}\n";
    exit($failed > 0 ? 1 : 0);
}

require_once __DIR__ . '/../services/IncomingMessage.php';
require_once __DIR__ . '/../services/DialogueController.php';

$passed = 0;
$failed = 0;
function dcCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$incoming = IncomingMessage::text(
    'telegram',
    77,
    77,
    'm1',
    'Хочу Турцию',
    ['first_name'=>'Ivan','last_name'=>'Petrov','username'=>'ivan']
);
$message = DialogueController::messageEnvelope($incoming);
dcCheck('message chat id preserved', $message['chat']['id'], 77);
dcCheck('message user first name preserved', $message['from']['first_name'], 'Ivan');
dcCheck('message platform preserved', $message['_platform'], 'telegram');
dcCheck('message text preserved', $message['text'], 'Хочу Турцию');

$callback = IncomingMessage::callback(
    'max',
    123,
    -123,
    'cb1',
    'manager_request',
    ['first_name'=>'Pavel']
);
$query = DialogueController::queryEnvelope($callback);
dcCheck('callback internal id preserved', $query['from']['id'], -123);
dcCheck('callback payload preserved', $query['data'], 'manager_request');
dcCheck('callback platform preserved', $query['_platform'], 'max');

$reply = DialogueController::objectionReply('очень дорого!');
dcCheck('price objection detected', is_string($reply), true);
dcCheck('normal message not objection', DialogueController::objectionReply('Турция на 7 ночей'), null);
dcCheck('objection response suggests dates', mb_strpos((string)$reply, 'сдвинуть даты') !== false, true);

$fixtureOutput = [];
$fixtureStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --start-parity-fixture 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('real MAX/Telegram entry and start parity fixture', $fixtureStatus, 0);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
