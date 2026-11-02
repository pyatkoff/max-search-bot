<?php
declare(strict_types=1);

/**
 * Reader for the already prepared candidate and semantic JSON/JSONL files.
 * A baseline index pins their two manifests; no original archive is reopened.
 * Raw rows remain authoritative for types, unknown fields and ordered variants.
 * Claim-level acceptance is not variant selection or recommendation readiness.
 * This helper has no network, database, acceptance or queue side effects.
 */
final class HotelRetainedBaseline
{
    public const SCHEMA = 'hotel-retained-baseline-v1';
    public const MAX_FILE = 4194304;
    public const MAX_TOTAL = 67108864;
    public const MAX_FILES = 128;
    private const EARLY = [
        'earlier-explicit-accepted-facts'=>'explicit-accepted',
        'earlier-inherited-baseline-facts'=>'inherited-baseline',
        'earlier-acceptance-unresolved-facts'=>'acceptance-unresolved',
    ];
    private const DIRECT_STATES = ['WORKING_ACCEPTED_EXPORT_PENDING',
        'WORKING_ACCEPTED_NATIVE_READBACK_CONFIRMED',
        'WORKING_ACCEPTED_READBACK_CONFIRMED_MANUAL_SCOPE_COMPLETE'];
    private const LEDGER_STATES = ['WORKING_ACCEPTED_EXPORT_PENDING','CANONICAL_ALL_THREE_SUCCEEDED'];
    private const LIMIT_FIELDS = ['kind','declared','finding_role','valid_at_travel','status','scope','remaining',
        'limits','information_boundary','information_as_of','publication_date','document_validity_period','retrieved_on'];

    /** Index shape: schema, candidate/semantic {path,bytes,sha256}, new_acceptance_credit:0. */
    public static function load(string $indexBytes, string $root): array
    {
        $index=self::index($indexBytes); $parts=[];
        foreach(['candidate','semantic'] as $namespace){
            $ref=$index[$namespace];
            $raw=self::readFile($root,$ref['path'],$ref);
            $parts[$namespace.'/manifest.json']=$raw;
            $manifest=self::object($raw);
            $dir=dirname($ref['path']);
            foreach(self::entries($manifest) as $entry){
                $key=$namespace.'/'.$entry['path'];
                $parts[$key]=self::readFile($root,($dir==='.'?'':$dir.'/').$entry['path'],$entry);
                self::limits($parts);
            }
        }
        return $parts;
    }

    /** Pure preparation; callers retain the exact parts separately in immutable input bytes. */
    public static function validate(string $indexBytes, array $parts, array $registry, array $accepted): array
    {
        $index=self::index($indexBytes); self::limits($parts); $files=[]; $manifests=[];
        foreach(['candidate','semantic'] as $namespace){
            $key=$namespace.'/manifest.json';self::pin($parts[$key]??null,$index[$namespace]);
            $files[$key]=['bytes'=>$index[$namespace]['bytes'],'sha256'=>$index[$namespace]['sha256'],'format'=>'json'];
            $manifest=self::object($parts[$key]);$manifests[$namespace]=$manifest;
            foreach(self::entries($manifest) as $entry){
                $key=$namespace.'/'.$entry['path'];
                if(isset($files[$key]))self::fail('duplicate_file');
                self::pin($parts[$key]??null,$entry);$files[$key]=$entry;
            }
        }
        $actual=array_keys($parts);$expected=array_keys($files);sort($actual);sort($expected);
        if($actual!==$expected)self::fail('complete_parts_required');
        $candidate=$manifests['candidate'];$semantic=$manifests['semantic'];
        if(($candidate['schema']??null)!=='anytour.retained-working-input-candidate.v1'
            ||($semantic['schema']??null)!=='anytour.partial-semantic-retained-inputs.v1')self::fail('prepared_manifest_schema');
        $sourceRef=$candidate['source_ref']??null;
        if(!is_string($sourceRef)||$sourceRef==='')self::fail('working_source');
        $sourceInventory=self::object($parts['candidate/source-files.json']??'');
        $source=$sourceInventory['sources'][$sourceRef]??null;
        if(!is_array($source)||($source['sha256']??null)!==($candidate['source_working_sha256']??null)
            ||!isset($source['version'],$candidate['source_working_version'])
            ||(string)$source['version']!==(string)$candidate['source_working_version'])self::fail('working_source_pin');
        HotelServerInputs::sha($source['sha256']);
        $joined=[];
        foreach($semantic['candidate_input_shards']??[] as $entry){
            if(!is_array($entry)||!isset($entry['path'])||($files['candidate/'.$entry['path']]??null)!==$entry)self::fail('candidate_manifest_join');
            if(isset($joined[$entry['path']]))self::fail('candidate_manifest_join');$joined[$entry['path']]=true;
        }
        $claims=[];$sources=[];$variants=0;$sourceVariants=0;$working=[];$ledger=[];
        foreach(self::rows($parts,$files,'candidate/working-acceptances/') as [$row]){
            $pointer=$row['provenance']['json_pointer']??null;
            if(!is_string($pointer)||isset($working[$pointer])||!is_array($row['original_value']??null)||($row['provenance']['source_ref']??null)!==$sourceRef)self::fail('working_acceptance_pointer');
            $working[$pointer]=$row['original_value'];
        }
        foreach(self::rows($parts,$files,'candidate/import-ledger/') as [$row]){
            $pointer=$row['provenance']['json_pointer']??null;
            if(!is_string($pointer)||isset($ledger[$pointer])||!is_array($row['original_value']??null)||($row['provenance']['source_ref']??null)!==$sourceRef)self::fail('import_ledger_pointer');
            $ledger[$pointer]=$row['original_value'];
        }
        foreach(self::rows($parts,$files,'candidate/claims/') as [$row,$raw,$location]){
            $id=self::identifier($row['claim_id']??null);
            self::variants($row,$sourceRef);
            if(isset($claims[$id]))self::fail('duplicate_claim');
            $claims[$id]=$location+['variant_count'=>$row['variant_count'],'provenance'=>$row['provenance']];
            $variants+=$row['variant_count'];
        }
        foreach(self::rows($parts,$files,'candidate/sources/') as [$row]){
            $id=self::identifier($row['source_id']??null);self::variants($row,$sourceRef);
            if(isset($sources[$id]))self::fail('duplicate_source');
            $sources[$id]=true;$sourceVariants+=$row['variant_count'];
        }
        foreach(['claim_ids'=>count($claims),'claim_variants'=>$variants,'source_ids'=>count($sources),'source_variants'=>$sourceVariants] as $field=>$count)
            if(($candidate['coverage'][$field]??null)!==$count)self::fail('candidate_coverage');
        $byStage=[];foreach($registry as $r)$byStage[$r['stage_id']]=$r;
        $continuous=[];$packetStages=[];
        $coverage=self::listDocument($parts['semantic/packet_coverage_13.json']??'');
        foreach($coverage as $entry){
            $identity=$entry['server_identity']??[];$stage=$identity['stage_id']??'';$r=$byStage[$stage]??null;
            if(!$r||isset($packetStages[$stage])||($entry['every_saved_new_claim_covered']??null)!==true)self::fail('continuous_identity');
            foreach(['stage_index','packet_sha256','receipt_sha256'] as $f)if(($identity[$f]??null)!==$r[$f])self::fail('continuous_identity');
            $packetStages[$stage]=true;
            if(!is_array($entry['accepted_claim_ids']??null)||!array_is_list($entry['accepted_claim_ids']))self::fail('continuous_claims');
            foreach($entry['accepted_claim_ids'] as $id){
                if(!is_string($id)||!isset($claims[$id])||isset($continuous[$id]))self::fail('continuous_claim_partition');
                $continuous[$id]=true;
            }
        }
        $early=[];$holds=[];$model=[];$ambiguous=[];$seenScopes=[];$modelBytes=0;$expectedExclusions=[];
        $counts=array_fill_keys(array_values(self::EARLY),0);
        // A continuous claim identity does not assign its receipt to every old variant.
        foreach(array_keys($continuous) as $id){
            $location=$claims[$id];$row=self::object(substr($parts[$location['file']],$location['offset'],$location['length']));
            foreach($row['original_value'] as $vi=>$wrapper){
                $holds[]=['selector'=>self::selector($wrapper['record']),
                    'reason'=>'continuous_variant_not_individually_admitted','claim_id'=>$id,'variant_index'=>$vi];
                foreach(self::fullScopes($wrapper['record']) as $scope)$seenScopes[HotelServerInputs::key($scope)]=true;
            }
        }
        foreach(self::EARLY as $family=>$classification){
            foreach(self::rows($parts,$files,'semantic/'.$family.'/') as [$meta,$unused,$metaLocation]){
                $id=self::identifier($meta['claim_id']??null);$location=$claims[$id]??null;
                if(!$location||isset($early[$id])||isset($continuous[$id])||($meta['classification']??null)!==$classification
                    ||($meta['acceptance_granularity']??null)!=='claim_id; preserve all original variants and their distinct status'
                    ||!array_key_exists('assigned_continuous_receipt',$meta)||$meta['assigned_continuous_receipt']!==null
                    ||($meta['new_acceptance_credit']??null)!==0)self::fail('early_claim_partition');
                $where=$meta['candidate_location']??[];
                if(($where['path']??null)!==substr($location['file'],strlen('candidate/'))
                    ||($where['line_1based']??null)!==$location['line_1based']
                    ||($where['sha256']??null)!==$files[$location['file']]['sha256'])self::fail('candidate_location');
                $raw=substr($parts[$location['file']],$location['offset'],$location['length']);$row=self::object($raw);
                if(($meta['original_working_provenance']??null)!==$row['provenance']
                    ||!is_array($meta['variants']??null)||!array_is_list($meta['variants'])
                    ||count($meta['variants'])!==count($row['original_value']))self::fail('original_provenance');
                $authority=self::authority($meta,$row,$working,$ledger);
                if($classification==='explicit-accepted'&&!$authority)self::fail('explicit_acceptance_evidence');
                if($classification!=='explicit-accepted'&&$authority)self::fail('acceptance_classification');
                $early[$id]=['classification'=>$classification,'location'=>$metaLocation];++$counts[$classification];
                foreach($meta['variants'] as $vi=>$v){
                    $wrapper=$row['original_value'][$vi];$record=$wrapper['record'];
                    $recordRaw=self::rawAt($raw,['original_value',(string)$vi,'record']);
                    if(($v['variant_index']??null)!==$vi
                        ||($v['record_pointer']??null)!==$row['provenance']['json_pointer'].'/'.$vi.'/record'
                        ||!hash_equals(HotelServerInputs::sha($v['original_record_sha256']??null),hash('sha256',$recordRaw))
                        ||($v['origins']??null)!==($wrapper['origins']??null))self::fail('variant_provenance');
                    $fields=$v['observed_fields']??[];
                    foreach(['hotel'=>'hotel_key','answer'=>'text','source_ids'=>'source_ids'] as $field=>$original)
                        if(!array_key_exists($field,$fields)||$fields[$field]!==($record[$original]??null))self::fail('observed_field');
                    foreach(['country'=>'country','question'=>'question_id'] as $field=>$original)
                        if(isset($record[$original])&&$record[$original]!==''&&($fields[$field]??null)!==$record[$original])self::fail('observed_field');
                    $limits=array_intersect_key($record,array_fill_keys(self::LIMIT_FIELDS,true));
                    if(($v['original_status_and_limits']??null)!==$limits)self::fail('original_limits');
                    if($classification==='explicit-accepted'&&is_string($fields['answer'])&&$fields['answer']!==''){
                        $observed=['country'=>$fields['country']??null,'hotel_key'=>$fields['hotel'],
                            'question_id'=>$fields['question']??null,'source_ids'=>$fields['source_ids']];
                        foreach(self::fullScopes($observed) as $scope){
                            $key=HotelServerInputs::key($scope);
                            $expectedExclusions[$key]??=['scope'=>$scope,'claims'=>[]];
                            $expectedExclusions[$key]['claims'][]=['claim_id'=>$id,'variant_index'=>$vi,
                                'record_pointer'=>$v['record_pointer'],'classification'=>'explicit-accepted'];
                        }
                    }
                    // Derived country/question values are preserved, but never used as admission.
                    $selector=self::selector($record);$complete=self::fullScopes($record);
                    foreach($complete as $scope){
                        $key=HotelServerInputs::key($scope);
                        if(isset($seenScopes[$key])){
                            unset($model[$key]);$ambiguous[$key]=true;
                            $holds[]=['selector'=>$selector,'reason'=>'multiple_retained_records','claim_id'=>$id,'variant_index'=>$vi];
                        }
                        $seenScopes[$key]=true;
                    }
                    $eligible=$classification==='explicit-accepted'&&count($row['original_value'])===1
                        &&$authority&&$complete!==[]&&count($complete)<=32&&self::modelRecordSafe($record,$recordRaw)
                        &&!array_diff_key(array_fill_keys(array_column($complete,'source'),true),$sources);
                    $provenance=['claim_id'=>$id,'variant_index'=>$vi,'record_sha256'=>$v['original_record_sha256'],
                        'candidate_location'=>$where,'original_working_provenance'=>$row['provenance'],
                        'acceptance_granularity'=>$meta['acceptance_granularity'],'new_acceptance_credit'=>0];
                    $projected=count($complete)*(strlen(HotelServerInputs::json(['answer'=>$recordRaw,'retained_provenance'=>$provenance]))+2048);
                    $bound=$projected>262144||$modelBytes+$projected>4194304;
                    $eligible=$eligible&&!$bound;
                    if($eligible){
                        $modelBytes+=$projected;
                        foreach($complete as $scope){
                            $key=HotelServerInputs::key($scope);
                            if(isset($ambiguous[$key]))continue;
                            // A literal JSON fact retains its original text AND every qualifier.
                            $model[$key]=['scope'=>$scope,'answer'=>$recordRaw,'retained_provenance'=>$provenance];
                        }
                    } else {
                        $holds[]=['selector'=>$selector,'reason'=>$bound?'model_projection_bound':$classification,'claim_id'=>$id,'variant_index'=>$vi];
                    }
                }
            }
        }
        if(count($early)+count($continuous)!==count($claims))self::fail('incomplete_claim_partition');
        $semanticCounts=$semantic['counts']??[];
        foreach(['accepted_claim_ids_in_13_packets'=>count($continuous),'all_retained_claim_ids_accounted_for'=>count($claims),
            'early_explicit_accepted_claim_ids'=>$counts['explicit-accepted'],'early_inherited_baseline_claim_ids'=>$counts['inherited-baseline'],
            'early_acceptance_unresolved_claim_ids'=>$counts['acceptance-unresolved'],'matched_accepted_packets'=>count($packetStages)] as $k=>$n)
            if(($semanticCounts[$k]??null)!==$n)self::fail('semantic_coverage');
        $exclusions=[];$exclusionProofs=[];
        foreach(self::listDocument($parts['semantic/earlier_explicit_exclusions.json']??'') as $scope){
            $key=HotelServerInputs::key($scope);
            if(isset($exclusions[$key])||!isset($expectedExclusions[$key]))self::fail('early_exclusion_binding');
            $exclusions[$key]='earlier_explicit';
        }
        if(count($exclusions)!==count($expectedExclusions)||($semanticCounts['early_explicit_4tuple_exclusions']??null)!==count($exclusions))self::fail('early_exclusion_coverage');
        foreach(self::rows($parts,$files,'semantic/earlier-exclusion-provenance/') as [$proof,$raw]){
            $key=HotelServerInputs::key($proof['scope']??[]);$expected=$expectedExclusions[$key]??null;
            if(!$expected||isset($exclusionProofs[$key])||($proof['continuous_packet_or_receipt_assigned']??null)!==false
                ||($proof['semantic_key']??null)!==hash('sha256',self::rawAt($raw,['scope']))
                ||HotelServerInputs::json($proof['claim_provenance']??null)!==HotelServerInputs::json($expected['claims']))self::fail('early_exclusion_provenance');
            $exclusionProofs[$key]=true;
        }
        if(count($exclusionProofs)!==count($exclusions))self::fail('early_exclusion_provenance');
        foreach($model as $key=>$record)$exclusions[$key]='earlier_explicit';
        foreach($accepted as $record)if(isset($model[HotelServerInputs::key($record['scope'])]))self::fail('early_continuous_scope_conflict');
        $reserves=self::object($parts['candidate/protected-reserves.json']??'');
        if(!is_array($reserves['records']??null)||!array_is_list($reserves['records']))self::fail('protected_reserves');
        foreach($reserves['records'] as $i=>$reserve){
            $original=$reserve['original_value']??null;
            if(!is_array($original))self::fail('protected_reserves');
            $questions=$original['questions']??$original['exact_question_membership']??null;
            if(!is_array($questions)||!array_is_list($questions)||!$questions)$questions=[[]];
            foreach($questions as $q){
                if(!is_array($q))self::fail('protected_reserves');
                if(!isset($q['country'])&&self::country($original['country_scope']??null))$q['country']=$original['country_scope'];
                $holds[]=['selector'=>self::selector($q),'reason'=>'protected_reserve','reserve_index'=>$i];
            }
        }
        ksort($files);ksort($claims);ksort($early);ksort($model);ksort($exclusions);
        return ['schema'=>self::SCHEMA,'source_ref'=>$sourceRef,'source_working_sha256'=>$source['sha256'],
            'files'=>$files,'claims'=>$claims,'early'=>$early,'continuous_claim_ids'=>array_keys($continuous),
            'counts'=>['claims'=>count($claims),'claim_variants'=>$variants,'sources'=>count($sources),'source_variants'=>$sourceVariants,
                'continuous_claims'=>count($continuous),'early'=>$counts,'protected_reserves'=>count($reserves['records'])],
            'holds'=>$holds,'exclusions'=>$exclusions,'model_records'=>array_values($model),'new_acceptance_credit'=>0];
    }

    public static function verify(array $retained, array $parts): void
    {
        if(($retained['schema']??null)!==self::SCHEMA)self::fail('reader_required');
        $keys=array_keys($parts);$expected=array_keys($retained['files']);sort($keys);sort($expected);
        if($keys!==$expected)self::fail('complete_parts_required');
        foreach($retained['files'] as $key=>$entry)self::pin($parts[$key],$entry);
    }

    public static function assertScope(array $retained, array $scope): void
    {
        $key=HotelServerInputs::key($scope);
        if(isset($retained['exclusions'][$key]))throw new DomainException('accepted_or_reserved_scope');
        foreach($retained['holds'] as $hold){
            $overlap=true;foreach($hold['selector'] as $field=>$value)if($scope[$field]!==$value){$overlap=false;break;}
            if($overlap)throw new DomainException('retained_scope_unresolved');
        }
    }

    /** Bounded private reader. Raw JSON is authoritative; no row/variant is silently truncated. */
    public static function page(array $retained, array $parts, string $family, int $offset=0, int $limit=50): array
    {
        if(!preg_match('~\A(?:candidate|semantic)/[a-z][a-z0-9-]*\z~D',$family)||$offset<0||$limit<1||$limit>100)self::fail('read_bounds');
        $records=[];$seen=0;$size=0;$next=null;$found=false;
        foreach($retained['files'] as $key=>$entry)if(str_starts_with($key,$family.'/'))$found=true;
        if(!$found)self::fail('read_family');
        foreach(self::rows($parts,$retained['files'],$family.'/') as [$row,$raw,$location]){
            if($seen++<$offset)continue;
            $meta=null;$id=$row['claim_id']??null;
            if($family==='candidate/claims'&&is_string($id)){
                if(isset($retained['early'][$id])){
                    $where=$retained['early'][$id]['location'];
                    $meta=self::object(substr($parts[$where['file']],$where['offset'],$where['length']));
                } else $meta=['classification'=>'continuous-claim-level','variants_receipt_assignment'=>null,
                    'acceptance_granularity'=>'claim_id; preserve all original variants and their distinct status','new_acceptance_credit'=>0];
            }
            $item=['location'=>$location+['sha256'=>$retained['files'][$location['file']]['sha256']],
                'original_json'=>$raw,'authority'=>$meta];
            $itemSize=strlen(HotelServerInputs::json($item));
            if($itemSize>8388608)self::fail('read_row_bound');
            if(count($records)>=$limit||$size+$itemSize>8388608){$next=$seen-1;break;}
            $records[]=$item;$size+=$itemSize;
        }
        return ['schema'=>'hotel-retained-read-v1','family'=>$family,'offset'=>$offset,'records'=>$records,
            'next_offset'=>$next,'complete'=>$next===null,'new_acceptance_credit'=>0];
    }

    /** Read one pinned JSON document without changing its bytes or interpreting unknown fields. */
    public static function document(array $retained, array $parts, string $file): string
    {
        if(!isset($retained['files'][$file])||!str_ends_with($file,'.json'))self::fail('read_document');
        return $parts[$file];
    }

    private static function authority(array $meta,array $row,array $working,array $ledger): bool
    {
        $e=$meta['acceptance_evidence']??null;
        if(!is_array($e)||!is_array($e['direct']??null)||!is_array($e['ledger']??null))self::fail('acceptance_evidence');
        $original=$row['acceptance']??[];
        if(!is_array($original['direct_working_acceptance_refs']??null)||!is_array($original['explicit_import_ledger_refs']??null)
            ||($e['all_observed_ledger_statuses']??null)!==$original['explicit_import_ledger_refs'])self::fail('acceptance_evidence');
        $positive=false;
        foreach($e['direct'] as $d){
            $i=$d['variant_index']??null;$old=$working[$d['json_pointer']??'']??null;
            if(!is_int($i)||!isset($row['original_value'][$i])||!is_array($old)
                ||!self::scopeString($d['operation_id']??null)||!in_array($d,$original['direct_working_acceptance_refs'],true)
                ||($d['operation_id']??null)!==($row['original_value'][$i]['working_acceptance_operation']??null)
                ||($old['operation_id']??null)!==($d['operation_id']??null)
                ||($old['state']??null)!==($d['observed_state']??null)
                ||!in_array($old['state']??null,self::DIRECT_STATES,true))self::fail('direct_acceptance_binding');
            $positive=true;
        }
        foreach($e['ledger'] as $d){
            $old=$ledger[$d['json_pointer']??'']??null;
            if(!is_array($old)||!in_array($d,$original['explicit_import_ledger_refs'],true)||!in_array($row['claim_id'],$old['claim_ids']??[],true)
                ||($old['import_id']??null)!==($d['import_id']??null)
                ||(array_key_exists('source_receipts_ref',$old)?$old['source_receipts_ref']:($old['content_acceptance_ref']??null))!==($d['observed_acceptance_ref']??null)
                ||($old['packet_sha256']??null)!==($d['observed_packet_sha256']??null)
                ||($old['import_status']??null)!==($d['observed_import_status']??null)
                ||!in_array($old['import_status']??null,self::LEDGER_STATES,true))self::fail('ledger_acceptance_binding');
            $positive=true;
        }
        return $positive;
    }

    private static function variants(array $row,string $sourceRef): void
    {
        if(!is_array($row['original_value']??null)||!array_is_list($row['original_value'])||!$row['original_value']
            ||($row['variant_count']??null)!==count($row['original_value'])
            ||($row['provenance']['source_ref']??null)!==$sourceRef
            ||!is_string($row['provenance']['json_pointer']??null)||!str_starts_with($row['provenance']['json_pointer'],'/'))self::fail('original_variants');
        foreach($row['original_value'] as $v)if(!is_array($v)||!is_array($v['record']??null)||!is_array($v['origins']??null))self::fail('original_variants');
    }

    private static function selector(array $record): array
    {
        $out=[];
        if(self::country($record['country']??null))$out['country']=$record['country'];
        foreach(['hotel'=>'hotel_key','question'=>'question_id'] as $target=>$field)
            if(self::scopeString($record[$field]??null))$out[$target]=$record[$field];
        // A new source ID does not resolve an incomplete or uncertain old overlap.
        return $out;
    }
    private static function fullScopes(array $record): array
    {
        $s=self::selector($record);
        if(count($s)!==3||!is_array($record['source_ids']??null)||!array_is_list($record['source_ids'])||!$record['source_ids'])return [];
        $out=[];foreach($record['source_ids'] as $source){if(!self::scopeString($source))return [];$out[]=$s+['source'=>$source];}
        return $out;
    }
    private static function modelRecordSafe(array $record,string $raw): bool
    {
        if(!is_string($record['text']??null)||trim($record['text'])===''||strlen($raw)>32768)return false;
        $allowed=array_fill_keys(array_merge(['id','country','hotel_key','question_id','source_ids','text'],self::LIMIT_FIELDS),true);
        if(array_diff_key($record,$allowed))return false; // Unknown fields remain in readRetained, not silently stripped.
        return !preg_match('/claim[_ -]?key|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|private[_ -]?key|authorization|password|credentials/i',$raw);
    }
    private static function scopeString(mixed $v): bool {return is_string($v)&&$v!==''&&trim($v)===$v&&strlen($v)<=256;}
    private static function country(mixed $v): bool {return is_string($v)&&preg_match('/\A[A-Z]{2}\z/D',$v)===1;}
    private static function identifier(mixed $v): string {if(!self::scopeString($v))self::fail('identifier');return $v;}

    private static function index(string $raw): array
    {
        if(strlen($raw)>1048576)self::fail('index_bound');$index=self::object($raw);
        $keys=array_keys($index);sort($keys);
        if($keys!==['candidate','new_acceptance_credit','schema','semantic']||$index['schema']!==self::SCHEMA||$index['new_acceptance_credit']!==0)self::fail('index_required');
        foreach(['candidate','semantic'] as $kind){$ref=$index[$kind];if(!is_array($ref))self::fail('manifest_ref');self::path($ref['path']??null);self::metadata($ref);}
        return $index;
    }
    private static function entries(array $manifest): array
    {
        $out=[];$files=$manifest['files']??null;
        if(!is_array($files)||!array_is_list($files)||count($files)>self::MAX_FILES)self::fail('manifest_files');
        foreach($files as $entry){if(!is_array($entry))self::fail('file_metadata');self::metadata($entry);$path=self::path($entry['path']??null);
            if(isset($out[$path])||$path==='manifest.json')self::fail('duplicate_file');
            if(str_ends_with($path,'.jsonl')&&(!is_int($entry['rows']??null)||$entry['rows']<0||$entry['rows']>100000))self::fail('row_count');
            $out[$path]=$entry;
        }
        return array_values($out);
    }
    private static function metadata(array $entry): void
    {HotelServerInputs::sha($entry['sha256']??null);if(!is_int($entry['bytes']??null)||$entry['bytes']<1||$entry['bytes']>self::MAX_FILE)self::fail('file_bound');}
    private static function path(mixed $path): string
    {if(!is_string($path)||!preg_match('~\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\.(?:json|jsonl)\z~D',$path))self::fail('file_path');return $path;}
    private static function pin(mixed $raw,array $entry): void
    {self::metadata($entry);if(!is_string($raw)||strlen($raw)!==$entry['bytes']||!hash_equals($entry['sha256'],hash('sha256',$raw)))self::fail('file_pin');}
    private static function limits(array $parts): void
    {$total=0;if(count($parts)>self::MAX_FILES)self::fail('total_bound');foreach($parts as $raw){if(!is_string($raw)||strlen($raw)>self::MAX_FILE)self::fail('file_bound');$total+=strlen($raw);}if($total>self::MAX_TOTAL)self::fail('total_bound');}
    private static function readFile(string $root,string $relative,array $entry): string
    {
        self::path($relative);self::metadata($entry);$path=realpath($root.'/'.$relative);$real=realpath($root);
        if(!$path||!$real||!str_starts_with($path,rtrim($real,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)||!is_file($path))self::fail('private_file');
        $f=fopen($path,'rb');if($f===false)self::fail('private_file');
        try{$raw=stream_get_contents($f,self::MAX_FILE+1);}finally{fclose($f);}
        self::pin($raw,$entry);return $raw;
    }
    private static function object(string $raw): array
    {$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||array_is_list($v))self::fail('object');return $v;}
    private static function listDocument(string $raw): array
    {$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||!array_is_list($v))self::fail('list');return $v;}

    /** Byte offsets refer to exact original lines, excluding their one terminal LF. */
    private static function rows(array $parts,array $files,string $prefix): Generator
    {
        foreach($files as $file=>$entry){
            if(!str_starts_with($file,$prefix)||!str_ends_with($file,'.jsonl'))continue;
            $raw=$parts[$file];$offset=0;$line=0;$n=strlen($raw);
            while($offset<$n){
                $end=strpos($raw,"\n",$offset);if($end===false)self::fail('jsonl_terminal_lf');
                $text=substr($raw,$offset,$end-$offset);if($text==='')self::fail('empty_jsonl_row');
                ++$line;yield [self::object($text),$text,['file'=>$file,'line_1based'=>$line,'offset'=>$offset,'length'=>$end-$offset]];
                $offset=$end+1;
            }
            if($line!==($entry['rows']??null))self::fail('row_count');
        }
    }

    /** Select raw JSON bytes; never re-encode Python canonical record digests in PHP. */
    private static function rawAt(string $json,array $tokens): string
    {
        $i=0;self::white($json,$i);$start=$i;
        if(!$tokens){self::skip($json,$i);return substr($json,$start,$i-$start);}
        $token=array_shift($tokens);$open=$json[$i++]??'';$index=0;$seen=[];
        if($open!=='{'&&$open!=='[')self::fail('raw_pointer');
        while(true){
            self::white($json,$i);if(($json[$i]??'')===($open==='{'?'}':']'))break;
            if($open==='{'){
                $keyStart=$i;self::skip($json,$i);$key=json_decode(substr($json,$keyStart,$i-$keyStart),true,4,JSON_THROW_ON_ERROR);
                if(!is_string($key)||isset($seen[$key]))self::fail('raw_pointer');$seen[$key]=true;
                self::white($json,$i);if(($json[$i++]??'')!==':')self::fail('raw_pointer');
            }else $key=(string)$index++;
            self::white($json,$i);$valueStart=$i;self::skip($json,$i);
            if($key===$token)return self::rawAt(substr($json,$valueStart,$i-$valueStart),$tokens);
            self::white($json,$i);$sep=$json[$i++]??'';if($sep===($open==='{'?'}':']'))break;if($sep!==',')self::fail('raw_pointer');
        }
        self::fail('raw_pointer');
    }
    private static function white(string $s,int &$i): void {while(isset($s[$i])&&str_contains(" \r\n\t",$s[$i]))++$i;}
    private static function skip(string $s,int &$i): void
    {
        self::white($s,$i);$c=$s[$i]??'';
        if($c==='"'){
            ++$i;while(isset($s[$i])){$c=$s[$i++];if($c==='\\'){++$i;continue;}if($c==='"')return;}self::fail('raw_json');
        }
        if($c==='{'||$c==='['){
            $close=$c==='{'?'}':']';++$i;
            while(isset($s[$i])){self::white($s,$i);if(($s[$i]??'')===$close){++$i;return;}
                if(($s[$i]??'')===','||($s[$i]??'')===':'){++$i;continue;}self::skip($s,$i);}
            self::fail('raw_json');
        }
        $start=$i;while(isset($s[$i])&&!str_contains(" ,]}\r\n\t:",$s[$i]))++$i;
        if($i===$start)self::fail('raw_json');
    }
    private static function fail(string $reason): never {throw new DomainException('retained_'.$reason);}
}
