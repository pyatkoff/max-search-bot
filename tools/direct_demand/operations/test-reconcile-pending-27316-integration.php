<?php
declare(strict_types=1);
/** Offline boundary integration: real extracted orchestration, no application bootstrap/network/DB.
 * Usage: php test-reconcile-pending-27316-integration.php /path/to/frozen-hook.php
 * This deliberately does NOT certify database CAS semantics or nightly-cycle closure.
 */
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
function check(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function extractFunction(string $source,string $name):string {
    $tokens=token_get_all('<?php '.substr($source,strpos($source,'function '.$name.'(')));
    $depth=0;$begun=false;$out='';
    foreach($tokens as $t){if(is_array($t)&&$t[0]===T_OPEN_TAG)continue;$s=is_array($t)?$t[1]:$t;$out.=$s;
        if(!is_array($t)&&$s==='{'){$depth++;$begun=true;}if(!is_array($t)&&$s==='}'&&--$depth===0&&$begun)return $out;}
    throw new RuntimeException('function_boundary_missing_'.$name);
}
$driver=file_get_contents(__DIR__.'/reconcile-pending-27316.php');
$hook=file_get_contents($argv[1]??'/tmp/price27316-hook.php');
check(hash('sha256',$hook)==='41e9f0151f97691f91258ebb37714090ba0798c76db18c3870ff93bcc23d0c9a','frozen_hook_changed');
preg_match_all('/^const RECONCILE_[A-Z_]+ = .*;$/m',$driver,$constants);
foreach($constants[0] as $line)eval($line);
foreach(['reconcile_need','reconcile_json','reconcile_final_receipt'] as $name)eval(extractFunction($driver,$name));
preg_match('/^const HOOK_EDITOR_B64=("[A-Za-z0-9+\/=]+");$/m',$hook,$m);
$editor=base64_decode(json_decode($m[1]),true);
foreach(['photoSignature','snapshotMatches'] as $name)eval(extractFunction($editor,$name));
eval(extractFunction($hook,'hook_finish_feed'));
check((new ReflectionFunction('hook_finish_feed'))->getNumberOfRequiredParameters()===4,'actual_hook_arity');
check(str_contains($driver,'hook_finish_feed($global,$globalLock,$installed,$out)'),'driver_hook_call_arity');
$start=strpos($driver,'        $tgBefore=$registry->publication(');
$end=strpos($driver,'        reconcile_assert_prefix($journalLock,$journalRaw);',$start);
check($start!==false&&$end!==false,'tg_block_boundary');
$block=substr($driver,$start,$end-$start);
// Controlled boundary doubles. None can open a connection or send a request.
function need($v,$e){check((bool)$v,$e);} function reconcile_runtime(){return 'fixture-runtime';}
function reconcile_identity($p,$platform,$proof,$row){check($p['platform']===$platform,'identity');}
function reconcile_price_admission(...$args){} function monitor_price_validate_text(...$args){}
function monitorEditorPriceLink($text,$platform,$url){return ['url'=>$url,'telegram_entities'=>[]];}
function monitorEditorPriceLinkVerified(...$args){return true;}
function priceObservationMetadata($metadata,...$args){return $metadata+['monitor_price_record_id'=>RECONCILE_RECORD];}
function reconcile_confirm_observed($pdo,$registry,$p,...$args){$p['state']='sent';$registry->rows[$p['publication_id']]=$p;return $p;}
function safeResult($p,$plan){return ['publication_id'=>$p['publication_id'],'platform'=>$p['platform']];}
function durable($lock,$state){$GLOBALS['journal'][]=$state;$GLOBALS['child']=$state;}
function tg($method,$payload){$GLOBALS['calls']++;check($method==='editMessageCaption','no_new_post');check($payload['chat_id']==='-1002262666616'&&$payload['message_id']==='156','exact_target');check(count($GLOBALS['journal'])===1&&end($GLOBALS['journal'])['posts']['telegram:'.RECONCILE_KEY]['state']==='edit_started','intent_before_write');return $GLOBALS['response'];}
class ChannelPostButtonTransport {static function buttonUrl($p){return 'https://t.me/anyru_bot?start='.$p['start_payload'];}}
class ChannelPostIncomingAdapter {
    static function telegramSnapshot($message){return $message['fixture_snapshot'];}
    static function contentFingerprint(...$a){return 'fixture-hash';}
    static function containsOnlyButton($snap,$url,$text){return ($snap['button_url']??null)===$url;}
}
class ChannelPostEntryService {static function resolve($platform,$token,$registry){foreach($registry->rows as $p)if($p['platform']===$platform&&$p['start_payload']===$token)return $p;return null;}}
class ChannelPostRegistryService {
    public array $rows=[];public int $writes=0;
    function publication($id){return $this->rows[$id]??null;}
    function registerPublication($project,$platform,$bot,$chat,$mid,$snap){$this->writes++;$p=$this->rows[395];$p['version_id']=4201;$p['snapshot']=$snap;$p['state']='pending';return $this->rows[395]=$p;}
}
const HOOK_WRITER_B64='Zml4dHVyZQ==';
function hook_need($v,$e){check((bool)$v,$e);}function hook_journal($operation){return $GLOBALS['child'];}
function monitor_hook_apply_abandonment(...$a){return null;}function hook_measure(&$out,$key,$fn){return $fn();}
function hook_prepare_feed(...$a){return ['operation'=>'fixture-feed','files'=>array_map(fn($name)=>['name'=>$name,'sha256'=>hash('sha256','fixture-bytes')],['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'])];}
function hook_append($lock,$state){$GLOBALS['global_lines'].=json_encode($state)."\n";}
function hook_child(...$a){$GLOBALS['feed_writes']++;if($GLOBALS['feed_unknown'])throw new RuntimeException('fixture_unknown');return [];}
function hook_read(...$a){return 'fixture-bytes';}function hook_get(...$a){return $GLOBALS['public_bad']?'wrong-bytes':'fixture-bytes';}
function fixture(string $variant):array {
    global $block;
    $GLOBALS['journal']=[];$GLOBALS['calls']=0;$GLOBALS['feed_writes']=0;$GLOBALS['feed_unknown']=false;$GLOBALS['public_bad']=false;
    $registry=new ChannelPostRegistryService();$pdo=null;$installed='fixture-runtime';$journalLock=null;$fresh=[];
    $out=['journal_appends'=>0,'telegram_edit_attempts'=>0,'database_write_calls'=>0,'fresh_admission'=>[]];
    $tgPlan=['platform'=>'telegram','publication_id'=>395,'old_version_id'=>4200,'message_id'=>'156','token'=>'stable-tg','text'=>'new-price','photo_signature'=>'telegram:photo','post_url'=>'https://t.me/any_toour/156'];
    $maxPlan=$tgPlan;$maxPlan['platform']='max';$maxPlan['publication_id']=400;$maxPlan['token']='stable-max';
    $snap=['text'=>'new-price','post_url'=>$tgPlan['post_url'],'attachments'=>[['type'=>'image','provider'=>'telegram','file_unique_id'=>'photo']],'button_url'=>'https://t.me/anyru_bot?start=stable-tg','metadata'=>['capability'=>'supported']];
    $p=['publication_id'=>395,'version_id'=>4200,'state'=>'sent','platform'=>'telegram','start_payload'=>'stable-tg','source_key'=>'fixture','channel'=>['bot_url'=>'https://t.me/anyru_bot'],'snapshot'=>$snap];$p['snapshot']['text']='old-price';
    $registry->rows[395]=$p;$maxCurrent=$p;$maxCurrent['publication_id']=400;$maxCurrent['platform']='max';$maxCurrent['start_payload']='stable-max';$maxCurrent['snapshot']=$snap;$maxCurrent['snapshot']['button_url']='https://t.me/anyru_bot?start=stable-max';$registry->rows[400]=$maxCurrent;
    $context=['payload'=>['rows'=>[['channel_texts'=>['telegram'=>['old_text'=>'old-price']]]]],'proofs'=>['telegram'=>[]],'row'=>[],'price'=>[]];
    $state=['posts'=>['max:'.RECONCILE_KEY=>['state'=>'confirmed']],'monitor_snapshot'=>[],'reconciliation'=>[]];
    $message=['chat'=>['id'=>'-1002262666616','username'=>'any_toour'],'message_id'=>156,'fixture_snapshot'=>$snap];
    if($variant==='identity')$message['message_id']=157;
    if($variant==='photo')$message['fixture_snapshot']['attachments'][0]['file_unique_id']='changed';
    if($variant==='token')$message['fixture_snapshot']['button_url']='https://t.me/anyru_bot?start=wrong';
    if($variant==='prior_intent')$state['posts']['telegram:'.RECONCILE_KEY]=['state'=>'edit_started'];
    $GLOBALS['response']=['http'=>$variant==='unknown'?0:200,'data'=>['ok'=>true,'result'=>$message]];
    $error=null;try{eval($block);}catch(Throwable $e){$error=$e->getMessage();}
    return compact('error','state','out','registry');
}
$count=0;
foreach(['success','unknown','identity','photo','token','prior_intent'] as $case){
    $r=fixture($case);
    if($case==='success'){check($r['error']===null,'success_failed_'.($r['error']??''));check($r['state']['complete']===true,'not_complete');}
    else {check($r['error']!==null,'unsafe_admitted_'.$case);check($r['registry']->writes===0,'registry_write_after_unproven_outcome');check(empty($r['state']['complete']),'unknown_completed');}
    check($GLOBALS['calls']===($case==='prior_intent'?0:1),'edit_replayed');$count++;
}
fixture('success');
$completed=[];for($i=1;$i<=588;$i++)$completed[$i]=['operation'=>'prior-'.$i];$completed[RECONCILE_TV]=['operation'=>'prior-target'];
$base=['completed'=>$completed,'pending'=>['operation'=>RECONCILE_OPERATION,'tourvisor_hotel_id'=>RECONCILE_TV,'phase'=>'post_started'],'holds'=>['untouched'],'nightly_cycle'=>['state'=>'started']];
foreach(['success','writer_unknown','public_mismatch'] as $case){
    $state=$base;$before=$state;$GLOBALS['global_lines']="immutable-prefix\n";$GLOBALS['feed_writes']=0;$GLOBALS['feed_unknown']=$case==='writer_unknown';$GLOBALS['public_bad']=$case==='public_mismatch';$out=[];
    $error=null;try{$result=hook_finish_feed($state,null,'fixture-runtime',$out);}catch(Throwable $e){$error=$e->getMessage();}
    check(str_starts_with($GLOBALS['global_lines'],"immutable-prefix\n"),'prefix_changed');
    foreach(range(1,588) as $i)check($state['completed'][$i]===$before['completed'][$i],'previous_hotel_changed');
    check($state['holds']===$before['holds']&&$state['nightly_cycle']===$before['nightly_cycle'],'unrelated_state_changed');
    check($GLOBALS['feed_writes']===1,'feed_writer_count');
    if($case==='success')check($error===null&&$result==='feed_completed'&&$state['pending']===null,'feed_not_complete');
    else {check($error!==null&&$state['pending']['phase']==='feed_started','unknown_not_retained');$GLOBALS['feed_unknown']=false;$GLOBALS['public_bad']=false;hook_finish_feed($state,null,'fixture-runtime',$out);check($GLOBALS['feed_writes']===1,'unknown_writer_replayed');}
    $count++;
}
echo "PRICE27316_INTEGRATION_OK cases=$count real_tg_block=1 real_feed_function=1 boundary_doubles=1 production_writes=0 closure_certified=0\n";
