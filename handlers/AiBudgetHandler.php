<?php

require_once __DIR__.'/../services/RuntimeStorage.php';
require_once __DIR__.'/../services/ConversationStateRepository.php';
require_once __DIR__.'/../services/NeedApplicationService.php';
require_once __DIR__.'/../services/NeedProgressionService.php';
require_once __DIR__.'/../services/IntegrationRegistry.php';
require_once __DIR__.'/../services/OptionalBudgetPromptService.php';

/** Capture explicit budget only inside the controller's existing self-service AI branch. */
final class AiBudgetHandler
{
    public static function handle($chatId, string $text): bool
    {
        if (!RuntimeStorage::usesMysql()) return false;
        try {
            $resolved=NeedApplicationService::resolveAndApply($chatId,'budget',$text);
        } catch (Throwable $e) {
            $resolved=NeedValueResolver::resolve('budget',$text);
            $resolved['applied']=false; // No DB details or false success acknowledgement.
        }
        if (!$resolved['recognized']) return false;
        if (empty($resolved['applied'])) {
            IntegrationRegistry::messenger()->send($chatId,'Не удалось сохранить бюджет. Его можно повторить или сообщить менеджеру.');
            return (bool)$resolved['only_budget'];
        }
        if (!$resolved['only_budget']) return false;
        NeedProgressionService::advance($chatId);
        return true;
    }

    /**
     * A completed check remains a button-confirmation surface. Only a definite
     * budget-only clarification may mutate state here; mixed text stays on the
     * existing check guidance path without partially applying its money clause.
     */
    public static function handleCheck($chatId, string $text): bool
    {
        if (!RuntimeStorage::usesMysql()) return false;

        // The one optional budget clarification may be declined explicitly.
        // Consume that reply only while the active budget is still unknown: a
        // later generic "не знаю" must never erase or contradict a known budget.
        if (OptionalBudgetPromptService::isExplicitSkipText($text)) {
            try {
                $context=(array)MaxSearchApi::getAiSearchContext($chatId);
            } catch (Throwable $e) {
                return false;
            }
            if (OptionalBudgetPromptService::budgetLine($context) === null) {
                OptionalBudgetPromptService::sendSkippedConfirmation($chatId);
                return true;
            }
        }

        $resolved=NeedValueResolver::resolve('budget',$text);
        if (empty($resolved['recognized']) || empty($resolved['only_budget'])) return false;

        try {
            $applied=NeedApplicationService::applyParameters($chatId,['budget_update'=>[
                'snapshot'=>ConversationStateRepository::budgetSnapshot($chatId,(int)MaxSearchApi::$statusStart),
                'changes'=>$resolved['value'],
            ]]);
        } catch (Throwable $e) {
            $applied=[];
        }

        if (empty($applied['budget'])) {
            IntegrationRegistry::messenger()->send($chatId,'Не удалось сохранить бюджет. Его можно повторить или сообщить менеджеру.');
            return true;
        }

        OptionalBudgetPromptService::sendSavedConfirmation($chatId);
        return true;
    }
}
