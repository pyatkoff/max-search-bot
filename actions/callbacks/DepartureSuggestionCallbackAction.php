<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/services/DepartureSuggestionService.php';
require_once dirname(__DIR__, 2) . '/services/InteractionGuard.php';
require_once dirname(__DIR__, 2) . '/services/NeedApplicationService.php';
require_once dirname(__DIR__, 2) . '/services/NeedProgressionService.php';
require_once dirname(__DIR__, 2) . '/services/MissingFieldQuestionService.php';

/** Explicit confirmation of a current advertising-region suggestion in AI mode. */
final class DepartureSuggestionCallbackAction
{
    public static function handles(string $payload): bool
    {
        return preg_match('/\Adeparture_suggest_(?:[1-9][0-9]*|other)\z/D', $payload) === 1;
    }

    public static function handle(int $chatId, string $payload, string $platform = ''): bool
    {
        return InteractionGuard::runExpectedStatusCallback($chatId, $payload, 'departure_suggestion',
            (int)MaxSearchApi::$statusAi, static function () use ($chatId, $payload, $platform): bool {
                $current = (array)MaxSearchApi::getAiSearchContext($chatId);
                $suggestion = DepartureSuggestionService::forChat($chatId, $current, $platform);
                if ($suggestion === []) return true;
                if ($payload === 'departure_suggest_other') {
                    return MissingFieldQuestionService::send($chatId, 'city', ['suppress_departure_suggestion'=>true]);
                }
                $id = substr($payload, strlen('departure_suggest_'));
                $candidate = DepartureSuggestionService::candidate($suggestion, $id);
                if ($candidate === null) return true;
                $applied = NeedApplicationService::applyParameters($chatId, ['city'=>$candidate['name']]);
                if (empty($applied['city'])) return false;
                $readback = (array)MaxSearchApi::getAiSearchContext($chatId);
                if ((string)($readback['city'] ?? '') !== $candidate['name']) return false;
                NeedProgressionService::advance($chatId);
                return true;
            });
    }
}
