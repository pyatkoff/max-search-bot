<?php
declare(strict_types=1);
require_once __DIR__.'/../integrations/MaxIncomingAdapter.php';
$fail=0;
function ck($name,$actual,$expected){global $fail;if($actual!==$expected){$fail++;fwrite(STDERR,"FAIL $name\n");}else echo "PASS $name\n";}
$base=['update_type'=>'message_created','message'=>['sender'=>['user_id'=>42],'body'=>['mid'=>'m1','text'=>'Этот вариант']]];
$reply=$base;$reply['message']['link']=['type'=>'reply','mid'=>'m0','sender'=>['name'=>'Менеджер'],'message'=>['body'=>['text'=>'Отель A']]];
$x=MaxIncomingAdapter::fromUpdate($reply);
ck('reply type',$x['linked_message']['type']??null,'reply');
ck('reply mid',$x['linked_message']['mid']??null,'m0');
ck('reply text',$x['linked_message']['text']??null,'Отель A');
$forward=$base;$forward['message']['link']=['type'=>'forward','mid'=>'m2','sender'=>['name'=>'Турист'],'message'=>['body'=>['attachments'=>[['type'=>'video','payload'=>['token'=>'video-token']]]]]];
$x=MaxIncomingAdapter::fromUpdate($forward);
ck('forward type',$x['linked_message']['type']??null,'forward');
ck('forward video retained',$x['attachments'][0]['type']??null,'video');
ck('forward video token retained',$x['attachments'][0]['token']??null,'video-token');
$bad=$base;$bad['message']['link']=['type'=>'other','mid'=>'secret'];
$x=MaxIncomingAdapter::fromUpdate($bad);
ck('unsupported link omitted',$x['linked_message']??null,[]);
exit($fail?1:0);
