<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerHotelOfferReference.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Private aggregate only. Caller authorizes the current admin before and after reading. */
final class ManagerHotelDemandInventory
{
    private const PUBLICATION_BYTES=1048576;
    private const EVENT_BYTES=65536;
    private const METADATA_BYTES=16384;
    private const TOTAL_BYTES=4194304;
    private const INQUIRY_LIMIT=400;
    public const OUTPUT_BYTES=262144;
    private int $bytes=0;
    private function __construct(private PDO $pdo,private int $now) {}

    public static function read(PDO $pdo,int $limit=20,?int $now=null): array
    {
        if($limit<1||$limit>20||($now!==null&&$now<=0))throw new InvalidArgumentException();
        if($pdo->inTransaction())return self::unavailable('transaction_active');
        $buffered=null;
        try {
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                $buffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('SET TRANSACTION READ ONLY');
            }
            $pdo->beginTransaction();$result=(new self($pdo,$now??time()))->inventory($limit);
            // No store, queue, receipt, read-marker or repair writes belong here.
            $pdo->rollBack();
            return strlen(self::json($result))>self::OUTPUT_BYTES?self::unavailable('output_limit'):$result;
        } catch(Throwable $error) {
            if($pdo->inTransaction())$pdo->rollBack();
            return self::unavailable($error instanceof LengthException?'byte_limit':'storage_unavailable');
        } finally {
            if($buffered!==null)$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
        }
    }
    private function inventory(int $limit): array
    {
        $rows=$this->rows('SELECT p.publication_id,p.conversation_id,p.created_at,p.draft_hash,'
            .$this->bounded('p.binding_json',self::METADATA_BYTES).' AS binding_json,'
            .$this->bounded('p.selection_json',self::PUBLICATION_BYTES).' AS selection_json '
            .'FROM manager_tour_selection_publications p JOIN conversations c ON c.id=p.conversation_id '
            .'WHERE '.$this->scope().' AND p.created_at<=? ORDER BY p.created_at DESC,p.publication_id DESC LIMIT '.($limit+1),[$this->date()]);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        $groups=[];$publications=[];$exposures=[];$badPublications=0;$badCards=0;$notConfirmed=0;$duplicates=0;$cards=0;
        $oldest=null;$newest=null;$windowValid=true;
        foreach($rows as $row){
            $stamp=self::timestamp($row['created_at']);
            if($stamp===null)$windowValid=false;else{$oldest=$oldest===null?$stamp:min($oldest,$stamp);$newest=$newest===null?$stamp:max($newest,$stamp);}
            $binding=self::decode($row['binding_json']);$selection=self::decode($row['selection_json']);
            if(!self::hex($row['publication_id'],32)||self::id($row['conversation_id'])===null||$stamp===null
                ||($binding['project_key']??null)!=='anytour'||!in_array($binding['channel']??null,['max','telegram'],true)
                ||!self::native($binding['external_chat_id']??null)||!self::native($binding['external_user_id']??null)
                ||!is_array($selection['offers']??null)||!array_is_list($selection['offers'])||count($selection['offers'])<1||count($selection['offers'])>20
                ||!self::hex($row['draft_hash'],64)||!hash_equals($row['draft_hash'],hash('sha256',self::json($binding).$row['selection_json']))){++$badPublications;continue;}
            $cid=(int)$row['conversation_id'];$pub=$row['publication_id'];$pubHash=hash('sha256',$row['binding_json']."\n".$row['selection_json']);
            $stored=$this->rows('SELECT card_index,offer_id FROM manager_tour_selection_cards WHERE publication_id=? ORDER BY card_index LIMIT 21',[$pub]);
            $indices=array_map(static fn($r)=>(int)$r['card_index'],$stored);
            if(count($stored)!==count($selection['offers'])||$indices!==range(0,count($selection['offers'])-1)){++$badPublications;continue;}
            $publications[$pub]=['created_at'=>$stamp,'entries'=>[]];
            foreach($selection['offers'] as $index=>$entry){
                ++$cards;
                if(!is_array($entry)||!is_array($entry['snapshot']??null)
                    ||TourChoiceService::normalize($entry['snapshot'])!==$entry['snapshot']){++$badCards;continue;}
                $provenance=['kind'=>'publication','publication_id'=>$pub,'card_index'=>$index,
                    'draft_offer_id'=>$entry['draft_offer_id']??'', 'publication_sha256'=>$pubHash,
                    'source_sha256'=>is_array($entry['import_source']??null)?ManagerHotelOfferReference::hash($entry['import_source']):str_repeat('0',64)];
                $selector=ManagerHotelOfferReference::selector($entry,$provenance);
                $facts=ManagerHotelOfferReference::capture($entry,$provenance,$this->now);
                $identity=$selector['source_identity']??null;
                // An admitted source key is not itself an accepted hotel match.
                // In particular source_name_mismatch must not inherit another
                // card's available facts merely because its typed key agrees.
                $group=self::group($identity,$facts);
                $key=$group['group_revision'];
                if(!isset($groups[$key]))$groups[$key]=$group;
                ++$groups[$key]['observed_publication_cards'];
                $publications[$pub]['entries'][$index]=['entry'=>$entry,'group'=>$key];
                $offerId=self::id($stored[$index]['offer_id']);if($offerId===null){++$badCards;continue;}
                $proof=$this->delivery($cid,$offerId,$binding,$entry);
                if($proof==='not_confirmed'){++$notConfirmed;continue;}
                if($proof==='uncertain'){++$notConfirmed;++$badCards;continue;}
                if($proof===null){++$badCards;continue;}
                $fingerprint=ManagerHotelOfferReference::hash(['group'=>$key,'snapshot'=>$entry['snapshot']]);
                if(isset($exposures[$offerId])){
                    ++$duplicates;
                    if($exposures[$offerId]['fingerprint']!==$fingerprint){$exposures[$offerId]['ambiguous']=true;++$badCards;}
                }else $exposures[$offerId]=['group'=>$key,'fingerprint'=>$fingerprint,'ambiguous'=>false];
            }
        }
        $delivered=0;foreach($exposures as $exposure)if(!$exposure['ambiguous']){++$delivered;++$groups[$exposure['group']]['verified_delivered_lower_bound'];}
        $inquiries=['verified'=>0,'unknown'=>0,'has_more'=>false,'rows_read'=>0,'oldest'=>null,'newest'=>null];
        if($rows&&$windowValid&&$oldest!==null)$inquiries=$this->inquiries($publications,$groups,$oldest);
        elseif($rows)$inquiries['unknown']=1;
        $deliveryComplete=$badPublications===0&&$badCards===0;$inquiryComplete=$badPublications===0&&$inquiries['unknown']===0&&!$inquiries['has_more'];
        foreach($groups as &$group){
            $group['delivered_card_exposures']=$deliveryComplete?$group['verified_delivered_lower_bound']:null;
            $group['native_inquiries']=$inquiryComplete?$group['verified_inquiries_lower_bound']:null;
        }unset($group);ksort($groups,SORT_STRING);
        return ['ok'=>true,'schema_version'=>'hotel_demand_inventory_v1','project_key'=>'anytour',
            'status'=>$deliveryComplete&&$inquiryComplete?'available':'partial','observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$this->now),
            'classification'=>['included_sessions'=>'unknown','confirmed_test_sessions'=>'excluded_by_is_test',
                'confirmed_internal_owner_dialogues'=>'excluded_by_canonical_scope','is_test_zero_proves_real_customer'=>false],
            'window'=>['kind'=>'latest_immutable_publications','limit'=>$limit,'publications_read'=>count($rows),'has_more'=>$more,
                'order'=>'created_at DESC, publication_id DESC','oldest_created_at'=>$oldest,'newest_created_at'=>$newest,
                'max_cards_per_publication'=>20,'delivery_basis'=>'unique verified offer delivery referenced by these publications, not sends within publication time span',
                'inquiry_basis'=>'native inquiry events since oldest publication through observed_at; only exact selected publication/card matches',
                'inquiry_limit'=>self::INQUIRY_LIMIT,'inquiry_rows_read'=>$inquiries['rows_read'],'inquiry_has_more'=>$inquiries['has_more'],
                'oldest_inquiry_row_at'=>$inquiries['oldest'],'newest_inquiry_row_at'=>$inquiries['newest']],
            'counts'=>['observed_publication_cards'=>$cards,'delivered_card_exposures'=>$deliveryComplete?$delivered:null,
                'verified_delivered_lower_bound'=>$delivered,'native_inquiries'=>$inquiryComplete?$inquiries['verified']:null,
                'verified_inquiries_lower_bound'=>$inquiries['verified'],'not_confirmed_sent_cards'=>$notConfirmed,
                'repeated_delivery_references'=>$duplicates,'unreadable_publications'=>$badPublications,
                'unknown_card_delivery_proofs'=>$badCards,'unknown_inquiry_proofs'=>$inquiries['unknown']],
            'hotels'=>array_values($groups),'coverage'=>['complete_population'=>false,'delivery_window_complete'=>$deliveryComplete,
                'inquiry_window_complete'=>$inquiryComplete,'unique_people'=>null,'unique_trips'=>null,'real_customer_demand_verified'=>false,
                'distinct_hotel_count'=>null,'unidentified_cards_may_share_a_group'=>true,
                'current_trip_binding'=>false,'current_price_verified'=>false,'availability_verified'=>false],
            'interpretation'=>'Counts describe bounded saved evidence only. Prepared/unknown delivery is not sent. Test classification is unknown unless positively excluded. No population, unique-person, current-trip or popularity ranking inference.'];
    }
    /** Saved canonical state, exact prepared snapshot, delivery event and transcript mirror must agree. */
    private function delivery(int $cid,int $offer,array $binding,array $entry): ?string
    {
        $r=$this->rows('SELECT d.state,d.offer_hash,e.created_at AS prepared_at,'.$this->bounded('d.receipt_json',self::EVENT_BYTES).' AS receipt_json,'
            .$this->bounded('e.payload_json',self::EVENT_BYTES).' AS payload_json '
            ."FROM manager_tour_selection_deliveries d LEFT JOIN conversation_events e ON e.id=d.offer_id AND e.conversation_id=d.conversation_id AND e.event_type='tour_offer_prepared' WHERE d.offer_id=? AND d.conversation_id=? LIMIT 2",[$offer,$cid]);
        if(count($r)!==1)return null;$r=$r[0];
        if(in_array($r['state'],['pending','rejected'],true))return 'not_confirmed';
        if(in_array($r['state'],['reserved','unknown','known'],true))return 'uncertain';
        if($r['state']!=='delivered')return null;
        $prepared=self::decode($r['payload_json']);$receipt=self::decode($r['receipt_json']);
        if(($prepared['schema']??null)!==1||($prepared['snapshot']??null)!==$entry['snapshot']||($prepared['binding']??null)!==$binding
            ||self::timestamp($r['prepared_at'])===null||$r['prepared_at']>$this->date()
            ||!self::hex($prepared['token_hash']??null,64)||!is_array($entry['presentation']??null)
            ||!self::hex($r['offer_hash'],64)||!hash_equals($r['offer_hash'],hash('sha256',self::json(['binding'=>$binding,'snapshot'=>$entry['snapshot'],'presentation'=>$entry['presentation']]))))return null;
        $mid=$receipt['native']['mid']??null;$chat=$receipt['identity']['chat']??null;$user=$receipt['identity']['user']??null;
        if(!is_string($mid)||!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$mid)||!is_string($chat)||!preg_match('/\A-?[1-9][0-9]{0,18}\z/D',$chat)
            ||!is_string($user)||!preg_match('/\A[1-9][0-9]{0,18}\z/D',$user)||$user!==$binding['external_user_id']
            ||($binding['channel']==='telegram'&&($chat!==$user||$chat!==$binding['external_chat_id'])))return null;
        $events=$this->rows('SELECT created_at,'.$this->bounded('payload_json',self::EVENT_BYTES)." AS payload_json FROM conversation_events WHERE conversation_id=? AND event_type='tour_offer_delivered' AND ".$this->jsonField('payload_json','offer_id').'=? LIMIT 2',[$cid,(string)$offer]);
        if(count($events)!==1||self::decode($events[0]['payload_json'])!==['offer_id'=>$offer,'native_mid'=>$mid,'native_chat_id'=>$chat]
            ||self::timestamp($events[0]['created_at'])===null||$events[0]['created_at']<$r['prepared_at']||$events[0]['created_at']>$this->date())return null;
        $messages=$this->rows('SELECT channel,created_at,'.$this->bounded('metadata_json',self::METADATA_BYTES)." AS metadata_json FROM messages WHERE conversation_id=? AND direction='outbound' AND sender_type='manager' AND external_message_id=? LIMIT 2",[$cid,$mid]);
        if(count($messages)!==1||$messages[0]['channel']!==$binding['channel']||self::timestamp($messages[0]['created_at'])===null
            ||$messages[0]['created_at']<$events[0]['created_at']||$messages[0]['created_at']>$this->date())return null;
        $metadata=self::decode($messages[0]['metadata_json']);
        return ($metadata['project_key']??null)==='anytour'&&($metadata['tour_offer_id']??null)===$offer?'delivered':null;
    }
    private function inquiries(array $publications,array &$groups,string $since): array
    {
        $rows=$this->rows('SELECT e.id,e.conversation_id,c.channel AS inquiry_channel,e.created_at,'.$this->bounded('e.payload_json',self::EVENT_BYTES)
            ." AS payload_json FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE ".$this->scope()
            ." AND e.event_type='tour_web_inquiry' AND e.created_at>=? AND e.created_at<=? ORDER BY e.created_at DESC,e.id DESC LIMIT ".(self::INQUIRY_LIMIT+1),[$since,$this->date()]);
        $out=['verified'=>0,'unknown'=>0,'has_more'=>count($rows)>self::INQUIRY_LIMIT,'rows_read'=>min(count($rows),self::INQUIRY_LIMIT),'oldest'=>null,'newest'=>null];
        foreach(array_slice($rows,0,self::INQUIRY_LIMIT) as $r){
            $p=self::decode($r['payload_json']);$stamp=self::timestamp($r['created_at']);
            if($stamp===null||!in_array($r['inquiry_channel'],['max','telegram'],true)||($p['schema']??null)!==1||!self::hex($p['publication_id']??null,32)||!self::hex($p['entry_key']??null,64)
                ||!is_int($p['variant']??null)||$p['variant']<1||$p['variant']>20||!is_int($p['link_version']??null)||$p['link_version']<1){++$out['unknown'];continue;}
            $out['oldest']=$out['oldest']===null?$stamp:min($out['oldest'],$stamp);$out['newest']=$out['newest']===null?$stamp:max($out['newest'],$stamp);
            if(!isset($publications[$p['publication_id']]))continue;
            $publication=$publications[$p['publication_id']];$card=$publication['entries'][$p['variant']-1]??null;
            if($card===null||$stamp<$publication['created_at']||($p['public_offer']??null)!==self::publicOffer($card['entry']['snapshot'],$p['variant']-1)){++$out['unknown'];continue;}
            $cid=self::id($r['conversation_id']);if($cid===null){++$out['unknown'];continue;}
            $tuple=$this->jsonField('payload_json','publication_id').'=? AND '.$this->jsonField('payload_json','variant').'=? AND '.$this->jsonField('payload_json','link_version').'=?';
            $args=[$cid,$p['publication_id'],(string)$p['variant'],(string)$p['link_version']];
            $events=$this->rows("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='tour_web_inquiry' AND $tuple LIMIT 2",$args);
            $tuple=str_replace('payload_json','metadata_json',$tuple);
            $messages=$this->rows('SELECT channel,created_at,'.$this->bounded('metadata_json',self::METADATA_BYTES)." AS metadata_json FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' AND $tuple LIMIT 2",$args);
            $metadata=count($messages)===1?self::decode($messages[0]['metadata_json']):null;
            if(count($events)!==1||(string)$events[0]['id']!==(string)$r['id']||count($messages)!==1||$messages[0]['channel']!==$r['inquiry_channel']||($metadata['type']??null)!=='tour_web_inquiry'
                ||($metadata['publication_id']??null)!==$p['publication_id']||($metadata['variant']??null)!==$p['variant']||($metadata['link_version']??null)!==$p['link_version']
                ||self::timestamp($messages[0]['created_at'])===null||$messages[0]['created_at']<$stamp||$messages[0]['created_at']>$this->date()){++$out['unknown'];continue;}
            ++$out['verified'];++$groups[$card['group']]['verified_inquiries_lower_bound'];
        }
        return $out;
    }
    private static function publicOffer(array $s,int $index): array
    {
        $out=['index'=>$index];foreach(['hotel','departure','date_from','nights','adults'] as $k)$out[$k]=$s[$k];
        $out['children']=count($s['child_ages']);foreach(['room','meal','amount','currency','rationale','compromise'] as $k)$out[$k]=$s[$k];
        foreach(['destination','flights','inclusions','constraints','observed_at'] as $k)$out[$k]=$s['quoted_details'][$k]??'';
        $out['price_verified']=false;return $out;
    }
    private static function group(?array $identity,array $facts): array
    {
        $available=($facts['status']??null)==='available';
        // knowledge_revision contains offer provenance and must not split one
        // accepted property bundle into a different hotel for every selection.
        $version=$available?[
            'binding_revision'=>$facts['binding_revision'],'research_hotel_key'=>$facts['research_hotel_key'],
            'record_hashes'=>$facts['record_hashes'],'material_limits'=>$facts['material_limits'],'coverage'=>$facts['coverage'],
        ]:null;
        $revision=ManagerHotelOfferReference::hash(['identity'=>$identity,'hotel_ref'=>$facts['hotel_ref']??null,
            'status'=>$facts['status']??'held','reason'=>$facts['reason']??null,'accepted_version'=>$version]);
        return ['group_revision'=>$revision,'group_kind'=>$available?'accepted_hotel':($identity===null?'unidentified_cards':'unaccepted_source_identity'),
            'source_identity'=>$identity,'hotel_ref'=>$available?$facts['hotel_ref']:null,
            'selected_hotel'=>$available?$facts['selected_hotel']:null,
            'accepted_knowledge'=>$available?[
                'bundle_revision'=>ManagerHotelOfferReference::hash($version),
                'binding_revision'=>$facts['binding_revision'],'research_hotel_key'=>$facts['research_hotel_key'],
                'claim_ids'=>array_column($facts['facts'],'id'),'source_ids'=>array_column($facts['sources'],'id'),
                'price_authority'=>'none',
            ]:null,
            'facts_coverage'=>['status'=>$available?'available':'held','reason'=>$available?null:($facts['reason']??'unavailable'),
                'selected_claim_count'=>$available?count($facts['facts']):null,'source_count'=>$available?count($facts['sources']):null,'complete'=>false],
            'observed_publication_cards'=>0,'verified_delivered_lower_bound'=>0,'verified_inquiries_lower_bound'=>0];
    }
    private function scope(): string {return "c.project_key='anytour' AND (c.is_test IS NULL OR c.is_test<>1) AND NOT ".InternalOwnerDialogueScope::conversationSql('c');}
    private function bounded(string $column,int $limit): string
    {
        $length=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"LENGTH($column)":"LENGTH(CAST($column AS BLOB))";
        return "CASE WHEN $length<=$limit THEN $column ELSE NULL END";
    }
    private function jsonField(string $column,string $key): string
    {
        $extract="JSON_EXTRACT($column,'$.".$key."')";
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$extract="JSON_UNQUOTE($extract)";
        $max=str_contains($column,'metadata_json')?self::METADATA_BYTES:self::EVENT_BYTES;
        $bounded=$this->bounded($column,$max);
        return "CASE WHEN ($bounded) IS NOT NULL THEN CASE WHEN JSON_VALID($column) THEN CAST($extract AS CHAR) ELSE NULL END ELSE NULL END";
    }
    private function rows(string $sql,array $args): array
    {
        $q=$this->pdo->prepare($sql);$rows=[];
        try {
            $q->execute($args);
            while(($row=$q->fetch(PDO::FETCH_ASSOC))!==false){
                foreach($row as $value)if(is_string($value)){
                    $this->bytes+=strlen($value);if($this->bytes>self::TOTAL_BYTES)throw new LengthException();
                }
                $rows[]=$row;
            }
            return $rows;
        } finally {$q->closeCursor();}
    }
    private function date(): string {return gmdate('Y-m-d H:i:s',$this->now);}
    private static function decode(mixed $raw): ?array {if(!is_string($raw))return null;try{$v=json_decode($raw,true,48,JSON_THROW_ON_ERROR);return is_array($v)&&!array_is_list($v)?$v:null;}catch(Throwable $e){return null;}}
    private static function timestamp(mixed $v): ?string {if(!is_string($v))return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new DateTimeZone('UTC'));return $d!==false&&$d->format('Y-m-d H:i:s')===$v?$v:null;}
    private static function id(mixed $v): ?int {return (is_int($v)&&$v>0)||(is_string($v)&&preg_match('/\A[1-9][0-9]*\z/D',$v)&&((string)(int)$v)===$v)?(int)$v:null;}
    private static function hex(mixed $v,int $n): bool {return is_string($v)&&preg_match('/\A[a-f0-9]{'.$n.'}\z/D',$v)===1;}
    private static function native(mixed $v): bool {return is_string($v)&&$v!==''&&strlen($v)<=255&&preg_match('/[\x00-\x1f\x7f]/',$v)!==1;}
    private static function json(array $v): string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function unavailable(string $reason): array {return ['ok'=>false,'schema_version'=>'hotel_demand_inventory_v1','project_key'=>'anytour','status'=>'unavailable','reason'=>$reason,'counts'=>null,'hotels'=>[],'coverage'=>['complete_population'=>false,'real_customer_demand_verified'=>false]];}
}
