<?php
declare(strict_types=1);

require_once __DIR__.'/HotelTransferPriceLineage.php';
require_once __DIR__.'/ChannelPostRegistryService.php';

/**
 * Private, read-only input adapter for a future v2 transfer. No route, reservation,
 * provider call, DB write, journal creation or standalone runtime entry point.
 *
 * The existing editor must provide its own already opened PRICE/CONTENT lease
 * and retain it through the later CAS and write. This adapter reasserts that
 * exact lease, never creates/replaces/releases it. Production callers must use
 * the existing canonical operations root, not request-controlled paths.
 *
 * A candidate is not permission to write: the owner must still authenticate
 * installed PRICE source/renderer provenance, use materialize with the existing public reader,
 * hold the existing target SQL lock and use the canonical
 * reservation/assert/complete/transport boundaries. Never invoke for old v1
 * batches or an already touched target.
 */
final class HotelTransferPriceEvidenceReader
{
    private const JOURNAL_LIMIT=10_000_000;
    private const TOTAL_LIMIT=32_000_000;
    private const GLOBAL_LIMIT=128_000_000;
    private const LEASE='monitor-price-after-collection-v1.jsonl';

    private static function need(bool $ok,string $reason):void
    {
        if(!$ok)throw new RuntimeException('transfer_evidence_'.$reason);
    }

    private static function sameFile($h,string $path):bool
    {
        clearstatcache(true,$path);
        if(!is_resource($h)||is_link($path)||realpath($path)!==$path)return false;
        $a=fstat($h);$b=@lstat($path);
        return is_array($a)&&is_array($b)&&($a['mode']&0170000)===0100000
            &&($b['mode']&0170000)===0100000&&$a['dev']===$b['dev']&&$a['ino']===$b['ino'];
    }

    /** Reassert the existing lease on the same descriptor. It remains caller-owned. */
    private static function lease(string $root,$lease):void
    {
        self::need(realpath($root)===$root&&is_dir($root)&&!is_link($root)
            &&self::sameFile($lease,$root.'/'.self::LEASE)
            &&flock($lease,LOCK_EX|LOCK_NB)
            &&self::sameFile($lease,$root.'/'.self::LEASE),'lease_unavailable');
    }

    private static function globalStat(string $root,$lease):array
    {
        $path=$root.'/'.self::LEASE;
        self::need(self::sameFile($lease,$path),'global_journal_changed');
        $opened=fstat($lease);$named=lstat($path);$out=[];
        foreach(['dev','ino','size','mtime','ctime'] as $field){
            self::need(is_array($opened)&&is_array($named)&&isset($opened[$field],$named[$field])
                &&$opened[$field]===$named[$field],'global_journal_changed');
            $out[$field]=$opened[$field];
        }
        return $out;
    }

    /** Reuse the installed PRICE reader, not a last-line-only inference. */
    private static function priceHistory(string $root,$lease):array
    {
        $stat=self::globalStat($root,$lease);
        self::need($stat['size']>0&&$stat['size']<=self::GLOBAL_LIMIT,'global_journal_size');
        $history=monitor_hook_read_history($lease);
        $state=$history['state'];
        self::need(self::globalStat($root,$lease)===$stat
            &&$history['pin']['bytes']===$stat['size']&&$history['trailing_blank_lines']===0
            &&is_array($state)&&($state['schema']??null)===1
            &&array_key_exists('pending',$state)&&$state['pending']===null
            &&is_array($state['completed']??null)
            &&(!array_key_exists('holds',$state)||is_array($state['holds']))
            &&($state['nightly_cycle']['state']??null)!=='started','global_price_unresolved');
        return ['state'=>$state,'pin'=>$history['pin'],'stat'=>$stat];
    }

    /** The most recent source PRICE revision must have the corresponding
     * completed pair/feed state, not merely a successful Telegram child.
     * Earlier child receipts remain the separate lineage proof.
     */
    private static function priceProof(array $history,array $row,array $candidate):array
    {
        $state=$history['state'];$hotel=$row['hotel_id'];
        self::need(!array_key_exists($hotel,$state['holds']??[]),'hotel_price_held');
        $completion=null;
        if($candidate['price_lineage']!==[]){
            $last=end($candidate['price_lineage']);
            $operation='commercial-monitor-price-h'.$hotel.'-'.$last['record_id'];
            $receipt=$state['completed'][$hotel]??null;
            $feed=is_array($receipt)?($receipt['feed_operation']??null):null;
            self::need(is_array($receipt)&&($receipt['operation']??null)===$operation
                &&is_string($feed)&&preg_match('/\Acommercial-channel-hotel-feeds-([0-9]{8})-update[0-9]+\z/D',$feed,$m)===1
                &&$feed===monitor_hook_feed_operation($operation,$m[1])
                &&is_string($receipt['at']??null),'price_pair_feed_not_completed');
            monitor_price_time($receipt['at']);
            $completion=['operation'=>$operation,'feed_operation'=>$feed,'at'=>$receipt['at']];
        }
        return ['journal'=>$history['pin'],'latest_completion'=>$completion];
    }

    private static function absent(string $path):void
    {
        clearstatcache(true,$path);
        self::need(!file_exists($path)&&!is_link($path),'prior_operation_do_not_replay');
    }

    private static function journal(string $root,string $operation):string
    {
        $dir=$root.'/'.$operation;$path=$dir.'/receipt.jsonl';
        clearstatcache(true,$path);
        self::need(realpath($dir)===$dir&&is_dir($dir)&&!is_link($dir)
            &&realpath($path)===$path&&is_file($path)&&!is_link($path),'journal_missing');
        $h=@fopen($path,'rb');
        self::need(is_resource($h),'journal_unavailable');
        try{
            self::need(flock($h,LOCK_SH|LOCK_NB)&&self::sameFile($h,$path),'journal_busy_or_changed');
            $before=fstat($h);
            self::need(is_array($before)&&$before['size']>0&&$before['size']<=self::JOURNAL_LIMIT,'journal_size');
            $raw=stream_get_contents($h,self::JOURNAL_LIMIT+1);
            $after=fstat($h);
            self::need(is_string($raw)&&strlen($raw)===$before['size']&&is_array($after)
                &&$before['size']===$after['size']&&$before['mtime']===$after['mtime']&&$before['ctime']===$after['ctime']
                &&self::sameFile($h,$path)&&str_ends_with($raw,"\n"),'journal_changed');
            return $raw;
        }finally{flock($h,LOCK_UN);fclose($h);}
    }

    private static function query(PDO $pdo,string $sql,array $args):array
    {
        $q=$pdo->prepare($sql);
        self::need($q!==false&&$q->execute($args),'registry_read_failed');
        try{$rows=$q->fetchAll(PDO::FETCH_ASSOC);self::need(is_array($rows),'registry_read_failed');return $rows;}
        finally{$q->closeCursor();}
    }

    private static function noOperation(PDO $pdo,string $root,array $row):void
    {
        self::absent($root.'/issue-1566-edit-existing-'.$row['source_publication_id'].'.jsonl');
        // Deliberately keep the old operation key: v2 is not a second attempt.
        self::absent($root.'/anytour-issue1566-historical-tg-media-'.$row['target_message_id'].'-v1');
        self::need(self::query($pdo,'SELECT id FROM post_publications WHERE post_channel_id=6 AND external_message_id=? LIMIT 1',
            [$row['target_message_id']])===[],'target_already_reserved');
        self::need(self::query($pdo,"SELECT id FROM post_versions WHERE publication_id=? AND "
            ."(state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) LIMIT 1",
            [$row['source_publication_id']])===[],'source_writer_held');
    }

    /** Read a single publication identity through the canonical registry. */
    private static function current(ChannelPostRegistryService $registry,array $row,array $intent):array
    {
        $p=$registry->publication($row['source_publication_id']);
        self::need(is_array($p)&&($p['project_key']??null)==='anytour'&&($p['platform']??null)==='telegram'
            &&($p['bot_key']??null)==='anyru_bot'&&($p['channel_id']??null)==='-1002262666616'
            &&(int)($p['channel']['id']??0)===10&&($p['message_id']??null)===$row['source_message_id']
            &&($p['state']??null)==='sent'&&($p['start_payload']??null)===$intent['source_start_payload']
            &&($p['channel']['canonical_bot_username']??null)==='anyru_bot'
            &&($p['channel']['bot_url']??null)==='https://t.me/anyru_bot'
            &&($p['channel']['button_text']??null)==='✈️ Узнать стоимость тура'
            &&(int)($p['channel']['config_version']??0)===2,'source_identity_changed');
        $resolved=$registry->resolveToken('anytour','telegram','anyru_bot',$p['start_payload']);
        self::need(is_array($resolved)&&$resolved['publication_id']===$p['publication_id']
            &&$resolved['version_id']===$p['version_id']&&$resolved['content_hash']===$p['content_hash'],'source_token_changed');
        return $p;
    }

    private static function versions(PDO $pdo,array $row,array $current):array
    {
        $baseline=self::query($pdo,'SELECT version_number FROM post_versions WHERE publication_id=? AND id=?',
            [$row['source_publication_id'],$row['source_version_id']]);
        self::need(count($baseline)===1&&(int)$baseline[0]['version_number']>0,'baseline_missing');
        $start=(int)$baseline[0]['version_number'];
        $rows=self::query($pdo,'SELECT id,version_number,state,claim_token,claimed_at,content_hash,snapshot_json '
            .'FROM post_versions WHERE publication_id=? AND version_number>=? ORDER BY version_number LIMIT 130',
            [$row['source_publication_id'],$start]);
        self::need(count($rows)>=1&&count($rows)<=129&&(int)$rows[0]['id']===$row['source_version_id']
            &&(int)$rows[count($rows)-1]['id']===$current['version_id'],'history_not_current_or_bounded');
        $versions=[];
        foreach($rows as $k=>$v){
            self::need((int)$v['version_number']===$start+$k&&is_string($v['snapshot_json'])
                &&strlen($v['snapshot_json'])<=131072,'history_not_contiguous');
            $snapshot=json_decode($v['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            self::need(is_array($snapshot),'snapshot_invalid');
            $versions[]=['project_key'=>$current['project_key'],'platform'=>$current['platform'],'bot_key'=>$current['bot_key'],
                'channel_id'=>$current['channel_id'],'message_id'=>$current['message_id'],'publication_id'=>$current['publication_id'],
                'version_id'=>(int)$v['id'],'version_number'=>(int)$v['version_number'],'state'=>$v['state'],
                'claim_token'=>$v['claim_token'],'claimed_at'=>$v['claimed_at'],'start_payload'=>$current['start_payload'],
                'content_hash'=>$v['content_hash'],'snapshot'=>$snapshot];
        }
        $last=$versions[count($versions)-1];
        self::need($last['version_number']===$current['version_number']&&$last['content_hash']===$current['content_hash']
            &&$last['snapshot']===$current['snapshot'],'current_snapshot_changed');
        return $versions;
    }

    /**
     * Read-only candidate without public observations, for internal preparation.
     * Never use this result alone as a transfer authorization.
     */
    public static function candidate(PDO $pdo,string $bytes,string $approvedDigest,string $root,$lease):array
    {
        return self::read($pdo,$bytes,$approvedDigest,$root,$lease,null);
    }

    /**
     * Future existing editor's opt-in entry before intent_saved/reservation.
     * The callback is the existing private, scope-checked public parser/reader:
     * it receives the exact materialized row, returns ['source'=>..., 'target'=>...].
     * It must not reserve, mutate, send or retry anything.
     */
    public static function materialize(PDO $pdo,string $bytes,string $approvedDigest,string $root,$lease,callable $publicRead):array
    {
        return self::read($pdo,$bytes,$approvedDigest,$root,$lease,$publicRead);
    }

    private static function read(PDO $pdo,string $bytes,string $approvedDigest,string $root,$lease,?callable $publicRead):array
    {
        self::need(PHP_SAPI==='cli'&&!$pdo->inTransaction()&&strlen($bytes)<=64000
            &&hash('sha256',$bytes)===$approvedDigest,'intent_unapproved');
        $intent=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        $row=$intent['row']??[];
        self::need(($intent['schema']??null)==='telegram_hotel_transfer_intent_v2'
            &&($intent['price_policy']??null)==='omit_previous_price_keep_hotel_calculation_button'
            &&is_int($row['hotel_id']??null)&&$row['hotel_id']>0
            &&is_int($row['source_publication_id']??null)&&$row['source_publication_id']>0
            &&is_int($row['source_version_id']??null)&&$row['source_version_id']>0
            &&is_string($row['source_message_id']??null)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$row['source_message_id'])===1
            &&is_string($row['target_message_id']??null)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$row['target_message_id'])===1
            &&is_string($intent['source_start_payload']??null),'intent_scope_invalid');
        self::lease($root,$lease);
        $global=self::priceHistory($root,$lease);
        self::noOperation($pdo,$root,$row);
        $registry=new ChannelPostRegistryService($pdo);
        $current=self::current($registry,$row,$intent);
        $versions=self::versions($pdo,$row,$current);
        $journals=[];$size=0;
        foreach(array_slice($versions,1) as $version){
            $meta=$version['snapshot']['metadata']??[];
            $record=$meta['monitor_price_record_id']??null;
            self::need(is_string($record)&&preg_match('/\A[a-f0-9]{64}\z/D',$record)===1,'price_record_missing');
            $operation='commercial-monitor-price-h'.$row['hotel_id'].'-'.$record;
            $event=$operation.':telegram:'.$row['source_message_id'];
            self::need(($meta['source_event_id']??null)===$event
                &&($meta['observation_kind']??null)==='authorized_admin_content_edit','source_event_changed');
            $observations=self::query($pdo,'SELECT version_id FROM post_observations WHERE publication_id=? AND source_event_key=?',
                [$row['source_publication_id'],hash('sha256',$event)]);
            self::need(count($observations)===1&&(int)$observations[0]['version_id']===$version['version_id'],'source_event_unbound');
            $raw=self::journal($root,$operation);$size+=strlen($raw);
            self::need($size<=self::TOTAL_LIMIT,'history_size');
            $last=monitor_hook_parse_journal($raw,$operation);
            foreach($last['posts']??[] as $post){
                if(($post['receipt']['platform']??null)==='telegram')
                    self::need(($post['receipt']['source_key']??null)===$current['source_key'],'journal_source_key_changed');
            }
            $journals[]=$raw;
        }
        $candidate=HotelTransferPriceLineage::candidate($bytes,$approvedDigest,$versions,$journals);
        $priceProof=self::priceProof($global,$row,$candidate);
        $candidate['global_price_proof']=$priceProof;
        if($publicRead!==null){
            $observed=$publicRead($candidate['row']);
            self::need(is_array($observed)&&is_array($observed['source']??null)
                &&is_array($observed['target']??null),'public_observation_missing');
            $candidate['public_preimages']=HotelTransferPriceLineage::publicPreimages(
                $bytes,$approvedDigest,$candidate['row'],$observed['source'],$observed['target']);
            $candidate['schema']='telegram_hotel_transfer_materialized_candidate_v2';
        }
        unset($candidate['execution_digest']);
        $candidate['execution_digest']=hash('sha256',json_encode($candidate,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        // Detect native registry changes while reading historical receipts. The
        // later canonical source/target CAS is still required before any write.
        self::lease($root,$lease);
        self::need(self::globalStat($root,$lease)===$global['stat'],'global_price_changed_during_read');
        self::noOperation($pdo,$root,$row);
        $after=self::current($registry,$row,$intent);
        self::need($after===$current&&self::versions($pdo,$row,$after)===$versions,'registry_changed_during_read');
        return $candidate;
    }
}
