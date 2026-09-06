<?php

declare(strict_types=1);

require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';

final class ToursCheckedStateMessenger implements MessengerInterface
{
    public bool $succeeds = true;
    public array $sent = [];

    public function send($chatId, string $text): bool
    {
        return $this->sendWithButtons($chatId, $text, []);
    }

    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        $this->sent[] = ['chat_id'=>(int)$chatId, 'text'=>$text, 'buttons'=>$buttons];
        return $this->succeeds;
    }

    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool
    {
        return false;
    }
}

class MaxSearchApi
{
    public static $statusPhone = 75;
    public static $statusAi = 76;
    public static int $currentStatus = 75;
    public static array $transitions = [];
    public static int $deletes = 0;

    public static function getCurentStatus($chatId): int
    {
        return self::$currentStatus;
    }

    public static function setStatus($chatId, $status): void
    {
        self::$currentStatus = (int)$status;
        self::$transitions[] = [(int)$chatId, (int)$status];
    }

    public static function deletePrevMessage($chatId, $withButtons = false): void
    {
        self::$deletes++;
    }
}

require_once __DIR__ . '/../actions/callbacks/ToursCallbackAction.php';

$passed = 0;
$failed = 0;
function toursCheckedStateCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$messenger = new ToursCheckedStateMessenger();
IntegrationRegistry::resetForTests($messenger, null, null);

MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusPhone;
MaxSearchApi::$transitions = [];
$handled = ToursCallbackAction::handle(501, 'tours_checked', []);
toursCheckedStateCheck('phone-return callback is consumed', $handled, true);
toursCheckedStateCheck('after-tours question is rendered once', count($messenger->sent), 1);
toursCheckedStateCheck('successful return leaves phone input state', MaxSearchApi::$transitions, [[501, 76]]);
toursCheckedStateCheck('successful return activates normal AI text handling', MaxSearchApi::$currentStatus, 76);

MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusAi;
MaxSearchApi::$transitions = [];
$handledFromAi = ToursCallbackAction::handle(502, 'tours_checked', []);
toursCheckedStateCheck('ordinary after-tours callback remains consumed', $handledFromAi, true);
toursCheckedStateCheck('ordinary after-tours callback keeps existing state', MaxSearchApi::$transitions, []);

$messenger->succeeds = false;
MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusPhone;
MaxSearchApi::$transitions = [];
$failedRender = ToursCallbackAction::handle(503, 'tours_checked', []);
toursCheckedStateCheck('failed question render reports transport failure', $failedRender, false);
toursCheckedStateCheck('failed question render preserves retryable phone state', MaxSearchApi::$transitions, []);
toursCheckedStateCheck('failed question render does not strand a silent AI state', MaxSearchApi::$currentStatus, 75);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/ToursCallbackAction.php');
toursCheckedStateCheck('return transition does not erase saved trip or phone data', strpos($source, 'deleteAllStatus') === false, true);

IntegrationRegistry::resetForTests();
$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
