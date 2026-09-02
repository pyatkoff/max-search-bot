<?php

declare(strict_types=1);

require_once __DIR__ . '/MaxWebhookHealth.php';

/**
 * Narrow production cutover repair for MAX webhook ownership.
 * It may remove only explicitly allowed legacy subscriptions and only when
 * the canonical subscription is already present. Unknown extra owners are never mutated.
 */
final class MaxWebhookReconciler
{
    public const LEGACY_ANYTOUR_WEBHOOK = 'https://anytour.online/max-search/webhook.php';

    public static function plan(array $health, array $allowedLegacyUrls = [self::LEGACY_ANYTOUR_WEBHOOK]): array
    {
        if (($health['ok'] ?? false) === true) {
            return ['ok'=>true,'action'=>'none','reason'=>'already_healthy','delete_urls'=>[]];
        }
        if (($health['reason'] ?? '') !== 'extra_subscriptions' || ($health['expected_present'] ?? false) !== true) {
            return ['ok'=>false,'action'=>'blocked','reason'=>'canonical_not_safely_reconcilable','delete_urls'=>[]];
        }

        $extras = array_values(array_unique(array_filter(array_map('strval', (array)($health['extra_subscription_urls'] ?? [])))));
        $allowed = array_values(array_unique(array_filter(array_map('strval', $allowedLegacyUrls))));
        $unknown = array_values(array_diff($extras, $allowed));
        if ($extras === [] || $unknown !== []) {
            return [
                'ok'=>false,
                'action'=>'blocked',
                'reason'=>$extras === [] ? 'no_extra_urls' : 'unknown_extra_subscription',
                'delete_urls'=>[],
                'unknown_urls'=>$unknown,
            ];
        }

        return ['ok'=>true,'action'=>'delete_legacy','reason'=>'known_legacy_only','delete_urls'=>$extras];
    }
}
