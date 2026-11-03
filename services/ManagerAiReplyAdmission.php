<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerAiDraftInputEvidence.php';

/** Read-only decision data, never a send permit or a semantic reviewer. No I/O. */
final class ManagerAiReplyAdmission
{
    private const FIELDS=['ok','should_draft','draft','next_action','reason','checks','evidence_message_ids','history_incomplete'];

    public static function proposalHash(array $proposal): ?string
    {
        if(array_is_list($proposal)||count($proposal)!==count(self::FIELDS)||array_diff(self::FIELDS,array_keys($proposal)))return null;
        try{$ordered=[];foreach(self::FIELDS as $field)$ordered[$field]=$proposal[$field];return hash('sha256',json_encode($ordered,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
        catch(Throwable $ignored){return null;}
    }

    /** Only the canonical generate boundary calls this with its captured input. */
    public static function manual(int $viewer,array $before,array $after,array $proposal,?ManagerAiDraftInputEvidence $input,string $instructionRevision,string $currentInstructionRevision,?string $draftId,int $now): array
    {
        return self::evaluate($viewer,$before,$after,$proposal,[
            'kind'=>'manual','conversation_id'=>$before['context']['conversation_id']??null,
            'manager_id'=>$before['owner_id']??null,'project_key'=>$before['project_key']??null,
            'context_revision'=>$before['revision']??null,'latest_message_id'=>$before['latest_message_id']??null,
            'instruction_revision'=>$instructionRevision,'current_instruction_revision'=>$currentInstructionRevision,
            'proposal_sha256'=>self::proposalHash($proposal),'proposal_persisted'=>$draftId!==null,
            'input_integrity'=>$input!==null,'input_context'=>$input?->context(),
            'input_provenance'=>$input!==null?'captured_manual_arguments':'unavailable',
            'external_model_input'=>'unknown',
        ],$now);
    }

    /** Row/archive are supplied by the canonical prepared owner, never HTTP JSON. */
    public static function prepared(int $viewer,array $before,array $after,array $proposal,?array $row,array $archive,string $instructionRevision,int $ttlCeiling,int $now): array
    {
        $input=$archive['input_evidence']??null;
        return self::evaluate($viewer,$before,$after,$proposal,[
            'conversation_id'=>isset($row['conversation_id'])?(int)$row['conversation_id']:null,'manager_id'=>isset($row['manager_id'])?(int)$row['manager_id']:null,
            'project_key'=>$row['project_key']??null,'context_revision'=>$row['context_revision']??null,
            'latest_message_id'=>isset($row['latest_message_id'])?(int)$row['latest_message_id']:null,
            'instruction_revision'=>$row['instruction_revision']??null,'proposal_sha256'=>$row['proposal_sha256']??null,
            'prepared_at'=>$row['prepared_at']??null,'expires_at'=>$row['expires_at']??null,
            'kind'=>'prepared','current_instruction_revision'=>$instructionRevision,'ttl_ceiling'=>$ttlCeiling,
            'proposal_persisted'=>$row!==null,'input_integrity'=>($archive['available']??false)===true,
            'input_context'=>is_array($input)?($input['context']??null):null,
            'input_provenance'=>($archive['available']??false)===true?'server_issued_context':'unavailable',
            // Issuance and envelope integrity do not establish external model use.
            'external_model_input'=>'unknown',
        ],$now);
    }

    public static function unavailable(): array
    {
        return self::evaluate(0,[],[],[],[],0);
    }

    private static function evaluate(int $viewer,array $before,array $after,array $proposal,array $source,int $now): array
    {
        $checks=[];$context=is_array($after['context']??null)?$after['context']:[];
        $cid=$context['conversation_id']??null;$latest=$after['latest_message_id']??null;
        $known=($before['ok']??false)===true&&($after['ok']??false)===true&&is_int($cid)&&$cid>0;
        $owner=$after['owner_id']??null;
        $checks['reply_owner']=self::check(!$known?'unknown':($viewer>0&&$owner===$viewer&&empty($after['supervisor_read'])?'pass':'fail'),'reply_owner_unconfirmed');
        $checks['scope']=self::check(!$known?'unknown':(($source['conversation_id']??null)===$cid&&($before['context']['conversation_id']??null)===$cid
            &&is_string($after['project_key']??null)&&$after['project_key']!==''&&($source['project_key']??null)===$after['project_key']
            &&($before['project_key']??null)===$after['project_key']&&(int)($source['manager_id']??-1)===$owner?'pass':'fail'),'owner_project_or_conversation_changed');
        $revisions=self::digest($before['revision']??null)&&self::digest($after['revision']??null)&&self::digest($source['context_revision']??null);
        $checks['context_revision']=self::check(!$revisions?'unknown':(hash_equals($before['revision'],$after['revision'])&&hash_equals($after['revision'],$source['context_revision'])
            &&($before['viewer_access_revision']??null)===($after['viewer_access_revision']??null)?'pass':'fail'),'context_changed_or_unavailable');
        $checks['latest_input']=self::check(!$known||!is_int($latest)?'unknown':(($source['latest_message_id']??null)===$latest
            &&($before['latest_message_id']??null)===$latest&&($context['as_of_message_id']??null)===$latest?'pass':'fail'),'latest_input_changed');
        $instruction=$source['instruction_revision']??null;$current=$source['current_instruction_revision']??null;
        $checks['instruction_revision']=self::check(!self::digest($instruction)||!self::digest($current)?'unknown':(hash_equals($instruction,$current)?'pass':'fail'),'instruction_changed_or_unavailable');
        $proposalHash=self::proposalHash($proposal);$saved=$source['proposal_sha256']??null;
        $checks['proposal_binding']=self::check($proposalHash===null||!self::digest($saved)?'unknown':(hash_equals($proposalHash,$saved)&&($source['proposal_persisted']??false)===true?'pass':'fail'),'proposal_not_bound');
        $input=$source['input_context']??null;
        $hasInput=($source['input_integrity']??false)===true&&is_array($input);
        $inputStatus=$hasInput?self::messageBindingStatus($context,$input):'unknown';
        $checks['input_envelope']=self::check($inputStatus,$hasInput&&$inputStatus==='unknown'?'input_normalization_unverified':'input_envelope_missing_or_changed');
        $prepared=self::utc($source['prepared_at']??null);$expires=self::utc($source['expires_at']??null);$ceiling=$source['ttl_ceiling']??null;
        $checks['ttl']=self::check(($source['kind']??null)!=='prepared'||$prepared===null||$expires===null||!is_int($ceiling)?'unknown':
            ($now>0&&$prepared<=$now&&$now<$expires&&$expires>$prepared&&$expires-$prepared<=$ceiling?'pass':'fail'),'ttl_missing_or_expired');
        $status='pass';foreach($checks as $check){if($check['status']==='fail'){$status='fail';break;}if($check['status']==='unknown')$status='unknown';}
        $bindingMessages=[];foreach($checks as $check)if($check['status']!=='pass')$bindingMessages[]=$check['message'];
        $bindingMessage=$status==='pass'?'Техническая привязка актуальна на момент проверки. Это не проверка смысла ответа.':implode(' ',array_unique($bindingMessages));
        $customerIds=[];foreach($context['messages']??[] as $message){if(is_array($message)&&($message['sender_type']??null)==='customer'&&($message['event_type']??null)==='message'&&is_int($message['id']??null))$customerIds[]=$message['id'];}
        $noContact=($proposal['next_action']??null)==='no_contact';
        $human=$after['has_manager_reply']??null;
        $pause=$human===true?'requires_explicit_resume':($human===false?'not_triggered':'unknown');
        $reasons=['semantic_question_review_required','semantic_completeness_not_proven','material_condition_authority_missing','autosend_disabled'];
        if($noContact)$reasons[]='no_contact_is_not_permission';
        if($pause==='requires_explicit_resume')$reasons[]='human_reply_requires_explicit_resume';
        if(($source['external_model_input']??'unknown')==='unknown')$reasons[]='external_model_input_unproven';
        foreach($checks as $check)if($check['status']!=='pass')$reasons[]=$check['reason'];
        return [
            'schema_version'=>1,'decision'=>'manager_review','actual_send_allowed'=>false,
            'criteria'=>[
                'last_customer_question'=>['status'=>'unknown','reason'=>'semantic_question_review_required',
                    'message'=>'Проверьте, отвечает ли текст на последний ввод и все ещё открытые вопросы и исправления клиента.',
                    'last_customer_input_id'=>$customerIds?end($customerIds):null,'customer_turn_ids_in_scope'=>$customerIds,
                    'open_question_resolution'=>'not_independently_established'],
                'context_currentness'=>['status'=>$status,'reason'=>$status==='pass'?'technical_binding_only':'context_binding_incomplete',
                    'message'=>$bindingMessage,
                    'technical_checks'=>$checks],
                'answer_completeness'=>['status'=>'unknown','reason'=>'semantic_completeness_not_proven',
                    'message'=>'Нет независимой проверки, что ответ охватывает все текущие вопросы. Ссылки на сообщения и пустой список проверок этого не доказывают.'],
                'material_conditions'=>['status'=>'unknown','reason'=>'material_condition_authority_missing',
                    'message'=>'Нужен источник каждого существенного условия именно текущего варианта. Старое предложение, фото и сведения об отеле не подтверждают текущую цену, наличие или бронирование.'],
            ],
            'input_provenance'=>$source['input_provenance']??'unavailable','external_model_input'=>'unknown',
            'send_pause'=>['state'=>$pause,'explicit_resume_proven'=>false,
                'message'=>$human===true?'После ответа менеджера нужен отдельный явный допуск к возобновлению автоответов. Новый ввод клиента и правила подготовки его не заменяют.':'Автоотправка выключена. Правила подготовки черновиков не разрешают отправку.'],
            'reasons'=>array_values(array_unique($reasons)),
        ];
    }

    private static function check(string $status,string $reason): array
    {
        $messages=[
            'reply_owner_unconfirmed'=>'Не подтверждён текущий владелец права на ответ.',
            'owner_project_or_conversation_changed'=>'Не совпадают диалог, проект или ответственный менеджер.',
            'context_changed_or_unavailable'=>'Переписка или связанный контекст изменились либо недоступны.',
            'latest_input_changed'=>'Последнее сообщение не совпадает с основанием ответа.',
            'instruction_changed_or_unavailable'=>'Версия инструкции изменилась или не подтверждена.',
            'proposal_not_bound'=>'Текст предложения не связан с сохранённым результатом.',
            'input_envelope_missing_or_changed'=>'Нет совпадающего сохранённого входного контекста.',
            'input_normalization_unverified'=>'В сохранённом вводе изменены отметки чтения подборки или фото. Соответствие обработанного ввода исходной переписке не подтверждено; это не доказывает, что переписка устарела.',
            'ttl_missing_or_expired'=>$status==='fail'?'Срок актуальности истёк или некорректен.':'Для этого ответа нет подтверждённого срока актуальности.',
        ];
        return ['status'=>$status,'reason'=>$status==='pass'?'binding_matched':$reason,
            'message'=>$status==='pass'?'Привязка совпадает.':($messages[$reason]??'Доказательство недоступно.')];
    }
    private static function digest(mixed $value): bool{return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;}
    private static function utc(mixed $value): ?int
    {
        if(!is_string($value))return null;$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $date!==false&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():null;
    }
    /** Known prepareInput enrichment is not a raw transcript edit. Without an
     * independent transformation binding it stays unknown, never technical pass.
     * No enrichment readers are called here; any other content change fails. */
    private static function messageBindingStatus(array $current,array $input): string
    {
        if(($current['conversation_id']??null)!==($input['conversation_id']??null)||($current['as_of_message_id']??null)!==($input['as_of_message_id']??null)
            ||($current['history_incomplete']??null)!==($input['history_incomplete']??null)||!is_array($current['messages']??null)||!is_array($input['messages']??null)
            ||!array_is_list($current['messages'])||!array_is_list($input['messages'])||count($current['messages'])!==count($input['messages']))return 'fail';
        $fields=array_flip(['id','sender_type','text','event_type','created_at','media_unread','selections_link_unread','text_truncated']);
        $enriched=false;
        foreach($current['messages']as$i=>$raw){
            $saved=$input['messages'][$i];if(!is_array($raw)||!is_array($saved))return 'fail';
            $expected=array_intersect_key($raw,$fields);$actual=array_intersect_key($saved,$fields);
            if(($raw['selections_link_unread']??null)===true&&($saved['selections_link_unread']??null)===false){
                if(!is_string($expected['text']??null))return 'fail';
                $expected['text']=str_replace('[ссылка на подборку не прочитана]','[подборка прочитана]',$expected['text']);
                $expected['selections_link_unread']=false;$enriched=true;
            }
            if(($raw['media_unread']??null)===true&&($saved['media_unread']??null)===false){$expected['media_unread']=false;$enriched=true;}
            if($expected!==$actual)return 'fail';
        }
        return $enriched?'unknown':'pass';
    }
}
