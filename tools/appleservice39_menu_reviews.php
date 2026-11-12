<?php
declare(strict_types=1);ini_set('display_errors','0');
// One existing pinned message, one durable edit in the existing publication Store.
function as39PinCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function as39PinJson(array $a):string{return json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function as39PinOut(array $a):void{echo as39PinJson($a).PHP_EOL;}
function as39PinInvoke(object $o,string $name,...$args){return(new ReflectionMethod($o,$name))->invoke($o,...$args);}
function as39PinGit(array $args):string{
 $p=proc_open(array_merge(['git','-C','/var/www/anytoour/data/www/app.anytoour.ru'],$args),[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);as39PinCheck(is_resource($p),'checkout_unavailable');fclose($pipes[0]);$s=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);as39PinCheck(proc_close($p)===0,'checkout_unavailable');return trim($s);
}
function as39PinPlan(array $urls):array{
 $plain='';$html='';$markup=[];
 $add=static function(string $text,bool $bold=false,?string $url=null,string $sep="\n\n")use(&$plain,&$html,&$markup):void{
  as39PinCheck(preg_match('/[\x{10000}-\x{10ffff}]/u',$text)===0,'unsupported_character');
  if($plain!==''){$plain.=$sep;$html.=$sep;}$from=mb_strlen($plain,'UTF-8');$plain.=$text;$escaped=htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  if($bold){$html.='<b>'.$escaped.'</b>';$markup[]=['type'=>'strong','from'=>$from,'length'=>mb_strlen($text,'UTF-8')];}
  elseif($url!==null){$html.='<a href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.$escaped.'</a>';$markup[]=['type'=>'link','from'=>$from,'length'=>mb_strlen($text,'UTF-8'),'url'=>$url];}else $html.=$escaped;
 };
 $add('Ремонт iPhone в Калининграде',true);
 $add('Экран, аккумулятор, зарядка, камеры и звук — поможем подобрать ремонт для вашей модели.');
 $add('Стоимость и срок согласуем до начала работ. Гарантия на ремонт — 3 месяца.');
 $add("Калининград, Ленинский проспект, 18\n4 этаж, офис 404");
 $add('Каталог и отзывы',true);
 $add('Все модели iPhone',false,$urls['models-index'],"\n");
 $add('Все услуги и варианты ремонта',false,$urls['services-index'],"\n");
 $add('Отзывы клиентов на Яндекс Картах',false,'https://yandex.ru/maps/org/apple_service39/1731550627/reviews/',"\n");
 $add('Нажмите «Написать менеджеру» — уточним ремонт и удобное время.');
 return ['body'=>['text'=>$html,'format'=>'html'],'native_text'=>$plain,'native_markup'=>$markup];
}
$store=null;$operation=null;
try{
 $root='/var/www/anytoour/data/www/app.anytoour.ru';$sha='6e1c61915d0afc35ec8198d59021fe5a6bddadb6';$channel='-80917954947727';$mid='mid.ffffb667d20a157101a1bd6d233963da';
 as39PinCheck(PHP_SAPI==='cli'&&realpath($root)===$root,'fixed_cli_target_required');
 $checkout=static fn():bool=>as39PinGit(['rev-parse','HEAD'])===$sha&&as39PinGit(['status','--porcelain','--untracked-files=no'])==='';as39PinCheck($checkout(),'checkout_changed');
 $private='/var/www/anytoour/data/config/max-search.php';as39PinCheck(is_file($private)&&!is_link($private),'private_connection_unavailable');$configHash=hash_file('sha256',$private);
 $safe=static fn():bool=>$checkout()&&is_file($private)&&!is_link($private)&&hash_file('sha256',$private)===$configHash;
 require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/AppleService39PilotPublisher.php';
 $pdo=ConversationDb::connection();as39PinCheck(!$pdo->inTransaction(),'autocommit_required');$store=new AppleService39PilotPublicationStore($pdo);
 $rows=$store->rows();as39PinCheck(count($rows)===193&&count(array_filter($rows,static fn($r)=>$r['state']!=='verified'))===0,'accepted_rows_changed');
 $manifest=AppleService39PilotManifest::installed();$publisher=new AppleService39PilotPublisher($pdo,$manifest,$channel,$safe,null,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));
 $store->assertBinding(as39PinInvoke($publisher,'preflight'));as39PinCheck(as39PinInvoke($publisher,'pinnedMid')===$mid,'pin_changed');
 $connection=ProjectMaxConnection::resolve('appleservice39');as39PinCheck($connection!==null,'connection_unavailable');$transport=new AppleService39ChannelTransport($connection,$channel);
 $old=$store->operation('menu','coverage_menu');$parent=$store->operation('menu','search_v1_edit');
 as39PinCheck($old&&$parent&&$old['state']==='verified'&&$old['external_message_id']===$mid&&$old['request']===$store->coverageRequest('menu','coverage_menu'),'menu_source_changed');
 $get=static function(string $id)use($transport):array{$r=$transport->message($id);as39PinCheck(AppleService39ChannelTransport::accepted($r),'native_read_failed');$m=$r['data']['message']??$r['data'];as39PinCheck(is_array($m),'native_read_failed');return $m;};
 $before=$get($mid);$oldBody=$parent['request']['body'];$oldBody['text']=$old['request']['body']['text'];$oldBody['format']='html';
 $url=as39PinInvoke($publisher,'verifyMessageContent',$before,$mid,$oldBody,$old['request']);$imageBefore=as39PinInvoke($publisher,'imagePayload',$before);$photo=(string)($imageBefore['photo_id']??'');
 as39PinCheck($photo!==''&&$photo===$old['outcome']['image_receipt']['photo_id'],'menu_photo_changed');
 $buttons=[];foreach($before['body']['attachments']as$a)if($a['type']==='inline_keyboard')foreach($a['payload']['buttons']as$line)foreach($line as$button)$buttons[]=$button;
 as39PinCheck(count($buttons)===1&&($buttons[0]['type']??'')==='link'&&str_starts_with($buttons[0]['url']??'','https://max.ru/id390521129062_bot?start='),'menu_button_changed');
 $urls=[];$audit=[];
 foreach(['model-iphone-8','model-iphone-15-pro-max','repair-iphone-x-battery-replacement','service-earpiece-replacement','models-index','services-index']as$id){
  $r=$store->operation($id,'create');as39PinCheck($r&&$r['state']==='verified'&&is_string($r['public_url']),'sample_publication_unavailable');$m=$get($r['external_message_id']);
  as39PinCheck(($m['body']['mid']??null)===$r['external_message_id']&&(string)($m['recipient']['chat_id']??'')===$channel&&($m['url']??null)===$r['public_url'],'sample_identity_changed');
  $bs=[];foreach($m['body']['attachments']??[]as$a)if(($a['type']??null)==='inline_keyboard')foreach($a['payload']['buttons']??[]as$line)foreach($line as$b)$bs[]=$b;
  as39PinCheck(count($bs)===1&&($bs[0]['type']??null)==='link'&&str_starts_with($bs[0]['url']??'','https://max.ru/id390521129062_bot?start='),'sample_single_bot_button_required');
  $links=array_values(array_filter($m['body']['markup']??[],static fn($m)=>($m['type']??null)==='link'));
  $audit[]=['post_id'=>$id,'url'=>$r['public_url'],'text_chars'=>mb_strlen($m['body']['text']??'','UTF-8'),'text_links'=>count($links),'bot_buttons'=>count($bs),'native_text'=>$m['body']['text'],'native_markup'=>$m['body']['markup']??[],'photo_present'=>isset(as39PinInvoke($publisher,'imagePayload',$m)['photo_id'])];
  if(in_array($id,['models-index','services-index'],true))$urls[$id]=$r['public_url'];
 }
 $plan=as39PinPlan($urls);as39PinCheck(mb_strlen($plan['native_text'],'UTF-8')<=700&&count(array_filter($plan['native_markup'],static fn($m)=>$m['type']==='link'))===3,'menu_plan_invalid');
 $request=['revision'=>'menu-reviews-v1','target_post'=>'menu','message_id'=>$mid,'url'=>$url,'original_operation_id'=>(int)$old['id'],'original_request_sha256'=>$old['payload_sha256'],
  'before_text_sha256'=>hash('sha256',$before['body']['text']),'before_markup'=>$before['body']['markup'],'preserved_photo_id'=>$photo,'preserved_buttons'=>$buttons,
  'reviews_url'=>'https://yandex.ru/maps/org/apple_service39/1731550627/reviews/','body'=>$plan['body'],'native_text'=>$plan['native_text'],'native_markup'=>$plan['native_markup'],'catalogue_urls'=>$urls];
 $operation=$store->prepare('menu-reviews-v1','links',$request);as39PinCheck($operation['state']==='prepared'&&(int)$operation['write_attempts']===0,'menu_update_already_attempted');
 $store->assertBinding(as39PinInvoke($publisher,'preflight'));as39PinCheck($safe()&&as39PinInvoke($publisher,'pinnedMid')===$mid,'publication_authority_changed');
 $fresh=$get($mid);as39PinCheck(($fresh['body']['text']??null)===$before['body']['text']&&($fresh['body']['markup']??null)===$before['body']['markup'],'menu_changed_before_claim');
 as39PinCheck($store->claim($operation),'menu_update_claimed');$store->rememberMessage($operation,$mid,$url);
 $response=$transport->edit($mid,$plan['body']);$code=$response['data']['code']??null;
 as39PinOut(['stage'=>'menu_edit_ack','http'=>(int)($response['http']??0),'transport_error'=>($response['transport_error']??true)===true,'code'=>is_string($code)?substr($code,0,100):null]);
 as39PinCheck(AppleService39ChannelTransport::accepted($response)&&($response['data']['success']??null)===true,'menu_edit_unknown');
 $after=$get($mid);$newBody=$oldBody;$newBody['text']=$plan['body']['text'];as39PinCheck(as39PinInvoke($publisher,'verifyMessageContent',$after,$mid,$newBody,$plan)===$url,'menu_readback_failed');
 $imageAfter=as39PinInvoke($publisher,'imagePayload',$after);as39PinCheck((string)($imageAfter['photo_id']??'')===$photo&&as39PinInvoke($publisher,'pinnedMid')===$mid&&$safe(),'menu_pin_or_photo_changed');
 $store->assertBinding(as39PinInvoke($publisher,'preflight'));$afterRows=$store->rows();$prefix=array_values(array_filter($afterRows,static fn($r)=>$r['post_key']!=='menu-reviews-v1'));as39PinCheck($prefix===$rows,'accepted_operation_changed');
 $store->finish($operation,'verified',['readback'=>true,'revision'=>'menu-reviews-v1','reviews_url'=>$request['reviews_url'],'original_operations_preserved'=>193,'pin_preserved'=>true,'photo_preserved'=>true,'single_bot_button'=>true]);
 as39PinOut(['ok'=>true,'state'=>'pinned_menu_reviews_updated','post_url'=>$url,'reviews_url'=>$request['reviews_url'],'before_chars'=>mb_strlen($before['body']['text'],'UTF-8'),'after_chars'=>mb_strlen($after['body']['text'],'UTF-8'),
  'before_links'=>count(array_filter($before['body']['markup']??[],static fn($m)=>$m['type']==='link')),'after_links'=>3,'bot_buttons'=>1,'pin_preserved'=>true,'photo_preserved'=>true,'original_operations_preserved'=>193,'operations_now'=>194,
  'native_text'=>$after['body']['text'],'native_markup'=>$after['body']['markup'],'samples'=>$audit,'provider_edits'=>1,'provider_creates'=>0]);
}catch(Throwable $e){if($store instanceof AppleService39PilotPublicationStore&&is_array($operation)){try{$row=$store->operation('menu-reviews-v1','links');if($row&&in_array($row['state'],['write_started','unknown'],true))$store->finish($row,'unknown',['reason'=>'menu_edit_or_readback_unknown']);}catch(Throwable $ignored){}}$s=$e->getMessage();as39PinOut(['ok'=>false,'state'=>'stopped','reason'=>preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$s)?$s:'menu_operation_failed','automatic_retry'=>false]);exit(1);}
