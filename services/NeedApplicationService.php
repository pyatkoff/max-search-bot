<?php
require_once __DIR__ . '/NeedValueResolver.php';
require_once __DIR__ . '/ConversationStateRepository.php';

/**
 * Canonical boundary between deterministic need-value resolution and trip-state application.
 *
 * Keeps parser ownership in NeedValueResolver while making the state mutation path explicit.
 * Multi-field clarifications may still enter through applyParameters(), but callers no longer
 * need to invoke MaxSearchApi::applyAiParameters directly.
 */
class NeedApplicationService
{
    private const EXTRACTED_PREFERENCE_CONFIDENCE = 0.90;

    public static function resolveAndApply($chatId, string $field, string $text, array $context = []): array
    {
        $resolved = NeedValueResolver::resolve($field, $text, $context);
        if (empty($resolved['recognized'])) {
            return array_merge($resolved, ['applied'=>false]);
        }

        $params = [$field=>$resolved['value']];
        if ($field === 'budget') {
            $params = ['budget_update'=>[
                'snapshot'=>ConversationStateRepository::budgetSnapshot($chatId, (int)MaxSearchApi::$statusStart),
                'changes'=>$resolved['value'],
            ]];
        }
        $applied = self::applyParameters($chatId, $params);
        return array_merge($resolved, ['applied'=>!empty($applied[$field])]);
    }

    /**
     * Promote only explicitly extracted, high-confidence wishes into canonical
     * active metadata. The model still does not own state or progression.
     */
    public static function applyExtractedPreferences($chatId, array $changes, array $confidence): bool
    {
        if (!class_exists('MaxSearchApi')) return false;
        $accepted = [];
        foreach (['preferences','negative_preferences'] as $key) {
            if (!array_key_exists($key, $changes) || !is_array($changes[$key]) || $changes[$key] === []) continue;
            $score = $confidence[$key] ?? null;
            if ((!is_int($score) && !is_float($score)) || !is_finite((float)$score)
                || (float)$score < self::EXTRACTED_PREFERENCE_CONFIDENCE || (float)$score > 1.0) continue;
            $accepted[$key] = $changes[$key];
        }
        if ($accepted === []) return false;
        $applied = self::applyParameters($chatId, ['preferences_update'=>[
            'snapshot'=>ConversationStateRepository::preferenceSnapshot($chatId, (int)MaxSearchApi::$statusStart),
            'changes'=>$accepted,
        ]]);
        return !empty($applied['preferences']);
    }

    public static function applyParameters($chatId, array $params): array
    {
        if (empty($params) || !class_exists('MaxSearchApi')) return [];
        $metadataApplied = [];
        if (array_key_exists('budget_update', $params)) {
            if (is_array($params['budget_update']) && ConversationStateRepository::applyBudget($chatId, $params['budget_update'], (int)MaxSearchApi::$statusStart)) {
                $metadataApplied['budget'] = true;
            }
            unset($params['budget_update']);
        }
        if (array_key_exists('preferences_update', $params)) {
            if (is_array($params['preferences_update']) && ConversationStateRepository::applyPreferences($chatId, $params['preferences_update'], (int)MaxSearchApi::$statusStart)) {
                $metadataApplied['preferences'] = true;
            }
            unset($params['preferences_update']);
        }
        $applied = $params === [] ? [] : MaxSearchApi::applyAiParameters($chatId, $params);
        return array_merge(is_array($applied) ? $applied : [], $metadataApplied);
    }
}
