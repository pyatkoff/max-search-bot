<?php
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/ButtonFactory.php';
require_once __DIR__ . '/TourResultsService.php';

class PostTourService
{
    public static function followupModel(): array
    {
        return [
            'text' => "🙂 <b>Могу помочь с выбором</b>\n\n"
                . "Могу передать ваш подбор менеджеру — он проверит актуальные цены и пришлёт <b>3–5 лучших вариантов</b>.\n\n"
                . "Параметры поездки уже сохранены, повторно ничего заполнять не придётся.",
            'buttons' => ButtonFactory::rows(
                ButtonFactory::row(ButtonFactory::callback('💬 Получить 3–5 лучших вариантов','manager_after_tours')),
                ButtonFactory::row(ButtonFactory::callback('👍 Я уже нашёл подходящий тур','tours_found')),
                ButtonFactory::row(ButtonFactory::callback('✏️ Изменить параметры','edit_params'))
            ),
        ];
    }

    public static function afterToursModel(): array
    {
        return [
            'text' => "🙂 <b>Удалось найти подходящий тур?</b>\n\n"
                . "Если вариантов слишком много или сложно определиться — менеджер может посмотреть ваш запрос и помочь с выбором.",
            'buttons' => ButtonFactory::rows(
                ButtonFactory::row(ButtonFactory::callback('👍 Да, нашёл варианты','tours_found')),
                ButtonFactory::row(ButtonFactory::callback('👩‍💼 Нужна помощь с подбором','manager_after_tours')),
                ButtonFactory::row(ButtonFactory::callback('✏️ Изменить параметры','edit_params'))
            ),
        ];
    }

    public static function channelOfferModel($chatId, bool $afterLead = false): array
    {
        $buttons = [];
        $channelUrl = (string)MaxSearchApi::buildChannelMiniappUrl($chatId);
        if ($channelUrl !== '') {
            $tracked = TourResultsService::trackedUrl(
                (string)ProjectConfig::get('messenger.open_channel_path', '/max-search/open_channel.php'),
                $chatId,
                $channelUrl
            );
            $buttons[] = ButtonFactory::row(ButtonFactory::url('📢 Подписаться на канал', $tracked));
        }

        $claimUrl = '';
        $claim = MaxSearchApi::getLastClaimForChat($chatId);
        if (is_array($claim) && $claim) {
            $claimUrl = ProjectConfig::searchUrlFromClaim(
                $claim,
                (string)MaxSearchApi::getLatestYclid($chatId)
            );
            $buttons[] = ButtonFactory::row(ButtonFactory::url('🔥 Вернуться к турам', $claimUrl));
        }

        $channelName = self::channelName();
        $text = $afterLead
            ? "✅ <b>Заявка отправлена</b>\n\nМенеджер получил параметры вашего отдыха и свяжется с вами.\n\nА пока можно заглянуть в наш {$channelName} — там публикуем хорошие цены и горящие предложения."
            : "🌴 <b>Отлично!</b>\n\nЕсли хотите следить за хорошими ценами и горящими предложениями, подписывайтесь на наш {$channelName}.";

        return [
            'text' => $text,
            'buttons' => $buttons,
            'channel_url' => $channelUrl,
            'claim_url' => $claimUrl,
        ];
    }

    public static function channelName(): string
    {
        $provider = strtolower((string)ProjectConfig::get('messenger.provider', 'max'));
        if ($provider === 'telegram') return 'Telegram-канал';
        if ($provider === 'max') return 'MAX-канал';
        return 'канал';
    }
}
