<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectMaxConnection.php';
require_once __DIR__.'/ProjectMaxMessengerFactory.php';
require_once __DIR__.'/ConversationRecorder.php';
require_once __DIR__.'/ConversationControlService.php';
require_once __DIR__.'/ChannelPostRegistryService.php';
require_once __DIR__.'/MaxInboundMediaArchiveService.php';
require_once __DIR__.'/../integrations/MaxIncomingAdapter.php';

/** Registered repair bot ingress into the existing conversation/manager owners. */
final class RepairMaxWebhookService
{
    /** Injected transports are for isolated regressions; no alternate state store. */
    public static function handle(string $projectSelector, string $secret, array $update, ?callable $messengerFactory = null, ?callable $notify = null): array
    {
        $connection = ProjectMaxConnection::authenticate($projectSelector, $secret);
        if ($connection === null) return self::response(403, 'forbidden');
        if ($connection->settings()->get('business.kind') !== 'device_repair') return self::response(403, 'forbidden');

        $type = (string)($update['update_type'] ?? '');
        if (!in_array($type, ['bot_started','message_created'], true)) return self::response(200, 'ignored');
        $user = MaxIncomingAdapter::user($update);
        $chatType = (string)($update['message']['recipient']['chat_type'] ?? $update['chat_type'] ?? $update['chat']['type'] ?? '');
        if (!empty($user['is_bot']) || ($chatType !== '' && $chatType !== 'dialog') || ($type === 'message_created' && $chatType !== 'dialog')) return self::response(200, 'ignored');
        $externalUser = (string)($user['user_id'] ?? $user['id'] ?? '');
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/D', $externalUser) || (string)(int)$externalUser !== $externalUser) return self::response(400, 'invalid_update');
        $payload = $update['payload'] ?? '';
        if (!is_string($payload) || strlen($payload) > 2048) return self::response(400, 'invalid_update');
        $messageId = (string)($update['message']['body']['mid'] ?? '');
        $timestamp = (string)($update['timestamp'] ?? '');
        if (($type === 'message_created' && ($messageId === '' || strlen($messageId) > 191))
            || ($type === 'bot_started' && !preg_match('/\A[0-9]{1,20}\z/D', $timestamp))) return self::response(400, 'invalid_update');

        $projectKey = $connection->projectKey();
        $botKey = $connection->botKey();
        $chatId = -(int)$externalUser;
        $deliveryKey = hash('sha256', implode("\0", [$projectKey,$botKey,$externalUser,$type,$type === 'message_created' ? $messageId : $timestamp."\0".$payload]));
        $pdo = null;
        $locked = false;
        $lockKey = 'repair-max:'.substr(hash('sha256', $projectKey."\0".$botKey."\0".$externalUser), 0, 48);
        try {
            if (!ConversationDb::isConfigured()) return self::response(503, 'storage_unavailable');
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return self::response(503, 'storage_busy');
            // Same production advisory-lock mechanism as existing MAX starts.
            // SQLite fixtures are single process and have no MySQL GET_LOCK.
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $q = $pdo->prepare('SELECT GET_LOCK(?,3)');
                $q->execute([$lockKey]);
                $locked = (string)$q->fetchColumn() === '1';
                if (!$locked) return self::response(503, 'storage_busy');
            }
            $recordedConversation = self::recorded($pdo, $projectKey, (string)$chatId, $type, $messageId, $deliveryKey);
            if ($recordedConversation > 0) {
                // Recover only a persisted greeting that never attempted a provider write.
                // An attempted/unknown delivery is deliberately never auto-repeated.
                if (!self::deliverGreeting($pdo, $connection, $recordedConversation, $chatId, $deliveryKey, $messengerFactory)) return self::response(503, 'reply_not_persisted');
                return self::response(200, 'duplicate');
            }

            $registry = new ChannelPostRegistryService($pdo);
            $resolved = $type === 'bot_started' && str_starts_with($payload, 'p_')
                ? $registry->resolveToken($projectKey, 'max', $botKey, $payload) : null;
            $sourceKey = $resolved !== null ? (string)$resolved['source_key']
                : (string)$connection->settings()->get('routing.source_key', 'max:'.$projectKey.'-main');
            $q = $pdo->prepare("SELECT s.id,s.handling_mode FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND p.is_active=1 AND s.source_key=? AND s.channel='max' AND s.is_active=1");
            $q->execute([$projectKey,$sourceKey]);
            $source = $q->fetch(PDO::FETCH_ASSOC);
            if (!$source || !in_array((string)$source['handling_mode'], ['manager','ask'], true)) return self::response(503, 'source_unavailable');

            $context = $projectKey === 'appleservice39' && $type === 'bot_started' && $payload === 'as39_x_battery'
                ? ['model'=>'iPhone X','service'=>'Замена аккумулятора','entry_code'=>'as39_x_battery'] : [];
            if ($type === 'bot_started') {
                $name = preg_split('/\s+/', trim((string)($user['name'] ?? '')), 2) ?: [];
                $incoming = ['platform'=>'max','type'=>'bot_started','user'=>[
                    'external_user_id'=>$externalUser,'chat_id'=>$chatId,
                    'first_name'=>(string)($user['first_name'] ?? $name[0] ?? ''),
                    'last_name'=>(string)($user['last_name'] ?? $name[1] ?? ''),
                    'username'=>(string)($user['username'] ?? '')], 'repair_context'=>$context];
            } else {
                $incoming = MaxIncomingAdapter::fromUpdate($update);
                if ($incoming === null) return self::response(400, 'invalid_update');
            }
            $incoming['source_key'] = $sourceKey;
            $incoming['bot_key'] = $botKey;
            $incoming['delivery_key'] = $deliveryKey;

            $pdo->beginTransaction();
            $conversationId = null;
            $duplicate = false;
            if (!ConversationRecorder::inboundForProject($projectKey, $incoming, $conversationId, $duplicate) || !$conversationId) throw new RuntimeException('inbound_not_persisted');
            $q = $pdo->prepare('SELECT metadata_json,status,manager_id FROM conversations WHERE id=? AND project_key=? AND channel=?');
            $q->execute([$conversationId,$projectKey,'max']);
            $conversation = $q->fetch(PDO::FETCH_ASSOC);
            if (!$conversation) throw new RuntimeException('conversation_scope_mismatch');
            $metadata = json_decode((string)$conversation['metadata_json'], true);
            $metadata = is_array($metadata) ? $metadata : [];
            if (isset($metadata['max_bot_key']) && $metadata['max_bot_key'] !== $botKey) throw new RuntimeException('conversation_bot_mismatch');
            $metadata['max_bot_key'] = $botKey;
            // A generic/reopened start cannot erase the customer's known device.
            // Only another recognized repair entry replaces the reviewed context.
            if ($context) $metadata['repair_context'] = $context;
            $q = $pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=? AND project_key=?');
            $q->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$conversationId,$projectKey]);
            if ($type === 'bot_started') {
                $q = $pdo->prepare('UPDATE conversations SET entry_channel=? WHERE id=? AND project_key=?');
                $q->execute([$sourceKey,$conversationId,$projectKey]);
                // Refresh a source only while unowned; assigned manager routing is stable.
                $q = $pdo->prepare("UPDATE conversations SET source_id=? WHERE id=? AND project_key=? AND status='ai' AND manager_id IS NULL");
                $q->execute([(int)$source['id'],$conversationId,$projectKey]);
                if ($resolved !== null && $registry->recordEntry($conversationId, $resolved, $deliveryKey, true) === null) throw new RuntimeException('entry_not_persisted');
                if ($resolved === null && str_starts_with($payload, 'p_') && $registry->recordUnavailableEntry($conversationId, $projectKey, 'max', $deliveryKey, true) === null) throw new RuntimeException('entry_not_persisted');
            }
            $created = false;
            $handoff = ['source'=>'repair_max','source_key'=>$sourceKey,'repair_greeting'=>[
                'delivery_key'=>$deliveryKey,'bot_key'=>$botKey,'state'=>'pending',
                'text'=>self::greeting($connection, $type, $context)]];
            if (!ConversationControlService::markWaitingForProject($projectKey, 'max', $chatId, $handoff, $created, false)) throw new RuntimeException('handoff_not_persisted');
            $pdo->commit();

            // Existing manager notification owner runs only after the shared history commits.
            if ($created || $type === 'message_created') {
                try {
                    $notify = $notify ?? static fn(int $id, string $body, string $mid) => ManagerPushService::notifyConversation($id, $body, $mid);
                    $notify($conversationId, $created ? 'Новая заявка ждёт менеджера' : 'Клиент написал в диалог', $messageId);
                } catch (Throwable $ignored) {}
            }
            if ($created && !$duplicate) {
                if (!self::deliverGreeting($pdo, $connection, $conversationId, $chatId, $deliveryKey, $messengerFactory)) return self::response(503, 'reply_not_persisted');
            }
            $result = self::response(200, 'recorded');
            if ($type === 'message_created' && !empty($incoming['attachments'])) {
                // Internal descriptor only. The entrypoint strips it from HTTP output.
                // Archival starts after response completion and advisory-lock release.
                $result['archive'] = ['message_id'=>$messageId,'project_key'=>$projectKey,'bot_key'=>$botKey,'conversation_id'=>$conversationId];
            }
            return $result;
        } catch (Throwable $ignored) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            // Never write the update, credentials, provider URLs or customer text to logs.
            return self::response(503, 'storage_unavailable');
        } finally {
            if ($locked && $pdo instanceof PDO) {
                try { $q = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockKey]); } catch (Throwable $ignored) {}
            }
        }
    }

    /** Existing best-effort archive, called only in the post-response request tail. */
    public static function archiveCommitted(array $result, ?callable $archive = null): void
    {
        if (($result['status'] ?? 0) !== 200 || ($result['result'] ?? '') !== 'recorded' || !is_array($result['archive'] ?? null)) return;
        $saved = $result['archive'];
        $connection = ProjectMaxConnection::resolve((string)($saved['project_key'] ?? ''));
        if (!$connection || $connection->botKey() !== ($saved['bot_key'] ?? '') || (int)($saved['conversation_id'] ?? 0) <= 0) return;
        try {
            $archive = $archive ?? static function (string $mid, string $project, int $id) use ($connection): int {
                return MaxInboundMediaArchiveService::archiveRecordedMessage($mid,
                    static fn(string $message): ?array => MaxInboundMediaDownloadAdapter::fetchMessage($message, $connection->token(), $connection->baseUrl()),
                    null,
                    static fn(string $token): ?array => MaxInboundMediaDownloadAdapter::fetchVideo($token, $connection->token(), $connection->baseUrl()),
                    $project, $id);
            };
            $archive((string)($saved['message_id'] ?? ''),$connection->projectKey(),(int)$saved['conversation_id']);
        } catch (Throwable $ignored) {}
    }

    private static function recorded(PDO $pdo, string $project, string $chat, string $type, string $messageId, string $deliveryKey): int
    {
        $needle = '%"delivery_key":"'.$deliveryKey.'"%';
        if ($type === 'message_created') {
            $q = $pdo->prepare("SELECT c.id FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.project_key=? AND c.channel='max' AND c.external_chat_id=? AND m.direction='inbound' AND m.external_message_id=? AND m.metadata_json LIKE ? LIMIT 1");
            $q->execute([$project,$chat,$messageId,$needle]);
        } else {
            $q = $pdo->prepare("SELECT c.id FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE c.project_key=? AND c.channel='max' AND c.external_chat_id=? AND e.event_type='bot_started' AND e.payload_json LIKE ? LIMIT 1");
            $q->execute([$project,$chat,$needle]);
        }
        return (int)$q->fetchColumn();
    }

    private static function greeting(ProjectMaxConnection $connection, string $type, array $context): string
    {
        $brand = trim((string)$connection->settings()->get('brand.name', $connection->projectKey()));
        $text = 'Здравствуйте! Это '.$brand.'.';
        if ($context) $text .= "\n".$context['model'].' — '.mb_strtolower($context['service'], 'UTF-8').'.';
        return $text.($type === 'bot_started'
            ? ($context ? "\nРасскажите, что случилось с устройством. Можно приложить фото — менеджер увидит ваше сообщение здесь." : "\nКакая модель устройства и что с ним случилось? Можно приложить фото — менеджер увидит ваше сообщение здесь.")
            : "\nПолучили ваше сообщение. Менеджер ответит здесь.");
    }

    /** One bounded intent in the existing handoff event; never a retry queue. */
    private static function deliverGreeting(PDO $pdo, ProjectMaxConnection $connection, int $conversationId, int $chatId, string $deliveryKey, ?callable $factory): bool
    {
        $q = $pdo->prepare("SELECT e.id,e.payload_json,c.status,c.manager_id FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE e.conversation_id=? AND c.project_key=? AND c.channel='max' AND c.external_chat_id=? AND e.event_type='waiting_manager' AND e.payload_json LIKE ? ORDER BY e.id DESC LIMIT 1");
        $q->execute([$conversationId,$connection->projectKey(),(string)$chatId,'%"delivery_key":"'.$deliveryKey.'"%']);
        $event = $q->fetch(PDO::FETCH_ASSOC);
        if (!$event) return true;
        $payload = json_decode((string)$event['payload_json'], true);
        $intent = is_array($payload) ? ($payload['repair_greeting'] ?? null) : null;
        if (!is_array($intent) || ($intent['state'] ?? '') !== 'pending'
            || ($intent['delivery_key'] ?? '') !== $deliveryKey || ($intent['bot_key'] ?? '') !== $connection->botKey()) return true;
        $text = (string)($intent['text'] ?? '');
        if ($text === '') return false;
        if ((string)$event['status'] !== 'waiting_manager' || !empty($event['manager_id'])) {
            return self::greetingState($pdo, (int)$event['id'], (string)$event['payload_json'], $payload, 'suppressed');
        }
        // Construction may fail before any provider write; the pending intent remains recoverable.
        $factory = $factory ?? static fn(ProjectMaxConnection $owner) => ProjectMaxMessengerFactory::forConnection($owner, 'system');
        $messenger = $factory($connection);
        $payload['repair_greeting']['state'] = 'attempted';
        $attempted = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $claim = $pdo->prepare("UPDATE conversation_events SET payload_json=? WHERE id=? AND payload_json=? AND EXISTS (SELECT 1 FROM conversations c WHERE c.id=? AND c.project_key=? AND c.channel='max' AND c.external_chat_id=? AND c.status='waiting_manager' AND c.manager_id IS NULL)");
        $claim->execute([$attempted,(int)$event['id'],(string)$event['payload_json'],$conversationId,$connection->projectKey(),(string)$chatId]);
        if ($claim->rowCount() !== 1) return true;
        $current = $pdo->prepare("SELECT status,manager_id FROM conversations WHERE id=? AND project_key=? AND channel='max' AND external_chat_id=?");
        $current->execute([$conversationId,$connection->projectKey(),(string)$chatId]);
        $owner = $current->fetch(PDO::FETCH_ASSOC);
        if (!$owner || (string)$owner['status'] !== 'waiting_manager' || $owner['manager_id'] !== null) {
            return self::greetingState($pdo, (int)$event['id'], $attempted, $payload, 'suppressed');
        }
        try { $sent = $messenger->send($chatId, $text); } catch (Throwable $ignored) { $sent = false; }
        if (!$sent) return self::greetingState($pdo, (int)$event['id'], $attempted, $payload, 'unknown');

        try {
            $pdo->beginTransaction();
            $externalId = method_exists($messenger, 'lastExternalMessageId') ? (string)$messenger->lastExternalMessageId() : '';
            if (!ConversationRecorder::outboundForConversation($conversationId, 'max', $text, 'system', null, ['bot_key'=>$connection->botKey(),'repair_entry'=>true], $externalId)) throw new RuntimeException('reply_not_persisted');
            if (!self::greetingState($pdo, (int)$event['id'], $attempted, $payload, 'delivered')) throw new RuntimeException('reply_receipt_not_persisted');
            $pdo->commit();
            return true;
        } catch (Throwable $ignored) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // The provider may have delivered. Preserve ambiguity and never resend automatically.
            self::greetingState($pdo, (int)$event['id'], $attempted, $payload, 'unknown');
            return false;
        }
    }

    private static function greetingState(PDO $pdo, int $eventId, string $expected, array $payload, string $state): bool
    {
        $payload['repair_greeting']['state'] = $state;
        $next = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $q = $pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=? AND payload_json=?');
        $q->execute([$next,$eventId,$expected]);
        return $q->rowCount() === 1;
    }

    private static function response(int $status, string $result): array
    {
        return ['status'=>$status,'ok'=>$status === 200,'result'=>$result];
    }
}
