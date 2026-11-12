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
 $add('300+ оценок клиентов — читать отзывы',false,'https://yandex.ru/maps/org/apple_service39/1731550627/reviews/',"\n");
 $add('Нажмите «Написать менеджеру» — уточним ремонт и удобное время.');
 return ['body'=>['text'=>$html,'format'=>'html'],'native_text'=>$plain,'native_markup'=>$markup];
}
function as39PathHttp(string $url,string $method='GET',?string $body=null):array{
 $host=parse_url($url,PHP_URL_HOST);as39PinCheck(in_array($host,['tour-max.ru','app.anytoour.ru'],true),'public_host_refused');
 $h=curl_init($url);$text='';curl_setopt_array($h,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Accept: text/html,application/json,text/csv,image/*','Content-Type: application/json'],CURLOPT_WRITEFUNCTION=>static function($c,string $s)use(&$text):int{if(strlen($text)+strlen($s)>5242880)return 0;$text.=$s;return strlen($s);}]);
 if($body!==null)curl_setopt($h,CURLOPT_POSTFIELDS,$body);$done=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($h,CURLINFO_CONTENT_TYPE);$error=curl_errno($h);curl_close($h);as39PinCheck($done!==false&&$error===0,'public_request_failed');return ['http'=>$status,'type'=>$type,'body'=>$text];
}
function as39PathAudit(PDO $pdo,ProjectMaxConnection $connection,AppleService39ChannelTransport $transport,array $rows,callable $get,int $source):array{
 require_once '/var/www/anytoour/data/www/app.anytoour.ru/services/ProjectFunnelEntry.php';
 require_once '/var/www/anytoour/data/www/app.anytoour.ru/services/RoutingAccessService.php';
 require_once '/var/www/anytoour/data/www/app.anytoour.ru/services/ManagerPushConnectionService.php';
 $settings=ProjectFunnelSettings::load($connection);as39PinCheck(is_array($settings),'funnel_unavailable');$entryOwner=new ProjectFunnelEntry($pdo,$connection,$settings);
 $creates=array_values(array_filter($rows,static fn($r)=>$r['operation']==='create'));as39PinCheck(count($creates)===69,'post_inventory_changed');$urls=array_column($creates,'public_url','post_key');$byUrl=array_flip($urls);$posts=[];$issues=[];$answers=[];
 foreach($creates as$r){$key=$r['post_key'];$m=$get($r['external_message_id']);as39PinCheck(($m['body']['mid']??null)===$r['external_message_id']&&(string)($m['recipient']['chat_id']??'')==='-80917954947727'&&($m['url']??null)===$urls[$key],'post_identity_mismatch');
  $buttons=[];$photo=false;foreach($m['body']['attachments']??[]as$a){if(($a['type']??'')==='image')$photo=true;if(($a['type']??'')==='inline_keyboard')foreach($a['payload']['buttons']??[]as$line)foreach($line as$b)$buttons[]=$b;}
  as39PinCheck($photo&&count($buttons)===1&&($buttons[0]['type']??'')==='link','single_bot_cta_or_image_failed');$burl=parse_url($buttons[0]['url']);parse_str($burl['query']??'',$q);
  as39PinCheck(($burl['scheme']??'')==='https'&&($burl['host']??'')==='max.ru'&&($burl['path']??'')==='/id390521129062_bot'&&array_keys($q)===['start']&&is_string($q['start']),'bot_target_failed');
  $entry=RepairProjectSettings::entry($connection,$q['start']);as39PinCheck(is_array($entry)&&$entry['post_id']===$key,'bot_entry_context_failed');$context=AppleService39PilotCatalog::entryContext($entry,[],$q['start']);$reply=AppleService39PilotCatalog::contextResponseText($entry,'AppleService39',$context);
  as39PinCheck(!preg_match('/турист|тур\b|отель|вылет|питание/iu',$reply),'tourism_copy_found');$links=[];
  foreach($m['body']['markup']??[]as$a)if(($a['type']??'')==='link'){ $link=$a['url']??'';if(isset($byUrl[$link]))$links[]=$byUrl[$link];elseif($link!=='https://yandex.ru/maps/org/apple_service39/1731550627/reviews/')$issues[]=['kind'=>'foreign_text_link','post'=>$key]; }
  $posts[$key]=['links'=>$links,'model'=>$entry['model'],'scope'=>$entry['scope']??'repair','service'=>$entry['service']];
  if(in_array($key,['menu','model-iphone-8','model-iphone-15-pro-max','repair-iphone-x-battery-replacement','service-earpiece-replacement','symptom-not-charging'],true))$answers[]=['post'=>$key,'bot_reply'=>$reply,'context'=>$context];
 }
 $seen=['menu'=>true];$queue=['menu'];while($queue){$k=array_shift($queue);foreach($posts[$k]['links']as$to)if(!isset($seen[$to])){$seen[$to]=true;$queue[]=$to;}}
 $repeatModel=[];foreach($posts as$key=>$p)if($p['scope']==='model'&&$p['model']!==''){foreach($p['links']as$to){if(in_array($posts[$to]['scope'],['service','symptom'],true)&&$posts[$to]['model']==='')$repeatModel[$key]=true;}}
 $navModels=$posts['models-index']['links'];$navServices=$posts['services-index']['links'];
 as39PinOut(['stage'=>'native_path_audit','posts'=>count($posts),'reachable_from_pin'=>count($seen),'model_links'=>count($navModels),'service_links'=>count($navServices),'all_single_bot_cta'=>true,'fresh_model_to_generic_service_cards'=>count($repeatModel),'foreign_link_issues'=>$issues]);
 $base='https://tour-max.ru/new/appleservice39/';$first=as39PathHttp($base.'feed-search-intent-v1.csv');$second=as39PathHttp($base.'?format=csv');as39PinCheck($first['http']===200&&$second['http']===200&&$first['body']===$second['body'],'public_csv_mismatch');
 $stream=fopen('php://temp','w+');fwrite($stream,$first['body']);rewind($stream);$header=fgetcsv($stream);$offers=[];while(($row=fgetcsv($stream))!==false){as39PinCheck(count($row)===count($header),'public_csv_invalid');$offers[]=array_combine($header,$row);}fclose($stream);as39PinCheck(count($offers)===67,'public_offer_count_changed');
 $images=[];$maxDescription=0;$maxTitle=0;$modelOffers=0;foreach($offers as$row){$p=parse_url($row['URL']);parse_str($p['query']??'',$q);as39PinCheck(($p['host']??'')==='max.ru'&&($p['path']??'')==='/id390521129062_bot'&&isset($q['startapp']),'advertising_url_failed');
  $sample=str_replace(['{yclid}','{region_id}','{campaign_id}'],['12345678901234567890','22','123'],$q['startapp']);$launch=ProjectFunnelLaunch::parse($sample);$destination=$entryOwner->destination($launch['post_id'],$launch['offer_id']);as39PinCheck($destination['url']===$urls[$launch['post_id']]&&$row['ID']===$launch['offer_id'],'offer_destination_failed');
  $maxDescription=max($maxDescription,mb_strlen($row['Description'],'UTF-8'));$maxTitle=max($maxTitle,mb_strlen($row['Title'],'UTF-8'));$images[$row['Image']]=true;if(($row['custom_label_0']??'')==='model')$modelOffers++;
 }
 as39PinCheck($maxDescription<=81&&$maxTitle<=56,'direct_copy_limits_failed');$imageProof=[];foreach(array_keys($images)as$url){$im=as39PathHttp($url);$size=@getimagesizefromstring($im['body']);as39PinCheck($im['http']===200&&is_array($size)&&$size[0]>=300&&$size[1]>=300,'public_image_failed');$imageProof[]=['url'=>$url,'width'=>$size[0],'height'=>$size[1],'sha256'=>hash('sha256',$im['body'])];}
 $page=as39PathHttp($base);as39PinCheck($page['http']===200&&preg_match('~<script id="funnel-config" type="application/json">(.+?)</script>~s',$page['body'],$m)===1,'public_html_failed');$client=json_decode($m[1],true);as39PinCheck($client===ProjectFunnelSettings::publicClient($settings),'public_client_config_failed');
 $js=as39PathHttp('https://app.anytoour.ru/assets/project-funnel.js');as39PinCheck($js['http']===200&&hash('sha256',$js['body'])===hash_file('sha256','/var/www/anytoour/data/www/app.anytoour.ru/assets/project-funnel.js'),'public_client_source_failed');
 $invalid=as39PathHttp($base,'POST','{}');as39PinCheck($invalid['http']===400&&(json_decode($invalid['body'],true)['error']??'')==='invalid_launch','unsigned_launch_not_refused');
 $webhook=RepairProjectSettings::webhook($connection);$subs=as39PinInvoke($transport,'api','GET','/subscriptions');as39PinCheck(AppleService39ChannelTransport::accepted($subs),'subscriptions_read_failed');$registered=[];foreach($subs['data']['subscriptions']??[]as$s)if(($s['url']??'')===$webhook['url'])$registered[]=['url'=>$s['url'],'update_types'=>$s['update_types']??[]];
 $hasWebhook=count($registered)===1&&count(array_diff(['bot_started','message_created'],$registered[0]['update_types']))===0;
 $g=as39PathHttp($webhook['url']);$bad=as39PathHttp($webhook['url'],'POST','{}');as39PinCheck($g['http']===405&&$bad['http']===403,'webhook_boundary_failed');
 $q=$pdo->prepare("SELECT m.id,m.is_working FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE p.project_key=? AND m.is_active=1");$q->execute(['appleservice39']);$managers=$q->fetchAll(PDO::FETCH_ASSOC);$view=['project_key'=>'appleservice39','source_id'=>$source,'status'=>'waiting_manager','manager_id'=>null,'channel'=>'max','entry_channel'=>'max:appleservice39-main'];$canSee=[];$working=[];
 foreach($managers as$m)if(RoutingAccessService::canSeeConversation((int)$m['id'],$view)){$canSee[]=(int)$m['id'];if((int)$m['is_working']===1)$working[]=(int)$m['id'];}
 $activePush=ManagerPushConnectionService::activeSubscriptions($pdo,$working);$ready=[];foreach($activePush as$p)$ready[(int)$p['manager_id']]=true;
 $q=$pdo->prepare("SELECT status,COUNT(*) AS total FROM conversations WHERE project_key=? AND channel='max' GROUP BY status");$q->execute(['appleservice39']);$conversationCounts=$q->fetchAll(PDO::FETCH_ASSOC);
 return ['native_posts'=>count($posts),'reachable_from_pin'=>count($seen),'unreachable'=>array_values(array_diff(array_keys($posts),array_keys($seen))),'single_bot_button_posts'=>count($posts),'models_in_catalogue'=>count($navModels),'service_navigation_links'=>count($navServices),'offers'=>count($offers),'model_offers'=>$modelOffers,'max_description'=>$maxDescription,'max_title'=>$maxTitle,'public_images'=>$imageProof,'csv_sha256'=>hash('sha256',$first['body']),'public_html_config_verified'=>true,'public_js_sha256'=>hash('sha256',$js['body']),'unsigned_launch_http'=>$invalid['http'],'webhook_registered'=>$hasWebhook,'webhook'=>$registered,'webhook_get_http'=>$g['http'],'unauthenticated_webhook_http'=>$bad['http'],'active_project_managers'=>count($managers),'queue_visible_managers'=>count($canSee),'working_visible_managers'=>count($working),'working_managers_with_push'=>count($ready),'conversation_counts'=>$conversationCounts,'foreign_link_issues'=>$issues,'fresh_model_to_generic_service_cards'=>array_keys($repeatModel),'bot_replies'=>$answers,'real_customer_messages_sent'=>0,'real_test_leads_created'=>0,'signed_live_max_client_tested'=>false];
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
 $rows=$store->rows();as39PinCheck(count($rows)===194&&count(array_filter($rows,static fn($r)=>$r['state']!=='verified'))===0,'accepted_rows_changed');
 $manifest=AppleService39PilotManifest::installed();$publisher=new AppleService39PilotPublisher($pdo,$manifest,$channel,$safe,null,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));
 $store->assertBinding(as39PinInvoke($publisher,'preflight'));as39PinCheck(as39PinInvoke($publisher,'pinnedMid')===$mid,'pin_changed');
 $connection=ProjectMaxConnection::resolve('appleservice39');as39PinCheck($connection!==null,'connection_unavailable');$transport=new AppleService39ChannelTransport($connection,$channel);
 $old=$store->operation('menu-reviews-v1','links');$parent=$store->operation('menu','search_v1_edit');
 as39PinCheck($old&&$parent&&$old['state']==='verified'&&$old['external_message_id']===$mid&&($old['request']['revision']??'')==='menu-reviews-v1','menu_source_changed');
 $get=static function(string $id)use($transport):array{$r=$transport->message($id);as39PinCheck(AppleService39ChannelTransport::accepted($r),'native_read_failed');$m=$r['data']['message']??$r['data'];as39PinCheck(is_array($m),'native_read_failed');return $m;};
 $before=$get($mid);$oldBody=$parent['request']['body'];$oldBody['text']=$old['request']['body']['text'];$oldBody['format']='html';
 $url=as39PinInvoke($publisher,'verifyMessageContent',$before,$mid,$oldBody,$old['request']);$imageBefore=as39PinInvoke($publisher,'imagePayload',$before);$photo=(string)($imageBefore['photo_id']??'');
 as39PinCheck($photo!==''&&$photo===$old['request']['preserved_photo_id'],'menu_photo_changed');
 $buttons=[];foreach($before['body']['attachments']as$a)if($a['type']==='inline_keyboard')foreach($a['payload']['buttons']as$line)foreach($line as$button)$buttons[]=$button;
 as39PinCheck(count($buttons)===1&&($buttons[0]['type']??'')==='link'&&str_starts_with($buttons[0]['url']??'','https://max.ru/id390521129062_bot?start='),'menu_button_changed');
 $audit=as39PathAudit($pdo,$connection,$transport,$rows,$get,(int)as39PinInvoke($publisher,'preflight')['source_id']);$urls=[];
 foreach(['models-index','services-index']as$id){$r=$store->operation($id,'create');as39PinCheck($r&&$r['state']==='verified','catalogue_unavailable');$urls[$id]=$r['public_url'];}
 $plan=as39PinPlan($urls);as39PinCheck(mb_strlen($plan['native_text'],'UTF-8')<=700&&count(array_filter($plan['native_markup'],static fn($m)=>$m['type']==='link'))===3,'menu_plan_invalid');
 $request=['revision'=>'menu-social-proof-v1','target_post'=>'menu','message_id'=>$mid,'url'=>$url,'original_operation_id'=>(int)$old['id'],'original_request_sha256'=>$old['payload_sha256'],
  'before_text_sha256'=>hash('sha256',$before['body']['text']),'before_markup'=>$before['body']['markup'],'preserved_photo_id'=>$photo,'preserved_buttons'=>$buttons,
  'reviews_url'=>'https://yandex.ru/maps/org/apple_service39/1731550627/reviews/','body'=>$plan['body'],'native_text'=>$plan['native_text'],'native_markup'=>$plan['native_markup'],'catalogue_urls'=>$urls];
 $operation=$store->prepare('menu-social-proof-v1','links',$request);as39PinCheck($operation['state']==='prepared'&&(int)$operation['write_attempts']===0,'menu_update_already_attempted');
 $store->assertBinding(as39PinInvoke($publisher,'preflight'));as39PinCheck($safe()&&as39PinInvoke($publisher,'pinnedMid')===$mid,'publication_authority_changed');
 $fresh=$get($mid);as39PinCheck(($fresh['body']['text']??null)===$before['body']['text']&&($fresh['body']['markup']??null)===$before['body']['markup'],'menu_changed_before_claim');
 as39PinCheck($store->claim($operation),'menu_update_claimed');$store->rememberMessage($operation,$mid,$url);
 $response=$transport->edit($mid,$plan['body']);$code=$response['data']['code']??null;
 as39PinOut(['stage'=>'menu_edit_ack','http'=>(int)($response['http']??0),'transport_error'=>($response['transport_error']??true)===true,'code'=>is_string($code)?substr($code,0,100):null]);
 as39PinCheck(AppleService39ChannelTransport::accepted($response)&&($response['data']['success']??null)===true,'menu_edit_unknown');
 $after=$get($mid);$newBody=$oldBody;$newBody['text']=$plan['body']['text'];as39PinCheck(as39PinInvoke($publisher,'verifyMessageContent',$after,$mid,$newBody,$plan)===$url,'menu_readback_failed');
 $imageAfter=as39PinInvoke($publisher,'imagePayload',$after);as39PinCheck((string)($imageAfter['photo_id']??'')===$photo&&as39PinInvoke($publisher,'pinnedMid')===$mid&&$safe(),'menu_pin_or_photo_changed');
 $store->assertBinding(as39PinInvoke($publisher,'preflight'));$afterRows=$store->rows();$prefix=array_values(array_filter($afterRows,static fn($r)=>$r['post_key']!=='menu-social-proof-v1'));as39PinCheck($prefix===$rows,'accepted_operation_changed');
 $store->finish($operation,'verified',['readback'=>true,'revision'=>'menu-social-proof-v1','reviews_url'=>$request['reviews_url'],'original_operations_preserved'=>194,'pin_preserved'=>true,'photo_preserved'=>true,'single_bot_button'=>true]);
 as39PinOut(['ok'=>true,'state'=>'pinned_menu_reviews_updated','post_url'=>$url,'reviews_url'=>$request['reviews_url'],'before_chars'=>mb_strlen($before['body']['text'],'UTF-8'),'after_chars'=>mb_strlen($after['body']['text'],'UTF-8'),
  'before_links'=>count(array_filter($before['body']['markup']??[],static fn($m)=>$m['type']==='link')),'after_links'=>3,'bot_buttons'=>1,'pin_preserved'=>true,'photo_preserved'=>true,'original_operations_preserved'=>194,'operations_now'=>195,
  'native_text'=>$after['body']['text'],'native_markup'=>$after['body']['markup'],'customer_path_audit'=>$audit,'provider_edits'=>1,'provider_creates'=>0]);
}catch(Throwable $e){if($store instanceof AppleService39PilotPublicationStore&&is_array($operation)){try{$row=$store->operation('menu-social-proof-v1','links');if($row&&in_array($row['state'],['write_started','unknown'],true))$store->finish($row,'unknown',['reason'=>'menu_edit_or_readback_unknown']);}catch(Throwable $ignored){}}$s=$e->getMessage();as39PinOut(['ok'=>false,'state'=>'stopped','reason'=>preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$s)?$s:'menu_operation_failed','automatic_retry'=>false]);exit(1);}
