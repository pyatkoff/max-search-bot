<?php

require_once __DIR__ . '/RuntimeStorage.php';
require_once __DIR__ . '/MysqlClaimRepository.php';

class ClaimRepository
{
    private static function dataClass($hlId)
    {
        \Bitrix\Main\Loader::includeModule('highloadblock');
        $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlId)->fetch();
        if (!$hlblock) return false;
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
        return $entity->getDataClass();
    }

    public static function buildClaimData($chatID, array $savedData, array $statusMap, $code)
    {
        return [
            'UF_CHAT_ID' => $chatID,
            'UF_NAME' => (string)($savedData['NAME'] ?? ''),
            'UF_CITY' => !empty($savedData[$statusMap['city']]) ? $savedData[$statusMap['city']] : 0,
            'UF_COUNTRY' => !empty($savedData[$statusMap['country']]) ? $savedData[$statusMap['country']] : 0,
            'UF_ADULTS' => !empty($savedData[$statusMap['adults']]) ? $savedData[$statusMap['adults']] : 0,
            'UF_CHILD' => !empty($savedData[$statusMap['children']]) ? $savedData[$statusMap['children']] : 0,
            'UF_AGE' => !empty($savedData[$statusMap['child_ages']]) ? $savedData[$statusMap['child_ages']] : '',
            'UF_STARS' => !empty($savedData[$statusMap['stars']]) ? $savedData[$statusMap['stars']] : 0,
            'UF_MEAL' => !empty($savedData[$statusMap['meal']]) ? $savedData[$statusMap['meal']] : 0,
            'UF_NIGHTS' => !empty($savedData[$statusMap['nights']]) ? $savedData[$statusMap['nights']] : '',
            'UF_DATE_DEPART' => !empty($savedData[$statusMap['date']]) ? $savedData[$statusMap['date']] : '',
            'UF_CODE' => (string)$code,
        ];
    }

    public static function create($hlId, $chatID, array $savedData, array $statusMap, $code)
    {
        $data = self::buildClaimData($chatID, $savedData, $statusMap, $code);
        if (RuntimeStorage::usesMysql()) {
            return MysqlClaimRepository::create($chatID, $data);
        }
        $eclass = self::dataClass($hlId);
        if (!$eclass) return false;
        $data['UF_DATE'] = new \Bitrix\Main\Type\DateTime();
        $result = $eclass::add($data);
        return $result && method_exists($result, 'isSuccess') ? $result->isSuccess() : (bool)$result;
    }

    public static function latestForChat($hlId, $chatID)
    {
        if (RuntimeStorage::usesMysql()) {
            return MysqlClaimRepository::latestForChat($chatID);
        }
        $eclass = self::dataClass($hlId);
        if (!$eclass) return false;
        return $eclass::getList([
            'filter'=>['UF_CHAT_ID'=>$chatID],
            'order'=>['ID'=>'desc'],
            'limit'=>1,
        ])->fetch();
    }

    public static function byCode($hlId, $code)
    {
        if (RuntimeStorage::usesMysql()) {
            return MysqlClaimRepository::byCode($code);
        }
        $eclass = self::dataClass($hlId);
        if (!$eclass) return [];
        $row = $eclass::getList([
            'filter'=>['=UF_CODE'=>$code],
            'order'=>['ID'=>'desc'],
            'limit'=>1,
        ])->fetch();
        return $row ?: [];
    }

    public static function setPhone($hlId, $claimId, $phone)
    {
        if (!$claimId) return false;
        if (RuntimeStorage::usesMysql()) {
            return MysqlClaimRepository::setPhone($claimId, $phone);
        }
        $eclass = self::dataClass($hlId);
        if (!$eclass) return false;
        $result = $eclass::update($claimId, ['UF_PHONE'=>$phone]);
        return $result && method_exists($result, 'isSuccess') ? $result->isSuccess() : (bool)$result;
    }

    public static function markPhoneAsked($hlId, $claimId, $value = true)
    {
        if (!$claimId) return false;
        if (RuntimeStorage::usesMysql()) {
            return MysqlClaimRepository::markPhoneAsked($claimId, $value);
        }
        $eclass = self::dataClass($hlId);
        if (!$eclass) return false;
        $result = $eclass::update($claimId, ['UF_PHONE_ASKED'=>(bool)$value]);
        return $result && method_exists($result, 'isSuccess') ? $result->isSuccess() : (bool)$result;
    }
}
