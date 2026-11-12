<?php
declare(strict_types=1);ini_set('display_errors','0');
const AS39_CTA='Узнать стоимость ремонта';
function ck(bool $b,string $s):void{if(!$b)throw new RuntimeException($s);}
function out(array $v):void{echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;}
function inv(object $o,string $m,...$a){return(new ReflectionMethod($o,$m))->invoke($o,...$a);}
function gitRead(array $args):string{$p=proc_open(array_merge(['git','-C','/var/www/anytoour/data/www/app.anytoour.ru'],$args),[['pipe','r'],['pipe','w'],['pipe','w']],$x);ck(is_resource($p),'checkout_unavailable');fclose($x[0]);$s=stream_get_contents($x[1]);fclose($x[1]);fclose($x[2]);ck(proc_close($p)===0,'checkout_unavailable');return trim($s);}
function htmlPlan(string $text,array $markup):array{
 ck(preg_match('/[\\x{10000}-\\x{10ffff}]/u',$text)===0,'unsupported_character');
 usort($markup,static fn($a,$b)=>$a['from']<=>$b['from']);$html='';$offset=0;
 $esc=static fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 foreach($markup as$m){$from=(int)$m['from'];$length=(int)$m['length'];ck($from>=$offset&&$length>0&&$from+$length<=mb_strlen($text,'UTF-8'),'native_markup_invalid');$html.=$esc(mb_substr($text,$offset,$from-$offset,'UTF-8'));$span=$esc(mb_substr($text,$from,$length,'UTF-8'));
  if($m['type']==='strong')$html.='<b>'.$span.'</b>';elseif($m['type']==='link'){$u=$m['url'];ck(preg_match('~\\Ahttps://(?:max\\.ru/channel_appleservice39ru/[A-Za-z0-9_-]+|yandex\\.ru/maps/org/apple_service39/1731550627/reviews/)\\z~D',$u)===1,'native_link_refused');$html.='<a href="'.$esc($u).'">'.$span.'</a>';}else throw new RuntimeException('unsupported_native_markup');$offset=$from+$length;
 }
 $html.=$esc(mb_substr($text,$offset,null,'UTF-8'));return ['body'=>['text'=>$html,'format'=>'html'],'native_text'=>$text,'native_markup'=>$markup];
}
function renamedText(string $text,array $markup,string $old):array{
 $needle='«'.$old.'»';$replacement='«'.AS39_CTA.'»';$pos=mb_strpos($text,$needle,0,'UTF-8');
 if($pos!==false){ck(mb_strpos($text,$needle,$pos+1,'UTF-8')===false,'ambiguous_cta_sentence');$length=mb_strlen($needle,'UTF-8');$delta=mb_strlen($replacement,'UTF-8')-$length;foreach($markup as&$m){ck($m['from']+$m['length']<=$pos||$m['from']>=$pos+$length,'cta_sentence_overlaps_markup');if($m['from']>=$pos+$length)$m['from']+=$delta;}unset($m);$text=mb_substr($text,0,$pos,'UTF-8').$replacement.mb_substr($text,$pos+$length,null,'UTF-8');}
 return htmlPlan($text,$markup);
}
$store=null;$active=null;
try{
 $root='/var/www/anytoour/data/www/app.anytoour.ru';$sha='0cb62969d0a8fa59b1872df465b3810bf5b569d4';$channel='-80917954947727';$pin='mid.ffffb667d20a157101a1bd6d233963da';$config='/var/www/anytoour/data/config/max-search.php';
 ck(PHP_SAPI==='cli'&&realpath($root)===$root&&is_file($config)&&!is_link($config),'fixed_cli_target_required');$configHash=hash_file('sha256',$config);$safe=static fn():bool=>gitRead(['rev-parse','HEAD'])===$sha&&gitRead(['status','--porcelain','--untracked-files=no'])===''&&is_file($config)&&!is_link($config)&&hash_file('sha256',$config)===$configHash;ck($safe(),'checkout_changed');
 require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/AppleService39PilotPublisher.php';
 $pdo=ConversationDb::connection();ck(!$pdo->inTransaction(),'autocommit_required');$store=new AppleService39PilotPublicationStore($pdo);$original=$store->rows();ck(count($original)===195&&count(array_filter($original,static fn($r)=>$r['state']!=='verified'))===0,'original_operations_changed');
 $manifest=AppleService39PilotManifest::installed();$pub=new AppleService39PilotPublisher($pdo,$manifest,$channel,$safe,null,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));$store->assertBinding(inv($pub,'preflight'));ck(inv($pub,'pinnedMid')===$pin,'pin_changed');
 $conn=ProjectMaxConnection::resolve('appleservice39');ck($conn!==null,'connection_unavailable');$tr=new AppleService39ChannelTransport($conn,$channel);
 $get=static function(string $mid)use($tr):array{$r=$tr->message($mid);ck(AppleService39ChannelTransport::accepted($r),'native_read_failed');return $r['data']['message']??$r['data'];};
 $creates=array_values(array_filter($original,static fn($r)=>$r['operation']==='create'));ck(count($creates)===69,'post_inventory_changed');$plans=[];
 foreach($creates as$c){$id=$c['post_key'];$mid=$c['external_message_id'];$m=$get($mid);$full=null;$receipt=null;
  foreach($original as$r)if($r['post_key']===$id){if(isset($r['request']['body']['attachments']))$full=$r['request']['body'];if(isset($r['outcome']['image_receipt']))$receipt=$r['outcome']['image_receipt'];}
  ck(is_array($full)&&is_array($receipt),'original_image_binding_missing');$before=htmlPlan($m['body']['text'],$m['body']['markup']??[]);$body=$before['body'];$body['attachments']=$full['attachments'];
  ck(inv($pub,'verifyMessageContent',$m,$mid,$body,$before)===$c['public_url'],'native_content_changed');$image=inv($pub,'imagePayload',$m);$photo=(string)($image['photo_id']??'');ck($photo!==''&&$photo===$receipt['photo_id'],'native_photo_changed');
  $imageAt=null;$keyAt=null;foreach($full['attachments']as$i=>$a){if($a['type']==='image')$imageAt=$i;if($a['type']==='inline_keyboard')$keyAt=$i;}ck($imageAt!==null&&$keyAt!==null,'original_attachments_invalid');
  // The most recent text-only request can retain an older image token. The
  // native image receipt, not that stale request, owns the current artwork.
  $tokens=[];foreach($original as$upload){$candidate=$upload['outcome']['token']??null;if(is_string($candidate)&&hash('sha256',$candidate)===$receipt['upload_token_sha256'])$tokens[$candidate]=true;}
  ck(count($tokens)===1,'current_image_token_unavailable');$token=(string)array_key_first($tokens);
  $full['attachments'][$imageAt]['payload']=['token'=>$token];$body['attachments']=$full['attachments'];
  $buttons=$m['body']['attachments'][$keyAt]['payload']['buttons'];ck(count($buttons)===1&&count($buttons[0])===1&&$buttons[0][0]['type']==='link','single_bot_button_required');$old=$buttons[0][0]['text'];$target=$buttons[0][0]['url'];ck(str_starts_with($target,'https://max.ru/id390521129062_bot?start='),'bot_target_changed');
  $next=renamedText($m['body']['text'],$m['body']['markup']??[],$old);$next['body']['attachments']=$full['attachments'];$next['body']['attachments'][$keyAt]['payload']['buttons']=[[['type'=>'link','text'=>AS39_CTA,'url'=>$target]]];
  $plans[]=['post'=>$id,'mid'=>$mid,'url'=>$c['public_url'],'before'=>$before,'before_body'=>$body,'before_cta'=>$old,'photo_id'=>$photo,'plan'=>$next];
 }
 $channelRead=$tr->channel();$member=$tr->membership();ck(AppleService39ChannelTransport::accepted($channelRead)&&AppleService39ChannelTransport::accepted($member),'channel_read_failed');$ch=$channelRead['data'];$desc=$ch['description']??'';ck(is_string($desc)&&substr_count($desc,'Даем безусловную гарантию на ремонт.')===1,'channel_copy_changed');ck(in_array('change_chat_info',$member['data']['permissions']??[],true),'channel_description_permission_missing');$newDesc=str_replace('Даем безусловную гарантию на ремонт.','Гарантия на ремонт — 3 месяца.',$desc);
 foreach($plans as&$p){$req=['revision'=>'cta-cost-v1','target_post'=>$p['post'],'message_id'=>$p['mid'],'url'=>$p['url'],'before_text_sha256'=>hash('sha256',$p['before']['native_text']),'before_markup'=>$p['before']['native_markup'],'before_button'=>$p['before_cta'],'preserved_photo_id'=>$p['photo_id'],'body'=>$p['plan']['body'],'native_text'=>$p['plan']['native_text'],'native_markup'=>$p['plan']['native_markup']];$p['row']=$store->prepare('cta1-'.$p['post'],'links',$req);ck($p['row']['state']==='prepared'&&(int)$p['row']['write_attempts']===0,'cta_edit_already_attempted');}unset($p);
 $profile=$store->prepare('channel-copy-v1','links',['revision'=>'cta-cost-v1','target_channel'=>$channel,'before_description_sha256'=>hash('sha256',$desc),'description'=>$newDesc]);ck($profile['state']==='prepared'&&(int)$profile['write_attempts']===0,'profile_edit_already_attempted');out(['stage'=>'copy_prepared','posts'=>69,'channel_profile'=>1,'original_operations_preserved'=>195]);
 $done=0;
 foreach($plans as$p){$active=$p['row'];$fresh=$get($p['mid']);ck(inv($pub,'verifyMessageContent',$fresh,$p['mid'],$p['before_body'],$p['before'])===$p['url'],'post_changed_before_claim');ck((string)(inv($pub,'imagePayload',$fresh)['photo_id']??'')===$p['photo_id'],'photo_changed_before_claim');inv($pub,'beforeWrite',$active);$store->rememberMessage($active,$p['mid'],$p['url']);
  $ack=$tr->edit($p['mid'],$p['plan']['body']);ck(AppleService39ChannelTransport::accepted($ack)&&($ack['data']['success']??null)===true,'cta_edit_unknown');$after=$get($p['mid']);ck(inv($pub,'verifyMessageContent',$after,$p['mid'],$p['plan']['body'],$p['plan'])===$p['url']&&(string)(inv($pub,'imagePayload',$after)['photo_id']??'')===$p['photo_id'],'cta_readback_failed');
  $store->finish($active,'verified',['readback'=>true,'photo_preserved'=>true,'cta'=>AS39_CTA,'original_target_preserved'=>true]);$active=null;$done++;if($done%10===0)out(['stage'=>'cta_readback','verified'=>$done,'total'=>69]);usleep(120000);
 }
 $active=$profile;$fresh=$tr->channel();ck(AppleService39ChannelTransport::accepted($fresh)&&($fresh['data']['description']??null)===$desc,'profile_changed_before_claim');inv($pub,'beforeWrite',$active);$ack=inv($tr,'api','PATCH','/chats/'.$channel,[],['description'=>$newDesc]);ck(AppleService39ChannelTransport::accepted($ack),'profile_edit_unknown');$after=$tr->channel();ck(AppleService39ChannelTransport::accepted($after)&&($after['data']['description']??null)===$newDesc&&($after['data']['title']??null)===$ch['title']&&($after['data']['link']??null)===$ch['link']&&inv($pub,'pinnedMid')===$pin,'profile_readback_failed');$store->finish($active,'verified',['readback'=>true,'warranty_months'=>3,'title_link_pin_preserved'=>true]);$active=null;
 $afterRows=$store->rows();$prefix=array_values(array_filter($afterRows,static fn($r)=>!str_starts_with($r['post_key'],'cta1-')&&$r['post_key']!=='channel-copy-v1'));ck($prefix===$original&&$safe(),'original_operations_changed');ck(count($afterRows)===265&&count(array_filter($afterRows,static fn($r)=>$r['state']!=='verified'))===0,'final_rows_unconfirmed');$store->assertBinding(inv($pub,'preflight'));
 out(['ok'=>true,'state'=>'cta_and_warranty_published','posts_updated'=>69,'cta'=>AS39_CTA,'warranty_months'=>3,'channel_description_updated'=>true,'old_operations_preserved'=>195,'verified_operations'=>265,'provider_edits'=>70,'provider_creates'=>0,'images_mids_links_pin_reviews_preserved'=>true,'model_service_links_changed'=>false]);
}catch(Throwable $e){if($store instanceof AppleService39PilotPublicationStore&&is_array($active)){try{$r=$store->operation($active['post_key'],$active['operation']);if($r&&in_array($r['state'],['write_started','unknown'],true))$store->finish($r,'unknown',['reason'=>'copy_edit_or_readback_unknown']);}catch(Throwable $ignored){}}$msg=$e->getMessage();out(['ok'=>false,'reason'=>preg_match('/\\A[a-z_]{2,100}\\z/D',$msg)?$msg:'content_copy_failed','error_class'=>get_class($e),'error_file'=>basename($e->getFile()),'line'=>$e->getLine(),'automatic_retry'=>false]);exit(1);}

