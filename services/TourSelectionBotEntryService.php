<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionShareService.php';
require_once __DIR__.'/ConversationRecorder.php';
require_once __DIR__.'/ManagerHandoffDispatchService.php';

/** A native sender, never the public URL holder, owns an explicit inquiry. */
final class TourSelectionBotEntryService
{
    public static function payload(array $incoming): string
    {
        if (($incoming['platform']??'')==='max' && ($incoming['type']??'')==='bot_started') {
            $value=(string)($incoming['start_payload']??'');return str_starts_with($value,'ts_')||str_starts_with($value,'s3_')?$value:'';
        }
        if (($incoming['platform']??'')==='telegram' && ($incoming['type']??'')==='message'
            && preg_match('~\A/start(?:@[A-Za-z0-9_]+)?\s+((?:ts_|s3_)\S*)\s*\z~u',trim((string)($incoming['text']??'')),$m)) return $m[1];
        return '';
    }

    private static function entry(string $platform,string $payload,bool $lock=false): ?array
    {
        if (!str_starts_with($payload,'s3_')) return ManagerTourSelectionShareService::botEntry($platform,$payload);
        require_once __DIR__.'/Search3TourHandoffService.php';
        return Search3TourHandoffService::botEntry($platform,$payload,$lock);
    }

    private static function identity(array $in): bool
    {
        $raw=$in['raw']??[];$user=$in['user']??[];$external=(string)($user['external_user_id']??'');$chat=(string)($user['chat_id']??'');
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/D',$external)) return false;
        if (($in['platform']??'')==='telegram') {
            $message=$raw['message']??[];
            if (($message['chat']['type']??'')!=='private' || (string)($message['chat']['id']??'')!==$external || (string)($message['from']['id']??'')!==$external || $chat!==$external
                || (string)($message['text']??'')!==($in['text']??'') || !empty($message['from']['is_bot'])) return false;
            if (preg_match('~\A/start@([A-Za-z0-9_]+)~',trim($in['text']),$m)) {
                $identity=ChannelPostRuntime::identity('telegram');if (!$identity || strcasecmp($m[1],$identity['bot_key'])!==0) return false;
            }
            return true;
        }
        return ($in['platform']??'')==='max' && ($raw['update_type']??'')==='bot_started'
            && (string)($raw['user']['user_id']??$raw['user']['id']??'')===$external && $chat==='-'.$external
            && (string)($raw['payload']??$raw['start_payload']??'')===self::payload($in) && empty($raw['user']['is_bot']);
    }

    private static function target(PDO $pdo,array $in): ?array
    {
        $q=$pdo->prepare("SELECT c.id,c.status,c.manager_id,c.source_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel JOIN projects p ON p.project_key=c.project_key AND p.is_active=1 JOIN conversation_sources s ON s.id=c.source_id AND s.is_active=1 WHERE c.project_key=? AND c.channel=? AND c.external_chat_id=? AND cc.external_user_id=? AND c.status IN ('ai','waiting_manager','manager') ORDER BY c.id DESC LIMIT 1");
        $q->execute([ProjectConfig::projectId(),$in['platform'],(string)$in['user']['chat_id'],(string)$in['user']['external_user_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
    }

    /** No provider/queue calls. The caller receives success only after commit. */
    public static function accept(array $in): array
    {
        $payload=self::payload($in);
        if (!$payload || !self::identity($in)) return ['ok'=>false,'error'=>'invalid_identity'];
        $entry=self::entry($in['platform'],$payload);
        if (!$entry) return ['ok'=>false,'error'=>'unavailable'];
        $pdo=ConversationDb::connection();$target=self::target($pdo,$in);
        if (!$target) return ['ok'=>false,'error'=>'conversation_unavailable'];
        if ($pdo->inTransaction()) throw new LogicException('Bot entry owns its transaction');
        $pdo->beginTransaction();
        try {
            $search=($entry['origin']??'')==='search3';
            if ($search&&!Search3TourHandoffService::lockProject($pdo)) {$pdo->rollBack();return ['ok'=>false,'error'=>'unavailable'];}
            // Same lock order as canonical manager writes: account before
            // conversations. The identity is private server-resolved context.
            if(!$search&&!empty($entry['contact_manager_id'])) {
                $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                $q=$pdo->prepare('SELECT id FROM managers WHERE id=?'.$suffix);$q->execute([$entry['contact_manager_id']]);
            }
            $ids=array_unique(array_filter([(int)($entry['conversation_id']??0),(int)$target['id']]));sort($ids,SORT_NUMERIC);
            $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            foreach ($ids as $id) {$q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$suffix);$q->execute([$id]);}
            $fresh=self::entry($in['platform'],$payload,$search);$actual=self::target($pdo,$in);
            if ($fresh!==$entry || !$actual || (int)$actual['id']!==(int)$target['id']) {$pdo->rollBack();return ['ok'=>false,'error'=>'unavailable'];}
            $method=$search?'searchInquiryInTransaction':'webInquiryInTransaction';
            $result=TourChoiceService::$method($pdo,(int)$actual['id'],(string)$in['user']['external_user_id'],hash('sha256',$payload),$fresh);
            if (!$result['ok']) {$pdo->rollBack();return $result;}
            $referral=$search?null:ManagerConversationService::assignSelectionReferralInTransaction((int)$actual['id'],(int)$fresh['conversation_id'],(int)$fresh['contact_manager_id']);
            if ($result['changed']) {
                // A labelled projection of the verified native action, not a
                // fabricated literal message or a native callback/delivery.
                // The existing Inbox/read/reply owners consume this same store.
                $now=gmdate('Y-m-d H:i:s');
                $metadata=$search?['type'=>'tour_search_inquiry','native_event_type'=>$in['type'],'handoff_id'=>$fresh['handoff_id']]
                    :['type'=>'tour_web_inquiry','native_event_type'=>$in['type'],'publication_id'=>$fresh['publication_id'],'variant'=>$fresh['card']+1,'link_version'=>$fresh['version']];
                $assignmentNotice='';
                if(!$search&&!in_array($referral['state'],['assigned','same_manager'],true)) {
                    $metadata['selection_manager_assignment']=$referral['state'];
                    $assignmentNotice=' · Назначение менеджеру подборки требует проверки.';
                }
                $label=$search?'Обращение из поиска: ':'Обращение из веб-подборки · вариант '.($fresh['card']+1).': ';
                $q=$pdo->prepare('INSERT INTO messages(conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $q->execute([$actual['id'],'inbound','customer',(string)$in['user']['external_user_id'],$in['platform'],null,
                    $label.$fresh['public_offer']['hotel'].$assignmentNotice,
                    json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
                $q=$pdo->prepare('UPDATE conversations SET last_message_at=? WHERE id=?');$q->execute([$now,$actual['id']]);
            }
            $pdo->commit();return $result+['previous_status'=>$actual['status'],'public_offer'=>$fresh['public_offer']]+($search?[]:['selection_manager'=>$referral]);
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    /** Save the rejected-entry acknowledgement intent before its single send. */
    private static function unavailable(array $in,?callable $feedback): void
    {
        $pdo=ConversationDb::connection();$pdo->beginTransaction();
        try {
            $target=self::target($pdo,$in);
            if (!$target) {$pdo->rollBack();return;}
            $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$suffix);$q->execute([$target['id']]);
            $key=hash('sha256',self::payload($in));
            $json=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.entry_key'))":"JSON_EXTRACT(payload_json,'$.entry_key')";
            $q=$pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='tour_web_entry_rejected' AND ".$json.'=? LIMIT 1');$q->execute([$target['id'],$key]);
            if ($q->fetchColumn()) {$pdo->commit();return;}
            $q=$pdo->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(?,?,?,?,?,?)');
            $q->execute([$target['id'],'tour_web_entry_rejected','customer',(string)$in['user']['external_user_id'],json_encode(['schema'=>1,'entry_key'=>$key],JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')]);
            $pdo->commit();
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
        $text=str_starts_with(self::payload($in),'s3_')
            ?'Этот вариант из поиска не сохранён: ссылка недоступна или истекла. Откройте свежую ссылку либо напишите менеджеру в этом диалоге.'
            :'Этот вариант не сохранён: подборка обновлена или закрыта. Откройте свежую ссылку либо напишите менеджеру в этом диалоге.';
        try {if ($feedback) $feedback($in,$text);else IntegrationRegistry::messenger()->send($in['user']['chat_id'],$text);} catch (Throwable $ignored) {}
    }

    public static function handle(array $in,?callable $handoff=null,?callable $feedback=null): bool
    {
        $payload=self::payload($in);if (!$payload) return false;
        // Consume this family even on failure: never reset the trip or source.
        if (!self::identity($in)) return true;
        $entry=self::entry($in['platform'],$payload);
        $pdo=ConversationDb::connection();
        if (!self::target($pdo,$in)) {
            $record=$in;$record['type']=$in['platform']==='max'?'bot_started':'start';
            $record['source_key']=$in['platform']==='telegram'
                ? (string)ProjectConfig::get('messenger.telegram.source_key','tg:anytour-main')
                : (string)ProjectConfig::get('routing.source_key','max:default');
            ConversationRecorder::inbound($record);
        }
        if (!$entry) {self::unavailable($in,$feedback);return true;}
        $result=self::accept($in);
        if (!$result['ok']) {
            if ($result['error']==='unavailable') {self::unavailable($in,$feedback);return true;}
            throw new RuntimeException('selection_entry_not_saved');
        }
        $chat=$in['user']['chat_id'];$platform=$in['platform'];
        // A retry after save may finish a missing handoff. Canonical queue state
        // suppresses repeated greetings, assignments and notifications.
        if ($handoff) $handoff($in,$result);
        else {
            $handoffResult=ManagerHandoffDispatchService::dispatch($chat,$platform,'',false,null,true);
            if (empty($handoffResult['queue_applied'])) throw new RuntimeException('selection_entry_handoff_pending');
        }
        $assigned=!empty($result['selection_manager']['assigned']);
        if ((!empty($result['changed']) && in_array($result['previous_status'],['waiting_manager','manager'],true))||$assigned) {
            $text='Обращение по варианту «'.htmlspecialchars($result['public_offer']['hotel'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'» сохранено. Менеджер увидит показанные условия.';
            // Durable inquiry is the once intent; unknown feedback is not replayed.
            if(!empty($result['changed']))try {if ($feedback) $feedback($in,$text);else IntegrationRegistry::messenger()->send($chat,$text);} catch (Throwable $ignored) {}
            try {ManagerPushService::notifyConversation((int)$result['conversation_id'],str_starts_with($payload,'s3_')?'Обращение из поиска':'Обращение по веб-подборке');} catch (Throwable $ignored) {}
        }
        return true;
    }
}
