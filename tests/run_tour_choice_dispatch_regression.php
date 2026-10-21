<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/actions/callbacks/TourChoiceCallbackAction.php';
require_once dirname(__DIR__).'/services/DialogueApplication.php';
require_once dirname(__DIR__).'/services/IncomingUpdateDispatcher.php';
require_once dirname(__DIR__).'/services/IncomingMessage.php';

$checks = 0;
function choiceDispatchCheck(string $name, bool $ok): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$checks;
}
$payload = 'tour_like_17_'.str_repeat('a', 24);
$incoming = IncomingMessage::callback('telegram', 77, 77, 'choice-cb', $payload);
$saved = []; $notified = []; $feedback = [];
$save = static function(array $value) use (&$saved): array {
    $saved[] = $value;
    return ['ok'=>true,'changed'=>count($saved)===1,'conversation_id'=>17];
};
$notify = static function(int $id) use (&$notified): void { $notified[] = $id; };
$respond = static function(array $value, string $text) use (&$feedback, &$saved): void {
    $feedback[] = [$value['platform'], $value['user']['chat_id'], $text, count($saved)];
};
choiceDispatchCheck('ordinary text is not interpreted as a choice', !TourChoiceCallbackAction::handle(
    IncomingMessage::text('telegram',77,77,'plain','Нравится'),$save,$notify,$respond));
$other=$incoming; $other['callback_data']='manager_request';
choiceDispatchCheck('other callback families keep their owner', !TourChoiceCallbackAction::handle($other,$save,$notify,$respond) && !$saved && !$feedback);
$incoming['project_key']='forged_project';
choiceDispatchCheck('choice is handled',TourChoiceCallbackAction::handle($incoming,$save,$notify,$respond));
choiceDispatchCheck('project is assigned by server configuration',$saved[0]['project_key']===ProjectConfig::projectId());
choiceDispatchCheck('one durable preference emits one manager notification',$notified===[17]);
choiceDispatchCheck('truthful confirmation follows save and targets the current callback chat', $feedback === [
    ['telegram',77,'✅ Выбор сохранён. Менеджер увидит этот тур и его условия.',1]]);
TourChoiceCallbackAction::handle($incoming,$save,$notify,$respond);
choiceDispatchCheck('duplicate preference reports its saved state without another manager notification',$notified===[17]
    && count($feedback)===2 && end($feedback)[2]==='Этот вариант уже сохранён. Менеджер видит ваш выбор.');
TourChoiceCallbackAction::handle($incoming,static fn()=>['ok'=>false,'changed'=>false],$notify,$respond);
choiceDispatchCheck('rejected preference does not notify',$notified===[17]);
choiceDispatchCheck('rejected preference gives a retry response without claiming success', end($feedback)[2] === 'Не удалось сохранить выбор. Попробуйте нажать «Нравится» ещё раз.');
choiceDispatchCheck('storage failure is contained without another dialogue action',TourChoiceCallbackAction::handle(
    $incoming,static fn()=>throw new RuntimeException('synthetic failure'),$notify,$respond));
choiceDispatchCheck('storage exception cannot produce a saved confirmation', end($feedback)[2] === 'Не удалось сохранить выбор. Попробуйте нажать «Нравится» ещё раз.');
choiceDispatchCheck('notification failure does not replay or fail the saved preference',TourChoiceCallbackAction::handle(
    $incoming,static fn()=>['ok'=>true,'changed'=>true,'conversation_id'=>17],static fn()=>throw new RuntimeException('synthetic push failure'),$respond));
choiceDispatchCheck('push failure cannot conceal a successfully saved preference', end($feedback)[2] === '✅ Выбор сохранён. Менеджер увидит этот тур и его условия.');
$order=[];
TourChoiceCallbackAction::handle($incoming,
    static function() use (&$order): array {$order[]='save'; return ['ok'=>true,'changed'=>true,'conversation_id'=>17];},
    static function() use (&$order): void {$order[]='notify';},
    static function() use (&$order): void {$order[]='feedback'; throw new RuntimeException('synthetic transport failure');});
choiceDispatchCheck('confirmation transport failure preserves save and independent manager notification', $order===['save','feedback','notify']);

// Exercise the actual request-scoped messenger default for both transports.
foreach (['telegram','max'] as $channel) {
    $requests=[];
    $messenger = $channel==='telegram'
        ? new TelegramMessengerAdapter(static function($method,$body) use (&$requests): bool {$requests[]=[$method,$body]; return true;})
        : new MaxMessengerAdapter(static function($chatId,$text) use (&$requests): bool {$requests[]=[$chatId,$text]; return true;});
    IntegrationRegistry::useMessenger($messenger);
    $value=$incoming; $value['platform']=$channel;
    TourChoiceCallbackAction::handle($value,static fn()=>['ok'=>true,'changed'=>true,'conversation_id'=>17],static fn()=>null);
    choiceDispatchCheck('default confirmation uses the current '.$channel.' transport exactly once', count($requests)===1);
    choiceDispatchCheck('default confirmation reaches only the current '.$channel.' chat', $channel==='telegram'
        ? $requests[0][0]==='sendMessage' && $requests[0][1]['chat_id']===77 && $requests[0][1]['text']==='✅ Выбор сохранён. Менеджер увидит этот тур и его условия.'
        : $requests[0] === [77,'✅ Выбор сохранён. Менеджер увидит этот тур и его условия.']);
}
IntegrationRegistry::useMessenger(new TelegramMessengerAdapter(static fn()=>true));

// With the ordinary DB-unconfigured regression runtime, a card callback still
// follows the real acknowledgement path and cannot fall through to the AI/wizard.
$acks=[]; $legacy=[];
$application = new DialogueApplication(null,static function() use (&$legacy): void { $legacy[]='called'; },null,
    static function(string $id) use (&$acks): void { $acks[]=$id; });
choiceDispatchCheck('application contains an unavailable card preference',$application->dispatch($incoming));
choiceDispatchCheck('native callback acknowledgement remains exactly once',$acks===['choice-cb']);
choiceDispatchCheck('choice never falls through to ordinary callback actions',$legacy===[]);
$other['callback_id']='manager-cb';
$application->dispatch($other);
choiceDispatchCheck('existing manager callback still dispatches',$legacy===['called'] && $acks===['choice-cb','manager-cb']);
IntegrationRegistry::resetForTests();

$output=[];$code=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/tour_choice_owned_dispatch.php').' 2>&1',$output,$code);
echo implode(PHP_EOL,$output).PHP_EOL;
choiceDispatchCheck('real dispatcher preserves manager ownership for both messengers',$code===0);

echo 'PASS '.$checks." tour choice dispatch checks\n";
