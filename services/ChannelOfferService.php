<?php
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/ButtonFactory.php';
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/TrafficAttributionService.php';

/**
 * Owns paid MAX entry and the website-only channel offer before results.
 * The offer is advisory only: it never blocks or changes tour-search/manager routing.
 */
class ChannelOfferService
{
    /** Current MAX bot_started metadata only; ordinary restarts must not reuse old traffic. */
    public static function startUrl(array $entryMeta): string
    {
        if (strtolower((string)ProjectConfig::get('messenger.provider', 'max')) !== 'max') return '';
        $yclid = (string)($entryMeta['yclid'] ?? '');
        $region = (string)($entryMeta['region_id'] ?? '');
        $campaign = (string)($entryMeta['campaign_id'] ?? '');
        // /new/max2/ accepts three numeric fields; its documented defaults are region 1 / campaign 0.
        if ($region === '') $region = '1';
        if ($campaign === '') $campaign = '0';
        if (!preg_match('/\A[0-9]{6,64}\z/', $yclid) || !preg_match('/[1-9]/', $yclid)) return '';
        if (!preg_match('/\A[0-9]{1,20}\z/', $region) || !preg_match('/[1-9]/', $region)) return '';
        if (!preg_match('/\A[0-9]{1,20}\z/', $campaign)) return '';
        if (self::sourceSuppressesOffer($entryMeta)) return '';
        $botUrl = (string)ProjectConfig::get('messenger.miniapp_bot_url', '');
        if ($botUrl === '') return '';
        // entry_channel remains in Search attribution/suppression; MAX2 does not accept that suffix.
        return TrafficAttributionService::buildMiniappUrl($botUrl, [
            'yclid' => $yclid, 'region_id' => $region, 'campaign_id' => $campaign,
        ]);
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

    /**
     * Additional source-level suppression for eligible website offers and paid MAX entry.
     * Messenger repeats are suppressed separately by the actual request transport.
     * Missing/unknown source or unavailable DB does not invent a source restriction.
     */
    public static function sourceSuppressesOffer(array $meta): bool
    {
        $sourceKey = trim((string)($meta['entry_channel'] ?? ''));
        if ($sourceKey === '' || !ConversationDb::isConfigured()) return false;

        try {
            $pdo = ConversationDb::connection();
            $q = $pdo->prepare('SELECT s.suppress_channel_offer FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.source_key=? AND s.is_active=1 LIMIT 1');
            $q->execute([ProjectConfig::projectId(), $sourceKey]);
            $value = $q->fetchColumn();
            return $value !== false && (int)$value === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function model(array $meta, string $latestYclid = '', bool $suppressed = false): array
    {
        $buttons = [];
        if (!$suppressed) {
            $maxUrl = self::channelUrl('max', $meta, $latestYclid);
            if ($maxUrl !== '') $buttons[] = ButtonFactory::row(ButtonFactory::url('Подписаться в MAX', $maxUrl));

            $tgUrl = self::channelUrl('telegram', $meta, $latestYclid);
            if ($tgUrl !== '') $buttons[] = ButtonFactory::row(ButtonFactory::url('Подписаться в Telegram', $tgUrl));
        }

        return [
            'text' => "А пока можете подписаться на наш канал — там публикуем горящие туры и интересные снижения цен 🔥",
            'buttons' => $buttons,
            'source_key' => trim((string)($meta['entry_channel'] ?? '')),
            'suppressed' => $suppressed,
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

    /** Alternate webhooks override IntegrationRegistry without changing ProjectConfig. */
    public static function allowsRepeatOffer(): bool
    {
        return IntegrationRegistry::messenger() instanceof WebsiteMessengerAdapter;
    }

    public static function sendOffer($chatId): bool
    {
        if (!self::allowsRepeatOffer()) return true;
        $meta = [];
        $yclid = '';
        try { $meta = (array)MaxSearchApi::getTrafficMeta($chatId); } catch (Throwable $e) {}
        try { $yclid = (string)MaxSearchApi::getLatestYclid($chatId); } catch (Throwable $e) {}
        $suppressed = self::sourceSuppressesOffer($meta);
        $model = self::model($meta, $yclid, $suppressed);
        if ($suppressed) {
            try { MaxSearchApi::funnelLog($chatId, 'channel_offer_suppressed', ['source_key'=>$model['source_key']]); } catch (Throwable $e) {}
            return true;
        }
        if (empty($model['buttons'])) return true;
        $ok = (bool)IntegrationRegistry::messenger()->sendWithButtons($chatId, $model['text'], $model['buttons']);
        if ($ok) {
            try { MaxSearchApi::funnelLog($chatId, 'channel_offer_pre_results', ['source_key'=>$model['source_key']]); } catch (Throwable $e) {}
        }
        return $ok;
    }

    public static function runBeforeResults($chatId, ?int $resultDelaySeconds = null): void
    {
        self::sendPreparing($chatId);
        // MAX/TG already have their entry flow; do not repeat promotion or its delay.
        if (!self::allowsRepeatOffer()) return;
        sleep(3);
        self::sendOffer($chatId);
        $total = $resultDelaySeconds ?? random_int(5, 8);
        $total = max(5, min(8, $total));
        $remaining = max(0, $total - 3);
        if ($remaining > 0) sleep($remaining);
    }
}
