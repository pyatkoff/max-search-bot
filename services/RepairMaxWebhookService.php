<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectMaxConnection.php';
require_once __DIR__.'/ProjectMaxMessengerFactory.php';
require_once __DIR__.'/AppleService39PilotCatalog.php';
require_once __DIR__.'/RepairProjectSettings.php';
require_once __DIR__.'/ConversationRecorder.php';
require_once __DIR__.'/ConversationControlService.php';
require_once __DIR__.'/ChannelPostRegistryService.php';
require_once __DIR__.'/ProjectFunnelConversation.php';
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
        if (RepairProjectSettings::businessKind($connection->settings()) !== 'device_repair') {
            if($projectSelector===AppleService39PilotCatalog::PROJECT)return self::response(403,'forbidden');
            $funnel=ProjectFunnelSettings::load($connection);
            if(!$funnel||($funnel['handoff_mode']??'')!=='manager_first'||RepairProjectSettings::webhook($connection)===null)return self::response(403, 'forbidden');
        }

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
                if (!self::deliverPendingReply($pdo, $connection, $recordedConversation, $chatId, $deliveryKey, $messengerFactory)) return self::response(503, 'reply_not_persisted');
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

            $entry = $type === 'bot_started' ? RepairProjectSettings::entry($connection, $payload) : null;
            $context = $entry !== null ? ['model'=>$entry['model'],'service'=>$entry['service'],'entry_code'=>$payload] : [];
            if ($type === 'bot_started') {
                $name = preg_split('/\s+/', trim((string)($user['name'] ?? '')), 2) ?: [];
                $incoming = ['platform'=>'max','type'=>'bot_started','start_payload'=>$payload,'user'=>[
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
            $q = $pdo->prepare('SELECT metadata_json,status,manager_id,customer_id,customer_channel_id FROM conversations WHERE id=? AND project_key=? AND channel=?');
            $q->execute([$conversationId,$projectKey,'max']);
            $conversation = $q->fetch(PDO::FETCH_ASSOC);
            if (!$conversation) throw new RuntimeException('conversation_scope_mismatch');
            $metadata = json_decode((string)$conversation['metadata_json'], true);
            $metadata = is_array($metadata) ? $metadata : [];
            if (isset($metadata['max_bot_key']) && $metadata['max_bot_key'] !== $botKey) throw new RuntimeException('conversation_bot_mismatch');
            $metadata['max_bot_key'] = $botKey;
            $replyContext = $context;
            if ($entry !== null && $projectKey === AppleService39PilotCatalog::PROJECT) {
                $known = is_array($metadata['repair_context'] ?? null) ? $metadata['repair_context'] : [];
                $replyContext = AppleService39PilotCatalog::entryContext($entry, $known, $payload);
            }
            // A generic/reopened start cannot erase the customer's known device.
            // Only another recognized repair entry replaces the reviewed context.
            if ($context && ($entry['scope'] ?? 'repair') !== 'menu') $metadata['repair_context'] = $replyContext;
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
                // Optional advertising context cannot obstruct the existing lead/manager path.
                try {
                    $funnelPost=(string)($entry['post_id']??'');
                    if($resolved!==null){
                        $funnelSettings=ProjectFunnelSettings::load($connection);
                        foreach(($funnelSettings['posts']??[])as$key=>$item)if(($item['publication_id']??null)===$resolved['publication_id'])$funnelPost=(string)$key;
                    }
                    ProjectFunnelConversation::entry($pdo,$connection,$conversationId,$externalUser,$sourceKey,$funnelPost,$deliveryKey,time());
                } catch(Throwable $ignoredFunnel) {}
            }
            if ($entry !== null) {
                $brand = trim((string)$connection->settings()->get('brand.name', $projectKey));
                $text = $projectKey === AppleService39PilotCatalog::PROJECT
                    ? AppleService39PilotCatalog::contextResponseText($entry, $brand, $replyContext)
                    : RepairProjectSettings::responseText($entry, $brand);
                self::captureEntryReply($pdo, $connection, $conversationId, $externalUser, $deliveryKey, $conversation,
                    $entry, $context, $text, $replyContext);
            }
            $created = false;
            $handoff = ['source'=>'repair_max','source_key'=>$sourceKey];
            // One explicit catalog entry owns its reply, including on an existing
            // manager conversation. Do not also create a handoff greeting intent.
            if ($entry === null) $handoff['repair_greeting'] = [
                'delivery_key'=>$deliveryKey,'bot_key'=>$botKey,'state'=>'pending',
                'text'=>self::greeting($connection, $type, $context)];
            if (!ConversationControlService::markWaitingForProject($projectKey, 'max', $chatId, $handoff, $created, false)) throw new RuntimeException('handoff_not_persisted');
            $pdo->commit();

            // Existing manager notification owner runs only after the shared history commits.
            if ($created || $type === 'message_created') {
                try {
                    $notify = $notify ?? static fn(int $id, string $body, string $mid) => ManagerPushService::notifyConversation($id, $body, $mid);
                    $notify($conversationId, $created ? 'Новая заявка ждёт менеджера' : 'Клиент написал в диалог', $messageId);
                } catch (Throwable $ignored) {}
            }
            if (($created || $entry !== null) && !$duplicate) {
                if (!self::deliverPendingReply($pdo, $connection, $conversationId, $chatId, $deliveryKey, $messengerFactory)) return self::response(503, 'reply_not_persisted');
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
        if(RepairProjectSettings::businessKind($connection->settings())!=='device_repair')return $text."\nРасскажите, что вас интересует. Менеджер увидит ваше сообщение и ответит здесь.";
        if ($context) $text .= "\n".$context['model'].' — '.mb_strtolower($context['service'], 'UTF-8').'.';
        return $text.($type === 'bot_started'
            ? ($context ? "\nРасскажите, что случилось с устройством. Можно приложить фото — менеджер увидит ваше сообщение здесь." : "\nКакая модель устройства и что с ним случилось? Можно приложить фото — менеджер увидит ваше сообщение здесь.")
            : "\nПолучили ваше сообщение. Менеджер ответит здесь.");
    }

    /** Add the immutable answer to the native entry event in the core transaction. */
    private static function captureEntryReply(PDO $pdo, ProjectMaxConnection $connection, int $conversationId, string $externalUser, string $deliveryKey, array $conversation, array $entry, array $context, string $text, array $replyContext): void
    {
        if (!$pdo->inTransaction() || $text === '' || strlen($text) > 8192) throw new RuntimeException('entry_reply_not_persisted');
        $q = $pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type='bot_started' AND payload_json LIKE ? ORDER BY id DESC LIMIT 2");
        $q->execute([$conversationId,'%"delivery_key":"'.$deliveryKey.'"%']);
        $events = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($events) !== 1) throw new RuntimeException('entry_reply_not_persisted');
        $event = $events[0];
        $payload = json_decode((string)$event['payload_json'], true);
        if (!is_array($payload) || ($payload['delivery_key'] ?? '') !== $deliveryKey
            || ($payload['bot_key'] ?? '') !== $connection->botKey() || ($payload['repair_context'] ?? null) !== $context
            || array_key_exists('repair_entry_reply', $payload)) throw new RuntimeException('entry_reply_not_persisted');
        // The recorder's original event must match before enriching that same new event.
        // Its captured reply and effective context are committed by one existing CAS.
        if (($replyContext['entry_code'] ?? null) !== $context['entry_code']) throw new RuntimeException('entry_reply_not_persisted');
        $payload['repair_context'] = $replyContext;
        $payload['repair_entry_reply'] = [
            'delivery_key'=>$deliveryKey,'project_key'=>$connection->projectKey(),'bot_key'=>$connection->botKey(),
            'conversation_id'=>$conversationId,'external_user_id'=>$externalUser,
            'customer_id'=>(int)$conversation['customer_id'],'customer_channel_id'=>(int)$conversation['customer_channel_id'],
            'entry_code'=>$context['entry_code'],'post_id'=>$entry['post_id'],'state'=>'pending','text'=>$text,
        ];
        if (isset($entry['config_sha256'])) $payload['repair_entry_reply']['config_sha256'] = $entry['config_sha256'];
        $next = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $q = $pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=? AND payload_json=?');
        $q->execute([$next,(int)$event['id'],(string)$event['payload_json']]);
        if ($q->rowCount() !== 1) throw new RuntimeException('entry_reply_not_persisted');

        // A contextual answer replaces a still-unsent generic handoff greeting.
        // Its earlier delivery retry must not send a second, less specific answer.
        $q = $pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type='waiting_manager' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);
        $handoff = $q->fetch(PDO::FETCH_ASSOC);
        $handoffPayload = $handoff ? json_decode((string)$handoff['payload_json'], true) : null;
        $greeting = is_array($handoffPayload) ? ($handoffPayload['repair_greeting'] ?? null) : null;
        if (is_array($greeting) && ($greeting['state'] ?? '') === 'pending' && ($greeting['bot_key'] ?? '') === $connection->botKey()) {
            if (!self::replyState($pdo,(int)$handoff['id'],(string)$handoff['payload_json'],$handoffPayload,'repair_greeting','suppressed')) throw new RuntimeException('handoff_reply_not_suppressed');
        }
    }

    private static function replyOwner(PDO $pdo, ProjectMaxConnection $connection, int $conversationId, int $chatId): ?array
    {
        $q = $pdo->prepare("SELECT c.id,c.status,c.manager_id,c.customer_id,c.customer_channel_id,c.metadata_json FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id WHERE c.id=? AND c.project_key=? AND c.channel='max' AND c.external_chat_id=? AND cc.project_key=? AND cc.channel='max' AND cc.external_user_id=? AND cc.external_chat_id=?");
        $q->execute([$conversationId,$connection->projectKey(),(string)$chatId,$connection->projectKey(),(string)(-$chatId),(string)$chatId]);
        $owner = $q->fetch(PDO::FETCH_ASSOC);
        if (!$owner) return null;
        $metadata = json_decode((string)$owner['metadata_json'], true);
        return is_array($metadata) && ($metadata['max_bot_key'] ?? '') === $connection->botKey() ? $owner : null;
    }

    private static function mayReply(?array $owner, array $intent, bool $entryReply, ProjectMaxConnection $connection, int $conversationId, int $chatId): bool
    {
        if (!$owner || !in_array((string)$owner['status'], ['waiting_manager','manager'], true)) return false;
        if (!$entryReply) return (string)$owner['status'] === 'waiting_manager' && $owner['manager_id'] === null;
        return ($intent['project_key'] ?? '') === $connection->projectKey()
            && (int)($intent['conversation_id'] ?? 0) === $conversationId
            && ($intent['external_user_id'] ?? '') === (string)(-$chatId)
            && (int)($intent['customer_id'] ?? 0) === (int)$owner['customer_id']
            && (int)($intent['customer_channel_id'] ?? 0) === (int)$owner['customer_channel_id'];
    }

    /** One intent per native entry, or the unchanged first-handoff greeting. */
    private static function deliverPendingReply(PDO $pdo, ProjectMaxConnection $connection, int $conversationId, int $chatId, string $deliveryKey, ?callable $factory): bool
    {
        foreach (['bot_started'=>'repair_entry_reply','waiting_manager'=>'repair_greeting'] as $eventType=>$key) {
            $q = $pdo->prepare('SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type=? AND payload_json LIKE ? ORDER BY id DESC LIMIT 2');
            $q->execute([$conversationId,$eventType,'%"delivery_key":"'.$deliveryKey.'"%']);
            $events = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$events) continue;
            if (count($events) !== 1) return false;
            $event = $events[0];
            $payload = json_decode((string)$event['payload_json'], true);
            if (!is_array($payload)) return false;
            if (!array_key_exists($key, $payload)) continue;
            $intent = $payload[$key];
            if (!is_array($intent) || ($intent['delivery_key'] ?? '') !== $deliveryKey || ($intent['bot_key'] ?? '') !== $connection->botKey()) return false;
            $entryReply = $key === 'repair_entry_reply';
            if (!in_array($intent['state'] ?? '', ['pending','attempted','delivered','unknown','suppressed'], true)) return false;
            // Never fall through to a second greeting for this entry, even if a
            // provider write is unknown or the reply has already been delivered.
            if ($intent['state'] !== 'pending') return true;
            if ($entryReply && !RepairProjectSettings::pendingEntryMatches($connection, $intent)) return false;
            return self::deliverIntent($pdo,$connection,$conversationId,$chatId,$deliveryKey,$factory,$event,$payload,$key,$entryReply);
        }
        return true;
    }

    private static function deliverIntent(PDO $pdo, ProjectMaxConnection $connection, int $conversationId, int $chatId, string $deliveryKey, ?callable $factory, array $event, array $payload, string $key, bool $entryReply): bool
    {
        $intent = $payload[$key];
        $text = (string)($intent['text'] ?? '');
        if ($text === '') return false;
        $owner = self::replyOwner($pdo,$connection,$conversationId,$chatId);
        if (!self::mayReply($owner,$intent,$entryReply,$connection,$conversationId,$chatId)) {
            return self::replyState($pdo,(int)$event['id'],(string)$event['payload_json'],$payload,$key,'suppressed');
        }
        // A construction failure precedes any provider write. The captured text
        // remains pending; recovery never re-renders it from a later catalog.
        $factory = $factory ?? static fn(ProjectMaxConnection $owner) => ProjectMaxMessengerFactory::forConnection($owner, 'system');
        $messenger = $factory($connection);
        $payload[$key]['state'] = 'attempted';
        $attempted = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $status = $entryReply ? "c.status IN ('waiting_manager','manager')" : "c.status='waiting_manager' AND c.manager_id IS NULL";
        $claim = $pdo->prepare("UPDATE conversation_events SET payload_json=? WHERE id=? AND payload_json=? AND EXISTS (SELECT 1 FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id WHERE c.id=? AND c.project_key=? AND c.channel='max' AND c.external_chat_id=? AND c.customer_id=? AND c.customer_channel_id=? AND cc.project_key=? AND cc.channel='max' AND cc.external_user_id=? AND cc.external_chat_id=? AND {$status})");
        $claim->execute([$attempted,(int)$event['id'],(string)$event['payload_json'],$conversationId,$connection->projectKey(),(string)$chatId,
            (int)$owner['customer_id'],(int)$owner['customer_channel_id'],$connection->projectKey(),(string)(-$chatId),(string)$chatId]);
        if ($claim->rowCount() !== 1) {
            $current = self::replyOwner($pdo,$connection,$conversationId,$chatId);
            if (!self::mayReply($current,$intent,$entryReply,$connection,$conversationId,$chatId)) {
                self::replyState($pdo,(int)$event['id'],(string)$event['payload_json'],$payload,$key,'suppressed');
            }
            return true;
        }
        $current = self::replyOwner($pdo,$connection,$conversationId,$chatId);
        if (!self::mayReply($current,$intent,$entryReply,$connection,$conversationId,$chatId)) {
            return self::replyState($pdo,(int)$event['id'],$attempted,$payload,$key,'suppressed');
        }
        try { $sent = $messenger->send($chatId, $text); } catch (Throwable $ignored) { $sent = false; }
        if (!$sent) return self::replyState($pdo,(int)$event['id'],$attempted,$payload,$key,'unknown');

        try {
            $pdo->beginTransaction();
            $externalId = method_exists($messenger, 'lastExternalMessageId') ? (string)$messenger->lastExternalMessageId() : '';
            $metadata = ['bot_key'=>$connection->botKey(),'repair_entry'=>true];
            if ($entryReply) $metadata += ['entry_delivery_key'=>$deliveryKey,'entry_code'=>$intent['entry_code'],
                'source_post_id'=>$intent['post_id'],'repair_context'=>$payload['repair_context']];
            if (!ConversationRecorder::outboundForConversation($conversationId, 'max', $text, 'system', null, $metadata, $externalId)) throw new RuntimeException('reply_not_persisted');
            if (!self::replyState($pdo,(int)$event['id'],$attempted,$payload,$key,'delivered')) throw new RuntimeException('reply_receipt_not_persisted');
            $pdo->commit();
            return true;
        } catch (Throwable $ignored) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // The provider may have delivered. Preserve ambiguity and never resend automatically.
            self::replyState($pdo,(int)$event['id'],$attempted,$payload,$key,'unknown');
            return false;
        }
    }

    private static function replyState(PDO $pdo, int $eventId, string $expected, array $payload, string $key, string $state): bool
    {
        $payload[$key]['state'] = $state;
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
