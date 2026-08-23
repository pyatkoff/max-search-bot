<?php

class UserContext
{
    public static function make(string $platform, $externalUserId, $internalChatId, array $user = [], array $attribution = []): array
    {
        return [
            'platform' => strtolower(trim($platform)),
            'external_user_id' => (string)$externalUserId,
            'chat_id' => $internalChatId,
            'first_name' => (string)($user['first_name'] ?? ''),
            'last_name' => (string)($user['last_name'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'attribution' => [
                'yclid' => (string)($attribution['yclid'] ?? ''),
                'client_id' => (string)($attribution['client_id'] ?? ''),
                'region_id' => (string)($attribution['region_id'] ?? ($attribution['region'] ?? '')),
                'campaign_id' => (string)($attribution['campaign_id'] ?? ($attribution['campaign'] ?? '')),
                'source' => (string)($attribution['source'] ?? ''),
            ],
        ];
    }

    public static function displayName(array $context): string
    {
        $name = trim((string)($context['first_name'] ?? '') . ' ' . (string)($context['last_name'] ?? ''));
        if ($name !== '') return $name;
        return trim((string)($context['username'] ?? ''));
    }
}
