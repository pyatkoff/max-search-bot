<?php

require_once __DIR__ . '/RuntimeStorage.php';
require_once __DIR__ . '/MysqlDialogueStateRepository.php';
require_once __DIR__ . '/TripBudgetPolicy.php';

/**
 * Dialogue-state repository with a compatibility bridge from legacy Bitrix HL
 * storage to standalone MySQL storage.
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
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::currentStatus($chatId);
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
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::addStatus($chatId, $statusId, $messageId);
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
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::latestMessageRow($chatId);
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
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::deleteRow($rowId);
        $class = self::dataClass($hlId);
        return $class::delete($rowId);
    }

    public static function deleteAll($hlId, $chatId)
    {
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::deleteAll($chatId);
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

    public static function saveLastValue($hlId, $chatId, $statusId, $value, $startStatusId = 64)
    {
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::saveLastValue($chatId, $statusId, $value, $startStatusId);
        $class = self::dataClass($hlId);
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$statusId],
        ])->fetch();
        if (!$row) return false;

        $startRow = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$startStatusId],
        ])->fetch();
        if (!self::shouldReuseValueRow($row['ID'] ?? 0, $startRow['ID'] ?? 0)) return false;

        $class::update($row['ID'], ['UF_VALUE'=>$value]);
        return true;
    }

    public static function lastValue($hlId, $chatId, $statusId, $startStatusId = 64)
    {
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::lastValue($chatId, $statusId, $startStatusId);
        $class = self::dataClass($hlId);
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['ID','UF_VALUE'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$statusId],
        ])->fetch();
        if (!$row) return false;

        $startRow = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['ID'],
            'filter'=>['UF_CHAT_ID'=>$chatId,'UF_STATUS'=>$startStatusId],
        ])->fetch();
        if (!self::shouldReuseValueRow($row['ID'] ?? 0, $startRow['ID'] ?? 0)) return false;

        return $row['UF_VALUE'];
    }

    public static function upsertValue($hlId, $chatId, $statusId, $value, $startStatusId = 64)
    {
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::upsertValue($chatId, $statusId, $value, $startStatusId);
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
        if (RuntimeStorage::usesMysql()) return MysqlDialogueStateRepository::savedData($chatId, $statusStart, $statusCheck);
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
            if ($status == $statusStart) {
                $budget = TripBudgetPolicy::fromStartValue($row['UF_VALUE'] ?? null);
                if ($budget !== null && !empty($budget['max'])) $result['_budget'] = $budget;
                break;
            }
            if ($status != $statusCheck && empty($result[$status])) {
                $result[$status] = $row['UF_VALUE'] ?? null;
            }
        }
        return $result;
    }

    /** Optional budget is attached to the existing start, not a wizard status. */
    public static function budgetSnapshot($chatId, int $startStatus = 64): array
    {
        if (!RuntimeStorage::usesMysql()) return [];
        $row=MysqlDialogueStateRepository::startValue($chatId,$startStatus);
        if (!$row) return [];
        $budget=TripBudgetPolicy::fromStartValue($row['UF_VALUE']??null);
        if ($budget===null) return [];
        return ['start_id'=>(int)$row['ID'],'raw'=>$row['UF_VALUE']??null,'budget'=>$budget];
    }

    public static function applyBudget($chatId, array $update, int $startStatus = 64): bool
    {
        if (!RuntimeStorage::usesMysql() || !is_array($update['snapshot']??null)
            || !is_array($update['changes']??null)) return false;
        $snapshot=$update['snapshot'];$changes=$update['changes'];
        $current=self::budgetSnapshot($chatId,$startStatus);
        if (!$current || $current!==$snapshot || $changes===[]
            || array_diff(array_keys($changes),['budget.max','budget.currency','budget.basis'])!==[]) return false;
        foreach ($changes as $key=>$v) {
            if ($key==='budget.max' && $v!==null && TripBudgetPolicy::amount($v)===null) return false;
            if ($key==='budget.currency' && TripBudgetPolicy::currency($v)===null) return false;
            if ($key==='budget.basis' && !in_array($v,['total','per_person'],true)) return false;
        }
        if (!array_key_exists('budget.max',$changes) && empty($current['budget']['max'])) return false;
        $budget=TripBudgetPolicy::apply($current['budget'],$changes);
        $raw=TripBudgetPolicy::toStartValue($budget);
        if ($raw===$current['raw']) return true;
        return MysqlDialogueStateRepository::compareStartValue($chatId,$startStatus,$current['start_id'],$current['raw'],$raw);
    }

}
