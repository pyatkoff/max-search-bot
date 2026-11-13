<?php
declare(strict_types=1);

/** Owner-approved DB identities for the isolated ANEX search preview. */
final class AnyTourAnexSearchMappingRegistry
{
    // Exact policy/class pairs from completed guarded MATCH acceptances. Do not
    // admit arbitrary enabled rows, policy prefixes, or a class under another policy.
    private const APPROVED_CLASSES = [
        'owner_exact_and_strong_20260908' => ['exact', 'strong_candidate'],
        'owner_exact_operator_key_20260912' => ['exact_operator_key'],
        'owner_exact_operator_key_20260912_v2' => ['exact_operator_key'],
        'owner_coordinate_name_geo_rescue_20260912_v1' => ['coordinate_name_geo'],
        'owner_multi_evidence_consensus_20260912_v1' => ['multi_evidence_consensus'],
        'owner_current_exact_cross_provider_20260912' => ['exact_cross_provider'],
    ];
    private const MAX_ROWS = 50000;
    private const PROVIDERS = ['anex_xml' => true, 'anex_online' => true];
    private $index;

    private function __construct(array $index)
    {
        $this->index = $index;
    }

    public static function fromPdo(PDO $pdo): self
    {
        $policyClauses = [];
        $policyParameters = [];
        foreach (self::APPROVED_CLASSES as $policy => $classes) {
            $policyClauses[] = '(m.approval_policy=? AND m.match_class IN ('
                . implode(',', array_fill(0, count($classes), '?')) . '))';
            $policyParameters[] = $policy;
            foreach ($classes as $class) $policyParameters[] = $class;
        }
        $mappings = $pdo->prepare(
            'SELECT m.anex_hotel_id,m.catalog_hotel_id,m.match_class,m.approval_policy,m.enabled,m.scope,'
            . ' h.id AS existing_catalog_hotel_id FROM anex_hotel_search_mappings m'
            . ' INNER JOIN catalog_hotels h ON h.id=m.catalog_hotel_id'
            . " WHERE m.enabled=1 AND m.scope='preview' AND ("
            . implode(' OR ', $policyClauses) . ') LIMIT 50001'
        );
        $mappings->execute($policyParameters);
        $mappingRows = $mappings->fetchAll(PDO::FETCH_ASSOC);
        // Read all decisions, including decisions with a missing target, so an
        // explicit block can never disappear through an inner join or fallback.
        $decisions = $pdo->query(
            'SELECT d.anex_hotel_id,d.decision_status,d.catalog_hotel_id,'
            . ' h.id AS existing_catalog_hotel_id FROM anex_hotel_decisions d'
            . ' LEFT JOIN catalog_hotels h ON h.id=d.catalog_hotel_id LIMIT 50001'
        );
        $decisionRows = $decisions->fetchAll(PDO::FETCH_ASSOC);
        try {
            $exclusions = $pdo->query('SELECT anex_hotel_id,catalog_hotel_id FROM anex_review_pair_exclusions LIMIT 50001');
            if ($exclusions === false) throw new RuntimeException('anex_search_mapping_exclusions_unavailable');
            $pairs = $exclusions->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            // Legacy deployments have no review schema. Only a missing table is
            // optional: permission, schema and connection failures stay closed.
            $info = $error->errorInfo ?? [];
            $missing = ($info[0] ?? null) === '42S02' && (int)($info[1] ?? 0) === 1146;
            if ($missing) {
                // A broken view/dependency can also report 1146. Its existence
                // means review is installed and the read must not be bypassed.
                $present = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='anex_review_pair_exclusions'");
                if ($present === false || $present->fetchColumn() !== false) throw $error;
            }
            $missingSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && (int)($info[1] ?? 0) === 1
                && ($info[2] ?? '') === 'no such table: anex_review_pair_exclusions';
            if (!$missing && !$missingSqlite) throw $error;
            $pairs = [];
        }
        return self::fromRows($mappingRows, $decisionRows, $pairs);
    }

    /**
     * Rows use the SQL projection above; existing_catalog_hotel_id establishes
     * that the target exists. No price or availability evidence is required.
     */
    public static function fromRows(array $mappingRows, array $decisionRows = [], array $pairExclusions = []): self
    {
        if (count($mappingRows) > self::MAX_ROWS || count($decisionRows) > self::MAX_ROWS
            || count($pairExclusions) > self::MAX_ROWS) {
            throw new UnexpectedValueException('anex_search_mapping_registry_invalid');
        }
        $index = [];
        $seen = [];
        foreach ($mappingRows as $row) {
            $id = self::rowId($row, $seen);
            $target = self::target($row);
            if (!in_array($row['enabled'] ?? null, [1, '1', true], true)
                || ($row['scope'] ?? null) !== 'preview'
                || !self::approved($row)
                || $target === null) {
                continue;
            }
            $index[$id] = $target;
        }
        $seen = [];
        foreach ($decisionRows as $row) {
            $id = self::rowId($row, $seen);
            // Every manual decision supersedes the policy mapping. Only an
            // explicit acceptance with an existing target can enable identity.
            unset($index[$id]);
            $target = self::target($row);
            if (($row['decision_status'] ?? null) === 'accepted' && $target !== null) {
                $index[$id] = $target;
            }
        }
        foreach ($pairExclusions as $pair) {
            $id = is_array($pair) ? self::id($pair['anex_hotel_id'] ?? null, 8) : null;
            $target = is_array($pair) ? self::id($pair['catalog_hotel_id'] ?? null, 10) : null;
            if ($id === null || $target === null || (float)$target > 2147483647) {
                throw new UnexpectedValueException('anex_search_mapping_registry_invalid');
            }
            if (($index[$id] ?? null) === (int)$target) unset($index[$id]);
        }
        return new self($index);
    }

    public function resolve(string $provider, $externalId, string $scope = 'production'): ?int
    {
        $id = self::id($externalId, 8);
        if ($scope !== 'preview' || !isset(self::PROVIDERS[$provider]) || $id === null) {
            return null;
        }
        return $this->index[$id] ?? null;
    }

    public function previewResolver(): callable
    {
        return function (string $provider, $externalId): ?int {
            return $this->resolve($provider, $externalId, 'preview');
        };
    }

    /**
     * Search3 HOTELS filter, using only the final preview identities above.
     * An empty result keeps the broad request: never drop a selected local hotel
     * or truncate its accepted supplier identities to fit the 30-ID contract.
     */
    public function previewHotelIds(array $localHotelIds): array
    {
        if ($localHotelIds === [] || count($localHotelIds) > 30) return [];
        $wanted = [];
        foreach ($localHotelIds as $localId) {
            $id = self::id($localId, 10);
            if ($id === null) return [];
            $wanted[$id] = true;
        }
        $covered = [];
        $externalIds = [];
        foreach ($this->index as $externalId => $localId) {
            if (!isset($wanted[$localId])) continue;
            $covered[$localId] = true;
            $externalIds[] = (int) $externalId;
            if (count($externalIds) > 30) return [];
        }
        if (count($covered) !== count($wanted)) return [];
        sort($externalIds, SORT_NUMERIC);
        return $externalIds;
    }

    public function count(): int
    {
        return count($this->index);
    }

    private static function approved(array $row): bool
    {
        $policy = $row['approval_policy'] ?? null;
        return is_string($policy)
            && in_array($row['match_class'] ?? null, self::APPROVED_CLASSES[$policy] ?? [], true);
    }

    private static function id($value, int $digits): ?string
    {
        if (is_int($value)) $value = (string) $value;
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,' . ($digits - 1) . '}\z/D', $value)
            ? $value : null;
    }

    private static function rowId($row, array &$seen): string
    {
        $id = is_array($row) ? self::id($row['anex_hotel_id'] ?? null, 8) : null;
        if ($id === null || isset($seen[$id])) {
            throw new UnexpectedValueException('anex_search_mapping_registry_invalid');
        }
        $seen[$id] = true;
        return $id;
    }

    private static function target(array $row): ?int
    {
        $id = self::id($row['catalog_hotel_id'] ?? null, 10);
        return $id !== null && $id === self::id($row['existing_catalog_hotel_id'] ?? null, 10)
            ? (int) $id : null;
    }
}
