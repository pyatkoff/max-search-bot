<?php

declare(strict_types=1);

/** Immutable, server-owned knowledge snapshot; never constructed from HTTP/chat data. */
final class ManagerCompanyKnowledge
{
    private function __construct(private readonly array $facts, private readonly string $revision) {}

    public function facts(): array { return $this->facts; }
    public function revision(): string { return $this->revision; }

    public static function forProject(string $projectKey, ?DateTimeImmutable $now = null): self
    {
        $path = dirname(__DIR__).'/knowledge/company.json';
        $bytes = is_file($path) && filesize($path) <= 65536 ? file_get_contents($path) : false;
        $catalog = is_string($bytes) ? json_decode($bytes, true, 16) : null;
        // Only this repository-owned file is a supported source in the first slice.
        // A source edit withdraws its facts until their provenance is reviewed again.
        $sourceHash = hash_file('sha256', dirname(__DIR__).'/project.php');
        return self::select($catalog, $projectKey, $now ?? new DateTimeImmutable('now'), $sourceHash ?: '');
    }

    private static function select($catalog, string $projectKey, DateTimeImmutable $now, string $sourceHash): self
    {
        $facts = [];
        $validCatalog = is_array($catalog) && ($catalog['schema_version'] ?? null) === 1
            && self::text($catalog['version'] ?? null, 80)
            && is_array($catalog['facts'] ?? null) && array_is_list($catalog['facts'])
            && count($catalog['facts']) <= 50;
        $ids = [];
        if ($validCatalog && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $projectKey)) {
            foreach ($catalog['facts'] as $fact) {
                if (!is_array($fact) || ($fact['project_key'] ?? null) !== $projectKey
                    || ($fact['status'] ?? null) !== 'approved') continue;
                $id = $fact['id'] ?? null;
                $source = $fact['source'] ?? null;
                $reviewed = self::timestamp($fact['reviewed_at'] ?? null);
                $expires = self::timestamp($fact['expires_at'] ?? null);
                if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9_.-]{0,79}\z/D', $id)
                    || !self::text($fact['text'] ?? null, 600)
                    || !self::text($fact['topic'] ?? null, 80)
                    || !self::text($fact['approval'] ?? null, 400)
                    || !is_array($source) || ($source['path'] ?? null) !== 'project.php'
                    || !in_array($source['field'] ?? null, ['brand.name','search.base_domain'], true)
                    || !is_string($source['sha256'] ?? null) || $sourceHash === ''
                    || !hash_equals($sourceHash, $source['sha256'])
                    || $reviewed === null || $expires === null || $reviewed > $now
                    || $expires <= $now || $expires <= $reviewed) continue;
                if (isset($ids[$id])) { $facts = []; break; }
                $ids[$id] = true;
                $facts[] = ['id'=>$id, 'topic'=>$fact['topic'], 'text'=>$fact['text'],
                    'reviewed_at'=>$fact['reviewed_at'], 'expires_at'=>$fact['expires_at'],
                    'source'=>['path'=>$source['path'], 'field'=>$source['field'], 'sha256'=>$source['sha256']],
                    'approval'=>$fact['approval']];
            }
        }
        // Approval/version/source edits invalidate drafts even if the visible text stays equal.
        $revision = hash('sha256', json_encode(['schema'=>1, 'project_key'=>$projectKey,
            'catalog'=>$catalog, 'source_hash'=>$sourceHash, 'eligible_facts'=>$facts], JSON_THROW_ON_ERROR));
        return new self($facts, $revision);
    }

    private static function timestamp($value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date : null;
    }

    private static function text($value, int $limit): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $limit * 4
            && preg_match('//u', $value) === 1 && preg_match_all('/./us', $value) <= $limit
            && preg_match('/[\x00-\x1f\x7f]/', $value) === 0;
    }
}
