<?php
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/ButtonFactory.php';

/**
 * Builds the post-search result screen independently from a concrete messenger.
 * Business work (claim creation and tracked links) is kept separate from rendering.
 */
class TourResultsService
{
    public static function build($chatId, string $name = ''): array
    {
        $savedData = (array)MaxSearchApi::getSavedData($chatId);
        $savedData['NAME'] = $name;
        $claimUrl = (string)MaxSearchApi::saveClaim($chatId, $savedData);

        // The claim remains persisted for lead/manager continuity, but the customer-facing
        // destination is always the canonical search page. Preserve the route when available.
        $claim = MaxSearchApi::getLastClaimForChat($chatId);
        if (is_array($claim) && $claim) {
            $claimUrl = ProjectConfig::searchUrlFromClaim(
                $claim,
                (string)MaxSearchApi::getLatestYclid($chatId)
            );
        }

        $openToursUrl = self::trackedUrl(
            (string)ProjectConfig::get('search.open_tours_path', '/max-search/open_tours.php'),
            $chatId,
            $claimUrl
        );

        $buttons = [
            ButtonFactory::row(ButtonFactory::url('🔥 Открыть туры на сайте', $openToursUrl)),
        ];

        $channelUrl = (string)MaxSearchApi::buildChannelMiniappUrl($chatId);
        if ($channelUrl !== '') {
            $trackedChannelUrl = self::trackedUrl(
                (string)ProjectConfig::get('messenger.open_channel_path', '/max-search/open_channel.php'),
                $chatId,
                $channelUrl
            );
            $buttons[] = ButtonFactory::row(ButtonFactory::url(self::channelButtonText(), $trackedChannelUrl));
        }

        $buttons[] = ButtonFactory::row(ButtonFactory::callback('👩‍💼 Нужна помощь менеджера', 'manager_after_tours'));
        $buttons[] = ButtonFactory::row(ButtonFactory::callback('✏️ Изменить параметры', 'edit_params'));

        return [
            'claim_url' => $claimUrl,
            'channel_url' => $channelUrl,
            'text' => self::messageText(),
            'buttons' => $buttons,
        ];
    }

    public static function trackedUrl(string $path, $chatId, string $targetUrl): string
    {
        $base = ProjectConfig::trackingBaseDomain();
        if (preg_match('~^https?://~i', $path)) $url = $path;
        else $url = $base . '/' . ltrim($path, '/');

        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . 'chat=' . rawurlencode((string)$chatId) . '&url=' . rawurlencode($targetUrl);
    }

    public static function channelButtonText(): string
    {
        $provider = strtolower((string)ProjectConfig::get('messenger.provider', 'max'));
        if ($provider === 'telegram') return '🔥 Горящие туры в Telegram';
        if ($provider === 'max') return '🔥 Горящие туры в MAX';
        return '🔥 Горящие туры в канале';
    }

    public static function messageText(): string
    {
        $provider = strtolower((string)ProjectConfig::get('messenger.provider', 'max'));
        $channelName = $provider === 'telegram' ? 'Telegram-канал' : ($provider === 'max' ? 'MAX-канал' : 'канал');

        return "🔥 <b>Подходящие туры готовы</b>\n\n"
            . "Откройте результаты на сайте — там будут актуальные варианты по выбранным параметрам.\n\n"
            . "Хотите следить за снижением цен и горящими предложениями — загляните в наш {$channelName}.";
    }
}
