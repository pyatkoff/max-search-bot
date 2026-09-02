<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/integrations/MaxCredentialProvider.php';

/**
 * Shared sender/receiver bridge configuration.
 * A dedicated configured secret wins. During cutover, installations that
 * already share the MAX bot token can derive a domain-separated HMAC key
 * without copying another secret between hosts.
 */
final class LeadBridgeConfig
{
    private const DEFAULT_RECEIVER_URL = 'https://app.anytoour.ru/lead-receiver.php';
    private const KEY_CONTEXT = 'max-search-lead-bridge-v1';

    public static function receiverUrl(): string
    {
        if (defined('MAX_SEARCH_LEAD_RECEIVER_URL')) {
            $url = trim((string) MAX_SEARCH_LEAD_RECEIVER_URL);
            if ($url !== '') return $url;
        }
        return self::DEFAULT_RECEIVER_URL;
    }

    public static function secret(): string
    {
        if (defined('MAX_SEARCH_LEAD_BRIDGE_SECRET')) {
            $secret = trim((string) MAX_SEARCH_LEAD_BRIDGE_SECRET);
            if ($secret !== '') return $secret;
        }
        $token = MaxCredentialProvider::token();
        return $token !== '' ? hash_hmac('sha256', self::KEY_CONTEXT, $token) : '';
    }
}
