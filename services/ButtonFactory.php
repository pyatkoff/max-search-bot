<?php

class ButtonFactory
{
    public static function callback(string $text, string $payload): array
    {
        return ['text'=>$text, 'callback_data'=>$payload];
    }

    public static function url(string $text, string $url): array
    {
        return ['text'=>$text, 'url'=>$url];
    }

    public static function contact(string $text): array
    {
        return ['text'=>$text, 'request_contact'=>true];
    }

    public static function row(array ...$buttons): array
    {
        return $buttons;
    }

    public static function rows(array ...$rows): array
    {
        return $rows;
    }

    public static function back(string $payload, string $text = '← Назад'): array
    {
        return self::callback($text, $payload);
    }
}
