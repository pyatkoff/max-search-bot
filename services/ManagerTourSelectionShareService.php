<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionDeliveryService.php';
require_once __DIR__.'/ChannelPostRuntime.php';
require_once __DIR__.'/ManagerTourSelectionPublicHost.php';

/** One explicit public link per conversation; private publications stay immutable. */
final class ManagerTourSelectionShareService
{
    public static function get(int $actor,int $conversation): array
    {
        $access=ManagerTourSelectionService::get($actor,$conversation);
        if (!$access['ok']) return $access;
        $manager=ManagerAuthService::byId($actor);$visible=ManagerConversationService::visibleConversation($conversation,$actor);
        return ['ok'=>true,'share'=>self::view(self::link($conversation))+['can_revoke'=>$manager && $visible && ManagerConversationAccessPolicy::canEditVisibleConversation($visible,$manager)]];
    }

    public static function change(int $actor,int $conversation,int $version,string $publication,bool $active): array
    {
        if ($version<0 || !preg_match('/\A[a-f0-9]{32}\z/D',$publication)) return self::error('invalid_publication');
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Selection sharing owns its transaction');
        $pdo->beginTransaction();
        try {
            $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id FROM managers WHERE id=?'.$suffix);$q->execute([$actor]);
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$suffix);$q->execute([$conversation]);
            $access=ManagerTourSelectionService::get($actor,$conversation);
            $canRevoke=false;
            if (!$active && $access['ok']) {
                $manager=ManagerAuthService::byId($actor);$visible=ManagerConversationService::visibleConversation($conversation,$actor);
                $canRevoke=$manager && $visible && ManagerConversationAccessPolicy::canEditVisibleConversation($visible,$manager);
            }
            if (!$access['ok'] || (!$access['selection']['can_edit'] && !$canRevoke)) {$pdo->rollBack();return $access['ok']?self::error('forbidden'):$access;}
            $old=self::link($conversation);
            if ((int)($old['version']??0)!==$version) {$pdo->rollBack();return self::error('share_changed');}
            // Revoke uses the currently published version, not arbitrary input.
            if (!$active && (!$old || $old['publication_id']!==$publication)) {$pdo->rollBack();return self::error('share_changed');}
            if ($active) {
                $review=ManagerTourSelectionDeliveryService::get($actor,$conversation,$publication);
                $stored=self::publication($conversation,$publication);
                if (!$review['ok'] || !$stored) {$pdo->rollBack();return self::error('not_found');}
                $current=ManagerTourSelectionPublicationDraft::build($access['selection']['draft']);
                if (!$current['ok']) {$pdo->rollBack();return self::error('draft_changed');}
                unset($current['ok']);
                // prepare() deliberately reuses an older identical publication.
                // Compare exact terms rather than rejecting its original revision.
                if ($current!==$stored['selection']) {$pdo->rollBack();return self::error('draft_changed');}
                // All public photos must already have been inspected/cached in preview.
                foreach ($stored['cards'] as $i=>$card) foreach (array_keys($stored['selection']['offers'][$i]['presentation']['photos']) as $photo) {
                    $url=$stored['selection']['offers'][$i]['presentation']['photos'][$photo]['url']??'';
                    if (!TourSelectionPhotoStore::cached(TourSelectionPhotoStore::offerKey((int)$card['offer_id']),0,$photo,$url)) {$pdo->rollBack();return self::error('preparation_failed');}
                }
            }
            if ($old && (bool)$old['is_active']===$active && $old['publication_id']===$publication) {$pdo->commit();return ['ok'=>true,'share'=>self::view($old)];}
            $next=$version+1;$now=gmdate('Y-m-d H:i:s');
            // Updating preserves the URL. Reactivation always creates a new token.
            $token=$old && (!$active || (bool)$old['is_active'])?$old['token']:bin2hex(random_bytes(32));
            if ($old) {
                $q=$pdo->prepare('UPDATE manager_tour_selection_links SET version=?,token=?,publication_id=?,is_active=?,updated_by_manager_id=?,updated_at=? WHERE conversation_id=? AND version=?');
                $q->execute([$next,$token,$publication,(int)$active,$actor,$now,$conversation,$version]);
                if ($q->rowCount()!==1) throw new RuntimeException('Share revision changed under lock');
            } else {
                $q=$pdo->prepare('INSERT INTO manager_tour_selection_links(conversation_id,version,token,publication_id,is_active,updated_by_manager_id,updated_at) VALUES(?,?,?,?,?,?,?)');
                $q->execute([$conversation,$next,$token,$publication,(int)$active,$actor,$now]);
            }
            $q=$pdo->prepare('INSERT INTO manager_tour_selection_link_history(conversation_id,version,publication_id,is_active,manager_id,created_at) VALUES(?,?,?,?,?,?)');
            $q->execute([$conversation,$next,$publication,(int)$active,$actor,$now]);
            $result=['ok'=>true,'share'=>self::view(self::link($conversation))+['can_revoke'=>true]];$pdo->commit();return $result;
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    /** Explicit allowlist only: never serialize a publication, conversation or source. */
    public static function read(string $token): ?array
    {
        $link=self::byToken($token);
        if (!$link) return null;
        $p=self::publication((int)$link['conversation_id'],$link['publication_id']);
        if (!$p) return null;
        $offers=[];$groups=[];
        foreach ($p['selection']['offers'] as $i=>$offer) {
            $s=$offer['snapshot'];$details=$s['quoted_details']??[];
            // Public ordinal only. Do not expose private draft/group identities.
            $groupKey=isset($offer['hotel_group'])?'manual:'.$offer['hotel_group']:'offer:'.$i;
            if (!array_key_exists($groupKey,$groups)) $groups[$groupKey]=count($groups);
            $offers[]=[
                'hotel_group'=>$groups[$groupKey],
                'bot_links'=>self::botLinks($link,$p,$i),
                'index'=>$i,'hotel'=>(string)$s['hotel'],'departure'=>(string)$s['departure'],
                'date_from'=>(string)$s['date_from'],'nights'=>(int)$s['nights'],'adults'=>(int)$s['adults'],'children'=>count($s['child_ages']),
                'room'=>(string)$s['room'],'meal'=>(string)$s['meal'],'amount'=>(string)$s['amount'],'currency'=>(string)$s['currency'],
                'rationale'=>(string)$s['rationale'],'compromise'=>(string)$s['compromise'],
                'destination'=>(string)($details['destination']??''),'flights'=>(string)($details['flights']??''),
                'inclusions'=>(string)($details['inclusions']??''),'constraints'=>(string)($details['constraints']??''),
                'observed_at'=>(string)($details['observed_at']??''),'price_verified'=>false,
                'photos'=>array_map(static fn(int $photo):string=>'photo.php?'.http_build_query(['t'=>$token,'v'=>$link['version'],'card'=>$i,'photo'=>$photo]),array_keys($offer['presentation']['photos'])),
                ...(($offer['presentation']['layout']??'')==='gallery-v1'?['photo_layout'=>'gallery-v1']:[]),
            ];
        }
        return ['title'=>(string)$p['selection']['title'],'intro'=>(string)$p['selection']['intro'],'version'=>(int)$link['version'],'updated_at'=>$link['updated_at'],'offers'=>$offers];
    }

    /** An opaque exact-version selector, not identity and not a native callback. */
    private static function botLinks(array $link,array $publication,int $card): array
    {
        $version=(int)$link['version'];
        if ($version<1 || $version>2176782335) return [];
        $out=[];
        foreach (ChannelPostRuntime::botIdentities() as $platform=>$identity) {
            $prefix='ts_'.substr($link['token'],0,12).'_'.base_convert((string)$version,10,36).'_'.base_convert((string)$card,10,36);
            $payload=$prefix.'_'.self::entryMac($identity,$link,$publication,$card);
            $out[$platform]=$identity['bot_url'].'?start='.rawurlencode($payload);
        }
        return $out;
    }

    private static function entryMac(array $identity,array $link,array $publication,int $card): string
    {
        $message=['selection-bot-entry-v1',$identity['project_key'],$identity['platform'],$identity['bot_key'],$link['token'],$link['publication_id'],(int)$link['version'],$card];
        return substr(hash_hmac('sha256',json_encode($message,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(string)$publication['cards'][$card]['callback_data']),0,32);
    }

    /** Called only for a dedicated native start, before ordinary source/start effects. */
    public static function botEntry(string $platform,string $payload): ?array
    {
        if (!preg_match('/\Ats_([a-f0-9]{12})_([0-9a-z]{1,6})_([0-9a-j])_([a-f0-9]{32})\z/D',$payload,$m)) return null;
        $identity=ChannelPostRuntime::identity($platform);if (!$identity) return null;
        $version=(int)base_convert($m[2],36,10);$card=(int)base_convert($m[3],36,10);
        if (base_convert((string)$version,10,36)!==$m[2] || $card>=20) return null;
        $q=ConversationDb::connection()->prepare('SELECT l.*,COALESCE(c.manager_id,l.updated_by_manager_id) AS contact_manager_id FROM manager_tour_selection_links l JOIN conversations c ON c.id=l.conversation_id JOIN projects p ON p.project_key=c.project_key JOIN conversation_sources cs ON cs.id=c.source_id AND cs.is_active=1 WHERE l.token LIKE ? AND l.is_active=1 AND p.is_active=1 AND c.project_key=? LIMIT 2');
        $q->execute([$m[1].'%',ProjectConfig::projectId()]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($links)!==1 || (int)$links[0]['version']!==$version) return null;
        $link=$links[0];$p=self::publication((int)$link['conversation_id'],$link['publication_id']);
        if (!$p || !isset($p['cards'][$card],$p['selection']['offers'][$card]) || !hash_equals(self::entryMac($identity,$link,$p,$card),$m[4])) return null;
        return ['conversation_id'=>(int)$link['conversation_id'],'contact_manager_id'=>(int)$link['contact_manager_id'],'publication_id'=>$link['publication_id'],'version'=>$version,'card'=>$card,'public_offer'=>self::publicOffer($link['token'],$card),'token'=>$link['token']];
    }

    private static function publicOffer(string $token,int $card): array
    {
        $offer=self::read($token)['offers'][$card]??[];
        unset($offer['bot_links'],$offer['photos'],$offer['hotel_group'],$offer['photo_layout']);
        return $offer;
    }

    public static function photo(string $token,int $version,int $card,int $photo): ?array
    {
        if ($card<0 || $card>=20 || $photo<0 || $photo>2) return null;
        $link=self::byToken($token);
        if (!$link || (int)$link['version']!==$version) return null;
        $p=self::publication((int)$link['conversation_id'],$link['publication_id']);
        $row=$p['cards'][$card]??null;$url=$p['selection']['offers'][$card]['presentation']['photos'][$photo]['url']??null;
        return $row && is_string($url)?TourSelectionPhotoStore::cached(TourSelectionPhotoStore::offerKey((int)$row['offer_id']),0,$photo,$url):null;
    }

    /** PHP-only context. Never serialize its private signing key or identities. */
    public static function interestContext(string $token,int $version): ?array
    {
        $link=self::byToken($token);
        if (!$link || (int)$link['version']!==$version) return null;
        $p=self::publication((int)$link['conversation_id'],$link['publication_id']);
        if (!$p) return null;
        $groups=[];$ordinals=[];
        foreach ($p['selection']['offers'] as $i=>$offer) {
            $key=isset($offer['hotel_group'])?'manual:'.$offer['hotel_group']:'offer:'.$i;
            if (!array_key_exists($key,$ordinals)) $ordinals[$key]=count($ordinals);
            $groups[$i]=$ordinals[$key];
        }
        return ['conversation_id'=>(int)$link['conversation_id'],'publication_id'=>$link['publication_id'],
            'version'=>$version,'key'=>(string)$p['cards'][0]['callback_data'],'groups'=>$groups];
    }

    private static function byToken(string $token): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$token)) return null;
        $q=ConversationDb::connection()->prepare('SELECT l.* FROM manager_tour_selection_links l JOIN conversations c ON c.id=l.conversation_id JOIN projects p ON p.project_key=c.project_key JOIN conversation_sources cs ON cs.id=c.source_id AND cs.is_active=1 WHERE l.token=? AND l.is_active=1 AND p.is_active=1 AND c.project_key=?');
        $q->execute([$token,ProjectConfig::projectId()]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    private static function link(int $conversation): ?array
    {
        $q=ConversationDb::connection()->prepare('SELECT * FROM manager_tour_selection_links WHERE conversation_id=?');$q->execute([$conversation]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
    }
    private static function publication(int $conversation,string $id): ?array
    {
        $q=ConversationDb::connection()->prepare('SELECT draft_version,selection_json FROM manager_tour_selection_publications WHERE conversation_id=? AND publication_id=?');$q->execute([$conversation,$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;
        $p['selection']=json_decode($p['selection_json'],true,48,JSON_THROW_ON_ERROR);
        $q=ConversationDb::connection()->prepare('SELECT card_index,offer_id,callback_data FROM manager_tour_selection_cards WHERE publication_id=? ORDER BY card_index LIMIT 21');$q->execute([$id]);$p['cards']=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($p['cards'])!==count($p['selection']['offers']) || count($p['cards'])<1 || count($p['cards'])>20 || array_map('intval',array_column($p['cards'],'card_index'))!==range(0,count($p['cards'])-1)) return null;
        return $p;
    }
    private static function view(?array $row): array
    {
        $out=['version'=>$row?(int)$row['version']:0,'active'=>$row?(bool)$row['is_active']:false,'publication_id'=>$row['publication_id']??null,
            'path'=>$row && (bool)$row['is_active']?'/selection/?t='.$row['token']:null,'updated_at'=>$row['updated_at']??null];
        if ($out['active']) {
            require_once __DIR__.'/ManagerTourSelectionInterestService.php';
            $out['preview_path']=ManagerTourSelectionInterestService::previewPath($row['token'],(int)$row['version']);
            $url=ManagerTourSelectionPublicHost::url($out['path']);
            if ($url!==null) {
                $out['public_url']=$url;
                $out['preview_url']=ManagerTourSelectionPublicHost::url($out['preview_path']);
            }
        }
        return $out;
    }
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
