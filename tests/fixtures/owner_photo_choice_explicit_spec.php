<?php
declare(strict_types=1);
define('OWNER_PHOTO_CHOICE_FIXTURE_LIBRARY', true);
require_once dirname(__DIR__).'/run_owner_tour_photo_choice_trial_regression.php';

function explicitSpec(string $key = 'palace'): array
{
    if ($key === 'grand-park') {
        $spec=explicitSpec('palace'); $spec['id']='owner-live-quote-20261021-grand-park';
        $spec['snapshot']['hotel']='Grand Park Lara 5★';
        foreach ([
            'https://www.corendonhotels.com.tr/storage/slides/March2026/ZuI2cGTDetugSWKhnE93.jpg',
            'https://www.corendonhotels.com.tr/storage/pages/May2026/iX5LgEU46J2oOMC8VFjS.jpg',
            'https://www.corendonhotels.com.tr/storage/rooms/March2026/hJ0wq9HCId31KoXNb6MI.jpg',
        ] as $index=>$url) $spec['presentation']['photos'][$index]['url']=$url;
        return $spec;
    }
    $data = [
        'palace'=>['Delphin Palace 5★','200001.50','4003','4042','798'],
        'imperial'=>['Delphin Imperial 5★','200002.50','2996','4270','709'],
        'be-grand'=>['Delphin BE Grand Resort 5★','200003.50','4407','4391','4376'],
    ][$key];
    $medium = $key === 'imperial' ? 'medium' : 'big';
    return ['id'=>'owner-live-quote-20261021-'.$key,
        'snapshot'=>array_replace(ownerPhotoChoiceSnapshot(), ['hotel'=>$data[0], 'amount'=>$data[1], 'is_demo'=>false,
            'room'=>'Стандартный номер', 'flights'=>['Рейс и обязательные доплаты ещё не проверены.'],
            'rationale'=>'Контрольная синтетическая котировка; это не доступный туристу тариф.']),
        'presentation'=>['destination'=>'Турция, Лара','inclusions'=>[],'room_illustrative'=>true,'photos'=>[
            ['role'=>'cover','url'=>'https://www.delphinhotel.com/main_pics/pages/'.$medium.'/'.$data[2].'.png'],
            ['role'=>'beach','url'=>'https://www.delphinhotel.com/main_pics/pages/big/'.$data[3].'.png'],
            ['role'=>'room','url'=>'https://www.delphinhotel.com/main_pics/pages/'.($key === 'be-grand' ? 'big' : 'medium').'/'.$data[4].'.png']]]];
}

function explicitReceipt(string $directory, string $channel, array $spec): array
{
    return json_decode(file_get_contents($directory.'/'.$spec['id'].'-'.$channel.'.json'), true);
}

function explicitNative(string $channel, string $callback, array $spec): array
{
    $body = ownerPhotoChoiceBody($channel, $callback, [], $spec);
    if ($channel === 'telegram') {
        foreach ([0,1,2] as $index) $body['rich_message']['blocks'][0]['blocks'][$index]['photo'] = [['file_id'=>'explicit-photo-'.$index,'width'=>1100,'height'=>825]];
        return ['message_id'=>201,'chat'=>['id'=>881001,'type'=>'private'],'from'=>['id'=>881099,'is_bot'=>true],
            'rich_message'=>$body['rich_message'],'reply_markup'=>$body['reply_markup']];
    }
    $card = ownerPhotoChoiceCard($callback, $spec); $images = [];
    foreach ([0,1,2] as $index) $images[] = ['type'=>'image','payload'=>['photo_id'=>900+$index]];
    return ['body'=>['mid'=>'mid.explicit_choice','text'=>$card['plain_text'],'markup'=>$card['max_markup'],
        'attachments'=>array_merge($images, $body['attachments'])], 'sender'=>['user_id'=>772099],
        'recipient'=>['user_id'=>772001,'chat_id'=>-770001,'chat_type'=>'dialog']];
}

function explicitRun(array $spec, ?string $directory = null, string $failure = '', array $transferOverrides = []): array
{
    global $directories;
    if ($directory === null) { $directory=sys_get_temp_dir().'/owner-explicit-choice-'.bin2hex(random_bytes(8)); $directories[]=$directory; }
    $calls=[]; $writes=[];
    $requestTG=static function($method,$body,$token,$images) use (&$calls,$spec,$directory,$failure): array {
        $calls[]=['telegram',$method];
        if ($method==='getMe') return ['data'=>['id'=>881099,'username'=>'anyru_bot','is_bot'=>true]];
        if ($method==='getChat') return ['data'=>['id'=>881001,'type'=>'private']];
        $record=explicitReceipt($directory,'telegram',$spec);
        photoChoiceAssert($record['post_reserved'] && $record['offer_id']===2001 && count($images)===3, 'Explicit Telegram snapshot and photos precede creation');
        photoChoiceAssert($body===['chat_id'=>'881001']+ownerPhotoChoiceBody('telegram',$record['callback_data'],[],$spec), 'Telegram posts the exact explicit conditions and callback');
        if ($failure==='unknown') return ['data'=>null,'diagnostic'=>['http_status'=>0,'error_category'=>'transport']];
        $native=explicitNative('telegram',$record['callback_data'],$spec);
        if ($failure==='changed_caption') $native['rich_message']['blocks'][3]['text']='Different conditions';
        return ['data'=>$native,'diagnostic'=>['http_status'=>200,'curl_code'=>0,'error_category'=>'none']];
    };
    $requestMAX=static function($method,$path,$query,$body,$token) use (&$calls,$spec,$directory): array {
        $calls[]=['max',$method,$path]; $diagnostic=['http_status'=>200,'curl_code'=>0,'error_category'=>'none'];
        if ($path==='/messages/mid.owner_inbound') return ['data'=>['body'=>['mid'=>'mid.owner_inbound'],'sender'=>['user_id'=>772001],
            'recipient'=>['user_id'=>772099,'chat_id'=>-770001,'chat_type'=>'dialog']],'diagnostic'=>$diagnostic];
        if ($path==='/uploads') return ['data'=>['url'=>'https://iu.oneme.ru/uploadImage?token=explicit-private'],'diagnostic'=>$diagnostic];
        $record=explicitReceipt($directory,'max',$spec);
        if ($method==='GET') return ['data'=>explicitNative('max',$record['callback_data'],$spec),'diagnostic'=>$diagnostic];
        photoChoiceAssert($record['post_reserved'] && $record['offer_id']===2002 && count($record['image_tokens'])===3, 'Explicit MAX upload tokens and permanent reservation precede POST');
        photoChoiceAssert($body===ownerPhotoChoiceBody('max',$record['callback_data'],$record['image_tokens'],$spec), 'MAX posts exact explicit conditions, photo tokens and callback');
        return ['data'=>['message'=>['body'=>['mid'=>'mid.explicit_choice']]],'diagnostic'=>$diagnostic];
    };
    $targets=[];
    foreach (['telegram','max'] as $channel) $targets[$channel]=static function() use (&$calls,$channel): array {$calls[]=['target',$channel];return photoChoiceRow($channel);};
    $prepare=static function(int $conversationId,array $snapshot) use (&$writes,$spec): array {
        $writes[]=['prepare',$conversationId,$snapshot];
        photoChoiceAssert($snapshot===TourChoiceService::normalize($spec['snapshot']) && $snapshot['is_demo']===$spec['snapshot']['is_demo'] && !$snapshot['price_verified'], 'Explicit canonical unverified quote snapshot is prepared exactly');
        return ['ok'=>true,'offer_id'=>2000+$conversationId,'callback_data'=>'tour_like_'.(2000+$conversationId).'_'.str_repeat('b',24)];
    };
    $delivered=static function(int $id,string $mid,string $chat) use (&$writes,$failure): array {
        $writes[]=['delivered',$id,$mid,$chat];
        photoChoiceAssert(($id===2001 && $mid==='201' && $chat==='881001') || ($id===2002 && $mid==='mid.explicit_choice' && $chat==='-770001'), 'Explicit native proof binds only exact offer/MID/chat');
        return ['ok'=>!($failure==='tg_binding' && $id===2001)];
    };
    $download=static function(int $index,string $dir) use (&$calls): array {
        $calls[]=['download',$index]; $path=$dir.'/.owner-preview-image-'.$index.'-'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($path,'explicit synthetic image '.$index);chmod($path,0600);return ['path'=>$path,'mime'=>'image/png'];
    };
    $upload=static function($url,$image) use (&$calls): array {
        $calls[]=['upload'];
        return ['data'=>['photos'=>[['token'=>'explicit-token-'.hash_file('sha256',$image['path'])]]],
            'diagnostic'=>['http_status'=>200,'curl_code'=>0,'error_category'=>'none']];
    };
    $sha=str_repeat('a',40);
    $out=ownerPhotoChoiceRun($sha,$sha,'anytour',['telegram'=>'PRIVATE_TG','max'=>'PRIVATE_MAX'],$targets,
        ['telegram'=>$requestTG,'max'=>$requestMAX],$directory,$prepare,$delivered,
        array_replace(['download'=>$download,'upload'=>$upload],$transferOverrides),$spec);
    photoChoiceAssert(!str_contains(json_encode($out),'PRIVATE_') && !str_contains(json_encode($out),'tour_like_') && !str_contains(json_encode($out),'881001'), 'Explicit public result retains private capability/identity boundary');
    return [$out,$calls,$directory,$writes];
}

if (!defined('OWNER_EXPLICIT_PHOTO_FIXTURE_LIBRARY')) {
photoChoiceAssert(ownerPhotoChoiceSpec(null)===null && ownerPhotoChoiceSnapshot()['is_demo']===true, 'Null specification preserves the original fixed demo profile');
foreach (['palace','imperial','be-grand','grand-park'] as $key) {
    $spec=explicitSpec($key);$spec['snapshot']['hotel']='  '.$spec['snapshot']['hotel'].'  ';
    $normalized=ownerPhotoChoiceSpec($spec);
    photoChoiceAssert($normalized['snapshot']===TourChoiceService::normalize($spec['snapshot']), 'Explicit canonical normalization happens before content and receipt hashing');
    $card=ownerPhotoChoiceCard('tour_like_2001_'.str_repeat('b',24),$spec);
    photoChoiceAssert(!str_contains($card['plain_text'],'Демо:') && str_contains($card['plain_text'],'Актуальность цены не проверена.')
        && str_contains($card['plain_text'],'Фото номера — пример интерьера отеля.'), 'Real-quote presentation stays unverified and room-photo illustrative');
    [$out,$calls,$directory,$writes]=explicitRun($spec);
    photoChoiceAssert($out['ok'] && photoChoiceSends($calls)===2 && count($writes)===4, 'Each fixed explicit profile delivers separate canonical snapshots to both owner chats: '.json_encode($out));
    photoChoiceAssert(!file_exists($directory.'/owner-photo-choice-20261021-telegram.json'), 'Explicit profile never creates or renames an original demo receipt');
    foreach (['telegram'=>1,'max'=>2] as $channel=>$id) {
        $record=explicitReceipt($directory,$channel,$spec);
        $hash=ownerSelectionHash(['snapshot'=>$normalized['snapshot'],'presentation'=>$normalized['presentation'],'identity'=>$record['identity'],'conversation_id'=>$id,'id'=>$spec['id']]);
        photoChoiceAssert($record['trial']===$spec['id'].'-'.$channel && $record['spec_sha256']===$hash, 'Explicit receipt binds canonical snapshot, presentation, identity and fixed profile ID');
    }
    [$again,$calls,$_,$writes]=explicitRun($spec,$directory);
    photoChoiceAssert($again['ok'] && photoChoiceSends($calls)===0 && count(array_filter($writes,static fn($x)=>$x[0]==='prepare'))===0, 'Verified explicit replay permits binding proof without another prepare or native creation');
    $changed=$spec;$changed['snapshot']['amount']='999999';
    [$again,$calls,$_,$writes]=explicitRun($changed,$directory);
    photoChoiceAssert(!$again['ok'] && photoChoiceSends($calls)===0 && $writes===[], 'Changed real conditions cannot reuse an explicit once receipt');
}

$spec=explicitSpec();[$out,$calls,$directory]=explicitRun($spec,null,'unknown');
[$again,$calls,$_,$writes]=explicitRun($spec,$directory);
photoChoiceAssert(!$again['ok'] && $again['state']==='unknown' && photoChoiceSends($calls)===0 && $writes===[], 'Unknown explicit creation permanently blocks both channels before new writes');
$guardCalls=0;
[$again,$calls,$_,$writes]=explicitRun($spec,$directory,'',['can_create'=>static function() use (&$guardCalls): bool { $guardCalls++; return false; }]);
photoChoiceAssert($again['state']==='unknown' && $guardCalls===0 && photoChoiceSends($calls)===0 && $writes===[], 'Unknown POST blocks before expiry guard and cannot become a fresh creation');
[$out,$calls,$directory]=explicitRun($spec,null,'changed_caption');
[$again,$calls,$_,$writes]=explicitRun($spec,$directory);
photoChoiceAssert(!$again['ok'] && photoChoiceSends($calls)===0 && $writes===[], 'Mismatched cached native explicit content cannot authorize another creation');

$expired=static fn(): bool=>false;
[$out,$calls,$directory,$writes]=explicitRun($spec,null,'',['can_create'=>$expired]);
photoChoiceAssert(!$out['ok'] && $out['state']==='quote_expired' && !$out['send_attempted'] && photoChoiceSends($calls)===0
    && count(array_filter($calls,static fn($x)=>in_array($x[0],['download','upload'],true)))===0 && $writes===[],
    'Expired fresh quote stops after receipt proof before images, snapshot prepare and native POST');
foreach (['telegram','max'] as $channel) photoChoiceAssert(explicitReceipt($directory,$channel,$spec)['state']==='unprepared', 'Expired fresh quote does not reserve native creation');

[$out,$calls,$directory]=explicitRun($spec);
$guardCalls=0;
[$again,$calls,$_,$writes]=explicitRun($spec,$directory,'',['can_create'=>static function() use (&$guardCalls): bool { $guardCalls++; return false; }]);
photoChoiceAssert($again['ok'] && $guardCalls===0 && photoChoiceSends($calls)===0
    && count(array_filter($writes,static fn($x)=>$x[0]==='delivered'))===2
    && count(array_filter($writes,static fn($x)=>$x[0]==='prepare'))===0,
    'Completed known-MID receipts still read and rebind after quote expiry without prepare or POST');

[$out,$calls,$directory]=explicitRun($spec,null,'tg_binding');
photoChoiceAssert($out['state']==='delivery_binding_unavailable' && photoChoiceSends($calls)===1
    && explicitReceipt($directory,'telegram',$spec)['state']==='sent_unverified'
    && explicitReceipt($directory,'max',$spec)['state']==='unprepared',
    'Delivery-binding failure leaves one known native MID and one genuinely unsent channel');
$unsentBefore=file_get_contents($directory.'/'.$spec['id'].'-max.json');
[$again,$calls,$_,$writes]=explicitRun($spec,$directory,'',['can_create'=>$expired]);
photoChoiceAssert(!$again['ok'] && $again['state']==='quote_expired' && !$again['send_attempted']
    && $again['cards']===['telegram'=>['ok'=>true,'state'=>'delivered']]
    && $writes===[['delivered',2001,'201','881001']] && photoChoiceSends($calls)===0
    && count(array_filter($calls,static fn($x)=>in_array($x[0],['download','upload'],true) || ($x[0]==='max' && $x[1]==='POST')))===0,
    'Expired mixed batch rebinds the known Telegram MID while blocking images, prepare and every new native POST');
photoChoiceAssert(explicitReceipt($directory,'telegram',$spec)['state']==='delivered'
    && file_get_contents($directory.'/'.$spec['id'].'-max.json')===$unsentBefore,
    'Expired mixed recovery persists the proven channel and preserves the unsent receipt byte-for-byte');

$guardCalls=0;
[$out,$calls,$directory,$writes]=explicitRun($spec,null,'',['can_create'=>static function() use (&$guardCalls): bool { return ++$guardCalls===1; }]);
photoChoiceAssert($out['state']==='quote_expired' && !$out['send_attempted'] && $guardCalls===2 && photoChoiceSends($calls)===0
    && count(array_filter($writes,static fn($x)=>$x[0]==='prepare'))===1
    && explicitReceipt($directory,'telegram',$spec)['state']==='prepared' && !explicitReceipt($directory,'telegram',$spec)['post_reserved'],
    'Expiry between prepare and creation is checked again before durable POST reservation');
[$again,$calls,$_,$writes]=explicitRun($spec,$directory,'',['can_create'=>$expired]);
photoChoiceAssert($again['state']==='quote_expired' && photoChoiceSends($calls)===0 && $writes===[]
    && count(array_filter($calls,static fn($x)=>$x[0]==='download'))===0,
    'Expired partially prepared receipt cannot download, prepare again or POST');

[$out,$calls,$directory,$writes]=explicitRun($spec,null,'',['can_create'=>null]);
photoChoiceAssert($out['state']==='invalid_creation_guard' && $calls===[] && $writes===[] && !file_exists($directory), 'Provided non-callable expiry guard fails before identity, filesystem and network work');

[$old,$_,$directory]=photoChoiceRun();$before=[];
foreach (['telegram','max'] as $channel) $before[$channel]=file_get_contents($directory.'/owner-photo-choice-20261021-'.$channel.'.json');
[$out,$calls]=explicitRun($spec,$directory);
photoChoiceAssert($out['ok'], 'A genuinely new fixed explicit profile may coexist with known original demo receipts');
foreach (['telegram','max'] as $channel) photoChoiceAssert(file_get_contents($directory.'/owner-photo-choice-20261021-'.$channel.'.json')===$before[$channel], 'Original completed receipts remain byte-identical');
foreach (['telegram','max'] as $channel) file_put_contents($directory.'/'.$spec['id'].'-'.$channel.'.json',$before[$channel]);
[$again,$calls,$_,$writes]=explicitRun($spec,$directory);
photoChoiceAssert(!$again['ok'] && photoChoiceSends($calls)===0 && $writes===[], 'An old demo receipt copied into an explicit filename is rejected, not migrated or replayed');

$invalid=[];$bad=$spec;$bad['id']='owner-photo-choice-20261021';$invalid[]=$bad;
$bad=$spec;$bad['id']='owner-live-quote-20261021-palace/../imperial';$invalid[]=$bad;
$bad=$spec;$bad['snapshot']['is_demo']=true;$invalid[]=$bad;
$bad=$spec;$bad['snapshot']['price_verified']=true;$invalid[]=$bad;
$bad=$spec;$bad['presentation']['photos'][0]['url']='https://example.com/photo.png';$invalid[]=$bad;
$bad=$spec;$bad['presentation']=explicitSpec('imperial')['presentation'];$invalid[]=$bad;
$bad=$spec;$bad['presentation']['inclusions']=['Неархивированные допуслуги'];$invalid[]=$bad;
$bad=$spec;$bad['presentation']['room_illustrative']=false;$invalid[]=$bad;
$bad=$spec;$bad['extra']='ignored';$invalid[]=$bad;
$bad=explicitSpec('grand-park');$bad['snapshot']['hotel']='Delphin Palace 5★';$invalid[]=$bad;
foreach ($invalid as $bad) {
    [$out,$calls,$directory,$writes]=explicitRun($bad);
    photoChoiceAssert(!$out['ok'] && $out['state']==='invalid_spec' && $calls===[] && $writes===[] && !file_exists($directory), 'Invalid explicit input fails before targets, provider calls, files and business writes');
}
foreach ($directories as $directory) if (is_dir($directory)) { foreach (glob($directory.'/*') ?: [] as $path) unlink($path); foreach (glob($directory.'/.owner-preview-image-*') ?: [] as $path) unlink($path); rmdir($directory); }
echo "EXPLICIT OWNER PHOTO CHOICE: $checks checks PASSED\n";
}
