<?php

declare(strict_types=1);

class TravelDirectoryRepository
{
    private static function dataClass($hlId)
    {
        \Bitrix\Main\Loader::includeModule('highloadblock');
        $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlId)->fetch();
        if (!$hlblock) return null;
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
        return $entity->getDataClass();
    }

    public static function cityById($hlId, $cityId)
    {
        $class = self::dataClass($hlId);
        if (!$class) return false;
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_NAME'],
            'filter'=>['UF_DEPID'=>$cityId],
        ])->fetch();
        return self::cityNameFromRow($row);
    }

    public static function cityFromById($hlId, $cityId)
    {
        $class = self::dataClass($hlId);
        if (!$class) return false;
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_NAME2'],
            'filter'=>['UF_DEPID'=>$cityId],
        ])->fetch();
        return self::cityFromNameFromRow($row);
    }

    public static function cityByName($hlId, $name)
    {
        $class = self::dataClass($hlId);
        if (!$class) return false;
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_NAME','UF_DEPID'],
            'filter'=>['UF_NAME'=>$name,'UF_ACTIVE'=>true],
        ])->fetch();
        return self::cityRecordFromRow($row);
    }

    public static function countryById($hlId, $countryId)
    {
        $class = self::dataClass($hlId);
        if (!$class) return false;
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_NAME'],
            'filter'=>['UF_CID'=>$countryId],
        ])->fetch();
        return self::countryNameFromRow($row);
    }

    public static function countryByName($hlId, $name)
    {
        $class = self::dataClass($hlId);
        if (!$class) return false;
        $row = $class::getList([
            'order'=>['ID'=>'desc'],
            'limit'=>1,
            'select'=>['UF_NAME','UF_CID'],
            'filter'=>['UF_NAME'=>$name,'UF_ACTIVE'=>true],
        ])->fetch();
        return self::countryRecordFromRow($row);
    }

    public static function cityNameFromRow($row)
    {
        return is_array($row) && array_key_exists('UF_NAME', $row)
            ? $row['UF_NAME']
            : false;
    }

    public static function cityFromNameFromRow($row)
    {
        return is_array($row) && array_key_exists('UF_NAME2', $row)
            ? $row['UF_NAME2']
            : false;
    }

    public static function cityRecordFromRow($row)
    {
        if (!is_array($row) || !array_key_exists('UF_NAME', $row) || !array_key_exists('UF_DEPID', $row)) {
            return false;
        }
        return ['NAME'=>$row['UF_NAME'], 'ID'=>$row['UF_DEPID']];
    }

    public static function countryNameFromRow($row)
    {
        return is_array($row) && array_key_exists('UF_NAME', $row)
            ? $row['UF_NAME']
            : false;
    }

    public static function countryRecordFromRow($row)
    {
        if (!is_array($row) || !array_key_exists('UF_NAME', $row) || !array_key_exists('UF_CID', $row)) {
            return false;
        }
        return ['NAME'=>$row['UF_NAME'], 'ID'=>$row['UF_CID']];
    }

    public static function mealMap(): array
    {
        return [
            'all'=>'ЛЮБОЕ',
            '999'=>'ЛЮБОЕ',
            '7'=>'ВСЕ ВКЛЮЧЕНО',
            '3'=>'ЗАВТРАК',
            '4'=>'ПОЛУПАНСИОН',
            '5'=>'ПОЛНЫЙ ПАНСИОН',
        ];
    }
}
