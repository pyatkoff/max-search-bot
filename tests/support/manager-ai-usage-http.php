<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/manager-ai-usage-fixture.php';

/** Fresh local database and byte-exact endpoints/assets; no application config. */
function managerAiUsageHttpFixture(string $directory,bool $visual=false): array
{
    $directory=realpath($directory)?:'';
    if($directory===''||!str_starts_with(basename($directory),'manager-ai-usage-')||count(array_diff(scandir($directory),['.','..']))!==0)throw new RuntimeException('fresh_local_fixture_directory_required');
    $root=dirname(__DIR__,2);$db=$directory.'/fixture.sqlite';$pdo=ManagerAiUsageFixture::create($db);
    // Keep HTTP fixtures relative to wall-clock time; service tests use a fixed clock.
    $shift=time()-(new DateTimeImmutable('2026-10-25 12:00:00',new DateTimeZone('UTC')))->getTimestamp();
    foreach(['messages'=>['created_at'],'manager_ai_drafts'=>['created_at','insert_requested_at']] as $table=>$fields){
        $key=$table==='messages'?'id':'draft_id';foreach($pdo->query('SELECT * FROM '.$table)->fetchAll() as $row){$values=[];foreach($fields as $field)$values[]=$row[$field]===null?null:gmdate('Y-m-d H:i:s',strtotime($row[$field].' UTC')+$shift);$values[]=$row[$key];$pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn($f)=>$f.'=?',$fields)).' WHERE '.$key.'=?')->execute($values);}
    }
    if($visual){
        $pdo->exec("UPDATE manager_customer_names SET working_name='Мария · отдых в Турции';UPDATE managers SET display_name='Светлана' WHERE id=7");
        for($n=1;$n<=47;$n++){
            $id=str_pad(dechex($n),32,'0',STR_PAD_LEFT);$q=$pdo->prepare('SELECT proposal_json FROM manager_ai_drafts WHERE draft_id=?');$q->execute([$id]);$proposal=json_decode($q->fetchColumn(),true);
            $proposal['draft']='Здравствуйте! Подберу варианты на двоих на 7 ночей с хорошим пляжем в вашем бюджете. Из Москвы планируете вылет? И подскажите, какие даты в начале июня вам подходят?';
            $proposal['reason']='Уже известны страна, состав, длительность и общий бюджет. Осталось уточнить вылет и подходящие даты.';
            $text=$n%2?$proposal['draft']:'Здравствуйте, Мария! Для отдыха на двоих на 7 ночей посмотрю отели с хорошим песчаным пляжем до 220 тысяч рублей. Из Москвы планируете вылет? Даты в начале июня можно немного сдвигать?';
            $meta=['project_key'=>'anytour','ai_draft'=>['draft_id'=>$id,'text_matches_proposal'=>$n%2===1,'sent_text_sha256'=>hash('sha256',$text)]];
            $pdo->prepare('UPDATE manager_ai_drafts SET proposal_json=? WHERE draft_id=?')->execute([json_encode($proposal,JSON_UNESCAPED_UNICODE),$id]);
            $pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=?')->execute([htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),json_encode($meta),10000+$n]);
            $pdo->prepare('UPDATE messages SET text=? WHERE id=?')->execute(['Здравствуйте! Хотим в Турцию в начале июня: двое взрослых, 7 ночей, до 220 тысяч за двоих. Нужен хороший пляж.',1000+$n]);
        }
        // The frozen provider window intentionally differs from the current
        // message above. The viewer must never rebuild it from today's dialogue.
        $context=['conversation_id'=>147,'as_of_message_id'=>1047,'history_incomplete'=>true,
            'media_unread'=>true,'selections_link_unread'=>true,
            'verified_company_facts'=>[['id'=>'office-hours','text'=>'Консультация по подборке доступна с 10:00 до 20:00.','source'=>'Утверждённая справка проекта']],
            'messages'=>[
                ['id'=>941,'sender_type'=>'system','text'=>'Начало доступного окна.','text_truncated'=>true],
                ['id'=>942,'sender_type'=>'manager','text'=>'В подборке два отеля: «Лазурный берег» и «Тихая бухта».'],
                ['id'=>943,'sender_type'=>'ai','text'=>'Нужно сравнить питание и расстояние до пляжа.'],
                ['id'=>1047,'sender_type'=>'customer','text'=>"Сравните именно эти два отеля из подборки.\n<img src=x onerror=\"window.usageInjected=1\">",'media_unread'=>true,'selections_link_unread'=>true]
            ],
            'selection_snapshots'=>[['state'=>'observed','observed_at'=>'2026-10-25T09:59:00Z','historical_send_snapshot'=>false,
                'source_messages'=>[['message_id'=>942,'sender_type'=>'manager','sent_at'=>'2026-10-25T09:58:00Z']],
                'offers_truncated'=>false,'offers'=>[
                    ['hotel'=>'Лазурный берег','meal'=>'Завтрак','price_text'=>'198 000 ₽','price_basis'=>null,'current_price_verified'=>false],
                    ['hotel'=>'Тихая бухта','meal'=>'Всё включено','price_text'=>'219 000 ₽','price_basis'=>null,'current_price_verified'=>false]
                ]]],
            'working_selection'=>['status'=>'available','version'=>3,'saved_at'=>'2026-10-25T09:59:30Z','price_basis'=>'whole_party',
                'offers'=>[['variant'=>1,'hotel'=>'Тихая бухта — рабочий вариант','destination'=>'Турция','departure'=>'Москва',
                    'date_from'=>'2027-06-05','nights'=>7,'adults'=>3,'child_ages'=>[],'room'=>'Стандарт','meal'=>'Всё включено',
                    'amount'=>'229000','currency'=>'RUB','photos_unread'=>true,
                    'rationale'=>'Заметка менеджера: проверить размещение троих взрослых.','constraints'=>'Условия требуют проверки до отправки.',
                    'source_condition_edits'=>['adults','amount'],'source_observation'=>['adults'=>2,'amount'=>'219000',
                        'reference'=>'https://example.invalid/'.str_repeat('original-source-',15)]]]],
            'hotel_reference'=>['status'=>'available','name'=>'Тихая бухта','facts'=>[['id'=>'beach','text'=>'Песчаный пляж в 300 метрах.','source'=>'Сохранённая справка отеля']]]];
        $evidence=ManagerAiDraftInputEvidence::capture($context,"Сравнивай только сохранённые варианты.\nНе обещай цену без проверки.\n<script>window.usageInjected=1</script>",
            [['type'=>'input_image','image_url'=>'data:image/png;base64,YWJj','detail'=>'high']]);
        if($evidence===null)throw new RuntimeException('visual_input_invalid');
        $pdo->prepare('UPDATE manager_ai_draft_inputs SET input_json=?,input_sha256=? WHERE draft_id=?')
            ->execute([$evidence->json(),$evidence->hash(),str_pad(dechex(47),32,'0',STR_PAD_LEFT)]);
        ManagerAiUsageFixture::captureInput($pdo,44);
        $pdo->prepare('UPDATE manager_ai_draft_inputs SET input_sha256=? WHERE draft_id=?')
            ->execute([str_repeat('0',64),str_pad(dechex(44),32,'0',STR_PAD_LEFT)]);
    }
    require_once $root.'/services/ManagerAiSentTextService.php';
    $original=$pdo->query('SELECT * FROM messages WHERE id=10047')->fetch();$meta=json_decode($original['metadata_json'],true);
    if(!ManagerAiSentTextService::record($pdo,10047,147,'manager','7',$original['text'],$meta,htmlspecialchars_decode($original['text'],ENT_QUOTES)))throw new RuntimeException('fixture_sent_text_invalid');
    if($visual){
        $original=$pdo->query('SELECT * FROM messages WHERE id=10044')->fetch();$meta=json_decode($original['metadata_json'],true);
        if(!ManagerAiSentTextService::record($pdo,10044,144,'manager','7',$original['text'],$meta,htmlspecialchars_decode($original['text'],ENT_QUOTES)))throw new RuntimeException('visual_initial_text_invalid');
        $meta['edited_at']=gmdate('Y-m-d H:i:s',strtotime($original['created_at'].' UTC')+60);
        $pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=10044')->execute(['Позднее изменение ответа менеджера.',json_encode($meta)]);
        $row=$pdo->query('SELECT * FROM messages WHERE id=10046')->fetch();$meta=json_decode($row['metadata_json'],true);$meta['edited_at']=gmdate('Y-m-d H:i:s',strtotime($row['created_at'].' UTC')+60);
        $pdo->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=10046')->execute([$row['text'].' Поздняя правка.',json_encode($meta)]);
    }
    $web=$directory.'/web';mkdir($web.'/manager/lib',0700,true);mkdir($web.'/manager/assets',0700,true);mkdir($web.'/manager2',0700,true);mkdir($directory.'/sessions',0700);
    foreach(['manager/ai-usage-api.php','manager/ai-usage.php','manager2/ai-usage.php','manager/analytics.php','manager2/analytics.php','manager/lib/ManagerHttp.php','manager/assets/manager-http-client.js','manager/assets/manager-time.js','manager/assets/manager-ai-usage.css','manager/assets/manager-ai-usage.js','manager/assets/manager-analytics.css','manager/assets/manager-analytics.js'] as $file){if(!copy($root.'/'.$file,$web.'/'.$file))throw new RuntimeException('fixture_copy_failed');}
    if(!symlink($root.'/services',$web.'/services'))throw new RuntimeException('fixture_service_link_failed');
    $config="<?php\nrequire_once ".var_export($root.'/services/ManagerAiUsageService.php',true).";\n";
    $config.='(new ReflectionProperty(ConversationDb::class,\'pdo\'))->setValue(null,new PDO('.var_export('sqlite:'.$db,true).",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));\n";
    $config.="ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);\n";file_put_contents($web.'/config.php',$config);
    session_save_path($directory.'/sessions');session_name('anytour_manager_panel');$sessions=[];
    foreach([1,2,3,4] as $actor){$sid=bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['manager_id'=>$actor,'csrf'=>'fixture-usage-csrf'];session_write_close();$sessions[(string)$actor]=$sid;}
    return ['root'=>$root,'webroot'=>$web,'database'=>$db,'sessions'=>$directory.'/sessions','session_ids'=>$sessions];
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){echo json_encode(managerAiUsageHttpFixture($argv[1]??'',($argv[2]??'')==='visual'),JSON_THROW_ON_ERROR);}
