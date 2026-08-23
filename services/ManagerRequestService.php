<?php

/**
 * Prepares the manager handoff without knowing which messenger renders it.
 */
class ManagerRequestService
{
    public static function prepare($chatId, string $name = '', bool $fromTours = false): array
    {
        $claim = MaxSearchApi::getLastClaimForChat($chatId);
        $created = false;

        if (!$claim) {
            $savedData = (array)MaxSearchApi::getSavedData($chatId);
            $savedData['NAME'] = $name;
            MaxSearchApi::saveClaim($chatId, $savedData);
            $claim = MaxSearchApi::getLastClaimForChat($chatId);
            $created = true;
        }

        return [
            'claim_created'=>$created,
            'claim'=>$claim ?: null,
            'manual_callback'=>'phone_manual',
            'back_callback'=>$fromTours ? 'tours_checked' : 'back_check',
            'text'=>self::messageText(),
        ];
    }

    public static function messageText(): string
    {
        return "👩‍💼 <b>Передам запрос менеджеру</b>\n\n"
            . "Параметры поездки уже сохранены — повторно заполнять ничего не нужно.\n"
            . "Осталось поделиться номером телефона, чтобы менеджер мог связаться с вами.";
    }
}
