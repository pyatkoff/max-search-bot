<?php

declare(strict_types=1);

require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';

final class PostTourHelpMessenger implements MessengerInterface
{
    public array $sent = [];
    public bool $succeeds = true;
    public function send($chatId, string $text): bool
    {
        return $this->sendWithButtons($chatId, $text, []);
    }
    public function sendWithButtons($chatId, string $text, array $buttons): bool
    {
        $this->sent[] = ['chat_id'=>$chatId, 'text'=>$text, 'buttons'=>$buttons];
        return $this->succeeds;
    }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool
    {
        throw new RuntimeException('Help must not request a phone or hand off automatically');
    }
}

class MaxSearchApi
{
    public static $statusStart=64, $statusCityChoose=65, $statusContryChoose=66,
        $statusAdults=67, $statusChild=68, $statusAge=69, $statusStars=70,
        $statusMeal=71, $statusNights=72, $statusDate=73, $statusCheck=74,
        $statusPhone=75, $statusAi=76;
    public static int $status = 74;
    public static array $claim = ['UF_CODE'=>'synthetic-existing-claim'];
    public static function getCurentStatus($chatId) { return self::$status; }
    public static function getLastClaimForChat($chatId) { return self::$claim; }
    public static function __callStatic($method, $args)
    {
        throw new RuntimeException('Unexpected state/search/lead operation: ' . $method);
    }
}

require_once __DIR__ . '/../services/DialogueController.php';

$passed=0; $failed=0;
function helpCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; }
    else { echo "FAIL  {$name}\n"; $failed++; }
}
$messenger = new PostTourHelpMessenger();
IntegrationRegistry::resetForTests($messenger, null, null);
$controller = new DialogueController();
function helpDispatch(string $text, int $status = 74, string $platform = 'max'): ?bool
{
    global $messenger, $controller;
    MaxSearchApi::$status = $status;
    $messenger->sent = [];
    try {
        return $controller->handleIncomingMessage([
            'platform'=>$platform, 'user'=>['chat_id'=>501], 'text'=>$text,
        ]);
    } catch (Throwable $e) {
        helpCheck('no unrelated handler or mutation for ' . $text, $e->getMessage(), null);
        return null;
    }
}

// The live defect: results leave the wizard in statusCheck, whose text handler
// silently ignored this message. Also exercise the AI state after #721's return.
foreach ([74,76] as $status) {
    foreach (['Ссылка не работает','Подборка не открывается'] as $phrase) {
        helpCheck("{$status}: help is handled", helpDispatch($phrase, $status), true);
        helpCheck("{$status}: one answer", count($messenger->sent), 1);
        helpCheck("{$status}: wizard status is untouched", MaxSearchApi::$status, $status);
        $sent = $messenger->sent[0] ?? [];
        helpCheck('existing manager callback', $sent['buttons'][0][0]['callback_data'] ?? null, 'manager_after_tours');
        helpCheck('existing edit callback', $sent['buttons'][1][0]['callback_data'] ?? null, 'edit_params');
        helpCheck('answer acknowledges opening issue', strpos($sent['text'] ?? '', 'не открывается') !== false, true);
        helpCheck('answer gives existing result-button guidance', strpos($sent['text'] ?? '', 'Посмотреть на сайте') !== false, true);
        helpCheck('help does not invent or resend a URL', strpos(json_encode($sent), 'http') === false, true);
    }
}

foreach (["  ССЫЛКА НЕ РАБОТАЕТ!  ", "Не открывается подборка", "Не могу открыть ссылку", "Подборка\nне загружается", 'Не работает ссылка на туры'] as $phrase) {
    helpCheck('explicit help variant', helpDispatch($phrase), true);
    helpCheck('variant receives one answer', count($messenger->sent), 1);
}
foreach (['Ссылка работает', 'Подборка открывается', 'Не работает телефон', 'Не открывается ссылка на канал', 'Ссылка не работает, хочу Турцию на 7 ночей', 'Подборка не открывается? Нет, всё работает', 'Хочу Египет', 'manager_after_tours'] as $phrase) {
    helpCheck('unrelated/mixed text stays on existing check path', helpDispatch($phrase), true);
    helpCheck('unrelated text is not intercepted', $messenger->sent, []);
}

MaxSearchApi::$claim = [];
helpCheck('missing prior result remains unchanged', helpDispatch('Ссылка не работает'), true);
helpCheck('no prior result produces no post-tour advice', $messenger->sent, []);
MaxSearchApi::$claim = ['UF_CODE'=>'synthetic-existing-claim'];

helpCheck('phone input path still handles message', helpDispatch('Подборка не открывается',75), true);
helpCheck('phone path is not replaced by help', strpos($messenger->sent[0]['text'] ?? '', 'распознать номер') !== false, true);
helpCheck('unrelated wizard state stays untouched', helpDispatch('Ссылка не работает',67), true);
helpCheck('adults wizard is not intercepted', $messenger->sent, []);

helpCheck('shared Telegram controller gives help', helpDispatch('Ссылка не работает',74,'telegram'), true);
helpCheck('Telegram gets one platform-neutral answer', count($messenger->sent), 1);
$messenger->succeeds = false;
helpCheck('failed send reports failure, without AI fallthrough', helpDispatch('Ссылка не работает'), false);
helpCheck('failed send attempts once', count($messenger->sent), 1);
helpCheck('failed send preserves state', MaxSearchApi::$status, 74);

IntegrationRegistry::resetForTests();
echo "\nTOTAL " . ($passed+$failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
