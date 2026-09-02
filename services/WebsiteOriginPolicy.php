<?php

class WebsiteOriginPolicy
{
    private array $allowedOrigins;

    public function __construct(?array $allowedOrigins = null)
    {
        $this->allowedOrigins = $allowedOrigins ?? self::configuredOrigins();
    }

    public static function configuredOrigins(): array
    {
        $origins = [
            'https://anytour.com',
            'https://www.anytour.com',
            'https://app.anytoour.ru',
            'https://www.anytoour.ru',
            'https://anytoour.ru',
            'https://www.anytoour.ru',
        ];

        if (defined('WEBSITE_ALLOWED_ORIGINS')) {
            $extra = preg_split('/[\s,]+/', trim((string) WEBSITE_ALLOWED_ORIGINS)) ?: [];
            foreach ($extra as $origin) {
                $origin = self::normalize((string) $origin);
                if ($origin !== null) $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    public function isAllowed(?string $origin): bool
    {
        if ($origin === null || trim($origin) === '') return true;
        $origin = self::normalize($origin);
        return $origin !== null && in_array($origin, $this->allowedOrigins, true);
    }

    public function apply(?string $origin): bool
    {
        if ($origin === null || trim($origin) === '') return true;
        $origin = self::normalize($origin);
        if ($origin === null || !in_array($origin, $this->allowedOrigins, true)) return false;

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
        header('Vary: Origin', false);
        return true;
    }

    private static function normalize(string $origin): ?string
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin === '') return null;
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) return null;
        if (isset($parts['path']) && $parts['path'] !== '') return null;
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) return null;

        $normalized = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) $normalized .= ':' . (int) $parts['port'];
        return $normalized;
    }
}
