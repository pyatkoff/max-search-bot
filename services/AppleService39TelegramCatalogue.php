<?php
declare(strict_types=1);

require_once __DIR__.'/AppleService39PilotCatalog.php';

final class AppleService39TelegramCatalogue
{
    public const CAMPAIGN = 'appleservice39-telegram-catalogue-v1';
    public const BATCH_SIZE = 8;

    private array $posts = [];
    private string $manifestSha;

    public function __construct()
    {
        $manifest = file_get_contents(dirname(__DIR__).'/resources/appleservice39/telegram-catalogue-v1/manifest.json');
        if ($manifest === false) throw new RuntimeException('telegram_catalogue_unavailable');
        $this->manifestSha = hash('sha256', $manifest);
        $data = json_decode($manifest, true, 32, JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? null) !== 1 || ($data['project_key'] ?? null) !== 'appleservice39'
            || !is_array($data['posts'] ?? null) || count($data['posts']) !== 69) {
            throw new RuntimeException('telegram_catalogue_invalid');
        }
        foreach ($data['posts'] as $post) {
            $id = $post['id'] ?? '';
            if (!preg_match('/\A[a-z0-9-]{1,59}\z/D', $id) || str_starts_with($id, 'device-')
                || isset($this->posts[$id]) || AppleService39PilotCatalog::landingPayloadForPost($id) !== 'as39_'.$id
                || !is_string($post['title'] ?? null) || $post['title'] === ''
                || !is_array($post['paragraphs'] ?? null) || !$post['paragraphs']
                || count(array_filter($post['paragraphs'], 'is_string')) !== count($post['paragraphs'])
                || !preg_match('~\Aresources/appleservice39/(?:pilot/covers|landing-v2/artwork/covers|search-intent-v1/covers)/[a-z0-9-]+\.png\z~D', $post['image']['path'] ?? '')
                || !preg_match('/\A[a-f0-9]{64}\z/D', $post['image']['sha256'] ?? '')) {
                throw new RuntimeException('telegram_catalogue_invalid');
            }
            $post['button_label'] = 'Узнать стоимость ремонта';
            $this->posts[$id] = $post;
        }
    }

    public function order(): array { return array_keys($this->posts); }
    public function post(string $id): array { return $this->posts[$id]; }
    public function sha256(): string { return $this->manifestSha; }
    public function assetSha(string $id): string { return $this->post($id)['image']['sha256']; }

    public function artwork(string $id): string
    {
        $path = dirname(__DIR__).'/'.$this->post($id)['image']['path'];
        if (!is_file($path) || is_link($path) || filesize($path) > 5242880
            || hash_file('sha256', $path) !== $this->assetSha($id)) {
            throw new RuntimeException('telegram_catalogue_artwork_invalid');
        }
        return $path;
    }
}
