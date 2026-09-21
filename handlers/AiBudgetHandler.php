<?php

require_once __DIR__.'/../services/RuntimeStorage.php';
require_once __DIR__.'/../services/ConversationStateRepository.php';
require_once __DIR__.'/../services/NeedApplicationService.php';
require_once __DIR__.'/../services/NeedProgressionService.php';
require_once __DIR__.'/../services/IntegrationRegistry.php';

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
}
