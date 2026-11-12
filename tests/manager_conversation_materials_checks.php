<?php
// Real canonical service/API branches on the existing isolated history fixture.
function materialMeta(HistoryFixturePdo $pdo,int $id,array|string|null $meta,?string $text=null): void
{
    $pdo->prepare('UPDATE messages SET metadata_json=?'.($text!==null?',text=?':'').' WHERE id=?')
        ->execute($text!==null?[is_array($meta)?json_encode($meta):$meta,$text,$id]:[is_array($meta)?json_encode($meta):$meta,$id]);
}
function materials(array $data=[],int $manager=1): array{return apiSearch($data+['conversation_id'=>1],$manager,'message_materials');}
$pdo=fixture(650);
materialMeta($pdo,50,['attachments'=>[['type'=>'file','name'=>'Договор.pdf','provider'=>'telegram','file_id'=>'private-file-secret','url'=>'https://api.telegram.org/private-secret']]]);
materialMeta($pdo,610,null,'Веб-ссылка https://example.org/условия?x=1&y=2.');
materialMeta($pdo,600,['tour_selection'=>str_repeat('a',32),'tour_offer_id'=>42]);$pdo->exec("UPDATE messages SET direction='outbound',sender_type='manager',text='Отправленные условия &amp; цены' WHERE id=600");
materialMeta($pdo,500,['type'=>'callback'],'https://example.org/private-callback');
materialMeta($pdo,325,'broken-json','Обычный текст https://example.org/old');
$pdo->executed=[];$r=materials();$first=$r['body'];
check('materials lazily scan a bounded saved window and distinguish actual offers',$r['status']===200&&array_column($first['items'],'message_id')===[610,600]&&$first['scanned']===250&&$first['before_id']===401&&$first['has_more']&&$first['snapshot_id']===650&&$first['items'][1]['kinds']===['offers']);
check('materials decode recorded manager text without inventing a current offer',$first['items'][1]['snippet']==='Отправленные условия & цены'&&!isset($first['items'][1]['tour_offer_id']));
$r=materials(['snapshot_id'=>650,'before_id'=>401]);check('materials retain earlier continuation through malformed metadata',array_column($r['body']['items'],'message_id')===[325]&&$r['body']['before_id']===151&&$r['body']['has_more']);
$r=materials(['snapshot_id'=>650,'before_id'=>151]);$wire=json_encode($r['body']);
check('old file beyond loaded transcript is found only when saved history is exhausted',array_column($r['body']['items'],'message_id')===[50]&&!$r['body']['has_more']&&$r['body']['items'][0]['files']===[['type'=>'file','name'=>'Договор.pdf']]);
check('materials never expose provider secrets file identifiers or metadata URLs',!str_contains($wire,'private')&&!str_contains($wire,'file_id')&&!str_contains($wire,'telegram.org')&&!isset($r['body']['messages'],$r['body']['read_state']));
check('overview performs no writes acknowledgement or media hydration',$pdo->readWrites===0&&cursor($pdo)===0&&array_filter($pdo->executed,static fn($q)=>!str_starts_with($q['sql'],'SELECT '))===[]&&array_filter($pdo->executed,static fn($q)=>str_starts_with($q['sql'],'SELECT id,channel,direction,metadata_json'))===[]);
$pdo=fixture(501);$r=materials(['kind'=>'files']);check('empty partial overview never claims entire saved history is empty',$r['body']['items']===[]&&$r['body']['has_more']&&$r['body']['before_id']===252);
$pdo=fixture(31);$meta=['attachments'=>[['type'=>'file','name'=>'Файл.pdf','provider'=>'telegram','file_id'=>'secret']]];$pdo->prepare('UPDATE messages SET metadata_json=?')->execute([json_encode($meta)]);
$first=materials(['kind'=>'files'])['body'];check('dense material messages have a strict25message bound',array_column($first['items'],'message_id')===range(31,7)&&$first['scanned']===25&&$first['has_more']);
$pdo->prepare("INSERT INTO messages VALUES(99,1,'inbound','customer','Новое вложение','2026-11-12 20:00:00','telegram',?)")->execute([json_encode($meta)]);
$r=materials(['kind'=>'files','snapshot_id'=>$first['snapshot_id'],'before_id'=>$first['before_id']]);check('materials snapshot excludes later arrivals and duplicate rows',array_column($r['body']['items'],'message_id')===range(6,1)&&!$r['body']['has_more']&&$r['body']['snapshot_id']===31);
check('explicit overview refresh includes the new saved material',materials(['kind'=>'files'])['body']['items'][0]['message_id']===99);
$pdo=fixture(3);materialMeta($pdo,2,['attachments'=>[['type'=>'image','name'=>'Фото']]]);$pdo->exec('UPDATE messages SET conversation_id=2 WHERE id=2');$r=materials();check('material message IDs cannot cross the conversation fence',$r['status']===200&&$r['body']['items']===[]);
$pdo=fixture(3);$r=materials([],2);check('denied materials access reads no captured messages',$r['status']===404&&array_filter($pdo->executed,static fn($q)=>str_contains($q['sql'],'FROM messages'))===[]);
foreach(['project'=>"DELETE FROM manager_projects WHERE manager_id=1",'assignment'=>"UPDATE conversations SET manager_id=2 WHERE id=1",'actor'=>"UPDATE managers SET is_active=0 WHERE id=1"] as $name=>$sql){
    $pdo=fixture(3);materialMeta($pdo,2,$meta);$pdo->afterPage=static fn($db)=>$db->exec($sql);$r=materials();check('material projection is discarded after '.$name.' changes during read',$r['status']===404&&!isset($r['body']['items'])&&$pdo->readWrites===0);
}
foreach([['kind'=>'other'],['kind'=>[]],['kind'=>true],['before_id'=>-1],['snapshot_id'=>'01'],['before_id'=>10],['before_id'=>11,'snapshot_id'=>10],['conversation_id'=>true],['conversation_id'=>'999999999999999999999999999']] as $invalid){$pdo=fixture(3);$r=materials($invalid);check('invalid materials input rejected before SQL '.json_encode($invalid),$r['status']===422&&$pdo->executed===[]);}
$row=['id'=>1,'sender_type'=>'customer','direction'=>'inbound','text'=>'https://user:secret@example.org/private javascript:alert(1) https://example.org/safe.','created_at'=>'2026-11-12 10:30:00','metadata_json'=>null];$p=ManagerMessageMaterials::project($row);
check('only literal web links without URL credentials become material links',$p['links']===['https://example.org/safe']&&$p['created_at']===$row['created_at']);
$row['text']='См. https://example.org/Trip_(2026). И http://[::1]/docs';$p=ManagerMessageMaterials::project($row);
check('literal links preserve balanced URL parentheses and IPv6 hosts',$p['links']===['https://example.org/Trip_(2026)','http://[::1]/docs']);
foreach(['start','bot_started','callback'] as $type){$row['metadata_json']=json_encode(['type'=>$type]);check('recorded '.$type.' actions do not become conversation materials',ManagerMessageMaterials::project($row)===null);}
$attachments=array_fill(0,12,['type'=>'file','name'=>'<img src=x> синтетический.pdf','provider'=>'telegram','file_id'=>'secret']);$row['metadata_json']=json_encode(['attachments'=>$attachments]);$p=ManagerMessageMaterials::project($row,'files');
check('large message keeps an honest file count and bounded plain filenames',$p['file_count']===12&&count($p['files'])===8&&$p['files'][0]['name']==='<img src=x> синтетический.pdf');
$row['metadata_json']=json_encode(['attachments'=>[['type'=>'file','name'=>['invalid'=>'shape']]]]);$p=ManagerMessageMaterials::project($row,'files');check('malformed historical filename remains a safe unnamed file',$p['files']===[['type'=>'file','name'=>'']]);
$row['metadata_json']=json_encode(['tour_selection'=>str_repeat('b',32),'tour_offer_id'=>77]);check('customer text or metadata never invents a manager sent offer',ManagerMessageMaterials::project($row,'offers')===null);
