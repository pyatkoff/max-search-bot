"""Reuse admitted roster extension for remaining32; preserve prior628 and legacy pins."""
from __future__ import annotations
import hashlib,json,pathlib,sys,types,gzip
IDS=[548,2947,4032,43675,48263,82790,24743,66547,60232,78033,43569,78002,2451,63208,2491,2470,64954,2479,2493,76318,53617,12124,76011,65108,123308,65938,80008,2436,2444,2445,218,63191]
def require(ok,why):
 if not ok:raise ValueError(why)
def sha(b):return hashlib.sha256(b).hexdigest()
def blob(b):return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
def main():
 require(len(sys.argv)==5,'root bound_zip feed_zip output');root,bound,feed,out=map(pathlib.Path,sys.argv[1:]);out.mkdir(parents=True,exist_ok=True)
 source=(root/'prepare-expansion20-monitor-20261116.py').read_bytes();require(blob(source)=='892195cfb5e5872e9b5dc27ce89b674a369316c9','old_roster_preparer_changed');text=source.decode()
 replacements={
 "require(len(ends)==608,'old_roster_not608')":"require(len(ends)==628,'old_roster_not628')",
 "require(len(old['rows'])==608,'old_roster_count_changed')":"require(len(old['rows'])==628,'old_roster_count_changed')",
 "'16b25de3b0c85c64b195443b2c22ad6306b3cbd57959fd42681b6e257626ebd4'":"'495d2d5c4b0c353603cda847ff795fdc253a1893e280bd2cc76254fa4d2240cf'",
 "'4c0b2367eb18b4da05f36834d17897977b1bbec32dbdf17ad5f923ed0fe08920'":"'de0e03e9fb7aedf9a3b4f2a2dfa8fc629009ec7e7a94dca0d5c17eeee3802c91'",
 "'66e65ca20729ffe322be841d5f6b9f22b4108b036c7a6bf5e3cfcedfb573d0f1'":"'c5e43c9629db8558284335521e07f70e179cfc7593638c2e6e4a830b0686e6d2'",
 '==[628,628,1256]':'==[660,660,1320]',
 'for group in (20,21):':'for group in (22,23,24,25):',
 "s['new_posts']==len(s['posts'])==20":"s['new_posts']==len(s['posts'])==2*len(m['rows'])",
 "'d5091653179ac0460ed0129ae32cc52495abfbdd'":"'372823d98e580e708169bc8ac9367245376a803b'",
 "read['scope_hotels']==read['feed_rows']==read['private_binding_count']==10":"read['scope_hotels']==read['feed_rows']==read['private_binding_count']==len(m['rows'])",
 "'729bd1cf40567b2072f2471af283954cd9989cd1'":"'ec72fcffa24ace22a997b642c33ea7fb6fb251d9'",
 "data['rows'][:608]==old['rows'] and data['rows'][608:]==rows and len(data['rows'])==628":"data['rows'][:628]==old['rows'] and data['rows'][628:]==rows and len(data['rows'])==660",
 "len({r['tourvisor_hotel_id'] for r in data['rows']})==628":"len({r['tourvisor_hotel_id'] for r in data['rows']})==660",
 "len({r['posts'][c] for r in data['rows'] for c in ('max','telegram')})==1256":"len({r['posts'][c] for r in data['rows'] for c in ('max','telegram')})==1320",
 "'kind':'published20_monitor_roster_admission','previous_count':608,'next_count':628":"'kind':'published32_monitor_roster_admission','previous_count':628,'next_count':660",
 "'bound_run':43871777544,'bound_artifact':14228391968":"'bound_run':43927951309,'bound_artifact':14255918310",
 "'feed_run':43874344520,'feed_artifact':14229960161":"'feed_run':43929964963,'feed_artifact':14257044679",
 "'monitor-price-expansion20-proof-20261116.json'":"'monitor-price-expansion32-proof-20261116.json'"}
 for old,new in replacements.items():require(text.count(old)==1,'roster_scope_anchor_changed:'+old);text=text.replace(old,new,1)
 mod=types.ModuleType('existing_roster_extension32');exec(compile(text,'existing_roster_extension32','exec'),mod.__dict__);mod.IDS=IDS;mod.BOUND_SHA='7d1ae9812083058edf9ce65f8e63af28b98f2dabc1f44b878d4718aaf507960b';mod.FEED_SHA='bb54b091d782b38e52395f43cba52a532119500c5cf3cb88ab473774cabbce34'
 previous_argv=sys.argv[:]
 try:
  sys.argv=['existing_roster_extension32','--root',str(root),'--bound',str(bound),'--feed',str(feed),'--out',str(out)];mod.main()
 finally:sys.argv=previous_argv
 oldraw=gzip.decompress((root/'monitor-price-expanded-roster-20261111.json.gz').read_bytes());newraw=gzip.decompress((out/'monitor-price-expanded-roster-20261111.json.gz').read_bytes())
 proof_path=out/'monitor-price-expansion32-proof-20261116.json';proof=json.loads(proof_path.read_bytes());proof['old_decompressed_bytes']=len(oldraw);proof['appended_bytes']=len(newraw)-len(oldraw)
 cut=proof['old_rows_prefix_bytes'];require(newraw[:cut]+newraw[cut+proof['appended_bytes']:]==oldraw,'exact628_reconstruction_failed')
 editor=(root/'update-commercial-price-20261104-remaining1.php').read_bytes();edited=editor.decode()
 old="    if($monitorMode)$accepted=array_replace($accepted,[\n";new=old+"        // PR1572 changes only proved entry annotations, not this editor's publication methods.\n        'services/ChannelPostEntryService.php'=>'1982ff7368763edf8b7238c3142e3cc28579e991',\n"
 require(edited.count(old)==1,'monitor_override_anchor_changed');edited=edited.replace(old,new,1)
 old_registry="'services/ChannelPostRegistryService.php'=>'db8181426091a685a79ee55991cde22671f0d9dd'";new_registry="'services/ChannelPostRegistryService.php'=>'7304af19cc19666f00ca4ead1b04c2bdce719646'"
 require(edited.count(old_registry)==1,'reviewed_monitor_registry_pin_changed');edited=edited.replace(old_registry,new_registry,1)
 require(edited.replace(new,old,1).replace(new_registry,old_registry,1).encode()==editor,'editor_behavior_changed')
 (out/'update-commercial-price-20261104-remaining1.php').write_text(edited)
 proof['reviewed_runtime_compatibility']={'installed_source':'ec72fcffa24ace22a997b642c33ea7fb6fb251d9','original_surface_read_run':43927604117,'completed_binding_read_run':43927951309,'native_route_run':43928966057,'feed_run':43929964963,'original_editor_sha256':sha(editor),'new_editor_sha256':sha(edited.encode()),'monitor_only_pin_changes':{'services/ChannelPostEntryService.php':{'before':'f0e04e9b4e0ed6859ced96279cbf0619931596e6','after':'1982ff7368763edf8b7238c3142e3cc28579e991'},'services/ChannelPostRegistryService.php':{'before':'db8181426091a685a79ee55991cde22671f0d9dd','after':'7304af19cc19666f00ca4ead1b04c2bdce719646'}},'other20_pins_unchanged':True,'legacy_mode_pins_unchanged':True,'pricing_and_pause_and_journal_logic_unchanged':True}
 proof_path.write_bytes(mod.compact(proof)+b'\n')
 test=(root/'test-monitor-price-expansion20.php').read_bytes();require(blob(test)=='e81b9b5d3d0ac3244cd2668494358e73d97b8dc4','old20_test_changed')
 anchor="$all=json_decode($raw,true,64,JSON_THROW_ON_ERROR);"
 reconstruction="""// Later append-only rosters must reconstruct the exact accepted628 bytes before this original cohort test.
$extension=json_decode(file_get_contents(__DIR__.'/monitor-price-expansion32-proof-20261116.json'),true,64,JSON_THROW_ON_ERROR);
if(hash('sha256',$raw)!==$extension['new_decompressed_sha256']||$extension['previous_count']!==628||$extension['next_count']!==660)throw new RuntimeException('expanded_roster_proof_changed');
$raw=substr($raw,0,$extension['old_rows_prefix_bytes']).substr($raw,$extension['old_rows_prefix_bytes']+$extension['appended_bytes']);
if(strlen($raw)!==$extension['old_decompressed_bytes']||hash('sha256',$raw)!==$extension['old_decompressed_sha256'])throw new RuntimeException('old628_bytes_changed');
"""
 require(test.decode().count(anchor)==1,'old_test_anchor_changed');legacy_test=test.decode().replace(anchor,reconstruction+anchor,1);require(legacy_test.replace(reconstruction,'',1).encode()==test,'old20_tests_lost');(out/'test-monitor-price-expansion20.php').write_text(legacy_test)
 newtest=test.decode();oldids='$ids='+json.dumps([88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676],separators=(',',':'))+';'
 changes={oldids:'$ids='+json.dumps(IDS,separators=(',',':'))+';',"'monitor-price-expansion20-proof-20261116.json'":"'monitor-price-expansion32-proof-20261116.json'","$proof['previous_count']===608&&$proof['next_count']===628":"$proof['previous_count']===628&&$proof['next_count']===660","count($all['rows'])===628&&count(array_unique(array_column($all['rows'],'tourvisor_hotel_id')))===628":"count($all['rows'])===660&&count(array_unique(array_column($all['rows'],'tourvisor_hotel_id')))===660","array_slice($all['rows'],608)":"array_slice($all['rows'],628)","===1256,'duplicate_post_url'":"===1320,'duplicate_post_url'"," roster=628 old608_preserved=1 new20=20":" roster=660 old628_preserved=1 new32=32"}
 for before,after in changes.items():require(newtest.count(before)==1,'new32_test_anchor_changed:'+before);newtest=newtest.replace(before,after,1)
 newtest=newtest.replace('check20','check32').replace('EXPANSION20','EXPANSION32').replace('expansion20-fixture','expansion32-fixture');(out/'test-monitor-price-expansion32.php').write_text(newtest)
 print(json.dumps({'ok':True,'roster':660,'old628_exact_bytes_preserved':True,'new_pairs':32,'runtime_pin_updates':2,'new_price_values':0,'production_calls':0}))
if __name__=='__main__':main()
