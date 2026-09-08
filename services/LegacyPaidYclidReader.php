<?php

declare(strict_types=1);

/**
 * Read-only batch projection of the legacy Bitrix YCLID store.
 *
 * Only chat keys are returned. YCLID values never leave this boundary.
 */
final class LegacyPaidYclidReader
{
    public static function paidChatKeys(array $chatKeys, int $highloadBlockId): array
    {
        if ($highloadBlockId <= 0) throw new RuntimeException('paid_report_legacy_store_invalid');

        $keys = [];
        foreach ($chatKeys as $chatKey) {
            $chatKey = trim((string)$chatKey);
            if (!preg_match('/\\A-?[0-9]+\\z/', $chatKey)) {
                throw new RuntimeException('paid_report_invalid_chat_key');
            }
            $keys[$chatKey] = true;
        }
        if (!$keys) return [];

        if (!class_exists('\\Bitrix\\Main\\Loader')
            || !\\Bitrix\\Main\\Loader::includeModule('highloadblock')) {
            throw new RuntimeException('paid_report_legacy_store_unavailable');
        }

        $block = \\Bitrix\\Highloadblock\\HighloadBlockTable::getById($highloadBlockId)->fetch();
        if (!is_array($block) || !$block) {
            throw new RuntimeException('paid_report_legacy_store_unavailable');
        }
        $entity = \\Bitrix\\Highloadblock\\HighloadBlockTable::compileEntity($block);
        $dataClass = $entity->getDataClass();
        $result = $dataClass::getList([
            'order' => ['ID' => 'DESC'],
            'select' => ['ID', 'UF_CHATID', 'UF_YCLID'],
            'filter' => ['@UF_CHATID' => array_keys($keys)],
            'limit' => 10001,
        ]);

        $paid = [];
        $seen = [];
        $count = 0;
        while ($row = $result->fetch()) {
            $count++;
            if ($count > 10000) throw new RuntimeException('paid_report_legacy_result_limit');
            $chatKey = trim((string)($row['UF_CHATID'] ?? ''));
            if (!isset($keys[$chatKey]) || isset($seen[$chatKey])) continue;
            $seen[$chatKey] = true;
            if (trim((string)($row['UF_YCLID'] ?? '')) !== '') $paid[$chatKey] = true;
        }
        return $paid;
    }
}
