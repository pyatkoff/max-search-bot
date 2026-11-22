<?php
declare(strict_types=1);

/**
 * Captions for confirmed promoted @anytour hotel publications.
 * Server CLI only. Consumes the public combined feed under the original PRICE
 * lock. One unknown provider edit fences the affected publication only.
 */
final class PromotedHotelPriceWriter
{
    private const MAX_CHANNEL = '-68765999669267';
    private const TG_CHANNEL = '-1001845835645';
    private const TARGET_FILE = __DIR__.'/../config/promoted_hotel_price_targets.json';
    private const PRICE_FOOTER = ' Стоимость и наличие могут измениться.';
    private const PRICE_PREFIX = '💰 От ';
    private array $channelsVerified = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ChannelPostRegistryService $registry,
        private readonly string $journal,
        private readonly ?Closure $publicReader = null,
    ) {}

    /** Protect original HTML formatting. New price suffix contains plain text. */
    public static function maxHtml(string $old, string $new, array $markup): string
    {
        $old16 = mb_convert_encoding($old, 'UTF-16LE', 'UTF-8');
        $new16 = mb_convert_encoding($new, 'UTF-16LE', 'UTF-8');
        self::need(array_is_list($markup) && count($markup) <= 100, 'invalid_max_markup');
        usort($markup, static fn(array $a, array $b): int => ($a['from'] ?? -1) <=> ($b['from'] ?? -1));
        $tags = ['strong'=>'b', 'emphasized'=>'i', 'underline'=>'u', 'strikethrough'=>'s', 'monospaced'=>'code', 'link'=>'a'];
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $slice = static function (string $text, int $offset, int $length): string {
            $bytes = substr($text, $offset * 2, $length * 2);
            self::need(mb_check_encoding($bytes, 'UTF-16LE'), 'markup_splits_unicode');
            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
        };
        $cursor = 0;
        $result = '';
        foreach ($markup as $span) {
            $type = $span['type'] ?? null;
            $offset = $span['from'] ?? null;
            $length = $span['length'] ?? null;
            self::need(is_string($type) && isset($tags[$type]) && is_int($offset) && is_int($length)
                && $offset >= $cursor && $length > 0 && $offset + $length <= strlen($old16) / 2
                && $offset + $length <= strlen($new16) / 2, 'unsupported_max_markup');
            $value = $slice($old16, $offset, $length);
            self::need($value === $slice($new16, $offset, $length), 'price_overlaps_existing_markup');
            $result .= $escape($slice($new16, $cursor, $offset - $cursor));
            $attr = '';
            if ($type === 'link') {
                $url = $span['url'] ?? null;
                self::need(is_string($url) && preg_match('~\Ahttps?://[^\x00-\x20\x7f]+\z~D', $url) === 1, 'invalid_max_markup_link');
                $attr = ' href="'.$escape($url).'"';
            }
            $tag = $tags[$type];
            $result .= '<'.$tag.$attr.'>'.$escape($value).'</'.$tag.'>';
            $cursor = $offset + $length;
        }
        return $result.$escape($slice($new16, $cursor, intdiv(strlen($new16), 2) - $cursor));
    }

    /** No guessing at a previous price or changes to the editorial body. */
    public static function revision(string $old, string $block, bool $telegram): string
    {
        $prefix = $old;
        $split = strrpos($old, "\n\n".self::PRICE_PREFIX);
        if ($split !== false) {
            $previous = substr($old, $split + 2);
            self::need(self::completePrice($previous), 'unexpected_existing_price_block');
            if (self::observed($previous) > self::observed($block)) return $old;
            $prefix = substr($old, 0, $split);
        }
        self::need($prefix !== '' && !preg_match('/Цена найдена мониторингом|^\s*💰|[0-9][ \x{00a0}\x{202f}]*₽/mu', $prefix)
            && self::completePrice($block), 'unexpected_caption_or_price');
        $new = $prefix."\n\n".$block;
        self::need(!$telegram || strlen(mb_convert_encoding($new, 'UTF-16LE', 'UTF-8')) <= 2048, 'telegram_caption_limit');
        return $new;
    }

    private static function completePrice(string $block): bool
    {
        $lines = explode("\n", $block);
        return count($lines) === 6 && str_starts_with($lines[0], self::PRICE_PREFIX)
            && str_starts_with($lines[1], '✈️ Вылет из ') && str_starts_with($lines[2], '👥 ')
            && str_starts_with($lines[3], '🛏 ') && str_starts_with($lines[4], '🍽 ')
            && str_starts_with($lines[5], 'Цена найдена мониторингом ') && str_ends_with($block, self::PRICE_FOOTER);
    }

    private static function observed(string $text): int
    {
        self::need(preg_match('/^Цена найдена мониторингом ([0-9.]{10}, [0-9:]{5}) МСК\./mu', $text, $match) === 1, 'observation_missing');
        $stamp = DateTimeImmutable::createFromFormat('!d.m.Y, H:i', $match[1], new DateTimeZone('Europe/Moscow'));
        self::need($stamp instanceof DateTimeImmutable && $stamp->format('d.m.Y, H:i') === $match[1], 'invalid_observation');
        return $stamp->getTimestamp();
    }

    /** Validate the entire plan before the first native edit, including hotels held for lack of a price. */
    public static function jobsForPlan(array $plan, string $feedHash, string $targetBytes): array
    {
        self::need(($plan['targets_sha256'] ?? null) === hash('sha256', $targetBytes)
            && ($plan['feed_sha256'] ?? null) === $feedHash, 'price_plan_mismatch');
        $rows = json_decode($targetBytes, true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($rows) && array_is_list($rows) && $rows !== [], 'invalid_promoted_targets');
        $targets = $usedIds = $usedUrls = [];
        $patterns = ['max'=>'~\Ahttps://max\.ru/anytour/[A-Za-z0-9_-]{1,100}\z~D',
            'telegram'=>'~\Ahttps://t\.me/anytour/[1-9][0-9]*\z~D'];
        foreach ($rows as $row) {
            $hotel = $row['hotel_id'] ?? null;
            $platform = $row['platform'] ?? null;
            $id = $row['publication_id'] ?? null;
            $url = $row['url'] ?? null;
            self::need(is_array($row) && count($row) === 4 && is_int($hotel) && $hotel > 0
                && is_int($id) && $id > 0 && is_string($platform) && isset($patterns[$platform])
                && is_string($url) && preg_match($patterns[$platform], $url) === 1, 'invalid_promoted_target');
            self::need(!isset($targets[$hotel][$platform]) && !isset($usedIds[$id]) && !isset($usedUrls[$url]),
                'duplicate_promoted_target');
            $targets[$hotel][$platform] = $row;
            $usedIds[$id] = $usedUrls[$url] = true;
        }
        self::need(($plan['target_hotels'] ?? null) === count($targets)
            && is_array($plan['plans'] ?? null) && array_is_list($plan['plans'])
            && is_array($plan['held'] ?? null) && array_is_list($plan['held'])
            && ($plan['eligible_hotels'] ?? null) === count($plan['plans']), 'price_plan_mismatch');
        foreach ($plan['held'] as $held) {
            $hotel = $held['hotel_id'] ?? null;
            self::need(is_int($hotel) && isset($targets[$hotel]) && is_string($held['reason'] ?? null)
                && $held['reason'] !== '', 'price_plan_mismatch');
            unset($targets[$hotel]);
        }
        $jobs = [];
        foreach ($plan['plans'] as $row) {
            $hotel = $row['hotel_id'] ?? null;
            self::need(is_int($hotel) && isset($targets[$hotel]) && is_string($row['price_block'] ?? null)
                && self::completePrice($row['price_block']), 'price_plan_mismatch');
            $bindingFields = preg_grep('/_(?:url|publication_id)\z/D', array_keys($row));
            self::need(count($bindingFields) === 2 * count($targets[$hotel]), 'price_plan_mismatch');
            foreach ($patterns as $platform => $_) {
                $urlKey = $platform.'_url';
                $idKey = $platform.'_publication_id';
                $target = $targets[$hotel][$platform] ?? null;
                if ($target === null) {
                    self::need(!array_key_exists($urlKey, $row) && !array_key_exists($idKey, $row), 'price_plan_mismatch');
                    continue;
                }
                self::need(($row[$urlKey] ?? null) === $target['url']
                    && ($row[$idKey] ?? null) === $target['publication_id'], 'price_plan_mismatch');
                $jobs[] = ['hotel'=>$hotel, 'platform'=>$platform, 'publication'=>$target['publication_id'],
                    'url'=>$target['url'], 'price_block'=>$row['price_block']];
            }
            unset($targets[$hotel]);
        }
        self::need($targets === [], 'price_plan_mismatch');
        return $jobs;
    }

    public function run(array $plan, string $feedHash, bool $apply): array
    {
        $targetBytes = file_get_contents(self::TARGET_FILE);
        self::need(is_string($targetBytes), 'promoted_targets_unavailable');
        $out = [];
        foreach (self::jobsForPlan($plan, $feedHash, $targetBytes) as $item) {
            $block = $item['price_block'];
            unset($item['price_block']);
            $attempted = false;
            try {
                $item += $this->update($item['publication'], $item['platform'], $item['url'], $item['hotel'],
                    $block, $apply, $attempted);
            } catch (Throwable $error) {
                $reason = $error->getMessage();
                $item += ['result'=>$attempted ? 'unknown_after_write' : 'held_before_write',
                    'reason'=>preg_match('/^[a-z0-9_]{1,100}$/D', $reason) ? $reason : 'native_editor_error'];
            }
            $out[] = $item;
            if ($attempted) usleep(1000000);
        }
        return $out;
    }

    private function update(int $id, string $platform, string $url, int $hotel, string $block, bool $apply, bool &$attempted): array
    {
        $post = $this->registry->publication($id);
        $channel = $platform === 'max' ? self::MAX_CHANNEL : self::TG_CHANNEL;
        self::need(is_array($post) && $post['project_key'] === 'anytour' && $post['platform'] === $platform
            && $post['channel_id'] === $channel && $post['snapshot']['post_url'] === $url, 'original_publication_mismatch');
        $mid = $post['external_message_id'];
        $snapshot = $post['snapshot'];
        $canonicalId = $snapshot['metadata']['hotel_catalog_id'] ?? null;
        self::need($canonicalId === null || (int)$canonicalId === $hotel, 'original_hotel_mismatch');
        $check = $this->pdo->prepare("SELECT COUNT(*) FROM post_versions WHERE publication_id=? AND (
            state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)");
        $check->execute([$id]);
        self::need($post['state'] === 'sent' && (int)$check->fetchColumn() === 0, 'original_operation_held');

        $prior = $this->readJournal($id);
        if (($prior['state'] ?? null) === 'started') throw new RuntimeException('previous_price_edit_unknown');
        $observed = self::observed($block);
        self::need($observed <= time() && time() - $observed < 129600, 'offer_expired_before_write');
        $published = $this->readPublicPost($platform, $url);
        self::need($published['caption'] === $snapshot['text'], 'public_caption_differs_from_registry');
        $new = self::revision($snapshot['text'], $block, $platform === 'telegram');
        if ($new === $snapshot['text']) {
            // A delayed public read closes the original provider receipt, never a new edit.
            if (($prior['state'] ?? null) === 'provider_verified') {
                self::need(($prior['platform'] ?? null) === $platform && ($prior['hotel'] ?? null) === $hotel
                    && ($prior['url'] ?? null) === $url
                    && is_int($prior['version'] ?? null) && $prior['version'] > 0
                    && ($prior['new_version'] ?? null) === $post['version_id']
                    && ($prior['next_hash'] ?? null) === hash('sha256', $snapshot['text'])
                    && is_int($prior['at'] ?? null) && $prior['at'] > 0
                    && ($snapshot['metadata']['source_event_id'] ?? null) === 'promoted-price:'.$id.':'.$prior['at']
                    && ($snapshot['metadata']['observation_kind'] ?? null) === 'authorized_admin_content_edit',
                    'previous_price_receipt_mismatch');
                if ($platform === 'telegram') {
                    $buttonUrl = ChannelPostButtonTransport::buttonUrl($post);
                    $buttonText = (string)$post['channel']['button_text'];
                    self::need($buttonUrl !== '' && $buttonText !== '', 'canonical_button_missing');
                    self::need($published['buttons'] === [['text'=>$buttonText, 'url'=>$buttonUrl]],
                        'public_telegram_button_unverified');
                }
                if ($apply) {
                    $fresh = $this->registry->publication($id);
                    $check->closeCursor();
                    $check->execute([$id]);
                    self::need($fresh === $post, 'publication_changed_before_reconcile');
                    self::need((int)$check->fetchColumn() === 0, 'publication_claim_changed_before_reconcile');
                    $prior['state'] = 'verified';
                    $this->writeJournal($id, $prior);
                }
            }
            return ['result'=>'already_current', 'public_verified'=>true];
        }
        $channelKey = $platform.':'.(int)$post['channel']['config_version'];
        if (!isset($this->channelsVerified[$channelKey])) {
            $config = $post['channel'];
            if ($platform === 'max') $config['expected_public_url'] = 'https://max.ru/anytour';
            $this->channelsVerified[$channelKey] = (new ChannelPostButtonTransport())->verifyChannel($config)['state'] === 'ready';
        }
        self::need($this->channelsVerified[$channelKey], 'channel_edit_rights_unverified');
        $photo = self::photo($snapshot, $platform);
        $buttonUrl = ChannelPostButtonTransport::buttonUrl($post);
        $buttonText = (string)$post['channel']['button_text'];
        self::need($buttonUrl !== '' && $buttonText !== '', 'canonical_button_missing');
        $markup = $snapshot['markup'];
        $forward = null;

        if ($platform === 'max') {
            $native = self::readMax($mid, $channel);
            self::need(($native['text'] ?? null) === $snapshot['text'] && $native['post_url'] === $url
                && self::photo($native, $platform) === $photo
                && ChannelPostIncomingAdapter::containsOnlyButton($native, $buttonUrl, $buttonText), 'native_max_changed');
            $forward = $native['metadata']['link'] ?? null;
            $markup = $native['markup'];
            $body = ['text'=>self::maxHtml($native['text'], $new, $markup), 'format'=>'html'];
        } else {
            $button = [['text'=>$buttonText, 'url'=>$buttonUrl]];
            self::need($published['buttons'] === $button, 'public_telegram_button_unverified');
            $old16 = mb_convert_encoding($snapshot['text'], 'UTF-16LE', 'UTF-8');
            $new16 = mb_convert_encoding($new, 'UTF-16LE', 'UTF-8');
            foreach ($markup as $span) {
                $offset = $span['offset'] ?? null;
                $length = $span['length'] ?? null;
                self::need(is_int($offset) && $offset >= 0 && is_int($length) && $length > 0
                    && ($offset + $length) * 2 <= min(strlen($old16), strlen($new16))
                    && substr($old16, $offset * 2, $length * 2) === substr($new16, $offset * 2, $length * 2),
                    'price_overlaps_existing_markup');
            }
            $body = ['caption'=>$new, 'caption_entities'=>$markup, 'reply_markup'=>['inline_keyboard'=>[$button]]];
        }
        if (!$apply) return ['result'=>'eligible', 'public_verified'=>true];
        $fresh = $this->registry->publication($id);
        $check->closeCursor();
        $check->execute([$id]);
        self::need($fresh === $post && time() - $observed < 129600, 'publication_changed_before_edit');
        self::need((int)$check->fetchColumn() === 0, 'publication_claim_changed_before_edit');
        $requestAt = (int)floor(microtime(true) * 1000);
        $record = ['state'=>'started','platform'=>$platform,'hotel'=>$hotel,'url'=>$url,'version'=>$post['version_id'],
            'at'=>$requestAt,'next_hash'=>hash('sha256',$new)];
        $this->writeJournal($id, $record);
        $attempted = true;
        $response = ChannelPostApiTransport::captionRequest($platform, $channel, $mid, $body);

        if ($platform === 'telegram') {
            $message = $response['data']['result'] ?? null;
            self::need(!$response['transport_error'] && $response['http'] === 200
                && ($response['data']['ok'] ?? null) === true && is_array($message)
                && (string)($message['chat']['id'] ?? '') === $channel && (string)($message['message_id'] ?? '') === $mid
                && ($message['chat']['username'] ?? '') === 'anytour' && (int)($message['edit_date'] ?? 0) > 0,
                'telegram_caption_response_unverified');
            $after = ChannelPostIncomingAdapter::telegramSnapshot($message);
            $revision = (int)$message['edit_date'] * 1000;
        } else {
            $after = self::readMax($mid, $channel);
            $revision = $requestAt;
        }

        self::need(is_array($after) && ($after['text'] ?? null) === $new && $after['post_url'] === $url
            && self::photo($after, $platform) === $photo && ($after['metadata']['link'] ?? null) === $forward
            && self::sameMarkup($after['markup'] ?? [], $markup)
            && ChannelPostIncomingAdapter::containsOnlyButton($after, $buttonUrl, $buttonText), 'provider_after_edit_mismatch');
        $after['metadata'] = array_replace($snapshot['metadata'], $after['metadata']);
        unset($after['metadata']['source_sequence'], $after['metadata']['source_sequence_scope']);
        $after['metadata']['source_event_id'] = 'promoted-price:'.$id.':'.$requestAt;
        $after['metadata']['observation_kind'] = 'authorized_admin_content_edit';
        $after['metadata']['source_revision_ms'] = (string)$revision;
        $after['metadata']['promoted_price_observed_at'] = gmdate(DATE_ATOM,$observed);
        $after['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($after, '', (string)$post['channel']['bot_url']);
        $saved = $this->registry->registerPublication('anytour', $platform, $post['bot_key'], $channel, $mid, $after);
        self::need(is_array($saved) && (int)$saved['publication_id'] === $id
            && $saved['start_payload'] === $post['start_payload'] && $saved['snapshot']['text'] === $new, 'registered_revision_mismatch');
        if ($saved['state'] !== 'sent') {
            self::need($saved['state'] === 'pending' && $this->registry->confirmObservedButton($id, (int)$saved['version_id'], [
                'verified'=>true, 'button_verified'=>true, 'content_preserved'=>true, 'write_attempts'=>0,
            ]), 'registry_confirmation_mismatch');
        }
        $record['state'] = 'provider_verified';
        $record['new_version'] = (int)$saved['version_id'];
        $this->writeJournal($id, $record);
        for ($read = 0; $read < 3; $read++) {
            if ($read > 0) sleep(2);
            try {
                if ($this->readPublicPost($platform, $url)['caption'] === $new) {
                    $record['state'] = 'verified';
                    $this->writeJournal($id, $record);
                    return ['result'=>'updated', 'public_verified'=>true, 'observed_at'=>gmdate(DATE_ATOM,$observed)];
                }
            } catch (Throwable) {
                // Retry only the public read.
            }
        }
        return ['result'=>'provider_verified_public_pending', 'public_verified'=>false];
    }

    private static function readMax(string $mid, string $channel): array
    {
        $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/'.$mid, [], null);
        $data = $response['data'] ?? [];
        self::need(!$response['transport_error'] && $response['http'] === 200 && ($data['body']['mid'] ?? null) === $mid
            && (string)($data['recipient']['chat_id'] ?? '') === $channel && ($data['recipient']['chat_type'] ?? '') === 'channel',
            'max_read_unverified');
        $snapshot = ChannelPostIncomingAdapter::maxSnapshot($data);
        self::need(is_array($snapshot), 'max_snapshot_invalid');
        return $snapshot;
    }

    private static function photo(array $post, string $platform): string
    {
        $media = $post['attachments'] ?? [];
        $key = $platform === 'max' ? 'photo_id' : 'file_unique_id';
        $id = $media[0][$key] ?? null;
        self::need(count($media) === 1 && ($media[0]['provider'] ?? '') === $platform
            && ($media[0]['type'] ?? '') === 'image' && is_string($id) && $id !== '', 'original_photo_unverified');
        return $id;
    }

    private static function sameMarkup(array $a, array $b): bool
    {
        foreach ([$a,$b] as $spans) {
            if (!array_is_list($spans)) return false;
        }
        $canonical = static function (array $spans): array {
            $keys = [];
            foreach ($spans as $span) {
                ksort($span);
                $keys[] = json_encode($span, JSON_THROW_ON_ERROR);
            }
            sort($keys);
            return $keys;
        };
        return $canonical($a) === $canonical($b);
    }

    private function readPublicPost(string $platform, string $url): array
    {
        return $this->publicReader !== null
            ? ($this->publicReader)($platform, $url) : self::publicPost($platform, $url);
    }

    private static function publicPost(string $platform, string $url): array
    {
        if ($platform === 'max') {
            self::need(preg_match('~\Ahttps://max\.ru/anytour/[A-Za-z0-9_-]{11}\z~D', $url) === 1, 'max_public_url_invalid');
            return ['caption'=>self::maxPublicCaption(self::publicBytes($url)),'buttons'=>[]];
        }
        self::need(preg_match('~\Ahttps://t\.me/anytour/([1-9][0-9]{0,9})\z~D', $url, $match) === 1, 'telegram_public_url_invalid');
        $mid = $match[1];
        $html = self::publicBytes('https://t.me/s/anytour/'.$mid);
        $dom = new DOMDocument();
        self::need(@$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING),
            'telegram_public_html_unavailable');
        $xpath = new DOMXPath($dom);
        $messages = $xpath->query('//*[@data-post="anytour/'.$mid.'"]');
        self::need($messages->length === 1, 'telegram_public_identity_unverified');
        $root = $messages->item(0);
        $texts = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " tgme_widget_message_text ")]', $root);
        self::need($texts->length === 1, 'telegram_public_caption_unavailable');
        $caption = '';
        foreach ($texts->item(0)->childNodes as $node) $caption .= $dom->saveHTML($node);
        $caption = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $caption)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $buttons = [];
        foreach ($xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " tgme_widget_message_inline_button ")]', $root) as $node) {
            $buttons[] = ['text'=>trim($node->textContent), 'url'=>$node->getAttribute('href')];
        }
        return ['caption'=>$caption,'buttons'=>$buttons];
    }

    public static function maxPublicCaption(string $html): string
    {
        self::need(preg_match('~message:\{text:("(?:[^"\\\\]|\\\\.)*")~', $html, $match) === 1, 'max_public_caption_unavailable');
        $caption = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
        // The MAX public renderer stores an HTML-escaped text value in its data block.
        return html_entity_decode($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function publicBytes(string $url, int $bound = 4000000): string
    {
        $body = '';
        $request = curl_init($url);
        curl_setopt_array($request, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>15,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body,$bound): int {
                if (strlen($body) + strlen($chunk) > $bound) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_HTTP_CODE);
        curl_close($request);
        self::need($ok === true && $status === 200 && $body !== '', 'public_http_read_failed');
        return $body;
    }

    private function readJournal(int $id): array
    {
        $path = $this->journal.'/'.$id.'.json';
        self::need(!is_link($path), 'revision_journal_link');
        if (!is_file($path)) return [];
        $raw = file_get_contents($path, false, null, 0, 4097);
        self::need(is_string($raw) && strlen($raw) <= 4096, 'invalid_revision_journal');
        $record = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        self::need(is_array($record) && in_array($record['state'] ?? null, ['started','provider_verified','verified'], true),
            'invalid_revision_journal');
        return $record;
    }

    private function writeJournal(int $id, array $item): void
    {
        $path = $this->journal.'/'.$id.'.json';
        $staged = tempnam($this->journal, 'price-');
        self::need(is_string($staged), 'revision_journal_stage_failed');
        try {
            $stream = fopen($staged,'wb');
            if (!$stream) throw new RuntimeException('revision_journal_open_failed');
            try {
                $raw = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                self::need(fwrite($stream,$raw) === strlen($raw) && fflush($stream) && fsync($stream), 'revision_journal_write_failed');
            } finally {
                fclose($stream);
            }
            self::need(!is_link($path) && rename($staged,$path), 'revision_journal_commit_failed');
        } finally {
            if (is_file($staged)) unlink($staged);
        }
    }

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new RuntimeException($reason);
    }
}
