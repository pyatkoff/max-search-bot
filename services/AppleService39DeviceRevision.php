<?php
declare(strict_types=1);

/** The four reviewed device-category posts share the existing Apple publication ledger. */
final class AppleService39DeviceRevision
{
    public const KEY = 'device-v1';
    public const UPLOAD = 'device_upload';
    public const POSTS = ['device-iphone-18', 'device-ipad', 'device-macbook', 'device-apple-watch'];

    private array $posts = [];
    private string $sha;

    public function __construct()
    {
        $path = dirname(__DIR__).'/resources/appleservice39/device-v1/manifest.json';
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 65536);
        $source = file_get_contents($path);
        self::check(is_string($source));
        $data = json_decode($source, true, 12, JSON_THROW_ON_ERROR);
        self::check(($data['schema_version'] ?? null) === 1 && ($data['revision_key'] ?? null) === self::KEY
            && ($data['project_key'] ?? null) === 'appleservice39' && ($data['channel_id'] ?? null) === '-80917954947727'
            && is_array($data['posts'] ?? null) && array_column($data['posts'], 'id') === self::POSTS);
        foreach ($data['posts'] as $post) {
            $id = $post['id'];
            self::check(count($post) === 5 && is_array($post['paragraphs'] ?? null) && array_is_list($post['paragraphs'])
                && count($post['paragraphs']) > 0 && count($post['paragraphs']) <= 8
                && preg_match('/\A[0-9a-f]{64}\z/D', $post['cover_sha256'] ?? '') === 1);
            self::plain($post['title'] ?? null, 150);
            self::plain($post['button_label'] ?? null, 64);
            foreach ($post['paragraphs'] as $text) self::plain($text, 1200);
            self::check(AppleService39PilotCatalog::landingPayloadForPost($id) === 'as39_'.$id);
            $this->posts[$id] = $post;
        }
        self::check(count(array_unique(array_column($this->posts, 'cover_sha256'))) === count(self::POSTS));
        $this->sha = hash('sha256', $source);
    }

    public function sha256(): string { return $this->sha; }
    public function order(): array { return self::POSTS; }
    public function post(string $id): array
    {
        self::check(isset($this->posts[$id]));
        return $this->posts[$id];
    }
    public function assetSha(string $id): string { return $this->post($id)['cover_sha256']; }

    public function artwork(string $id): string
    {
        $sha = $this->assetSha($id);
        $path = dirname(__DIR__).'/resources/appleservice39/device-v1/covers/'.$id.'.png';
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 5242880 && hash_file('sha256', $path) === $sha);
        $image = getimagesize($path);
        self::check(is_array($image) && ($image['mime'] ?? null) === 'image/png'
            && $image[0] >= 256 && $image[0] <= 4096 && $image[1] >= 256 && $image[1] <= 4096);
        return $path;
    }

    public function plan(string $id, array $upload): array
    {
        $post = $this->post($id);
        self::check(is_string($upload['token'] ?? null) && $upload['token'] !== '');
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = implode("\n\n", $post['paragraphs']);
        $native = $post['title']."\n\n".$paragraphs;
        $html = '<b>'.$escape($post['title']).'</b>'."\n\n".$escape($paragraphs);
        self::check(mb_strlen($html, 'UTF-8') <= 4000);
        $button = ['type'=>'link', 'text'=>$post['button_label'],
            'url'=>'https://max.ru/'.AppleService39PilotManifest::BOT.'?start='.AppleService39PilotCatalog::landingPayloadForPost($id)];
        return ['body'=>['text'=>$html, 'format'=>'html', 'attachments'=>[
            ['type'=>'image', 'payload'=>['token'=>$upload['token']]],
            ['type'=>'inline_keyboard', 'payload'=>['buttons'=>[[$button]]]],
        ], 'notify'=>false], 'native_text'=>$native, 'native_markup'=>[['type'=>'strong', 'from'=>0,
            'length'=>mb_strlen($post['title'], 'UTF-8')]]];
    }

    public static function isOperation(string $post, string $operation, array $request): bool
    {
        return in_array($post, self::POSTS, true) || $operation === self::UPLOAD || ($request['revision'] ?? null) === self::KEY;
    }

    private static function plain($value, int $limit): void
    {
        self::check(is_string($value) && trim($value) !== '' && mb_strlen($value,'UTF-8') <= $limit
            && preg_match('/[\x00-\x1f<>]/u', $value) !== 1);
    }

    private static function check(bool $ok): void
    {
        if (!$ok) throw new RuntimeException('device_revision_invalid');
    }
}
