<?php
declare(strict_types=1);

/** Synthetic prepared-format files only; never opens a retained user archive. */
function hrJson(mixed $value): string
{
    $sort=static function(mixed $v)use(&$sort):mixed{
        if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $k=>$item)$v[$k]=$sort($item);return $v;
    };
    return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
}

function hrRecord(string $hotel,string $question='old-question'): array
{
    return ['id'=>'synthetic-'.$hotel.'-'.$question,'country'=>'ZZ','hotel_key'=>$hotel,'question_id'=>$question,'source_ids'=>['synthetic-source'],
        'text'=>'Сохранённый синтетический факт 🌊','valid_at_travel'=>'unverified','scope'=>['season'=>'SYNTHETIC']];
}

/** Returns [baseline index bytes, exact candidate/semantic file bytes]. */
function hrBundle(array $definitions=[],array $reserves=[],bool $unclaimedSource=false): array
{
    $sourceRef='synthetic-working';$sourceSha=hash('sha256','synthetic preserved source');$parts=[];$candidateFiles=[];$semanticFiles=[];
    $write=static function(string $namespace,string $path,string $raw,?string $kind=null,?int $rows=null)use(&$parts,&$candidateFiles,&$semanticFiles):array{
        $entry=['path'=>$path,'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];
        if($namespace==='candidate')$entry['format']=$rows===null?'json':'jsonl';
        if($kind!==null)$entry['kind']=$kind;if($rows!==null)$entry['rows']=$rows;
        $parts[$namespace.'/'.$path]=$raw;
        if($namespace==='candidate')$candidateFiles[]=$entry;else $semanticFiles[]=$entry;
        return $entry;
    };
    $rows=static function(string $namespace,string $family,array $data)use($write):?array{
        if(!$data)return null;$raw='';foreach($data as $r)$raw.=(is_string($r)?$r:hrJson($r))."\n";
        return $write($namespace,$family.'/part-001.jsonl',$raw,$namespace==='candidate'?$family:null,count($data));
    };
    $claims=[];$models=[];$working=[];$ledger=[];$sources=[];$variantTotal=0;$continuous=[];
    $classCounts=['explicit-accepted'=>0,'inherited-baseline'=>0,'acceptance-unresolved'=>0];
    foreach($definitions as $i=>$definition){
        $id=$definition['id']??'synthetic-claim-'.$i;$classification=$definition['classification']??'explicit-accepted';
        $records=$definition['records']??[hrRecord('retained-'.$i)];
        $variants=[];$recordRaws=[];
        foreach($records as $vi=>$record){
            $recordRaw=is_string($record)?$record:hrJson($record);$value=json_decode($recordRaw,true,64,JSON_THROW_ON_ERROR);
            $variants[]=['origins'=>[$classification==='inherited-baseline'?'synthetic-baseline/origin#/'.$id:'synthetic-origin/'.$id],
                'record'=>$value];$recordRaws[]=$recordRaw;
            foreach($value['source_ids']??[] as $sourceId)if(!in_array($sourceId,$definition['missing_sources']??[],true))$sources[$sourceId]=true;
        }
        $authority=$definition['authority']??($classification==='explicit-accepted'?'direct':'none');
        $direct=[];$imports=[];
        if($authority==='direct'){
            $wi=count($working);$pointer='/working_acceptances/'.$wi;$operation=($definition['null_operation']??false)?null:'SYNTHETIC-OP-'.$i;
            $state=$definition['state']??'WORKING_ACCEPTED_NATIVE_READBACK_CONFIRMED';
            $working[]=['provenance'=>['source_ref'=>$sourceRef,'json_pointer'=>$pointer],
                'original_value'=>['operation_id'=>$operation,'state'=>$state,'country'=>'ZZ']];
            $variants[0]['working_acceptance_operation']=$operation;
            $direct[]=['variant_index'=>0,'operation_id'=>$operation,'json_pointer'=>$pointer,'observed_state'=>$state];
        }
        if($authority==='ledger'){
            $li=count($ledger);$pointer='/country_import_ledger/imports/'.$li;$import='SYNTHETIC-IMPORT-'.$i;
            $status=$definition['state']??'CANONICAL_ALL_THREE_SUCCEEDED';
            $ledger[]=['provenance'=>['source_ref'=>$sourceRef,'json_pointer'=>$pointer],
                'original_value'=>['import_id'=>$import,'import_status'=>$status,'claim_ids'=>[$id],
                    'source_receipts_ref'=>null,'content_acceptance_ref'=>'SYNTHETIC-NOT-A-RECEIPT','country'=>'ZZ']];
            $imports[]=['import_id'=>$import,'json_pointer'=>$pointer,'observed_import_status'=>$status,
                'observed_acceptance_ref'=>null,'observed_packet_sha256'=>null];
        }
        $provenance=['source_ref'=>$sourceRef,'json_pointer'=>'/knowledge/claims/'.$id];
        $claim=['claim_id'=>$id,'variant_count'=>count($variants),'provenance'=>$provenance,'original_value'=>$variants,
            'hotel_ids'=>array_values(array_unique(array_filter(array_column(array_column($variants,'record'),'hotel_key')))),
            'source_ids'=>['synthetic-source'],'acceptance'=>['direct_working_acceptance_refs'=>$direct,
                'explicit_import_ledger_refs'=>$imports,'working_presence'=>'synthetic','no_inferred_assignment_to_19_server_packets'=>true]];
        // Insert exact canonical record spans, retaining e.g.1.0,bigint and U+2028 bytes.
        $wire=$claim;
        foreach($wire['original_value'] as $vi=>&$wrapper)$wrapper['record']='SYNTHETIC-RAW-RECORD-'.$i.'-'.$vi;
        unset($wrapper);$raw=hrJson($wire);
        foreach($recordRaws as $vi=>$recordRaw)$raw=str_replace(hrJson('SYNTHETIC-RAW-RECORD-'.$i.'-'.$vi),$recordRaw,$raw);
        $claims[]=$raw;$variantTotal+=count($variants);
        if($classification==='continuous')$continuous[]=$id;else ++$classCounts[$classification];
        $models[]=compact('id','classification','claim','recordRaws','direct','imports','definition');
    }
    $write('candidate','source-files.json',hrJson(['schema'=>'anytour.retained-source-inventory.v1',
        'sources'=>[$sourceRef=>['sha256'=>$sourceSha,'version'=>'1']]])."\n");
    $claimFile=$rows('candidate','claims',$claims);
    if($unclaimedSource)$sources['unclaimed-source']=true;
    $sourceRows=[];
    foreach(array_keys($sources) as $id)$sourceRows[]=['source_id'=>$id,'variant_count'=>1,
        'provenance'=>['source_ref'=>$sourceRef,'json_pointer'=>'/knowledge/sources_including_unclaimed/'.$id],
        'original_value'=>[['origins'=>['synthetic-source-origin'],'record'=>['source_id'=>$id,'unknown_source_field'=>['keep'=>null]]]],
        'claim_ids'=>[],'hotel_ids'=>[],'source_status'=>'retained_including_unclaimed_not_a_new_read'];
    $rows('candidate','sources',$sourceRows);$rows('candidate','working-acceptances',$working);$rows('candidate','import-ledger',$ledger);
    $write('candidate','protected-reserves.json',hrJson(['schema'=>'synthetic-retained-reserves','records'=>$reserves,
        'central41'=>'UNKNOWN','effect'=>'preserve'])."\n");
    $early=['explicit-accepted'=>[],'inherited-baseline'=>[],'acceptance-unresolved'=>[]];$exclusions=[];$exclusionProvenance=[];
    foreach($models as $i=>$m){
        if($m['classification']==='continuous')continue;
        $row=$m['claim'];$variantMeta=[];
        foreach($row['original_value'] as $vi=>$wrapper){
            $record=$wrapper['record'];$pointer=$row['provenance']['json_pointer'].'/'.$vi.'/record';
            $fields=['country'=>$record['country']??null,'hotel'=>$record['hotel_key']??null,'question'=>$record['question_id']??null,
                'source_ids'=>$record['source_ids']??null,'answer'=>$record['text']??null];
            foreach($m['definition']['derived']??[] as $key=>$value)$fields[$key]=$value;
            $missing=[];foreach($fields as $key=>$value)if($value===null||$value===''||$value===[])$missing[]=$key;
            $limits=array_intersect_key($record,array_fill_keys(['kind','declared','finding_role','valid_at_travel','status','scope','remaining',
                'limits','information_boundary','information_as_of','publication_date','document_validity_period','retrieved_on'],true));
            $variantMeta[]=['variant_index'=>$vi,'record_pointer'=>$pointer,'original_record_sha256'=>hash('sha256',$m['recordRaws'][$vi]),
                'origins'=>$wrapper['origins'],'observed_fields'=>$fields,'original_status_and_limits'=>$limits,
                'country_provenance'=>isset($record['country'])?[$pointer.'/country']:[],
                'question_provenance'=>isset($record['question_id'])?[$pointer.'/question_id']:[],
                'missing_for_answered_4tuple'=>$missing];
            if($m['classification']==='explicit-accepted'&&!$missing){
                foreach($fields['source_ids'] as $source){
                    $scope=['country'=>$fields['country'],'hotel'=>$fields['hotel'],'question'=>$fields['question'],'source'=>$source];
                    $key=HotelServerInputs::key($scope);$exclusions[$key]=$scope;
                    $exclusionProvenance[$key]??=['scope'=>$scope,'semantic_key'=>hash('sha256',hrJson($scope)),'continuous_packet_or_receipt_assigned'=>false,'claim_provenance'=>[]];
                    $exclusionProvenance[$key]['claim_provenance'][]=['claim_id'=>$m['id'],'variant_index'=>$vi,
                        'record_pointer'=>$pointer,'classification'=>'explicit-accepted'];
                }
            }
        }
        $early[$m['classification']][]=['claim_id'=>$m['id'],'classification'=>$m['classification'],
            'candidate_location'=>['path'=>$claimFile['path'],'line_1based'=>$i+1,'sha256'=>$claimFile['sha256']],
            'original_working_provenance'=>$row['provenance'],'acceptance_granularity'=>'claim_id; preserve all original variants and their distinct status',
            'acceptance_evidence'=>['direct'=>$m['direct'],'ledger'=>$m['imports'],'all_observed_ledger_statuses'=>$m['imports']],
            'assigned_continuous_receipt'=>null,'new_acceptance_credit'=>0,'variants'=>$variantMeta];
    }
    foreach(['explicit-accepted'=>'earlier-explicit-accepted-facts','inherited-baseline'=>'earlier-inherited-baseline-facts',
        'acceptance-unresolved'=>'earlier-acceptance-unresolved-facts'] as $class=>$family)$rows('semantic',$family,$early[$class]);
    $packetCoverage=[];
    if($continuous){$ret=hsLegacy();$reg=HotelServerInputs::registry($ret,hash('sha256',$ret['receipt_bytes']));
        $packetCoverage[]=['server_identity'=>$reg[0]+['registry_json_pointer'=>'/accepted/0'],
            'accepted_claim_ids'=>$continuous,'every_saved_new_claim_covered'=>true];}
    $write('semantic','packet_coverage_13.json',hrJson($packetCoverage)."\n");
    $write('semantic','earlier_explicit_exclusions.json',hrJson(array_values($exclusions))."\n");
    $rows('semantic','earlier-exclusion-provenance',array_values($exclusionProvenance));
    $candidate=['schema'=>'anytour.retained-working-input-candidate.v1','source_ref'=>$sourceRef,'source_working_sha256'=>$sourceSha,
        'source_working_version'=>1,'source_inventory'=>'source-files.json','files'=>$candidateFiles,'coverage'=>[
            'claim_ids'=>count($claims),'claim_variants'=>$variantTotal,'source_ids'=>count($sources),'source_variants'=>count($sources)]];
    $semantic=['schema'=>'anytour.partial-semantic-retained-inputs.v1','files'=>$semanticFiles,
        'candidate_input_shards'=>array_values(array_filter($candidateFiles,static fn(array $f):bool=>in_array($f['kind']??null,
            ['claims','working-acceptances','import-ledger','hotel-index','question-results'],true))),
        'counts'=>['accepted_claim_ids_in_13_packets'=>count($continuous),'all_retained_claim_ids_accounted_for'=>count($claims),
            'early_explicit_accepted_claim_ids'=>$classCounts['explicit-accepted'],'early_inherited_baseline_claim_ids'=>$classCounts['inherited-baseline'],
            'early_acceptance_unresolved_claim_ids'=>$classCounts['acceptance-unresolved'],'matched_accepted_packets'=>count($packetCoverage),
            'early_explicit_4tuple_exclusions'=>count($exclusions)],'new_acceptance_credit'=>0,'ready_for_launch'=>false];
    $index=['schema'=>HotelRetainedBaseline::SCHEMA,'new_acceptance_credit'=>0];
    foreach(['candidate'=>$candidate,'semantic'=>$semantic] as $kind=>$manifest){
        $raw=hrJson($manifest)."\n";$parts[$kind.'/manifest.json']=$raw;
        $index[$kind]=['path'=>$kind.'/manifest.json','bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];
    }
    return [hrJson($index),$parts];
}

/** Update a synthetic child file and its manifest/index pins to test semantic validation. */
function hrRepack(array $bundle,string $file,string $raw): array
{
    [$baseline,$parts]=$bundle;[$kind,$path]=explode('/',$file,2);$parts[$file]=$raw;
    $manifest=json_decode($parts[$kind.'/manifest.json'],true,64,JSON_THROW_ON_ERROR);$found=false;
    foreach($manifest['files'] as &$entry)if($entry['path']===$path){$entry['bytes']=strlen($raw);$entry['sha256']=hash('sha256',$raw);$found=true;}
    unset($entry);if(!$found)throw new RuntimeException('synthetic_repack_file');
    $manifestRaw=hrJson($manifest)."\n";$parts[$kind.'/manifest.json']=$manifestRaw;
    $index=json_decode($baseline,true,64,JSON_THROW_ON_ERROR);$index[$kind]['bytes']=strlen($manifestRaw);$index[$kind]['sha256']=hash('sha256',$manifestRaw);
    return [hrJson($index),$parts];
}

function hrInputs(array $bundle,?array $views=null): HotelServerInputs
{
    $ret=hsLegacy();$bytes=hsInputs(null,$views)->package()['bytes'];
    [$bytes['baseline'],$bytes['retained_parts']]=$bundle;
    return HotelServerInputs::validate($ret,hash('sha256',$ret['receipt_bytes']),$bytes);
}
