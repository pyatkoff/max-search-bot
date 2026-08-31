<?php

declare(strict_types=1);

final class RuntimeBootstrap
{
    public static function isStandalone(): bool
    {
        return defined('MAX_SEARCH_STANDALONE_RUNTIME') && MAX_SEARCH_STANDALONE_RUNTIME === true;
    }

    public static function boot(?string $legacyDocumentRoot = null): void
    {
        if (self::isStandalone()) {
            return;
        }

        $documentRoot = $legacyDocumentRoot;
        if ($documentRoot === null || trim($documentRoot) === '') {
            $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        }

        $prolog = rtrim($documentRoot, '/') . '/bitrix/modules/main/include/prolog_before.php';
        if (!is_file($prolog)) {
            throw new RuntimeException('legacy_bitrix_bootstrap_missing:' . $prolog);
        }

        require_once $prolog;
    }
}
