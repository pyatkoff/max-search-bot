<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionService.php';
require_once __DIR__.'/ConversationStateRepository.php';
require_once __DIR__.'/AiSearchContextService.php';
require_once __DIR__.'/TravelDirectoryRepository.php';

/** Optional read-only proposal. Saved wizard values are not proof of a current enquiry. */
final class ManagerTourSelectionTripContext
{
    private const STATUS=['city'=>65,'country'=>66,'adults'=>67,'children'=>68,'child_ages'=>69,'stars'=>70,'meal'=>71,'nights'=>72,'date'=>73];

    public static function read(int $actor,int $id,?string $revision=null,bool $confirmed=false): array
    {
        $pdo=ConversationDb::connection();
        if($pdo->inTransaction())return ['ok'=>false,'error'=>'trip_unavailable'];
        try {
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');}
            $pdo->beginTransaction();
            ProjectAccessService::initializeReadOnly();
            $selection=ManagerTourSelectionService::get($actor,$id);
            if(empty($selection['ok']))return $selection;
            if(empty($selection['selection']['can_edit']))return ['ok'=>false,'error'=>'forbidden'];
            $c=ManagerConversationService::visibleConversation($id,$actor,true);
            if(!$c)return ['ok'=>false,'error'=>'not_found'];
            if(!self::active($pdo,$c))return ['ok'=>false,'error'=>'trip_unavailable'];
            $q=$pdo->prepare('SELECT id AS ID,value_text AS UF_VALUE FROM runtime_dialogue_state WHERE project_key=? AND chat_id=? AND status_id=64 ORDER BY id DESC LIMIT 1');
            $q->execute([$c['project_key'],(string)$c['external_chat_id']]);$start=$q->fetch(PDO::FETCH_ASSOC);
            $rows=[];
            if($start){
                $q=$pdo->prepare('SELECT id AS ID,status_id AS UF_STATUS,value_text AS UF_VALUE FROM runtime_dialogue_state WHERE project_key=? AND chat_id=? AND id>=? AND status_id BETWEEN 64 AND 73 ORDER BY id DESC LIMIT 257');
                $q->execute([$c['project_key'],(string)$c['external_chat_id'],(int)$start['ID']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
                if(count($rows)>256||array_sum(array_map(static fn($r)=>strlen((string)$r['UF_VALUE']),$rows))>65536)return ['ok'=>false,'error'=>'trip_unavailable'];
            }
            $q=$pdo->prepare('SELECT MAX(id) FROM messages WHERE conversation_id=?');$q->execute([$id]);$latest=(int)$q->fetchColumn();
            $basis=self::binding($c,$actor,$selection['selection']['version'],$latest);
            $trip=self::project($rows,$basis,gmdate('Y-m-d'),static fn($v)=>TravelDirectoryRepository::cityById(0,$v),static fn($v)=>TravelDirectoryRepository::countryById(0,$v));
            $pdo->commit();
            // Authorization is fresh after the read snapshot as well.
            $fresh=ManagerTourSelectionService::get($actor,$id);$after=ManagerConversationService::visibleConversation($id,$actor,true);
            if(empty($fresh['ok'])||empty($fresh['selection']['can_edit'])||!$after||!self::active($pdo,$after))return ['ok'=>false,'error'=>'forbidden'];
            $q=$pdo->prepare('SELECT MAX(id) FROM messages WHERE conversation_id=?');$q->execute([$id]);
            if($basis!==self::binding($after,$actor,$fresh['selection']['version'],(int)$q->fetchColumn()))return ['ok'=>false,'error'=>'trip_changed'];
            $q=$pdo->prepare('SELECT id AS ID,value_text AS UF_VALUE FROM runtime_dialogue_state WHERE project_key=? AND chat_id=? AND status_id=64 ORDER BY id DESC LIMIT 1');$q->execute([$c['project_key'],(string)$c['external_chat_id']]);$newStart=$q->fetch(PDO::FETCH_ASSOC);
            if($newStart!==$start)return ['ok'=>false,'error'=>'trip_changed'];
            if($start){$q=$pdo->prepare('SELECT id AS ID,status_id AS UF_STATUS,value_text AS UF_VALUE FROM runtime_dialogue_state WHERE project_key=? AND chat_id=? AND id>=? AND status_id BETWEEN 64 AND 73 ORDER BY id DESC LIMIT 257');$q->execute([$c['project_key'],(string)$c['external_chat_id'],(int)$start['ID']]);if($rows!==$q->fetchAll(PDO::FETCH_ASSOC))return ['ok'=>false,'error'=>'trip_changed'];}
            if($revision!==null&&(!$confirmed||$trip['revision']===''||!hash_equals($trip['revision'],$revision)))return ['ok'=>false,'error'=>'trip_changed'];
            return ['ok'=>true,'trip'=>$trip];
        }catch(Throwable $ignored){return ['ok'=>false,'error'=>'trip_unavailable'];}
        finally {if($pdo->inTransaction())$pdo->rollBack();}
    }

    private static function active(PDO $pdo,array $c): bool
    {
        // Only the documented AnyTour wizard status map is supported here.
        if(($c['project_key']??'')!=='anytour'||(string)($c['external_chat_id']??'')==='')return false;
        $q=$pdo->prepare('SELECT id FROM projects WHERE project_key=? AND is_active=1');$q->execute([$c['project_key']]);if(!$q->fetchColumn())return false;
        if((int)($c['source_id']??0)>0){$q=$pdo->prepare('SELECT id FROM conversation_sources WHERE id=? AND is_active=1');$q->execute([$c['source_id']]);if(!$q->fetchColumn())return false;}
        return true;
    }
    private static function binding(array $c,int $actor,int $version,int $latest): array
    {
        return [$actor,$version,$latest,array_intersect_key($c,array_flip(['id','project_key','source_id','channel','external_chat_id','customer_channel_id','manager_id','status','started_at','last_message_at']))];
    }

    /** Pure canonical-row projection, also used by deterministic regression. Newest first. */
    public static function project(array $rows,array $binding,string $today,callable $city,callable $country): array
    {
        $start=null;foreach($rows as $r)if((int)$r['UF_STATUS']===64){$start=(int)$r['ID'];break;}
        $out=['state'=>'unbound','revision'=>'','fields'=>[],'details'=>[],'warnings'=>[]];
        if(!$start)return $out;
        $saved=ConversationStateRepository::savedDataFromRows($rows,64,74);
        // Canonical reader historically casts numbers; fail closed on malformed stored values.
        foreach(['city'=>999999999,'country'=>999999999,'adults'=>20,'children'=>12] as $k=>$max){$v=$saved[self::STATUS[$k]]??null;if($v!==null&&(!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$v)||(int)$v>$max||($k!=='children'&&(int)$v<1)))unset($saved[self::STATUS[$k]]);}
        $context=AiSearchContextService::contextFromSaved($saved,self::STATUS,$city,$country);$fields=[];$details=[];$warnings=[];
        foreach(['city'=>['departure','Вылет'],'country'=>['destination','Направление']] as $k=>[$field,$label]){
            $v=$context[$k]??null;if(is_string($v)&&trim($v)!==''&&preg_match('//u',$v)&&preg_match('/[\x00-\x1f\x7f]/u',$v)!==1&&mb_strlen($v)<=160){$fields[$field]=$v;$details[]=$label.': '.$v;}
        }
        $children=$context['children']??null;$ages=null;
        if($children===0)$ages=[];
        elseif(is_int($children)&&$children>0)$ages=ChildAgeValueContract::fromStorage($saved[69]??null,$children);
        if(is_array($ages)&&isset($context['adults'])){$fields['adults']=$context['adults'];$fields['child_ages']=$ages;$details[]='Состав: '.$context['adults'].' взрослых, детей: '.$children.($ages?' ('.implode(', ',$ages).')':'');}
        else $warnings[]='Состав или возраста детей неполны — заполните их вручную.';
        $date=$context['date']??'';$past=false;
        if(preg_match('/\A(\d{2})\.(\d{2})\.(\d{4})\z/D',$date,$m)&&checkdate((int)$m[2],(int)$m[1],(int)$m[3])){
            $iso=$m[3].'-'.$m[2].'-'.$m[1];$details[]='Сохранённая дата: '.$date;$past=$iso<$today;
            if(!$past)$fields['date_from']=$iso;else $warnings[]='Сохранённая дата уже прошла — выберите новую вручную.';
        }elseif($date!=='')$warnings[]='Дата не является точной календарной датой — выберите её вручную.';
        $nights=(string)($context['nights']??'');
        if(preg_match('/\A[1-9][0-9]?\z/D',$nights)&&(int)$nights<=60){$fields['nights']=(int)$nights;$details[]='Ночей: '.$nights;}
        elseif($nights!=='')$warnings[]='Ночи сохранены как диапазон или неточное значение: '.mb_substr($nights,0,40).'. Выберите точное число вручную.';
        if(isset($context['budget']['max']))$details[]='Бюджет поездки: '.$context['budget']['max'].' '.($context['budget']['currency']??'').' '.(($context['budget']['basis']??'total')==='per_person'?'на человека':'за всех').' (не цена предложения)';
        $out=['state'=>$past?'historical':'saved','revision'=>hash('sha256',json_encode([$binding,$start,$rows,$fields,$today],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'fields'=>$fields,'details'=>$details,'warnings'=>$warnings];
        return $out;
    }
}
