<?php
require_once __DIR__ . '/NeedValueResolver.php';

/**
 * Canonical boundary between deterministic need-value resolution and trip-state application.
 *
 * Keeps parser ownership in NeedValueResolver while making the state mutation path explicit.
 * Multi-field clarifications may still enter through applyParameters(), but callers no longer
 * need to invoke MaxSearchApi::applyAiParameters directly.
 */
class NeedApplicationService
{
    public static function resolveAndApply($chatId, string $field, string $text, array $context = []): array
    {
        $resolved = NeedValueResolver::resolve($field, $text, $context);
        if (empty($resolved['recognized'])) {
            return array_merge($resolved, ['applied'=>false]);
        }

        $applied = self::applyParameters($chatId, [$field=>$resolved['value']]);
        return array_merge($resolved, ['applied'=>!empty($applied[$field])]);
    }

    public static function applyParameters($chatId, array $params): array
    {
        if (empty($params) || !class_exists('MaxSearchApi')) return [];
        $applied = MaxSearchApi::applyAiParameters($chatId, $params);
        return is_array($applied) ? $applied : [];
    }
}
