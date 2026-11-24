<?php
declare(strict_types=1);

namespace AnyTour\Tests\TelegramAcceptedRoute;

use LogicException;
use RuntimeException;

require_once dirname(__DIR__).'/hotel-posts-telegram-main19.php';

function expectRoute($actual, $expected, string $label): void
{
    \channelPostCheck('Accepted Telegram route '.$label, $actual, $expected);
}

function runCases(): void
{
    $pilots = [
        'tg_1972' => ['https://t.me/any_toour/391', 'https://t.me/anytour/316'],
        'tg_2588' => ['https://t.me/any_toour/689', 'https://t.me/anytour/346'],
    ];
    $legacy = [
        'tg_118475' => ['https://t.me/any_toour/194', 'https://t.me/anytour/1780'],
        'tg_3405' => ['https://t.me/any_toour/158', 'https://t.me/anytour/1760'],
    ];
    $unrelated = ['tg_408' => ['https://t.me/any_toour/120', 'https://t.me/anytour/997']];
    $xml = '<yml_catalog><shop><offers>';
    foreach ($pilots + $legacy + $unrelated as $id => [$source]) {
        $xml .= '<offer id="'.$id.'"><url>'.$source.'</url></offer>';
    }
    $xml .= '</offers></shop></yml_catalog>';
    $calls = [];
    $lookup = static function (string $id) use (&$calls, $pilots): array {
        $calls[] = $id;
        return ['offer_id' => $id, 'post_url' => $pilots[$id][1], 'version' => 7];
    };
    foreach ($pilots as $id => [$source, $direct]) {
        $accepted = ['offer_id' => $id, 'post_url' => $direct];
        $tagged = $accepted + ['route_table' => 'max2-v1'];
        foreach ([$xml, str_replace($source, $direct, $xml)] as $input) {
            expectRoute(anytourTelegramFeedResolve($input, $id), $accepted, $id.' accepted baseline');
            expectRoute(anytourTelegramFeedResolve($input, $id, $lookup), $tagged, $id.' confirmed');
        }
        $other = $id === 'tg_1972' ? 'tg_2588' : 'tg_1972';
        $rejected = [
            null, [], 'malformed', $accepted,
            ['offer_id' => $other, 'post_url' => $direct, 'version' => 3],
            ['offer_id' => $id, 'post_url' => $source, 'version' => 3],
            ['offer_id' => $id, 'post_url' => $pilots[$other][1], 'version' => 3],
            ['offer_id' => $id, 'post_url' => $direct.'?x=1', 'version' => 3],
        ];
        foreach ([0, -1, '7', true, 1.5, null] as $invalidVersion) {
            $rejected[] = $accepted + ['version' => $invalidVersion];
        }
        foreach ($rejected as $row) {
            expectRoute(anytourTelegramFeedResolve($xml, $id, static fn () => $row), $accepted, $id.' fallback');
        }
        expectRoute(anytourTelegramFeedResolve($xml, $id, static fn () => throw new RuntimeException('offline')),
            $accepted, $id.' offline');
    }
    $flag = 'ANYTOUR_TELEGRAM_ACCEPTED_TABLE_PILOTS';
    $previous = getenv($flag);
    try {
        putenv($flag.'=0');
        foreach ($pilots as $id => [$source, $direct]) {
            expectRoute(anytourTelegramFeedResolve($xml, $id, 'anytourTelegramReadSharedRoute'),
                ['offer_id' => $id, 'post_url' => $direct], $id.' disabled');
        }
    } finally {
        putenv($previous === false ? $flag : $flag.'='.$previous);
    }
    expectRoute($calls, ['tg_1972', 'tg_1972', 'tg_2588', 'tg_2588'], 'pilot calls');
    foreach ($legacy as $id => [$source, $direct]) {
        foreach ([$source, $direct] as $url) {
            $row = ['offer_id' => $id, 'post_url' => $url];
            expectRoute(anytourTelegramFeedResolve($xml, $id, static fn () => $row), $row + ['route_table' => 'max2-v1'],
                $id.' existing switch and rollback');
        }
        expectRoute(anytourTelegramFeedResolve($xml, $id, static fn () => null),
            ['offer_id' => $id, 'post_url' => $source], $id.' existing fallback');
        try {
            anytourTelegramFeedResolve($xml, $id, static fn () => ['offer_id' => $id, 'post_url' => 'bad']);
            throw new LogicException($id.' accepted an invalid route');
        } catch (RuntimeException $error) {
            expectRoute($error->getMessage(), 'Unexpected Telegram table destination', $id.' strict legacy check');
        }
    }
    $never = static fn () => throw new LogicException('Unrelated offer reached the table');
    expectRoute(anytourTelegramFeedResolve($xml, 'tg_408', $never),
        ['offer_id' => 'tg_408', 'post_url' => $unrelated['tg_408'][1]], 'unrelated route');
    expectRoute(anytourTelegramFeedResolve($xml, 'tg_9999999', $never), null, 'unknown offer');
}
