<?php
require_once dirname(__DIR__, 2) . '/services/DialogueView.php';
require_once dirname(__DIR__, 2) . '/services/InteractionGuard.php';
require_once dirname(__DIR__, 2) . '/services/ChannelOfferService.php';

class ToursCallbackAction
{
    public static function handles(string $q): bool
    {
        return in_array($q, ['show_tours','tours_checked','tours_found'], true)
            || strpos($q, 'finish') === 0;
    }

    private static function handleShowTours(int $chatId, string $q, array $query): bool
    {
        return InteractionGuard::runDuplicateCallback(
            $chatId,
            $q,
            'tours_show',
            10.0,
            static function () use ($chatId, $query): bool {
                ChannelOfferService::runBeforeResults($chatId);
                MaxSearchApi::showToursChoice($chatId, self::userName($query));
                return true;
            },
            static function (string $previousPayload, float $previousAt, float $now) use ($chatId, $q): void {
                if (function_exists('put_log_in')) put_log_in('DUPLICATE_SHOW_TOURS_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $q);
            }
        );
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
