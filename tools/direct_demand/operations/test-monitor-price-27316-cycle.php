<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-hook-contract.php';
// Synthetic fixtures test contract semantics only, never constitute runtime evidence.
$id=monitor_hook_price27316_identity();$now=strtotime('2026-11-17T06:00:00+00:00');
$cycle=['collector_run_id'=>$id['collector_run_id'],'night_date'=>$id['night_date'],'state'=>'started',
    'collection_started_at'=>'2026-11-17T00:00:00+00:00','started_at'=>'2026-11-17T00:01:00+00:00',
    'budget_seconds'=>1800,'finish_reserve_seconds'=>300];
$completed=[];for($i=1;$i<=588;$i++)$completed[$i]=['operation'=>'fixture-'.$i];
$completed[27316]=['operation'=>'prior-fixture'];
$original=['schema'=>1,'pending'=>['operation'=>$id['operation'],'tourvisor_hotel_id'=>27316],
    'completed'=>$completed,'holds'=>[],'nightly_cycle'=>$cycle];
$feed=['operation'=>monitor_hook_feed_operation($id['operation'],'20261117'),'files'=>[],
    'local_and_public_hashes_verified'=>true,'verification_owner'=>'frozen_hook_finish_feed',
    'appended_global_states_sha256'=>str_repeat('a',64)];
foreach(['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'] as $name)
    $feed['files'][]=['name'=>$name,'rows'=>$name==='hotel-posts-all.xml'?1320:660,'bytes'=>100,
        'sha256'=>hash('sha256',$name),'expected_previous_sha256'=>str_repeat('b',64)];
$state=$original;$state['pending']=null;$state['completed'][27316]=['operation'=>$id['operation'],'feed_operation'=>$feed['operation']];
$child=['operation'=>$id['operation'],'manifest_hash'=>$id['manifest_hash'],'complete'=>true,
    'feed_binding'=>['tourvisor_hotel_id'=>27316],'posts'=>[],
    'reconciliation'=>['max_puts'=>0,'new_posts'=>0,'telegram_first_edit_attempts'=>1,'max_confirm_calls'=>1]];
foreach(['max','telegram'] as $p){$max=$p==='max';$text='Synthetic fixture '.$p;
    $r=['state'=>'sent','platform'=>$p,'logical_id'=>'commercial-regnum-carya-belek-27316-v1',
        'publication_id'=>$max?400:395,'version_id'=>$max?7024:8000,
        'message_id'=>$max?'mid.ffffb6caf0606fed01a1a3b88da95daa':'156',
        'post_url'=>$max?'https://max.ru/channel_anytour/AaGjuI2pXao':'https://t.me/any_toour/156',
        'existing_message_reused'=>true,'body_photo_button_verified'=>true,'price_link_verified'=>true,
        'button_text'=>'✈️ Узнать стоимость тура','button_url'=>'https://fixture.invalid/price',
        'price_link_url'=>'https://fixture.invalid/price','monitor_price'=>['record_id'=>substr($id['operation'],-64)],
        'text'=>$text,'text_sha256'=>hash('sha256',$text)];
    $child['posts'][$p.':regnum-carya-belek']=['state'=>'confirmed','receipt'=>$r];
}
$args=[$state,$original,$id['global'],$id['child'],$child,$feed,$now];$n=0;
function check27316(bool $ok):void {global $n;if(!$ok)throw new RuntimeException('test failed #'.($n+1));$n++;}
$result=monitor_hook_close_price27316_cycle(...$args);
check27316(monitor_hook_price27316_cycle_valid($result['nightly_cycle']));
$rest=$result;$rest['nightly_cycle']=$state['nightly_cycle'];check27316($rest===$state);
check27316(monitor_hook_attempted_collector_runs([json_encode($result)])===['run:44012967294'=>true]);
check27316(monitor_hook_nightly_gate($result,$now,gmdate('c',$now),'44012967294')['reason']==='collector_cycle_already_attempted');
check27316(monitor_hook_nightly_gate($result,$now,gmdate('c',$now),'44012967295')['due']===true);
$mutations=[
    fn(&$a)=>$a[2]['sha256']=str_repeat('0',64),
    fn(&$a)=>$a[3]['lines']=5,
    fn(&$a)=>$a[0]['pending']=['operation'=>'still pending'],
    fn(&$a)=>$a[0]['completed'][1]['operation']='changed',
    fn(&$a)=>$a[0]['holds']=[1=>'held'],
    fn(&$a)=>$a[0]['nightly_cycle']['collector_run_id']='other',
    fn(&$a)=>$a[4]['complete']=false,
    fn(&$a)=>$a[4]['posts']['max:regnum-carya-belek']['receipt']['version_id']=7025,
    fn(&$a)=>$a[4]['posts']['telegram:regnum-carya-belek']['receipt']['version_id']=4200,
    fn(&$a)=>$a[4]['posts']['telegram:regnum-carya-belek']['receipt']['message_id']='157',
    fn(&$a)=>$a[4]['posts']['max:regnum-carya-belek']['receipt']['logical_id']='regnum-carya-belek',
    fn(&$a)=>$a[4]['posts']['max:regnum-carya-belek']['receipt']['text']='corrupt',
    fn(&$a)=>$a[4]['reconciliation']['max_puts']=1,
    fn(&$a)=>$a[5]['files'][0]['rows']=661,
    fn(&$a)=>$a[5]['files'][0]['sha256']='missing',
    fn(&$a)=>$a[5]['files'][1]=$a[5]['files'][0],
    fn(&$a)=>$a[5]['local_and_public_hashes_verified']=false,
    fn(&$a)=>$a[5]['operation']='commercial-channel-hotel-feeds-20261117-update1',
    fn(&$a)=>$a[6]=0,
];
foreach($mutations as $mutation){$a=$args;$mutation($a);$failed=false;try{monitor_hook_close_price27316_cycle(...$a);}catch(RuntimeException $e){$failed=true;}check27316($failed);}
$bad=$result['nightly_cycle'];$bad['state']='completed';check27316(!monitor_hook_price27316_cycle_valid($bad));
$bad=$result;$bad['nightly_cycle']['recovery']['all_660_completed']=true;
$failed=false;try{monitor_hook_attempted_collector_runs([json_encode($bad)]);}catch(RuntimeException $e){$failed=true;}check27316($failed);
echo "PRICE27316 cycle: $n checks passed (synthetic, no runtime writes)\n";
