<?php
require_once __DIR__ . '/NeedValueResolver.php';
require_once __DIR__ . '/ConversationStateRepository.php';
require_once __DIR__ . '/AiSearchContextService.php';
require_once __DIR__ . '/ExistingWizardStepApplicationService.php';
require_once __DIR__ . '/ChildAgeValueContract.php';

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
     * Resolve one deterministic wizard answer and apply it only to an already
     * existing step in the current dialogue session. This preserves the explicit
     * wizard's update-only/fail-closed semantics while keeping mutation behind
     * the canonical application boundary.
     */
    public static function resolveAndApplyExistingWizardStep(
        $chatId,
        string $field,
        string $text,
        int $statusId,
        array $context = []
    ): array {
        $resolved = NeedValueResolver::resolve($field, $text, $context);
        if (empty($resolved['recognized'])) {
            return array_merge($resolved, ['applied'=>false, 'storage_value'=>null]);
        }

        $storageValue = $resolved['value'];
        if ($field === 'meal') {
            $normalized = AiSearchContextService::normalizeParameters(
                ['meal'=>(string)$storageValue],
                static function($name){ return null; },
                static function($name){ return null; }
            );
            $storageValue = $normalized['meal'] ?? null;
        } elseif ($field === 'child_ages') {
            $childrenCount = (int)($context['children'] ?? 0);
            $storageValue = is_array($storageValue)
                ? ChildAgeValueContract::toStorage($storageValue, $childrenCount)
                : null;
        }

        if ($storageValue === null || $storageValue === '') {
            return array_merge($resolved, ['applied'=>false, 'storage_value'=>null]);
        }

        $applied = ExistingWizardStepApplicationService::apply(
            $chatId,
            $statusId,
            (string)$storageValue
        );

        return array_merge($resolved, [
            'applied'=>$applied,
            'storage_value'=>(string)$storageValue,
        ]);
    }

    /**
     * Promote only explicitly extracted, high-confidence wish changes into canonical
     * active metadata. The model still does not own state or progression. Neutral
     * removals are separate from opposite-polarity additions.
     */
    public static function applyExtractedPreferences($chatId, array $changes, array $confidence): bool
    {
        if (!class_exists('MaxSearchApi')) return false;
        $accepted = self::acceptedExtractedPreferences($changes, $confidence);
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

    private static function acceptedExtractedPreferences(array $changes, array $confidence): array
    {
        $accepted = [];
        foreach (['preferences','negative_preferences','preferences_remove','negative_preferences_remove'] as $key) {
            if (!array_key_exists($key, $changes) || !is_array($changes[$key]) || $changes[$key] === []) continue;
            $score = $confidence[$key] ?? null;
            if ((!is_int($score) && !is_float($score)) || !is_finite((float)$score)
                || (float)$score < self::EXTRACTED_PREFERENCE_CONFIDENCE || (float)$score > 1.0) continue;
            $accepted[$key] = $changes[$key];
        }
        return $accepted;
    }
}