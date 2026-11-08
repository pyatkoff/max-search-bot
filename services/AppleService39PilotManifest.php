<?php
declare(strict_types=1);

require_once __DIR__.'/AppleService39PilotCatalog.php';

/** The reviewed, source-controlled pilot is the only publication input. */
final class AppleService39PilotManifest
{
    public const PROJECT = 'appleservice39';
    public const BOT = 'id390521129062_bot';
    public const CHANNEL = 'https://max.ru/channel_appleservice39ru';
    public const SOURCE = 'max:appleservice39-main';
    public const CAMPAIGN = 'appleservice39-pilot-v1';
    private array $data;
    private string $directory;
    private string $sha256;

    public function __construct(string $path)
    {
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 262144, 'manifest_unavailable');
        $raw = file_get_contents($path);
        self::check(is_string($raw), 'manifest_unavailable');
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($data) && ($data['schema_version'] ?? null) === 1
            && ($data['project_key'] ?? null) === self::PROJECT && ($data['bot_key'] ?? null) === self::BOT
            && ($data['channel_url'] ?? null) === self::CHANNEL && ($data['source_key'] ?? null) === self::SOURCE
            && ($data['campaign_key'] ?? null) === self::CAMPAIGN, 'manifest_identity_mismatch');
        self::check(is_array($data['posts'] ?? null) && array_is_list($data['posts']) && count($data['posts']) === 24, 'exact_pilot_required');
        $this->directory = dirname((string)realpath($path));
        $ids = []; $repair = 0;
        foreach ($data['posts'] as $post) {
            self::check(is_array($post) && is_string($post['id'] ?? null)
                && preg_match('/\A[a-z][a-z0-9_-]{0,55}\z/D', $post['id']) === 1 && !isset($ids[$post['id']]), 'invalid_post_identity');
            $ids[$post['id']] = true;
            self::check(is_string($post['kind'] ?? null) && preg_match('/\A[a-z][a-z_]{0,31}\z/D', $post['kind']) === 1, 'invalid_post_kind');
            self::text($post['text'] ?? null, 4000, 'invalid_post_text');
            self::check(is_array($post['buttons'] ?? null) && array_is_list($post['buttons']) && count($post['buttons']) <= 1, 'invalid_post_buttons');
            if ($post['kind'] === 'repair') {
                $repair++;
                $payload = AppleService39PilotCatalog::payloadForPost($post['id']);
                self::check(is_string($payload) && $payload === 'as39_'.$post['id'] && count($post['buttons']) === 1, 'repair_entry_mismatch');
                $button = $post['buttons'][0];
                self::check(is_array($button) && array_keys($button) === ['text','url']
                    && ($button['url'] ?? null) === 'https://max.ru/'.self::BOT.'?start='.$payload, 'repair_button_mismatch');
                self::text($button['text'] ?? null, 128, 'invalid_button_label');
            } else self::check($post['buttons'] === [], 'nonrepair_button_not_approved');
            self::check(is_array($post['links'] ?? null) && array_is_list($post['links']) && count($post['links']) <= 8, 'invalid_post_links');
            $targets = [];
            foreach ($post['links'] as $link) {
                self::check(is_array($link) && array_keys($link) === ['id','label'] && is_string($link['id'])
                    && $link['id'] !== $post['id'] && !isset($targets[$link['id']]), 'invalid_post_link');
                self::text($link['label'] ?? null, 128, 'invalid_link_label');
                $targets[$link['id']] = true;
            }
            if (isset($post['image'])) {
                self::check(is_array($post['image']) && array_keys($post['image']) === ['path','sha256']
                    && is_string($post['image']['path']) && preg_match('~\Acovers/[a-z][a-z0-9_-]{0,55}\.png\z~D', $post['image']['path']) === 1
                    && is_string($post['image']['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $post['image']['sha256']) === 1, 'invalid_image_manifest');
                $this->validateImage($post);
            }
        }
        self::check($repair === 15 && is_string($data['menu_post_id'] ?? null) && isset($ids[$data['menu_post_id']]), 'pilot_catalog_mismatch');
        foreach ($data['posts'] as $post) foreach ($post['links'] as $link) self::check(isset($ids[$link['id']]), 'link_outside_pilot');
        $this->data = $data;
        $this->sha256 = hash('sha256', $raw);
    }

    public static function installed(): self { return new self(dirname(__DIR__).'/resources/appleservice39/pilot/manifest.json'); }
    public function sha256(): string { return $this->sha256; }
    public function posts(): array { return $this->data['posts']; }
    public function menuPostId(): string { return $this->data['menu_post_id']; }
    public function post(string $id): array
    {
        foreach ($this->posts() as $post) if ($post['id'] === $id) return $post;
        throw new RuntimeException('post_outside_pilot');
    }

    public function validateImage(array $post): string
    {
        $path = $this->directory.'/'.$post['image']['path'];
        $resolved = realpath($path);
        self::check(is_string($resolved) && str_starts_with($resolved, $this->directory.'/covers/')
            && !is_link($path) && is_file($path) && filesize($path) > 0 && filesize($path) <= 5242880
            && hash_file('sha256', $path) === $post['image']['sha256'], 'approved_image_changed');
        $image = @getimagesize($path);
        self::check(is_array($image) && ($image[2] ?? null) === IMAGETYPE_PNG && $image[0] > 0 && $image[1] > 0
            && $image[0] <= 7680 && $image[1] <= 7680, 'approved_image_invalid');
        return $path;
    }

    public function body(array $post, ?array $upload, array $urls = []): array
    {
        $text = $post['text'];
        $buttons = array_map(static fn(array $button): array => [['type'=>'link','text'=>$button['text'],'url'=>$button['url']]], $post['buttons']);
        if ($urls !== []) {
            foreach ($post['links'] as $link) {
                self::check(isset($urls[$link['id']]) && is_string($urls[$link['id']]), 'verified_link_missing');
                $buttons[] = [['type'=>'link','text'=>$link['label'],'url'=>$urls[$link['id']]]];
            }
        }
        self::text($text, 4000, 'resolved_post_too_long');
        self::check(count($buttons) <= 9, 'pilot_keyboard_too_large');
        $body = ['text'=>$text]; $attachments = [];
        if (isset($post['image'])) {
            self::check(is_array($upload) && is_string($upload['token'] ?? null) && $upload['token'] !== ''
                && is_string($upload['photo_id'] ?? null) && preg_match('/\A[0-9]+\z/D', $upload['photo_id']) === 1, 'verified_upload_missing');
            $attachments[] = ['type'=>'image','payload'=>['token'=>$upload['token']]];
        }
        if ($buttons !== []) {
            $attachments[] = ['type'=>'inline_keyboard','payload'=>['buttons'=>$buttons]];
        }
        if ($attachments !== []) $body['attachments'] = $attachments;
        return $body;
    }

    private static function text($text, int $max, string $reason): void
    {
        self::check(is_string($text) && trim($text) !== '' && preg_match('//u', $text) === 1
            && mb_strlen($text, 'UTF-8') <= $max && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text), $reason);
    }
    private static function check(bool $condition, string $reason): void { if (!$condition) throw new RuntimeException($reason); }
}
