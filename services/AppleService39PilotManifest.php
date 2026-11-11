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
                && ($asset['width'] ?? null) === 1072 && ($asset['height'] ?? null) === 1467
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
            && $image[0] === 1072 && $image[1] === 1467, 'landing_artwork_changed');
        return $path;
    }

    /** Build both the outgoing HTML and its exact native text/link projection. */
    /** Shared canonical text projection; a text-only revision never supplies attachments. */
    public static function textPlan(array $post, array $urls, ?string $navigationTitle = null): array
    {
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $plain = $post['title']."\n\n".implode("\n\n", $post['paragraphs']);
        $html = '<b>'.$escape($post['title']).'</b>'."\n\n".$escape(implode("\n\n", $post['paragraphs']));
        $markup = [['type'=>'strong','from'=>0,'length'=>mb_strlen($post['title'], 'UTF-8')]];
        $navigationTitle ??= $post['id'] === 'menu' ? 'Выберите модель или услугу'
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
        return ['body'=>['text'=>$html,'format'=>'html'],'native_text'=>$plain,'native_markup'=>$markup];
    }

    public function plan(array $post, array $upload, array $urls): array
    {
        $text = self::textPlan($post, $urls);
        $old = $this->original->post($post['id']);
        $body = $this->original->body($old, $upload);
        $images = array_values(array_filter($body['attachments'] ?? [], static fn(array $item): bool => $item['type'] === 'image'));
        self::check(count($images) === 1, 'landing_original_image_required');
        $payload = AppleService39PilotCatalog::landingPayloadForPost($post['id']);
        $body = ['text'=>$text['body']['text'],'format'=>'html','attachments'=>[$images[0],
            ['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'link','text'=>$post['button_label'],
                'url'=>'https://max.ru/'.AppleService39PilotManifest::BOT.'?start='.$payload]]]]]]];
        return ['revision_sha256'=>$this->sha256,'body'=>$body,'native_text'=>$text['native_text'],'native_markup'=>$text['native_markup']];
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

/** An immutable successor text revision. The accepted v2 artwork and claims stay untouched. */
final class AppleService39CatalogueRevision
{
    public const KEY = 'catalogue-v1';
    public const OPERATION = 'catalogue_v1';
    public const PARENT_SHA = 'f772e83df3532120cf20d99ff0476c9717d5f42d75b7f6f89b8e0fed8890f8bd';
    private array $data;
    private array $catalogue;
    private string $sha256;

    public function __construct(AppleService39PilotManifest $original, ?string $path = null)
    {
        $path ??= dirname(__DIR__).'/resources/appleservice39/catalogue-v1/manifest.json';
        $raw = self::readFile($path, 131072);
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $parent = self::readFile(dirname(__DIR__).'/resources/appleservice39/landing-v2/manifest.json', 131072);
        self::check(hash('sha256', $parent) === self::PARENT_SHA, 'catalogue_parent_changed');
        $previous = json_decode($parent, true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($data) && ($data['schema_version'] ?? null) === 1
            && ($data['revision_key'] ?? null) === self::KEY
            && ($data['campaign_key'] ?? null) === AppleService39PilotManifest::CAMPAIGN
            && ($data['project_key'] ?? null) === AppleService39PilotManifest::PROJECT
            && ($data['bot_key'] ?? null) === AppleService39PilotManifest::BOT
            && ($data['source_key'] ?? null) === AppleService39PilotManifest::SOURCE
            && ($data['channel_url'] ?? null) === AppleService39PilotManifest::CHANNEL
            && ($data['base_manifest_sha256'] ?? null) === $original->sha256()
            && ($data['parent_revision_sha256'] ?? null) === self::PARENT_SHA
            && ($data['asset_policy'] ?? null) === 'preserve_native_attachments'
            && ($data['warranty_months'] ?? null) === AppleService39PilotCatalog::WARRANTY_MONTHS
            && ($data['model_count'] ?? null) === 33
            && ($data['contact_source'] ?? null) === 'https://appleservice39.ru/kontakty/', 'catalogue_revision_invalid');
        $catalogueRaw = self::readFile(dirname($path).'/catalog.json', 262144);
        self::check(is_string($data['catalogue_sha256'] ?? null)
            && hash('sha256', $catalogueRaw) === $data['catalogue_sha256'], 'catalogue_source_changed');
        $this->catalogue = json_decode($catalogueRaw, true, 32, JSON_THROW_ON_ERROR);
        $this->validateCatalogue();
        self::check(is_array($data['posts'] ?? null) && array_is_list($data['posts'])
            && array_column($data['posts'], 'id') === array_column($original->posts(), 'id'), 'catalogue_posts_changed');
        $prior = array_column($previous['posts'], null, 'id');
        foreach ($data['posts'] as $post) {
            self::check(is_array($post) && array_keys($post) === ['id','title','paragraphs','button_label','links'], 'catalogue_post_invalid');
            self::plain($post['title'], 100); self::plain($post['button_label'], 20);
            self::check($post['button_label'] === $prior[$post['id']]['button_label']
                && is_array($post['paragraphs']) && array_is_list($post['paragraphs'])
                && count($post['paragraphs']) >= 2 && count($post['paragraphs']) <= 6
                && count(array_keys($post['paragraphs'], AppleService39PilotCatalog::WARRANTY_TEXT, true)) === 1,
                'catalogue_post_invalid');
            foreach ($post['paragraphs'] as $paragraph) self::plain($paragraph, 1600);
            $links = $post['links']; $oldLinks = $prior[$post['id']]['links'];
            self::check(is_array($links) && array_is_list($links), 'catalogue_links_changed');
            usort($links, static fn(array $a,array $b): int => strcmp($a['id'],$b['id']));
            usort($oldLinks, static fn(array $a,array $b): int => strcmp($a['id'],$b['id']));
            self::check($links === $oldLinks, 'catalogue_links_changed');
            $entry = AppleService39PilotCatalog::forPost($post['id']);
            if ($entry !== null) self::check(in_array($entry['price_text'], $post['paragraphs'], true)
                && str_contains($post['title'], $entry['model']), 'catalogue_reviewed_price_changed');
            $service = ['service-battery-replacement'=>'battery_replacement',
                'service-front-glass-replacement'=>'front_glass_replacement','service-display'=>'display'][$post['id']] ?? null;
            if ($service !== null) self::check($post['paragraphs'][1] === $this->priceParagraph($service), 'catalogue_price_projection_changed');
        }
        $this->data = $data; $this->sha256 = hash('sha256', $raw);
    }

    public function sha256(): string { return $this->sha256; }
    public function posts(): array { return $this->data['posts']; }
    public function catalogueSha256(): string { return $this->data['catalogue_sha256']; }
    public function modelCount(): int { return $this->catalogue['model_count']; }

    public function plan(array $post, array $urls): array
    {
        $title = $post['id'] === 'menu' ? 'Разделы и подробные карточки'
            : (str_starts_with($post['id'], 'service-') ? 'Подробные карточки' : null);
        return AppleService39LandingRevision::textPlan($post, $urls, $title);
    }

    private function validateCatalogue(): void
    {
        $c = $this->catalogue;
        self::check(($c['schema_version'] ?? null) === 1 && ($c['project_key'] ?? null) === AppleService39PilotManifest::PROJECT
            && ($c['bot_key'] ?? null) === AppleService39PilotManifest::BOT && ($c['model_count'] ?? null) === 33
            && ($c['warranty_months'] ?? null) === AppleService39PilotCatalog::WARRANTY_MONTHS
            && is_array($c['models'] ?? null) && array_is_list($c['models']) && count($c['models']) === 33
            && is_array($c['model_groups'] ?? null) && array_is_list($c['model_groups']) && count($c['model_groups']) === 9
            && is_array($c['offers'] ?? null) && array_is_list($c['offers']) && count($c['offers']) === 96,
            'catalogue_source_invalid');
        $models = []; $groups = []; $coverage = [];
        foreach ($c['models'] as $model) {
            self::check(is_array($model) && is_string($model['id'] ?? null)
                && preg_match('/\Aiphone-[a-z0-9-]{1,40}\z/D', $model['id']) === 1 && !isset($models[$model['id']]), 'catalogue_source_invalid');
            self::plain($model['short_label'] ?? null, 40);
            self::check(($model['label'] ?? null) === 'iPhone '.$model['short_label'], 'catalogue_source_invalid');
            $models[$model['id']] = $model;
        }
        foreach ($c['model_groups'] as $group) {
            self::check(is_array($group) && is_string($group['id'] ?? null) && is_array($group['model_ids'] ?? null)
                && array_is_list($group['model_ids']) && count($group['model_ids']) > 0 && !isset($groups[$group['id']]), 'catalogue_source_invalid');
            $groups[$group['id']] = true;
            foreach ($group['model_ids'] as $id) {
                self::check(is_string($id) && isset($models[$id]) && !isset($coverage[$id])
                    && $models[$id]['family_id'] === $group['id'], 'catalogue_source_invalid');
                $coverage[$id] = true;
            }
        }
        self::check(count($coverage) === 33, 'catalogue_source_invalid');
        $seen = []; $display = [];
        foreach ($c['offers'] as $offer) {
            self::check(is_array($offer) && in_array($offer['service_key'] ?? '', ['battery_replacement','front_glass_replacement','display'], true)
                && is_array($offer['model_ids'] ?? null) && array_is_list($offer['model_ids']) && count($offer['model_ids']) > 0
                && ($offer['warranty_months'] ?? null) === AppleService39PilotCatalog::WARRANTY_MONTHS
                && is_array($offer['price'] ?? null) && ($offer['price']['currency'] ?? null) === 'RUB'
                && in_array($offer['price']['kind'] ?? '', ['listed','on_request','unconfirmed'], true)
                && is_string($offer['source_id'] ?? null) && preg_match('/\Aas39_[a-f0-9]{20}\z/D', $offer['source_id']) === 1
                && is_string($offer['source_url'] ?? null) && preg_match('~\Ahttps://appleservice39\.ru/[a-z0-9/_-]+/\z~D', $offer['source_url']) === 1,
                'catalogue_source_invalid');
            $price = $offer['price'];
            self::check($price['kind'] === 'listed' ? is_int($price['amount'] ?? null) && $price['amount'] > 0
                : array_key_exists('amount',$price) && $price['amount'] === null, 'catalogue_source_invalid');
            if ($price['kind'] === 'unconfirmed') self::check(($offer['review_status'] ?? null) === 'owner_price_confirmation_required', 'catalogue_source_invalid');
            else self::check(($offer['review_status'] ?? null) === 'verified_source_row', 'catalogue_source_invalid');
            if ($offer['service_key'] === 'display') {
                self::check(in_array($offer['variant'] ?? '', ['original','analogue'], true)
                    && (count($offer['model_ids']) === 1 || $price['kind'] === 'on_request'), 'catalogue_source_invalid');
            } else self::check(count($offer['model_ids']) === 1 && ($offer['variant'] ?? null) === 'unspecified'
                && $offer['source_url'] === ($offer['service_key'] === 'battery_replacement'
                    ? 'https://appleservice39.ru/remont-iphone/zamena-batarei-ayfon-iphone-v-kaliningrade/'
                    : 'https://appleservice39.ru/remont-iphone/zamena-stekla-ekrana-iphone-v-kaliningrade/'), 'catalogue_source_invalid');
            foreach ($offer['model_ids'] as $id) {
                self::check(is_string($id) && isset($models[$id]), 'catalogue_source_invalid');
                $key = $offer['service_key'].'|'.$offer['variant'].'|'.$id;
                self::check(!isset($seen[$key]), 'catalogue_source_invalid'); $seen[$key] = true;
                if ($offer['service_key'] === 'display') $display[$id][$offer['variant']] = true;
            }
        }
        foreach ($models as $id=>$model) {
            foreach (['battery_replacement','front_glass_replacement'] as $service) self::check(isset($seen[$service.'|unspecified|'.$id]), 'catalogue_source_invalid');
            if (isset($display[$id])) self::check(count($display[$id]) === 2, 'catalogue_source_invalid');
        }
        $missing = array_values(array_diff(array_keys($models), array_keys($display))); sort($missing);
        self::check($missing === ['iphone-12-mini','iphone-13-mini'] && ($c['display_inquiry_only_model_ids'] ?? null) === $missing,
            'catalogue_source_invalid');
    }

    private function priceParagraph(string $service): string
    {
        $models = array_column($this->catalogue['models'], null, 'id');
        $offers = array_values(array_filter($this->catalogue['offers'], static fn(array $o): bool => $o['service_key'] === $service));
        if ($service === 'display') {
            $lines = [];
            foreach ($models as $id=>$model) {
                $rows = array_values(array_filter($offers, static fn(array $o): bool => $o['model_ids'] === [$id]));
                if ($rows === []) continue;
                self::check(count($rows) === 2, 'catalogue_source_invalid'); $parts = [];
                foreach (['original'=>'оригинал','analogue'=>'аналог'] as $variant=>$label) {
                    $row = array_values(array_filter($rows, static fn(array $o): bool => $o['variant'] === $variant))[0];
                    self::check($row['price']['kind'] === 'listed', 'catalogue_source_invalid');
                    $parts[] = $label.' '.self::money($row['price']['amount']);
                }
                $lines[] = $model['label'].' — '.implode(', ', $parts);
            }
            self::check(count($lines) === 9, 'catalogue_source_invalid');
            $lines[] = 'Серии iPhone 12–17 и iPhone Air — стоимость по запросу для точной модели и варианта дисплея.';
            $lines[] = 'Для iPhone 12 mini и 13 mini отдельно уточним подходящий вариант и его наличие.';
            return implode("\n", $lines);
        }
        $byModel = []; foreach ($offers as $offer) $byModel[$offer['model_ids'][0]] = $offer;
        $lines = [];
        foreach ($this->catalogue['model_groups'] as $family) {
            $groups = [];
            foreach ($family['model_ids'] as $id) {
                $offer = $byModel[$id]; $key = json_encode([$offer['price']['kind'],$offer['price']['amount'],$offer['warranty_months']], JSON_THROW_ON_ERROR);
                if (!isset($groups[$key])) $groups[$key] = ['price'=>$offer['price'],'labels'=>[]];
                $groups[$key]['labels'][] = $models[$id]['short_label'];
            }
            $parts = [];
            foreach ($groups as $group) $parts[] = implode(' / ', $group['labels']).' — '
                .($group['price']['kind'] === 'listed' ? self::money($group['price']['amount']) : 'стоимость уточняется');
            $lines[] = 'iPhone '.implode('; ', $parts);
        }
        return implode("\n", $lines);
    }

    private static function money(int $value): string { return number_format($value,0,'.',' ').' ₽'; }
    private static function readFile(string $path, int $max): string
    {
        self::check(is_file($path) && !is_link($path) && filesize($path) <= $max, 'catalogue_revision_unavailable');
        $raw = file_get_contents($path); self::check(is_string($raw), 'catalogue_revision_unavailable'); return $raw;
    }
    private static function plain($text, int $max): void
    {
        self::check(is_string($text) && trim($text) !== '' && mb_strlen($text,'UTF-8') <= $max
            && !str_contains($text,'<') && !str_contains($text,'>')
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$text) === 0, 'catalogue_post_invalid');
    }
    private static function check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
}


/** Pure fixed-content compiler. It cannot authorize, claim or send a provider operation. */
final class AppleService39SearchIntentRevision
{
    public const KEY = 'search-intent-v1';
    private const SOURCE_SHA256 = 'f92835c769d4a6875b5e8eab846cf13a52ee51030f71f3f5b0ff89b783f00bd3';
    private const PARENT_SHA256 = 'b2585f77ef5e3d062da62f1f6bcb7ef9ea13ddbaa07486649c8ea7b552a21e2b';
    private const CATALOGUE_SHA256 = 'f5eacf9afbb280f6c0bc58c926a4fd2b4bd0d07bbf36401848649b84fb27b9f2';
    private const ORDER = [
        'repair-iphone-16-display','service-rear-glass-replacement','symptom-not-charging',
        'service-rear-camera-replacement','service-earpiece-replacement','model-iphone-17',
        'service-display','menu',
    ];
    private array $data;
    private array $posts = [];
    private array $bindings = [];
    private string $directory;
    private int $totalLinks = 0;

    public function __construct(AppleService39PilotManifest $original, ?string $path = null)
    {
        $path ??= dirname(__DIR__).'/resources/appleservice39/search-intent-v1/manifest.json';
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 131072, 'search_manifest_unavailable');
        $raw = file_get_contents($path);
        self::check(is_string($raw) && hash('sha256', $raw) === self::SOURCE_SHA256, 'search_source_changed');
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($data) && ($data['schema_version'] ?? null) === 1
            && ($data['revision_key'] ?? null) === self::KEY
            && ($data['project_key'] ?? null) === AppleService39PilotManifest::PROJECT
            && ($data['bot_key'] ?? null) === AppleService39PilotManifest::BOT
            && ($data['campaign_key'] ?? null) === AppleService39PilotManifest::CAMPAIGN
            && ($data['source_key'] ?? null) === AppleService39PilotManifest::SOURCE
            && ($data['channel_url'] ?? null) === AppleService39PilotManifest::CHANNEL
            && ($data['channel_id'] ?? null) === '-80917954947727'
            && ($data['base_manifest_sha256'] ?? null) === $original->sha256()
            && ($data['parent_revision_sha256'] ?? null) === self::PARENT_SHA256
            && ($data['parent_catalogue_sha256'] ?? null) === self::CATALOGUE_SHA256
            && ($data['warranty_months'] ?? null) === 3 && ($data['action_order'] ?? null) === self::ORDER,
            'search_identity_changed');
        $parent = dirname(__DIR__).'/resources/appleservice39/catalogue-v1/';
        self::check(hash_file('sha256', $parent.'manifest.json') === self::PARENT_SHA256
            && hash_file('sha256', $parent.'catalog.json') === self::CATALOGUE_SHA256, 'search_parent_changed');
        $this->directory = dirname((string)realpath($path));
        $oldIds = array_column($original->posts(), 'id');
        self::check(is_array($data['historical_accepted_bindings'] ?? null)
            && array_is_list($data['historical_accepted_bindings'])
            && array_column($data['historical_accepted_bindings'], 'post_key') === $oldIds, 'search_baseline_changed');
        $mids = []; $urls = [];
        foreach ($data['historical_accepted_bindings'] as $binding) {
            self::check(is_string($binding['message_id'] ?? null)
                && preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $binding['message_id']) === 1
                && ($binding['state'] ?? null) === 'verified' && ($binding['write_attempts'] ?? null) === 1
                && is_string($binding['native_photo_id'] ?? null)
                && preg_match('/\A[1-9][0-9]{0,18}\z/D', $binding['native_photo_id']) === 1
                && self::publicAddress($binding['url'] ?? null)
                && !isset($mids[$binding['message_id']]) && !isset($urls[$binding['url']]), 'search_baseline_changed');
            $this->bindings[$binding['post_key']] = $binding;
            $mids[$binding['message_id']] = true; $urls[$binding['url']] = true;
        }
        self::check(($data['expected_pin_mid'] ?? null) === $this->bindings['menu']['message_id'], 'search_pin_changed');
        self::check(is_array($data['posts'] ?? null) && array_is_list($data['posts'])
            && count($data['posts']) === 8, 'search_exact_posts_required');
        foreach ($data['posts'] as $post) {
            self::check(is_array($post) && is_string($post['id'] ?? null)
                && in_array($post['id'], self::ORDER, true) && !isset($this->posts[$post['id']]), 'search_post_changed');
            $id = $post['id'];
            self::check(($post['action'] ?? null) === (isset($this->bindings[$id]) ? 'update_existing' : 'create_new')
                && ($post['button_label'] ?? null) === 'Написать менеджеру'
                && ($post['start_payload'] ?? null) === 'as39_'.$id
                && AppleService39PilotCatalog::landingPayloadForPost($id) === $post['start_payload'], 'search_entry_changed');
            self::plain($post['title'] ?? null, 120);
            self::check(is_array($post['paragraphs'] ?? null) && array_is_list($post['paragraphs'])
                && count($post['paragraphs']) >= 2 && count($post['paragraphs']) <= 6, 'search_text_invalid');
            foreach ($post['paragraphs'] as $paragraph) self::plain($paragraph, 1600);
            self::check(is_array($post['links'] ?? null) && array_is_list($post['links'])
                && count($post['links']) >= 1 && count($post['links']) <= 16, 'search_links_invalid');
            $linked = [];
            foreach ($post['links'] as $link) {
                self::check(is_array($link) && array_keys($link) === ['id','label'] && is_string($link['id'])
                    && in_array($link['id'], array_merge($oldIds, self::ORDER), true)
                    && $link['id'] !== $id && !isset($linked[$link['id']]), 'search_links_invalid');
                self::plain($link['label'], 128); $linked[$link['id']] = $link['label'];
            }
            if (isset($this->bindings[$id])) {
                foreach ($original->post($id)['links'] as $oldLink) {
                    self::check(($linked[$oldLink['id']] ?? null) === $oldLink['label'], 'search_legacy_link_removed');
                }
            }
            self::check(is_array($post['image'] ?? null)
                && ($post['image']['path'] ?? null) === 'covers/'.$id.'.png'
                && is_string($post['image']['sha256'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $post['image']['sha256']) === 1
                && is_int($post['image']['size_bytes'] ?? null) && $post['image']['size_bytes'] > 0
                && $post['image']['size_bytes'] < 1900000
                && ($post['image']['width'] ?? null) === 1254 && ($post['image']['height'] ?? null) === 1254,
                'search_image_invalid');
            $this->posts[$id] = $post;
            $this->validateArtwork($id);
        }
        $available = array_fill_keys($oldIds, true);
        foreach (self::ORDER as $id) {
            foreach ($this->posts[$id]['links'] as $link) {
                self::check(isset($available[$link['id']]), 'search_temporary_link_required');
            }
            $available[$id] = true;
        }
        foreach ($original->posts() as $old) {
            $this->totalLinks += count(($this->posts[$old['id']] ?? $old)['links']);
        }
        foreach ($this->posts as $id=>$post) if (!isset($this->bindings[$id])) $this->totalLinks += count($post['links']);
        self::check(count($available) === 30 && $this->totalLinks === 99, 'search_graph_changed');
        $this->data = $data;
    }

    public function sha256(): string { return self::SOURCE_SHA256; }
    public function posts(): array { return array_values($this->posts); }
    public function actionOrder(): array { return self::ORDER; }
    public function expectedPinMid(): string { return $this->data['expected_pin_mid']; }
    public function totalLinks(): int { return $this->totalLinks; }
    /** Historical approved binding only, never a fresh provider observation. */
    public function historicalBinding(string $id): ?array { return $this->bindings[$id] ?? null; }

    /** Individually admitted evidence for the two replacements, separate from the content hash. */
    public function reviewedNativeImage(string $id): ?array
    {
        $directory = $this->directory.'/reconciliation'; $indexPath = $directory.'/index.json';
        self::check(!is_link($directory) && is_file($indexPath) && !is_link($indexPath)
            && filesize($indexPath) <= 16384, 'search_review_evidence_invalid');
        $index = json_decode((string)file_get_contents($indexPath), true, 16, JSON_THROW_ON_ERROR);
        self::check(is_array($index) && array_keys($index) === ['schema_version','revision_key','project_key','policy','approvals']
            && $index['schema_version'] === 1 && $index['revision_key'] === self::KEY
            && $index['project_key'] === AppleService39PilotManifest::PROJECT
            && $index['policy'] === 'exact_source_and_native_visual_review'
            && is_array($index['approvals']) && array_is_list($index['approvals']) && count($index['approvals']) <= 2,
            'search_review_evidence_invalid');
        $selected = null; $seen = [];
        foreach ($index['approvals'] as $entry) {
            self::check(is_array($entry) && array_keys($entry) === ['post_id','proof_sha256','native_preview_sha256']
                && in_array($entry['post_id'], ['menu','service-display'], true) && !isset($seen[$entry['post_id']]),
                'search_review_evidence_invalid');
            foreach (['proof_sha256','native_preview_sha256'] as $key) self::check(is_string($entry[$key])
                && preg_match('/\A[a-f0-9]{64}\z/D',$entry[$key]) === 1, 'search_review_evidence_invalid');
            $seen[$entry['post_id']] = true;
            if ($entry['post_id'] === $id) $selected = $entry;
        }
        if ($selected === null) return null;
        $proofPath = $directory.'/'.$id.'.json'; $preview = $directory.'/'.$id.'.jpg';
        foreach ([$proofPath,$preview] as $file) self::check(is_file($file) && !is_link($file)
            && dirname((string)realpath($file)) === realpath($directory), 'search_review_evidence_invalid');
        self::check(filesize($proofPath) > 0 && filesize($proofPath) <= 16384 && filesize($preview) > 0
            && filesize($preview) <= 5242880 && hash_file('sha256',$proofPath) === $selected['proof_sha256']
            && hash_file('sha256',$preview) === $selected['native_preview_sha256'], 'search_review_evidence_invalid');
        $proof = json_decode((string)file_get_contents($proofPath),true,16,JSON_THROW_ON_ERROR); $image = @getimagesize($preview);
        self::check(is_array($proof) && is_array($image) && $image[2] === IMAGETYPE_JPEG
            && $image[0] > 0 && $image[0] <= 7680 && $image[1] > 0 && $image[1] <= 7680
            && ($proof['native_preview_width'] ?? null) === $image[0] && ($proof['native_preview_height'] ?? null) === $image[1]
            && ($proof['native_preview_sha256'] ?? null) === $selected['native_preview_sha256'], 'search_review_evidence_invalid');
        return ['proof'=>$proof,'evidence_sha256'=>$selected['proof_sha256'],'native_preview_sha256'=>$selected['native_preview_sha256']];
    }

    public function validateArtwork(string $id): string
    {
        self::check(isset($this->posts[$id]), 'search_post_outside_revision');
        $image = $this->posts[$id]['image'];
        $path = $this->directory.'/'.$image['path']; $resolved = realpath($path);
        self::check(is_string($resolved) && str_starts_with($resolved, $this->directory.'/covers/')
            && !is_link($path) && !is_link($this->directory.'/covers') && is_file($path)
            && filesize($path) === $image['size_bytes'] && hash_file('sha256', $path) === $image['sha256'],
            'search_source_image_changed');
        $size = @getimagesize($path);
        self::check(is_array($size) && ($size[2] ?? null) === IMAGETYPE_PNG
            && $size[0] === 1254 && $size[1] === 1254, 'search_source_image_invalid');
        return $path;
    }

    /** Addresses and upload data are compiler inputs, not evidence of native verification. */
    public function plan(string $id, array $upload, array $addresses): array
    {
        self::check(isset($this->posts[$id]), 'search_post_outside_revision');
        $post = $this->posts[$id]; $this->validateArtwork($id);
        $seen = [];
        foreach ($addresses as $key=>$url) {
            self::check(is_string($key) && (isset($this->bindings[$key]) || isset($this->posts[$key]))
                && self::publicAddress($url) && !isset($seen[$url]), 'search_address_invalid');
            if (isset($this->bindings[$key])) self::check($url === $this->bindings[$key]['url'], 'search_original_url_changed');
            $seen[$url] = true;
        }
        foreach ($this->bindings as $key=>$binding) {
            self::check(($addresses[$key] ?? null) === $binding['url'], 'search_original_url_missing');
        }
        foreach ($post['links'] as $link) self::check(isset($addresses[$link['id']]), 'search_link_address_missing');
        $keys = array_keys($upload); sort($keys);
        self::check($keys === ['photo_id','source_sha256','token']
            && is_string($upload['token']) && $upload['token'] !== '' && strlen($upload['token']) <= 32768
            && preg_match('/[\x00-\x1f\x7f]/', $upload['token']) === 0
            // The photos-map key may be zero. It is an upload index, never native image evidence.
            && is_string($upload['photo_id']) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $upload['photo_id']) === 1
            && $upload['source_sha256'] === $post['image']['sha256'], 'search_own_upload_required');
        $text = AppleService39LandingRevision::textPlan($post, $addresses, 'Услуги и варианты ремонта');
        $body = $text['body'];
        $body['attachments'] = [
            ['type'=>'image','payload'=>['token'=>$upload['token']]],
            ['type'=>'inline_keyboard','payload'=>['buttons'=>[[[
                'type'=>'link','text'=>$post['button_label'],
                'url'=>'https://max.ru/'.AppleService39PilotManifest::BOT.'?start='.$post['start_payload'],
            ]]]]],
        ];
        return [
            'state'=>'compiled_unpublished','post_id'=>$id,'action'=>$post['action'],
            'revision_sha256'=>self::SOURCE_SHA256,
            'source_image_sha256'=>$post['image']['sha256'],
            'compiled_body_sha256'=>hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'body'=>$body,'native_text'=>$text['native_text'],'native_markup'=>$text['native_markup'],
            'historical_binding'=>$this->bindings[$id] ?? null,
            'native_verified'=>false,'operation_claim_created'=>false,'pin_write_permitted'=>false,
        ];
    }

    private static function publicAddress($value): bool
    {
        return is_string($value)
            && preg_match('~\Ahttps://max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]{1,191}\z~D', $value) === 1;
    }
    private static function plain($value, int $limit): void
    {
        self::check(is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1
            && mb_strlen($value, 'UTF-8') <= $limit && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value) === 0,
            'search_text_invalid');
    }
    private static function check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
}
