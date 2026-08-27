<?php
require_once dirname(__DIR__) . '/ai/AiRouter.php';

class AiInvocationService
{
    public static function invoke(string $route, $chatId, string $userText, array $current): array
    {
        $route = strtoupper(trim($route));
        if (!in_array($route, ['RICH_AI', 'SHORT_AI'], true)) {
            $route = 'SHORT_AI';
        }

        @file_put_contents(
            dirname(__DIR__) . '/handlers/ai_debug.log',
            "\n" . date('d.m.Y H:i:s') . "--- chat=" . $chatId . " ---\n" .
            "ROUTE: " . $route . "\n" .
            "AI INPUT: " . $userText . "\n" .
            "AI CONTEXT BEFORE: " . json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );

        try {
            $ai = AiRouter::parseTourRequest($userText, $current);
            @file_put_contents(
                dirname(__DIR__) . '/handlers/ai_debug.log',
                "AI RAW: " . json_encode($ai, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
            return is_array($ai) ? $ai : ['_error'=>true];
        } catch (\Throwable $e) {
            @file_put_contents(
                dirname(__DIR__) . '/handlers/ai_errors.log',
                date('d.m.Y H:i:s') . '--- chat=' . $chatId . ' --- ' . $e->getMessage() . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            return ['_error'=>true];
        }
    }
}
