<?php

class ToursCallbackAction
{
    public static function handles(string $q): bool
    {
        return in_array($q, ['show_tours','tours_checked','tours_found'], true)
            || strpos($q, 'finish') === 0;
    }

    public static function handle(int $chatId, string $q, array $query): bool
    {
        if ($q === 'show_tours' || strpos($q, 'finish') === 0) {
            MaxSearchApi::showToursChoice($chatId, self::userName($query));
            return true;
        }

        if ($q === 'tours_checked') {
            MaxSearchApi::showAfterToursQuestion($chatId);
            return true;
        }

        if ($q === 'tours_found') {
            MaxSearchApi::funnelLog($chatId, 'tours_found');
            MaxSearchApi::cancelToursFollowup($chatId);
            MaxSearchApi::showChannelOffer($chatId, false);
            return true;
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
