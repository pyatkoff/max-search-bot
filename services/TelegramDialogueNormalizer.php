<?php
declare(strict_types=1);

/** Data-only projection; never calls a provider or the ordinary bot dispatcher. */
final class TelegramDialogueNormalizer
{
    public const UPDATE_TYPES = ['business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages'];
    public const MAX_TEXT_BYTES = 1048576;

    public static function isBusinessUpdate(array $update): bool
    {
        foreach (self::UPDATE_TYPES as $type) if (array_key_exists($type, $update)) return true;
        return false;
    }

    public static function id($value): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,15}\z/', (string)$value)) {
            throw new InvalidArgumentException('invalid_telegram_identity');
        }
        return (string)$value;
    }

    public static function timestamp($value): int
    {
        $value = self::id($value);
        if (strlen($value) > 11 || (int)$value > 253402300799) throw new InvalidArgumentException('invalid_telegram_timestamp');
        return (int)$value;
    }

    public static function sequence($value): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/', (string)$value)) throw new InvalidArgumentException('invalid_telegram_sequence');
        return (string)$value;
    }

    public static function connectionId($value): string
    {
        // Telegram defines this as an opaque string, not a username or local key.
        if (!is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[^\x21-\x7e]/', $value)) throw new InvalidArgumentException('invalid_business_connection_id');
        return $value;
    }

    public static function text($value): string
    {
        if (!is_string($value) || strlen($value) > self::MAX_TEXT_BYTES || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('invalid_telegram_text');
        }
        return $value;
    }

    public static function links(string $text, array $entities = []): array
    {
        preg_match_all('~https?://[^\s<>"\x{0000}-\x{0020}]+~u', $text, $matches);
        $links = $matches[0];
        foreach ($entities as $entity) {
            if (!is_array($entity)) continue;
            $url = $entity['href'] ?? $entity['url'] ?? null;
            if (is_string($url) && preg_match('~\Ahttps?://[^\s<>"\x{0000}-\x{0020}]+\z~u', $url)) $links[] = $url;
        }
        return array_values(array_unique($links));
    }

    /** Both the complete account export and an individual chat export are accepted. */
    public static function desktop(array $export, string $account, array $selectedChats): array
    {
        $account = self::id($account);
        if (!$selectedChats || count($selectedChats) > 10000) throw new InvalidArgumentException('explicit_chat_scope_required');
        $selected = [];
        foreach ($selectedChats as $chat) $selected[self::id($chat)] = false;
        $chats = isset($export['chats']['list']) ? $export['chats']['list'] : [$export];
        if (!is_array($chats)) throw new InvalidArgumentException('invalid_desktop_export');
        $records = []; $seen = []; $serviceCount = 0; $excluded = 0;
        foreach ($chats as $chat) {
            if (!is_array($chat)) throw new InvalidArgumentException('invalid_desktop_chat');
            $chatId = (string)($chat['id'] ?? '');
            if (!array_key_exists($chatId, $selected)) { $excluded++; continue; }
            if ($selected[$chatId]) throw new InvalidArgumentException('duplicate_desktop_chat');
            if (($chat['type'] ?? '') !== 'personal_chat') throw new InvalidArgumentException('selected_chat_is_not_private');
            if (!is_array($chat['messages'] ?? null)) throw new InvalidArgumentException('invalid_desktop_messages');
            $selected[$chatId] = true;
            foreach ($chat['messages'] as $message) {
                if (!is_array($message)) throw new InvalidArgumentException('invalid_desktop_message');
                if (($message['type'] ?? '') === 'service') { $serviceCount++; continue; }
                if (($message['type'] ?? '') !== 'message') throw new InvalidArgumentException('unsupported_desktop_message');
                $messageId = self::id($message['id'] ?? null);
                if (isset($seen[$chatId.':'.$messageId])) throw new InvalidArgumentException('duplicate_desktop_message');
                $seen[$chatId.':'.$messageId] = true;
                $parts = $message['text'] ?? '';
                $text = ''; $entities = [];
                if (is_string($parts)) $text = self::text($parts);
                elseif (is_array($parts)) foreach ($parts as $part) {
                    if (is_string($part)) $text .= $part;
                    elseif (is_array($part) && is_string($part['text'] ?? null)) { $text .= $part['text']; $entities[] = $part; }
                    else throw new InvalidArgumentException('invalid_desktop_text_entity');
                }
                else throw new InvalidArgumentException('invalid_desktop_text');
                $text = self::text($text);
                $author = $message['from_id'] ?? null;
                $author = is_string($author) && preg_match('/\Auser([1-9][0-9]{0,15})\z/', $author, $m) ? $m[1] : null;
                $created = self::timestamp($message['date_unixtime'] ?? null);
                $edited = isset($message['edited_unixtime']) ? self::timestamp($message['edited_unixtime']) : $created;
                if ($edited < $created || (!empty($message['edited']) && !isset($message['edited_unixtime']))) throw new InvalidArgumentException('invalid_desktop_edit_time');
                $attachments = [];
                foreach (['photo', 'file'] as $field) if (isset($message[$field])) {
                    $reference = self::text($message[$field]);
                    $relative = $reference !== '' && !str_contains($reference, '\\') && !str_starts_with($reference, '/')
                        && !preg_match('~(?:\A|/)\.\.(?:/|\z)|\A[A-Za-z]+:~', $reference) && !str_starts_with($reference, '(');
                    $media = $message['media_type'] ?? 'file';
                    if (!is_string($media)) throw new InvalidArgumentException('invalid_desktop_media_type');
                    $media = in_array($media, ['voice_message','audio_file'], true) ? 'audio'
                        : (in_array($media, ['video_file','video_message','animation'], true) ? 'video' : 'file');
                    $attachments[] = ['type'=>$field === 'photo' ? 'image' : $media,
                        'reference'=>$relative ? $reference : null, 'availability'=>$relative ? 'not_checked' : 'not_exported'];
                }
                $records[] = self::record($account, $chatId, $messageId, $author, $created, $edited, $text,
                    self::links($text, array_merge($entities, (array)($message['text_entities'] ?? []))), $attachments,
                    isset($message['reply_to_message_id']) ? self::id($message['reply_to_message_id']) : null,
                    'desktop_import', null, null, isset($message['forwarded_from']));
                if (count($records) > 200000) throw new InvalidArgumentException('desktop_message_limit');
            }
        }
        if (in_array(false, $selected, true)) throw new InvalidArgumentException('selected_chat_missing');
        return ['messages'=>$records, 'counts'=>['selected_chats'=>count($selected), 'messages'=>count($records),
            'service_messages'=>$serviceCount, 'excluded_chats'=>$excluded]];
    }

    public static function businessMessage(array $message, string $account, string $connection, string $sequence): array
    {
        if (($message['chat']['type'] ?? '') !== 'private') throw new InvalidArgumentException('business_chat_is_not_private');
        $account = self::id($account); $chat = self::id($message['chat']['id'] ?? null);
        $author = isset($message['from']['id']) ? self::id($message['from']['id']) : null;
        $created = self::timestamp($message['date'] ?? null);
        $edited = isset($message['edit_date']) ? self::timestamp($message['edit_date']) : $created;
        if ($edited < $created) throw new InvalidArgumentException('invalid_business_edit_time');
        $nativeContent = self::nativeContent($message);
        $text = self::text($message['text'] ?? $message['caption'] ?? '');
        $attachments = [];
        $photo = null;
        foreach ((array)($message['photo'] ?? []) as $item) if (is_array($item) && isset($item['file_id'])) $photo = $item;
        if ($photo) $attachments[] = self::file('image', $photo);
        foreach (['animation'=>'video', 'video'=>'video', 'video_note'=>'video', 'voice'=>'audio', 'audio'=>'audio', 'document'=>'file', 'sticker'=>'file'] as $field=>$type) {
            if (is_array($message[$field] ?? null)) { $attachments[] = self::file($type, $message[$field]); break; }
        }
        $record = self::record($account, $chat, self::id($message['message_id'] ?? null), $author, $created, $edited, $text,
            self::links($text, (array)($message['entities'] ?? $message['caption_entities'] ?? [])), $attachments,
            isset($message['reply_to_message']['message_id']) ? self::id($message['reply_to_message']['message_id']) : null,
            'business_message', $connection, $sequence, isset($message['forward_origin']));
        if (!empty($message['sender_business_bot']) || !empty($message['from']['is_bot'])) $record['sender_type'] = 'bot';
        if (isset($message['rich_message']) && $text === '') $record['content_coverage'] = 'rich_message_not_decoded';
        $record['native_content'] = $nativeContent;
        return $record;
    }

    /** Native fields only: a text message does not prove typing rather than a reply-keyboard action. */
    private static function nativeContent(array $message): array
    {
        $native = ['schema_version'=>1, 'producer'=>'TelegramDialogueNormalizer.v1',
            'source_format'=>'telegram_bot_api_message', 'kind'=>'unknown', 'text_origin'=>'unknown'];
        // Unknown/service/rich body fields fail closed without rejecting or changing the archived content.
        // Context objects are not traversed; a reply, quote or forwarded origin is not this message's body.
        $known = ['message_id','message_thread_id','direct_messages_topic','from','sender_chat','sender_boost_count',
            'sender_business_bot','sender_tag','receiver_user','ephemeral_message_id','date','guest_query_id',
            'business_connection_id','chat','forward_origin','is_topic_message','is_automatic_forward','reply_to_message',
            'external_reply','quote','reply_to_story','reply_to_checklist_task_id','reply_to_poll_option_id','via_bot',
            'guest_bot_caller_user','guest_bot_caller_chat','edit_date','has_protected_content','is_from_offline',
            'is_paid_post','media_group_id','author_signature','paid_star_count','entities','link_preview_options',
            'suggested_post_info','effect_id','caption_entities','show_caption_above_media','has_media_spoiler','reply_markup',
            'text','caption','contact','photo','animation','audio','document','sticker','video','video_note','voice'];
        if (array_diff(array_keys($message), $known)) return $native;
        $hasText = array_key_exists('text', $message);
        $hasCaption = array_key_exists('caption', $message);
        $hasContact = array_key_exists('contact', $message);
        $media = array_values(array_intersect(['photo','animation','audio','document','sticker','video','video_note','voice'], array_keys($message)));
        if ($hasText) {
            if (!$hasCaption && !$hasContact && !$media && is_string($message['text']) && $message['text'] !== '') $native['kind'] = 'text';
            return $native;
        }
        if ($hasContact) {
            $contact = $message['contact'];
            if (!$hasCaption && !$media && is_array($contact)
                && is_string($contact['phone_number'] ?? null) && $contact['phone_number'] !== ''
                && is_string($contact['first_name'] ?? null) && $contact['first_name'] !== '') $native['kind'] = 'contact';
            return $native; // No contact values enter the provenance or the stored record.
        }
        // Telegram sends document alongside animation for backward compatibility.
        if (!$media || (count($media) > 1 && $media !== ['animation','document'])) return $native;
        foreach ($media as $field) {
            $files = $field === 'photo' ? $message[$field] : [$message[$field]];
            if (!is_array($files) || !$files) return $native;
            foreach ($files as $file) if (!is_array($file) || !is_string($file['file_id'] ?? null) || $file['file_id'] === '') return $native;
        }
        if ($hasCaption) {
            if (!is_string($message['caption']) || array_intersect($media, ['sticker','video_note'])) return $native;
            $native['kind'] = $message['caption'] !== '' ? 'caption' : 'media';
        } else $native['kind'] = 'media';
        return $native;
    }

    private static function file(string $type, array $file): array
    {
        return ['type'=>$type, 'telegram_file_id'=>self::text($file['file_id'] ?? ''),
            'file_unique_id'=>isset($file['file_unique_id']) ? self::text($file['file_unique_id']) : null,
            'availability'=>'provider_reference_only'];
    }

    public static function tombstone(string $account, string $chat, string $message, string $connection, string $sequence): array
    {
        return ['account_id'=>self::id($account), 'chat_id'=>self::id($chat), 'message_id'=>self::id($message),
            'sender_id'=>null, 'sender_type'=>'unknown', 'direction'=>'unknown', 'created_at'=>null, 'version_at'=>null,
            'text'=>null, 'links'=>[], 'attachments'=>[], 'reply_to_message_id'=>null, 'is_forwarded'=>false,
            'is_deleted'=>true, 'source_kind'=>'business_message', 'connection_id'=>$connection, 'update_id'=>$sequence];
    }

    private static function record(string $account, string $chat, string $message, ?string $author, int $created, int $edited,
        string $text, array $links, array $attachments, ?string $reply, string $source, ?string $connection, ?string $sequence, bool $forwarded): array
    {
        $role = $author === $account ? 'manager' : ($author === $chat ? 'customer' : 'unknown');
        return ['account_id'=>$account, 'chat_id'=>$chat, 'message_id'=>$message, 'sender_id'=>$author, 'sender_type'=>$role,
            'direction'=>$role === 'manager' ? 'outbound' : ($role === 'customer' ? 'inbound' : 'unknown'),
            'created_at'=>$created, 'version_at'=>$edited, 'text'=>$text, 'links'=>$links, 'attachments'=>$attachments,
            'reply_to_message_id'=>$reply, 'is_forwarded'=>$forwarded, 'is_deleted'=>false, 'source_kind'=>$source,
            'content_coverage'=>$text !== '' ? 'text_or_caption' : ($attachments ? 'media_reference_only' : 'unsupported_or_empty_content'),
            'connection_id'=>$connection, 'update_id'=>$sequence];
    }
}
