<?php
require_once __DIR__ . '/../services/SearchRequestBuilder.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/DialogueView.php';

class SearchAction
{
    public static function plan(array $tripState, array $context = []): array
    {
        $request = SearchRequestBuilder::fromTripState($tripState);
        $providerPlan = IntegrationRegistry::searchProvider()->build($request, $context);
        return [
            'action'=>'OPEN_SEARCH',
            'request'=>$request,
            'ready'=>(bool)($providerPlan['ready'] ?? false),
            'missing'=>array_values((array)($providerPlan['missing'] ?? [])),
            'provider_plan'=>$providerPlan,
        ];
    }

    public static function execute($chatId, array $tripState, string $name = ''): bool
    {
        $plan = self::plan($tripState, ['chat_id'=>$chatId, 'name'=>$name]);
        if (!$plan['ready']) return false;
        return DialogueView::check($chatId);
    }
}
