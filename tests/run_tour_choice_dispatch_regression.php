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
$saved = []; $notified = [];
$save = static function(array $value) use (&$saved): array {
    $saved[] = $value;
    return ['ok'=>true,'changed'=>count($saved)===1,'conversation_id'=>17];
};
$notify = static function(int $id) use (&$notified): void { $notified[] = $id; };
choiceDispatchCheck('ordinary text is not interpreted as a choice', !TourChoiceCallbackAction::handle(
    IncomingMessage::text('telegram',77,77,'plain','Нравится'),$save,$notify));
$other=$incoming; $other['callback_data']='manager_request';
choiceDispatchCheck('other callback families keep their owner', !TourChoiceCallbackAction::handle($other,$save,$notify) && !$saved);
$incoming['project_key']='forged_project';
choiceDispatchCheck('choice is handled',TourChoiceCallbackAction::handle($incoming,$save,$notify));
choiceDispatchCheck('project is assigned by server configuration',$saved[0]['project_key']===ProjectConfig::projectId());
choiceDispatchCheck('one durable preference emits one manager notification',$notified===[17]);
TourChoiceCallbackAction::handle($incoming,$save,$notify);
choiceDispatchCheck('duplicate preference does not send another manager notification',$notified===[17]);
TourChoiceCallbackAction::handle($incoming,static fn()=>['ok'=>false,'changed'=>false],$notify);
choiceDispatchCheck('rejected preference does not notify',$notified===[17]);
choiceDispatchCheck('storage failure is contained without another dialogue action',TourChoiceCallbackAction::handle(
    $incoming,static fn()=>throw new RuntimeException('synthetic failure'),$notify));
choiceDispatchCheck('notification failure does not replay or fail the saved preference',TourChoiceCallbackAction::handle(
    $incoming,static fn()=>['ok'=>true,'changed'=>true,'conversation_id'=>17],static fn()=>throw new RuntimeException('synthetic push failure')));

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

$output=[];$code=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/tour_choice_owned_dispatch.php').' 2>&1',$output,$code);
echo implode(PHP_EOL,$output).PHP_EOL;
choiceDispatchCheck('real dispatcher preserves manager ownership for both messengers',$code===0);

echo 'PASS '.$checks." tour choice dispatch checks\n";
