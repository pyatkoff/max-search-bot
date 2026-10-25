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
