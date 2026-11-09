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


/** Fixed text/artwork revision; original campaign assets and receipts are immutable. */
final class AppleService39LandingRevision
{
    public const KEY = 'landing-v2';
    private array $data;
    private string $sha256;
    private AppleService39PilotManifest $original;
    private array $artwork = [];
    private string $artworkDirectory;
    private string $reviewDirectory;

    public function __construct(AppleService39PilotManifest $original, ?string $path = null)
    {
        $path ??= dirname(__DIR__).'/resources/appleservice39/landing-v2/manifest.json';
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 131072, 'landing_revision_unavailable');
        $raw = file_get_contents($path);
        self::check(is_string($raw), 'landing_revision_unavailable');
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($data) && ($data['schema_version'] ?? null) === 1
            && ($data['revision_key'] ?? null) === self::KEY
            && ($data['campaign_key'] ?? null) === AppleService39PilotManifest::CAMPAIGN
            && ($data['project_key'] ?? null) === AppleService39PilotManifest::PROJECT
            && ($data['bot_key'] ?? null) === AppleService39PilotManifest::BOT
            && ($data['source_key'] ?? null) === AppleService39PilotManifest::SOURCE
            && ($data['channel_url'] ?? null) === AppleService39PilotManifest::CHANNEL
            && ($data['base_manifest_sha256'] ?? null) === $original->sha256()
            && ($data['asset_policy'] ?? null) === 'reviewed_repair_artwork'
            && ($data['contact_source'] ?? null) === 'https://appleservice39.ru/kontakty/',
            'landing_revision_identity_mismatch');
        self::check(is_array($data['posts'] ?? null) && array_is_list($data['posts'])
            && array_column($data['posts'], 'id') === array_column($original->posts(), 'id'), 'landing_exact_posts_required');
        foreach ($data['posts'] as $post) {
            self::check(is_array($post) && array_keys($post) === ['id','title','paragraphs','button_label','links'],
                'landing_post_invalid');
            self::plain($post['title'], 100);
            self::plain($post['button_label'], 20);
            self::check(is_array($post['paragraphs']) && array_is_list($post['paragraphs'])
                && count($post['paragraphs']) >= 2 && count($post['paragraphs']) <= 6
                && $post['links'] === $original->post($post['id'])['links'], 'landing_post_invalid');
            foreach ($post['paragraphs'] as $paragraph) self::plain($paragraph, 1600);
            self::check(AppleService39PilotCatalog::landingPayloadForPost($post['id']) !== null, 'landing_entry_invalid');
            $entry = AppleService39PilotCatalog::forPost($post['id']);
            if ($entry !== null) self::check(in_array($entry['price_text'], $post['paragraphs'], true)
                && str_contains($post['title'], $entry['model']), 'landing_reviewed_price_required');
        }
        $this->original = $original; $this->data = $data; $this->sha256 = hash('sha256', $raw);
        $this->artworkDirectory = dirname((string)realpath($path)).'/artwork';
        $this->reviewDirectory = dirname((string)realpath($path)).'/reconciliation';
        $assetPath = $this->artworkDirectory.'/asset-manifest.json';
        self::check(is_file($assetPath) && !is_link($assetPath) && filesize($assetPath) <= 65536
            && is_string($data['artwork_manifest_sha256'] ?? null)
            && hash_file('sha256', $assetPath) === $data['artwork_manifest_sha256'], 'landing_artwork_manifest_changed');
        $assets = json_decode((string)file_get_contents($assetPath), true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($assets) && ($assets['schema_version'] ?? null) === 1
            && ($assets['project_key'] ?? null) === AppleService39PilotManifest::PROJECT
            && ($assets['media_kind'] ?? null) === 'AI_generated_illustration'
            && is_array($assets['posts'] ?? null) && array_is_list($assets['posts']) && count($assets['posts']) === 15,
            'landing_artwork_manifest_changed');
        foreach ($assets['posts'] as $asset) {
            $id = $asset['post_id'] ?? null;
            self::check(is_string($id) && !isset($this->artwork[$id]), 'landing_artwork_manifest_changed');
            $entry = AppleService39PilotCatalog::forPost($id);
            self::check($entry !== null && ($asset['path'] ?? null) === 'covers/'.$id.'.png'
                && ($asset['source_artwork_visually_reviewed'] ?? null) === true
                && ($asset['width'] ?? null) === 1122 && ($asset['height'] ?? null) === 1402
                && is_int($asset['bytes'] ?? null) && $asset['bytes'] > 0 && $asset['bytes'] < 1900000
                && is_string($asset['sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $asset['sha256']) === 1,
                'landing_artwork_manifest_changed');
            foreach (['model','service','service_key','price_kind','price_text','source_urls'] as $key) {
                self::check(($asset[$key] ?? null) === $entry[$key], 'landing_artwork_catalog_changed');
            }
            $this->artwork[$id] = $asset;
            $this->validateArtwork($id);
        }
    }

    public function sha256(): string { return $this->sha256; }
    public function posts(): array { return $this->data['posts']; }
    public function artwork(string $postId): ?array { return $this->artwork[$postId] ?? null; }

    /** Source-controlled approvals only. An unregistered JSON/JPEG is never evidence. */
    public function reviewedNativeImage(string $postId): ?array
    {
        $indexPath = $this->reviewDirectory.'/index.json';
        self::check(is_file($indexPath) && !is_link($indexPath) && filesize($indexPath) <= 16384,
            'landing_review_index_unavailable');
        $index = json_decode((string)file_get_contents($indexPath), true, 16, JSON_THROW_ON_ERROR);
        self::check(is_array($index) && array_keys($index) === ['schema_version','revision_key','project_key','policy','approvals']
            && $index['schema_version'] === 1 && $index['revision_key'] === self::KEY
            && $index['project_key'] === AppleService39PilotManifest::PROJECT
            && $index['policy'] === 'exact_source_and_native_visual_review'
            && is_array($index['approvals']) && array_is_list($index['approvals']) && count($index['approvals']) <= 15,
            'landing_review_index_unavailable');
        $ids = []; $selected = null;
        foreach ($index['approvals'] as $item) {
            self::check(is_array($item) && array_keys($item) === ['post_id','proof_sha256','native_preview_sha256']
                && is_string($item['post_id']) && $this->artwork($item['post_id']) !== null
                && !isset($ids[$item['post_id']]), 'landing_review_index_unavailable');
            foreach (['proof_sha256','native_preview_sha256'] as $key) self::check(is_string($item[$key])
                && preg_match('/\A[a-f0-9]{64}\z/D', $item[$key]) === 1, 'landing_review_index_unavailable');
            $ids[$item['post_id']] = true;
            if ($item['post_id'] === $postId) $selected = $item;
        }
        if ($selected === null) return null;
        $proofPath = $this->reviewDirectory.'/'.$postId.'.json';
        $previewPath = $this->reviewDirectory.'/'.$postId.'.jpg';
        foreach ([$proofPath,$previewPath] as $file) self::check(is_file($file) && !is_link($file)
            && dirname((string)realpath($file)) === (string)realpath($this->reviewDirectory), 'landing_review_evidence_unavailable');
        self::check(filesize($proofPath) > 0 && filesize($proofPath) <= 16384
            && filesize($previewPath) > 0 && filesize($previewPath) <= 5242880
            && hash_file('sha256', $proofPath) === $selected['proof_sha256']
            && hash_file('sha256', $previewPath) === $selected['native_preview_sha256'], 'landing_review_evidence_changed');
        $proof = json_decode((string)file_get_contents($proofPath), true, 16, JSON_THROW_ON_ERROR);
        $image = @getimagesize($previewPath);
        self::check(is_array($proof) && is_array($image) && $image[2] === IMAGETYPE_JPEG
            && $image[0] > 0 && $image[0] <= 7680 && $image[1] > 0 && $image[1] <= 7680
            && ($proof['native_preview_width'] ?? null) === $image[0]
            && ($proof['native_preview_height'] ?? null) === $image[1]
            && ($proof['native_preview_sha256'] ?? null) === $selected['native_preview_sha256'],
            'landing_review_evidence_changed');
        return ['proof'=>$proof,'evidence_sha256'=>$selected['proof_sha256'],
            'native_preview_sha256'=>$selected['native_preview_sha256']];
    }

    public function validateArtwork(string $postId): string
    {
        $asset = $this->artwork[$postId] ?? null;
        self::check(is_array($asset), 'landing_artwork_manifest_changed');
        $path = $this->artworkDirectory.'/'.$asset['path'];
        $resolved = realpath($path); $directory = realpath($this->artworkDirectory.'/covers');
        self::check(is_string($resolved) && is_string($directory) && dirname($resolved) === $directory
            && !is_link($path) && is_file($path) && filesize($path) === $asset['bytes']
            && hash_file('sha256', $path) === $asset['sha256'], 'landing_artwork_changed');
        $image = @getimagesize($path);
        self::check(is_array($image) && ($image[2] ?? null) === IMAGETYPE_PNG
            && $image[0] === 1122 && $image[1] === 1402, 'landing_artwork_changed');
        return $path;
    }

    /** Build both the outgoing HTML and its exact native text/link projection. */
    public function plan(array $post, array $upload, array $urls): array
    {
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $plain = $post['title']."\n\n".implode("\n\n", $post['paragraphs']);
        $html = '<b>'.$escape($post['title']).'</b>'."\n\n".$escape(implode("\n\n", $post['paragraphs']));
        $markup = [['type'=>'strong','from'=>0,'length'=>mb_strlen($post['title'], 'UTF-8')]];
        $navigationTitle = $post['id'] === 'menu' ? 'Выберите модель или услугу'
            : (str_starts_with($post['id'], 'model-') ? 'Выберите ремонт'
                : (str_starts_with($post['id'], 'service-') ? 'Выберите модель' : 'Другие варианты'));
        $plain .= "\n\n".$navigationTitle;
        $html .= "\n\n".$escape($navigationTitle);
        foreach ($post['links'] as $link) {
            $url = $urls[$link['id']] ?? null;
            self::check(is_string($url) && preg_match('~\Ahttps://max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]{1,191}\z~D', $url) === 1,
                'landing_verified_link_required');
            $plain .= "\n";
            $markup[] = ['type'=>'link','from'=>mb_strlen($plain, 'UTF-8'),
                'length'=>mb_strlen($link['label'], 'UTF-8'),'url'=>$url];
            $plain .= $link['label'];
            $html .= "\n".'<a href="'.$escape($url).'">'.$escape($link['label']).'</a>';
        }
        self::check(mb_strlen($plain, 'UTF-8') <= 4000 && mb_strlen($html, 'UTF-8') <= 4000, 'landing_post_too_long');
        $old = $this->original->post($post['id']);
        $body = $this->original->body($old, $upload);
        $images = array_values(array_filter($body['attachments'] ?? [], static fn(array $item): bool => $item['type'] === 'image'));
        self::check(count($images) === 1, 'landing_original_image_required');
        $payload = AppleService39PilotCatalog::landingPayloadForPost($post['id']);
        $body = ['text'=>$html,'format'=>'html','attachments'=>[$images[0],
            ['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'link','text'=>$post['button_label'],
                'url'=>'https://max.ru/'.AppleService39PilotManifest::BOT.'?start='.$payload]]]]]]];
        return ['revision_sha256'=>$this->sha256,'body'=>$body,'native_text'=>$plain,'native_markup'=>$markup];
    }

    public static function sameMarkup($actual, array $expected): bool
    {
        if (!is_array($actual) || !array_is_list($actual) || count($actual) !== count($expected)) return false;
        foreach ($actual as $item) {
            if (!is_array($item) || !is_string($item['type'] ?? null) || !is_int($item['from'] ?? null)
                || !is_int($item['length'] ?? null)) return false;
            $keys = $item['type'] === 'link' ? ['type','from','length','url'] : ['type','from','length'];
            if (array_diff(array_keys($item), $keys) !== [] || count($item) !== count($keys)) return false;
        }
        $sort = static fn(array $a, array $b): int => ($a['from'] <=> $b['from']) ?: strcmp($a['type'], $b['type']);
        usort($actual, $sort); usort($expected, $sort);
        foreach ($expected as $i=>$item) foreach ($item as $key=>$value) {
            if (!array_key_exists($key, $actual[$i]) || $actual[$i][$key] !== $value) return false;
        }
        return true;
    }

    private static function plain($value, int $limit): void
    {
        // Fixed BMP-only copy makes Unicode character and UTF-16 entity offsets identical.
        self::check(is_string($value) && trim($value) !== '' && mb_strlen($value, 'UTF-8') <= $limit
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>\x{10000}-\x{10ffff}]/u', $value) === 0,
            'landing_plain_text_required');
    }
    private static function check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
}
