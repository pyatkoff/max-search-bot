<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-hook-contract.php';
// Synthetic fixtures test contract semantics only, never constitute runtime evidence.
$id=monitor_hook_price81173_identity();$now=strtotime('2026-11-18T06:00:00+00:00');
$cycle=json_decode('{"night_date":"2026-11-18","state":"started","collector_run_id":"44204094292","collection_started_at":"2026-11-18T00:02:17+00:00","started_at":"2026-11-18T00:11:11+00:00","budget_seconds":1800,"finish_reserve_seconds":300}',true,16,JSON_THROW_ON_ERROR);
$completed=[];for($i=1;$i<=594;$i++)$completed[$i]=['operation'=>'fixture-'.$i];
$completed[81173]=['operation'=>'prior-fixture'];
$original=['schema'=>1,'pending'=>['operation'=>$id['operation'],'tourvisor_hotel_id'=>81173],
    'completed'=>$completed,'holds'=>[],'nightly_cycle'=>$cycle];
$feed=['operation'=>monitor_hook_feed_operation($id['operation'],'20261118'),'files'=>[],
    'local_and_public_hashes_verified'=>true,'verification_owner'=>'frozen_hook_finish_feed',
    'prefix10_preserved'=>true,'appended_global_states_sha256'=>str_repeat('a',64)];
$preimages=['hotel-posts-max.xml'=>'80d43159c87426a555bc36bd6444fee6b790ec06a19b389f2c7dd2f3f0b18ec3','hotel-posts-telegram.xml'=>'1f4d3b4d49eddcb5e11231f689b8d6e9ea77ae74fd0f2df382c00a5f9b5ee427','hotel-posts-all.xml'=>'65b13a827a08d1f50284718fdfc0680627e61bdff84fb47bf7e99ef4d3e6900a'];
foreach(array_keys($preimages) as $name)
    $feed['files'][]=['name'=>$name,'rows'=>$name==='hotel-posts-all.xml'?1340:670,'bytes'=>100,
        'sha256'=>hash('sha256',$name),'expected_previous_sha256'=>$preimages[$name]];
$state=$original;$state['pending']=null;$state['completed'][81173]=['operation'=>$id['operation'],'feed_operation'=>$feed['operation']];
$child=['operation'=>$id['operation'],'manifest_hash'=>$id['manifest_hash'],'complete'=>true,
    'feed_binding'=>['tourvisor_hotel_id'=>81173],'posts'=>[],
    'reconciliation'=>['original_rejection'=>['http'=>429,'ok'=>false,'error_code'=>429,'retry_after'=>21],'max_puts'=>0,'new_posts'=>0,'telegram_recovery_edit_attempts'=>1,'max_confirm_calls'=>0]];
$roster=json_decode(gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz')),true);
$row=array_values(array_filter($roster['rows'],fn($r)=>$r['tourvisor_hotel_id']===81173))[0];
$price=['tourvisor_hotel_id'=>81173,'price'=>403605,'observed_at'=>'2026-11-18T00:08:49Z','offer'=>['price'=>403605,'departureDate'=>'2026-11-21','nights'=>10,'roomType'=>'deluxe room garden or pool view','mealId'=>9]];
foreach(['max','telegram'] as $p){$max=$p==='max';$text=monitor_hook_draft($row['templates'][$p],$price);
    $button=($max?'https://max.ru/id9704048781_1_bot?start=p_':'https://t.me/anyru_bot?start=p_').str_repeat('A',24);
    $r=['state'=>'sent','platform'=>$p,'logical_id'=>'commercial-white-hills-81173-v1',
        'publication_id'=>$max?147:144,'version_id'=>$max?8095:8000,
        'message_id'=>$max?'mid.ffffb6caf0606fed01a19be8d82f26f3':'38',
        'post_url'=>$max?'https://max.ru/channel_anytour/AaGb6NgvJvM':'https://t.me/any_toour/38',
        'existing_message_reused'=>true,'body_photo_button_verified'=>true,'price_link_verified'=>true,
        'button_text'=>'✈️ Узнать стоимость тура','button_url'=>$button,
        'price_link_url'=>$button,'monitor_price'=>['record_id'=>substr($id['operation'],-64)],
        'text'=>$text,'text_sha256'=>hash('sha256',$text)];
    $child['posts'][$p.':commercial-white-hills-81173-v1']=['state'=>'confirmed','receipt'=>$r];
}
$args=[$state,$original,$id['global'],$id['child'],$child,$feed,$now];$n=0;
function check81173(bool $ok):void {global $n;if(!$ok)throw new RuntimeException('test failed #'.($n+1));$n++;}
$result=monitor_hook_close_price81173_cycle(...$args);
check81173(monitor_hook_price81173_cycle_valid($result['nightly_cycle']));
$rest=$result;$rest['nightly_cycle']=$state['nightly_cycle'];check81173($rest===$state);
check81173(monitor_hook_attempted_collector_runs([json_encode($result)])===['run:44204094292'=>true]);
check81173(monitor_hook_nightly_gate($result,$now,gmdate('c',$now),'44204094292')['reason']==='collector_cycle_already_attempted');
check81173(monitor_hook_nightly_gate($result,$now,gmdate('c',$now),'44204094293')['due']===true);
// The target may be newly completed or replace a previous observation; both preserve every other row.
$absent=$args;unset($absent[0]['completed'][81173],$absent[1]['completed'][81173]);
$absent[0]['completed'][595]=['operation'=>'fixture-595'];$absent[1]['completed'][595]=['operation'=>'fixture-595'];
$absent[0]['completed'][81173]=['operation'=>$id['operation'],'feed_operation'=>$feed['operation']];
$absentResult=monitor_hook_close_price81173_cycle(...$absent);
check81173(count($absentResult['completed'])===596);
check81173($absentResult['nightly_cycle']['recovery']['other_completed_count']===595);
$mutations=[
    fn(&$a)=>$a[2]['sha256']=str_repeat('0',64),
    fn(&$a)=>$a[3]['lines']=5,
    fn(&$a)=>$a[0]['pending']=['operation'=>'still pending'],
    fn(&$a)=>$a[0]['completed'][1]['operation']='changed',
    fn(&$a)=>$a[0]['holds']=[1=>'held'],
    fn(&$a)=>$a[0]['nightly_cycle']['collector_run_id']='other',
    fn(&$a)=>$a[4]['complete']=false,
    fn(&$a)=>$a[4]['posts']['max:commercial-white-hills-81173-v1']['receipt']['version_id']=8096,
    fn(&$a)=>$a[4]['posts']['telegram:commercial-white-hills-81173-v1']['receipt']['version_id']=2214,
    fn(&$a)=>$a[4]['posts']['telegram:commercial-white-hills-81173-v1']['receipt']['message_id']='157',
    fn(&$a)=>$a[4]['posts']['max:commercial-white-hills-81173-v1']['receipt']['logical_id']='white-hills',
    fn(&$a)=>$a[4]['posts']['max:commercial-white-hills-81173-v1']['receipt']['text']='corrupt',
    fn(&$a)=>$a[4]['reconciliation']['max_puts']=1,
    fn(&$a)=>$a[5]['files'][0]['rows']=661,
    fn(&$a)=>$a[5]['files'][0]['sha256']='missing',
    fn(&$a)=>$a[5]['files'][1]=$a[5]['files'][0],
    fn(&$a)=>$a[5]['local_and_public_hashes_verified']=false,
    fn(&$a)=>$a[5]['operation']='commercial-channel-hotel-feeds-20261118-update1',
    fn(&$a)=>$a[6]=0,
    fn(&$a)=>$a[6]=strtotime('2026-11-18T00:01:00+00:00'),
    fn(&$a)=>$a[4]['reconciliation']['original_rejection']['http']=500,
    fn(&$a)=>$a[4]['reconciliation']['original_rejection']['retry_after']=22,
    fn(&$a)=>$a[4]['reconciliation']['telegram_recovery_edit_attempts']=2,
    fn(&$a)=>$a[4]['reconciliation']['max_confirm_calls']=1,
    fn(&$a)=>$a[5]['prefix10_preserved']=false,
    fn(&$a)=>$a[0]['completed'][99999]=['operation'=>'unexpected'],

];
foreach($mutations as $mutation){$a=$args;$mutation($a);$failed=false;try{monitor_hook_close_price81173_cycle(...$a);}catch(RuntimeException $e){$failed=true;}check81173($failed);}
$bad=$result['nightly_cycle'];$bad['state']='completed';check81173(!monitor_hook_price81173_cycle_valid($bad));
$bad=$result;$bad['nightly_cycle']['recovery']['all_660_completed']=true;
$failed=false;try{monitor_hook_attempted_collector_runs([json_encode($bad)]);}catch(RuntimeException $e){$failed=true;}check81173($failed);
// Exact native evidence must reject internally consistent but foreign proof.
{
    $probes=[
        'rehashed wrong MAX text'=>static function(&$a){$r=&$a[4]['posts']['max:commercial-white-hills-81173-v1']['receipt'];$r['text']='Wrong content';$r['text_sha256']=hash('sha256',$r['text']);},
        'wrong external button'=>static function(&$a){$r=&$a[4]['posts']['telegram:commercial-white-hills-81173-v1']['receipt'];$r['button_url']=$r['price_link_url']='https://attacker.invalid/price';},
        'wrong feed preimage'=>static function(&$a){$a[5]['files'][0]['expected_previous_sha256']=str_repeat('0',64);},
        'wrong original cycle'=>static function(&$a){$a[0]['nightly_cycle']['collection_started_at']=$a[1]['nightly_cycle']['collection_started_at']='2026-11-18T00:00:01+00:00';},
    ];
    $bad=[];foreach($probes as $label=>$mutation){$a=$args;$mutation($a);try{monitor_hook_close_price81173_cycle(...$a);$bad[]=$label;}catch(RuntimeException $e){}}
    if($bad){fwrite(STDERR,"UNEXPECTEDLY ACCEPTED: ".implode('; ',$bad)."\n");exit(1);}
}
echo "PRICE81173 cycle: $n checks passed (synthetic, no runtime writes)\n";
