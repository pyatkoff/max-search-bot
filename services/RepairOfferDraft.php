<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectConfiguration.php';

/** Prepares a reviewed catalog item for the existing channel publication flow. */
final class RepairOfferDraft
{
    private const OPERATIONS = [
        'battery-replacement' => 'Замена аккумулятора',
        'front-glass-replacement' => 'Замена стекла без дисплея',
        'display-replacement' => 'Замена дисплея',
    ];

    /** Select only the source rows pinned to a reviewed offer. */
    public static function catalogItem(array $catalog, array $item): array
    {
        $ids = $item['catalog_entry_ids'];
        if (($catalog['schema_version'] ?? null) !== 1 || ($catalog['business_key'] ?? null) !== $item['business_key']
            || !is_array($catalog['entries'] ?? null) || !array_is_list($catalog['entries'])) {
            throw new InvalidArgumentException('repair_offer_catalog_mismatch');
        }
        $rows = [];
        foreach ($catalog['entries'] as $entry) {
            if (!is_array($entry) || !in_array($entry['id'] ?? null, $ids, true)) continue;
            $id = $entry['id'];
            $scope = $entry['model_scope'] ?? [];
            $flags = $entry['review_flags'] ?? null;
            if (isset($rows[$id]) || ($entry['business_key'] ?? null) !== $item['business_key']
                || ($entry['operation_key'] ?? null) !== str_replace('-', '_', $item['operation'])
                || ($scope['kind'] ?? null) !== 'model' || ($scope['label'] ?? null) !== $item['model']
                || ($scope['model_keys'] ?? null) !== [str_replace('-', '_', $item['model_key'])]
                || ($entry['price']['currency'] ?? null) !== 'RUB' || !is_array($flags) || !array_is_list($flags)
                || !is_int($entry['source_table'] ?? null) || $entry['source_table'] < 1
                || !is_int($entry['source_row'] ?? null) || $entry['source_row'] < 1) {
                throw new InvalidArgumentException('repair_offer_catalog_row_mismatch');
            }
            foreach ($flags as $flag) {
                if (!is_string($flag)) throw new InvalidArgumentException('repair_offer_catalog_row_mismatch');
                if ($flag !== 'duration_conflict') $item['price_review_required'] = true;
            }
            $rows[$id] = [
                'url' => $entry['source_url'] ?? null,
                'label_raw' => $entry['source_cells'][0] ?? null,
                'price_raw' => $entry['price']['raw'] ?? null,
                'warranty_raw' => $entry['warranty']['raw'] ?? null,
                'duration_raw' => $entry['duration_raw'] ?? null,
                'catalog_ref' => ['entry_id' => $id, 'table' => $entry['source_table'], 'row' => $entry['source_row']],
            ];
        }
        if (count($rows) !== count($ids)) throw new InvalidArgumentException('repair_offer_catalog_row_missing');
        $item['source_rows'] = array_values($rows);
        return $item;
    }

    public static function prepare(ProjectConfiguration $project, array $item): array
    {
        $businessKey = $project->get('business.key');
        $sourceHost = $project->get('business.source_host');
        if ($project->get('business.profile') !== 'device_repair' || !is_string($businessKey)
            || !preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $businessKey)
            || ($item['business_key'] ?? null) !== $businessKey || !is_string($sourceHost) || $sourceHost === '') {
            throw new InvalidArgumentException('repair_offer_business_mismatch');
        }

        $modelKey = $item['model_key'] ?? null;
        $model = self::label($item['model'] ?? null);
        $modelScope = $item['model_scope'] ?? null;
        $operation = $item['operation'] ?? null;
        $reviewRequired = $item['price_review_required'] ?? null;
        $imageAsset = $item['image_asset'] ?? null;
        $imageHash = $item['image_sha256'] ?? null;
        if (!is_string($modelKey) || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $modelKey)
            || strlen($modelKey) > 48 || !in_array($modelScope, ['model', 'series'], true)
            || !is_string($operation) || !isset(self::OPERATIONS[$operation]) || !is_bool($reviewRequired)) {
            throw new InvalidArgumentException('repair_offer_invalid_item');
        }
        if (!is_string($imageAsset) || !preg_match('/\A[a-z0-9-]{1,80}\.(?:png|jpg|webp)\z/', $imageAsset)
            || !is_string($imageHash) || !preg_match('/\A[a-f0-9]{64}\z/', $imageHash)) {
            throw new InvalidArgumentException('repair_offer_invalid_image_asset');
        }

        $sources = $item['source_rows'] ?? null;
        if (!is_array($sources) || !array_is_list($sources) || $sources === [] || count($sources) > 20) {
            throw new InvalidArgumentException('repair_offer_invalid_sources');
        }
        $rows = [];
        foreach ($sources as $source) {
            if (!is_array($source)) throw new InvalidArgumentException('repair_offer_invalid_sources');
            $url = self::sourceUrl($source['url'] ?? null, $sourceHost);
            $sourceKey = rtrim($url, '/');
            if (isset($rows[$sourceKey])) throw new InvalidArgumentException('repair_offer_duplicate_source');
            $rawPrice = self::label($source['price_raw'] ?? null);
            [$kind, $amount] = self::price($rawPrice);
            $rows[$sourceKey] = [
                'url' => $url,
                'label_raw' => self::label($source['label_raw'] ?? null),
                'price_raw' => $rawPrice,
                'price_kind' => $kind,
                'price_amount_rub' => $amount,
                'warranty_raw' => self::optionalLabel($source['warranty_raw'] ?? null),
                'duration_raw' => self::optionalLabel($source['duration_raw'] ?? null),
            ];
            if (isset($source['catalog_ref'])) $rows[$sourceKey]['catalog_ref'] = $source['catalog_ref'];
        }
        ksort($rows, SORT_STRING);
        $rows = array_values($rows);

        $price = null;
        if (!$reviewRequired && $modelScope === 'model' && count($rows) >= 2 && $rows[0]['price_kind'] === 'exact') {
            $price = $rows[0]['price_amount_rub'];
            foreach ($rows as $row) {
                if ($row['price_kind'] !== 'exact' || $row['price_amount_rub'] !== $price) {
                    $price = null;
                    break;
                }
            }
        }

        $title = self::OPERATIONS[$operation] . ' ' . $model;
        $priceText = $price === null ? 'Стоимость уточняйте у специалиста.' : number_format($price, 0, '', ' ') . ' ₽';
        $offer = [
            'project_key' => $project->projectId(),
            'business_key' => $businessKey,
            'offer_id' => $businessKey . ':' . $modelKey . ':' . $operation,
            'model' => $model,
            'model_scope' => $modelScope,
            'operation' => $operation,
            'price_rub' => $price,
            'price_review_required' => $reviewRequired,
            'source_rows' => $rows,
            'publication' => [
                'state' => 'draft',
                'title' => $title,
                'caption' => $title . "\n" . $priceText . "\n" . self::label($project->get('brand.name'))
                    . ' · ' . self::label($project->get('business.city')),
                'image_asset' => $imageAsset,
                'image_sha256' => $imageHash,
                'button_text' => 'Уточнить ремонт',
            ],
        ];
        $offer['offer_version'] = hash('sha256', json_encode($offer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $offer;
    }

    private static function price(string $raw): array
    {
        if (preg_match('/\A(?:уточняйте|по запросу)\.?\z/iu', $raw)) return ['on_request', null];
        if (preg_match('/\Aбесплатно\z/iu', $raw)) return ['free', 0];
        if (!preg_match('/\A(от\h+)?(0|[1-9][0-9]{0,8}|[1-9][0-9]{0,2}(?:\h[0-9]{3}){1,2})\h*(?:руб\.?|₽)\z/iu', $raw, $parts)) {
            return ['unrecognized', null];
        }
        $amount = (int)preg_replace('/\h/u', '', $parts[2]);
        if ($parts[1] !== '') return ['from', $amount];
        return [$amount === 0 ? 'free' : 'exact', $amount];
    }

    private static function sourceUrl($url, string $host): string
    {
        $parts = is_string($url) && strlen($url) <= 512 ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== $host
            || !preg_match('~\A/(?:[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*/)*[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*/?\z~', $parts['path'] ?? '')
            || isset($parts['user']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || $url !== 'https://' . $host . $parts['path']) {
            throw new InvalidArgumentException('repair_offer_invalid_source_url');
        }
        return $url;
    }

    private static function label($value): string
    {
        if (!is_string($value) || strlen($value) > 240 || trim($value) === ''
            || preg_match('/\A[^\p{C}]+\z/u', $value) !== 1) {
            throw new InvalidArgumentException('repair_offer_invalid_label');
        }
        return trim($value);
    }

    private static function optionalLabel($value): ?string
    {
        return $value === null ? null : self::label($value);
    }
}
