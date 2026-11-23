<?php
require_once dirname(__DIR__) . '/ai/AiRouter.php';
require_once __DIR__ . '/AiRuntimeLogger.php';
require_once __DIR__ . '/SourceHandlingService.php';

class AiInvocationService
{
    public static function invoke(string $route, $chatId, string $userText, array $current): array
    {
        // This identifies this invocation only, not the incoming turn or an
        // outgoing message. Missing entropy must not change the business path.
        $invocationId = null;
        try {
            $invocationId = bin2hex(random_bytes(16));
        } catch (\Throwable $ignored) {}

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

        self::recordInvocation('debug', 'input', $invocationId,
            "\n" . date('d.m.Y H:i:s') . "--- chat=" . $chatId . " ---\n" .
            "ROUTE: " . $route . "\n" .
            "AI INPUT: " . $userText . "\n" .
            "AI CONTEXT BEFORE: " . json_encode($aiCurrent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        try {
            $ai = AiRouter::parseTourRequest($userText, $aiCurrent);
            self::recordInvocation('debug', 'result', $invocationId,
                "AI RAW: " . json_encode($ai, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            );
            return is_array($ai) ? $ai : ['_error'=>true];
        } catch (\Throwable $e) {
            self::recordInvocation('error', 'exception', $invocationId,
                date('d.m.Y H:i:s') . '--- chat=' . $chatId . ' --- ' . $e->getMessage() . PHP_EOL
            );
            return ['_error'=>true];
        }
    }

    /** One bounded private append; payload length/hash preserve raw boundaries. */
    private static function recordInvocation(string $stream, string $stage, ?string $invocationId, string $payload): void
    {
        try {
            $metadata = json_encode([
                'schema' => 'anytour.ai_invocation.v1',
                'invocation_id' => $invocationId,
                'stage' => $stage,
                // Recording time, not provider timing or a historical timestamp.
                'recorded_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'payload_bytes' => strlen($payload),
                'payload_sha256' => hash('sha256', $payload),
            ], JSON_UNESCAPED_SLASHES);
            if ($metadata === false) return;
            $record = 'AI INVOCATION: ' . $metadata . "\n" . $payload;
            if ($stream === 'error') {
                AiRuntimeLogger::error($record);
            } else {
                AiRuntimeLogger::debug($record);
            }
        } catch (\Throwable $ignored) {
            // A missing diagnostic cannot replace a successful model result.
        }
    }
}
