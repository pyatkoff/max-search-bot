<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationRecordClock.php';
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/RoutingAccessService.php';

/**
 * Best-effort mirror of live dialogues into the dedicated conversation DB.
 * Any recorder failure is swallowed so it can never block the production bot.
 */
class ConversationRecorder
{
    // Request-local provenance, never recovered from the mutable last dialogue.
    private static ?array $wizardInput = null;
    private static array $wizardReceipts = [];
    private static string $wizardReceiptReason = 'input_unavailable';

    public static function inbound(array $incoming): bool
    {
        self::$wizardInput = null;
        self::$wizardReceipts = [];
        self::$wizardReceiptReason = 'input_unavailable';
        try {
            if (!ConversationDb::isConfigured()) return false;
            $user = (array)($incoming['user'] ?? []);
            $platform = strtolower(trim((string)($incoming['platform'] ?? '')));
            $externalUserId = trim((string)($user['external_user_id'] ?? ''));
            $chatId = trim((string)($user['chat_id'] ?? ''));
            if ($platform === '' || $externalUserId === '' || $chatId === '') return false;
            $sourceKey=trim((string)($incoming['source_key']??ProjectConfig::get('routing.source_key',$platform.':default')));

            $conversationId = self::ensureConversation($platform, $externalUserId, $chatId, $user, $sourceKey);
            if (!$conversationId) return false;

            $type = (string)($incoming['type'] ?? 'message');
            if ($platform === 'max' && $type === 'bot_started') {
                return self::eventByChat($platform, $chatId, 'bot_started', ['source_key'=>$sourceKey], 'system');
            }
            $attachments = array_values(array_filter((array)($incoming['attachments'] ?? []), 'is_array'));
            $text = $type === 'contact'
                ? (string)($incoming['contact_phone'] ?? '')
                : ($type === 'callback' ? (string)($incoming['callback_data'] ?? '') : (string)($incoming['text'] ?? ''));
            if ($type === 'message' && trim($text) === '' && $attachments) $text = self::attachmentPreview($attachments);
            $messageId = trim((string)($incoming['message_id'] ?? ''));
            if ($messageId === '' && $type === 'callback') $messageId = trim((string)($incoming['callback_id'] ?? ''));

            $pdo = ConversationDb::connection();
            if ($messageId !== '') {
                $check = $pdo->prepare('SELECT id FROM messages WHERE conversation_id=? AND direction=? AND external_message_id=? LIMIT 1');
                $check->execute([$conversationId, 'inbound', $messageId]);
                if ($check->fetchColumn()) {
                    self::$wizardReceiptReason = 'duplicate_input';
                    return true;
                }
            }

            $metadata = ['type'=>$type, 'username'=>(string)($user['username'] ?? ''), 'source_key'=>$sourceKey];
            if ($attachments) $metadata['attachments'] = $attachments;
            $linked=(array)($incoming['linked_message']??[]);
            if(in_array((string)($linked['type']??''),['reply','forward'],true)) $metadata['linked_message']=$linked;
            $meta = self::json(ConversationRecordClock::stamp($metadata));
            $stmt = $pdo->prepare('INSERT INTO messages (conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$conversationId,'inbound','customer',$externalUserId,$platform,$messageId !== '' ? $messageId : null,$text,$meta]);
            $inputId = (string)$pdo->lastInsertId();
            self::touch($conversationId);
            if (ProjectConfig::projectId() === 'anytour' && in_array($platform, ['max','telegram'], true)
                && in_array($type, ['message','callback'], true) && $text !== ''
                && ($linked['type'] ?? '') !== 'forward' && ctype_digit($inputId) && (int)$inputId > 0) {
                self::$wizardInput = ['conversation_id'=>$conversationId, 'chat_id'=>$chatId,
                    'input_message_id'=>$inputId, 'input_kind'=>$type, 'input_text_sha256'=>hash('sha256',$text)];
                self::$wizardReceiptReason = 'ready';
            }
            return true;
        } catch (Throwable $e) {
            self::logFailure('inbound', $e);
            return false;
        }
    }

    /** Observe only a successful deterministic application of this request's input. */
    public static function wizardValueApplied($chatId, string $field, string $value): bool
    {
        try {
            $input = self::$wizardInput;
            if (!$input) return false;
            if (ProjectConfig::projectId() !== 'anytour') {
                self::$wizardReceiptReason = 'project_mismatch';
                return false;
            }
            if ($input['chat_id'] !== (string)$chatId) {
                self::$wizardReceiptReason = 'chat_mismatch';
                return false;
            }
            if (!in_array($field, ['city','country','adults','children','child_ages','stars','meal','nights','date'], true)) {
                self::$wizardReceiptReason = 'unsupported_field';
                return false;
            }
            if ($value === '' || strlen($value) > 160) {
                self::$wizardReceiptReason = 'invalid_value';
                return false;
            }
            $key = $field . ':' . $value;
            if (isset(self::$wizardReceipts[$key])) {
                self::$wizardReceiptReason = 'already_recorded';
                return true;
            }
            $payload = ['version'=>1, 'origin'=>'existing_wizard_step_application',
                'input_message_id'=>$input['input_message_id'], 'input_kind'=>$input['input_kind'],
                'input_text_sha256'=>$input['input_text_sha256'], 'field'=>$field, 'storage_value'=>$value];
            $stmt = ConversationDb::connection()->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json) VALUES (?,?,?,?)');
            $stmt->execute([$input['conversation_id'],'wizard_value_applied','system',self::json(ConversationRecordClock::stamp($payload))]);
            self::$wizardReceipts[$key] = true;
            self::$wizardReceiptReason = 'recorded';
            return true;
        } catch (Throwable $ignored) {
            // Provenance failure must not change the applied value or leak its content.
            self::$wizardReceiptReason = 'write_failed';
            return false;
        }
    }

    /** Fixed non-sensitive reason for the last request-local receipt decision. */
    public static function wizardReceiptReason(): string
    {
        return self::$wizardReceiptReason;
    }

    public static function outbound(string $platform, $chatId, string $text, string $senderType = 'ai', array $metadata = []): bool
    {
        try {
            if (!ConversationDb::isConfigured()) return false;
            $platform = strtolower(trim($platform));
            $chatId = trim((string)$chatId);
            if ($platform === '' || $chatId === '') return false;
            $conversationId = self::findConversationByChat($platform, $chatId);
            if (!$conversationId) return false;
            return self::outboundForConversation($conversationId,$platform,$text,$senderType,null,$metadata);
        } catch (Throwable $e) {
            self::logFailure('outbound', $e);
            return false;
        }
    }

    /** Mirror an outbound message when the owning conversation is already known. */
    public static function outboundForConversation(int $conversationId, string $platform, string $text, string $senderType = 'ai', $senderId = null, array $metadata = [], string $externalMessageId = '', ?string $initialAiText = null): bool
    {
        try {
            if (!ConversationDb::isConfigured() || $conversationId <= 0) return false;
            $platform = strtolower(trim($platform));
            if ($platform === '') return false;
            $pdo = ConversationDb::connection();
            $stmt = $pdo->prepare('INSERT INTO messages (conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$conversationId,'outbound',$senderType,$senderId,$platform,$externalMessageId !== '' ? $externalMessageId : null,$text,self::json(ConversationRecordClock::stamp($metadata))]);
            if($initialAiText!==null){
                // Capture this INSERT's ID before optional storage or touch can
                // change lastInsertId or fail. Never retry successful transport.
                $messageId=(int)$pdo->lastInsertId();
                try{
                    require_once __DIR__.'/ManagerAiSentTextService.php';
                    ManagerAiSentTextService::record($pdo,$messageId,$conversationId,$senderType,$senderId,$text,$metadata,$initialAiText);
                }catch(Throwable $ignored){/* Optional provenance only. */}
            }
            self::touch($conversationId);
            return true;
        } catch (Throwable $e) {
            self::logFailure('outbound_for_conversation', $e);
            return false;
        }
    }

    public static function eventByChat(string $platform, $chatId, string $eventType, array $payload = [], string $actorType = 'system'): bool
    {
        try {
            if (!ConversationDb::isConfigured()) return false;
            $conversationId = self::findConversationByChat(strtolower(trim($platform)), trim((string)$chatId));
            if (!$conversationId) return false;
            $pdo = ConversationDb::connection();
            $stmt = $pdo->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json) VALUES (?,?,?,?)');
            $stmt->execute([$conversationId,$eventType,$actorType,self::json(ConversationRecordClock::stamp($payload))]);
            return true;
        } catch (Throwable $e) {
            self::logFailure('event', $e);
            return false;
        }
    }

    public static function attachmentPreview(array $attachments): string
    {
        $labels = ['image'=>'Фото','video'=>'Видео','audio'=>'Аудио','file'=>'Файл'];
        $names = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $type = strtolower((string)($attachment['type'] ?? 'file'));
            $names[] = $labels[$type] ?? 'Вложение';
        }
        $names = array_values(array_unique($names));
        return $names ? '📎 ' . implode(', ', $names) : '📎 Вложение';
    }

    private static function ensureConversation(string $platform, string $externalUserId, string $chatId, array $user, string $sourceKey): int
    {
        $pdo = ConversationDb::connection();
        $project = ProjectConfig::projectId();
        $sourceId=RoutingAccessService::sourceId($project,$sourceKey,$platform);

        $stmt = $pdo->prepare('SELECT cc.id AS channel_id, cc.customer_id FROM customer_channels cc WHERE cc.project_key=? AND cc.channel=? AND cc.external_user_id=? LIMIT 1');
        $stmt->execute([$project,$platform,$externalUserId]);
        $row = $stmt->fetch();

        $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        if (!$row) {
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('INSERT INTO customers (display_name) VALUES (?)');
                $q->execute([$displayName !== '' ? $displayName : null]);
                $customerId = (int)$pdo->lastInsertId();
                $q = $pdo->prepare('INSERT INTO customer_channels (customer_id,project_key,channel,external_user_id,external_chat_id,username,metadata_json) VALUES (?,?,?,?,?,?,?)');
                $q->execute([$customerId,$project,$platform,$externalUserId,$chatId,(string)($user['username'] ?? ''),self::json(['first_name'=>$user['first_name'] ?? '', 'last_name'=>$user['last_name'] ?? ''])]);
                $channelId = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } else {
            $channelId = (int)$row['channel_id'];
            $customerId = (int)$row['customer_id'];
            $q = $pdo->prepare('UPDATE customer_channels SET external_chat_id=?, username=? WHERE id=?');
            $q->execute([$chatId,(string)($user['username'] ?? ''),$channelId]);
            if ($displayName !== '') {
                $q = $pdo->prepare('UPDATE customers SET display_name=? WHERE id=? AND (display_name IS NULL OR display_name="")');
                $q->execute([$displayName,$customerId]);
            }
        }

        $q = $pdo->prepare('SELECT id,source_id FROM conversations WHERE customer_channel_id=? AND status<>? ORDER BY id DESC LIMIT 1');
        $q->execute([$channelId,'closed']);
        $existing=$q->fetch();
        if ($existing) {
            $id=(int)$existing['id'];
            if(empty($existing['source_id'])&&$sourceId>0)$pdo->prepare('UPDATE conversations SET source_id=? WHERE id=?')->execute([$sourceId,$id]);
            return $id;
        }

        $q = $pdo->prepare('INSERT INTO conversations (customer_id,customer_channel_id,project_key,source_id,channel,external_chat_id,status,last_message_at,metadata_json) VALUES (?,?,?,?,?,?,?,NOW(),?)');
        $q->execute([$customerId,$channelId,$project,$sourceId>0?$sourceId:null,$platform,$chatId,'ai',self::json(ConversationRecordClock::stamp([]))]);
        return (int)$pdo->lastInsertId();
    }

    private static function findConversationByChat(string $platform, string $chatId): int
    {
        if ($platform === '' || $chatId === '') return 0;
        $pdo = ConversationDb::connection();
        $q = $pdo->prepare('SELECT id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>? ORDER BY id DESC LIMIT 1');
        $q->execute([ProjectConfig::projectId(),$platform,$chatId,'closed']);
        return (int)$q->fetchColumn();
    }

    private static function touch(int $conversationId): void
    {
        $q = ConversationDb::connection()->prepare('UPDATE conversations SET last_message_at=NOW() WHERE id=?');
        $q->execute([$conversationId]);
    }

    private static function json(array $value): ?string
    {
        if (!$value) return null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    private static function logFailure(string $stage, Throwable $e): void
    {
        if (class_exists('DiagnosticLogger')) {
            try { DiagnosticLogger::log('conversation_mirror','failure',['stage'=>$stage,'error'=>$e->getMessage()],null,'error'); } catch (Throwable $ignored) {}
        }
    }
}
