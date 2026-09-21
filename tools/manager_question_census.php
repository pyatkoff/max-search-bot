<?php

declare(strict_types=1);

/** One-shot #780 triage. Free text stays in this process; output is counts only. */
final class ManagerQuestionCensus
{
    public const EXPECTED_SHA = 'c1235a1f99519f45033edb521d117233a4207fb4';
    public const SINCE = '2026-09-06 22:00:00';
    public const UNTIL = '2026-09-20 22:00:00';
    public const CONVERSATION_LIMIT = 300;
    public const MESSAGE_LIMIT = 1000;
    public const TEXT_LIMIT_BYTES = 16000;

    private const TOPICS = [
        'budget' => '/бюджет|сумм[ауые]|стоимост|ценов.{0,12}диапазон|рассчитыва|сколько.{0,20}потрат/iu',
        'departure' => '/из какого города|откуда.{0,25}(?:вылет|лет|отправ)|город.{0,20}(?:вылет|отправ)|вылет.{0,20}город/iu',
        'destination' => '/стран|направлен|курорт|куда.{0,20}(?:хот|план|лет|поех)|район отдыха/iu',
        'dates' => '/дат[ауы]|когда|числ[аоу]|период|месяц|возвращ|вернуть/iu',
        'nights' => '/ноч[ьи]|ночей|дней|длительност|продолжительност/iu',
        'party' => '/взросл|состав|сколько.{0,25}(?:турист|человек|дет|реб)/iu',
        'child_ages' => '/возраст.{0,15}(?:дет|реб)|(?:дет|реб).{0,25}(?:лет|возраст)|сколько лет/iu',
        'hotel_preferences' => '/отел|гостиниц|зв[её]зд|пляж|лини[яию]|территори|анимац|аквапарк/iu',
        'meal' => '/питани|завтрак|вс[её] включ|полупансион|ужин/iu',
        'flight_preferences' => '/пересад|прям.{0,10}рейс|перел[её]т|аэропорт/iu',
        'booking' => '/брониров|оформлен|оплат|покуп/iu',
        'contact' => '/телефон|номер.{0,15}(?:связ|контакт)|как.{0,10}зовут|ваше имя|мессенджер|whatsapp|ватсап/iu',
    ];

    public static function topics(string $text): array
    {
        $found = [];
        foreach (self::TOPICS as $key => $pattern) if (preg_match($pattern, $text) === 1) $found[$key] = true;
        return $found;
    }

    /** Conservative lexical signals, not an LLM judgment or confirmed missing need. */
    public static function questions(string $text): array
    {
        $text = preg_replace('~https?://[^\\s<>]+~iu', ' ', $text) ?? '';
        $found = []; $isQuestion = false;
        foreach (preg_split('/(?<=[?!.])\\s+|\\r?\\n/u', $text) ?: [] as $part) {
            if (!str_contains($part, '?') && preg_match('/(?:^|[,:;]\\s*)(?:подскажите|уточните|скажите|напишите|сообщите|назовите)\\b/iu', trim($part)) !== 1) continue;
            $isQuestion = true;
            $found += self::topics($part);
        }
        return ['question'=>$isQuestion, 'topics'=>$found];
    }

    public static function blank(): array
    {
        $topics = [];
        foreach (self::TOPICS as $topic => $_) $topics[$topic] = [
            'question_conversations'=>0, 'early_question_conversations'=>0,
            'early_with_prior_customer_topic_mention'=>0, 'early_with_prior_bot_topic_mention'=>0,
        ];
        return ['conversations'=>0, 'manager_messages'=>0, 'customer_messages'=>0, 'bot_messages'=>0,
            'question_messages'=>0, 'unclassified_question_messages'=>0,
            'conversations_with_bot_before_manager'=>0, 'conversations_with_question'=>0,
            'conversations_with_customer_message_after_manager'=>0, 'topics'=>$topics];
    }

    /** Input must be a COMPLETE chronological conversation up to UNTIL. */
    public static function add(array $aggregate, array $messages): array
    {
        $managerOrdinal = 0; $priorCustomer = []; $priorBot = [];
        $asked = []; $early = []; $hasQuestion = false; $hasBotBefore = false; $customerAfter = false;
        $lastId = 0;
        foreach ($messages as $row) {
            if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] <= $lastId
                || !is_string($row['text'] ?? null) || strlen($row['text']) > self::TEXT_LIMIT_BYTES) {
                throw new RuntimeException('invalid_or_incomplete_history');
            }
            $lastId = $row['id'];
            $type = (string)($row['event_type'] ?? '');
            if (in_array($type, ['callback','start','bot_started'], true)) continue;
            $sender = (string)($row['sender_type'] ?? '');
            $direction = (string)($row['direction'] ?? '');
            $text = $row['text'];
            if ($direction === 'inbound' && $sender === 'customer') {
                $aggregate['customer_messages']++;
                if ($managerOrdinal === 0) $priorCustomer += self::topics($text);
                else $customerAfter = true;
            } elseif ($direction === 'outbound' && $sender === 'ai') {
                $aggregate['bot_messages']++;
                if ($managerOrdinal === 0) { $hasBotBefore = true; $priorBot += self::topics($text); }
            } elseif ($direction === 'outbound' && $sender === 'manager') {
                $managerOrdinal++; $aggregate['manager_messages']++;
                $q = self::questions($text);
                if ($q['question']) {
                    $hasQuestion = true; $aggregate['question_messages']++;
                    if ($q['topics'] === []) $aggregate['unclassified_question_messages']++;
                }
                $asked += $q['topics'];
                if ($managerOrdinal <= 3) $early += $q['topics'];
            }
        }
        if ($managerOrdinal === 0) throw new RuntimeException('manager_cohort_mismatch');
        $aggregate['conversations']++;
        $aggregate['conversations_with_bot_before_manager'] += (int)$hasBotBefore;
        $aggregate['conversations_with_question'] += (int)$hasQuestion;
        $aggregate['conversations_with_customer_message_after_manager'] += (int)$customerAfter;
        foreach ($asked as $key => $_) $aggregate['topics'][$key]['question_conversations']++;
        foreach ($early as $key => $_) {
            $aggregate['topics'][$key]['early_question_conversations']++;
            $aggregate['topics'][$key]['early_with_prior_customer_topic_mention'] += (int)isset($priorCustomer[$key]);
            $aggregate['topics'][$key]['early_with_prior_bot_topic_mention'] += (int)isset($priorBot[$key]);
        }
        return $aggregate;
    }

    public static function conversationSql(): string
    {
        return "SELECT c.id,c.channel FROM conversations c WHERE c.project_key=? AND COALESCE(c.is_test,0)=0"
            . " AND c.channel IN ('max','telegram','website') AND c.started_at>=? AND c.started_at<?"
            . " AND EXISTS(SELECT 1 FROM messages m WHERE m.conversation_id=c.id AND m.direction='outbound'"
            . " AND m.sender_type='manager' AND m.created_at<?) ORDER BY c.id ASC LIMIT 301";
    }

    public static function collect(): void
    {
        if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
        ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('memory_limit', '128M');
        set_time_limit(90); ob_start(); $stage = 'bootstrap'; $pdo = null;
        set_error_handler(static function (): bool { throw new RuntimeException('census_runtime_error'); });
        try {
            $root = '/var/www/anytoour/data/www/app.anytoour.ru';
            if (getcwd() !== $root || trim((string)shell_exec('git rev-parse HEAD 2>/dev/null')) !== self::EXPECTED_SHA) throw new RuntimeException();
            // Same minimal config/connection path as the canonical live snapshot.
            // Deliberately no RuntimeBootstrap or ProjectAccessService initializer.
            require_once $root.'/config.php';
            require_once $root.'/services/ConversationDb.php';
            require_once $root.'/services/ProjectConfig.php';
            $project = ProjectConfig::projectId();
            if ($project === '' || $project === 'default') throw new RuntimeException();
            $pdo = ConversationDb::connection();
            $stage = 'read_only_transaction';
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            try { $readOnly = $pdo->query('SELECT @@transaction_read_only')->fetchColumn(); }
            catch (PDOException $e) { $readOnly = $pdo->query('SELECT @@tx_read_only')->fetchColumn(); }
            if ((string)$readOnly !== '1') throw new RuntimeException();
            $pdo->beginTransaction();
            $stage = 'select_cohort';
            $q = $pdo->prepare(self::conversationSql());
            $q->execute([$project, self::SINCE, self::UNTIL, self::UNTIL]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $capReached = count($rows) > self::CONVERSATION_LIMIT;
            $rows = array_slice($rows, 0, self::CONVERSATION_LIMIT);
            $channels = ['max'=>self::blank(), 'telegram'=>self::blank(), 'website'=>self::blank()];
            $excluded = ['message_cap'=>0, 'oversized_or_malformed'=>0];
            $mq = $pdo->prepare("SELECT id,direction,sender_type,CASE WHEN OCTET_LENGTH(text)<=16000 THEN COALESCE(text,'') ELSE NULL END AS text,COALESCE(OCTET_LENGTH(text),0) AS text_bytes,CASE WHEN JSON_VALID(metadata_json) THEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.type')) ELSE NULL END AS event_type FROM messages WHERE conversation_id=? AND created_at<? ORDER BY id ASC LIMIT 1001");
            $stage = 'analyze_in_memory';
            foreach ($rows as $row) {
                if (!isset($channels[$row['channel']])) throw new RuntimeException();
                $mq->execute([(int)$row['id'], self::UNTIL]);
                $messages = $mq->fetchAll(PDO::FETCH_ASSOC);
                if (count($messages) > self::MESSAGE_LIMIT) { $excluded['message_cap']++; continue; }
                if (array_filter($messages, static fn(array $m): bool => (int)$m['text_bytes'] > self::TEXT_LIMIT_BYTES)) { $excluded['oversized_or_malformed']++; continue; }
                foreach ($messages as &$m) { $m['id'] = (int)$m['id']; $m['text'] = (string)($m['text'] ?? ''); }
                unset($m);
                try { $channels[$row['channel']] = self::add($channels[$row['channel']], $messages); }
                catch (RuntimeException $e) { $excluded['oversized_or_malformed']++; }
                unset($messages);
            }
            $pdo->rollBack();
            $stage = 'verify_source_after_read';
            if (trim((string)shell_exec('git rev-parse HEAD 2>/dev/null')) !== self::EXPECTED_SHA) throw new RuntimeException();
            $result = ['ok'=>true, 'schema_version'=>1, 'generated_at'=>gmdate('c'), 'production_sha'=>self::EXPECTED_SHA,
                'window'=>['timezone'=>'Europe/Kaliningrad','local_from'=>'2026-09-07','local_until_exclusive'=>'2026-09-21',
                    'since_utc'=>self::SINCE,'until_utc_exclusive'=>self::UNTIL],
                'basis'=>'new_non_test_conversations_with_recorded_manager_message_before_cutoff',
                'method'=>'fixed_lexical_signals_v1_not_semantic_review', 'early_basis'=>'first_three_recorded_manager_messages',
                'prior_mentions_are_not_confirmed_answers'=>true, 'read_only'=>true, 'transcripts_exported'=>false,
                'conversation_limit'=>self::CONVERSATION_LIMIT, 'message_limit'=>self::MESSAGE_LIMIT,
                'selected_conversations'=>count($rows), 'conversation_cap_reached'=>$capReached,
                'excluded'=>$excluded, 'channels'=>$channels];
            // Rebuild output only from fixed keys and aggregate integers. No text/IDs.
            ob_end_clean(); restore_error_handler();
            echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $ignored) {} }
            ob_end_clean(); restore_error_handler();
            echo json_encode(['ok'=>false,'stage'=>$stage,'transcripts_exported'=>false])."\n";
            exit(1);
        }
    }
}

if (getenv('MANAGER_QUESTION_CENSUS_COLLECT') === '1') ManagerQuestionCensus::collect();
