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

        return [
            'claim_url' => $claimUrl,
            'text' => self::messageText(),
            'buttons' => [
                ButtonFactory::row(ButtonFactory::url('🔥 Посмотреть на сайте', $openToursUrl)),
                ButtonFactory::row(ButtonFactory::callback('👩‍💼 Подобрать тур с менеджером', 'manager_after_tours')),
                ButtonFactory::row(ButtonFactory::callback('✏️ Изменить параметры', 'edit_params')),
            ],
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

    public static function messageText(): string
    {
        return "🔥 <b>Подходящие туры готовы</b>\n\n"
            . "Можно посмотреть варианты самостоятельно или продолжить подбор с менеджером.";
    }
}
