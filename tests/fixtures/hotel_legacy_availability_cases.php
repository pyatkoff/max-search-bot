<?php
declare(strict_types=1);

/** Owner-directed partial history; all identities and files below are synthetic. */
function haBytes(bool $omitQuarantine=true): array
{
    $ret=hsLegacy();$pin=hash('sha256',$ret['receipt_bytes']);
    $registry=HotelServerInputs::registry($ret,$pin);$bytes=hsInputs()->package()['bytes'];
    $unavailable=[];
    foreach(array_slice($registry,13) as $row)$unavailable[]=$row+['kind'=>'accepted','reason'=>'original_unavailable'];
    if($omitQuarantine)foreach(HotelServerInputs::quarantine($ret,$pin) as $row)
        $unavailable[]=$row+['kind'=>'quarantined','reason'=>'original_unavailable'];
    $bytes['accepted_mapping']=HotelServerInputs::json(array_slice(json_decode($bytes['accepted_mapping'],true,64,JSON_THROW_ON_ERROR),0,13));
    $bytes['accepted_context']=HotelServerInputs::json(array_slice(json_decode($bytes['accepted_context'],true,64,JSON_THROW_ON_ERROR),0,13));
    if($omitQuarantine)$bytes['quarantine_mapping']='[]';
    $bytes[HotelServerInputs::AVAILABILITY]=HotelServerInputs::json([
        'schema'=>'hotel-legacy-availability-policy-v1','mode'=>'available_confirmed','retirement_sha256'=>$pin,
        'owner_decision'=>'SYNTHETIC-OWNER-DECISION','unknown_legacy_overlap_acknowledged'=>true,
        'new_acceptance_credit'=>0,'unavailable'=>$unavailable]);
    return $bytes;
}
function haInputs(array $bytes): HotelServerInputs
{
    $ret=hsLegacy();return HotelServerInputs::validate($ret,hash('sha256',$ret['receipt_bytes']),$bytes);
}
function haEditPolicy(array $bytes,callable $edit): array
{
    $policy=json_decode($bytes[HotelServerInputs::AVAILABILITY],true,64,JSON_THROW_ON_ERROR);
    $edit($policy);$bytes[HotelServerInputs::AVAILABILITY]=HotelServerInputs::json($policy);return $bytes;
}
function haRemoveTree(string $path): void
{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file)
        $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($path);
}

$ha=haInputs(haBytes())->package();
hsCheck(count($ha['registry'])===19&&count($ha['quarantine'])===1,'availability preserves full immutable historical registry and quarantine');
hsCheck(count($ha['accepted_context'])===13&&$ha['retained_admission']['available_accepted']===13
    &&$ha['retained_admission']['unavailable_accepted']===6&&$ha['retained_admission']['unavailable_quarantine']===1
    &&$ha['retained_admission']['unknown_legacy_overlap']===true&&$ha['retained_admission']['new_acceptance_credit']===0,
    'available history reports13 admitted six unavailable one quarantined and no credit');
hsCheck($ha['pins'][HotelServerInputs::AVAILABILITY]===hash('sha256',$ha['bytes'][HotelServerInputs::AVAILABILITY]),
    'availability decision is included in immutable input pins');
$bad=haBytes();unset($bad[HotelServerInputs::AVAILABILITY]);
hsReject(fn()=>haInputs($bad),'partial history remains rejected without explicit policy','complete_historical_mapping_required');
foreach([
    'wrong retirement'=>static function(array &$p):void{$p['retirement_sha256']=str_repeat('0',64);},
    'missing decision'=>static function(array &$p):void{$p['owner_decision']='';},
    'unacknowledged overlap'=>static function(array &$p):void{$p['unknown_legacy_overlap_acknowledged']=false;},
    'forged import credit'=>static function(array &$p):void{$p['new_acceptance_credit']=1;},
    'unknown policy field'=>static function(array &$p):void{$p['skip_everything']=true;}
] as $label=>$edit)hsReject(fn()=>haInputs(haEditPolicy(haBytes(),$edit)),$label,'legacy_availability_policy_required');
hsReject(fn()=>haInputs(haEditPolicy(haBytes(),static function(array &$p):void{$p['unavailable'][]=$p['unavailable'][0];})),
    'duplicate unavailable identity rejected','legacy_unavailable_identity_required');
hsReject(fn()=>haInputs(haEditPolicy(haBytes(),static function(array &$p):void{$p['unavailable'][0]['stage_id']=str_repeat('0',64);})),
    'unknown unavailable stage rejected','legacy_unavailable_identity_required');
foreach(['packet_sha256','receipt_sha256'] as $field)
    hsReject(fn()=>haInputs(haEditPolicy(haBytes(),static function(array &$p)use($field):void{$p['unavailable'][0][$field]=str_repeat('0',64);})),
        'unavailable '.$field.' stays bound to immutable receipt','legacy_unavailable_identity_mismatch');
hsReject(fn()=>haInputs(haEditPolicy(haBytes(),static function(array &$p):void{$p['unavailable'][0]['stage_index']=(string)$p['unavailable'][0]['stage_index'];})),
    'unavailable stage index cannot be coerced','legacy_unavailable_identity_mismatch');
hsReject(fn()=>haInputs(haEditPolicy(haBytes(),static function(array &$p):void{$p['unavailable'][0]['scopes']=[];})),
    'unavailable original cannot carry invented scopes','legacy_unavailable_identity_required');
hsReject(fn()=>haInputs(haEditPolicy(haBytes(),static function(array &$p):void{
    $ret=hsLegacy();$p['unavailable']=[];
    foreach(HotelServerInputs::registry($ret,hash('sha256',$ret['receipt_bytes'])) as $row)
        $p['unavailable'][]=$row+['kind'=>'accepted','reason'=>'original_unavailable'];
})),'empty admitted history is not a restart baseline','available_accepted_history_required');
$bad=haBytes();$bad['accepted_mapping']=hsInputs()->package()['bytes']['accepted_mapping'];
hsReject(fn()=>haInputs($bad),'omitted records cannot also be mapped','complete_historical_mapping_required');
$bad=haBytes();$bad['accepted_context']=hsInputs()->package()['bytes']['accepted_context'];
hsReject(fn()=>haInputs($bad),'unavailable records cannot enter the model projection','unaccepted_baseline_projection');
$bad=haBytes();$m=json_decode($bad['accepted_mapping'],true,64,JSON_THROW_ON_ERROR);array_pop($m);$bad['accepted_mapping']=HotelServerInputs::json($m);
hsReject(fn()=>haInputs($bad),'every available acceptance still requires mapping','complete_historical_mapping_required');
$bad=haBytes(false);$bad['quarantine_mapping']='[]';
hsReject(fn()=>haInputs($bad),'unlisted quarantine still requires mapping','complete_quarantined_mapping_required');
hsCheck(haInputs(haBytes(false))->package()['retained_admission']['mapped_quarantine']===1,
    'available quarantine retains its semantic exclusion');
$bad=hsInputs()->package()['bytes'];$bad[HotelServerInputs::AVAILABILITY]=haBytes(false)[HotelServerInputs::AVAILABILITY];
$bad=haEditPolicy($bad,static function(array &$p):void{
    $ret=hsLegacy();$registry=HotelServerInputs::registry($ret,hash('sha256',$ret['receipt_bytes']));
    $p['unavailable']=[$registry[0]+['kind'=>'accepted','reason'=>'original_unavailable']];
});
foreach(['accepted_mapping','accepted_context'] as $name)$bad[$name]=HotelServerInputs::json(array_slice(json_decode($bad[$name],true,64,JSON_THROW_ON_ERROR),1));
[$bad['baseline'],$bad['retained_parts']]=hrBundle([['classification'=>'continuous','records'=>[hrRecord('old-1','synthetic-question')]]]);
hsReject(fn()=>haInputs($bad),'unavailable packet cannot reenter through retained continuous coverage','retained_continuous_identity');

// This is the actual manifest/loader path, using only a disposable fixture database and files.
$f=hsFixture();$root=$f['dir'].'/private-inputs';mkdir($root,0700);
try {
    $ret=hsLegacy();$bytes=haBytes();$manifest=['schema'=>'hotel-server-input-manifest-v1','files'=>[]];
    foreach(array_merge(HotelServerInputs::REQUIRED,[HotelServerInputs::AVAILABILITY]) as $name){
        $path=$name.'.json';file_put_contents($root.'/'.$path,$bytes[$name]);
        $manifest['files'][$name]=['path'=>$path,'sha256'=>hash('sha256',$bytes[$name])];
    }
    foreach($bytes['retained_parts'] as $path=>$raw){
        $dir=dirname($root.'/'.$path);if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($root.'/'.$path,$raw);
    }
    file_put_contents($root.'/manifest.json',HotelServerInputs::json($manifest));
    $f['p']->exec('CREATE TABLE hotel_work_retirements(queue_id VARBINARY(80) PRIMARY KEY,snapshot_bytes LONGTEXT NOT NULL,original_state_sha256 CHAR(64) NOT NULL,receipt_bytes LONGTEXT NOT NULL)');
    $s=$f['p']->prepare('INSERT INTO hotel_work_retirements VALUES(?,?,?,?)');
    $s->execute([HotelServerInputs::OLD_QUEUE,$ret['snapshot_bytes'],$ret['original_state_sha256'],$ret['receipt_bytes']]);$s=null;
    $loaded=HotelServerInputs::load($f['p'],hash('sha256',$ret['receipt_bytes']),$root,$root.'/manifest.json');
    hsCheck($loaded->package()['inputs_sha256']===haInputs($bytes)->package()['inputs_sha256'],'loader pins optional availability file and all retained bytes');
    file_put_contents($root.'/'.HotelServerInputs::AVAILABILITY.'.json',$bytes[HotelServerInputs::AVAILABILITY].' ');
    hsReject(fn()=>HotelServerInputs::load($f['p'],hash('sha256',$ret['receipt_bytes']),$root,$root.'/manifest.json'),
        'changed availability file fails manifest hash','private_file_hash');
}finally{haRemoveTree($root);hsCleanup($f);}

// The same existing producer/reviewer/accept/next-stage path consumes the partial seed.
$f=hsFixture();try{
    $s=$f['cycle']->bootstrap('NEW',haInputs(haBytes()),hsPlan(),static fn():bool=>true);
    hsCheck($s['state']==='disabled'&&$s['historical_accepted']===19&&$s['new_accepted']===0
        &&$s['retained_admission']['available_accepted']===13,'partial bootstrap dormant with truthful historical/admitted counts');
    hsCheck(count($f['cycle']->readAccepted('NEW')['records'])===13,'reader exposes only available admitted history');
    hsCheck($f['cycle']->tick('NEW')['state']==='disabled'&&count($f['provider']->requests)===0,'partial policy never enables the campaign');
    hsEnable($f);$f['cycle']->tick('NEW');$f['cycle']->tick('NEW');$s=$f['cycle']->tick('NEW');
    $receipt=json_decode($f['p']->query('SELECT receipt_bytes FROM hotel_server_receipts')->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    hsCheck($s['state']==='ready'&&$s['stage_index']===1&&$s['new_accepted']===1
        &&$receipt['retained_admission']===$s['retained_admission'],'new acceptance and next stage preserve partial-history disclosure');
    hsCheck(count($f['provider']->requests[0]['accepted_context']['records'])===0
        &&!str_contains(HotelServerInputs::json($f['provider']->requests[0]),'SYNTHETIC-OWNER-DECISION'),
        'unrelated historical facts and private decision stay out of assigned model request');
    $s=$f['cycle']->tick('NEW');
    hsCheck($s['state']==='candidate'&&count($f['provider']->requests)===3,'next ordinary tick automatically continues the next synthetic stage');
}finally{hsCleanup($f);}

foreach(['old-1','reserve'] as $hotel){
    $f=hsFixture();try{
        $plan=hsPlan();$plan['stages'][0]=[hsScope($hotel)];
        hsReject(fn()=>$f['cycle']->bootstrap('NEW',haInputs(haBytes()),$plan,static fn():bool=>true),
            'partial policy preserves available history/reserve '.$hotel,'accepted_or_reserved_scope');
    }finally{hsCleanup($f);}
}
$f=hsFixture();try{
    $bytes=haBytes();[$bytes['baseline'],$bytes['retained_parts']]=hrBundle([
        ['classification'=>'acceptance-unresolved','records'=>[hrRecord('new-0','synthetic-question')]]]);
    hsReject(fn()=>$f['cycle']->bootstrap('NEW',haInputs($bytes),hsPlan(),static fn():bool=>true),
        'explicit unavailable policy never waives unresolved overlap in available baseline','retained_scope_unresolved');
}finally{hsCleanup($f);}
$f=hsFixture();try{
    $f['cycle']->bootstrap('NEW',haInputs(haBytes()),hsPlan(),static fn():bool=>true);hsEnable($f);
    $f['provider']->loseAck=true;$s=$f['cycle']->tick('NEW');$f['cycle']->tick('NEW');
    hsCheck($s['state']==='unknown'&&count($f['provider']->requests)===1,'partial-history choice never replays an UNKNOWN invocation');
}finally{hsCleanup($f);}
