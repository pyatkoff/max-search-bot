<?php

/**
 * Bitrix HL repository for conversation state.
 *
 * This class intentionally preserves the existing HL schema and read/write
 * semantics from MaxSearchBase. It contains no MAX transport or UI logic.
 */
class ConversationStateRepository
{
    private static function dataClass($hlId)
    {
        \Bitrix\Main\Loader::includeModule('highloadblock');
        $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlId)->fetch();
        if (!$hlblock) {
            throw new \RuntimeException('Conversation HL block not found: ' . $hlId);
        }
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
        return $entity->getDataClass();
    }

    public static function currentStatus($hlId, $chatId)
    {
        $class = self::dataClass($hlId);
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_STATUS'],
            'filter'=>['UF_CHAT_ID'=>$chatId],
        ])->fetch();
        return $row ? $row['UF_STATUS'] : false;
    }

    public static function addStatus($hlId, $chatId, $statusId, $messageId = false)
    {
        $class = self::dataClass($hlId);
        return $class::add([
            'UF_DATE'=>new \Bitrix\Main\Type\DateTime(),
            'UF_CHAT_ID'=>$chatId,
            'UF_STATUS'=>$statusId,
            'UF_MESSID'=>$messageId ? $messageId : '',
        ]);
    }

    public static function latestMessageRow($hlId, $chatId)
    {
        $class = self::dataClass($hlId);
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_MESSID','ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId],
        ])->fetch();
        return $row ?: false;
    }

    public static function deleteRow($hlId, $rowId)
    {
        $class = self::dataClass($hlId);
        return $class::delete($rowId);
    }

    public static function deleteAll($hlId, $chatId)
    {
        $class = self::dataClass($hlId);
        $rows = $class::getList([
            'order'=>['ID'=>'desc'],
            'select'=>['ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId],
        ]);
        while ($row = $rows->fetch()) {
            $class::delete($row['ID']);
        }
        return true;
    }

    public static function saveLastValue($hlId, $chatId, $statusId, $value)
    {
        $class = self::dataClass($hlId);
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$statusId],
        ])->fetch();
        if (!$row) return false;
        $class::update($row['ID'], ['UF_VALUE'=>$value]);
        return true;
    }

    public static function lastValue($hlId, $chatId, $statusId)
    {
        $class = self::dataClass($hlId);
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_VALUE'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$statusId],
        ])->fetch();
        return $row ? $row['UF_VALUE'] : false;
    }

    public static function upsertValue($hlId, $chatId, $statusId, $value, $startStatusId = 64)
    {
        $class = self::dataClass($hlId);
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$statusId],
        ])->fetch();
        $startRow = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$startStatusId],
        ])->fetch();

        // savedData() intentionally ignores rows before the latest start marker.
        // Reusing a pre-start row here would make a successful write immediately
        // disappear from the current dialogue state and can loop the same question.
        if ($row && self::shouldReuseValueRow($row['ID'] ?? 0, $startRow['ID'] ?? 0)) {
            $class::update($row['ID'], ['UF_VALUE'=>$value]);
            return 'updated';
        }
        $class::add([
            'UF_DATE'=>new \Bitrix\Main\Type\DateTime(),
            'UF_CHAT_ID'=>$chatId,
            'UF_STATUS'=>$statusId,
            'UF_VALUE'=>$value,
            'UF_MESSID'=>'',
        ]);
        return 'inserted';
    }

    public static function shouldReuseValueRow($rowId, $startRowId): bool
    {
        $rowId = (int)$rowId;
        $startRowId = (int)$startRowId;
        return $rowId > 0 && ($startRowId <= 0 || $rowId > $startRowId);
    }

    public static function savedData($hlId, $chatId, $statusStart, $statusCheck)
    {
        $class = self::dataClass($hlId);
        $rows = $class::getList([
            'order'=>['ID'=>'desc'],
            'filter'=>['UF_CHAT_ID'=>$chatId],
        ]);

        $list = [];
        while ($row = $rows->fetch()) $list[] = $row;
        return self::savedDataFromRows($list, $statusStart, $statusCheck);
    }

    /** Pure helper used by regression tests; rows must be newest first. */
    public static function savedDataFromRows(array $rows, $statusStart, $statusCheck)
    {
        $result = [];
        foreach ($rows as $row) {
            $status = $row['UF_STATUS'] ?? null;
            if ($status == $statusStart) break;

            // Preserve legacy MaxSearchBase semantics exactly. Do not replace
            // empty() with array_key_exists() here as part of infrastructure refactor.
            if ($status != $statusCheck && empty($result[$status])) {
                $result[$status] = $row['UF_VALUE'] ?? null;
            }
        }
        return $result;
    }
}
