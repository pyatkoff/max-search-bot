<?php
declare(strict_types=1);

/** Pure lexical screening of an already minimized archive projection; no event labels. */
final class ArchiveResearchFeatureExtractor
{
    public const PATTERN_SET_VERSION='ru_archive_lexical_v1';
    public const MAX_TEXT_BYTES=32768;
    private const SPECS=[
        'substantive_request'=>['customer','подбер\\pL{0,12}|подбор|интересу\\pL{0,12}|ищем\\s+тур|рассматрива\\pL{0,12}'],
        'offer_comparison'=>['customer','сравн\\pL{0,12}|чем\\s+отлича\\pL{0,12}|какой\\s+лучше|альтернатив\\pL{0,12}|другие\\s+вариант\\pL{0,8}|остановимся\\s+на'],
        'decision_timing'=>['customer','сегодня|завтра|до\\s+вечера|на\\s+следующ\\pL{0,5}\\s+год|в\\s+следующ\\pL{0,5}\\s+году|срок\\pL{0,6}\\s+решени\\pL{0,8}'],
        'ready_to_book'=>['customer','готов\\pL{0,4}\\s+(?:за)?брониров\\pL{0,12}|давайте\\s+(?:за)?брониру\\pL{0,12}|можно\\s+(?:за)?брониров\\pL{0,12}|(?<!\\pL)(?:за)?бронируем(?!\\pL)|(?<!\\pL)бер(?:[её]м|у)(?!\\pL)'],
        'ready_to_pay'=>['customer','готов\\pL{0,4}(?s:.{0,60})оплат\\pL{0,12}|могу\\s+оплат\\pL{0,12}|(?:сейчас|завтра)\\s+оплачу|внесу\\s+предоплат\\pL{0,8}|\\A\\s*(?:и\\s+)?оплатить\\s*[.!]?\\s*\\z'],
        'agency_preference'=>['customer','(?:только|именно)\\s+у\\s+вас|(?:заброниру|брониру|куп|бер[её]м)\\pL{0,12}(?s:.{0,40})у\\s+вас'],
        'office_visit'=>['customer','офис|приеха\\pL{0,10}\\s+к\\s+вам|прийти\\s+к\\s+вам|адрес\\s+(?:агентств|офис)'],
        'contract_documents'=>['customer','договор|паспорт|данные\\s+(?:для\\s+оформ|турист)|проверьте\\s+данные'],
        'payment_terms'=>['customer','(?:как|чем)\\s+оплат|форм\\pL{0,8}\\s+оплат|наличн\\pL{0,8}|картой|рассроч\\pL{0,10}|кредит'],
        'payment_link'=>['*','ссылк\\pL{0,8}(?s:.{0,80})оплат|оплат\\pL{0,10}(?s:.{0,80})ссылк'],
        'payment_self_report'=>['customer','(?<!\\pL)(?:оплатил[аи]?|оплатили|перев[её]л[аи]?|перевели|вн[её]с(?:ла|ли)?\\s+(?:предоплат\\pL{0,8}|остаток)|отправил[аи]?\\s+чек)(?!\\pL)'],
        'manager_payment_ack'=>['manager','(?:оплат|плат[её]ж|деньг)\\pL{0,10}(?s:(?!(?:будет|будут|должен|должна|должны|должно|может|могут)(?!\\pL)).){0,30}(?<!\\pL)(?:вижу|увидел(?:а|и)?)(?!\\pL)|(?:оплат|плат[её]ж|деньг)\\pL{0,10}(?s:(?!(?:будет|будут|должен|должна|должны|должно|может|могут)(?!\\pL)).){0,30}(?<!\\pL)(?:поступил(?:а|и|о)?|приш(?:л[аио]?|[её]л)|получил(?:а|и)?|получен(?:а|о|ы)?)(?!\\pL)|(?<!\\pL)(?:вижу|увидел(?:а|и)?)(?!\\pL)(?s:(?!(?:будет|будут|должен|должна|должны|должно|может|могут)(?!\\pL)).){0,30}(?:оплат|плат[её]ж|деньг)\\pL{0,10}|(?<!\\pL)(?:получил(?:а|и)?)(?!\\pL)(?s:(?!(?:будет|будут|должен|должна|должны|должно|может|могут)(?!\\pL)).){0,30}(?:оплат|плат[её]ж|деньг)\\pL{0,10}|(?<!\\pL)полученн(?:ый|ая|ое|ые|ую|ого|ой|ых)\\s+плат[её]ж\\pL{0,4}|(?<!\\pL)увидел(?:а|и)?(?!\\pL)'],
        'balance_payment'=>['*','остаток|остатка|оставш\\pL{0,10}\\s+(?:сумм|оплат)|доплат\\pL{0,8}(?s:.{0,30})50\\s*%'],
        'addon_payment'=>['*','доплат\\pL{0,8}\\s+за|(?:бизнес[- ]?класс|апгрейд|трансфер|страховк|экскурси)(?s:.{0,80})(?:оплат|доплат|перев)|(?:оплат|доплат|перев)(?s:.{0,80})(?:бизнес|апгрейд|трансфер|страховк|экскурси)'],
        'refund_or_cancellation'=>['*','возврат|аннулир\\pL{0,12}|отмен\\pL{0,10}\\s+(?:тур|брон)|расторг\\pL{0,10}\\s+договор'],
        'booking_confirmation'=>['*','брон\\pL{0,8}\\s+подтвержд\\pL{0,12}|подтвержд\\pL{0,12}\\s+брон|тур\\s+подтвержд\\pL{0,12}'],
        'document_issuance'=>['*','(?:выдал|выдали|направляю|отправил\\pL{0,4}|получил\\pL{0,4})(?s:.{0,40})(?:документ|ваучер)|(?:документ|ваучер)\\pL{0,10}(?s:.{0,30})(?:отправлен|выдан|готов)'],
        'deferral_or_dependency'=>['customer','соглас\\pL{0,10}(?s:.{0,40})отпуск|пока\\s+(?:воздерж|подума|не)|позже|в\\s+следующ\\pL{0,5}\\s+году|на\\s+следующ\\pL{0,5}\\s+год|жду\\s+зарплат|блокиров\\pL{0,10}(?s:.{0,30})(?:банк|кредит)|(?:банк|кредит)(?s:.{0,30})блокиров'],
        'purchased_elsewhere'=>['customer','(?:куп|заброниров|оплат)\\pL{0,10}(?s:.{0,50})(?:у\\s+друг|в\\s+другом\\s+агент|не\\s+у\\s+вас)'],
    ];

    public static function patternIds(): array {return array_keys(self::SPECS);}

    public static function extract(array $message): array
    {
        $role=$message['role']??null;
        $verified=($message['role_verified']??null)===true
            &&(($role==='customer'&&($message['direction']??null)==='inbound')
                ||($role==='manager'&&($message['direction']??null)==='outbound'));
        $time=$message['created_at']??null;
        $flags=['question'=>false,'negation'=>false,'conditional'=>false,'past'=>false,
            'forwarded'=>($message['forwarded']??null)===true,'unknown_forwarded'=>!is_bool($message['forwarded']??null),'unknown_role'=>!$verified,
            'unknown_time'=>!is_int($time)||$time<=0||$time>253402300799,
            'unknown_media'=>!is_array($message['media']??null),
            'unread_media'=>is_array($message['media']??null)&&count($message['media'])>0&&($message['media_contents_read']??null)!==true,
            'unread_links'=>($message['links_redacted']??null)===true||($message['selection_links_unread']??null)===true,
            'ambiguous_ack'=>false,'ambiguous_self_report'=>false];
        $out=['pattern_set_version'=>self::PATTERN_SET_VERSION,'semantics'=>'lexical_candidates_not_events_or_probabilities',
            'status'=>'scanned','actor_role'=>$verified?$role:'unknown','flags'=>$flags,'candidate_ids'=>[],
            'evidence_basis'=>'projected_minimized_text_utf8_bytes','evidence'=>[]];
        if(($message['read_status']??null)!=='available'){$out['status']='source_unavailable';return$out;}
        if(($message['text_minimized']??null)!==true){$out['status']='text_not_minimized';return$out;}
        $text=$message['text']??null;
        if(!is_string($text)){$out['status']='text_unavailable';return$out;}
        if(strlen($text)>self::MAX_TEXT_BYTES){$out['status']='text_too_large';return$out;}
        if(preg_match('//u',$text)!==1){$out['status']='invalid_utf8';return$out;}
        // Qualifiers cover the whole message; they do not negate or date every matched clause.
        $qualifiers=[
            'question'=>'[?？]|(?:\\A|[\\r\\n.!])\\s*(?:как|где|когда|сколько|можно\\s+ли)(?!\\pL)',
            'negation'=>'(?<!\\pL)(?:не|нет|без)(?!\\pL)|неоплач|неоплат|отказыва|воздерж',
            'conditional'=>'(?<!\\pL)(?:если|возможно|пока)(?!\\pL)|при\\s+услов|соглас\\pL{0,10}|зависит',
            'past'=>'в\\s+прошл|прошл\\pL{0,6}\\s+(?:год|раз|поездк)|раньше|предыдущ\\pL{0,8}\\s+тур|уже\\s+отдыхал',
        ];
        // Blank only fixed privacy markers. Equal byte lengths preserve original evidence offsets.
        $markers=['[ссылка на подборку не прочитана]','[ссылка не прочитана]',
            '[email скрыт]','[телефон скрыт]','[контакт скрыт]'];
        $analysisText=str_replace($markers,array_map(static fn($marker)=>str_repeat(' ',strlen($marker)),$markers),$text);
        foreach($qualifiers as$name=>$pattern)$out['flags'][$name]=preg_match('~'.$pattern.'~iu',$analysisText)===1;
        if(!$verified){$out['status']='unknown_role';return$out;}
        foreach(self::SPECS as$id=>[$expected,$pattern]){
            if($expected!=='*'&&$expected!==$role)continue;
            $matched=preg_match('~'.$pattern.'~iu',$analysisText,$match,PREG_OFFSET_CAPTURE);
            if($matched===false){$out['status']='pattern_unavailable';$out['candidate_ids']=[];$out['evidence']=[];return$out;}
            if($matched!==1)continue;
            $out['candidate_ids'][]=$id;
            $out['evidence'][]=['pattern_id'=>$id,'offset_bytes'=>$match[0][1],'length_bytes'=>strlen($match[0][0])];
        }
        $out['flags']['ambiguous_ack']=in_array('manager_payment_ack',$out['candidate_ids'],true)
            &&preg_match('~оплат|плат[её]ж|деньг|сумм~iu',$text)!==1;
        $out['flags']['ambiguous_self_report']=in_array('payment_self_report',$out['candidate_ids'],true)
            &&preg_match('~(?<!\\pL)перев[её]л(?:а|и)?(?!\\pL)~iu',$text)===1
            &&preg_match('~оплат|плат[её]ж|деньг|сумм|предоплат|остаток|чек|руб|₽|евро|доллар~iu',$text)!==1;
        return$out;
    }
}
