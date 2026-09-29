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
            'online_text'=>self::onlineMessageText(),
            'working_wait_text'=>self::workingWaitMessageText(),
            'fallback_text'=>self::fallbackMessageText(),
            'outside_hours_text'=>self::outsideHoursMessageText(),
        ];
    }

    public static function messageText(): string
    {
        return "👩‍💼 <b>Передам запрос менеджеру</b>\n\n"
            . "Параметры поездки уже сохранены — повторно заполнять ничего не нужно.\n"
            . "Осталось поделиться номером телефона, чтобы менеджер мог связаться с вами.";
    }

    public static function sourceEntryMessageText(bool $withinWorkingHours): string
    {
        return "Здравствуйте! 👋\n\n"
            . ($withinWorkingHours ? "Менеджер скоро присоединится к диалогу." : "Менеджер ответит в рабочее время.")
            . "\nА пока напишите, какой тур вас интересует или какой вопрос вы хотите задать.";
    }

    public static function onlineMessageText(): string
    {
        return "👩‍💼 <b>Запрос передан менеджеру</b>\n\nОн ответит вам в этом чате.";
    }

    public static function workingWaitMessageText(): string
    {
        return self::onlineMessageText();
    }

    public static function fallbackMessageText(): string
    {
        return "📱 <b>Менеджер пока не успел ответить</b>\n\n"
            . "Чтобы не потерять ваш запрос, можете оставить номер телефона — менеджер свяжется с вами.\n"
            . "Если удобнее, можно продолжить ждать ответ прямо в этом чате.";
    }

    public static function outsideHoursMessageText(): string
    {
        return "👩‍💼 <b>Запрос передан менеджеру</b>\n\n"
            . "Ваш запрос сохранён в очереди. Ответ придёт прямо в этот чат в следующий рабочий период.";
    }
}
