<?php
declare(strict_types=1);

require_once __DIR__.'/AppleService39DeviceRevision.php';
require_once __DIR__.'/AppleService39TelegramCatalogue.php';
require_once __DIR__.'/AppleService39PilotCatalog.php';
require_once __DIR__.'/AppleService39TelegramConnection.php';
require_once __DIR__.'/ConversationDb.php';

/** Reviewed AppleService39 posts in one public Telegram channel. Provider writes are claimed before transport. */
final class AppleService39TelegramDevicePublisher
{
    public const DEVICE_CAMPAIGN = 'appleservice39-telegram-device-v1';
    private const CHANNEL = 'appleservice39';
    private const BOT = 'Appleservice39_bot';

    private PDO $db;
    private $binding;
    private $request;

    public function __construct(?PDO $pdo = null, ?callable $binding = null, ?callable $request = null)
    {
        $this->db = $pdo ?? ConversationDb::connection();
        $this->binding = $binding ?? [AppleService39TelegramConnection::class, 'runtimeBinding'];
        $this->request = $request ?? [self::class, 'network'];
    }

    /** GET reads existing receipts only, even if no Telegram credential is configured. */
    public function status(): array
    {
        return $this->publicationStatus(self::DEVICE_CAMPAIGN, AppleService39DeviceRevision::POSTS);
    }

    public function catalogueStatus(AppleService39TelegramCatalogue $catalogue): array
    {
        return $this->publicationStatus(AppleService39TelegramCatalogue::CAMPAIGN, $catalogue->order());
    }

    private function publicationStatus(string $campaign, array $keys): array
    {
        $items = array_fill_keys($keys, ['state'=>'pending', 'url'=>null]);
        $q = $this->db->prepare("SELECT post_key,state,public_url,external_message_id FROM appleservice39_pilot_operations
            WHERE campaign_key=? AND operation='create'");
        $q->execute([$campaign]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['post_key'];
            if (!isset($items[$key])) continue;
            $state = $row['state'];
            $mid = (string)$row['external_message_id'];
            if ($state === 'verified' && preg_match('/\A[1-9][0-9]*\z/D', $mid)
                && $row['public_url'] === 'https://t.me/'.self::CHANNEL.'/'.$mid) {
                $items[$key] = ['state'=>'published', 'url'=>$row['public_url']];
            } else {
                $items[$key]['state'] = $state === 'prepared' ? 'pending' : ($state === 'rejected' ? 'rejected' : 'unknown');
            }
        }
        return $items;
    }

    public function publish(int $managerId): array
    {
        return $this->publishRevision($managerId, self::DEVICE_CAMPAIGN, new AppleService39DeviceRevision(),
            count(AppleService39DeviceRevision::POSTS));
    }

    public function publishCatalogue(int $managerId, AppleService39TelegramCatalogue $catalogue): array
    {
        return $this->publishRevision($managerId, AppleService39TelegramCatalogue::CAMPAIGN, $catalogue,
            AppleService39TelegramCatalogue::BATCH_SIZE);
    }

    private function publishRevision(int $managerId, string $campaign,
        AppleService39DeviceRevision|AppleService39TelegramCatalogue $revision, int $limit): array
    {
        $started = hrtime(true);
        if ($this->db->inTransaction()) throw new RuntimeException('publication_requires_autocommit');
        $binding = ($this->binding)();
        if (($binding['project_key'] ?? '') !== 'appleservice39' || ($binding['source_key'] ?? '') !== 'telegram:appleservice39-main'
            || strcasecmp((string)($binding['bot_username'] ?? ''), self::BOT) !== 0
            || !preg_match('/\A[1-9][0-9]*\z/D', (string)($binding['bot_id'] ?? ''))
            || empty($binding['bot_token']) || empty($binding['source_id'])) throw new RuntimeException('telegram_connection_required');
        $access = $this->db->prepare("SELECT s.id FROM conversation_sources s JOIN projects p ON p.id=s.project_id
            JOIN manager_projects mp ON mp.project_id=p.id JOIN managers m ON m.id=mp.manager_id
            WHERE p.project_key='appleservice39' AND p.is_active=1 AND s.id=? AND s.source_key=? AND s.channel='telegram'
            AND s.handling_mode='manager' AND s.is_active=1 AND m.id=? AND m.role='admin' AND m.is_active=1");
        $access->execute([$binding['source_id'], $binding['source_key'], $managerId]);
        if (!$access->fetchColumn()) throw new RuntimeException('apple_project_admin_required');

        $items = $this->publicationStatus($campaign, $revision->order());
        if (count(array_filter($items, static fn(array $row):bool => in_array($row['state'], ['pending','rejected'], true))) === 0) return $items;
        $token = $binding['bot_token'];
        $botId = (string)$binding['bot_id'];
        $me = $this->read($token, 'getMe', []);
        $chat = $this->read($token, 'getChat', ['chat_id'=>'@'.self::CHANNEL]);
        $channel = (string)($chat['id'] ?? '');
        if (($me['is_bot'] ?? false) !== true || (string)($me['id'] ?? '') !== $botId
            || strcasecmp((string)($me['username'] ?? ''), self::BOT) !== 0 || !self::matchesChat($chat, $channel)) {
            throw new RuntimeException('telegram_identity_mismatch');
        }
        $member = $this->read($token, 'getChatMember', ['chat_id'=>$channel, 'user_id'=>$botId]);
        if ((string)($member['user']['id'] ?? '') !== $botId || (($member['status'] ?? '') !== 'creator'
            && !(($member['status'] ?? '') === 'administrator' && ($member['can_post_messages'] ?? false) === true))) {
            throw new RuntimeException('telegram_channel_post_access_required');
        }
        if ($campaign === AppleService39TelegramCatalogue::CAMPAIGN) {
            $original = $this->db->prepare('SELECT channel_id,connection_sha256 FROM appleservice39_pilot_campaigns WHERE campaign_key=?');
            $original->execute([self::DEVICE_CAMPAIGN]);
            $receipt = $original->fetch(PDO::FETCH_ASSOC);
            if (!$receipt || $receipt['channel_id'] !== $channel
                || $receipt['connection_sha256'] !== hash('sha256', $botId.'|'.$channel.'|'.$binding['source_id'])) {
                throw new RuntimeException('telegram_campaign_changed');
            }
        }
        $this->bindCampaign($campaign, $revision->sha256(), $binding, $channel, $managerId);
        foreach ($revision->order() as $id) {
            if ($items[$id]['state'] === 'published' || $items[$id]['state'] === 'unknown') continue;
            $post = $revision->post($id);
            $caption = implode("\n\n", array_merge([$post['title']], $post['paragraphs']));
            $url = 'https://t.me/'.self::BOT.'?start='.AppleService39PilotCatalog::landingPayloadForPost($id);
            $button = ['text'=>$post['button_label'],'url'=>$url];
            $photo = $revision->artwork($id);
            if (mb_strlen($caption, 'UTF-8') > 1024) throw new RuntimeException('telegram_caption_too_long');
            $payload = json_encode([$channel,$revision->assetSha($id),$caption,$button], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $payload);
            if (!$this->claim($campaign, $id, $payload, $hash)) continue;
            try {
                $answer = ($this->request)($token, 'sendPhoto', ['chat_id'=>$channel, 'photo'=>$photo, 'caption'=>$caption,
                    'disable_notification'=>true, 'reply_markup'=>['inline_keyboard'=>[[$button]]]]);
            } catch (Throwable $ignored) {
                $answer = [];
            }
            $msg = $answer['result'] ?? [];
            $mid = (string)($msg['message_id'] ?? '');
            $native = $msg['reply_markup']['inline_keyboard'] ?? [];
            $ok = ($answer['http'] ?? 0) === 200 && ($answer['ok'] ?? false) === true && preg_match('/\A[1-9][0-9]*\z/D', $mid)
                && self::matchesChat($msg['chat'] ?? [], $channel) && ($msg['caption'] ?? null) === $caption
                && !empty($msg['photo'][0]['file_id']) && count($native) === 1 && count($native[0] ?? []) === 1
                && ($native[0][0]['text'] ?? null) === $button['text'] && ($native[0][0]['url'] ?? null) === $button['url'];
            $rejected = ($answer['ok'] ?? null) === false && in_array(($answer['http'] ?? 0), [400,401,403,429], true)
                && ($answer['error_code'] ?? null) === $answer['http'];
            $state = $ok ? 'verified' : ($rejected ? 'rejected' : 'unknown');
            $q = $this->db->prepare("UPDATE appleservice39_pilot_operations SET state=?,external_message_id=?,public_url=?,updated_at=?
                WHERE campaign_key=? AND post_key=? AND operation='create' AND payload_sha256=? AND state='write_started'");
            $q->execute([$state,$ok ? $mid : null,$ok ? 'https://t.me/'.self::CHANNEL.'/'.$mid : null,
                gmdate('Y-m-d H:i:s'),$campaign,$id,$hash]);
            if ($q->rowCount() !== 1) throw new RuntimeException('telegram_receipt_uncertain');
            if (!$ok || --$limit === 0
                || ($campaign === AppleService39TelegramCatalogue::CAMPAIGN && hrtime(true) - $started >= 18_000_000_000)) break;
        }
        return $this->publicationStatus($campaign, $revision->order());
    }

    private function read(string $token, string $method, array $params): array
    {
        $r = ($this->request)($token,$method,$params);
        if (($r['ok'] ?? false) !== true || ($r['http'] ?? 0) !== 200 || !is_array($r['result'] ?? null)) throw new RuntimeException('telegram_read_failed');
        return $r['result'];
    }

    private static function matchesChat(array $chat, string $channel): bool
    {
        return preg_match('/\A-100[0-9]+\z/D', $channel) === 1 && (string)($chat['id'] ?? '') === $channel
            && ($chat['type'] ?? '') === 'channel' && strcasecmp((string)($chat['username'] ?? ''), self::CHANNEL) === 0;
    }

    private function bindCampaign(string $campaign, string $manifestSha, array $binding, string $channel, int $managerId): void
    {
        $identity = hash('sha256', $binding['bot_id'].'|'.$channel.'|'.$binding['source_id']);
        $args = [$campaign,'appleservice39',self::BOT,$channel,$binding['source_id'],$managerId,$manifestSha,$identity,gmdate('Y-m-d H:i:s')];
        $this->insertOnce('INSERT INTO appleservice39_pilot_campaigns
            (campaign_key,project_key,bot_key,channel_id,source_id,admin_manager_id,manifest_sha256,connection_sha256,created_at) VALUES (?,?,?,?,?,?,?,?,?)', $args);
        $q = $this->db->prepare('SELECT project_key,bot_key,channel_id,source_id,manifest_sha256,connection_sha256 FROM appleservice39_pilot_campaigns WHERE campaign_key=?');
        $q->execute([$campaign]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['project_key'] !== 'appleservice39' || $row['bot_key'] !== self::BOT || $row['channel_id'] !== $channel
            || (int)$row['source_id'] !== (int)$binding['source_id'] || $row['manifest_sha256'] !== $manifestSha
            || $row['connection_sha256'] !== $identity) throw new RuntimeException('telegram_campaign_changed');
    }

    private function claim(string $campaign, string $id, string $payload, string $hash): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->insertOnce("INSERT INTO appleservice39_pilot_operations
            (campaign_key,post_key,operation,payload_sha256,request_json,state,write_attempts,outcome_json,created_at,updated_at)
            VALUES (?,?,'create',?,?,'prepared',0,'{}',?,?)", [$campaign,$id,$hash,$payload,$now,$now]);
        $q = $this->db->prepare("UPDATE appleservice39_pilot_operations SET state='write_started',write_attempts=write_attempts+1,updated_at=?
            WHERE campaign_key=? AND post_key=? AND operation='create' AND payload_sha256=? AND state IN ('prepared','rejected')");
        $q->execute([$now,$campaign,$id,$hash]);
        return $q->rowCount() === 1;
    }

    private function insertOnce(string $sql, array $params): void
    {
        try {
            $this->db->prepare($sql)->execute($params);
        } catch (PDOException $error) {
            if (!in_array((string)$error->getCode(), ['23000','23505'], true)) throw $error;
        }
    }

    private static function network(string $token, string $method, array $params): array
    {
        if (!in_array($method, ['getMe','getChat','getChatMember','sendPhoto'], true)
            || !preg_match('/\A[0-9]+:[a-zA-Z0-9_-]+\z/D', $token)) throw new RuntimeException('telegram_request_invalid');
        if ($method === 'sendPhoto') {
            usleep(1_100_000);
            $params['photo'] = new CURLFile($params['photo'],'image/png','cover.png');
            $params['reply_markup'] = json_encode($params['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $params['disable_notification'] = 'true';
        }
        $curl = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$params, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        try {
            $raw = curl_exec($curl);
            $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if (!is_string($raw) || curl_errno($curl) || strlen($raw) > 1048576) return [];
            $r = json_decode($raw, true);
            return ['http'=>$http,'ok'=>$r['ok'] ?? null,'result'=>$r['result'] ?? null,'error_code'=>$r['error_code'] ?? null];
        } finally {
            curl_close($curl);
        }
    }
}
