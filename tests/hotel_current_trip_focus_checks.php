<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerTourSelectionPublicationDraft.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionImportDraft.php';

/** Reusable synthetic native source for profile service, HTTP and browser checks. */
function hotelCurrentTripFocusSeed(PDO $pdo,string $hotel='sunrise'): array
{
    $observation=['adapter'=>'tourvisor-snapshot-v1',
        'url'=>'https://pro.tourvisor.ru/search?ts_dosearch=1&s_form_mode=0&s_nights_from=7&s_nights_to=7&s_directflight=0&s_regular=1&x_hotel_codes=9374&s_j_date_from=19.12.2026&s_j_date_to=19.12.2026&s_adults=2&s_meal=7&s_flyfrom=1&s_country=1',
        'observed_at'=>'2026-10-30T09:00:00Z','quote_id'=>'tv-snapshot-1234567890',
        'keys'=>['hotel'=>'9374','meal'=>'7','departure'=>'1','country'=>'1','operator'=>'13'],
        'offer'=>['hotel'=>'SUNRISE ARABIAN BEACH RESORT','destination'=>'Египет, Шарм-эль-Шейх','departure'=>'Москва',
            'date_from'=>'2026-12-19','nights'=>7,'adults'=>2,'child_ages'=>[],'room'=>'Family Suite','meal'=>'AI - Все Включено','amount'=>'160000','currency'=>'RUB','stars'=>5],
        'raw'=>['hotel'=>'SUNRISE ARABIAN BEACH RESORT 5*','destination'=>'Египет, Шарм-эль-Шейх','date'=>'19 декабря',
            'nights'=>'7 ночей','room'=>'Family Suite','meal'=>'AI - Все Включено','party'=>'2 взрослых','price'=>'160 000 РУБ',
            'price_basis'=>'за номер','base_price'=>'144 000 РУБ','fuel_price'=>'16 000 РУБ','tariff'=>'Synthetic tariff',
            'flight_alternatives'=>'Synthetic flights','party_price_confirmation'=>'one_room_two_adults'],
        'photos'=>array_map(static fn(int $i):string=>'https://static.tourvisor.ru/hotel_pics/verybig/1/9374-'.$i.'.jpg',[1,2,3])];
    if($hotel==='jaz'){
        $observation['offer']['hotel']='JAZ MAKADI SARAYA RESORT (EX. IBEROTEL MAKADI SARAYA RESORT)';
        $observation['raw']['hotel']=$observation['offer']['hotel'].' 5*';$observation['keys']['hotel']='268';
        $observation['url']=str_replace('x_hotel_codes=9374','x_hotel_codes=268',$observation['url']);
        $observation['photos']=array_map(static fn(int $i):string=>'https://static.tourvisor.ru/hotel_pics/verybig/1/268-'.$i.'.jpg',[1,2,3]);
    }elseif($hotel!=='sunrise')throw new InvalidArgumentException('unknown_focus_hotel_fixture');
    $import=ManagerTourSelectionImportDraft::normalize($observation);
    if($import===null)throw new RuntimeException('FAIL focus source fixture');
    $offer=$import['offer'];$offer['import_source']=ManagerTourSelectionImportDraft::source($import);
    $selection=ManagerTourSelectionPublicationDraft::build(['title'=>'Synthetic hotel focus','intro'=>'','offers'=>[$offer]]);
    if(empty($selection['ok']))throw new RuntimeException('FAIL focus publication fixture');unset($selection['ok']);
    $id=str_repeat('a',32);$s=$selection['offers'][0]['snapshot'];$d=$s['quoted_details'];
    $public=['index'=>0,'hotel'=>$s['hotel'],'departure'=>$s['departure'],'date_from'=>$s['date_from'],'nights'=>$s['nights'],
        'adults'=>$s['adults'],'children'=>count($s['child_ages']),'room'=>$s['room'],'meal'=>$s['meal'],'amount'=>$s['amount'],'currency'=>$s['currency'],
        'rationale'=>$s['rationale'],'compromise'=>$s['compromise'],'destination'=>$d['destination'],'flights'=>$d['flights'],
        'inclusions'=>$d['inclusions'],'constraints'=>$d['constraints'],'observed_at'=>$d['observed_at'],'price_verified'=>false];
    $pdo->exec("CREATE TABLE manager_tour_selection_publications(publication_id TEXT PRIMARY KEY,conversation_id INTEGER,draft_version INTEGER,draft_hash TEXT,created_by_manager_id INTEGER,binding_json TEXT,selection_json TEXT,created_at TEXT);
INSERT INTO conversations SELECT 2,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,'PRIVATE donor chat' FROM conversations WHERE id=1;");
    $pdo->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?,?,?)')->execute([$id,2,1,str_repeat('b',64),1,
        json_encode(['project_key'=>'anytour','channel'=>'telegram','external_chat_id'=>'PRIVATE donor chat','external_user_id'=>'PRIVATE donor user']),
        json_encode($selection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'2026-10-30 09:59:00']);
    $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?,?)')->execute([999,1,'tour_web_inquiry','customer',
        json_encode(['schema'=>1,'entry_key'=>str_repeat('c',64),'publication_id'=>$id,'link_version'=>1,'variant'=>1,'public_offer'=>$public],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'2026-10-30 10:00:00']);
    requestProfileMessage($pdo,12,'customer','Обращение из веб-подборки: '.$observation['offer']['hotel']);
    $pdo->prepare('UPDATE messages SET metadata_json=? WHERE id=12')->execute([json_encode(['type'=>'tour_web_inquiry','native_event_type'=>'bot_started','publication_id'=>$id,'variant'=>1,'link_version'=>1])]);
    requestProfileMessage($pdo,13,'customer','На те же даты только на 3-х, 2 взрослых и один ребёнок 12 лет. В этом отеле есть детский бассейн, удобный пляж и что входит в питание?');
    return ['publication_id'=>$id,'message_id'=>12,'latest_message_id'=>13,'public_offer'=>$public];
}

/** Use the same curated fields on each explicit review, with no automatic party inference. */
function hotelFocusCommand(array $view,bool $ack=true,array $changes=[]): array
{
    $command=requestProfileCommand($view);
    if($view['profile']['version']>0){
        $command['boundary_message_id']=$view['profile']['boundary_message_id'];$command['label']=$view['profile']['label'];$command['fields']=[];
        foreach($view['profile']['fields'] as $key=>$field){$citation=$field['citation'];unset($field['citation']);
            $command['fields'][$key]=$field+['source_id'=>$citation['message_id']??0,'quote'=>$citation['quote']??''];}
    }else{
        $command['fields']['party']=['value'=>['adults'=>2,'children'=>1,'child_ages'=>[12]],'kind'=>'customer_statement','importance'=>'hard',
            'note'=>'Фактическая семья, не тариф исходной карточки','source_id'=>13,'quote'=>'2 взрослых и один ребёнок 12 лет'];
    }
    if($ack){$candidate=$view['hotel_focus_candidates'][0];$command['hotel_focus']=['message_id'=>$candidate['message_id'],'revision'=>$candidate['revision']];}
    return array_replace($command,$changes);
}

function hotelFocusPrepared(): array
{
    $snap=ManagerAiDraftContextService::snapshot(1,1);
    if(empty($snap['ok']))throw new RuntimeException('FAIL focus snapshot');
    $prepared=ManagerAiDraftService::prepareInput($snap['context'],$snap['company_knowledge'],null,$snap['reference_context']);
    return [$snap,$prepared['context'],$prepared];
}

function hotelFocusStoreMutation(PDO $pdo,callable $change): void
{
    $value=json_decode($pdo->query('SELECT profile_json FROM manager_request_profiles ORDER BY version DESC LIMIT 1')->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    $change($value);$json=json_encode($value,ManagerRequestProfile::FLAGS);
    $pdo->prepare('UPDATE manager_request_profiles SET profile_json=?,profile_sha256=? WHERE version=(SELECT MAX(version) FROM manager_request_profiles)')->execute([$json,hash('sha256',$json)]);
}

function hotelCurrentTripFocusChecks(): void
{
    $p=requestProfileFixture(null,'hotel_focus');$get=static fn()=>ManagerRequestProfileService::read(1,1);$view=$get();
    $candidate=$view['hotel_focus_candidates'][0]??[];
    profileCheck('ordinary clarification suggests one exact retained native offer without confirming it',count($view['hotel_focus_candidates'])===1&&$candidate['message_id']===12
        &&$candidate['hotel_ref']===['catalog'=>'anytour','id'=>1554]&&$view['profile']['hotel_focus']===null&&$view['party_suggestion']===null);
    [$before,$plain]=hotelFocusPrepared();
    profileCheck('ordinary clarification alone cannot revive native hotel facts',($plain['hotel_reference']['status']??'')==='held'&&$plain['hotel_reference']['reason']==='current_intent_unbound');
    $command=hotelFocusCommand($view);$saved=ManagerRequestProfileService::save(1,1,$command);$view=$get();
    profileCheck('explicit current-context acknowledgement saves a fresh exact hotel focus',$saved['ok']&&$view['profile']['status']==='fresh'&&$view['profile']['hotel_focus']['status']==='confirmed');
    profileCheck('actual family and original two-adult tariff remain separate',$view['profile']['fields']['party']['value']===['adults'=>2,'children'=>1,'child_ages'=>[12]]
        &&$view['profile']['hotel_focus']['public_offer']['adults']===2&&$view['profile']['hotel_focus']['public_offer']['children']===0
        &&$view['profile']['hotel_focus']['public_offer']['amount']==='160000'&&$view['profile']['hotel_focus']['public_offer']['date_from']==='2026-12-19'
        &&$view['profile']['hotel_focus']['public_offer']['price_verified']===false);
    [$snap,$input]=hotelFocusPrepared();$hotel=$input['hotel_reference']??[];$focus=$input['request_profile']['hotel_focus']??[];
    profileCheck('canonical draft input admits native approved facts only with matching current-trip pins',($hotel['status']??'')==='available'&&count($hotel['facts'])===6&&count($hotel['sources'])===3
        &&$hotel['selector_provenance']['message_id']===12&&$hotel['request_profile_focus']===hash('sha256',json_encode([$view['profile']['trip_id'],1,12,$candidate['revision']],ManagerRequestProfile::FLAGS))
        &&$focus['revision']===$candidate['revision']&&$snap['revision']!==$before['revision']);
    $instructions=ManagerAiDraftService::instructions($input);
    profileCheck('focus instructions answer the last question without asserting supplier terms',str_contains($instructions,'Сначала ответь на последний вопрос')&&str_contains($instructions,'существенный компромисс')
        &&str_contains($instructions,'Противоречие источников')&&$hotel['price_authority']==='none'&&!$hotel['room_tariff_equivalence_verified']&&!$hotel['future_operation_verified']);
    $evidence=ManagerAiDraftInputEvidence::capture($input,$instructions,[]);$frozen=$evidence?ManagerAiDraftInputEvidence::fromJson($evidence->json()):null;
    profileCheck('frozen manual and prepared input retains exact source versions facts dates and party',$frozen!==null&&$frozen->context()===$input
        &&$frozen->context()['hotel_reference']['record_hashes']===$hotel['record_hashes']&&$frozen->instructionHash()===hash('sha256',$instructions)
        &&$frozen->matches(1,13,array_column($input['messages'],'id')));
    profileCheck('same acknowledgement is idempotent and cannot change its offer',ManagerRequestProfileService::save(1,1,$command)['replayed']
        &&ManagerRequestProfileService::save(1,1,array_replace($command,['hotel_focus'=>null]))['error']==='operation_mismatch');
    $serialized=json_encode([$view,$input],ManagerRequestProfile::FLAGS);
    profileCheck('native cross-conversation publication exposes no donor binding',!str_contains($serialized,'PRIVATE donor')&&!str_contains($serialized,'external_user_id'));

    // The final provider gate must not strand historical facts without their profile.
    $gate=new ReflectionMethod(ManagerAiDraftService::class,'admitHotelReference');$without=$input;unset($without['hotel_reference'],$without['request_profile'],$without['hotel_reference_admission']);
    [$omitted,$receipt]=$gate->invoke(null,$without,$hotel);
    profileCheck('optional profile omission also withholds historical focus facts',!isset($omitted['hotel_reference'])&&$receipt['reason']==='hotel_focus_requires_review');
    foreach(['trip_id','version','message_id','revision'] as $key){$base=$input;unset($base['hotel_reference']);
        if(in_array($key,['trip_id','version'],true))$base['request_profile'][$key]=is_int($base['request_profile'][$key])?999:str_repeat('e',strlen($base['request_profile'][$key]));
        else $base['request_profile']['hotel_focus'][$key]=is_int($base['request_profile']['hotel_focus'][$key])?999:str_repeat('e',strlen($base['request_profile']['hotel_focus'][$key]));
        [$rejected,$receipt]=$gate->invoke(null,$base,$hotel);
        profileCheck('provider gate rejects a different focus '.$key,!isset($rejected['hotel_reference'])&&$receipt['reason']==='hotel_focus_requires_review');}

    requestProfileMessage($p,14,'customer','А детский бассейн подогревается в декабре?');$changed=$get();[, $after]=hotelFocusPrepared();
    profileCheck('new ordinary customer question requires explicit hotel re-confirmation',$changed['profile']['status']==='needs_review'&&$changed['profile']['hotel_focus']['status']==='needs_review'
        &&$changed['hotel_focus_candidates'][0]['revision']===$candidate['revision']&&$after['hotel_reference']['status']==='held');
    $oldClient=hotelFocusCommand($changed,false);profileCheck('legacy profile save does not silently re-confirm old hotel focus',ManagerRequestProfileService::save(1,1,$oldClient)['ok']
        &&$get()['profile']['status']==='fresh'&&$get()['profile']['hotel_focus']['status']==='needs_review');
    $review=hotelFocusCommand($get());profileCheck('explicit fresh review restores the same exact offer for the current question',ManagerRequestProfileService::save(1,1,$review)['ok']&&$get()['profile']['hotel_focus']['status']==='confirmed');
    $prior=$get();$changedCriteria=hotelFocusCommand($prior,false);$changedCriteria['fields']['hotel']['importance']='preferred';
    profileCheck('same-context criteria edit by old client cannot inherit hotel confirmation',ManagerRequestProfileService::save(1,1,$changedCriteria)['ok']&&$get()['profile']['hotel_focus']['status']==='needs_review');
    profileCheck('explicit null clears focus instead of reviving an older offer',ManagerRequestProfileService::save(1,1,hotelFocusCommand($get(),false,['hotel_focus'=>null]))['ok']&&$get()['profile']['hotel_focus']===null);
    profileCheck('stored generation evidence remains immutable after later review and messages',$frozen->json()===$evidence->json()&&$frozen->context()['as_of_message_id']===13&&$frozen->context()['request_profile']['version']===1);

    $p=requestProfileFixture(null,'hotel_focus');$view=$get();$command=hotelFocusCommand($view);
    foreach([['message_id'=>12,'revision'=>str_repeat('e',64)],['message_id'=>13,'revision'=>$candidate['revision']]] as $bad)
        profileCheck('stale or non-native acknowledgement cannot select hotel facts',ManagerRequestProfileService::save(1,1,array_replace($command,['hotel_focus'=>$bad]))['error']==='hotel_focus_changed');
    foreach([['message_id'=>'12','revision'=>$candidate['revision']],['message_id'=>12,'revision'=>$candidate['revision'],'hotel_id'=>1554]] as $bad)
        profileCheck('focus accepts only the exact typed acknowledgement command',ManagerRequestProfileService::save(1,1,array_replace($command,['hotel_focus'=>$bad]))['error']==='invalid_hotel_focus');
    $boundary=hotelFocusCommand($view,false,['boundary_message_id'=>12,'fields'=>[]]);
    profileCheck('native inquiry cannot become a plain-text trip boundary',ManagerRequestProfileService::save(1,1,$boundary)['error']==='boundary_required');
    $citation=hotelFocusCommand($view,false);$citation['fields']['hotel']['source_id']=12;$citation['fields']['hotel']['quote']='SUNRISE';
    profileCheck('native inquiry cannot masquerade as a plain-text criterion citation',ManagerRequestProfileService::save(1,1,$citation)['error']==='source_changed');
    ManagerRequestProfileService::save(1,1,$command);$oldTrip=$get()['profile']['trip_id'];
    $new=hotelFocusCommand($get(),false,['new_trip'=>true,'boundary_message_id'=>13,'fields'=>[]]);$withOld=$new;$withOld['hotel_focus']=$command['hotel_focus'];
    profileCheck('new trip cannot acknowledge an offer before its boundary',ManagerRequestProfileService::save(1,1,$withOld)['error']==='hotel_focus_changed');
    profileCheck('new trip clears inherited focus even for an older client',ManagerRequestProfileService::save(1,1,$new)['ok']&&$get()['profile']['trip_id']!==$oldTrip
        &&$get()['profile']['hotel_focus']===null&&$get()['hotel_focus_candidates']===[]);

    $p=requestProfileFixture(null,'hotel_focus');ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()));
    $p->exec("UPDATE messages SET text='Изменённое исходное обращение' WHERE id=12");$edited=$get();[, $after]=hotelFocusPrepared();
    profileCheck('edited native source message suspends exact previous confirmation',$edited['profile']['hotel_focus']['status']==='source_changed'&&$after['hotel_reference']['status']==='held');
    $p=requestProfileFixture(null,'hotel_focus');ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()));
    $selection=json_decode($p->query('SELECT selection_json FROM manager_tour_selection_publications')->fetchColumn(),true);$selection['title']='Changed publication revision';
    $p->prepare('UPDATE manager_tour_selection_publications SET selection_json=?')->execute([json_encode($selection,ManagerRequestProfile::FLAGS)]);
    profileCheck('a changed frozen publication revision suspends prior facts',$get()['profile']['hotel_focus']['status']==='source_changed');
    $p=requestProfileFixture(null,'hotel_focus');ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()));
    // Simulate a well-formed confirmation made against a former knowledge
    // revision without changing any shared approved research file.
    hotelFocusStoreMutation($p,static function(array &$v):void{
        $v['hotel_focus']['knowledge_revision']=str_repeat('d',64);$candidate=$v['hotel_focus'];
        unset($candidate['revision'],$candidate['confirmed_context_sha256'],$candidate['confirmed_profile_sha256']);
        $canonical=static function(mixed $value)use(&$canonical):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$item)$item=$canonical($item);return $value;};
        $v['hotel_focus']['revision']=hash('sha256',json_encode($canonical($candidate),ManagerRequestProfile::FLAGS));
    });
    $oldKnowledge=$get();[, $input]=hotelFocusPrepared();
    profileCheck('a prior knowledge revision requires a new explicit confirmation',$oldKnowledge['profile']['hotel_focus']['status']==='knowledge_changed'
        &&$input['hotel_reference']['status']==='held'&&$oldKnowledge['hotel_focus_candidates'][0]['knowledge_revision']!==$oldKnowledge['profile']['hotel_focus']['knowledge_revision']);
    $p=requestProfileFixture(null,'hotel_focus');ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()));
    hotelFocusStoreMutation($p,static function(array &$v):void{$v['hotel_focus']['public_offer']['amount']='1';});
    profileCheck('altered stored offer cannot pass with a recomputed outer profile digest',$get()['error']==='profile_unavailable');

    foreach([['attachments'=>[['type'=>'image']]],['linked_message'=>['type'=>'forward']],['type'=>'unknown_callback']] as $metadata){
        $p=requestProfileFixture(null,'hotel_focus');ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()));
        requestProfileMessage($p,14,'customer','Уточнение с непрочитанным вложением');$p->prepare('UPDATE messages SET metadata_json=? WHERE id=14')->execute([json_encode($metadata)]);
        $unread=$get();[, $input]=hotelFocusPrepared();
        profileCheck('later unread customer activity cannot be silently bound to old hotel facts',$unread['hotel_focus_candidates']===[]
            &&$unread['profile']['hotel_focus']['reason']==='later_activity_unavailable'&&$input['hotel_reference']['status']==='held');
    }
    $p=requestProfileFixture(null,'hotel_focus');ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()));
    for($id=20;$id<61;$id++)requestProfileMessage($p,$id,'manager','Синтетическая история '.$id);
    profileCheck('candidate discovery never scans backward outside the retained forty messages',$get()['hotel_focus_candidates']===[]&&$get()['profile']['hotel_focus']['reason']==='selector_not_retained');
    $p=requestProfileFixture(null,'hotel_focus');$p->exec("UPDATE conversations SET project_key='other' WHERE id=2");
    profileCheck('foreign-project publication cannot supply a focus candidate',$get()['hotel_focus_candidates']===[]);

    $p=requestProfileFixture(null,'hotel_focus');ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()));$previous=$get();
    requestProfileSecondHotelInquiry($p,15);$newer=$get();[, $input]=hotelFocusPrepared();
    profileCheck('a newer native offer cancels previous acknowledgement even for the same hotel room and dates',$newer['profile']['hotel_focus']['status']==='unavailable'
        &&$newer['profile']['hotel_focus']['reason']==='newer_hotel_inquiry'&&count($newer['hotel_focus_candidates'])===1
        &&$newer['hotel_focus_candidates'][0]['message_id']===15&&$newer['hotel_focus_candidates'][0]['public_offer']['amount']==='185000'
        &&$newer['hotel_focus_candidates'][0]['selected_room']===$previous['profile']['hotel_focus']['selected_room']
        &&$newer['hotel_focus_candidates'][0]['public_offer']['date_from']===$previous['profile']['hotel_focus']['public_offer']['date_from']);
    profileCheck('stale prior focus cannot override the newer independently valid native offer',$input['hotel_reference']['status']==='available'
        &&$input['hotel_reference']['selector_provenance']['message_id']===15&&!isset($input['hotel_reference']['request_profile_focus']));
    requestProfileMessage($p,16,'customer','А что включено в этот новый тариф?');$latest=$get();$stale=hotelFocusCommand($latest,false);
    $stale['hotel_focus']=['message_id'=>12,'revision'=>$previous['profile']['hotel_focus']['revision']];
    profileCheck('old exact offer acknowledgement cannot be replayed after another native selection',ManagerRequestProfileService::save(1,1,$stale)['error']==='hotel_focus_changed');
    profileCheck('new native offer needs its own explicit review after clarification',ManagerRequestProfileService::save(1,1,hotelFocusCommand($get()))['ok']
        &&$get()['profile']['hotel_focus']['message_id']===15&&$get()['profile']['hotel_focus']['status']==='confirmed');

    // A still-current native inquiry remains independently valid even if the optional profile is dropped.
    $p=requestProfileFixture(null,'hotel_focus');$p->exec('DELETE FROM messages WHERE id=13');$view=$get();$command=requestProfileCommand($view);
    $command['hotel_focus']=['message_id'=>$view['hotel_focus_candidates'][0]['message_id'],'revision'=>$view['hotel_focus_candidates'][0]['revision']];
    profileCheck('manager may explicitly associate an independently current native inquiry',ManagerRequestProfileService::save(1,1,$command)['ok']);
    [, $native]=hotelFocusPrepared();$nativeHotel=$native['hotel_reference'];$base=$native;unset($base['hotel_reference'],$base['request_profile']);[$admitted]=$gate->invoke(null,$base,$nativeHotel);
    profileCheck('valid latest native facts retain their original independent admission',!isset($nativeHotel['request_profile_focus'])&&($admitted['hotel_reference']['status']??'')==='available');

    // This accepted conflict-bearing group already sits close to the unchanged
    // per-hotel wire limit; association metadata must not evict its facts.
    $p=requestProfileFixture();hotelCurrentTripFocusSeed($p,'jaz');$view=$get();
    profileCheck('near-limit accepted hotel has its own exact current-trip candidate',($view['hotel_focus_candidates'][0]['hotel_ref']??null)===['catalog'=>'anytour','id'=>99]
        &&ManagerRequestProfileService::save(1,1,hotelFocusCommand($view))['ok']);
    [, $input,$prepared]=hotelFocusPrepared();$hotel=$input['hotel_reference'];$receipt=$prepared['hotel_reference_admission'];
    $bundle=json_decode(file_get_contents(dirname(__DIR__).'/knowledge/hotels/anytour-99-apartments.json'),true,32,JSON_THROW_ON_ERROR);
    profileCheck('near-limit confirmed hotel retains the complete accepted conflicting evidence under the same cap',($hotel['status']??null)==='available'
        &&$hotel['facts']===$bundle['facts']&&$hotel['sources']===$bundle['sources']&&$hotel['material_limits']===$bundle['material_limits']&&$hotel['record_hashes']===$bundle['record_hashes']
        &&$receipt['wire_increment_bytes']>16000&&$receipt['wire_increment_bytes']<=16384&&$receipt['hotel_utf8_bytes']<=16384
        &&$receipt['text_request_bytes']<=ManagerDraftClient::MAX_REQUEST_BYTES&&strlen($hotel['request_profile_focus'])===64);
    $evidence=ManagerAiDraftInputEvidence::capture($input,ManagerAiDraftService::instructions($input));$frozen=$evidence?ManagerAiDraftInputEvidence::fromJson($evidence->json()):null;
    profileCheck('near-limit source conflicts and association survive the immutable input envelope',$frozen!==null&&$frozen->context()['hotel_reference']===$hotel
        &&$hotel['price_authority']==='none'&&!$hotel['future_operation_verified']);
}
