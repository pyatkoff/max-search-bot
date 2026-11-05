<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationRecorder.php';
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/ManagerHandoffDispatchService.php';
require_once __DIR__ . '/SourceQualificationPrompt.php';

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
    private const QUALIFICATION_REASON='source_needs_first';
    private static ?array $qualificationTurn=null;

    public static function handle(array $incoming): bool
    {
        // Only the current authenticated input may finish its own qualification.
        // This is request-local context; the existing choice event owns persistence.
        self::$qualificationTurn=null;
        try {
            if(!ConversationDb::isConfigured())return false;
            $platform=strtolower(trim((string)($incoming['platform']??'')));
            $chatId=$incoming['user']['chat_id']??0;
            $type=(string)($incoming['type']??'');
            if($platform===''||!$chatId)return false;
            $maxStart=$platform==='max'&&$type==='bot_started';
            $postStart=$platform==='telegram'&&$type==='message'
                &&($incoming['entry_kind']??'')==='channel_post'
                &&preg_match('~\A/start(?:@[A-Za-z0-9_]+)?\s+p_[A-Za-z0-9_-]{1,62}\s*\z~u',trim((string)($incoming['text']??'')));
            $explicitStart=$maxStart||$postStart;

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
            if(in_array($platform,['max','telegram'],true)&&!$explicitStart&&in_array((string)$row['status'],['manager','waiting_manager'],true))return false;

            $savedChoice=self::latestChoiceData((int)$row['id']);
            $choice=$savedChoice['choice']??null;

            // A configured publication source identifies the bounded hotel/feed
            // entry policy. Other manager/ask sources keep their existing route.
            if(self::qualificationSource($row,$platform)){
                if((int)($row['manager_id']??0)>0||in_array((string)$row['status'],['manager','waiting_manager'],true)){
                    return $explicitStart;
                }
                $sourceStart=$explicitStart||($type==='message'
                    &&(string)($incoming['source_key']??'')===(string)$row['source_key']
                    &&preg_match('~\A/start(?:@[A-Za-z0-9_]+)?(?:\s+\S+)?\s*\z~u',trim((string)($incoming['text']??''))));
                $qualifying=self::isQualificationChoice($savedChoice,$row);
                // Free-text manager intent keeps its canonical V2 interpretation;
                // this exact button is an explicit choice and needs no questions.
                if($callback===self::CHOICE_MANAGER){
                    if(self::handoff($incoming,$platform,$chatId,'customer_choice',false)){
                        self::recordChoice($platform,$chatId,self::MANAGER,'customer_choice');
                    }
                    return true;
                }
                if($callback===self::CHOICE_AI&&($qualifying||$mode===self::ASK)){
                    self::recordChoice($platform,$chatId,self::AI,'customer_choice');
                    self::sendSelfServiceStart($chatId);
                    return true;
                }
                if($sourceStart||$qualifying||$choice===null){
                    if(!$qualifying){
                        self::recordChoice($platform,$chatId,self::AI,self::QUALIFICATION_REASON,['source_id'=>(int)$row['source_id']]);
                    }
                    if($sourceStart){
                        // Reentry keeps current needs and does not manufacture a
                        // tourist message or complete an older trip by itself.
                        $postContext=self::greetingContext($incoming,$row,$platform);
                        $differentPost=!empty($postContext['publication_id'])
                            &&(int)$postContext['publication_id']!==(int)($savedChoice['publication_id']??0);
                        if(!$qualifying||empty($savedChoice['prompted'])||$differentPost){
                            require_once __DIR__.'/DialogueView.php';
                            if(DialogueView::sourceQualificationStart($chatId,self::missing($chatId),true,(string)($postContext['hotel_name']??''))){
                                self::recordChoice($platform,$chatId,self::AI,self::QUALIFICATION_REASON,
                                    ['source_id'=>(int)$row['source_id'],'prompted'=>true,'publication_id'=>(int)($postContext['publication_id']??0)]);
                            }
                        }
                        return true;
                    }
                    self::$qualificationTurn=[
                        'incoming'=>$incoming,'conversation_id'=>(int)$row['id'],
                        'project_key'=>ProjectConfig::projectId(),'source_id'=>(int)$row['source_id'],
                        'before'=>self::needContext($chatId),
                    ];
                    // A first real message may arrive without a separate start.
                    // It must reach the canonical collector rather than be lost
                    // behind an introductory question or an old check surface.
                    if(!$qualifying&&$type==='message'&&class_exists('MaxSearchApi')){
                        MaxSearchApi::setStatus($chatId,MaxSearchApi::$statusAi);
                    }
                    return false;
                }
            }
            // A new explicit MAX source entry must not inherit an obsolete AI choice.
            if($choice===self::AI&&!$explicitStart)return false;
            if($choice===self::MANAGER){
                // A fresh explicit manager entry is a new request even if an older
                // attempt failed before recording its presentation outcome.
                if((string)$row['status']==='ai'
                    &&(($explicitStart&&$mode===self::MANAGER)||$callback===self::CHOICE_MANAGER)){
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

    private static function handoff(array $incoming,string $platform,$chatId,string $reason,bool $sourceEntry=true): bool
    {
        $user=(array)($incoming['user']??[]);
        $name=trim(trim((string)($user['first_name']??'')).' '.trim((string)($user['last_name']??'')));
        if($name==='')$name=trim((string)($user['username']??''));
        $handoff=ManagerHandoffDispatchService::dispatch($chatId,$platform,$name,false,null,$sourceEntry);
        ConversationRecorder::eventByChat($platform,$chatId,'manager_request',[
            'source'=>$reason,
            'manager_available'=>$handoff['manager_available'],
            'within_working_hours'=>$handoff['within_working_hours'],
        ],'system');
        return (bool)ManagerHandoffDispatchService::applyQueueDecision($handoff,$platform,$chatId,[
            'source'=>$reason,
            'manager_available'=>$handoff['manager_available'],
            'within_working_hours'=>$handoff['within_working_hours'],
        ]);
    }

    public static function qualifyingTurn($chatId): bool
    {
        return self::$qualificationTurn!==null
            &&(string)(self::$qualificationTurn['incoming']['user']['chat_id']??'')===(string)$chatId
            &&self::$qualificationTurn['project_key']===ProjectConfig::projectId();
    }

    /** Used before model defaults: only already persisted essential needs count. */
    public static function qualificationNeedsChanged($chatId): bool
    {
        if(!self::qualifyingTurn($chatId))return false;
        $core=array_fill_keys(['city','country','adults','children','child_ages','nights','date'],true);
        return array_intersect_key(self::needContext($chatId),$core)!==array_intersect_key(self::$qualificationTurn['before'],$core);
    }

    /** null: ordinary flow; false: qualification still needs a substantive turn. */
    public static function completeQualification($chatId,array $questionOptions=[]): ?bool
    {
        if(!self::qualifyingTurn($chatId))return null;
        $turn=self::$qualificationTurn;
        $incoming=$turn['incoming'];
        if(($incoming['type']??'')!=='message'||trim((string)($incoming['text']??''))===''
            ||preg_match('~\A/start(?:\s|@|\z)~u',trim((string)($incoming['text']??''))))return false;
        $platform=(string)$incoming['platform'];
        try{
            $row=self::conversation($platform,$chatId);
            if(!$row||(int)$row['id']!==$turn['conversation_id'])return null;
            if((int)($row['manager_id']??0)>0||in_array((string)$row['status'],['manager','waiting_manager'],true))return true;
            if(!self::qualificationSource($row,$platform)
                ||!self::isQualificationChoice(self::latestChoiceData((int)$row['id']),$row))return null;
            if(self::missing($chatId)!==[])return false;
            // Merely opening a source or saying hello cannot adopt a previous
            // complete trip. Successful canonical writes or changed needs are
            // the evidence that this real turn progressed the current request.
            $progress=array_key_exists('source_need_applied',$questionOptions)
                ?!empty($questionOptions['source_need_applied'])
                :self::qualificationNeedsChanged($chatId);
            if(!$progress)return false;
            if(!self::handoff($incoming,$platform,$chatId,'source_needs_complete',false))return false;
            self::recordChoice($platform,$chatId,self::MANAGER,'source_needs_complete');
            return true;
        }catch(Throwable $ignored){return false;}
    }

    /** Bounded immutable post text for this current source, never a live offer. */
    public static function sourcePostContext($chatId): array
    {
        if(!self::qualifyingTurn($chatId))return [];
        if(array_key_exists('post_context',self::$qualificationTurn))return self::$qualificationTurn['post_context'];
        self::$qualificationTurn['post_context']=[];
        try{
            require_once __DIR__.'/ChannelPostEntryService.php';
            $platform=(string)self::$qualificationTurn['incoming']['platform'];
            $row=self::conversation($platform,$chatId);
            $identity=ChannelPostRuntime::identity($platform);
            if(!$identity||!$row||(int)$row['id']!==self::$qualificationTurn['conversation_id']
                ||(int)$row['source_id']!==self::$qualificationTurn['source_id'])return [];
            $registry=new ChannelPostRegistryService();
            $post=$registry->managerContext((int)$row['id'],ProjectConfig::projectId(),1)['current']??null;
            if(!$post||!empty($post['unavailable'])||($post['source_key']??'')!==$row['source_key'])return [];
            $version=$registry->versionForEntry((int)$row['id'],ProjectConfig::projectId(),(int)$post['entry_id']);
            if(!$version||$version['platform']!==$platform||$version['bot_key']!==$identity['bot_key'])return [];
            $out=[];
            foreach(['entry_id','publication_id','version_id'] as $key)$out[$key]=(int)$post[$key];
            foreach(['platform'=>20,'source_key'=>96,'title'=>500,'text'=>12000,'published_at'=>40,'post_url'=>2048] as $key=>$limit){
                $value=(string)($post[$key]??'');
                if(function_exists('mb_substr'))$out[$key]=mb_substr($value,0,$limit,'UTF-8');
                else{$matched=[];preg_match('/\A.{0,'.$limit.'}/us',$value,$matched);$out[$key]=(string)($matched[0]??'');}
            }
            $out['historical_publication']=true;
            return self::$qualificationTurn['post_context']=$out;
        }catch(Throwable $ignored){return [];}
    }

    /** Request-only data attached by the authenticated token-resolution owners. */
    private static function greetingContext(array $incoming,array $row,string $platform): array
    {
        $post=$incoming['source_post_context']??null;
        if(($incoming['entry_kind']??'')!=='channel_post'||!is_array($post)
            ||($post['platform']??'')!==$platform
            ||(int)($post['source_id']??0)!==(int)$row['source_id']
            ||($post['source_key']??'')!==$row['source_key']
            ||(int)($post['publication_id']??0)<1)return [];
        return $post;
    }

    private static function qualificationSource(array $row,string $platform): bool
    {
        if(ProjectConfig::projectId()!=='anytour'||!in_array($platform,['max','telegram'],true))return false;
        try{
            if(!is_array(ProjectConfig::get('channel_posts.'.$platform)))return false;
            require_once __DIR__.'/ChannelPostRuntime.php';
            $identity=ChannelPostRuntime::identity($platform);
            if(!$identity)return false;
            $q=ConversationDb::connection()->prepare("SELECT 1 FROM post_channels pc JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pc.source_id AND s.project_id=pc.project_id WHERE p.project_key=? AND p.is_active=1 AND pc.source_id=? AND pc.platform=? AND pc.bot_key=? AND pc.is_enabled=1 AND s.channel=? AND s.is_active=1 AND s.handling_mode IN ('manager','ask') LIMIT 1");
            $q->execute([ProjectConfig::projectId(),(int)($row['source_id']??0),$platform,$identity['bot_key'],$platform]);
            return(bool)$q->fetchColumn();
        }catch(Throwable $ignored){return false;}
    }

    private static function isQualificationChoice(array $choice,array $row): bool
    {
        return($choice['choice']??null)===self::AI&&($choice['reason']??'')===self::QUALIFICATION_REASON
            &&(int)($choice['source_id']??0)===(int)($row['source_id']??0);
    }

    private static function missing($chatId): array
    {
        return class_exists('MaxSearchApi')&&method_exists('MaxSearchApi','getAiMissingFields')
            ?(array)MaxSearchApi::getAiMissingFields($chatId):[];
    }

    private static function needContext($chatId): array
    {
        return class_exists('MaxSearchApi')&&method_exists('MaxSearchApi','getAiSearchContext')
            ?(array)MaxSearchApi::getAiSearchContext($chatId):[];
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
        $q=ConversationDb::connection()->prepare("SELECT c.id,c.status,c.manager_id,c.source_id,s.source_key,COALESCE(s.handling_mode,'ai') AS handling_mode FROM conversations c LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE c.project_key=? AND c.channel=? AND c.external_chat_id=? AND c.status<>? ORDER BY c.id DESC LIMIT 1");
        $q->execute([ProjectConfig::projectId(),$platform,(string)$chatId,'closed']);
        $row=$q->fetch();
        return$row?:null;
    }

    private static function latestChoiceData(int $conversationId): array
    {
        $q=ConversationDb::connection()->prepare("SELECT payload_json FROM conversation_events WHERE conversation_id=? AND event_type='source_handling_choice' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);$json=$q->fetchColumn();if(!$json)return [];
        $payload=json_decode((string)$json,true);$choice=(string)($payload['choice']??'');
        return in_array($choice,[self::AI,self::MANAGER],true)&&is_array($payload)?$payload:[];
    }

    private static function wasPrompted(int $conversationId): bool
    {
        $q=ConversationDb::connection()->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='source_handling_prompted' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);return(bool)$q->fetchColumn();
    }

    private static function recordChoice(string $platform,$chatId,string $choice,string $reason,array $metadata=[]): void
    {
        ConversationRecorder::eventByChat($platform,$chatId,'source_handling_choice',array_merge($metadata,['choice'=>$choice,'reason'=>$reason]),'system');
    }
}
