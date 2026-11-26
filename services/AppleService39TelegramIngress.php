<?php
declare(strict_types=1);

require_once __DIR__.'/AppleService39TelegramConnection.php';
require_once __DIR__.'/ProjectTelegramMessengerFactory.php';
require_once __DIR__.'/AppleService39PilotCatalog.php';
require_once __DIR__.'/ConversationRecorder.php';
require_once __DIR__.'/ConversationControlService.php';
require_once __DIR__.'/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__.'/AppleService39TelegramDevicePublisher.php';
require_once __DIR__.'/AppleService39TelegramFunnel.php';

final class AppleService39TelegramIngress
{
    private const PROJECT='appleservice39';
    private const SOURCE='telegram:appleservice39-main';
    private const EVENT='repair_telegram_reply';

    public static function authenticated(?array $binding,string $secret):bool
    {
        return ($binding['project_key']??null)===self::PROJECT&&($binding['source_key']??null)===self::SOURCE
            &&strcasecmp((string)($binding['bot_username']??''),'Appleservice39_bot')===0
            &&is_string($binding['bot_id']??null)&&preg_match('/\A[1-9][0-9]{0,15}\z/D',$binding['bot_id'])===1
            &&is_string($binding['webhook_secret']??null)&&$secret!==''&&hash_equals($binding['webhook_secret'],$secret);
    }

    /** The HTTP entry point obtains the binding from the protected server configuration and authenticates it. */
    public static function handle(array $binding,array $update,?callable $factory=null,?callable $notify=null):int
    {
        $message=$update['message']??null;
        if(!is_array($message)||($message['chat']['type']??null)!=='private'||($message['from']['is_bot']??true)!==false
            ||isset($message['business_connection_id'])||isset($message['sender_chat']))return 200;
        $user=(string)($message['from']['id']??'');$mid=(string)($message['message_id']??'');
        if(!preg_match('/\A[1-9][0-9]{0,15}\z/D',$user)||(string)($message['chat']['id']??'')!==$user
            ||!preg_match('/\A[1-9][0-9]{0,15}\z/D',$mid))return 400;
        if(($binding['project_key']??null)!==self::PROJECT||($binding['source_key']??null)!==self::SOURCE)return 503;
        $incoming=TelegramIncomingAdapter::fromUpdate(['message'=>$message]);
        if(!is_array($incoming))return 400;
        $text=(string)($message['text']??'');
        $start=!isset($message['forward_origin'])&&!isset($message['via_bot'])
            &&preg_match('~\A/start(?:@([A-Za-z0-9_]+))?(?:\s+(\S+))?\s*\z~u',$text,$parts)===1
            &&(($parts[1]??'')===''||strcasecmp($parts[1],$binding['bot_username'])===0);
        $entry=null;
        if($start){
            $incoming['text']='/start'; // Opaque or mistyped starts never become a CRM attribution.
            $code=$parts[2]??'';
            if(preg_match('/\Aas39_([a-z0-9-]{1,59})\z/D',$code,$match)){
                $candidate=AppleService39PilotCatalog::landingEntryForPost($match[1]);
                if(($candidate['start_payload']??null)===$code)$entry=$candidate;
            }
        }
        $key=hash('sha256',implode("\0",[self::PROJECT,'telegram',$binding['bot_id'],$user,$mid]));
        $incoming['source_key']=self::SOURCE;$incoming['bot_key']=$binding['bot_id'];$incoming['delivery_key']=$key;
        $pdo=ConversationDb::connection();if($pdo->inTransaction())return 503;
        $lock='repair-tg:'.substr(hash('sha256',self::PROJECT."\0".$user),0,48);$locked=false;
        try{
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                $q=$pdo->prepare('SELECT GET_LOCK(?,3)');$q->execute([$lock]);$locked=(string)$q->fetchColumn()==='1';
                if(!$locked)return 503;
            }
            $pdo->beginTransaction();
            $q=$pdo->prepare("SELECT s.id FROM conversation_sources s JOIN projects p ON p.id=s.project_id
                WHERE p.project_key=? AND p.is_active=1 AND s.channel='telegram' AND s.source_key=? AND s.is_active=1 AND s.handling_mode IN ('manager','ask')");
            $q->execute([self::PROJECT,self::SOURCE]);$source=(int)$q->fetchColumn();
            if($source<=0||$source!==($binding['source_id']??null))throw new RuntimeException('source_unavailable');
            if($entry!==null&&($entry['scope']??null)!=='device'
                &&self::publishedPost($pdo,$entry,$binding,$binding['bot_id'])==='')$entry=null;
            // Message IDs are unique within one private chat, including a redelivery after that conversation was closed.
            $q=$pdo->prepare("SELECT c.id,c.source_id,c.metadata_json,m.metadata_json AS message_meta FROM conversations c JOIN messages m ON m.conversation_id=c.id
                WHERE c.project_key=? AND c.channel='telegram' AND c.external_chat_id=? AND m.direction='inbound' AND m.external_message_id=? LIMIT 2");
            $q->execute([self::PROJECT,$user,$mid]);$prior=$q->fetchAll(PDO::FETCH_ASSOC);
            if($prior){
                if(count($prior)!==1||(int)$prior[0]['source_id']!==$source
                    ||(json_decode((string)$prior[0]['metadata_json'],true)['telegram_bot_id']??null)!==$binding['bot_id']
                    ||(json_decode((string)$prior[0]['message_meta'],true)['delivery_key']??null)!==$key)throw new RuntimeException('binding_mismatch');
                $id=(int)$prior[0]['id'];$created=false;
            }else{
                $q=$pdo->prepare("SELECT c.id,c.metadata_json,c.source_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id
                    WHERE cc.project_key=? AND cc.channel='telegram' AND cc.external_user_id=? AND c.status<>'closed' ORDER BY c.id DESC LIMIT 1");
                $q->execute([self::PROJECT,$user]);$old=$q->fetch(PDO::FETCH_ASSOC);
                if($old&&((int)$old['source_id']!==$source||(json_decode((string)$old['metadata_json'],true)['telegram_bot_id']??null)!==$binding['bot_id']))throw new RuntimeException('binding_mismatch');
                $id=null;$duplicate=false;
                if(!ConversationRecorder::inboundForProject(self::PROJECT,$incoming,$id,$duplicate)||!$id||$duplicate)throw new RuntimeException('inbound_not_persisted');
                $q=$pdo->prepare("SELECT metadata_json FROM conversations WHERE id=? AND project_key=? AND channel='telegram' AND external_chat_id=? AND source_id=?");
                $q->execute([$id,self::PROJECT,$user,$source]);$raw=$q->fetchColumn();
                if(!is_string($raw)||!is_array($meta=json_decode($raw,true))||($old&&(int)$old['id']!==$id))throw new RuntimeException('binding_mismatch');
                $meta['telegram_bot_id']=$binding['bot_id'];
                $newEntry=$entry&&($meta['repair_context']['entry_code']??null)!==$entry['start_payload'];
                if($newEntry)$meta['repair_context']=['model'=>$entry['model'],'service'=>$entry['service'],'entry_code'=>$entry['start_payload']];
                $created=false;
                if(!ConversationControlService::markWaitingForProject(self::PROJECT,'telegram',$user,['source'=>'repair_telegram','source_key'=>self::SOURCE],$created,false))throw new RuntimeException('handoff_not_persisted');
                if($newEntry||$created){
                    $reply=$entry?AppleService39PilotCatalog::responseText($entry,'AppleService39'):'Здравствуйте! Ваше обращение в AppleService39 передано менеджеру. Можете написать, какая у вас модель и что произошло.';
                    ConversationControlService::event($id,self::EVENT,'system',null,['delivery_key'=>$key,'bot_id'=>$binding['bot_id'],'source_key'=>self::SOURCE,
                        'entry_code'=>$entry['start_payload']??'','post_id'=>$entry['post_id']??'','model'=>$entry['model']??'','service'=>$entry['service']??'',
                        'text'=>$reply,'state'=>'pending']);
                    if($entry)$meta['repair_telegram_entry_id']=(int)$pdo->lastInsertId();
                }
                if($entry && is_int($message['date']??null)){
                    AppleService39TelegramFunnel::recordAttribution($pdo,$binding,$id,$user,$entry,$message['date'],$mid);
                }
                $q=$pdo->prepare('UPDATE conversations SET metadata_json=?,entry_channel=? WHERE id=?');
                $q->execute([json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),self::SOURCE,$id]);
            }
            $pdo->commit();
            if(!$prior&&($created||!$start)){
                try{($notify??[ManagerPushService::class,'notifyConversation'])($id,$created?'Новая заявка ждёт менеджера':'Клиент написал в диалог',$mid);}catch(Throwable $ignored){}
            }
            self::deliver($pdo,$id,$binding['bot_id'],$key,$factory);
            return 200;
        }catch(Throwable $ignored){
            if($pdo->inTransaction())$pdo->rollBack();
            return 503;
        }finally{
            if($locked){try{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}catch(Throwable $ignored){}}
        }
    }

    private static function owner(PDO $pdo,int $id):?array
    {
        $q=$pdo->prepare("SELECT c.id,c.project_key,c.channel,c.source_id,c.external_chat_id,c.customer_id,c.customer_channel_id,c.status,c.manager_id,c.metadata_json
            FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key
                AND cc.channel=c.channel AND cc.external_user_id=c.external_chat_id AND cc.external_chat_id=c.external_chat_id
            JOIN conversation_sources s ON s.id=c.source_id AND s.channel=c.channel JOIN projects p ON p.id=s.project_id AND p.project_key=c.project_key
            WHERE c.id=? AND c.project_key=? AND c.channel='telegram' AND s.source_key=?");
        $q->execute([$id,self::PROJECT,self::SOURCE]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }

    private static function deliver(PDO $pdo,int $id,string $bot,string $key,?callable $factory):void
    {
        $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $keyExpr=$mysql?"JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.delivery_key'))":"json_extract(payload_json,'$.delivery_key')";
        $stateExpr=$mysql?"JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.state'))":"json_extract(payload_json,'$.state')";
        $q=$pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type=? AND $keyExpr=? ORDER BY id DESC LIMIT 2");
        $q->execute([$id,self::EVENT,$key]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$matches)return;
        if(count($matches)!==1)throw new RuntimeException('reply_ambiguous');
        $row=$matches[0];$raw=$row['payload_json'];$data=json_decode($raw,true);
        if(($data['delivery_key']??null)!==$key||($data['bot_id']??null)!==$bot)throw new RuntimeException('binding_mismatch');
        if(($data['state']??'')!=='pending')return;
        $owner=self::owner($pdo,$id);
        if(!$owner||(json_decode($owner['metadata_json'],true)['telegram_bot_id']??null)!==$bot)throw new RuntimeException('binding_mismatch');
        $active=$owner['status']!=='closed'&&($data['entry_code']!==''||$owner['status']==='waiting_manager');
        $adapter=$active?($factory??[ProjectTelegramMessengerFactory::class,'forConversation'])($owner,'system'):null;
        if($active&&!$adapter)throw new RuntimeException('connection_unavailable');
        $change="UPDATE conversation_events SET payload_json=? WHERE id=? AND conversation_id=? AND event_type=? AND $keyExpr=? AND $stateExpr=?";
        $eligible=$data['entry_code']!==''?"c.status IN ('waiting_manager','manager')":"c.status='waiting_manager' AND c.manager_id IS NULL";
        $claim=$pdo->prepare($change.($active?" AND EXISTS(SELECT 1 FROM conversations c WHERE c.id=conversation_events.conversation_id AND $eligible)":''));
        $data['state']=$active?'attempted':'suppressed';
        $claim->execute([json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$row['id'],$id,self::EVENT,$key,'pending']);
        if($claim->rowCount()!==1||!$active)return;
        try{$ok=$adapter->send($owner['external_chat_id'],htmlspecialchars($data['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));}catch(Throwable $ignored){$ok=false;}
        $native=$ok?$adapter->lastExternalMessageId():'';
        $pdo->beginTransaction();
        if($ok&&$native!==''&&!ConversationRecorder::outboundForConversation($id,'telegram',$data['text'],'system',null,['source'=>'repair_telegram','bot_key'=>$bot],$native))throw new RuntimeException('reply_not_persisted');
        $data['state']=$ok&&$native!==''?'delivered':'unknown';
        $cas=$pdo->prepare($change);
        $cas->execute([json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$row['id'],$id,self::EVENT,$key,'attempted']);
        if($cas->rowCount()!==1)throw new RuntimeException('reply_not_persisted');
        $pdo->commit();
    }

    /** Read the canonical post only after the native publisher verified this bot, source and message. */
    public static function publishedPost(PDO $pdo, array $entry, array $owner, string $bot): string
    {
        $campaign = ($entry['scope'] ?? null) === 'device' ? AppleService39TelegramDevicePublisher::DEVICE_CAMPAIGN
            : AppleService39TelegramCatalogue::CAMPAIGN;
        try {
            $q=$pdo->prepare("SELECT c.channel_id,c.connection_sha256,o.external_message_id,o.public_url
                FROM appleservice39_pilot_campaigns c JOIN appleservice39_pilot_operations o ON o.campaign_key=c.campaign_key
                WHERE c.campaign_key=? AND c.project_key=? AND c.bot_key='Appleservice39_bot'
                  AND c.source_id=? AND o.post_key=? AND o.operation='create' AND o.state='verified'");
            $q->execute([$campaign,self::PROJECT,$owner['source_id'],$entry['post_id']]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['connection_sha256']!==hash('sha256',$bot.'|'.$row['channel_id'].'|'.$owner['source_id'])
                ||!preg_match('/\A[1-9][0-9]*\z/D',(string)$row['external_message_id']))return '';
            $url='https://t.me/appleservice39/'.$row['external_message_id'];
            return $row['public_url']===$url?$url:'';
        } catch(PDOException $unavailable) {
            return '';
        }
    }

    /** Projection for an already authorized Manager2 conversation. */
    public static function managerContext(array $conversation):?array
    {
        if(($conversation['project_key']??null)!==self::PROJECT||($conversation['channel']??null)!=='telegram')return null;
        $pdo=ConversationDb::connection();$id=(int)($conversation['id']??0);$owner=self::owner($pdo,$id);
        if(!$owner||(int)$owner['source_id']!==(int)($conversation['source_id']??0)||(string)$owner['external_chat_id']!==(string)($conversation['external_chat_id']??''))return null;
        $meta=json_decode((string)$owner['metadata_json'],true);
        if(!is_array($meta)||empty($meta['repair_telegram_entry_id'])||empty($meta['telegram_bot_id']))return null;
        $q=$pdo->prepare('SELECT id,payload_json FROM conversation_events WHERE id=? AND conversation_id=? AND event_type=?');
        $q->execute([(int)$meta['repair_telegram_entry_id'],$id,self::EVENT]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $data=$row?json_decode((string)$row['payload_json'],true):null;
        if(!is_array($data)||($data['bot_id']??null)!==$meta['telegram_bot_id']||($data['source_key']??null)!==self::SOURCE)return null;
        $entry=AppleService39PilotCatalog::landingEntryForPost((string)($data['post_id']??''));
        if($entry===null||$entry['start_payload']!==($data['entry_code']??null)||$entry['model']!==($data['model']??null)
            ||$entry['service']!==($data['service']??null)||!in_array($data['state']??'',['pending','attempted','suppressed','delivered','unknown'],true)||!is_string($data['text']??null))return null;
        $postUrl=self::publishedPost($pdo,$entry,$owner,$meta['telegram_bot_id']);
        if(($entry['scope']??null)!=='device'&&$postUrl==='')return null;
        return ['event_id'=>(int)$row['id'],'model'=>$entry['model'],'service'=>$entry['service'],'post_url'=>$postUrl,
            'reply'=>['state'=>$data['state'],'text'=>$data['text']]];
    }
}
