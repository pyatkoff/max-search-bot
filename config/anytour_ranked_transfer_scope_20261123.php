<?php
declare(strict_types=1);

final class AnyTourRankedHotelTransfer46
{
    public const REGISTRY_FIELDS = ['hotel_id','source_message_id','source_publication_id','source_version_id','source_content_hash',
        'source_caption_sha256','source_file_id_sha256','source_file_unique_id_sha256','target_message_id',
        'target_published_unixtime','target_caption_sha256','target_photo_marker_sha256','target_config_version',
        'next_caption','next_caption_sha256','button_text','bot_url','price_record_id'];

    // Stella has a separate, already published proof format.
    private const SPECIAL_PLANS = [
        'c71a7e945c0978910267ecb729dd25449a45b66e5fa4874c669c7592fae27aab' => [
            'path' => 'config/anytour_stella_alternate_20261123.json',
            'operation' => 'anytour_hotel_transfer_stella_alternate_20261123',
            'native_preflight' => [
                'run_id' => '45275242345',
                'run_attempt' => 1,
                'job_id' => 138045244207,
                'read_head_sha' => '68b789067a576da57cf7ec16533f658e437f6e3c',
                'source_run_id' => '45156882185',
                'previous_apply_run_id' => '45272173038',
            ],
            'pairs' => [
                466 => [
                    'source' => '473',
                    'source_publication' => 1047,
                    'target' => '925',
                    'date' => 1731241439,
                    'registry_sha256' => 'd18f0ef5717051a20058b73e6cb5125c8d77f1803e7780b762c415a40687f28e',
                    'caption_sha256' => '682eab065d04167a2ba1b0ed2e31fe37dba4f45bf6bdeb1cab0cc6653add8e5f',
                    'file_id_sha256' => 'd977212dead5341218feecdcd4d36ce49933cc5c689b11dae4305912233453d9',
                ],
            ],
        ],
    ];


    /** Only immutable, release-installed HOTEL indices can nominate plans. */
    public static function profile(string $digest): ?array
    {
        if (isset(self::SPECIAL_PLANS[$digest])) return self::SPECIAL_PLANS[$digest];
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $digest)) return null;
        static $profiles = [];
        if (isset($profiles[$digest])) return $profiles[$digest];

        $approvals = json_decode(file_get_contents(__DIR__.'/anytour_ranked_approved_indexes.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($approvals as $approval) {
            $index = self::document($approval['path'], $approval['sha256'], 64000);
            foreach ($index['plans'] as $item) {
                if ($item['sha256'] !== $digest) continue;
                $plan = self::document($item['path'], $digest, 64000);
                if (($plan['native_preflight'] ?? null) !== $index['native_preflight'] || !is_array($plan['rows'])
                    || !array_is_list($plan['rows']) || !$plan['rows']) throw new RuntimeException('transfer_ranked_native_proof_changed');
                $pairs = []; $sources = []; $targets = [];
                foreach ($plan['rows'] as $row) {
                    $hotel = $row['hotel_id']; $source = $row['source_message_id']; $target = $row['target_message_id'];
                    if (!is_int($hotel) || $hotel < 1 || !is_string($source) || !is_string($target)
                        || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $source) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $target)
                        || isset($pairs[$hotel]) || isset($targets[$target]) || isset($sources[$source]))
                        throw new RuntimeException('transfer_plan_row_identity');
                    $identity = [];
                    foreach (self::REGISTRY_FIELDS as $key) $identity[$key] = $row[$key];
                    $pairs[$hotel] = ['source'=>$source, 'source_publication'=>$row['source_publication_id'],
                        'target'=>$target, 'date'=>$row['target_published_unixtime'],
                        'registry_sha256'=>hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                        'caption_sha256'=>$row['next_caption_sha256'], 'file_id_sha256'=>$row['source_file_id_sha256']];
                    $sources[$source] = $targets[$target] = true;
                }
                return $profiles[$digest] = ['path'=>$item['path'], 'operation'=>$plan['operation'],
                    'native_preflight'=>$index['native_preflight'], 'approved_index_sha256'=>$approval['sha256'], 'pairs'=>$pairs];
            }
        }
        return null;
    }

    private static function document(string $path, string $digest, int $limit): array
    {
        if (!preg_match('~\Aconfig/anytour_[a-z0-9_]+\.json\z~D', $path))
            throw new RuntimeException('transfer_ranked_path_invalid');
        $path = dirname(__DIR__).'/'.$path;
        if (realpath($path) !== $path || !is_file($path)) throw new RuntimeException('transfer_plan_unavailable');
        $raw = file_get_contents($path, false, null, 0, $limit + 1);
        if (!is_string($raw) || strlen($raw) > $limit || !hash_equals($digest, hash('sha256', $raw)))
            throw new RuntimeException('transfer_approved_plan_changed');
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }
}
