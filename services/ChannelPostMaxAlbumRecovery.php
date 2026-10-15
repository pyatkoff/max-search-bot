<?php
declare(strict_types=1);
require_once __DIR__.'/ChannelPostAutomationService.php';

/** One reviewed recovery of the owner's existing SPB album; never a webhook replay. */
final class ChannelPostMaxAlbumRecovery
{
    private ChannelPostRegistryService $registry;
    private $read;
    private $process;
    public function __construct(ChannelPostRegistryService $registry, callable $read, callable $process)
    {
        $this->registry=$registry; $this->read=$read; $this->process=$process;
    }

    public function run(int $publicationId, bool $apply=false): array
    {
        $reads=0;
        try {
            $old=$this->registry->publication($publicationId);
            self::target($old);
            if (!$apply) return ['ok'=>true,'state'=>'preview','image_count'=>6,'provider_requests'=>0,'write_attempts'=>0];
            $mid=(string)$old['external_message_id'];
            $reads=1;
            $response=($this->read)($mid);
            self::check(is_array($response)&&empty($response['transport_error'])&&(int)($response['http']??0)>=200&&(int)($response['http']??0)<300&&is_array($response['data']??null),'provider_read_failed');
            $message=$response['data'];
            self::check(($message['body']['mid']??'')===$mid&&(string)($message['recipient']['chat_id']??'')==='-77309177991187'&&($message['recipient']['chat_type']??'')==='channel','provider_post_identity_mismatch');
            $snapshot=ChannelPostIncomingAdapter::maxSnapshot($message);
            self::check(is_array($snapshot)&&($snapshot['metadata']['capability']??'')==='supported','fresh_post_unsupported');
            $hash=ChannelPostIncomingAdapter::contentFingerprint($snapshot,'',(string)$old['channel']['bot_url']);
            self::check(!hash_equals((string)$old['content_hash'],$hash),'new_snapshot_version_required');
            $observedAt=(string)(int)floor(microtime(true)*1000);
            $snapshot['content_hash']=$hash;
            $snapshot['metadata']['observation_kind']='provider_read';
            $snapshot['metadata']['observed_at_ms']=$observedAt;
            $snapshot['metadata']['source_revision_ms']=$observedAt;
            $snapshot['metadata']['source_revision_scope']='provider_read_observation_time';
            $snapshot['metadata']['source_event_id']='provider_read:'.$publicationId.':'.bin2hex(random_bytes(12));
            $before=$this->registry->publication($publicationId); self::target($before);
            self::check((int)$before['version_id']===(int)$old['version_id']&&hash_equals((string)$before['content_hash'],(string)$old['content_hash'])&&(int)$before['channel']['config_version']===(int)$old['channel']['config_version'],'target_changed_during_read');
            $fresh=$this->registry->registerPublication('anytour','max','id9704048781_1_bot','-77309177991187',$mid,$snapshot);
            self::check(is_array($fresh)&&(int)$fresh['publication_id']===$publicationId&&(int)$fresh['version_number']===3&&(int)$fresh['version_id']!==(int)$old['version_id']&&$fresh['start_payload']===$old['start_payload']&&hash_equals($hash,(string)$fresh['content_hash'])&&$fresh['state']==='pending'&&empty($fresh['stale_observation'])&&empty($fresh['duplicate_event']),'fresh_version_not_registered');
            $operation=($this->process)($publicationId,(int)$fresh['version_id']);
            $receipt=(array)($operation['receipt']??[]);
            $reason=(string)($operation['reason']??'operation_unavailable');
            if(!preg_match('/\A[a-zA-Z0-9_-]{1,96}\z/D',$reason))$reason='operation_unavailable';
            $safe=array_intersect_key($receipt,array_flip(['request_count','write_attempts','http_results','content_preserved','button_verified']));
            return ['ok'=>($operation['state']??'')==='sent','state'=>in_array($operation['state']??'', ['sent','unknown','requires_attention'],true)?$operation['state']:'unknown','reason'=>$reason,'image_count'=>count($snapshot['attachments']),'version_number'=>3,'provider_requests'=>1+(int)($receipt['request_count']??0),'receipt'=>$safe];
        } catch (ChannelPostMaxAlbumRecoveryFailure $e) {
            return ['ok'=>false,'state'=>'blocked','reason'=>$e->getMessage(),'provider_requests'=>$reads,'write_attempts'=>0];
        } catch (Throwable $e) {
            // A registration may have committed; re-read the existing record, never repeat apply.
            return ['ok'=>false,'state'=>'unknown','reason'=>'recovery_unavailable','provider_requests'=>$reads];
        }
    }

    private static function check(bool $ok,string $reason): void
    {
        if(!$ok)throw new ChannelPostMaxAlbumRecoveryFailure($reason);
    }
    private static function target(?array $p): void
    {
        self::check(is_array($p),'target_unavailable');
        self::check(($p['project_key']??'')==='anytour'&&($p['platform']??'')==='max'&&($p['bot_key']??'')==='id9704048781_1_bot'&&($p['channel_id']??'')==='-77309177991187'&&($p['source_key']??'')==='max_anytour_spb','target_identity_changed');
        self::check((int)($p['version_number']??0)===2&&($p['state']??'')==='requires_attention'&&($p['error_reason']??'')==='album_not_supported'&&(int)($p['write_attempts']??-1)===0,'target_state_changed');
        $s=(array)($p['snapshot']??[]);$a=(array)($s['attachments']??[]);
        self::check(count($a)===6&&($s['metadata']['capability_reason']??'')==='album_not_supported','target_snapshot_changed');
        foreach($a as $item)self::check(is_array($item)&&($item['type']??'')==='image'&&($item['provider']??'')==='max'&&preg_match('/\A[0-9]+\z/D',(string)($item['photo_id']??''))===1,'target_snapshot_changed');
        self::check(preg_match('/\A[a-f0-9]{64}\z/D',(string)($p['content_hash']??''))===1,'target_hash_invalid');
        $raw=(array)($s['raw_body']??[]);
        self::check(($raw['body']['mid']??'')===($p['external_message_id']??null)&&(string)($raw['recipient']['chat_id']??'')==='-77309177991187'&&($raw['recipient']['chat_type']??'')==='channel','target_snapshot_identity_changed');
        $url=(string)($s['post_url']??'');
        self::check($url===''||$url==='https://max.ru/c/-77309177991187/AaE-mekYJW0','target_post_url_changed');
    }
}
final class ChannelPostMaxAlbumRecoveryFailure extends RuntimeException {}
