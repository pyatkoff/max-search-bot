<?php
require_once dirname(__DIR__) . '/ai/AiRouter.php';
require_once __DIR__ . '/AiRuntimeLogger.php';
require_once __DIR__ . '/SourceHandlingService.php';

class AiInvocationService
{
    public static function invoke(string $route, $chatId, string $userText, array $current): array
    {
        $route = strtoupper(trim($route));
        if (!in_array($route, ['RICH_AI', 'SHORT_AI'], true)) {
            $route = 'SHORT_AI';
        }

        $aiCurrent = $current;
        try {
            require_once __DIR__ . '/WebsitePageContextService.php';
            $pageContext = WebsitePageContextService::forChat((int)$chatId);
            if ($pageContext) $aiCurrent['_page_context'] = $pageContext;
        } catch (\Throwable $ignored) {}

        $sourcePost=SourceHandlingService::sourcePostContext($chatId);
        if($sourcePost)$aiCurrent['_source_post']=$sourcePost;
        $recentDialogue=SourceHandlingService::recentDialogueContext($chatId);
        if($recentDialogue)$aiCurrent['_recent_dialogue']=$recentDialogue;

        AiRuntimeLogger::debug(
            "\n" . date('d.m.Y H:i:s') . "--- chat=" . $chatId . " ---\n" .
            "ROUTE: " . $route . "\n" .
            "AI INPUT: " . $userText . "\n" .
            "AI CONTEXT BEFORE: " . json_encode($aiCurrent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        try {
            $ai = AiRouter::parseTourRequest($userText, $aiCurrent);
            AiRuntimeLogger::debug(
                "AI RAW: " . json_encode($ai, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            );
            return is_array($ai) ? $ai : ['_error'=>true];
        } catch (\Throwable $e) {
            AiRuntimeLogger::error(
                date('d.m.Y H:i:s') . '--- chat=' . $chatId . ' --- ' . $e->getMessage() . PHP_EOL
            );
            return ['_error'=>true];
        }
    }
}
