<?php
declare(strict_types=1);
/** Pure fixture tests only: no config bootstrap, PDO connection, network, or production invocation. */
define('HOTEL_ENTRY_PRICE_TEST_LIBRARY',true);
require __DIR__.'/program.php';
$class=$argv[1]??dirname(__DIR__,2).'/services/ModeledConversionDealValue.php';
if(!is_file($class)||hash_file('sha256',$class)!==EXPECTED_PRICE_CLASS_SHA256){
 fwrite(STDERR,"canonical_price_class_missing_or_changed\n");exit(1);
}
require_once $class;
$checks=0;
function check(bool $ok,string $name):void {
 global $checks;$checks++;if(!$ok)throw new RuntimeException('FAIL: '.$name);
}
function rejected(callable $fn,string $reason,string $name):void {
 try{$fn();}catch(RuntimeException $e){check($e->getMessage()===$reason,$name);return;}
 check(false,$name);
}
function entry(int $id,int $publication=10,int $version=100):array {
 return ['entry_id'=>$id,'conversation_id'=>90000+$id,'publication_id'=>$publication,'version_id'=>$version,
  'entered_at'=>'2026-11-12 10:00:00','external_message_id'=>'mid.PUBLIC_POST','platform'=>'max',
  'channel_id'=>'-80095447388179','source_key'=>'max_anytour_msk1','binding_valid'=>1];
}
function version(int $id,int $publication,string $text,string $state='present'):array {
 return ['version_id'=>$id,'publication_id'=>$publication,'content_hash'=>str_repeat('a',64),
  'text_state'=>$state,'post_text'=>$text];
}
try{
 // Exact immutable version wins. A later version is a separate row, never a fallback.
 $entries=[entry(1),entry(2),entry(3,10,101)];
 $entries[1]['conversation_id']=$entries[0]['conversation_id'];
 $requests=audit_version_requests($entries);
 $prices=audit_version_projection([
  version(100,10,'Цена от 150 000 руб за двоих'),
  version(101,10,'Цена 180 000–220 000 руб за 2 взрослых')
 ],$requests);
 $out=audit_project($entries,$prices,2);$old=$out['posts'][0];$new=$out['posts'][1];
 check(count($out['posts'])===2&&$old['version_id']===100&&$new['version_id']===101,'same publication keeps two pinned versions');
 check($old['entries']===2&&$old['distinct_dialogues']===1&&$new['entries']===1,'replayed conversation is counted within each version only');
 check($old['price']['point']===150000&&$old['price']['high']===null&&$new['price']['point']===200000,'original floor and later range never overwrite each other');
 check($old['price']['basis']==='advertised_floor_proxy'&&$new['price']['unit']==='party_total'
  &&$new['price']['party_size']===2&&!$new['price']['price_verified'],'typed party and price semantics');
 rejected(fn()=>audit_version_projection([version(999,10,'150000 руб')],$requests),'version_binding','unrequested current version rejected');
 rejected(fn()=>audit_version_projection([version(100,99,'150000 руб')],$requests),'version_binding','cross publication snapshot rejected');
 rejected(fn()=>audit_version_projection([version(100,10,'150000 руб'),version(100,10,'160000 руб')],$requests),'version_binding','duplicate snapshot rejected');
 // Absence/invalid/oversize do not silently become zero or a later price.
 $missing=audit_project([entry(4)],[],0)['posts'][0]['price'];
 check($missing['state']==='missing'&&$missing['point']===null,'missing version is retained without price');
 check(audit_price(version(100,10,'','missing'))['state']==='missing','missing text');
 check(audit_price(version(100,10,str_repeat('x',8193),'present'))['state']==='oversize','oversize text guard');
 check(audit_price(version(100,10,'','oversize'))['state']==='oversize','SQL oversized sentinel');
 check(audit_price(version(100,10,'','unparseable'))['state']==='unparseable','invalid JSON sentinel');
 check(audit_price(version(100,10,'Цена 150000 EUR'))['state']==='unparseable','foreign currency is not invented');
 check(audit_price(version(100,10,'От 10000 руб за ночь'))['state']==='unparseable','nightly price is not party tour price');
 check(audit_price(version(100,10,implode('; ',array_fill(0,9,'150000 руб'))))['state']==='unparseable','too many ambiguous amount candidates');
 $person=audit_price(version(100,10,'Цена 90 000 руб за человека'));
 check($person['state']==='parsed'&&$person['unit']==='per_person'&&$person['party_size']===null,'per person is retained without conversion to party');
 $unknown=audit_price(version(100,10,'Цена 180 000 руб'));
 check($unknown['state']==='parsed'&&$unknown['unit']==='unknown','unknown unit remains unknown');
 // Preserve every baseline cohort entry, including unavailable/invalid bindings.
 $rows=[];for($i=1;$i<=710;$i++)$rows[]=entry($i,10,$i%2?100:101);
 $rows[0]['publication_id']=null;$rows[0]['version_id']=null;
 $rows[1]['binding_valid']=0;
 $totals=audit_project($rows,$prices,2);
 check($totals['coverage']['entry_rows_read']===710&&array_sum(array_column($totals['posts'],'entries'))===708
  &&$totals['coverage']['unavailable_publication_entries']===1&&$totals['coverage']['invalid_binding_entries']===1,'all 710 entries reconciled');
 // A source key cannot vary inside one publication/version; isolate the invalid source fixture.
 $unsafe=entry(720,11,102);$unsafe['source_key']='https://private.invalid/?client_id=PRIVATE_CANARY';
 $safe=audit_project([$unsafe],[],0);
 check($safe['posts'][0]['source_key']===null&&$safe['coverage']['source_key_unavailable_entries']===1,'unsafe routing source is omitted');
 $serialized=json_encode($safe,JSON_THROW_ON_ERROR);
 check(!str_contains($serialized,'PRIVATE_CANARY')&&!str_contains($serialized,'conversation_id')
  &&!str_contains($serialized,'post_text')&&!str_contains($serialized,'90000'),'no actor identifiers or text in aggregate');
 check($safe['semantics']['customer_texts_read']===false&&$safe['semantics']['profiles_read']===false
  &&$safe['semantics']['price_is_frozen_modeled_context']===false,'historical publication price does not claim frozen modeled context');
 rejected(fn()=>audit_project([entry(1),entry(1)]),'entry_duplicate','duplicate entry rejected');
 $outside=entry(1);$outside['entered_at']=ENTRY_UNTIL;
 rejected(fn()=>audit_project([$outside]),'entry_clock','exclusive end boundary enforced');
 $large=[];for($i=1;$i<=1001;$i++)$large[]=entry($i,$i,$i);
 rejected(fn()=>audit_version_requests($large),'version_limit_incomplete','distinct version bound fails closed');
 check(audit_source_key('79995554433')===null&&audit_source_key('max_anytour_msk1')==='max_anytour_msk1','routing label grammar excludes numeric identities');
 // The inherited query-plan/server guards remain active without opening a database.
 audit_plan([['table'=>'pe','type'=>'range','key'=>'PRIMARY','rows'=>500,'Extra'=>'']]);
 audit_plan([['table'=>'v','type'=>'range','key'=>'PRIMARY','rows'=>50,'Extra'=>'']],false);
 check(true,'bounded entry and version plans accepted');
 rejected(fn()=>audit_plan([['table'=>'pe','type'=>'ALL','key'=>null,'rows'=>500,'Extra'=>'']]),'unbounded_base_access','entry full scan rejected');
 rejected(fn()=>audit_plan([['table'=>'v','type'=>'ALL','key'=>null,'rows'=>500,'Extra'=>'']],false),'explain_full_scan','version full scan rejected');
 rejected(fn()=>audit_plan([['table'=>'pe','type'=>'range','key'=>'PRIMARY','rows'=>50001,'Extra'=>'']]),'explain_cost_or_missing_base','explain cost bound');
 audit_server_version('8.0.42');check(true,'supported server version accepted');
 rejected(fn()=>audit_server_version('10.11.8-MariaDB'),'unsupported_server_version','unsupported timeout semantics rejected');
 check(audit_safe_error(new RuntimeException('PRIVATE_SQL_BODY'))==='bounded_read_stopped','unknown errors are sanitized');
 echo 'PASS '.$checks." pure assertions; no database or network opened\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

