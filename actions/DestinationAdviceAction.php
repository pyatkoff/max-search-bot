<?php

class DestinationAdviceAction
{
    public static function plan(array $tripState): array
    {
        return [
            'action'=>'SHOW_OPTIONS',
            'departure_city'=>$tripState['departure']['city'] ?? null,
            'departure_city_id'=>$tripState['departure']['city_id'] ?? null,
            'period'=>$tripState['dates']['month'] ?? null,
        ];
    }

    public static function execute($chatId, string $text): bool
    {
        return DepartureRouteAdviceHandler::handle($chatId, $text);
    }
}
