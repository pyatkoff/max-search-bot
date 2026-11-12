<?php
declare(strict_types=1);

/** Reviewed company data/compiler. No provider call, claim or runtime setting write. */
final class AppleService39CoverageRevision
{
    public const KEY = 'coverage-v1';
    public const UPLOAD = 'coverage_upload';
    public const MENU = 'coverage_menu';
    public const COVER = 'coverage-v1-cover';
    private const POSTS = ['model-iphone-8','model-iphone-8-plus','model-iphone-xr','model-iphone-xs','model-iphone-xs-max','model-iphone-11','model-iphone-11-pro','model-iphone-11-pro-max','model-iphone-12','model-iphone-12-mini','model-iphone-12-pro','model-iphone-12-pro-max','model-iphone-13','model-iphone-13-mini','model-iphone-13-pro','model-iphone-13-pro-max','model-iphone-14','model-iphone-14-plus','model-iphone-14-pro','model-iphone-14-pro-max','model-iphone-16','model-iphone-16-plus','model-iphone-16-pro','model-iphone-16-pro-max','model-iphone-17-pro','model-iphone-17-pro-max','model-iphone-air','models-index','service-charging-port-replacement','service-microphone-replacement','service-loudspeaker-replacement','service-front-camera-replacement','service-home-button-repair','service-power-button-repair','service-antenna-repair','service-software-recovery','service-diagnostics','service-housing-repair','services-index'];
    private const SHA = 'e4dfe5234befdc85cdbd19606af9588bb74af37021334d4a639ad6e4e23e7dc1';
    private array $data;
    private array $posts;

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__.'/../resources/appleservice39/coverage-v1/manifest.json';
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 262144
            && hash_file('sha256', $path) === self::SHA, 'coverage_source_changed');
        $data = json_decode((string)file_get_contents($path), true, 20, JSON_THROW_ON_ERROR);
        self::check(($data['schema_version'] ?? null) === 1 && ($data['revision_key'] ?? null) === self::KEY
            && ($data['project_key'] ?? null) === 'appleservice39'
            && ($data['bot_key'] ?? null) === 'id390521129062_bot'
            && ($data['channel_id'] ?? null) === '-80917954947727'
            && ($data['parent_operations'] ?? null) === 152 && ($data['parent_posts'] ?? null) === 30
            && ($data['expected_posts'] ?? null) === 69 && ($data['expected_feed_offers'] ?? null) === 67
            && is_array($data['posts'] ?? null) && count($data['posts']) === 39, 'coverage_source_invalid');
        $posts = [];
        foreach ($data['posts'] as $post) {
            self::check(is_array($post) && is_string($post['id'] ?? null)
                && preg_match('/\A[a-z][a-z0-9_-]{0,55}\z/D', $post['id']) === 1
                && !isset($posts[$post['id']]) && in_array($post['kind'] ?? null, ['model','service','navigation'], true)
                && is_string($post['title'] ?? null) && mb_strlen($post['title'], 'UTF-8') <= 150
                && is_array($post['paragraphs'] ?? null) && count($post['paragraphs']) >= 2
                && is_array($post['links'] ?? null) && count($post['links']) >= 1
                && count($post['links']) <= 34 && ($post['button_label'] ?? null) === 'Уточнить ремонт',
                'coverage_post_invalid');
            foreach ([$post['title'], ...$post['paragraphs']] as $text) self::plain($text);
            foreach ($post['links'] as $link) {
                self::check(is_string($link['id'] ?? null) && preg_match('/\A[a-z][a-z0-9_-]{0,55}\z/D', $link['id']) === 1,
                    'coverage_link_invalid');
                self::plain($link['label'] ?? null);
            }
            $posts[$post['id']] = $post;
        }
        self::check(array_keys($posts) === $data['action_order'] && array_keys($posts) === self::POSTS, 'coverage_order_changed');
        $catalogue = __DIR__.'/../resources/appleservice39/catalogue-v1/catalog.json';
        self::check(hash_file('sha256', $catalogue) === 'f5eacf9afbb280f6c0bc58c926a4fd2b4bd0d07bbf36401848649b84fb27b9f2',
            'coverage_catalogue_changed');
        $models = json_decode((string)file_get_contents($catalogue), true, 20, JSON_THROW_ON_ERROR)['models'];
        self::check(array_column($models, 'id') === $data['model_ids'], 'coverage_models_changed');
        $names = array_column($models, 'label', 'id');
        foreach ($posts as $post) if ($post['kind'] === 'model') {
            self::check(($names[$post['model_id']] ?? null) === $post['model']
                && $post['id'] === 'model-'.$post['model_id'], 'coverage_models_changed');
        }
        $this->data = $data;
        $this->posts = $posts;
    }

    public function sha256(): string { return self::SHA; }
    public function posts(): array { return $this->posts; }
    public function order(): array { return $this->data['action_order']; }
    public function post(string $id): array
    {
        self::check(isset($this->posts[$id]), 'coverage_post_invalid');
        return $this->posts[$id];
    }
    public function assetSha(): string { return $this->data['cover']['sha256']; }
    public function artwork(): string
    {
        $path = dirname(__DIR__).'/'.$this->data['cover']['path'];
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 5242880
            && hash_file('sha256', $path) === $this->assetSha(), 'coverage_image_changed');
        $size = getimagesize($path);
        self::check(is_array($size) && $size[0] === 1254 && $size[1] === 1254
            && ($size['mime'] ?? null) === 'image/png', 'coverage_image_changed');
        return $path;
    }
    public function entry(string $id): ?array
    {
        $post = $this->posts[$id] ?? null;
        if (!$post) return null;
        return ['post_id'=>$id, 'model'=>$post['model'], 'service'=>$post['service'],
            'scope'=>$post['kind'] === 'navigation' ? 'menu' : $post['kind'], 'start_payload'=>'as39_'.$id];
    }
    public function plan(string $id, array $upload, array $addresses): array
    {
        $post = $this->post($id);
        $text = AppleService39LandingRevision::textPlan($post, $addresses);
        self::check(is_string($upload['token'] ?? null) && $upload['token'] !== '', 'coverage_upload_required');
        $text['body']['attachments'] = [
            ['type'=>'image','payload'=>['token'=>$upload['token']]],
            ['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'link','text'=>'Уточнить ремонт',
                'url'=>'https://max.ru/id390521129062_bot?start=as39_'.$id]]]]],
        ];
        $text['body']['notify'] = false;
        return $text;
    }
    public function menuPlan(array $addresses): array
    {
        $path = __DIR__.'/../resources/appleservice39/search-intent-v1/manifest.json';
        self::check(hash_file('sha256', $path) === $this->data['parent_revision_sha256'], 'coverage_parent_changed');
        $old = json_decode((string)file_get_contents($path), true, 20, JSON_THROW_ON_ERROR);
        $menu = array_values(array_filter($old['posts'], static fn(array $post): bool => $post['id'] === 'menu'))[0] ?? null;
        self::check(is_array($menu), 'coverage_parent_changed');
        $menu['links'] = array_merge($menu['links'], $this->data['menu_extra_links']);
        return AppleService39LandingRevision::textPlan($menu, $addresses, 'Услуги и варианты ремонта');
    }
    public static function isOperation(string $post, string $operation, array $request): bool
    {
        return str_starts_with($operation, 'coverage_') || ($request['revision'] ?? null) === self::KEY
            || $post === self::COVER || in_array($post, self::POSTS, true);
    }
    private static function plain($text): void
    {
        self::check(is_string($text) && trim($text) !== '' && mb_strlen($text, 'UTF-8') <= 1200
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f<>]/u', $text) !== 1, 'coverage_text_invalid');
    }
    private static function check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
}
