<?php
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/ButtonFactory.php';
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/ConversationDb.php';

/**
 * Owns the pre-results channel offer policy.
 * The offer is advisory only: it never blocks or changes tour-search/manager routing.
 */
class ChannelOfferService
{
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
     * Promotion is suppressed only by an explicit source policy.
     * Transport alone is never enough: paid Yandex traffic may legitimately land in MAX/TG.
     * Missing source, unknown source or unavailable DB fail open and keep both offers visible.
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

    public static function sendOffer($chatId): bool
    {
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
        sleep(3);
        self::sendOffer($chatId);
        $total = $resultDelaySeconds ?? random_int(5, 8);
        $total = max(5, min(8, $total));
        $remaining = max(0, $total - 3);
        if ($remaining > 0) sleep($remaining);
    }
}
