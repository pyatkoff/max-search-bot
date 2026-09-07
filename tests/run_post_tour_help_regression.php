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
    helpCheck('unrelated/mixed text is not classified as link trouble', PostTourService::isLinkHelpRequest($phrase), false);
    helpCheck('unrelated/mixed check text receives guidance', helpDispatch($phrase), true);
    helpCheck('check guidance does not claim a link failure', strpos($messenger->sent[0]['text'] ?? '', 'Понимаю, подборка не открывается') === false, true);
}

MaxSearchApi::$claim = [];
helpCheck('missing prior result still allows neutral check guidance', helpDispatch('Ссылка не работает'), true);
helpCheck('no prior result produces no post-tour link advice', strpos($messenger->sent[0]['text'] ?? '', 'Посмотреть на сайте') === false, true);
MaxSearchApi::$claim = ['UF_CODE'=>'synthetic-existing-claim'];

// Fresh live evidence: a completed check summary was followed by a country
// correction, but the statusCheck text path silently ignored both messages.
// Guide into existing explicit edit controls; do not claim the value was saved.
foreach (['max', 'telegram'] as $platform) {
    foreach (['ОАЭ', 'Хотим в ОАЭ', 'Поменять дату'] as $phrase) {
        helpCheck('check text is handled', helpDispatch($phrase,74,$platform), true);
        helpCheck('check text gets one answer', count($messenger->sent), 1);
        helpCheck('check guidance preserves status', MaxSearchApi::$status, 74);
        $sent = $messenger->sent[0] ?? [];
        helpCheck('guidance offers existing edit callback', $sent['buttons'][0][0]['callback_data'] ?? null, 'edit_params');
        helpCheck('guidance truthfully leaves values unchanged', strpos($sent['text'] ?? '', 'Параметры пока не изменены') !== false, true);
        helpCheck('guidance has no search or manager side action', count($sent['buttons'] ?? []), 1);
        helpCheck('guidance never echoes customer text or invents URL', strpos(json_encode($sent), 'http') === false && strpos($sent['text'] ?? '', $phrase) === false, true);
    }
}

// Fresh production evidence: in the completed check state, "Цена" and
// "Какая цена" received generic parameter-edit guidance. Point only these
// bounded questions to the existing results control; never start the search.
foreach (['max', 'telegram'] as $platform) {
    foreach (['Цена', 'Какая цена', "  КАКАЯ   ЦЕНА?  "] as $phrase) {
        helpCheck('price question classifier accepts exact request', DialogueController::isCheckPriceQuestion($phrase), true);
        helpCheck('check price question is handled', helpDispatch($phrase,74,$platform), true);
        helpCheck('check price question gets one answer', count($messenger->sent), 1);
        helpCheck('price guidance preserves status', MaxSearchApi::$status, 74);
        $sent = $messenger->sent[0] ?? [];
        helpCheck('price guidance names current prices', strpos($sent['text'] ?? '', 'актуальные цены') !== false, true);
        helpCheck('price guidance points to existing tours control', strpos($sent['text'] ?? '', 'Показать туры') !== false, true);
        helpCheck('price guidance has no new callback side action', $sent['buttons'] ?? null, []);
        helpCheck('price guidance does not invent a URL', strpos(json_encode($sent), 'http') === false, true);
    }
}
foreach (['Какая цена и поменять дату', 'Цена до 200 тысяч', 'Дорого', 'Цены на октябрь'] as $phrase) {
    helpCheck('mixed or broader price text stays outside narrow classifier', DialogueController::isCheckPriceQuestion($phrase), false);
    helpCheck('mixed or broader check text receives generic guidance', helpDispatch($phrase), true);
    helpCheck('mixed or broader text does not get price claim', strpos($messenger->sent[0]['text'] ?? '', 'актуальные цены') === false, true);
}
helpCheck('blank check text remains harmless', helpDispatch('   '), true);
helpCheck('blank check text sends nothing', $messenger->sent, []);

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
helpCheck('failed check guidance returns failure without AI fallthrough', helpDispatch('Хотим в ОАЭ'), false);
helpCheck('failed check guidance attempts once', count($messenger->sent), 1);
helpCheck('failed check guidance preserves state', MaxSearchApi::$status, 74);
helpCheck('failed price guidance returns failure without AI fallthrough', helpDispatch('Какая цена'), false);
helpCheck('failed price guidance attempts once', count($messenger->sent), 1);
helpCheck('failed price guidance preserves state', MaxSearchApi::$status, 74);

IntegrationRegistry::resetForTests();
echo "\nTOTAL " . ($passed+$failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
