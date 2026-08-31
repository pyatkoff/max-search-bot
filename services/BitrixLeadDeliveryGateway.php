<?php

declare(strict_types=1);

/**
 * Compatibility adapter for the existing Bitrix lead delivery mechanism.
 *
 * Keep this class behaviorally identical to the legacy inline implementation:
 * include the iblock module and add the prepared element through CIBlockElement.
 */
final class BitrixLeadDeliveryGateway
{
    public static function create(array $element)
    {
        \Bitrix\Main\Loader::includeModule('iblock');
        $iblockElement = new \CIBlockElement();
        return $iblockElement->Add($element);
    }
}
