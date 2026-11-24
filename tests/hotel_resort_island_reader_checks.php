<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** A separate process lets both public entrypoints read only the disposable fixture catalogue. */
function hotelResortIslandReaderChecks(): void
{
    $command = [PHP_BINARY, __FILE__, '--isolated'];
    $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start island reader regression');
    fclose($pipes[0]);
    $report=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('Island reader regression failed: '.$error);
    $checks=json_decode($report,true,32,JSON_THROW_ON_ERROR);
    draftContextCheck('island core, pinned source pair, exact budgets and fail-closed public reads', $checks['passed']===true);
}

if (($argv[1]??'')!=='--isolated'||realpath($argv[0])!==__FILE__) return;

$tmp=sys_get_temp_dir().'/hotel-island-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);mkdir($tmp.'/services',0700);mkdir($tmp.'/knowledge',0700);
$data=$tmp.'/knowledge/hotels';mkdir($data,0700);
copy(dirname(__DIR__).'/services/ManagerHotelKnowledge.php',$tmp.'/services/ManagerHotelKnowledge.php');
require $tmp.'/services/ManagerHotelKnowledge.php';
function islandNeed(bool $ok,string $detail): void { if (!$ok) throw new RuntimeException($detail); }
$canon=(new ReflectionMethod(ManagerHotelKnowledge::class,'canonical'))->getClosure();
$hash=static fn($value)=>hash('sha256',$canon($value));
$ref=['catalog'=>'anytour','id'=>998811];$name='TEST ONLY ISLAND PROPERTY';
$now=strtotime('2026-11-24T12:00:00Z');$date=static fn($seconds)=>gmdate('Y-m-d\TH:i:s\Z',$now+$seconds);
$source=['hotel_key'=>'TEST998811','kind'=>'official','publication_date'=>null,'retrieved_on'=>'2026-11-24',
    'read_scope'=>'Synthetic reader fixture','limitations'=>'Fixture data only'];
$property=['id'=>'fixture-property','url'=>'https://property.example/fixture']+$source;
$authority=['id'=>'fixture-authority','url'=>'https://survey.example/fixture']+$source;
$location=['kind'=>'resort_island','name'=>'Fixture Island','admin_atoll'=>'Fixture Administrative Atoll',
    'authority_source_id'=>$authority['id'],'authority_source_record_sha256'=>$hash($authority)];
$anchor=['schema_version'=>'resort_island_anchor_v1','research_source_id'=>$property['id'],
    'research_source_record_sha256'=>$hash($property),'official_url'=>$property['url'],'address'=>'Synthetic address','country'=>'Maldives',
    'locality'=>$location,'property_atoll_label'=>'Fixture Operator Atoll','postal_code'=>'00123'];
$core=['project_key'=>'anytour','research_hotel_key'=>'TEST998811','hotel_ref'=>$ref,
    'accepted_chain'=>[['namespace'=>'legacy_catalog/catalog_hotels.id','id'=>998812],['namespace'=>'anytour','id'=>998811,'catalog_revision'=>1],
        ['namespace'=>'local-tv','id'=>998813,'catalog_revision'=>3]],'identity_anchor'=>$anchor,
    'proofs'=>['native.json'=>hash('sha256','synthetic native'),'property.json'=>$hash($property),'authority.json'=>$hash($authority)]];
$fact=['id'=>'sample-fact','hotel_key'=>'TEST998811','kind'=>'official_declared','valid_at_travel'=>'unverified','declared'=>true,
    'scope'=>['kind'=>'hotel','key'=>'fixture'],'text'=>'Fixture declaration','aspect'=>'location','source_ids'=>['fixture-property']];
$bundle=['schema_version'=>'hotel_reference_v1','status'=>'SEALED_AUTHORING_DATA_ONLY','project_key'=>'anytour','hotel_ref'=>$ref,
    'research_hotel_key'=>'TEST998811','trusted_selector_required'=>true,'price_authority'=>'none','runtime_admission_verified'=>false,
    'facts'=>[$fact],'sources'=>[$property,$authority],'coverage'=>['complete'=>false,'selected_claim_count'=>1],
    'material_limits'=>['note'=>'Fixture only']];
$entry=['status'=>'accepted','source_identity'=>['adapter'=>'tourvisor-snapshot-v1','hotel'=>'998813','country'=>'8'],
    'accepted_source_names'=>[$name],'bundle_file'=>'anytour-998811-fixture.json',
    'approval'=>['library_file_id'=>'libfile_'.str_repeat('c',32),'common_version'=>1,'member'=>'synthetic-fixture/accepted-binding.json',
        'scope'=>'Test-only isolated fixture, unrelated to a real property or owner.']];
$native=['project_key'=>'anytour','source_identity'=>$entry['source_identity'],'selected_hotel'=>$name,'selected_room'=>'Fixture Room',
    'provenance'=>['message_id'=>1,'event_id'=>1,'publication_sha256'=>hash('sha256','fixture publication'),'source_sha256'=>hash('sha256','fixture native')]];
$retained=['hotel'=>['anytour_hotel_id'=>$ref['id'],'name'=>$name,'category'=>null,'country'=>'Maldives','region'=>null],
    'provider'=>'tourvisor','operator_raw'=>null,'offer_digest'=>hash('sha256','fixture offer'),'source_scope_digest'=>hash('sha256','fixture source'),
    'checkin'=>'2026-12-05','nights'=>7,'party'=>['adults'=>2,'children'=>0,'child_ages'=>[]],'room_raw'=>'Fixture Room','meal_raw'=>null,'placement_raw'=>null,
    'price'=>['amount'=>'1','currency'=>'RUB','basis'=>'whole_party','source_listing_state'=>'legacy_ready_estimate','source_final_price_verified'=>false,'current_price_verified'=>false,'refresh_required'=>true],
    'observed_at'=>$date(-300),'last_seen_at'=>$date(-120),'expires_at'=>$date(3600),'availability'=>'unverified','booking_enabled'=>false,'photos_read'=>false];
$install=static function($c,$b,$e,bool $duplicate=false) use($data,$canon,$hash):int {
    $e['accepted_binding']=$c;$e['binding_revision']=$hash($c);$b['binding_revision']=$hash($c);
    foreach (['facts'=>'claims','sources'=>'sources'] as $key=>$field) {
        $b['record_hashes'][$field]=[];
        foreach($b[$key] as $record) $b['record_hashes'][$field][$record['id']]=$hash($record);
    }
    $bytes=$canon($b)."\n";file_put_contents($data.'/'.$e['bundle_file'],$bytes);$e['bundle_sha256']=hash('sha256',$bytes);
    $manifest=$canon(['schema_version'=>1,'version'=>'synthetic-island-test','project_key'=>'anytour','bindings'=>$duplicate?[$e,$e]:[$e]]);
    file_put_contents($data.'/manifest.json',$manifest);
    file_put_contents($data.'/catalogue.json',$canon(['schema_version'=>'hotel_catalogue_v1','project_key'=>'anytour',
        'shards'=>[['file'=>'manifest.json','git_blob_sha'=>sha1('blob '.strlen($manifest)."\0".$manifest)]]]));
    return strlen($bytes);
};
$read=static function($n,$r,$country='Maldives') use($now,$date):array {
    return ['native'=>ManagerHotelKnowledge::capture($n,$now),'retained'=>ManagerHotelKnowledge::captureRetainedCandidate($r,hash('sha256','fixture request'),$date(0),$country,$now)];
};
$cases=[];$sizes=[];
try {
    $sizes['small_bundle']=$install($core,$bundle,$entry);$result=$read($native,$retained);
    foreach($result as $type=>$reference) {
        islandNeed($reference['status']==='available'&&$reference['facts']===json_decode($canon([$fact]),true)
            &&$reference['sources']===json_decode($canon([$property,$authority]),true)&&$reference['binding_revision']===$hash($core),'positive '.$type.' '.($reference['reason']??''));
        $sizes['small_'.$type]=strlen($canon($reference));
    }
    $expect=static function(string $name,array $pair,string $reason) use (&$cases):void {
        foreach($pair as $type=>$r) islandNeed($r['status']==='held'&&$r['reason']===$reason&&$r['facts']===[],$name.' '.$type.' '.($r['reason']??''));
        $cases[$name]=$reason;
    };
    $negative=static function(string $name,callable $change,string $reason) use($core,$bundle,$entry,$native,$retained,$install,$read,$expect):void {
        $c=$core;$b=$bundle;$e=$entry;$change($c,$b,$e);$install($c,$b,$e);$expect($name,$read($native,$retained),$reason);
    };
    $negative('unknown anchor version',function(&$c){$c['identity_anchor']['schema_version']='future';},'manifest_invalid');
    $negative('unknown locality type',function(&$c){$c['identity_anchor']['locality']['kind']='archipelago';},'manifest_invalid');
    $negative('mixed city and island',function(&$c){$c['identity_anchor']['city']='Postal City';},'manifest_invalid');
    $negative('missing administrative atoll',function(&$c){unset($c['identity_anchor']['locality']['admin_atoll']);},'manifest_invalid');
    $negative('multiple islands',function(&$c){$c['identity_anchor']['locality']['name']=['First Island','Second Island'];},'manifest_invalid');
    $negative('numeric postcode',function(&$c){$c['identity_anchor']['postal_code']=123;},'manifest_invalid');
    $negative('country outside typed form',function(&$c){$c['identity_anchor']['country']='Egypt';},'manifest_invalid');
    $negative('mismatched native country',function(&$c,&$b,&$e){$e['source_identity']['country']='1';},'manifest_invalid');
    $negative('missing property canonical pin',function(&$c){unset($c['identity_anchor']['research_source_record_sha256']);},'manifest_invalid');
    $negative('missing native proof set',function(&$c){$c['proofs']=[];},'manifest_invalid');
    $negative('duplicate source role',function(&$c){$c['identity_anchor']['locality']['authority_source_id']='fixture-property';},'manifest_invalid');
    $negative('copied authority pin',function(&$c){$c['identity_anchor']['locality']['authority_source_record_sha256']=str_repeat('a',64);},'bundle_invalid');
    $negative('changed source record resealed only in bundle',function(&$c,&$b){$b['sources'][1]['limitations']='different bytes';},'bundle_invalid');
    $negative('missing authority record',function(&$c,&$b){array_pop($b['sources']);},'bundle_invalid');
    $negative('different official property URL',function(&$c){$c['identity_anchor']['official_url']='https://other.example/fixture';},'bundle_invalid');
    $negative('same publisher host under another URL',function(&$c,&$b)use($hash){$b['sources'][1]['url']='https://PROPERTY.EXAMPLE/other';$c['identity_anchor']['locality']['authority_source_record_sha256']=$hash($b['sources'][1]);},'bundle_invalid');
    $negative('missing approval',function(&$c,&$b,&$e){unset($e['approval']);},'manifest_invalid');
    $install($core,$bundle,$entry,true);$expect('duplicate native and own identity',$read($native,$retained),'manifest_ambiguous');
    $unknown=$native;$unknown['source_identity']['hotel']='997771';$other=$retained;$other['hotel']['anytour_hotel_id']=997771;
    $install($core,$bundle,$entry);$expect('unknown property',$read($unknown,$other),'binding_unresolved');
    $unknown=$native;$unknown['selected_hotel']='Different resort';$other=$retained;$other['hotel']['name']='Different resort';
    $expect('neighbouring property',$read($unknown,$other),'source_name_mismatch');
    $other=$retained;$other['hotel']['country']='Egypt';$foreign=$read($native,$other,'Egypt')['retained'];
    islandNeed($foreign['reason']==='source_country_mismatch'&&$foreign['facts']===[],'wrong retained request country');
    $cases['wrong retained request country']=$foreign['reason'];
    $c=$core;$c['identity_anchor']['postal_code']=null;$install($c,$bundle,$entry);
    foreach($read($native,$retained) as $r) islandNeed($r['status']==='available','unknown postal code is supported');
    $install($core,$bundle,$entry);
    $maxAlias=str_repeat('"',191);$maxName=str_pad($maxAlias,400);$maxRoom=str_repeat('"',800);
    $e=$entry;$e['accepted_source_names']=[$maxAlias];$n=$native;$n['selected_hotel']=$maxName;$n['selected_room']=$maxRoom;
    $r=$retained;$r['hotel']['name']=$maxName;$r['room_raw']=$maxRoom;
    // The retained adapter accepts 400/800 bytes. A double quote expands to two JSON bytes.
    $b=$bundle;$b['material_limits']['note']='';$install($core,$b,$e);
    $plain=$read($n,$r)['retained'];islandNeed($plain['status']==='available','max retained fields');
    $sizes['max_escaped_selector_before_padding']=strlen($canon($plain));$cap=ManagerHotelKnowledge::MAX_REFERENCE_BYTES;
    $b['material_limits']['note']=str_repeat('X',$cap-$sizes['max_escaped_selector_before_padding']);
    $sizes['bundle_at_exact_projection_cap']=$install($core,$b,$e);$full=$read($n,$r)['retained'];
    islandNeed($full['status']==='available'&&strlen($canon($full))===$cap,'at projection cap');$sizes['projection_at_cap']=$cap;
    $b['material_limits']['note'].='X';$install($core,$b,$e);$over=$read($n,$r)['retained'];
    islandNeed($over['reason']==='projection_too_large'&&$over['facts']===[],'over projection cap');$cases['projection cap plus one']=$over['reason'];
    $nativeMax=$native;$nativeMax['selected_hotel']=$maxAlias;$nativeMax['selected_room']=str_repeat('🛏',300);
    $nativeBundle=$bundle;$nativeBundle['material_limits']['note']='';$install($core,$nativeBundle,$e);
    $beforeNative=ManagerHotelKnowledge::capture($nativeMax,$now);
    islandNeed($beforeNative['status']==='available','max native fields');
    $sizes['max_native_selector_before_padding']=strlen($canon($beforeNative));
    $nativeBundle['material_limits']['note']=str_repeat('X',$cap-$sizes['max_native_selector_before_padding']);
    $sizes['native_bundle_at_exact_projection_cap']=$install($core,$nativeBundle,$e);
    $nativeFull=ManagerHotelKnowledge::capture($nativeMax,$now);
    islandNeed($nativeFull['status']==='available'&&strlen($canon($nativeFull))===$cap,'at native projection cap');
    $nativeBundle['material_limits']['note'].='X';$install($core,$nativeBundle,$e);
    $nativeOver=ManagerHotelKnowledge::capture($nativeMax,$now);
    islandNeed($nativeOver['reason']==='projection_too_large'&&$nativeOver['facts']===[],'over native projection cap');
    $sizes['native_projection_at_cap']=$cap;$cases['native projection cap plus one']=$nativeOver['reason'];
    $b['material_limits']['note']=str_repeat('X',$cap);$sizes['oversized_file']=$install($core,$b,$entry);$over=$read($native,$retained);
    // Restore the normal selector names for both reads; oversized files are rejected before parsing.
    foreach ($over as $refType=>$reference) islandNeed($reference['status']==='held'&&$reference['facts']===[],'oversized file '.$refType);
    $cases['oversized file']=$over['retained']['reason'];
    echo json_encode(['passed'=>true,'fixture_own_id'=>$ref['id'],'negative'=>$cases,'measured_bytes'=>$sizes],JSON_THROW_ON_ERROR),"\n";
} finally {
    foreach(glob($data.'/*')?:[] as $file) unlink($file);
    rmdir($data);rmdir($tmp.'/knowledge');unlink($tmp.'/services/ManagerHotelKnowledge.php');rmdir($tmp.'/services');rmdir($tmp);
}
