<?php
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/ButtonFactory.php';
require_once __DIR__ . '/IntegrationRegistry.php';

/**
 * Owns the pre-results channel offer policy.
 * The offer is advisory only: it never blocks or changes tour-search/manager routing.
 */
class ChannelOfferService
{
    public static function entryFamily(string $entryChannel): string
    {
        $entry = strtolower(trim($entryChannel));
        if ($entry === '') return '';
        if (strpos($entry, 'telegram') !== false || preg_match('/(^|[_:-])tg([_:-]|$)/', $entry)) return 'telegram';
        if (strpos($entry, 'max') !== false) return 'max';
        return '';
    }

    public static function channelUrl(string $provider, array $meta, string $latestYclid = ''): string
    {
        $provider = strtolower(trim($provider));
        $yclid = trim($latestYclid);
        if ($yclid === '') $yclid = trim((string)($meta['yclid'] ?? ''));
        if ($yclid === '') $yclid = '0';
        $region = trim((string)($meta['region_id'] ?? ''));
        if ($region === '') $region = '0';

        $template = (string)ProjectConfig::get('messenger.channel_offer.' . $provider . '_url', '');
        if ($template === '') return '';
        return strtr($template, [
            '{yclid}' => rawurlencode($yclid),
            '{region_id}' => rawurlencode($region),
        ]);
    }

    public static function model(array $meta, string $latestYclid = ''): array
    {
        $entryFamily = self::entryFamily((string)($meta['entry_channel'] ?? ''));
        $buttons = [];

        if ($entryFamily !== 'max') {
            $maxUrl = self::channelUrl('max', $meta, $latestYclid);
            if ($maxUrl !== '') $buttons[] = ButtonFactory::row(ButtonFactory::url('Подписаться в MAX', $maxUrl));
        }
        if ($entryFamily !== 'telegram') {
            $tgUrl = self::channelUrl('telegram', $meta, $latestYclid);
            if ($tgUrl !== '') $buttons[] = ButtonFactory::row(ButtonFactory::url('Подписаться в Telegram', $tgUrl));
        }

        return [
            'text' => "А пока можете подписаться на наш канал — там публикуем горящие туры и интересные снижения цен 🔥",
            'buttons' => $buttons,
            'entry_family' => $entryFamily,
        ];
    }

    public static function sendPreparing($chatId): bool
    {
        return (bool)IntegrationRegistry::messenger()->sendWithButtons(
            $chatId,
            "🔎 Отлично, всё записал. Подбираю подходящие варианты…",
            []
        );
    }

    public static function sendOffer($chatId): bool
    {
        $meta = [];
        $yclid = '';
        try { $meta = (array)MaxSearchApi::getTrafficMeta($chatId); } catch (Throwable $e) {}
        try { $yclid = (string)MaxSearchApi::getLatestYclid($chatId); } catch (Throwable $e) {}
        $model = self::model($meta, $yclid);
        if (empty($model['buttons'])) return true;
        $ok = (bool)IntegrationRegistry::messenger()->sendWithButtons($chatId, $model['text'], $model['buttons']);
        if ($ok) {
            try { MaxSearchApi::funnelLog($chatId, 'channel_offer_pre_results', ['entry_family'=>$model['entry_family']]); } catch (Throwable $e) {}
        }
        return $ok;
    }

    public static function runBeforeResults($chatId, ?int $resultDelaySeconds = null): void
    {
        self::sendPreparing($chatId);
        sleep(3);
        self::sendOffer($chatId);
        $total = $resultDelaySeconds ?? random_int(5, 8);
        $total = max(5, min(8, $total));
        $remaining = max(0, $total - 3);
        if ($remaining > 0) sleep($remaining);
    }
}
