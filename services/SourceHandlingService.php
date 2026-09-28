<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationRecorder.php';
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/ManagerHandoffDispatchService.php';

/**
 * Applies the configured conversation start policy for a concrete source.
 * This layer deliberately does not emit Metrika goals and does not alter
 * manager availability, shifts, priority bonuses or routing rules.
 */
class SourceHandlingService
{
    public const AI='ai';
    public const MANAGER='manager';
    public const ASK='ask';
    private const CHOICE_MANAGER='source_choice_manager';
    private const CHOICE_AI='source_choice_ai';

    public static function handle(array $incoming): bool
    {
        try {
            if(!ConversationDb::isConfigured())return false;
            $platform=strtolower(trim((string)($incoming['platform']??'')));
            $chatId=$incoming['user']['chat_id']??0;
            $type=(string)($incoming['type']??'');
            if($platform===''||!$chatId)return false;
            $maxStart=$platform==='max'&&$type==='bot_started';

            $row=self::conversation($platform,$chatId);
            if(!$row)return false;
            $mode=(string)($row['handling_mode']??self::AI);
            if(!in_array($mode,[self::AI,self::MANAGER,self::ASK],true)||$mode===self::AI)return false;

            $callback=$type==='callback'?(string)($incoming['callback_data']??''):'';
            if(in_array($callback,['back_check','tours_checked'],true)){
                self::recordChoice($platform,$chatId,self::AI,'handoff_back');
                return false;
            }

            // Existing dispatcher ownership must still receive replies and contacts.
            // Keep Back above this check so its saved self-service choice is restored.
            if($platform==='max'&&!$maxStart&&in_array((string)$row['status'],['manager','waiting_manager'],true))return false;

            $choice=self::latestChoice((int)$row['id']);
            // A new explicit MAX source entry must not inherit an obsolete AI choice.
            if($choice===self::AI&&!$maxStart)return false;
            if($choice===self::MANAGER){
                // A previous outside-hours offer is not an active manager handoff.
                if($maxStart&&$mode===self::MANAGER&&(string)$row['status']==='ai'
                    &&self::deferredStartCanResume((int)$row['id'])){
                    self::handoff($incoming,$platform,$chatId,'source_policy');
                    return true;
                }
                if($platform==='max'&&!$maxStart){
                    if($type==='contact'||$callback==='phone_manual')return false;
                    if($type==='message'&&class_exists('MaxSearchApi')&&isset(MaxSearchApi::$statusPhone)
                        &&method_exists('MaxSearchApi','getCurentStatus')
                        &&MaxSearchApi::getCurentStatus($chatId)==MaxSearchApi::$statusPhone)return false;
                }
                return true;
            }

            if($mode===self::ASK){
                if($callback===self::CHOICE_AI){
                    self::recordChoice($platform,$chatId,self::AI,'customer_choice');
                    self::sendSelfServiceStart($chatId);
                    return true;
                }
                if($callback===self::CHOICE_MANAGER){
                    self::recordChoice($platform,$chatId,self::MANAGER,'customer_choice');
                    self::handoff($incoming,$platform,$chatId,'source_choice');
                    return true;
                }
                if(self::wasPrompted((int)$row['id']))return true;
                if($type!=='message'&&$type!=='contact'&&!$maxStart)return false;
                if(self::sendChoicePrompt($chatId)){
                    ConversationRecorder::eventByChat($platform,$chatId,'source_handling_prompted',['mode'=>self::ASK],'system');
                    return true;
                }
                return false;
            }

            if($mode===self::MANAGER){
                if($type==='callback')return false;
                self::recordChoice($platform,$chatId,self::MANAGER,'source_policy');
                self::handoff($incoming,$platform,$chatId,'source_policy');
                return true;
            }
            return false;
        }catch(Throwable $e){
            return false;
        }
    }

    private static function deferredStartCanResume(int $conversationId): bool
    {
        // The existing availability service remains the only work-window owner.
        if(!class_exists('ManagerAvailabilityService')||!ManagerAvailabilityService::withinWorkingHours())return false;
        $q=ConversationDb::connection()->prepare("SELECT payload_json FROM conversation_events WHERE conversation_id=? AND event_type='manager_request' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);
        $payload=json_decode((string)$q->fetchColumn(),true);
        return is_array($payload)&&array_key_exists('within_working_hours',$payload)
            &&$payload['within_working_hours']===false;
    }

    private static function handoff(array $incoming,string $platform,$chatId,string $reason): void
    {
        $user=(array)($incoming['user']??[]);
        $name=trim(trim((string)($user['first_name']??'')).' '.trim((string)($user['last_name']??'')));
        if($name==='')$name=trim((string)($user['username']??''));
        $handoff=ManagerHandoffDispatchService::dispatch($chatId,$platform,$name,false);
        ConversationRecorder::eventByChat($platform,$chatId,'manager_request',[
            'source'=>$reason,
            'manager_available'=>$handoff['manager_available'],
            'within_working_hours'=>$handoff['within_working_hours'],
        ],'system');
        ManagerHandoffDispatchService::applyQueueDecision($handoff,$platform,$chatId,[
            'source'=>$reason,
            'manager_available'=>$handoff['manager_available'],
            'within_working_hours'=>$handoff['within_working_hours'],
        ]);
    }

    private static function sendChoicePrompt($chatId): bool
    {
        return (bool)IntegrationRegistry::messenger()->sendWithButtons(
            $chatId,
            'Как вам удобнее продолжить?',
            [
                [['text'=>'👤 Позвать менеджера','callback_data'=>self::CHOICE_MANAGER]],
                [['text'=>'🔎 Подобрать тур самостоятельно','callback_data'=>self::CHOICE_AI]],
            ]
        );
    }

    private static function sendSelfServiceStart($chatId): bool
    {
        return (bool)IntegrationRegistry::messenger()->sendWithButtons(
            $chatId,
            'Хорошо. Напишите, куда и когда хотите поехать — помогу подобрать тур.',
            []
        );
    }

    private static function conversation(string $platform,$chatId): ?array
    {
        $q=ConversationDb::connection()->prepare("SELECT c.id,c.status,COALESCE(s.handling_mode,'ai') AS handling_mode FROM conversations c LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE c.project_key=? AND c.channel=? AND c.external_chat_id=? AND c.status<>? ORDER BY c.id DESC LIMIT 1");
        $q->execute([ProjectConfig::projectId(),$platform,(string)$chatId,'closed']);
        $row=$q->fetch();
        return$row?:null;
    }

    private static function latestChoice(int $conversationId): ?string
    {
        $q=ConversationDb::connection()->prepare("SELECT payload_json FROM conversation_events WHERE conversation_id=? AND event_type='source_handling_choice' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);$json=$q->fetchColumn();if(!$json)return null;
        $payload=json_decode((string)$json,true);$choice=(string)($payload['choice']??'');
        return in_array($choice,[self::AI,self::MANAGER],true)?$choice:null;
    }

    private static function wasPrompted(int $conversationId): bool
    {
        $q=ConversationDb::connection()->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='source_handling_prompted' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);return(bool)$q->fetchColumn();
    }

    private static function recordChoice(string $platform,$chatId,string $choice,string $reason): void
    {
        ConversationRecorder::eventByChat($platform,$chatId,'source_handling_choice',['choice'=>$choice,'reason'=>$reason],'system');
    }
}
