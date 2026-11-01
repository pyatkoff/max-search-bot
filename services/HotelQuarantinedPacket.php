<?php
declare(strict_types=1);
require_once __DIR__.'/HotelCollectionCanonicalDocument.php';

/** Pure legacy handoff validation and lossless append, never web or Library. */
final class HotelQuarantinedPacket
{
    public static function inspect(string $working,string $packet,array $members,array $queue,array $protected):array
    {
        if(strlen($working)>67108864||strlen($packet)>2097152)self::deny('input_limit');
        $base=HotelCollectionCanonicalDocument::decodeObject($working);$p=HotelCollectionCanonicalDocument::decodeObject($packet);
        foreach(['claims','sources_including_unclaimed']as$g)if(!is_array($base['knowledge'][$g]??null)||array_is_list($base['knowledge'][$g]))self::deny('legacy_knowledge_map_required');
        if(!is_array($base['working_acceptances']??null)||!array_is_list($base['working_acceptances'])||!is_array($base['scoped_question_results']??null))self::deny('legacy_append_shape');
        if(!is_array($base['country_import_ledger']['imports']??null)||!array_is_list($base['country_import_ledger']['imports'])||!is_int($base['country_import_ledger']['revision']??null))self::deny('legacy_ledger_shape');
        $country=['EG','TH','VN','TR'][(array_search($queue['start_country'],['EG','TH','VN','TR'],true)+intdiv((int)$queue['stage_index'],2))%4];
        $previous=hash('sha256',self::json([$queue['queue_id'],$queue['plan_sha256'],(int)$queue['stage_index']-1]));
        if(($p['queue_id']??null)!==$queue['queue_id']||($p['country']??null)!==$country||($p['stage_id']??null)!==$previous||($p['state']??null)!=='COUNTRY_SAVED_CENTRAL_PENDING'
            ||!is_string($p['operation_id']??null)||!is_string($p['actual_run_id']??null)||($p['file_pins']['WORKING']['sha256']??null)!==hash('sha256',$working))self::deny('source_identity');
        if(count($protected)<1||!array_is_list($protected))self::deny('protected_inventory_required');
        $keys=array_column($p['scope']??[],'hotel_key');
        if(count($keys)<1||count($keys)>8||count($keys)!==count(array_unique($keys)))self::deny('scope');
        foreach($keys as$k)if(!is_string($k)||!isset($base['hotel_index'][$k])||in_array($k,$protected,true))self::deny('membership_or_protected');
        $visit=function(mixed$v)use(&$visit,$keys):void{if(is_string($v)&&in_array($v,$keys,true))self::deny('active_or_legacy_reserve');if(is_array($v))foreach($v as$x)$visit($x);};
        foreach(['active_claims','legacy_reserved_scopes']as$k)$visit($base['coordination'][$k]??[]);
        $sha=hash('sha256',$packet);$seen=function(mixed$v)use(&$seen,$sha,$p):void{if(is_string($v)&&in_array($v,[$sha,$p['operation_id']],true))self::deny('historical_packet_replay');if(is_array($v))foreach($v as$x)$seen($x);};
        foreach(['working_acceptances','country_import_ledger']as$k)$seen($base[$k]??[]);
        $expected=$p['source_evidence']??[];
        foreach(['delta','ledger','source_read','release']as$k){if(!is_string($p[$k.'_ref']??null)||!is_string($p[$k.'_sha256']??null))self::deny('member_reference');$expected[$p[$k.'_ref']]=$p[$k.'_sha256'];}
        if(!is_string($p['admission_readback']['member']??null)||!is_string($p['admission_readback']['sha256']??null))self::deny('admission_reference');
        $expected[$p['admission_readback']['member']]=$p['admission_readback']['sha256'];
        if(array_diff(array_keys($members),array_keys($expected))||array_diff(array_keys($expected),array_keys($members)))self::deny('exact_member_set');
        $size=strlen($packet);foreach($expected as$n=>$h){$b=$members[$n]??null;if(!is_string($b)||!is_string($h)||hash('sha256',$b)!==$h)self::deny('member_digest');$size+=strlen($b);}
        if($size>2097152)self::deny('bundle_limit');
        $d=$p['raw_delta']??null;if(!is_array($d)||self::canonical($d)!==self::canonical(HotelCollectionCanonicalDocument::decodeObject($members[$p['delta_ref']])))self::deny('delta_mismatch');
        foreach(['claims','sources','source_read_updates','question_results','failed_reads','source_observations','initial_source_reads']as$g)if(!is_array($d[$g]??null)||!array_is_list($d[$g]))self::deny('delta_shape');
        if(($d['operation_id']??null)!==$p['operation_id']||($d['country']??null)!==$country)self::deny('delta_identity');
        $ids=[];$sourceIds=array_keys($base['knowledge']['sources_including_unclaimed']??[]);$sourceHotels=[];
        foreach($base['knowledge']['sources_including_unclaimed']??[]as$id=>$variants)foreach($variants as$variant)if(is_string($variant['record']['hotel_key']??null))$sourceHotels[$id][]=$variant['record']['hotel_key'];
        foreach(['sources'=>'sources_including_unclaimed','claims'=>'claims']as$g=>$target){
            $group=[];if(count($d[$g])>200)self::deny('counts');
            foreach($d[$g]as$r){$id=$r['id']??null;if(!is_string($id)||$id===''||isset($group[$id])||isset($base['knowledge'][$target][$id])
                    ||!in_array($r['hotel_key']??null,$keys,true)||($r['country']??null)!==$country||($r['operation_id']??null)!==$p['operation_id'])self::deny('record_identity_or_replay');
                $group[$id]=true;if($g==='sources'){$sourceIds[]=$id;$sourceHotels[$id]=[$r['hotel_key']];if(!is_string($r['url']??null)||!str_starts_with($r['url'],'https://')||!is_string($r['read_scope']??null)||$r['read_scope']==='')self::deny('source_shape');}
                if(!isset($expected[$r['saved_evidence_ref']??''])||$expected[$r['saved_evidence_ref']]!==($r['saved_evidence_sha256']??null))self::deny('record_evidence');
                if($g==='claims'){if(!is_string($r['text']??null)||$r['text']===''||($r['property_fact_credit']??null)!==true||!is_array($r['source_ids']??null)||count($r['source_ids'])===0)self::deny('claim_evidence');foreach($r['source_ids']as$s)if(!in_array($s,$sourceIds,true)||!in_array($r['hotel_key'],$sourceHotels[$s]??[],true))self::deny('source_FK');}
            }$ids[$g]=array_keys($group);
        }
        // The current recovery supports the completed TR40 no-read-update shape.
        // Other shapes HOLD instead of guessing legacy update semantics.
        if($d['source_read_updates']!==[])self::deny('legacy_read_update_shape_not_supported');
        foreach($d['question_results']as$r)if(!in_array($r['hotel_key']??null,$keys,true)||!in_array($r['status']??null,['HOLD','PARTIAL','DONE'],true))self::deny('question_scope');
        foreach(['failed_reads','source_observations','initial_source_reads']as$g)foreach($d[$g]as$r)if(!in_array($r['hotel_key']??null,$keys,true)||($g!=='initial_source_reads'&&($r['property_fact_credit']??false)!==false))self::deny('observation_scope_or_credit');
        $counts=$p['counts']??[];foreach(['hotel_count'=>count($keys),'record_count'=>count($ids['claims']),'source_count'=>count($ids['sources']),'read_update_count'=>0]as$k=>$n)if(($counts[$k]??null)!==$n||($d['counts'][$k]??null)!==$n)self::deny('counts');
        if(($p['record_ids']??null)!==$ids['claims']||($p['new_source_ids']??null)!==$ids['sources'])self::deny('record_manifest');
        $release=HotelCollectionCanonicalDocument::decodeObject($members[$p['release_ref']]);
        if(($release['operation_id']??null)!==$p['operation_id']||($release['actual_run_id']??null)!==$p['actual_run_id']||($release['state']??null)!=='RELEASED_AFTER_CONTENT_READBACK')self::deny('producer_release');
        return ['packet'=>$p,'delta'=>$d,'hotel_keys'=>$keys,'counts'=>$counts];
    }
    public static function merge(string $working,string $packet,array $acceptance):string
    {
        // Preserve every original object/list, variant, origin and raw field.
        $w=json_decode($working,false,128,JSON_THROW_ON_ERROR);$p=json_decode($packet,false,128,JSON_THROW_ON_ERROR);$d=$p->raw_delta;
        foreach(['claims'=>'claims','sources'=>'sources_including_unclaimed']as$g=>$target)foreach($d->$g as$position=>$r){
            $id=$r->id;if(property_exists($w->knowledge->$target,$id))self::deny('merge_id_conflict');
            $w->knowledge->{$target}->{$id}=[(object)['record'=>$r,'origins'=>['server-quarantined-packet:'.hash('sha256',$packet).'#/raw_delta/'.$g.'/'.$position]]];
            $field=$g==='claims'?'claim_ids':'source_ids';$index=$w->hotel_index->{$r->hotel_key};
            if(!isset($index->$field))$index->$field=[];if(in_array($id,$index->$field,true))self::deny('merge_index_conflict');$index->{$field}[]=$id;
        }
        if((!is_array($w->scoped_question_results)&&!($w->scoped_question_results instanceof stdClass))||!is_array($w->working_acceptances)||!is_array($w->country_import_ledger->imports))self::deny('legacy_append_shape');
        if(is_array($w->scoped_question_results))foreach($d->question_results as$r)$w->scoped_question_results[]=$r;
        else{if(property_exists($w->scoped_question_results,$p->operation_id))self::deny('question_operation_replay');$w->scoped_question_results->{$p->operation_id}=$d->question_results;}
        $w->working_acceptances[]=(object)$acceptance;$w->country_import_ledger->imports[]=(object)$acceptance;
        if(!is_int($w->country_import_ledger->revision??null))self::deny('legacy_ledger_revision');$w->country_import_ledger->revision++;
        if(isset($w->server_snapshot_protocol)&&$w->server_snapshot_protocol!=='quarantined_intake_v1')self::deny('snapshot_protocol_conflict');$w->server_snapshot_protocol='quarantined_intake_v1';
        // All observations, failed reads, budgets and original provenance also
        // remain in the immutable packet store, exposed by the bound reader.
        return self::json($w);
    }
    public static function requiredReads(string$working,string$packet,array$members):array
    {
        $b=HotelCollectionCanonicalDocument::decodeObject($working);$p=HotelCollectionCanonicalDocument::decodeObject($packet);
        $out=[['kind'=>'packet','member'=>'','pointer'=>'']];foreach(array_keys($members)as$n)$out[]=['kind'=>'member','member'=>$n,'pointer'=>''];
        $keys=array_column($p['scope'],'hotel_key');$escape=static fn(string$s):string=>str_replace(['~','/'],['~0','~1'],$s);
        $requiredIds=['claims'=>[],'sources_including_unclaimed'=>[]];
        foreach($keys as$k){$out[]=['kind'=>'working','member'=>'','pointer'=>'/hotel_index/'.$escape($k)];foreach(['claims'=>'claim_ids','sources_including_unclaimed'=>'source_ids']as$g=>$f)foreach($b['hotel_index'][$k][$f]??[]as$id){if(!isset($b['knowledge'][$g][$id]))self::deny('missing_old_context');$requiredIds[$g][$id]=true;}}
        foreach(['claims','sources_including_unclaimed']as$group)foreach($b['knowledge'][$group]as$id=>$variants){
            foreach($variants as$wrapper)if(in_array($wrapper['record']['hotel_key']??null,$keys,true)){$requiredIds[$group][$id]=true;break;}
        }
        foreach($requiredIds as$g=>$ids)foreach(array_keys($ids)as$id)$out[]=['kind'=>'working','member'=>'','pointer'=>'/knowledge/'.$g.'/'.$escape((string)$id)];
        return$out;
    }
    public static function json(mixed$v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function canonical(array$v):string{$sort=function(mixed$x)use(&$sort):mixed{if(!is_array($x))return$x;if(!array_is_list($x))ksort($x,SORT_STRING);foreach($x as$k=>$z)$x[$k]=$sort($z);return$x;};return self::json($sort($v));}
    private static function deny(string$r):never{throw new DomainException('quarantined_packet_'.$r);}
}
