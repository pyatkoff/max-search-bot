<?php
declare(strict_types=1);
require_once __DIR__.'/ProjectFunnelEntry.php';
require_once __DIR__.'/ConversationRecordClock.php';

/** Attribution joins the existing conversation history; a miniapp view creates no lead. */
final class ProjectFunnelConversation
{
    public static function entry(PDO $pdo,ProjectMaxConnection $connection,int $conversationId,string $user,string $source,string $post,string $entryKey,int $now): ?array
    {
        $settings=ProjectFunnelSettings::load($connection);
        if(!$settings||$settings['source_key']!==$source||!isset($settings['posts'][$post]))return null;
        $q=$pdo->prepare("SELECT c.metadata_json FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel AND cc.external_chat_id=c.external_chat_id WHERE c.id=? AND c.project_key=? AND c.channel='max' AND cc.external_user_id=? AND c.external_chat_id=? LIMIT 1");
        $q->execute([$conversationId,$connection->projectKey(),$user,'-'.$user]);$owner=$q->fetchColumn();$meta=is_string($owner)?json_decode($owner,true):null;
        if(!is_array($meta)||($meta['max_bot_key']??null)!==$connection->botKey())return null;
        $destination=(new ProjectFunnelEntry($pdo,$connection,$settings))->destination($post);
        $scope=ProjectFunnelEntry::scope($settings);
        $q=$pdo->prepare("SELECT * FROM advertising_visit_observations WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND external_user_id=? AND identity_status='verified' AND post_ref=? AND offer_ref=? AND visit_at_unix>=? AND visit_at_unix<=? ORDER BY id DESC LIMIT 51");
        $q->execute([...array_values($scope),$user,$post,$destination['offer_id'],$now-86400,$now]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$visits=[];
        foreach($rows as$row)if(!isset($visits[$row['visit_id']]))$visits[$row['visit_id']]=$row;
        $state=count($rows)>=51?'history_limit':(count($visits)>1?'ambiguous':($visits?'matched':'unattributed'));
        $observations=[];
        if($state==='matched'){
            $row=reset($visits);
            $observations[]=['source_cursor'=>(string)$row['source_cursor'],'visit_id'=>$row['visit_id']];
        }
        $payload=ConversationRecordClock::stamp(['version'=>1,'project_key'=>$connection->projectKey(),'bot_key'=>$connection->botKey(),
            'source_key'=>$source,'advertising_scope'=>$scope,'external_user_id'=>$user,'entry_key'=>$entryKey,
            'post_id'=>$post,'offer_id'=>$destination['offer_id'],'post_url'=>$destination['url'],'post_title'=>$destination['title'],
            'attribution_state'=>$state,'observations'=>$observations],$now);
        $q=$pdo->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json,created_at) VALUES (?,?,?,?,?)');
        $q->execute([$conversationId,'project_funnel_entry','customer',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$now)]);
        return $payload;
    }

    /** Caller has already authorized this conversation. Never return advertising identities to the UI. */
    public static function managerContext(PDO $pdo,array $conversation): ?array
    {
        if(($conversation['channel']??'')!=='max'||(int)($conversation['id']??0)<=0)return null;
        $connection=ProjectMaxConnection::forConversation($conversation);$settings=$connection?ProjectFunnelSettings::load($connection):null;
        if(!$settings)return null;
        $q=$pdo->prepare("SELECT e.id,e.payload_json FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE c.id=? AND c.project_key=? AND c.channel='max' AND e.event_type='project_funnel_entry' ORDER BY e.id DESC LIMIT 1");
        $q->execute([$conversation['id'],$connection->projectKey()]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $data=$row?json_decode($row['payload_json'],true):null;
        if(!is_array($data)||($data['version']??null)!==1||($data['project_key']??null)!==$connection->projectKey()
            ||($data['bot_key']??null)!==$connection->botKey()||!isset($settings['posts'][$data['post_id']??''])
            ||!is_string($data['post_url']??null)||!preg_match('~\A'.preg_quote($settings['channel_url'],'~').'/[A-Za-z0-9_-]{1,191}\z~D',$data['post_url']))return null;
        return ['event_id'=>(int)$row['id'],'title'=>$data['post_title'],'post_url'=>$data['post_url'],
            'attribution_state'=>$data['attribution_state'],'recorded_at_unix'=>ConversationRecordClock::read($row['payload_json'])];
    }
}
