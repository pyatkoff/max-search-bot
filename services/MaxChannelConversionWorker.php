<?php
declare(strict_types=1);
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/MaxTrackerLookupClient.php';
require_once __DIR__.'/MetrikaOfflineClient.php';

/** Bounded background work. All outputs are aggregate counts, never identities. */
final class MaxChannelConversionWorker
{
    public static function tick(?PDO $pdo=null,$lookup=null,$api=null,?int $now=null,?callable $collector=null): array
    {
        $s=MaxChannelConversionOutbox::settings($pdo);$result=['mode'=>$s['mode']??'off','collected'=>0,'resolved'=>0,'accepted'=>0,'processed'=>0,'unknown'=>0,'errors'=>0];
        if(!$s||$s['mode']==='off')return $result;
        $pdo=$pdo??ConversationDb::connection();$now=$now??time();$repo=new MaxChannelConversionOutbox($pdo);
        $repo->recover($now);
        if($collector)$result['collected']=$repo->collect($s,$collector);
        $lookup=$lookup??new MaxTrackerLookupClient();
        $pending=$repo->pending($now);$users=array_values(array_unique(array_column($pending,'external_user_id')));
        if($users){
            try{
                $identities=$lookup->lookup($s['counter_id'],$users);
                foreach($pending as $row){$record=$identities[$row['external_user_id']]??null;$repo->resolve((string)$row['id'],$record,$now);if($record)$result['resolved']++;}
            }catch(Throwable $ignored){$result['errors']++;}
        }
        // collect mode proves lookup and durable capture without Metrika POSTs.
        if($s['mode']!=='send')return $result;
        if(!$api){
            if(!defined('METRIKA_COUNTER_ID')||trim((string)METRIKA_COUNTER_ID)!==$s['counter_id']||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('metrika_config_missing');
            $api=new MetrikaOfflineClient($s['counter_id'],(string)METRIKA_OAUTH_TOKEN);
        }
        foreach($repo->rows('ready',$now,5) as $row){
            // Collection can capture an event after this tick's clock snapshot.
            if((int)$row['event_unix']>$now)continue;
            $token=$repo->claim((string)$row['id'],$now);if($token===null)continue;
            try{$response=$api->upload($row,$now);}catch(Throwable $ignored){$response=['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];}
            $repo->finish((string)$row['id'],$token,$response,$now);
            if(($response['outcome']??'')==='accepted')$result['accepted']++;
            elseif(($response['outcome']??'')==='unknown')$result['unknown']++;
            else $result['errors']++;
        }
        foreach(['accepted','unknown'] as $state)foreach($repo->rows($state,$now,3) as $row){
            try{
                $status=$state==='accepted'?$api->poll((string)$row['upload_id']):$api->findUnknown($row);
                if($status){$repo->status($row,$status,$now);if(($status['status']??'')==='PROCESSED')$result['processed']++;}
                else $repo->defer((string)$row['id'],$state,$now+1800);
            }catch(Throwable $ignored){$repo->defer((string)$row['id'],$state,$now+1800);$result['errors']++;}
        }
        return $result;
    }
}
