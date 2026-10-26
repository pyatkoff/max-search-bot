<?php
declare(strict_types=1);
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/MaxTrackerLookupClient.php';
require_once __DIR__.'/MetrikaOfflineClient.php';
require_once __DIR__.'/TelegramDialogueConversions.php';

/** Bounded background work. All outputs are aggregate counts, never identities. */
final class MaxChannelConversionWorker
{
    public static function tick(?PDO $pdo=null,$lookup=null,$api=null,?int $now=null,?callable $collector=null): array
    {
        $s=MaxChannelConversionOutbox::settings($pdo);$result=['mode'=>$s['mode']??'off','collected'=>0,'resolved'=>0,'accepted'=>0,'processed'=>0,'unknown'=>0,'errors'=>0];
        $tg=TelegramDialogueConversions::settings($pdo);$tgActive=$tg&&$tg['telegram_mode']!=='off';
        $result['telegram']=['mode'=>$tg['telegram_mode']??'off','collected'=>0,'resolved'=>0,'accepted'=>0,'processed'=>0,'unknown'=>0,'errors'=>0];
        $maxActive=$s&&$s['mode']!=='off';
        if(!$maxActive&&!$tgActive)return $result;
        $pdo=$pdo??ConversationDb::connection();$now=$now??time();$repo=new MaxChannelConversionOutbox($pdo);
        $repo->recover($now);
        $shared=$maxActive&&MaxChannelConversionOutbox::sharedGoalsActive($s);
        if($collector&&$shared)$result['collected']=$repo->collect($s,$collector);
        $lookup=$lookup??new MaxTrackerLookupClient();
        $pending=$shared?$repo->pending($now):[];$users=array_values(array_unique(array_column($pending,'external_user_id')));
        if($users){
            try{
                $identities=$lookup->lookup($s['counter_id'],$users);
                foreach($pending as $row){$record=$identities[$row['external_user_id']]??null;$repo->resolve((string)$row['id'],$record,$now);if($record)$result['resolved']++;}
            }catch(Throwable $ignored){$result['errors']++;}
        }
        if($tgActive){
            try{
                $bridge=new TelegramDialogueConversions($pdo);
                $result['telegram']['collected']=$bridge->collect($tg,$now);
                foreach($repo->pending($now,25,'telegram')as$row)if($bridge->resolve($row,$now))$result['telegram']['resolved']++;
            }catch(Throwable $ignored){$result['telegram']['errors']++;}
        }
        // Each platform has an independent opt-in. One existing worker and wire protocol.
        $platforms=[];
        if($maxActive&&$s['mode']==='send')$platforms[]='max';
        if($tgActive&&$tg['telegram_mode']==='send')$platforms[]='telegram';
        if(!$platforms)return $result;
        if(!$api){
            if(!defined('METRIKA_COUNTER_ID')||trim((string)METRIKA_COUNTER_ID)!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('metrika_config_missing');
            $api=new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN);
        }
        foreach($platforms as$platform)foreach(($platform==='telegram'||$shared)?$repo->rows('ready',$now,5,$platform):[] as $row){
            // Collection can capture an event after this tick's clock snapshot.
            if((int)$row['event_unix']>$now)continue;
            $token=$repo->claim((string)$row['id'],$now,$platform);if($token===null)continue;
            $row=$repo->claimedEvent((string)$row['id'],$token,$platform);if(!$row)continue;
            if($platform==='telegram'&&!$bridge->canSend($row)){
                $repo->finish((string)$row['id'],$token,['outcome'=>'rejected','reason'=>'identity_scope_unavailable'],$now);$result['telegram']['errors']++;continue;
            }
            try{$response=$api->upload($row,$now);}catch(Throwable $ignored){$response=['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];}
            $repo->finish((string)$row['id'],$token,$response,$now);
            if($platform==='telegram')$stats=&$result['telegram'];else $stats=&$result;
            if(($response['outcome']??'')==='accepted')$stats['accepted']++;
            elseif(($response['outcome']??'')==='unknown')$stats['unknown']++;
            else $stats['errors']++;
            unset($stats);
        }
        foreach(['accepted','unknown'] as $state){
        foreach($platforms as$platform)foreach($repo->rows($state,$now,3,$platform) as $row){
            if($platform==='telegram')$stats=&$result['telegram'];else $stats=&$result;
            try{
                $status=$state==='accepted'?$api->poll((string)$row['upload_id']):$api->findUnknown($row);
                if($status){$repo->status($row,$status,$now);if(($status['status']??'')==='PROCESSED')$stats['processed']++;}
                else $repo->defer((string)$row['id'],$state,$now+1800);
            }catch(Throwable $ignored){$repo->defer((string)$row['id'],$state,$now+1800);$stats['errors']++;}
            unset($stats);
        }}
        return $result;
    }
}

