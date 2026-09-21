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

    public static function applyParameters($chatId, array $params): array
    {
        if (empty($params) || !class_exists('MaxSearchApi')) return [];
        $budgetApplied = [];
        if (array_key_exists('budget_update', $params)) {
            if (is_array($params['budget_update']) && ConversationStateRepository::applyBudget($chatId, $params['budget_update'], (int)MaxSearchApi::$statusStart)) $budgetApplied['budget'] = true;
            unset($params['budget_update']);
        }
        $applied = $params === [] ? [] : MaxSearchApi::applyAiParameters($chatId, $params);
        return array_merge(is_array($applied) ? $applied : [], $budgetApplied);
    }
}
