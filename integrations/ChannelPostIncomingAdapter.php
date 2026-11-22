<?php
declare(strict_types=1);

/** Channel publications are not customer messages and never enter the dialogue dispatcher. */
final class ChannelPostIncomingAdapter
{
    private const MAX_JSON_BYTES = 1048576;

    /** MAX allows twelve total attachments; images/videos reserve one for our keyboard. */
    public static function maxPhotoLimit(): int { return 11; }

    /** A video token authorizes reuse; MAX can renew it on every read. */
    public static function maxVideoTokenValid($token): bool
    {
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]+\z/D', $token) === 1;
    }

    /** Preserve the provider's explicit payload.id without integer coercion. */
    public static function maxVideoId($id): string
    {
        if (!is_int($id) && !is_string($id)) return '';
        $id = (string)$id;
        return preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) === 1 ? $id : '';
    }

    /** Older immutable snapshots kept payload.id only inside raw_body. */
    public static function maxVideoIds(array $snapshot): array
    {
        $media = (array)($snapshot['attachments'] ?? []);
        $raw = $snapshot['raw_body']['body']['attachments'] ?? null;
        $rawMedia = []; $bound = is_array($raw) && array_is_list($raw) && array_is_list($media);
        if ($bound) foreach ($raw as $item) {
            if (!is_array($item) || !is_array($item['payload'] ?? null)) { $bound = false; break; }
            if (($item['type'] ?? '') !== 'inline_keyboard') $rawMedia[] = $item;
        }
        $bound = $bound && count($rawMedia) === count($media);
        if ($bound) foreach ($media as $i => $item) {
            if (!is_array($item) || ($item['provider'] ?? '') !== 'max'
                || ($item['type'] ?? '') !== ($rawMedia[$i]['type'] ?? '')
                || !is_string($item['token'] ?? null) || $item['token'] !== ($rawMedia[$i]['payload']['token'] ?? null)) { $bound = false; break; }
        }
        $ids = [];
        foreach ($media as $i => $item) {
            if (!is_array($item) || ($item['provider'] ?? '') !== 'max' || ($item['type'] ?? '') !== 'video') continue;
            // Never override an explicitly supplied invalid normalized ID.
            $ids[$i] = self::maxVideoId(array_key_exists('video_id', $item)
                ? $item['video_id'] : ($bound ? ($rawMedia[$i]['payload']['id'] ?? null) : null));
        }
        return $ids;
    }

    public static function isChannelUpdate(string $platform, array $update): bool
    {
        if ($platform === 'telegram') return array_key_exists('channel_post', $update) || array_key_exists('edited_channel_post', $update);
        // A channel button callback is a personal interaction by callback.user,
        // even though its referenced message belongs to a channel publication.
        return $platform === 'max' && ($update['update_type'] ?? '') !== 'message_callback'
            && ($update['message']['recipient']['chat_type'] ?? '') === 'channel';
    }

    public static function fromUpdate(string $platform, array $update): ?array
    {
        if (!self::isChannelUpdate($platform, $update)) return null;
        if ($platform === 'max') {
            if (!in_array($update['update_type'] ?? '', ['message_created', 'message_edited'], true)) return null;
            $message = $update['message'] ?? null;
            if (!is_array($message)) return null;
            $snapshot = self::maxSnapshot($message);
            $channelId = self::channelId($message['recipient']['chat_id'] ?? null);
            $messageId = $message['body']['mid'] ?? null;
            if ($snapshot === null || $channelId === '' || !is_string($messageId) || !preg_match('/\A[a-zA-Z0-9_.-]{1,200}\z/', $messageId)) return null;
            // Message.timestamp is creation time; Update.timestamp is event time.
            // Using the latter prevents a delayed older edit replacing new text.
            $revision = self::revisionMilliseconds($update['timestamp'] ?? $message['timestamp'] ?? null);
            if ($revision !== '') $snapshot['metadata']['source_revision_ms'] = $revision;
            if ($update['update_type'] === 'message_edited' && isset($update['timestamp'])) $snapshot['edited_at'] = self::timestamp($update['timestamp'], true);
            $event = [
                'platform' => 'max', 'channel_id' => $channelId, 'external_message_id' => $messageId,
                'snapshot' => $snapshot, 'edited' => $update['update_type'] === 'message_edited',
            ];
        } else {
            $edited = array_key_exists('edited_channel_post', $update);
            $message = $update[$edited ? 'edited_channel_post' : 'channel_post'] ?? null;
            if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'channel') return null;
            $snapshot = self::telegramSnapshot($message);
            $channelId = self::channelId($message['chat']['id'] ?? null);
            $messageId = (string)($message['message_id'] ?? '');
            if ($snapshot === null || $channelId === '' || !preg_match('/\A[1-9][0-9]{0,19}\z/', $messageId)) return null;
            $revision = self::revisionMilliseconds($message['edit_date'] ?? $message['date'] ?? null, false);
            if ($revision !== '') $snapshot['metadata']['source_revision_ms'] = $revision;
            // Telegram edit_date has second precision. Within that same second,
            // its authenticated update_id orders previously unseen edit events.
            $sequence = $update['update_id'] ?? null;
            if ((is_int($sequence) || is_string($sequence)) && preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/', (string)$sequence)) {
                $snapshot['metadata']['source_sequence'] = (string)$sequence;
                $snapshot['metadata']['source_sequence_scope'] = 'telegram_update_id';
            }
            $group = (string)($message['media_group_id'] ?? '');
            if ($group !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $group)) return null;
            $event = [
                'platform' => 'telegram', 'channel_id' => $channelId,
                // A media group has one publication identity and one start token,
                // never an unrelated customer entry for each photo.
                'external_message_id' => $group !== '' ? 'group:' . $group : $messageId,
                'snapshot' => $snapshot, 'edited' => $edited,
            ];
        }
        $encoded = json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_JSON_BYTES) return null;
        $event['source_event_id'] = hash('sha256', $platform . ':' . ($update['update_id'] ?? '') . ':' . $encoded);
        $event['snapshot']['metadata']['source_event_id'] = $event['source_event_id'];
        return $event;
    }

    public static function maxSnapshot(array $message): ?array
    {
        $body = $message['body'] ?? null;
        if (!is_array($body) || !array_key_exists('text', $body) || (!is_string($body['text']) && $body['text'] !== null)) return null;
        $attachments = $body['attachments'] ?? [];
        if (!is_array($attachments)) return null;
        $media = []; $keyboard = []; $reason = ''; $keyboardCount = 0;
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) { $reason = 'invalid_attachment'; continue; }
            $type = $attachment['type'] ?? '';
            $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
            if ($type === 'inline_keyboard') {
                $keyboardCount++;
                if (!is_array($payload['buttons'] ?? null) || $keyboardCount > 1) $reason = 'invalid_keyboard';
                else $keyboard = $payload['buttons'];
                continue;
            }
            $item = ['type' => is_string($type) ? $type : 'unsupported', 'provider' => 'max'];
            foreach (['photo_id', 'token', 'url'] as $key) if (isset($payload[$key]) && is_scalar($payload[$key])) $item[$key] = (string)$payload[$key];
            $media[] = $item;
            if ($type === 'image') {
                if (!preg_match('/\A[0-9]+\z/', (string)($payload['photo_id'] ?? ''))) $reason = 'image_stable_id_missing';
            } elseif ($type === 'video') {
                $media[count($media) - 1]['video_id'] = self::maxVideoId($payload['id'] ?? null);
                if (!self::maxVideoTokenValid($payload['token'] ?? null)) $reason = 'video_token_invalid';
                elseif ($media[count($media) - 1]['video_id'] === '') $reason = 'video_stable_id_missing';
            } else $reason = 'unsupported_media';
        }
        if (count($media) > self::maxPhotoLimit() || count($attachments) > 12) $reason = 'attachment_limit';
        $channelId = self::channelId($message['recipient']['chat_id'] ?? null);
        $url = (string)($message['url'] ?? '');
        if ($url !== '' && !self::maxPostUrlAllowed($url, $channelId, ($message['recipient']['chat_type'] ?? '') === 'channel')) $url = '';
        return [
            'text' => $body['text'] ?? '', 'format' => 'native', 'attachments' => $media,
            'markup' => $body['markup'] ?? [], 'keyboard' => $keyboard,
            'post_url' => $url, 'raw_body' => $message,
            'published_at' => self::timestamp($message['timestamp'] ?? null, true),
            'metadata' => ['capability' => $reason === '' ? 'supported' : 'requires_attention', 'capability_reason' => $reason, 'link' => $message['link'] ?? null],
        ];
    }

    public static function telegramSnapshot(array $message): ?array
    {
        $text = $message['text'] ?? $message['caption'] ?? '';
        if (!is_string($text)) return null;
        $approvedVideo = self::approvedAloftVideo($message, $text);
        $media = []; $reason = ''; $photo = null;
        foreach ((array)($message['photo'] ?? []) as $size) {
            if (!is_array($size) || !is_string($size['file_id'] ?? null) || !is_string($size['file_unique_id'] ?? null)) { $reason = 'image_stable_id_missing'; continue; }
            if ($photo === null || (int)($size['width'] ?? 0) * (int)($size['height'] ?? 0) > (int)($photo['width'] ?? 0) * (int)($photo['height'] ?? 0)) $photo = $size;
        }
        if ($photo !== null) $media[] = ['type' => 'image', 'provider' => 'telegram', 'telegram_file_id' => $photo['file_id'], 'file_unique_id' => $photo['file_unique_id']];
        foreach (['video', 'animation', 'audio', 'voice', 'video_note', 'document', 'sticker', 'contact', 'location', 'venue', 'poll', 'dice', 'paid_media'] as $field) {
            if (!array_key_exists($field, $message)) continue;
            if ($field === 'video' && $approvedVideo !== null) {
                $media[] = $approvedVideo;
                continue;
            }
            $reason = 'unsupported_media';
            $media[] = ['type' => $field, 'provider' => 'telegram'];
        }
        // Telegram delivers each album member separately; the registry merges
        // authenticated members under the shared group identity.
        $keyboard = $message['reply_markup']['inline_keyboard'] ?? [];
        if (!is_array($keyboard)) { $keyboard = []; $reason = 'invalid_keyboard'; }
        $chatId = self::channelId($message['chat']['id'] ?? null);
        $username = (string)($message['chat']['username'] ?? '');
        $messageId = (string)($message['message_id'] ?? '');
        $url = '';
        if (preg_match('/\A[A-Za-z0-9_]{5,64}\z/', $username) && preg_match('/\A[1-9][0-9]*\z/', $messageId)) $url = 'https://t.me/' . $username . '/' . $messageId;
        elseif (preg_match('/\A-100([1-9][0-9]*)\z/', $chatId, $match) && preg_match('/\A[1-9][0-9]*\z/', $messageId)) $url = 'https://t.me/c/' . $match[1] . '/' . $messageId;
        $metadata = ['capability' => $reason === '' ? 'supported' : 'requires_attention', 'capability_reason' => $reason,
            'media_group_id' => (string)($message['media_group_id'] ?? ''), 'actual_message_id' => $messageId];
        if ($approvedVideo !== null && $reason === '') $metadata['hotel_catalog_id'] = 61159;
        return [
            'text' => $text, 'format' => 'native', 'attachments' => $media,
            'markup' => $message['entities'] ?? $message['caption_entities'] ?? [], 'keyboard' => $keyboard,
            'post_url' => $url, 'raw_body' => $message,
            'published_at' => self::timestamp($message['date'] ?? null, false),
            'edited_at' => self::timestamp($message['edit_date'] ?? null, false),
            'metadata' => $metadata,
        ];
    }

    /**
     * A public observation is a preview baseline, never a native Telegram event.
     * Its media path is public evidence; it is not a Telegram file identifier.
     */
    public static function aloftPublicSnapshot(string $html): array
    {
        if ($html === '' || strlen($html) > 4000000 || !mb_check_encoding($html, 'UTF-8'))
            throw new InvalidArgumentException('aloft_public_html_invalid');
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING))
            throw new InvalidArgumentException('aloft_public_html_invalid');
        $xpath = new DOMXPath($dom);
        $hasClass = static fn(string $name): string => 'contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")';
        $messages = $xpath->query('//*[@data-post="anytour/1678" and '.$hasClass('tgme_widget_message').']');
        if ($messages->length !== 1) throw new InvalidArgumentException('aloft_public_identity_mismatch');
        $root = $messages->item(0);
        $find = static fn(string $class) => $xpath->query('.//*['.$hasClass($class).']', $root);
        $texts = $find('tgme_widget_message_text');
        $players = $find('tgme_widget_message_video_player');
        $videos = $xpath->query('.//video', $root);
        $durations = $find('message_video_duration');
        $dates = $find('tgme_widget_message_date');
        if ($texts->length !== 1 || $players->length !== 1 || $videos->length !== 1 || $durations->length !== 1
            || $dates->length !== 1 || $players->item(0)->getAttribute('href') !== 'https://t.me/anytour/1678'
            || $dates->item(0)->getAttribute('href') !== 'https://t.me/anytour/1678')
            throw new InvalidArgumentException('aloft_public_structure_mismatch');
        foreach (['tgme_widget_message_photo_wrap', 'tgme_widget_message_grouped', 'tgme_widget_message_grouped_wrap',
            'grouped_media_wrap', 'tgme_widget_message_document_wrap', 'tgme_widget_message_audio',
            'tgme_widget_message_voice'] as $class) {
            if ($find($class)->length !== 0) throw new InvalidArgumentException('aloft_public_media_mismatch');
        }
        if ($root->hasAttribute('data-group') || $root->hasAttribute('data-media-group-id')
            || $xpath->query('.//*[@data-group or @data-media-group-id]', $root)->length !== 0
            || trim($durations->item(0)->textContent) !== '0:28')
            throw new InvalidArgumentException('aloft_public_media_mismatch');
        $times = $xpath->query('.//time', $dates->item(0));
        if ($times->length !== 1 || !in_array($times->item(0)->getAttribute('datetime'),
            ['2026-08-11T11:51:56+00:00', '2026-08-11T11:51:56Z'], true))
            throw new InvalidArgumentException('aloft_public_date_mismatch');
        $media = parse_url($videos->item(0)->getAttribute('src'));
        if (!is_array($media) || ($media['scheme'] ?? '') !== 'https'
            || !preg_match('/\Acdn[1-9][0-9]*\.telesco\.pe\z/D', $media['host'] ?? '')
            || !preg_match('~\A/file/[A-Za-z0-9_-]{1,128}\.mp4\z~D', $media['path'] ?? '')
            || isset($media['user']) || isset($media['pass']) || isset($media['port']) || isset($media['fragment']))
            throw new InvalidArgumentException('aloft_public_media_mismatch');
        $caption = '';
        foreach ($texts->item(0)->childNodes as $node) $caption .= $dom->saveHTML($node);
        $caption = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $caption)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $keyboards = $find('tgme_widget_message_inline_keyboard');
        if ($keyboards->length > 1) throw new InvalidArgumentException('aloft_public_keyboard_invalid');
        $keyboard = [];
        $buttonCount = 0;
        if ($keyboards->length === 1) {
            $rows = $xpath->query('./*['.$hasClass('tgme_widget_message_inline_row').']', $keyboards->item(0));
            foreach ($rows as $row) {
                $buttons = [];
                foreach ($xpath->query('./a['.$hasClass('tgme_widget_message_inline_button').' and '.$hasClass('url_button').']', $row) as $button) {
                    $buttons[] = ['text'=>trim($button->textContent), 'url'=>$button->getAttribute('href')];
                    $buttonCount++;
                }
                $keyboard[] = $buttons;
            }
        }
        if ($find('tgme_widget_message_inline_button')->length !== $buttonCount)
            throw new InvalidArgumentException('aloft_public_keyboard_invalid');
        $snapshot = [
            'text'=>$caption, 'title'=>'Aloft Palm Jumeirah', 'format'=>'public_html',
            'attachments'=>[['type'=>'video', 'provider'=>'telegram', 'duration'=>28, 'public_media_path'=>$media['path']]],
            'markup'=>[], 'keyboard'=>$keyboard, 'post_url'=>'https://t.me/anytour/1678', 'raw_body'=>[],
            'published_at'=>'2026-08-11 11:51:56', 'edited_at'=>'',
            'metadata'=>['capability'=>'requires_attention', 'capability_reason'=>'manual_approval_required',
                'observation_kind'=>'public_read', 'actual_message_id'=>'1678', 'channel_id'=>'-1001845835645',
                'hotel_catalog_id'=>61159, 'checked_at'=>gmdate('c')],
        ];
        self::aloftPublicFingerprint($snapshot);
        return $snapshot;
    }

    /** Bind the complete public preview, excluding only the recording time. */
    public static function aloftPublicFingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(self::aloftPublicFields($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** A later public read can compare the body separately from its replaced keyboard. */
    public static function aloftPublicContentFingerprint(array $snapshot): string
    {
        $fields = self::aloftPublicFields($snapshot);
        unset($fields['keyboard']);
        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Compare native after-evidence with the honest public baseline, without inventing a native before ID. */
    public static function aloftNativeComparison(array $message, array $publicBaseline): array
    {
        self::aloftPublicFields($publicBaseline);
        $chatId = $message['chat']['id'] ?? null;
        $identity = ($message['chat']['type'] ?? '') === 'channel'
            && (is_int($chatId) || is_string($chatId)) && (string)$chatId === '-1001845835645'
            && ($message['chat']['username'] ?? '') === 'anytour' && ($message['message_id'] ?? null) === 1678;
        $caption = ($message['caption'] ?? null) === $publicBaseline['text'] && !array_key_exists('text', $message);
        $date = ($message['date'] ?? null) === 1786449116;
        $video = $message['video'] ?? null;
        $media = is_array($video) && (is_int($video['duration'] ?? null) || is_float($video['duration'] ?? null))
            && (float)$video['duration'] === 28.0 && is_string($video['file_id'] ?? null)
            && trim($video['file_id']) !== '' && strlen($video['file_id']) <= 1024
            && !preg_match('/[\x00-\x20\x7f]/', $video['file_id']) && is_string($video['file_unique_id'] ?? null)
            && preg_match('/\A[A-Za-z0-9_-]{1,191}\z/D', $video['file_unique_id']) === 1;
        foreach (['media_group_id', 'photo', 'animation', 'audio', 'voice', 'video_note', 'document',
            'sticker', 'contact', 'location', 'venue', 'poll', 'dice', 'paid_media'] as $field)
            if (array_key_exists($field, $message)) $media = false;
        $result = ['matches'=>$identity && $caption && $date && $media, 'message_identity_matched'=>$identity,
            'public_caption_matched'=>$caption, 'publication_date_matched'=>$date, 'media_type_duration_matched'=>$media,
            'native_media_identity_before_verified'=>false];
        if ($media) $result['native_video_unique_id'] = $video['file_unique_id'];
        return $result;
    }

    private static function aloftPublicFields(array $snapshot): array
    {
        $metadata = $snapshot['metadata'] ?? null;
        $media = $snapshot['attachments'] ?? null;
        if (!is_array($metadata) || !is_array($media) || !array_is_list($media) || count($media) !== 1
            || !is_array($media[0]) || count($media[0]) !== 4
            || ($media[0]['type'] ?? null) !== 'video' || ($media[0]['provider'] ?? null) !== 'telegram'
            || ($media[0]['duration'] ?? null) !== 28 || !is_string($media[0]['public_media_path'] ?? null)
            || !preg_match('~\A/file/[A-Za-z0-9_-]{1,128}\.mp4\z~D', $media[0]['public_media_path'])
            || ($snapshot['format'] ?? null) !== 'public_html' || ($snapshot['title'] ?? null) !== 'Aloft Palm Jumeirah'
            || ($snapshot['post_url'] ?? null) !== 'https://t.me/anytour/1678'
            || ($snapshot['published_at'] ?? null) !== '2026-08-11 11:51:56'
            || ($snapshot['edited_at'] ?? '') !== '' || ($snapshot['media_group_id'] ?? '') !== ''
            || ($snapshot['raw_body'] ?? null) !== [] || ($snapshot['markup'] ?? null) !== []
            || ($metadata['capability'] ?? null) !== 'requires_attention'
            || ($metadata['capability_reason'] ?? null) !== 'manual_approval_required'
            || ($metadata['observation_kind'] ?? null) !== 'public_read'
            || ($metadata['actual_message_id'] ?? null) !== '1678'
            || ($metadata['channel_id'] ?? null) !== '-1001845835645' || ($metadata['hotel_catalog_id'] ?? null) !== 61159
            || ($metadata['media_group_id'] ?? '') !== ''
            || !is_string($snapshot['text'] ?? null) || !mb_check_encoding($snapshot['text'], 'UTF-8')
            || mb_strlen($snapshot['text'], 'UTF-8') > 12000 || !str_contains($snapshot['text'], 'Aloft Palm Jumeirah'))
            throw new InvalidArgumentException('aloft_public_baseline_invalid');
        $keyboard = $snapshot['keyboard'] ?? null;
        if (!is_array($keyboard) || !array_is_list($keyboard) || count($keyboard) > 8)
            throw new InvalidArgumentException('aloft_public_keyboard_invalid');
        foreach ($keyboard as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) < 1 || count($row) > 8)
                throw new InvalidArgumentException('aloft_public_keyboard_invalid');
            foreach ($row as $button) {
                if (!is_array($button) || count($button) !== 2 || !is_string($button['text'] ?? null)
                    || $button['text'] === '' || !mb_check_encoding($button['text'], 'UTF-8')
                    || mb_strlen($button['text'], 'UTF-8') > 128 || preg_match('/[\x00-\x1f\x7f]/u', $button['text'])
                    || !is_string($button['url'] ?? null) || strlen($button['url']) > 2048
                    || !preg_match('~\Ahttps://[^\x00-\x20\x7f]+\z~D', $button['url']))
                    throw new InvalidArgumentException('aloft_public_keyboard_invalid');
                $url = parse_url($button['url']);
                if (!is_array($url) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['port']))
                    throw new InvalidArgumentException('aloft_public_keyboard_invalid');
            }
        }
        return [
            'text'=>$snapshot['text'], 'title'=>'Aloft Palm Jumeirah', 'format'=>'public_html',
            'post_url'=>'https://t.me/anytour/1678', 'published_at'=>'2026-08-11 11:51:56',
            'media'=>['type'=>'video', 'provider'=>'telegram', 'duration'=>28, 'public_media_path'=>$media[0]['public_media_path']],
            'keyboard'=>$keyboard, 'observation_kind'=>'public_read', 'channel_id'=>'-1001845835645',
            'actual_message_id'=>'1678', 'hotel_catalog_id'=>61159,
        ];
    }

    /** The explicit Aloft archive edit preserves this one existing video. */
    private static function approvedAloftVideo(array $message, string $caption): ?array
    {
        $video = $message['video'] ?? null;
        if (($message['chat']['type'] ?? null) !== 'channel'
            || self::channelId($message['chat']['id'] ?? null) !== '-1001845835645'
            || ($message['chat']['username'] ?? null) !== 'anytour'
            || ($message['message_id'] ?? null) !== 1207
            || ($message['date'] ?? null) !== 1758623277
            || array_key_exists('media_group_id', $message) || array_key_exists('photo', $message)
            || !is_array($video) || !is_string($video['file_id'] ?? null) || trim($video['file_id']) === ''
            || !is_string($video['file_unique_id'] ?? null) || trim($video['file_unique_id']) === '') return null;

        // The old caption is ineligible. Only the new hotel-specific caption
        // with a monitored offer enters the normal button flow.
        if (!preg_match('/\AAloft Palm Jumeirah\r?\n/u', $caption)
            || !preg_match('/^💰 От [0-9][0-9 \x{00A0}\x{202F}]* ₽/mu', $caption)
            || !preg_match('/^Цена найдена мониторингом [0-9]{2}\.[0-9]{2}\.[0-9]{4}, [0-9]{2}:[0-9]{2} МСК/mu', $caption)) return null;

        return ['type' => 'video', 'provider' => 'telegram', 'telegram_file_id' => $video['file_id'],
            'file_unique_id' => $video['file_unique_id']];
    }

    /** Tour content excludes expiring credentials and all replaceable channel keyboards. */
    public static function contentFingerprint(array $snapshot, string $ownedButtonUrl = '', string $botUrl = ''): string
    {
        $media = [];
        $videoIds = self::maxVideoIds($snapshot);
        foreach ((array)($snapshot['attachments'] ?? []) as $i => $item) {
            if (!is_array($item)) continue;
            $stableId = (string)($item['photo_id'] ?? $item['file_unique_id'] ?? $item['telegram_file_id'] ?? '');
            if (($item['provider'] ?? '') === 'max' && ($item['type'] ?? '') === 'video') {
                // Only payload.id identifies the clip. Token and URL credentials
                // rotate independently. An unresolved old snapshot retains its
                // exact-token fingerprint; unrelated unknown clips never merge.
                $id = $videoIds[$i] ?? '';
                $stableId = $id !== '' ? 'video-id:' . $id
                    : (self::maxVideoTokenValid($item['token'] ?? null) ? 'video:' . hash('sha256', $item['token']) : '');
            }
            $media[] = [
                'type' => $item['type'] ?? '',
                'stable_id' => $stableId,
            ];
        }
        // The owner requests only our button on MAX and Telegram. A comment
        // bot's keyboard is not tour content and cannot advance its version.
        $keyboard = [];
        return hash('sha256', (string)json_encode([
            $snapshot['text'] ?? '', $snapshot['markup'] ?? null, $media, $keyboard,
            $snapshot['metadata']['link'] ?? null, $snapshot['metadata']['media_group_id'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function withoutOwnedButton(array $keyboard, string $ownedButtonUrl = '', string $botUrl = ''): array
    {
        $out = [];
        foreach ($keyboard as $row) {
            if (!is_array($row)) { $out[] = $row; continue; }
            $remaining = [];
            foreach ($row as $button) {
                $url = is_array($button) && is_string($button['url'] ?? null) ? $button['url'] : '';
                $owned = $url !== '' && ($url === $ownedButtonUrl || ($botUrl !== '' && preg_match('/\A' . preg_quote(rtrim($botUrl, '/') . '?start=', '/') . 'p_[A-Za-z0-9_-]{8,60}\z/', $url)));
                if (!$owned) $remaining[] = $button;
            }
            if ($remaining !== []) $out[] = $remaining;
        }
        return $out;
    }

    public static function containsButton(array $snapshot, string $url, string $label): bool
    {
        $count = 0;
        foreach ((array)($snapshot['keyboard'] ?? []) as $row) foreach ((array)$row as $button) {
            if (is_array($button) && ($button['url'] ?? '') === $url && ($button['text'] ?? '') === $label && (!isset($button['type']) || $button['type'] === 'link')) $count++;
        }
        return $count === 1;
    }

    /** A mixed keyboard is not the owner-requested final publication state. */
    public static function containsOnlyButton(array $snapshot, string $url, string $label): bool
    {
        $rows = $snapshot['keyboard'] ?? [];
        return is_array($rows) && count($rows) === 1 && isset($rows[0]) && is_array($rows[0])
            && count($rows[0]) === 1 && self::containsButton($snapshot, $url, $label);
    }

    public static function channelId($value): string
    {
        if (!is_int($value) && !is_string($value)) return '';
        $value = (string)$value;
        return preg_match('/\A-[1-9][0-9]{0,19}\z/', $value) ? $value : '';
    }

    private static function timestamp($value, bool $milliseconds): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]{1,16}\z/', (string)$value)) return '';
        $seconds = $milliseconds ? (int)floor((float)$value / 1000) : (int)$value;
        return $seconds > 0 ? gmdate('Y-m-d H:i:s', $seconds) : '';
    }

    private static function revisionMilliseconds($value, bool $milliseconds = true): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,15}\z/', (string)$value)) return '';
        return $milliseconds ? (string)$value : (string)((int)$value * 1000);
    }

    private static function maxPostUrlAllowed(string $url, string $channelId, bool $channelMessage): bool
    {
        if ($channelId === '' || strlen($url) > 300) return false;
        if (preg_match('/\Ahttps:\/\/max\.ru\/c\/' . preg_quote($channelId, '/') . '\/[A-Za-z0-9_-]{1,100}\z/D', $url)) return true;
        // MAX also reports public-slug permalinks in the authenticated message
        // envelope. Keep that exact URL as metadata, like Telegram's username;
        // never infer a channel ID from it. Registry admission still uses only
        // the message's exact recipient ID and the registered project/bot.
        return $channelMessage && (bool)preg_match('/\Ahttps:\/\/max\.ru\/[A-Za-z][A-Za-z0-9_]{0,63}\/[A-Za-z0-9_-]{1,100}\z/D', $url);
    }
}


