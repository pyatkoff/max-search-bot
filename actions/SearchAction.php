<?php
require_once __DIR__ . '/../services/SearchRequestBuilder.php';

class SearchAction
{
    public static function plan(array $tripState): array
    {
        $request = SearchRequestBuilder::fromTripState($tripState);
        return [
            'action'=>'OPEN_SEARCH',
            'request'=>$request,
            'ready'=>SearchRequestBuilder::isReady($request),
            'missing'=>SearchRequestBuilder::missingRequired($request),
        ];
    }

    public static function execute($chatId, array $tripState, string $name = ''): bool
    {
        $plan = self::plan($tripState);
        if (!$plan['ready']) return false;
        MaxSearchApi::showCheckButtons($chatId);
        return true;
    }
}
