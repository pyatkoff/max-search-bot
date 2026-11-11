<?php

declare(strict_types=1);

// Isolate real entry/controller/view behavior from the envelope-only checks below.
// Storage, catalog, model responses and transport are synthetic; dialogue decisions use production code.
$dateCityOnly = in_array('--date-city-clarifications-only', $argv ?? [], true);
$departureRetryOnly = in_array('--departure-retry-only', $argv ?? [], true);
$clarificationOnly = in_array('--useful-clarifications-only', $argv ?? [], true);
$overlapOnly = in_array('--overlapping-turn-only', $argv ?? [], true);
$redundantOverlapOnly = in_array('--redundant-fact-overlap-only', $argv ?? [], true);
$jointHandoff = in_array('--compound-handoff-only', $argv ?? [], true);
$jointOnly = $dateCityOnly || $redundantOverlapOnly || $overlapOnly || $clarificationOnly || $departureRetryOnly || $jointHandoff || in_array('--compound-joint-only', $argv ?? [], true);
if ($jointOnly || in_array('--start-parity-fixture', $argv ?? [], true)) {
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
        foreach (['project.php', 'maxsearchbaseclass.php', 'DepartureRouteResolver.php', 'DepartureRouteAdvisor.php'] as $name) {
            copy($root . '/' . $name, $tmp . '/' . $name);
        }
        mkdir($tmp . '/resources', 0700);
        copy($root . '/resources/yandex_regions.json', $tmp . '/resources/yandex_regions.json');
        if (is_file($root . '/resources/regional_departure_rules.json')) {
            copy($root . '/resources/regional_departure_rules.json', $tmp . '/resources/regional_departure_rules.json');
        }
        if (is_dir($root . '/resources/yandex_regions')) {
            mkdir($tmp . '/resources/yandex_regions', 0700);
            foreach (glob($root . '/resources/yandex_regions/*.json') ?: [] as $bucket) {
                copy($bucket, $tmp . '/resources/yandex_regions/' . basename($bucket));
            }
        }
        if ($dateCityOnly || $jointHandoff || $departureRetryOnly || $clarificationOnly || $overlapOnly || $redundantOverlapOnly) {
            foreach (['tests/fixtures/channel_post_entry_fixture.php','tests/fixtures/channel_post_registry_fixture.php',
                'migrations/033_channel_post_context.sql','migrations/036_channel_post_member_delivery.sql',
                'migrations/040_channel_post_reconciliation.sql'] as $path) {
                if (!is_dir(dirname($tmp . '/' . $path))) mkdir(dirname($tmp . '/' . $path),0700,true);
                copy($root . '/' . $path,$tmp . '/' . $path);
            }
        }
        // Handoff mode injects SQLite through the existing DB fixture. Other
        // modes reject DB access entirely; neither mode uses an external DB.
        if (!$dateCityOnly && !$jointHandoff && !$departureRetryOnly && !$clarificationOnly && !$overlapOnly && !$redundantOverlapOnly) file_put_contents($tmp . '/services/ConversationDb.php', <<<'PHP'
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
        file_put_contents($tmp . '/services/TravelDirectoryRepository.php', <<<'PHP'
<?php
class TravelDirectoryRepository {
    public static bool $includeMinsk = false;
    public static function activeDepartures(): array {
        return array_merge([
            ['id'=>1, 'name'=>'Казань', 'name_genitive'=>'Казани'],
            ['id'=>2, 'name'=>'Ростов-на-Дону', 'name_genitive'=>'Ростова-на-Дону'],
            ['id'=>3, 'name'=>'Минеральные Воды', 'name_genitive'=>'Минеральных Вод'],
            ['id'=>4, 'name'=>'Самара', 'name_genitive'=>'Самары'],
            ['id'=>5, 'name'=>'Москва', 'name_genitive'=>'Москвы'],
            ['id'=>10, 'name'=>'Санкт Город', 'name_genitive'=>'Санкт Города'],
            ['id'=>11, 'name'=>'Санаторный Город', 'name_genitive'=>'Санаторного Города'],
        ], self::$includeMinsk ? [['id'=>100, 'name'=>'Минск', 'name_genitive'=>'Минска']] : []);
    }
}
PHP
        );
        file_put_contents($tmp . '/services/DestinationCatalogRepository.php', <<<'PHP'
<?php
class DestinationCatalogRepository {
    public static array $queries = [];
    public static function query(int $id, array $filter, array $select, int $limit=20): array {
        self::$queries[] = [$id, $filter, $select, $limit];
        if (MaxSearchApi::$canonicalStorage) {
            $rows = $id === 2 ? [
                ['UF_CID'=>1,'UF_NAME'=>'Египет'],['UF_CID'=>4,'UF_NAME'=>'Турция'],['UF_CID'=>47,'UF_NAME'=>'Россия']
            ] : ($id === 3 ? [['UF_TID'=>100,'UF_CID'=>47,'UF_NAME'=>'Самара','UF_PARENT_TID'=>0]] : []);
            return array_values(array_filter($rows,static function(array $row) use($filter): bool {
                foreach ($filter as $key=>$value) {
                    if ($key[0] === '=' && ($row[substr($key,1)] ?? null) != $value) return false;
                    if ($key[0] === '%' && mb_stripos((string)($row[substr($key,1)] ?? ''),(string)$value) === false) return false;
                }
                return true;
            }));
        }
        // If the departure leaks into destination inference, reproduce its
        // plausible Russian resort match instead of silently returning nothing.
        if ($id === 3 && preg_match('/самар/ui', (string)($filter['%UF_NAME'] ?? ''))) {
            return [['UF_TID'=>100, 'UF_CID'=>1, 'UF_NAME'=>'Самара', 'UF_PARENT_TID'=>0]];
        }
        if ($id === 2 && (int)($filter['=UF_CID'] ?? 0) === 1) {
            return [['UF_CID'=>1, 'UF_NAME'=>'Россия']];
        }
        return [];
    }
}
PHP
        );
        file_put_contents($tmp . '/services/AiInvocationService.php', <<<'PHP'
<?php
class AiInvocationService {
    public static array $calls = [];
    public static ?array $answer = null;
    public static $duringInvoke = null;
    public static function invoke($route, $chat, $text, $context): array {
        self::$calls[] = [$route, $chat, $text, $context];
        if (self::$duringInvoke !== null) {
            $callback = self::$duringInvoke; self::$duringInvoke = null;
            $callback();
        }
        if (self::$answer !== null) return self::$answer;
        throw new RuntimeException('Fixture forbids external model calls');
    }
}
PHP
        );
        class DialogueStartAiReached extends RuntimeException {}
        require_once $tmp . '/maxsearchbaseclass.php';
        function put_log_out($data): void {}
        class MaxSearchApi extends MaxSearchBase {
            public static $statusStart=64, $statusCityChoose=65, $statusContryChoose=66, $statusAdults=67,
                $statusChild=68, $statusAge=69, $statusStars=70, $statusMeal=71, $statusNights=72,
                $statusDate=73, $statusCheck=74, $statusPhone=75, $statusAi=76;
            public static array $statuses=[], $needs=[], $edits=[], $followups=[], $writes=[], $events=[], $searchIntros=[];
            public static array $aiContexts=[], $missingFields=[], $parameterWrites=[];
            public static string $testBaseDir='';
            public static ?int $resetFailureChat=null;
            public static bool $probeAi=false;
            public static function getLastClaimForChat($chat): array { return ['CODE'=>'fixture-existing-claim']; }
            public static function saveClaim(...$args): bool { throw new RuntimeException('Fixture forbids legacy claim writes'); }
            public static function queueMetrikaGoal(...$args): bool { return true; }
            public static function answerCallback(...$args): void {}

            public static bool $canonicalStorage=false;
            public static ?string $nextPartyFailure=null;
            public static array $lastValues=[];
            public static function statusMap(): array {
                return ['city'=>65,'country'=>66,'adults'=>67,'children'=>68,'child_ages'=>69,
                    'stars'=>70,'meal'=>71,'nights'=>72,'date'=>73];
            }
            public static function getCityByID($id) { return [1=>'Москва',4=>'Самара',10=>'Казань',100=>'Минск'][$id] ?? false; }
            public static function getCountryByID($id) { return [1=>'Египет',4=>'Турция',47=>'Россия'][$id] ?? false; }
            public static function getCountryByName($name) { return $name === 'Турция' ? ['ID'=>4,'NAME'=>'Турция'] : false; }
            public static function getMealArr(): array { return ['7'=>'Всё включено']; }
            public static function saveLastValue($chat,$status,$value): void { self::$lastValues[$chat][$status]=$value; }
            public static function getLastValue($chat,$status) { return self::$lastValues[$chat][$status] ?? false; }

            public static bool $rejectParameters=false, $staleParameterReadback=false;
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
            public static function getAiSearchContext($chat): array {
                if (self::$canonicalStorage) return AiSearchContextService::contextFromSaved(
                    self::getSavedData($chat),self::statusMap(),[self::class,'getCityByID'],[self::class,'getCountryByID']);
                return self::$aiContexts[$chat] ?? [];
            }
            public static function getCityByName($text) { return false; }
            public static function getAiMissingFields($chat): array {
                if (self::$canonicalStorage) return AiSearchContextService::missingForCurrentSearch(self::getSavedData($chat),self::statusMap());
                return self::$missingFields[$chat] ?? ['city'];
            }
            public static function applyAiParameters($chat, array $params): array {
                self::$parameterWrites[] = [$chat, $params];
                if (self::$rejectParameters) return [];
                if (self::$canonicalStorage) {
                    $normalized=AiSearchContextService::normalizeParameters($params,
                        static fn($name)=>$name==='Самара'?4:($name==='Минск' && TravelDirectoryRepository::$includeMinsk?100:null),static fn($name)=>$name==='Россия'?47:null,
                        static fn($date)=>NativeDateService::isTodayOrFuture($date));
                    $partyFailure = array_key_exists('adults',$normalized) ? self::$nextPartyFailure : null;
                    if ($partyFailure !== null) self::$nextPartyFailure = null;
                    $partyKeys = array_fill_keys(['adults','children','child_ages'],true);
                    $writeValues = $partyFailure !== null ? array_diff_key($normalized,$partyKeys) : $normalized;
                    if (!self::$staleParameterReadback) {
                        foreach (AiSearchContextService::storageMap(self::statusMap()) as $field=>$status) {
                            if (array_key_exists($field,$writeValues)) self::$needs[$chat][$status]=$writeValues[$field];
                        }
                    }
                    return array_fill_keys(array_keys($partyFailure === 'reject' ? $writeValues : $normalized),true);
                }
                if (!self::$staleParameterReadback) {
                    self::$aiContexts[$chat] = array_merge(self::getAiSearchContext($chat), $params);
                    self::$missingFields[$chat] = array_values(array_diff(self::getAiMissingFields($chat), array_keys($params)));
                }
                return array_fill_keys(array_keys($params), true);
            }
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
        if (!$jointOnly) {
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

        }

        // Exercise the real controller/resolver/application/progression route.
        // Stop unmatched turns at the existing AI log boundary before any model call.
        $cityTurn = static function (int $chat, string $platform, string $text, array $context, array $missing, int $status, bool $probeAi = true)
            use (&$chats, &$sent, $maxMessenger, $telegramMessenger): array {
            $chats[] = $chat;
            MaxSearchApi::$statuses[$chat] = $status;
            MaxSearchApi::$aiContexts[$chat] = $context;
            MaxSearchApi::$missingFields[$chat] = $missing;
            if (MaxSearchApi::$canonicalStorage) {
                MaxSearchApi::$needs[$chat] = [MaxSearchApi::$statusStars=>null,MaxSearchApi::$statusMeal=>null];
                MaxSearchApi::applyAiParameters($chat,$context);
            }
            MaxSearchApi::$parameterWrites = [];
            DestinationCatalogRepository::$queries = [];
            AiInvocationService::$calls = [];
            IntegrationRegistry::useMessenger($platform === 'max' ? $maxMessenger : $telegramMessenger);
            $before = count($sent); $eventsBefore = count(MaxSearchApi::$events);
            $reached = null; $handled = null;
            MaxSearchApi::$probeAi = $probeAi;
            try {
                $handled = (new DialogueController())->handleIncomingMessage(IncomingMessage::text(
                    $platform, abs($chat), $chat, 'fixture-city', $text
                ));
            } catch (DialogueStartAiReached $e) { $reached = $e->getMessage(); }
            finally { MaxSearchApi::$probeAi = false; }
            return [
                'handled'=>$handled, 'ai_text'=>$reached,
                'messages'=>array_slice($sent, $before),
                'events'=>array_column(array_slice(MaxSearchApi::$events, $eventsBefore), 1),
                'context'=>MaxSearchApi::getAiSearchContext($chat),
                'missing'=>MaxSearchApi::getAiMissingFields($chat),
                'writes'=>MaxSearchApi::$parameterWrites,
                'destination_queries'=>count(DestinationCatalogRepository::$queries),
                'destination_filters'=>DestinationCatalogRepository::$queries,
                'model_calls'=>AiInvocationService::$calls,
            ];
        };
        if ($dateCityOnly) {
            require_once $tmp . '/tests/fixtures/channel_post_entry_fixture.php';
            [$pdo, $registry, $publications] = createChannelPostEntryFixture();
            MaxSearchApi::$canonicalStorage = true;
            $year = (int)date('Y') + 2;
            $help = 'Вы указали два города вылета. Какой из них взять для первого подбора?';
            $native = static function (string $platform, int $user, int $mid, string $text) use ($maxMessenger, $telegramMessenger): void {
                IntegrationRegistry::useMessenger($platform === 'max' ? $maxMessenger : $telegramMessenger);
                if ($platform === 'max') {
                    (new IncomingUpdateDispatcher())->dispatch(MaxIncomingAdapter::fromUpdate([
                        'update_type'=>'message_created', 'message'=>['sender'=>['user_id'=>$user],
                            'body'=>['mid'=>(string)$mid, 'text'=>$text]],
                    ]));
                } else {
                    TelegramWebhookHandler::dispatchUpdate(['update_id'=>$mid, 'message'=>[
                        'message_id'=>$mid, 'from'=>['id'=>$user], 'chat'=>['id'=>$user, 'type'=>'private'], 'text'=>$text,
                    ]], null, $telegramMessenger);
                }
            };
            $mid = 960000;
            foreach (['max','telegram'] as $index=>$platform) {
                $user=$chatBase+850+$index; $chat=$platform==='max'?-$user:$user; $chats[]=$chat;
                $enter($platform,$user,$publications[$platform]['start_payload']);
                $q=$pdo->prepare('SELECT id FROM conversations WHERE channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
                $q->execute([$platform,(string)$chat]); $id=(int)$q->fetchColumn();
                $intent=SourceHotelIntentService::current($id);
                (new IncomingUpdateDispatcher())->dispatch(['platform'=>$platform,'type'=>'callback',
                    'callback_data'=>'hotel_intent_same:'.($intent['token']??''),'callback_id'=>'date-city.'.$user,
                    'user'=>['chat_id'=>$chat,'external_user_id'=>$user]]);
                $cityNeeds=[66=>'4',70=>'4',71=>'7',72=>'8',73=>'17.12.'.$year];
                MaxSearchApi::$needs[$chat]=$cityNeeds; MaxSearchApi::$statuses[$chat]=MaxSearchApi::$statusAi;
                foreach (['Самара или Минеральные Воды','из Самары либо из Минеральных Вод'] as $text) {
                    $sentBefore=count($sent); MaxSearchApi::$parameterWrites=[]; AiInvocationService::$calls=[];
                    DestinationCatalogRepository::$queries=[];
                    $native($platform,$user,++$mid,$text);
                    $check($platform.' alternate departures ask one clear choice',
                        array_column(array_slice($sent,$sentBefore),'text'),[$help]);
                    $check($platform.' alternatives do not alter any trip need',MaxSearchApi::getSavedData($chat),$cityNeeds);
                    $check($platform.' alternatives do not infer a destination or invoke the model',
                        [DestinationCatalogRepository::$queries,AiInvocationService::$calls,MaxSearchApi::$parameterWrites],[[],[],[]]);
                }
                $check($platform.' alternatives neither assign nor queue a manager',
                    [(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id={$id} AND event_type='manager_request'")->fetchColumn(),
                     $pdo->query("SELECT manager_id FROM conversations WHERE id={$id}")->fetchColumn()],[0,null]);
                $sentBefore=count($sent);
                $native($platform,$user,++$mid,'Самара');
                $check($platform.' scalar departure still uses the existing next field',
                    array_column(array_slice($sent,$sentBefore),'text'),[MissingFieldQuestionService::question('adults')]);
                $check($platform.' scalar departure keeps the destination',
                    [MaxSearchApi::getAiSearchContext($chat)['city']??null,MaxSearchApi::getAiSearchContext($chat)['country']??null],['Самара','Турция']);
                AiInvocationService::$answer=['intent'=>'question','parameters'=>[],'reply'=>'Synthetic question route'];
                AiInvocationService::$calls=[]; $sentBefore=count($sent);
                $native($platform,$user,++$mid,'Самара или Минеральные Воды');
                $check($platform.' another missing field keeps the existing interpretation route',
                    [array_column(array_slice($sent,$sentBefore),'text'),count(AiInvocationService::$calls)],[['Synthetic question route'],1]);

                // Sequential native inputs, not the accepted overlapping-turn scenario.
                MaxSearchApi::$needs[$chat]=[66=>'4',67=>'2',68=>'0',70=>'4',71=>'7',72=>'8'];
                MaxSearchApi::$statuses[$chat]=MaxSearchApi::$statusAi;
                AiInvocationService::$answer=['intent'=>'search','parameters'=>[]];
                $native($platform,$user,++$mid,'16-18 июля '.$year);
                $check($platform.' a range first requires one confirmed departure date',
                    PendingMonthStore::requiresClarification($chat),true);
                $sentBefore=count($sent);
                $native($platform,$user,++$mid,'17 12 '.$year);
                $check($platform.' complete spaced date is saved before asking departure city',
                    [MaxSearchApi::getAiSearchContext($chat)['date']??null,PendingMonthStore::get($chat)],
                    ['17.12.'.$year,[]]);
                $check($platform.' complete date asks only the still missing city',
                    array_column(array_slice($sent,$sentBefore),'text'),[MissingFieldQuestionService::question('city')]);
                $sentBefore=count($sent);
                $native($platform,$user,++$mid,'Самара');
                $check($platform.' city answer keeps the confirmed date and independent needs',
                    [MaxSearchApi::getAiSearchContext($chat)['date']??null,
                     MaxSearchApi::getAiSearchContext($chat)['country']??null,
                     MaxSearchApi::getAiSearchContext($chat)['adults']??null,
                     MaxSearchApi::getAiSearchContext($chat)['nights']??null],['17.12.'.$year,'Турция',2,'8']);
                $check($platform.' completed needs reach one canonical handoff without repeating date',
                    [(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id={$id} AND event_type='manager_request'")->fetchColumn(),
                     count(array_slice($sent,$sentBefore)),str_contains(implode(' ',array_column(array_slice($sent,$sentBefore),'text')),'Какая ориентировочная дата вылета')],[1,1,false]);
            }
        } elseif ($redundantOverlapOnly) {
            require_once $tmp . '/tests/fixtures/channel_post_entry_fixture.php';
            [$pdo, $registry, $publications] = createChannelPostEntryFixture();
            MaxSearchApi::$canonicalStorage = true;
            foreach (['max', 'telegram'] as $index=>$platform) {
                $user = $chatBase + 770 + $index;
                $chat = $platform === 'max' ? -$user : $user;
                $chats[] = $chat;
                $enter($platform, $user, $publications[$platform]['start_payload']);
                $q = $pdo->prepare('SELECT id FROM conversations WHERE channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
                $q->execute([$platform, (string)$chat]);
                $id = (int)$q->fetchColumn();
                $intent = SourceHotelIntentService::current($id);
                (new IncomingUpdateDispatcher())->dispatch(['platform'=>$platform, 'type'=>'callback',
                    'callback_data'=>'hotel_intent_same:'.($intent['token'] ?? ''), 'callback_id'=>'redundant.'.$user,
                    'user'=>['chat_id'=>$chat, 'external_user_id'=>$user]]);
                // The date was already confirmed in an earlier turn. No party
                // count is inferred from a generic number of people.
                $date = '18.12.'.((int)date('Y') + 1);
                $seedNeeds = [70=>'4', 71=>'7', 72=>'8', 73=>$date];
                MaxSearchApi::$needs[$chat] = $seedNeeds;
                MaxSearchApi::$statuses[$chat] = MaxSearchApi::$statusAi;
                $native = static function (int $mid, string $text) use ($user, $platform, $maxMessenger, $telegramMessenger): void {
                    IntegrationRegistry::useMessenger($platform === 'max' ? $maxMessenger : $telegramMessenger);
                    if ($platform === 'max') {
                        (new IncomingUpdateDispatcher())->dispatch(MaxIncomingAdapter::fromUpdate([
                            'update_type'=>'message_created', 'message'=>['sender'=>['user_id'=>$user],
                                'body'=>['mid'=>(string)$mid, 'text'=>$text]],
                        ]));
                    } else {
                        TelegramWebhookHandler::dispatchUpdate(['update_id'=>$mid, 'message'=>[
                            'message_id'=>$mid, 'from'=>['id'=>$user], 'chat'=>['id'=>$user, 'type'=>'private'], 'text'=>$text,
                        ]], null, $telegramMessenger);
                    }
                };
                $mid = 950000 + $index * 100;
                $sentBefore = count($sent);
                AiInvocationService::$calls = [];
                MaxSearchApi::$parameterWrites = [];
                // Deliberately synthetic payload: the real model payload is unknown.
                AiInvocationService::$answer = ['intent'=>'search', 'parameters'=>['date'=>$date]];
                AiInvocationService::$duringInvoke = static function () use ($native, $mid): void {
                    $native($mid + 2, 'Самара');
                };
                $native($mid + 1, 'Декабря');
                $check($platform.' delayed redundant date invokes only the old model boundary',
                    array_map(static fn(array $call): array => [$call[2], $call[3]['date'] ?? null], AiInvocationService::$calls), [['Декабря', $date]]);
                $check($platform.' delayed redundant date does not repeat the newer country question',
                    array_column(array_slice($sent, $sentBefore), 'text'), [MissingFieldQuestionService::question('country')]);
                $expected = $seedNeeds;
                $expected[65] = 4;
                $check($platform.' delayed redundant date preserves the new city and independent needs', MaxSearchApi::getSavedData($chat), $expected);
                $check($platform.' overlap never invents adults or destination',
                    [MaxSearchApi::getAiSearchContext($chat)['adults'] ?? null, MaxSearchApi::getAiSearchContext($chat)['country'] ?? null], [null, null]);
                $check($platform.' redundant model echo performs no extra parameter write',
                    MaxSearchApi::$parameterWrites, [[$chat, ['city'=>'Самара']]]);
                $q = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id=? AND direction='inbound' AND external_message_id IN (?,?)");
                $q->execute([$id, (string)($mid + 1), (string)($mid + 2)]);
                $check($platform.' redundant-date overlap records both distinct native inputs', (int)$q->fetchColumn(), 2);

                $mid += 10;
                $run = static function (string $text, array $answer, bool $overlap = true)
                    use ($chat, $seedNeeds, $native, &$mid, &$sent): array {
                    MaxSearchApi::$needs[$chat] = $seedNeeds;
                    MaxSearchApi::$statuses[$chat] = MaxSearchApi::$statusAi;
                    PendingMonthStore::clear($chat);
                    AiInvocationService::$calls = [];
                    MaxSearchApi::$parameterWrites = [];
                    AiInvocationService::$answer = $answer;
                    $oldMid = ($mid += 2);
                    AiInvocationService::$duringInvoke = $overlap
                        ? static function () use ($native, $oldMid): void { $native($oldMid + 1, 'Самара'); }
                        : null;
                    $before = count($sent);
                    $native($oldMid, $text);
                    return array_column(array_slice($sent, $before), 'text');
                };
                $check($platform.' redundant correction has the same overlap guard',
                    $run('Декабря', ['intent'=>'correction', 'parameters'=>['date'=>$date]]),
                    [MissingFieldQuestionService::question('country')]);
                $check($platform.' redundant correction also writes only the newer city',
                    MaxSearchApi::$parameterWrites, [[$chat, ['city'=>'Самара']]]);

                $check($platform.' without overlap a repeated date keeps ordinary progression',
                    $run('Декабря', ['intent'=>'search', 'parameters'=>['date'=>$date]], false),
                    [MissingFieldQuestionService::question('city')]);
                $check($platform.' ordinary repeated date preserves all saved needs', MaxSearchApi::getSavedData($chat), $seedNeeds);

                $run('Декабря', ['intent'=>'correction', 'parameters'=>['date'=>$date, 'stars'=>5]]);
                $withStars = $expected;
                $withStars[70] = 5;
                $check($platform.' mixed redundant and new facts still apply the new fact', MaxSearchApi::getSavedData($chat), $withStars);
                $newDate = '19.12.'.((int)date('Y') + 1);
                $run('Перенесём поездку', ['intent'=>'correction', 'parameters'=>['date'=>$newDate]]);
                $withDate = $expected;
                $withDate[73] = $newDate;
                $check($platform.' a genuinely changed delayed date is not discarded', MaxSearchApi::getSavedData($chat), $withDate);

                foreach ([
                    ['question', 'Есть ли бассейн?', 'Условия посещения бассейна можно уточнить у менеджера.'],
                    ['stop', 'Пока не буду выбирать', 'Хорошо, можно вернуться к подбору позже.'],
                ] as [$intentName, $text, $reply]) {
                    $check($platform.' nonempty delayed '.$intentName.' retains its reply',
                        $run($text, ['intent'=>$intentName, 'parameters'=>['date'=>$date], 'reply'=>$reply]),
                        [MissingFieldQuestionService::question('country'), $reply]);
                    $check($platform.' delayed '.$intentName.' preserves independent needs and newer city',
                        MaxSearchApi::getSavedData($chat), $expected);
                }
                $check($platform.' delayed error remains on its existing error path',
                    $run('Декабря', ['_error'=>'synthetic timeout', 'intent'=>'search', 'parameters'=>['date'=>$date]]),
                    [MissingFieldQuestionService::question('country'), MissingFieldQuestionService::question('country')]);
                $check($platform.' delayed error preserves all saved needs', MaxSearchApi::getSavedData($chat), $expected);
            }
        } elseif ($overlapOnly) {
            require_once $tmp . '/tests/fixtures/channel_post_entry_fixture.php';
            [$pdo, $registry, $publications] = createChannelPostEntryFixture();
            MaxSearchApi::$canonicalStorage = true;
            foreach (['max','telegram'] as $index=>$platform) {
                $user=$chatBase+750+$index; $chat=$platform==='max'?-$user:$user; $chats[]=$chat;
                $enter($platform,$user,$publications[$platform]['start_payload']);
                $q=$pdo->prepare('SELECT id FROM conversations WHERE channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
                $q->execute([$platform,(string)$chat]); $id=(int)$q->fetchColumn();
                $intent=SourceHotelIntentService::current($id);
                (new IncomingUpdateDispatcher())->dispatch(['platform'=>$platform,'type'=>'callback',
                    'callback_data'=>'hotel_intent_same:'.($intent['token']??''),'callback_id'=>'overlap.'.$user,
                    'user'=>['chat_id'=>$chat,'external_user_id'=>$user]]);
                $seedNeeds=[65=>'4',66=>'1',70=>'4',71=>'7',72=>'8',73=>'05.04.'.((int)date('Y')+1)];
                MaxSearchApi::$needs[$chat]=$seedNeeds; MaxSearchApi::$statuses[$chat]=MaxSearchApi::$statusAi;
                $native=static function(int $mid,string $text)use($user,$platform,$maxMessenger,$telegramMessenger):void {
                    IntegrationRegistry::useMessenger($platform==='max'?$maxMessenger:$telegramMessenger);
                    if ($platform==='max') {
                        (new IncomingUpdateDispatcher())->dispatch(MaxIncomingAdapter::fromUpdate([
                            'update_type'=>'message_created','message'=>['sender'=>['user_id'=>$user],
                                'body'=>['mid'=>(string)$mid,'text'=>$text]],
                        ]));
                    } else {
                        TelegramWebhookHandler::dispatchUpdate(['update_id'=>$mid,'message'=>[
                            'message_id'=>$mid,'from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text,
                        ]],null,$telegramMessenger);
                    }
                };
                $mid=940000+$index*100;
                $sentBefore=count($sent);AiInvocationService::$calls=[];
                AiInvocationService::$answer=['intent'=>'search','parameters'=>[]];
                AiInvocationService::$duringInvoke=static function()use($native,$mid):void { $native($mid+2,'3'); };
                $native($mid+1,'8 дней');
                $check($platform.' nested newer input reaches the canonical handler during the old model call',MaxSearchApi::getAiSearchContext($chat)['adults']??null,3);
                $check($platform.' overlap invokes only the old synthetic model boundary',count(AiInvocationService::$calls),1);
                $check($platform.' old no-fact result does not repeat the newer question',array_column(array_slice($sent,$sentBefore),'text'),[MissingFieldQuestionService::question('children')]);
                $expected=$seedNeeds;$expected[67]=3;
                $check($platform.' overlap preserves newer adults and all independent needs',MaxSearchApi::getSavedData($chat),$expected);
                $q=$pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id=? AND direction='inbound' AND external_message_id IN (?,?)");
                $q->execute([$id,(string)($mid+1),(string)($mid+2)]);
                $check($platform.' both distinct native inputs were recorded',(int)$q->fetchColumn(),2);

                // Negative controls keep this a no-fact completion guard, not a
                // global cancellation of delayed interpretation or ordinary turns.
                MaxSearchApi::$needs[$chat]=$seedNeeds; $sentBefore=count($sent);
                $native($mid+3,'8 дней');
                $check($platform.' unchanged context still asks the ordinary next question',array_column(array_slice($sent,$sentBefore),'text'),[MissingFieldQuestionService::question('adults')]);
                MaxSearchApi::$needs[$chat]=$seedNeeds;
                AiInvocationService::$answer=['intent'=>'correction','parameters'=>['stars'=>5]];
                AiInvocationService::$duringInvoke=static function()use($native,$mid):void { $native($mid+5,'3'); };
                $native($mid+4,'8 дней');
                $expected[70]=5;
                $check($platform.' nonempty delayed result retains its independent fact and newer adults',MaxSearchApi::getSavedData($chat),$expected);
                MaxSearchApi::$needs[$chat]=$seedNeeds; $sentBefore=count($sent);
                AiInvocationService::$answer=['intent'=>'question','parameters'=>[],'reply'=>'Описание бассейна можно уточнить у менеджера.'];
                AiInvocationService::$duringInvoke=static function()use($native,$mid):void { $native($mid+7,'3'); };
                $native($mid+6,'Есть ли бассейн?');
                $check($platform.' delayed FAQ remains outside the no-fact search guard',array_column(array_slice($sent,$sentBefore),'text'),[MissingFieldQuestionService::question('children'),'Описание бассейна можно уточнить у менеджера.']);
            }
        } elseif ($clarificationOnly) {
            require_once $tmp . '/tests/fixtures/channel_post_entry_fixture.php';
            [$pdo, $registry, $publications] = createChannelPostEntryFixture();
            MaxSearchApi::$canonicalStorage = true;
            $countryHelp = 'Если пока не выбрали страну, можем обсудить варианты. Можно рассказать, какой отдых вам хочется, или написать «Позвать менеджера» — он поможет с выбором направления.';
            $tourHelp = 'Посмотреть варианты по вашим условиям можно кнопкой «Показать туры» в сообщении с итогами. Если нужна помощь с выбором, нажмите «Подобрать с менеджером» там же.';
            $native = static function (string $platform, int $user, int $mid, string $text) use ($maxMessenger, $telegramMessenger): void {
                IntegrationRegistry::useMessenger($platform === 'max' ? $maxMessenger : $telegramMessenger);
                if ($platform === 'max') {
                    (new IncomingUpdateDispatcher())->dispatch(MaxIncomingAdapter::fromUpdate([
                        'update_type'=>'message_created', 'message'=>['sender'=>['user_id'=>$user],
                            'body'=>['mid'=>(string)$mid, 'text'=>$text]],
                    ]));
                } else {
                    TelegramWebhookHandler::dispatchUpdate(['update_id'=>$mid, 'message'=>[
                        'message_id'=>$mid, 'from'=>['id'=>$user], 'chat'=>['id'=>$user, 'type'=>'private'], 'text'=>$text,
                    ]], null, $telegramMessenger);
                }
            };
            $mid = 930000;
            foreach (['max', 'telegram'] as $index=>$platform) {
                $user = $chatBase + 700 + $index;
                $chat = $platform === 'max' ? -$user : $user;
                $chats[] = $chat;
                $enter($platform, $user, $publications[$platform]['start_payload']);
                $q = $pdo->prepare('SELECT id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
                $q->execute(['anytour', $platform, (string)$chat]);
                $id = (int)$q->fetchColumn();
                $intent = SourceHotelIntentService::current($id);
                (new IncomingUpdateDispatcher())->dispatch(['platform'=>$platform, 'type'=>'callback',
                    'callback_data'=>'hotel_intent_same:'.($intent['token'] ?? ''), 'callback_id'=>'country-help.'.$user,
                    'user'=>['chat_id'=>$chat, 'external_user_id'=>$user]]);
                MaxSearchApi::$needs[$chat] = [65=>'4', 67=>'2', 68=>'1', 69=>'6', 70=>'4', 71=>'7', 72=>'8'];
                MaxSearchApi::$statuses[$chat] = MaxSearchApi::$statusAi;
                $before = MaxSearchApi::getAiSearchContext($chat);
                AiInvocationService::$answer = ['intent'=>'search', 'parameters'=>[]];
                foreach (['Не знаю', 'Пока не знаю.'] as $text) {
                    $sentBefore = count($sent); AiInvocationService::$calls = []; MaxSearchApi::$parameterWrites = [];
                    $native($platform, $user, ++$mid, $text);
                    $check($platform.' undecided country gets choice help', array_column(array_slice($sent, $sentBefore), 'text'), [$countryHelp]);
                    $check($platform.' choice help preserves all trip facts', MaxSearchApi::getAiSearchContext($chat), $before);
                    $check($platform.' choice help leaves the same missing fields', MaxSearchApi::getAiMissingFields($chat), ['country','date']);
                    $check($platform.' choice help neither writes needs nor invokes the model', [MaxSearchApi::$parameterWrites, AiInvocationService::$calls], [[], []]);
                }
                foreach ([
                    ['Не знаю, есть ли в отеле бассейн?', 'question', 'В описании указан бассейн.'],
                    ['Не хочу выбирать страну сейчас', 'stop', 'Хорошо, можно вернуться к подбору позже.'],
                ] as [$text, $intentName, $reply]) {
                    AiInvocationService::$answer = ['intent'=>$intentName, 'parameters'=>[], 'reply'=>$reply];
                    AiInvocationService::$calls = []; $sentBefore = count($sent);
                    $native($platform, $user, ++$mid, $text);
                    $check($platform.' country help preserves '.$intentName.' route', array_column(array_slice($sent, $sentBefore), 'text'), [$reply]);
                    $check($platform.' '.$intentName.' reaches existing model boundary', count(AiInvocationService::$calls), 1);
                    $check($platform.' '.$intentName.' preserves saved needs', MaxSearchApi::getAiSearchContext($chat), $before);
                }
                AiInvocationService::$answer = ['intent'=>'search', 'parameters'=>['country'=>'Турция']];
                $native($platform, $user, ++$mid, 'Турция');
                $expectedCountry = ['city'=>$before['city'], 'country'=>'Турция'] + $before;
                $check($platform.' valid country still uses canonical application', MaxSearchApi::getAiSearchContext($chat), $expectedCountry);
                $check($platform.' valid country moves to the existing next field', MaxSearchApi::getAiMissingFields($chat), ['date']);
                unset(MaxSearchApi::$needs[$chat][65], MaxSearchApi::$needs[$chat][66]);
                AiInvocationService::$answer = ['_error'=>'synthetic model failure'];
                AiInvocationService::$calls = []; $sentBefore = count($sent);
                $native($platform, $user, ++$mid, 'Не знаю');
                $check($platform.' later missing country does not intercept current city',
                    array_column(array_slice($sent, $sentBefore), 'text'), [MissingFieldQuestionService::question('city')]);
                $check($platform.' another first field keeps existing inference', count(AiInvocationService::$calls), 1);
                $check($platform.' choice help never queues or assigns a manager',
                    [(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id={$id} AND event_type='manager_request'")->fetchColumn(),
                     $pdo->query("SELECT manager_id FROM conversations WHERE id={$id}")->fetchColumn()], [0, null]);

                foreach ([
                    ['adults','4ч.',4,'4',[65=>'4',66=>'1',70=>'4',71=>'7',72=>'8'],['children','date']],
                    ['nights','2о-24','20-24','9',[65=>'4',66=>'1',67=>'3',68=>'1',69=>'6',70=>'4',71=>'7'],['date']],
                ] as [$field,$shortAnswer,$canonicalValue,$confirmed,$saved,$afterMissing]) {
                    MaxSearchApi::$needs[$chat]=$saved; MaxSearchApi::$statuses[$chat]=MaxSearchApi::$statusAi;
                    AiInvocationService::$answer=['intent'=>'search','parameters'=>[]];
                    $sentBefore=count($sent);MaxSearchApi::$parameterWrites=[];AiInvocationService::$calls=[];
                    $native($platform,$user,++$mid,$shortAnswer);
                    $fieldStatus=$field==='adults'?67:72;
                    $expectedSaved=$saved; $expectedSaved[$fieldStatus]=$canonicalValue;
                    $check($platform.' canonical short '.$field.' asks only the next question',
                        array_column(array_slice($sent,$sentBefore),'text'),[MissingFieldQuestionService::question($afterMissing[0])]);
                    $check($platform.' canonical short '.$field.' preserves all independent needs',MaxSearchApi::getSavedData($chat),$expectedSaved);
                    $check($platform.' canonical short '.$field.' writes once without model guessing',
                        [MaxSearchApi::$parameterWrites,AiInvocationService::$calls],[[[$chat,[$field=>$canonicalValue]]],[]]);
                    MaxSearchApi::$needs[$chat]=$saved; MaxSearchApi::$statuses[$chat]=MaxSearchApi::$statusAi;
                    $native($platform,$user,++$mid,$confirmed);
                    $check($platform.' confirmed numeric '.$field.' advances normally',MaxSearchApi::getAiMissingFields($chat),$afterMissing);
                    $expectedSaved=$saved; $expectedSaved[$fieldStatus]=$field==='adults'?(int)$confirmed:$confirmed;
                    $check($platform.' numeric '.$field.' changes only its canonical field',MaxSearchApi::getSavedData($chat),$expectedSaved);
                }
                // A leading Cyrillic letter is still not a digit-adjacent zero.
                MaxSearchApi::$needs[$chat]=$saved; MaxSearchApi::$statuses[$chat]=MaxSearchApi::$statusAi;
                for ($attempt=0;$attempt<2;$attempt++) {
                    $sentBefore=count($sent);MaxSearchApi::$parameterWrites=[];AiInvocationService::$calls=[];
                    $native($platform,$user,++$mid,'о2-24');
                    $check($platform.' unresolved nights still explains numeric confirmation',
                        array_column(array_slice($sent,$sentBefore),'text'),
                        ['Не получилось прочитать диапазон ночей: в нём есть буква. Подтвердите количество ночей цифрами, например «7» или «7-9».']);
                    $check($platform.' unresolved nights preserves exact independent needs',MaxSearchApi::getSavedData($chat),$saved);
                    $check($platform.' unresolved nights never writes or asks model to guess',
                        [MaxSearchApi::$parameterWrites,AiInvocationService::$calls],[[],[]]);
                }

                $tourUser = $chatBase + 710 + $index;
                $tourChat = $platform === 'max' ? -$tourUser : $tourUser;
                $chats[] = $tourChat;
                $enter($platform, $tourUser);
                MaxSearchApi::$needs[$tourChat] = [65=>'4', 66=>'1', 67=>'2', 68=>'1', 69=>'6', 70=>'4', 71=>'7', 72=>'8', 73=>'05.04.'.((int)date('Y')+1)];
                MaxSearchApi::$statuses[$tourChat] = MaxSearchApi::$statusCheck;
                MaxSearchApi::saveLastValue($tourChat, MaxSearchApi::$statusCheck, '1234abcd');
                foreach (['', 'menu'] as $mode) {
                    MaxSearchApi::setEditMode($tourChat, $mode);
                    foreach (['горящие туры', 'Горячие туры!'] as $text) {
                        $saved = MaxSearchApi::getSavedData($tourChat); $sentBefore = count($sent);
                        $writesBefore = count(MaxSearchApi::$writes); $eventsBefore = count(MaxSearchApi::$events);
                        MaxSearchApi::$parameterWrites = []; AiInvocationService::$calls = [];
                        $native($platform, $tourUser, ++$mid, $text);
                        $label = $platform.' tour help edit='.($mode ?: 'closed').' '.$text;
                        $check($label.' points to existing customer actions', array_column(array_slice($sent, $sentBefore), 'text'), [$tourHelp]);
                        $check($label.' preserves needs, status, edit mode and button generation',
                            [MaxSearchApi::getSavedData($tourChat), MaxSearchApi::getCurentStatus($tourChat), MaxSearchApi::getEditMode($tourChat), MaxSearchApi::getLastValue($tourChat, 74)],
                            [$saved, MaxSearchApi::$statusCheck, $mode, '1234abcd']);
                        $check($label.' has no application, progression, search or model effects',
                            [MaxSearchApi::$parameterWrites, array_slice(MaxSearchApi::$writes, $writesBefore), array_slice(MaxSearchApi::$events, $eventsBefore), AiInvocationService::$calls], [[], [], [], []]);
                    }
                    $saved = MaxSearchApi::getSavedData($tourChat); $sentBefore = count($sent);
                    $native($platform, $tourUser, ++$mid, 'Горящие туры в Турцию на 8 ночей');
                    $check($platform.' mixed tour correction is not partly consumed in '.$mode,
                        str_contains($sent[$sentBefore]['text'] ?? '', 'Параметры пока не изменены'), true);
                    $check($platform.' mixed tour correction preserves needs and edit mode in '.$mode,
                        [MaxSearchApi::getSavedData($tourChat), MaxSearchApi::getEditMode($tourChat)], [$saved, $mode]);
                }
            }
            $check('clarification turns never create manager requests', (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='manager_request'")->fetchColumn(), 0);
        } elseif ($departureRetryOnly) {
            require_once $tmp . '/tests/fixtures/channel_post_entry_fixture.php';
            [$pdo, $registry, $publications] = createChannelPostEntryFixture();
            MaxSearchApi::$canonicalStorage = true;
            $year = (int)date('Y') + 1;
            $recovery = 'Не получилось принять этот город для онлайн-подбора. Проверьте название. Если оно верное, напишите «Позвать менеджера» — он поможет проверить вылет из нужного вам города. Можно также указать другой удобный город вылета.';
            $native = static function (string $platform, int $user, int $mid, string $text) use ($maxMessenger, $telegramMessenger): void {
                IntegrationRegistry::useMessenger($platform === 'max' ? $maxMessenger : $telegramMessenger);
                if ($platform === 'max') {
                    (new IncomingUpdateDispatcher())->dispatch(MaxIncomingAdapter::fromUpdate([
                        'update_type'=>'message_created', 'message'=>['sender'=>['user_id'=>$user],
                            'body'=>['mid'=>(string)$mid, 'text'=>$text]],
                    ]));
                } else {
                    TelegramWebhookHandler::dispatchUpdate(['update_id'=>$mid, 'message'=>[
                        'message_id'=>$mid, 'from'=>['id'=>$user], 'chat'=>['id'=>$user, 'type'=>'private'],
                        'text'=>$text,
                    ]], null, $telegramMessenger);
                }
            };
            foreach (['max', 'telegram'] as $index=>$platform) {
                $user = $chatBase + 600 + $index;
                $chat = $platform === 'max' ? -$user : $user;
                $chats[] = $chat;
                // Enter through the existing source and customer identity path;
                // only the preceding accepted trip values are synthetic storage.
                $enter($platform, $user, $publications[$platform]['start_payload']);
                $q = $pdo->prepare('SELECT id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
                $q->execute(['anytour', $platform, (string)$chat]);
                $id = (int)$q->fetchColumn();
                $intent = SourceHotelIntentService::current($id);
                (new IncomingUpdateDispatcher())->dispatch(['platform'=>$platform, 'type'=>'callback',
                    'callback_data'=>'hotel_intent_same:'.($intent['token'] ?? ''), 'callback_id'=>'departure-retry.'.$user,
                    'user'=>['chat_id'=>$chat, 'external_user_id'=>$user]]);
                MaxSearchApi::$needs[$chat] = [65=>null, 66=>'1', 67=>'2', 68=>'1', 69=>'5', 70=>'4', 71=>'7', 72=>'9-10', 73=>'05.04.'.$year];
                MaxSearchApi::$statuses[$chat] = MaxSearchApi::$statusAi;
                $before = MaxSearchApi::getAiSearchContext($chat);
                unset(MaxSearchApi::$needs[$chat][73]);
                AiInvocationService::$answer = ['intent'=>'search', 'parameters'=>[]];
                $sentBefore = count($sent);
                $native($platform, $user, 909900 + $index * 100, '05.04.'.$year);
                $check($platform . ' first city question after a date answer stays ordinary',
                    array_column(array_slice($sent, $sentBefore), 'text'), [MissingFieldQuestionService::question('city')]);
                $check($platform . ' date answer preserves the accepted family and duration', MaxSearchApi::getAiSearchContext($chat), $before);
                TravelDirectoryRepository::$includeMinsk = false;
                AiInvocationService::$answer = ['intent'=>'search', 'parameters'=>['city'=>'Минск']];
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    if ($attempt === 3) {
                        ConversationEntryRegionService::record($id, 'anytour', [
                            'platform'=>$platform, 'entry_attribution'=>['region_id'=>'213', 'source'=>'incoming_attribution'],
                            'message_id'=>'departure-retry-region.'.$user,
                        ], $pdo, time());
                        $check($platform . ' recovery has a real regional suggestion control',
                            array_column(DepartureSuggestionService::forChat($chat, null, $platform)['candidates'] ?? [], 'name'), ['Москва']);
                    }
                    $sentBefore = count($sent);
                    $native($platform, $user, 910000 + $index * 100 + $attempt, 'Минск');
                    $label = $platform . ' unresolved departure attempt ' . $attempt;
                    $check($label . ' exercises the source qualification route', SourceHandlingService::qualifyingTurn($chat), true);
                    $check($label . ' explains recovery instead of repeating the question',
                        array_column(array_slice($sent, $sentBefore), 'text'), [$recovery]);
                    $check($label . ' retains all accepted trip facts', MaxSearchApi::getAiSearchContext($chat), $before);
                    $check($label . ' never substitutes an unsupported city', MaxSearchApi::getAiMissingFields($chat), ['city']);
                }
                $check($platform . ' recovery does not queue a manager automatically',
                    (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id={$id} AND event_type='manager_request'")->fetchColumn(), 0);
                AiInvocationService::$answer = ['intent'=>'question', 'parameters'=>[], 'reply'=>'В публикации указан детский клуб.'];
                $sentBefore = count($sent);
                $native($platform, $user, 910010 + $index * 100, 'Есть ли в отеле детский клуб?');
                $check($platform . ' hotel question keeps its answer while departure is unresolved',
                    array_column(array_slice($sent, $sentBefore), 'text'), ['В публикации указан детский клуб.']);

                // A supported catalogue entry must keep the successful scalar
                // path: same text, no model call, no exception for a city name.
                TravelDirectoryRepository::$includeMinsk = true;
                $callsBefore = count(AiInvocationService::$calls);
                $sentBefore = count($sent);
                $native($platform, $user, 910020 + $index * 100, 'Минск');
                $check($platform . ' active catalogue city is accepted', MaxSearchApi::getAiSearchContext($chat), ['city'=>'Минск'] + $before);
                $check($platform . ' accepted city does not call the model', count(AiInvocationService::$calls), $callsBefore);
                $check($platform . ' accepted last city completes through the existing handoff',
                    (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id={$id} AND event_type='manager_request'")->fetchColumn(), 1);
                $check($platform . ' accepted city does not send recovery guidance',
                    in_array($recovery, array_column(array_slice($sent, $sentBefore), 'text'), true), false);
                TravelDirectoryRepository::$includeMinsk = false;
            }
        } elseif ($jointHandoff) {
            require_once $tmp . '/tests/fixtures/channel_post_entry_fixture.php';
            require_once $tmp . '/services/ManagerHandoffContextService.php';
            require_once $tmp . '/services/ManagerHandoffEventContextService.php';
            [$pdo,$registry,$publications] = createChannelPostEntryFixture();
            MaxSearchApi::$canonicalStorage = true;
            $year = (int)date('Y') + 1;
            $phrase = "Из Самары с 12 декабря {$year} на 10 ночей, 2 взрослых и 1 ребёнок 5 лет";
            $expected = ['city'=>'Самара','adults'=>2,'children'=>1,'child_ages'=>'5','nights'=>'10','date'=>"12.12.{$year}"];
            $answers = [
                'conflict'=>['intent'=>'search','parameters'=>['adults'=>3,'children'=>2,'child_ages'=>[7,9]]],
                'timeout'=>['_error'=>'timeout'],
                'region-conflict'=>['intent'=>'search','parameters'=>[
                    'city'=>'Москва','adults'=>3,'children'=>2,'child_ages'=>[7,9],'nights'=>'7','date'=>"15.12.{$year}"]],
            ];
            $readConversation = static function(string $platform,int $chat) use($pdo): array {
                $q=$pdo->prepare('SELECT * FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
                $q->execute(['anytour',$platform,(string)$chat]); return $q->fetch() ?: [];
            };
            $eventCount = static function(int $id,string $type) use($pdo): int {
                $q=$pdo->prepare('SELECT COUNT(*) FROM conversation_events WHERE conversation_id=? AND event_type=?');
                $q->execute([$id,$type]);return (int)$q->fetchColumn();
            };
            $nativeMessage = static function(string $platform,int $user,string $id,string $text) use($maxMessenger,$telegramMessenger): void {
                IntegrationRegistry::useMessenger($platform==='max'?$maxMessenger:$telegramMessenger);
                if($platform==='max') {
                    (new IncomingUpdateDispatcher())->dispatch(MaxIncomingAdapter::fromUpdate([
                        'update_type'=>'message_created','message'=>['sender'=>['user_id'=>$user,'first_name'=>'Fixture'],
                        'body'=>['mid'=>$id,'text'=>$text]]
                    ]));
                } else {
                    TelegramWebhookHandler::dispatchUpdate(['update_id'=>(int)$id,'message'=>['message_id'=>(int)$id,
                        'from'=>['id'=>$user,'first_name'=>'Fixture'],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]],null,$telegramMessenger);
                }
            };
            $i=0;
            foreach(['max','telegram'] as $platform) foreach($answers as $outcome=>$answer) {
                $user=$chatBase+400+$i++;$chat=$platform==='max'?-$user:$user;$chats[]=$chat;
                $name=$platform . ' DB handoff ' . $outcome;
                MaxSearchApi::$needs[$chat]=[MaxSearchApi::$statusStars=>null,MaxSearchApi::$statusMeal=>null];
                MaxSearchApi::applyAiParameters($chat,['country'=>'Египет']);
                MaxSearchApi::$statuses[$chat]=MaxSearchApi::$statusAi;
                IntegrationRegistry::useMessenger($platform==='max'?$maxMessenger:$telegramMessenger);
                if($platform==='max') {
                    MaxUpdateHandler::handleStarted(['update_type'=>'bot_started','timestamp'=>1791960000000+$i,
                        'payload'=>$publications[$platform]['start_payload'],'user'=>['user_id'=>$user,'name'=>'Fixture Tourist']]);
                } else {
                    TelegramWebhookHandler::dispatchUpdate(['update_id'=>800000+$i,'message'=>['message_id'=>800000+$i,
                        'from'=>['id'=>$user,'first_name'=>'Fixture'],'chat'=>['id'=>$user,'type'=>'private'],
                        'text'=>'/start '.$publications[$platform]['start_payload']]],null,$telegramMessenger);
                }
                $before=$readConversation($platform,$chat);$id=(int)($before['id']??0);
                $check($name . ' publication entry stays in needs collection',$before['status']??null,'ai');
                $check($name . ' publication entry does not queue',$eventCount($id,'manager_request'),0);
                $intent=SourceHotelIntentService::current($id);
                (new IncomingUpdateDispatcher())->dispatch(['platform'=>$platform,'type'=>'callback',
                    'callback_data'=>'hotel_intent_same:'.($intent['token']??''),'callback_id'=>'joint-hotel.'.$user,
                    'user'=>['chat_id'=>$chat,'external_user_id'=>$user]]);
                $check($name . ' hotel consent persists',SourceHotelIntentService::projection($id)['status']??null,'post_hotel');
                $before=$readConversation($platform,$chat);
                $check($name . ' consent alone cannot complete missing needs',$before['status']??null,'ai');
                if ($outcome === 'region-conflict') {
                    $check($name . ' records advertising region',ConversationEntryRegionService::record($id,'anytour',[
                        'platform'=>$platform,'entry_attribution'=>['region_id'=>'213','source'=>'incoming_attribution'],
                        'message_id'=>'joint-region.'.$user,
                    ],$pdo,1794300000),true);
                    $suggestion=DepartureSuggestionService::forChat($chat,null,$platform);
                    $check($name . ' advertising region suggests Moscow',array_column($suggestion['candidates']??[],'name'),['Москва']);
                    $check($name . ' suggestion does not save departure',MaxSearchApi::getAiSearchContext($chat)['city']??null,null);
                }
                AiInvocationService::$answer=$answer;AiInvocationService::$calls=[];
                MaxSearchApi::$parameterWrites=[];
                $sentBefore=count($sent);
                $nativeMessage($platform,$user,(string)(810000+$i),$phrase);
                $after=$readConversation($platform,$chat);
                $context=MaxSearchApi::getAiSearchContext($chat);
                $check($name . ' native message reaches complete collector',count(AiInvocationService::$calls),1);
                $check($name . ' queue state saved in database',$after['status']??null,'waiting_manager');
                $check($name . ' queues exactly once',$eventCount($id,'waiting_manager'),1);
                $check($name . ' records one manager request',$eventCount($id,'manager_request'),1);
                $check($name . ' retains routing source',$after['source_id']??null,$before['source_id']??null);
                foreach($expected as $field=>$value)$check($name . ' saved '.$field,$context[$field]??null,$value);
                $check($name . ' does not lose direction',$context['country']??null,'Египет');
                if ($outcome === 'region-conflict') {
                    $check($name . ' explicit departure suppresses regional suggestion',DepartureSuggestionService::forChat($chat,$context,$platform),[]);
                    $check($name . ' retains advertising region separately',ConversationEntryRegionService::forChat($chat,$platform)['yandex_region_id']??null,'213');
                }
                $q=$pdo->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY id');$q->execute([$id]);$messages=$q->fetchAll();
                $check($name . ' tourist text reaches manager transcript',count(array_filter($messages,
                    static fn($m)=>$m['direction']==='inbound'&&$m['sender_type']==='customer'&&$m['text']===$phrase)),1);
                $handoff=ManagerHandoffEventContextService::latestManagerRequest($id);
                $summary=ManagerHandoffContextService::build($context,$messages,$handoff);
                $check($name . ' manager reads persisted hotel intent',$handoff['hotel_request_intent']['status']??null,'post_hotel');
                foreach(['Маршрут: Самара → Египет',"Вылет: 12.12.{$year}",'Ночей: 10','Туристы: 2 взр. + 1 реб. + возраст: 5'] as $line)
                    $check($name . ' manager summary '.$line,str_contains($summary,$line),true);
                $newMessages=array_slice($sent,$sentBefore);
                $check($name . ' sends one handoff acknowledgement',count($newMessages),1);
                $check($name . ' acknowledges actual handoff',str_contains($newMessages[0]['text']??'','Запрос передан менеджеру'),true);
                $sentBefore=count($sent);$callsBefore=count(AiInvocationService::$calls);
                $nativeMessage($platform,$user,(string)(820000+$i),'Спасибо');
                $check($name . ' subsequent reply is quiet',[count($sent),count(AiInvocationService::$calls)],[$sentBefore,$callsBefore]);
                $check($name . ' subsequent reply creates no duplicate queue',[$eventCount($id,'waiting_manager'),$eventCount($id,'manager_request')],[1,1]);
                echo 'TRACE '.json_encode(['case'=>$name,'conversation'=>$after,'context'=>$context,
                    'handoff'=>$handoff,'summary'=>$summary,'sent'=>$newMessages],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
            }

        } elseif ($jointOnly) {
            require_once $tmp . '/services/ManagerHandoffContextService.php';
            MaxSearchApi::$canonicalStorage = true;
            $year = (int)date('Y') + 1;
            $phrase = "Из Самары с 12 декабря {$year} на 10 ночей, 2 взрослых и 1 ребёнок 5 лет";
            $expected = ['city'=>'Самара','adults'=>2,'children'=>1,'child_ages'=>'5','nights'=>'10','date'=>"12.12.{$year}"];
            $answers = [
                'success'=>['intent'=>'search','parameters'=>[]],
                'conflict'=>['intent'=>'search','parameters'=>[
                    'city'=>'Москва','adults'=>3,'children'=>2,'child_ages'=>[7,9],'nights'=>'7','date'=>"15.12.{$year}"]],
                'timeout'=>['_error'=>true],
            ];
            $caseIndex = 0;
            foreach (['max','telegram'] as $platform) foreach ($answers as $outcome=>$answer) {
                $chat = ($platform === 'max' ? -1 : 1) * ($chatBase + 200 + $caseIndex++);
                AiInvocationService::$answer = $answer;
                $turn = $cityTurn($chat,$platform,$phrase,['country'=>'Египет'],[],MaxSearchApi::$statusAi,false);
                $name = $platform . ' joint ' . $outcome;
                $check($name . ' reaches whole-turn handler', in_array('ai_text',$turn['events'],true), true);
                $check($name . ' short handler performs no partial completion', in_array('ai_short_answer',$turn['events'],true), false);
                $check($name . ' invokes model once', count($turn['model_calls']), 1);
                $check($name . ' uses rich route', $turn['model_calls'][0][0] ?? null, 'RICH_AI');
                $beforeModel = $turn['model_calls'][0][3] ?? [];
                foreach ($expected as $field=>$value) {
                    $check($name . ' pre-model ' . $field, $beforeModel[$field] ?? null, $value);
                    $check($name . ' final ' . $field, $turn['context'][$field] ?? null, $value);
                }
                $check($name . ' retains direction', $turn['context']['country'] ?? null, 'Египет');
                $check($name . ' has no missing needs', $turn['missing'], []);
                $check($name . ' completes once', count(array_filter($turn['events'],static fn($e)=>$e==='search_ready')), 1);
                $messages = implode("\n",array_column($turn['messages'],'text'));
                $check($name . ' shows confirmed summary once', substr_count($messages,'Готово! Проверьте параметры'), 1);
                $check($name . ' never asks parameters again', preg_match('/Уточните, пожалуйста, параметры поездки|Сколько.*(?:взросл|дет|ноч)|возраст.*реб/iu',$messages), 0);
                $manager = ManagerHandoffContextService::build(MaxSearchApi::getAiSearchContext($chat),[]);
                foreach (['Маршрут: Самара → Египет',"Вылет: 12.12.{$year}",'Ночей: 10','Туристы: 2 взр. + 1 реб. + возраст: 5'] as $line) {
                    $check($name . ' manager projection ' . $line, str_contains($manager,$line), true);
                }
                echo 'TRACE ' . json_encode(['case'=>$name,'writes'=>$turn['writes'],'final'=>$turn['context'],
                    'events'=>$turn['events'],'messages'=>$turn['messages'],'manager'=>$manager],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
            }

            foreach (['max','telegram'] as $platform) {
                $chat = ($platform === 'max' ? -1 : 1) * ($chatBase + 220 + $caseIndex++);
                AiInvocationService::$answer = ['_error'=>true];
                $turn = $cityTurn($chat,$platform,$phrase,[],[],MaxSearchApi::$statusAi,false);
                $name = $platform . ' joint unknown destination';
                $check($name . ' does not infer country from departure', $turn['context']['country'] ?? null, null);
                $check($name . ' asks only destination', $turn['missing'], ['country']);
                $check($name . ' cannot complete before destination is chosen', in_array('search_ready',$turn['events'],true), false);
                echo 'TRACE ' . json_encode(['case'=>$name,'writes'=>$turn['writes'],'final'=>$turn['context'],
                    'events'=>$turn['events'],'messages'=>$turn['messages']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
            }

            // Controls for the candidate guards, not another broad regression run.
            $chat = -($chatBase + 250);
            AiInvocationService::$answer = ['intent'=>'search','parameters'=>['adults'=>3,'children'=>2,'child_ages'=>[7,9]]];
            $turn = $cityTurn($chat,'max',"Из Самары с 12 декабря {$year} на 10 ночей, 2 взрослых без детей",
                ['country'=>'Египет'] + $expected,[],MaxSearchApi::$statusAi,false);
            $check('control tourist correction replaces prior party', array_intersect_key($turn['context'],array_flip(['adults','children','child_ages'])),
                ['adults'=>2,'children'=>0]);
            $check('control tourist correction completes once',count(array_filter($turn['events'],static fn($e)=>$e==='search_ready')),1);

            AiInvocationService::$answer = ['intent'=>'correction','parameters'=>['adults'=>3]];
            $turn = $cityTurn($chat - 1,'max',"Из Самары с 12 декабря {$year} на 10 ночей, подберите подходящий тур",
                ['country'=>'Египет'] + $expected,[],MaxSearchApi::$statusAi,false);
            $check('control party not explicit in this turn remains available to model interpretation',$turn['context']['adults'] ?? null,3);

            foreach (['reject','stale'] as $index=>$failure) {
                AiInvocationService::$answer = ['intent'=>'search','parameters'=>['adults'=>2,'children'=>1,'child_ages'=>[5]]];
                MaxSearchApi::$nextPartyFailure = $failure;
                $turn = $cityTurn($chat - 2 - $index,'max',$phrase,['country'=>'Египет'],[],MaxSearchApi::$statusAi,false);
                $check('control ' . $failure . ' local write does not block valid model party',
                    array_intersect_key($turn['context'],array_flip(['adults','children','child_ages'])),
                    ['adults'=>2,'children'=>1,'child_ages'=>'5']);
                $check('control ' . $failure . ' completes after model save',count(array_filter($turn['events'],static fn($e)=>$e==='search_ready')),1);
            }

            AiInvocationService::$answer = ['intent'=>'search','parameters'=>[]];
            $turn = $cityTurn($chat - 4,'max',str_replace('Из Самары','Из Самары в Египет',$phrase),[],[],MaxSearchApi::$statusAi,false);
            $check('control explicit destination remains readable',$turn['context']['country'] ?? null,'Египет');
            $check('control explicit destination keeps departure',$turn['context']['city'] ?? null,'Самара');
            $check('control explicit destination completes once',count(array_filter($turn['events'],static fn($e)=>$e==='search_ready')),1);

            AiInvocationService::$answer = ['_error'=>true];
            $turn = $cityTurn($chat - 7,'max',$phrase,
                array_diff_key(['country'=>'Египет'] + $expected,['city'=>true]),[],MaxSearchApi::$statusAi,false);
            $check('control timeout completes the last missing departure resolved by controller',
                count(array_filter($turn['events'],static fn($e)=>$e==='search_ready')),1);
            $check('control last departure completion retains all trip facts',array_intersect_key($turn['context'],$expected),$expected);

            foreach (['max','telegram'] as $index=>$platform) {
                AiInvocationService::$answer = ['_error'=>true];
                $turn = $cityTurn(($platform === 'max' ? -1 : 1) * (abs($chat) + 5 + $index),$platform,
                    'Есть ли в отеле детский клуб для ребёнка 5 лет?',
                    ['country'=>'Египет'] + $expected,[],MaxSearchApi::$statusAi,false);
                $name = $platform . ' FAQ timeout with recognized child age';
                $check($name . ' reaches model',count($turn['model_calls']),1);
                $check($name . ' exercises redundant local age write',count(array_filter($turn['writes'],
                    static fn($write)=>array_key_exists('child_ages',$write[1]??[]))) > 0,true);
                $check($name . ' does not complete a previously saved trip',in_array('search_ready',$turn['events'],true),false);
                $check($name . ' preserves saved trip',array_intersect_key($turn['context'],$expected),$expected);
                $check($name . ' does not ask trip parameters again',preg_match('/Уточните, пожалуйста, параметры поездки|Сколько.*(?:взросл|дет|ноч)|Сколько лет детям/iu',
                    implode("\n",array_column($turn['messages'],'text'))),0);
                echo 'TRACE '.json_encode(['case'=>$name,'context'=>$turn['context'],'events'=>$turn['events'],
                    'messages'=>$turn['messages'],'writes'=>$turn['writes']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
            }

        } else {
        foreach ([
            ['max', 'Казань', 'Казань', MaxSearchApi::$statusAi],
            ['telegram', 'Ростов-на-Дону', 'Ростов-на-Дону', MaxSearchApi::$statusStart],
            ['max', 'Мин. Воды', 'Минеральные Воды', MaxSearchApi::$statusAi],
        ] as $index=>[$platform, $text, $city, $status]) {
            $chat = $platform === 'max' ? -($chatBase + 100 + $index) : $chatBase + 100 + $index;
            $result = $cityTurn($chat, $platform, $text, ['country'=>'Турция'], ['city', 'date'], $status);
            $label = $platform . ' current city answer ' . $text;
            $check($label . ' is handled without AI', [$result['handled'], $result['ai_text']], [true, null]);
            $check($label . ' saves only the canonical departure', $result['writes'], [[$chat, ['city'=>$city]]]);
            $check($label . ' preserves the destination', $result['context'], ['country'=>'Турция', 'city'=>$city]);
            $check($label . ' bypasses destination inference', $result['destination_queries'], 0);
            $check($label . ' asks the next missing field once', array_column($result['messages'], 'text'),
                [MissingFieldQuestionService::question('date')]);
        }
        foreach ([
            ['unknown city', 'Неизвестноград', [], ['city', 'date']],
            ['ambiguous abbreviation', 'Сан. Город', [], ['city', 'date']],
            ['city question', 'Казань?', [], ['city', 'date']],
            ['mixed trip text', 'Казань в Египет', [], ['city', 'date']],
            ['known departure', 'Казань', ['city'=>'Москва'], ['country', 'date']],
            ['city is not the current question', 'Казань', [], ['date', 'city']],
        ] as $index=>[$label, $text, $context, $missing]) {
            $chat = -($chatBase + 110 + $index);
            $result = $cityTurn($chat, 'max', $text, $context, $missing, MaxSearchApi::$statusAi);
            $check($label . ' retains the ordinary AI route', $result['ai_text'], $text);
            $check($label . ' does not save a bare departure', $result['writes'], []);
            $check($label . ' does not advance the field question', $result['messages'], []);
        }
        $chat = -($chatBase + 120);
        $text = 'из Казани в Египет';
        $result = $cityTurn($chat, 'max', $text, [], ['city', 'date'], MaxSearchApi::$statusAi);
        $check('explicit rich departure still saves its city', $result['writes'], [[$chat, ['city'=>'Казань']]]);
        $check('explicit rich departure continues destination inference', $result['destination_queries'] > 0, true);
        $check('explicit rich departure reaches AI unchanged', $result['ai_text'], $text);
        $check('explicit rich departure is not consumed as a field answer', $result['messages'], []);

        // Exercise the complete local collector with the real date/night
        // parsers, application and progression, on both messenger paths.
        $tripYear = (int)date('Y') + 1;
        $compoundText = 'Самара с 12 декабря ' . $tripYear . ' на 10 ночей';
        foreach (['max', 'telegram'] as $index=>$platform) {
            $chat = $platform === 'max' ? -($chatBase + 140 + $index) : $chatBase + 140 + $index;
            $result = $cityTurn($chat, $platform, $compoundText, ['country'=>'Египет'],
                ['city', 'adults', 'children', 'nights', 'date'], MaxSearchApi::$statusAi, false);
            $check($platform . ' compound answer retains all explicit trip facts', $result['context'],
                ['country'=>'Египет', 'city'=>'Самара', 'nights'=>'10', 'date'=>'12.12.' . $tripYear, 'meal'=>'all_inclusive', 'stars'=>4]);
            $check($platform . ' compound answer asks only the next missing fact', array_column($result['messages'], 'text'),
                [MissingFieldQuestionService::question('adults')]);
            $check($platform . ' compound answer is handled without model inference', [$result['handled'], $result['model_calls']], [true, []]);
            $check($platform . ' departure name never reaches destination lookup',
                strpos(json_encode($result['destination_filters'], JSON_UNESCAPED_UNICODE), 'Самар') === false
                    && strpos(json_encode($result['destination_filters'], JSON_UNESCAPED_UNICODE), 'самар') === false, true);
        }
        $result = $cityTurn(-($chatBase + 145), 'max', $compoundText, [],
            ['city', 'country', 'adults', 'children', 'nights', 'date'], MaxSearchApi::$statusAi, false);
        $check('compound departure never invents a holiday country', $result['context'],
            ['city'=>'Самара', 'nights'=>'10', 'date'=>'12.12.' . $tripYear]);
        $check('unknown destination is clarified through the existing question', array_column($result['messages'], 'text'),
            [MissingFieldQuestionService::question('country')]);
        $check('unknown destination does not invoke inference on the departure name', $result['model_calls'], []);
        $result = $cityTurn(-($chatBase + 146), 'max', $compoundText, ['country'=>'Египет'],
            ['city', 'adults', 'children', 'nights', 'date'], MaxSearchApi::$statusCityChoose, false);
        $check('wizard compound departure reaches the same complete local collector', $result['context'],
            ['country'=>'Египет', 'city'=>'Самара', 'nights'=>'10', 'date'=>'12.12.' . $tripYear, 'meal'=>'all_inclusive', 'stars'=>4]);
        $check('wizard compound departure asks the next missing field once', array_column($result['messages'], 'text'),
            [MissingFieldQuestionService::question('adults')]);
        $check('wizard compound departure performs no model call', $result['model_calls'], []);
        $result = $cityTurn(-($chatBase + 147), 'max', $compoundText,
            ['country'=>'Египет', 'adults'=>2, 'children'=>2],
            ['city', 'child_ages', 'nights', 'date'], MaxSearchApi::$statusAi, false);
        $check('date and duration numbers never become missing child ages',
            array_key_exists('child_ages', $result['context']), false);
        $check('known children retain the same compound trip facts', $result['context'],
            ['country'=>'Египет', 'adults'=>2, 'children'=>2, 'city'=>'Самара',
                'nights'=>'10', 'date'=>'12.12.' . $tripYear, 'meal'=>'all_inclusive', 'stars'=>4]);
        $check('only the actual child ages remain to be clarified',
            [$result['missing'], count($result['messages']), $result['model_calls']], [['child_ages'], 1, []]);
        foreach (['max', 'telegram'] as $index=>$platform) {
            $chat = $platform === 'max' ? -($chatBase + 150 + $index) : $chatBase + 150 + $index;
            $text = 'Самара 2 взрослых и 1 ребёнок 5 лет с 12 декабря ' . $tripYear . ' на 10 ночей';
            $result = $cityTurn($chat, $platform, $text, ['country'=>'Таиланд'],
                ['city', 'adults', 'children', 'child_ages', 'nights', 'date', 'stars'], MaxSearchApi::$statusAi, false);
            $check($platform . ' compound party bypasses scalar-answer consumption', $result['context'],
                ['country'=>'Таиланд', 'city'=>'Самара', 'adults'=>2, 'children'=>1,
                    'child_ages'=>[5], 'nights'=>'10', 'date'=>'12.12.' . $tripYear]);
            $check($platform . ' compound party advances once after all facts', array_column($result['messages'], 'text'),
                [MissingFieldQuestionService::question('stars')]);
            $check($platform . ' compound party stays local despite rich classification', $result['model_calls'], []);
        }

        foreach (['rejectParameters', 'staleParameterReadback'] as $index=>$failure) {
            MaxSearchApi::${$failure} = true;
            try {
                $result = $cityTurn(-($chatBase + 130 + $index), 'max', 'Казань', [], ['city', 'date'], MaxSearchApi::$statusAi);
            } finally { MaxSearchApi::${$failure} = false; }
            $check($failure . ' does not report a confirmed departure', in_array('departure_city_resolved', $result['events'], true), false);
            $check($failure . ' does not advance the field question', $result['messages'], []);
            $check($failure . ' retains the ordinary AI route', $result['ai_text'], 'Казань');
        }
        $check('bare departure remains disabled for existing callers', DepartureCityResolver::resolveAndStore($chat, 'Казань'), false);

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
        }
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL  start parity fixture: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    } finally {
        if (class_exists('EditFlowService')) foreach ($chats as $chat) EditFlowService::clearSnapshot($chat);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($tmp);
    }
    echo ($dateCityOnly ? "DATE AND CITY CLARIFICATIONS" : ($redundantOverlapOnly ? "REDUNDANT FACT OVERLAP" : ($overlapOnly ? "OVERLAPPING TURN" : ($clarificationOnly ? "USEFUL CLARIFICATIONS" : ($departureRetryOnly ? "DEPARTURE RETRY" : ($jointHandoff ? "COMPOUND HANDOFF" : ($jointOnly ? "COMPOUND JOINT" : "DIALOGUE START PARITY"))))))) . ": PASS {$passed} FAIL {$failed}\n";
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

$fixtureOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --compound-joint-only 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('joint compound MAX/Telegram fixture', $fixtureStatus, 0);

$fixtureOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --compound-handoff-only 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('joint compound DB handoff fixture', $fixtureStatus, 0);

$fixtureOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --departure-retry-only 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('unresolved departure recovery MAX/Telegram fixture', $fixtureStatus, 0);

$fixtureOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --useful-clarifications-only 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('useful clarification MAX/Telegram fixture', $fixtureStatus, 0);

$fixtureOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --overlapping-turn-only 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('overlapping native input fixture', $fixtureStatus, 0);

$fixtureOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --redundant-fact-overlap-only 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('redundant fact overlapping native input fixture', $fixtureStatus, 0);

$fixtureOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --date-city-clarifications-only 2>&1', $fixtureOutput, $fixtureStatus);
foreach ($fixtureOutput as $line) echo $line . PHP_EOL;
dcCheck('sequential complete date and departure alternatives native fixture', $fixtureStatus, 0);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
