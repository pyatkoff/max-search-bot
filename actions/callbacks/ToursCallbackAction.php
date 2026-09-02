<?php
require_once dirname(__DIR__, 2) . '/services/DialogueView.php';
require_once dirname(__DIR__, 2) . '/services/InteractionGuard.php';
require_once dirname(__DIR__, 2) . '/services/ChannelOfferService.php';

class ToursCallbackAction
{
    private const SHOW_TOURS_DUPLICATE_WINDOW_SECONDS = 10.0;

    public static function handles(string $q): bool
    {
        return in_array($q, ['show_tours','tours_checked','tours_found'], true)
            || strpos($q, 'finish') === 0;
    }

    public static function isDuplicateShowTours(string $previousPayload, float $previousAt, string $payload, float $now, float $windowSeconds = self::SHOW_TOURS_DUPLICATE_WINDOW_SECONDS): bool
    {
        return InteractionGuard::isDuplicate($previousPayload, $previousAt, $payload, $now, $windowSeconds);
    }

    private static function handleShowTours(int $chatId, string $q, array $query): bool
    {
        return InteractionGuard::synchronized($chatId, 'tours_show', function ($fp) use ($chatId, $q, $query): bool {
            rewind($fp);
            $state = json_decode((string)stream_get_contents($fp), true);
            $previousPayload = is_array($state) ? (string)($state['payload'] ?? '') : '';
            $previousAt = is_array($state) ? (float)($state['at'] ?? 0) : 0.0;
            $now = microtime(true);

            if (self::isDuplicateShowTours($previousPayload, $previousAt, $q, $now)) {
                InteractionGuard::reportSuppressed($chatId, $q, 'duplicate', null, null, 'tours_show');
                if (function_exists('put_log_in')) put_log_in('DUPLICATE_SHOW_TOURS_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $q);
                return true;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['payload'=>$q, 'at'=>$now], JSON_UNESCAPED_SLASHES));
            fflush($fp);

            ChannelOfferService::runBeforeResults($chatId);
            MaxSearchApi::showToursChoice($chatId, self::userName($query));
            return true;
        });
    }

    public static function handle(int $chatId, string $q, array $query): bool
    {
        if ($q === 'show_tours') {
            return self::handleShowTours($chatId, $q, $query);
        }

        if (strpos($q, 'finish') === 0) {
            ChannelOfferService::runBeforeResults($chatId);
            MaxSearchApi::showToursChoice($chatId, self::userName($query));
            return true;
        }

        if ($q === 'tours_checked') {
            return DialogueView::afterToursQuestion($chatId);
        }

        if ($q === 'tours_found') {
            MaxSearchApi::funnelLog($chatId, 'tours_found');
            MaxSearchApi::cancelToursFollowup($chatId);
            return DialogueView::channelOffer($chatId, false);
        }

        return false;
    }

    private static function userName(array $query): string
    {
        $from = (array)($query['from'] ?? []);
        $name = trim((string)($from['first_name'] ?? ''));
        $last = trim((string)($from['last_name'] ?? ''));
        if ($last !== '') $name = trim($name . ' ' . $last);
        if ($name === '') $name = trim((string)($from['username'] ?? ''));
        return $name;
    }
}
