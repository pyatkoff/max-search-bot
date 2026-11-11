<?php
declare(strict_types=1);
require __DIR__.'/monitor-price-contract.php';
$tmp=getenv('RUNNER_TEMP');monitor_price_require(is_string($tmp)&&$tmp!=='','temporary_directory_missing');
$operations=json_decode(file_get_contents($tmp.'/monitor-operations.json'),true,32,JSON_THROW_ON_ERROR);$updates=[];$bindings=[];
foreach($operations as $operation){
 $tv=$operation['tourvisor_hotel_id'];$payload=json_decode(file_get_contents($tmp.'/monitor-'.$tv.'-manifest.json'),true,64,JSON_THROW_ON_ERROR);
 $lines=explode("\n",rtrim(file_get_contents($tmp.'/monitor-'.$tv.'-result.json'),"\n"));$result=json_decode(end($lines),true,64,JSON_THROW_ON_ERROR);
 monitor_price_require(($result['ok']??false)===true&&($result['operation']??'')===$operation['operation']&&count($result['posts']??[])===2,'post_result_not_confirmed');
 $price=monitor_price_observation($payload['monitor_snapshot'],$tv,time(),false);$binding=$payload['feed_binding'];$binding['operation']=$payload['operation'];$binding['manifest_hash']=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$receipts=[];
 foreach($result['posts'] as $receipt){$channel=$receipt['platform']??'';monitor_price_require(in_array($channel,['max','telegram'],true)&&!isset($receipts[$channel])&&($receipt['state']??'')==='sent'&&($receipt['existing_message_reused']??false)===true&&($receipt['body_photo_button_verified']??false)===true&&($receipt['monitor_price']??null)===$price&&($receipt['post_url']??'')===$binding['posts'][$channel]&&hash('sha256',$receipt['text']??'')===($receipt['text_sha256']??''),'post_result_receipt_mismatch');monitor_price_validate_text($receipt['text'],$price);$receipts[$channel]=$receipt;}
 $updates[]=['price'=>$price,'binding'=>$binding,'receipts'=>$receipts];$bindings[]=$binding;
}
$at=time();$files=[];
foreach(['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'] as $name){$before=file_get_contents($tmp.'/before-'.$name);$after=monitor_price_render_feed($before,$updates,$name,$at);file_put_contents($tmp.'/after-'.$name,$after);$files[]=['name'=>$name,'sha256'=>hash('sha256',$after),'bytes'=>strlen($after),'rows'=>substr_count($after,'<offer id="'),'expected_previous_sha256'=>hash('sha256',$before),'content_base64'=>base64_encode($after)];}
$input=['schema_version'=>3,'operation'=>'commercial-channel-hotel-feeds-20261111-update47','files'=>$files,'generated_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),'price_updates'=>$bindings];$raw=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$prefix="<?php\ndeclare(strict_types=1);\n";$contract=file_get_contents(__DIR__.'/monitor-price-contract.php');$writer=file_get_contents(__DIR__.'/publish-commercial-channel-hotel-feeds-20261103-1.php');monitor_price_require(str_starts_with($contract,$prefix)&&str_starts_with($writer,$prefix),'source_header_changed');
$header='const EXPECTED_INSTALLED_SHA='.var_export(getenv('EXPECTED_PRODUCTION_SHA'),true).";\n".'const PUBLIC_STATIC_FEED_OPERATION='.var_export($input['operation'],true).";\n".'const PUBLIC_STATIC_FEED_INPUT_SHA256='.var_export(hash('sha256',$raw),true).";\n".'const PUBLIC_STATIC_FEED_INPUT_B64='.var_export(base64_encode($raw),true).";\n";
$writer=str_replace("require_once __DIR__.'/monitor-price-contract.php';",'',substr($writer,strlen($prefix)));
file_put_contents($tmp.'/monitor-feed.php',$prefix.$header.substr($contract,strlen($prefix))."\n".$writer);
echo json_encode(['prepared_feed_files'=>3,'updated_pairs'=>count($updates),'post_writes'=>0,'feed_writes'=>0],JSON_THROW_ON_ERROR),"\n";
