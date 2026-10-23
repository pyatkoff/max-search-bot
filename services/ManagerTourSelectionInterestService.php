<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionShareService.php';

/** Observations are not identity, reads, choices, inquiries or delivery receipts. */
final class ManagerTourSelectionInterestService
{
    private const KINDS=['page_view','hotel_view','bot_open'];
    private const BUCKETS=['browser','preview','robot'];

    public static function previewPath(string $token,int $version): ?string
    {
        $c=ManagerTourSelectionShareService::interestContext($token,$version);
        if (!$c) return null;
        $expires=time()+43200;
        return '/selection/?'.http_build_query(['t'=>$token,'preview'=>$expires.'.'.self::mac($c,['preview',$token,$version,$expires])]);
    }

    /** The page stays usable when optional observation storage is unavailable. */
    public static function page(string $token,int $version,string $preview,string $agent): ?array
    {
        $c=ManagerTourSelectionShareService::interestContext($token,$version);
        if (!$c) return null;
        $bucket=self::agentBucket($agent);
        if (preg_match('/\A([0-9]{10})\.([a-f0-9]{64})\z/D',$preview,$m)
            && (int)$m[1]>=time() && (int)$m[1]<=time()+43200
            && hash_equals(self::mac($c,['preview',$token,$version,(int)$m[1]]),$m[2])) $bucket='preview';
        $cap=['t'=>$token,'version'=>$version,'expires'=>time()+900,'nonce'=>bin2hex(random_bytes(16)),'bucket'=>$bucket];
        $cap['mac']=self::capMac($c,$cap);
        if ($bucket!=='browser') {
            // A known crawler/preview GET is an automatic fetch, never a browser view.
            try {self::record($cap+['kind'=>'page_fetch','event_id'=>bin2hex(random_bytes(16)),
                'visitor_id'=>bin2hex(random_bytes(16)),'card_index'=>-1,'platform'=>''],$agent,true);} catch (Throwable $ignored) {}
        }
        return $cap;
    }

    public static function record(array $in,string $agent,bool $automatic=false): array
    {
        $keys=['t','version','expires','nonce','bucket','mac','kind','event_id','visitor_id','card_index','platform'];
        if (array_diff(array_keys($in),$keys) || count($in)!==count($keys)) return self::error('invalid_observation');
        foreach (['t'=>64,'nonce'=>32,'mac'=>64,'event_id'=>32,'visitor_id'=>32] as $key=>$length)
            if (!is_string($in[$key]??null) || !preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$in[$key])) return self::error('invalid_observation');
        if (!is_int($in['version']) || $in['version']<1 || !is_int($in['expires'])
            || $in['expires']<time() || $in['expires']>time()+900 || !in_array($in['bucket'],self::BUCKETS,true)
            || !is_int($in['card_index']) || !in_array($in['platform'],['','telegram','max'],true)
            || (!in_array($in['kind'],self::KINDS,true) && !($automatic && $in['kind']==='page_fetch' && $in['bucket']!=='browser')))
            return self::error('invalid_observation');
        $c=ManagerTourSelectionShareService::interestContext($in['t'],$in['version']);
        if (!$c || !hash_equals(self::capMac($c,$in),$in['mac'])) return self::error('unavailable');
        $card=$in['card_index'];$page=in_array($in['kind'],['page_view','page_fetch'],true);
        if (($page && $card!==-1) || (!$page && !isset($c['groups'][$card]))
            || ($in['kind']==='bot_open' ? !in_array($in['platform'],['telegram','max'],true) : $in['platform']!=='')) return self::error('invalid_observation');
        $bucket=$in['bucket'];$observed=self::agentBucket($agent);
        if ($observed!=='browser') $bucket=$observed;
        $visitor=self::mac($c,['visitor',$in['visitor_id']]);
        $event=substr(hash('sha256',$in['t'].$in['event_id']),0,32);
        $row=[$event,$c['conversation_id'],$c['publication_id'],$in['version'],$visitor,$in['kind'],$bucket,$card,$page?-1:$c['groups'][$card],$in['platform']];
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Interest recording owns its transaction');
        $pdo->beginTransaction();
        try {
            $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$lock);$q->execute([$c['conversation_id']]);
            // Same parent lock as explicit publish/revoke; no stale-version attribution.
            if (ManagerTourSelectionShareService::interestContext($in['t'],$in['version'])!==$c) {$pdo->rollBack();return self::error('unavailable');}
            $q=$pdo->prepare('SELECT event_id,conversation_id,publication_id,link_version,visitor_hash,event_kind,bucket,card_index,hotel_group,platform FROM manager_tour_selection_interest WHERE event_id=?');$q->execute([$event]);
            if ($old=$q->fetch(PDO::FETCH_NUM)) {
                $same=array_map('strval',$old)===array_map('strval',$row);$pdo->commit();
                return $same?['ok'=>true,'saved'=>true,'duplicate'=>true]:self::error('observation_mismatch');
            }
            // Opportunistic bounded cleanup of this anonymous projection only.
            $q=$pdo->prepare('SELECT event_id FROM manager_tour_selection_interest WHERE conversation_id=? AND created_at<? ORDER BY created_at LIMIT 1000');
            $q->execute([$c['conversation_id'],gmdate('Y-m-d H:i:s',time()-30*86400)]);$expired=$q->fetchAll(PDO::FETCH_COLUMN);
            if ($expired) {$q=$pdo->prepare('DELETE FROM manager_tour_selection_interest WHERE event_id IN ('.implode(',',array_fill(0,count($expired),'?')).')');$q->execute($expired);}
            $since=gmdate('Y-m-d H:i:s',time()-86400);
            $q=$pdo->prepare('SELECT COUNT(*) FROM manager_tour_selection_interest WHERE conversation_id=? AND created_at>=?');$q->execute([$c['conversation_id'],$since]);
            if ((int)$q->fetchColumn()>=10000) {$pdo->rollBack();return self::error('rate_limited');}
            $q=$pdo->prepare('SELECT COUNT(*) FROM manager_tour_selection_interest WHERE publication_id=? AND visitor_hash=? AND created_at>=?');$q->execute([$c['publication_id'],$visitor,$since]);
            if ((int)$q->fetchColumn()>=100) {$pdo->rollBack();return self::error('rate_limited');}
            $q=$pdo->prepare('INSERT INTO manager_tour_selection_interest(event_id,conversation_id,publication_id,link_version,visitor_hash,event_kind,bucket,card_index,hotel_group,platform,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([...$row,gmdate('Y-m-d H:i:s')]);$pdo->commit();
            return ['ok'=>true,'saved'=>true,'duplicate'=>false];
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    public static function summary(int $actor,int $conversation,string $publication): array
    {
        $access=ManagerTourSelectionDeliveryService::get($actor,$conversation,$publication);
        if (!$access['ok']) return $access;
        $pdo=ConversationDb::connection();$since=gmdate('Y-m-d H:i:s',time()-30*86400);
        $q=$pdo->prepare('SELECT link_version,visitor_hash,event_kind,bucket,card_index,hotel_group,platform,created_at FROM manager_tour_selection_interest WHERE conversation_id=? AND publication_id=? AND created_at>=? ORDER BY created_at DESC,event_id DESC LIMIT 1001');
        $q->execute([$conversation,$publication,$since]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$limited=count($rows)>1000;$rows=array_slice($rows,0,1000);
        $totals=[];$seen=[];$variants=[];$last=null;
        foreach ($rows as $r) {
            if (!in_array($r['bucket'],self::BUCKETS,true) || !in_array($r['event_kind'],[...self::KINDS,'page_fetch'],true)) continue;
            $kind=$r['event_kind'];$bucket=$r['bucket'];
            $key=implode(':',[$bucket,$kind,$r['link_version'],$r['visitor_hash'],$kind==='hotel_view'?$r['hotel_group']:$r['card_index'],$r['platform']]);
            $totals[$bucket][$kind]??=['unique'=>0,'repeat'=>0];
            $repeat=isset($seen[$key]);$totals[$bucket][$kind][$repeat?'repeat':'unique']++;$seen[$key]=true;
            if ($bucket==='browser' && (int)$r['card_index']>=0) {
                $i=(int)$r['card_index'];$variants[$i]??=['index'=>$i,'hotel_views'=>0,'bot_opens'=>0];
                if (!$repeat) $variants[$i][$kind==='hotel_view'?'hotel_views':'bot_opens']++;
            }
            if ($bucket==='browser' && ($last===null || $r['created_at']>$last)) $last=$r['created_at'];
        }
        $native=TourChoiceService::selectionActivity($pdo,$conversation,$publication,$since);
        // No saved authorization, role or source permission survives the read.
        $fresh=ManagerTourSelectionDeliveryService::get($actor,$conversation,$publication);
        if (!$fresh['ok']) return $fresh;
        return ['ok'=>true,'interest'=>['window_days'=>30,'limited'=>$limited,'totals'=>$totals,'variants'=>array_values($variants),
            'last_browser_at'=>$last,'native'=>$native,'identity'=>'anonymous_observations','coverage'=>'observed_only']];
    }

    private static function agentBucket(string $agent): string
    {
        $agent=substr($agent,0,512);
        if (preg_match('/telegrambot|whatsapp|facebookexternalhit|twitterbot|vkshare|vkbot|maxbot|discordbot|slackbot|preview|prefetch/i',$agent)) return 'preview';
        return preg_match('/bot\b|crawler|spider|slurp|headless/i',$agent)?'robot':'browser';
    }
    private static function capMac(array $c,array $in): string {return self::mac($c,['observation',$in['t'],$in['version'],$in['expires'],$in['nonce'],$in['bucket']]);}
    private static function mac(array $c,array $values): string {return hash_hmac('sha256',json_encode(['selection-interest-v1',...$values],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$c['key']);}
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
