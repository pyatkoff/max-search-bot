<?php
declare(strict_types=1);
require __DIR__.'/support.php';
require_once dirname(__DIR__).'/services/Max2CommonValueBindingV2.php';
$checks=0;
function bindingCheck(bool $ok,string $label): void { global $checks; $checks++; if (!$ok) throw new RuntimeException('ASSERTION_FAILED_'.$label); }
function bindingSaved(array $f): array
{
    return ['status'=>'ADMITTED_SAVED_VALUE','subject'=>$f['subject'],
        'inquiry'=>['subject'=>$f['subject'],'journal_id'=>'103','native_message_id'=>$f['message_id'],'kind'=>'substantive_interest',
            'source_version'=>1,'verified_customer_message'=>true,'recorded_at_unix'=>$f['inquiry_recorded_at']],
        'actor_context'=>['subject'=>$f['subject'],'external_user_id'=>$f['actor'],'external_chat_id'=>'-'.$f['actor'],
            'customer_channel_id'=>'25','subject_actor_binding_sha256'=>hash('sha256','synthetic native reader binding')],
        'estimate'=>['admitted_saved_profile'=>true,'subject'=>$f['subject'],'inquiry_journal_id'=>'103','profile_id'=>'107',
            'profile_sha256'=>hash('sha256','synthetic saved profile'),'model_version'=>'saved_v1_model_readonly_in_common_v2',
            'currency'=>'RUB','value_rub'=>100,'input_cutoff_unix'=>$f['inquiry_recorded_at'],'event_unix'=>1795263620,'profile_observed_at_unix'=>1795263630]];
}
function bindingIdentity(array $saved,string $key): array
{
    return ['admitted'=>true,'subject'=>$saved['subject'],'inquiry_key'=>$key,'client_id'=>'654321',
        'basis'=>'saved_v1_identity_read_only','source_sha256'=>hash('sha256','synthetic exact saved CID')];
}
final class BindingGatewayFixture
{
    public int $lists=0; public int $rechecks=0; public ?array $original=null; public array $candidates=[];
    public bool $available=true; public bool $sameRecheck=true; public bool $complete=true;
    public function __construct(public array $f,bool $positive=true)
    {
        if ($positive) $this->candidates=[['tracker_record_id'=>'71','immutable_tuple_sha256'=>hash('sha256','synthetic exact HL9 tuple'),
            'counter_id'=>98615635,'metric_project_id'=>'1538','external_user_id'=>$f['actor'],'post_id'=>$f['pair']['publication_id'],
            'offer_id'=>$f['pair']['offer_id'],'client_id'=>'123456','early_kopeks'=>'2636','common_kopeks'=>'2636',
            'model_version'=>'max2_bootstrap_observed_value_v1','issuance_state'=>'issued_unconfirmed','recorded_at_unix'=>$f['entry_observed_at']-20]];
    }
    public function lookup(string $actor,array $context): ?array
    {
        $this->lists++; if (!$this->available) return null;
        bindingCheck($actor===$this->f['actor'] && $context['cutoff_unix']===$this->f['inquiry_recorded_at'],'wire_actor_and_cutoff');
        $r=['action'=>'list','context'=>$context,'external_user_id'=>$actor,'coverage'=>'all_exact_actor_own_early_reserves_at_observation',
            'complete_within_bound'=>$this->complete,'possible_max2_unresolved'=>!$this->complete,'own_reserve_only'=>true,
            'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,'checked_at_unix'=>$this->f['now'],
            'blank_row_count'=>0,'candidates'=>$this->candidates,'status'=>$this->candidates===[]?'complete_empty':'complete'];
        return $this->original=['receipt'=>$r,'source_receipt_sha256'=>hash('sha256',Max2CommonFrameV2::canonical($r)),'received_at_unix'=>$this->f['now']];
    }
    public function recheck(array $original,string $record): ?array
    {
        $this->rechecks++; bindingCheck($original===$this->original && !isset($original['receipt']['candidates'][0]['binding_state']),'recheck_uses_unchanged_private_source_receipt');
        if (!$this->sameRecheck) return null;
        $candidate=$this->candidates[0]; bindingCheck($record===$candidate['tracker_record_id'],'exact_record_recheck');
        $r=['action'=>'recheck','status'=>'match','coverage'=>'exact_selected_record_only','candidates'=>[$candidate],'checked_at_unix'=>$this->f['now']];
        return ['receipt'=>$r,'source_receipt_sha256'=>hash('sha256',Max2CommonFrameV2::canonical($r)),'received_at_unix'=>$this->f['now']];
    }
}
final class BindingClaimFixture
{
    public string $state='unbound'; public int $calls=0;
    public function receiptClaim(string $record,string $key): array
    {
        $this->calls++; return ['status'=>'OBSERVED','binding_state'=>$this->state,'bound_inquiry_key'=>$this->state==='same_inquiry'?$key:hash('sha256','another inquiry')];
    }
}
function bindingSetup(bool $entry=true): array
{
    $db=v2BindingTestDb();$f=v2BindingFixture($db,$entry);
    if ($entry) bindingCheck(v2BindingCaptureEntry($db,$f)['status']==='CAPTURED','fixture_entry_capture');
    bindingCheck(v2BindingCaptureMessage($db,$f)['status']==='CAPTURED','fixture_message_capture');
    $saved=bindingSaved($f);$gateway=new BindingGatewayFixture($f);$claims=new BindingClaimFixture();
    $binder=new Max2CommonValueBindingV2($db,$f['map_file'],'bindingIdentity');
    return [$db,$f,$saved,$gateway,$claims,$binder];
}
function bindingProject(array $set): array { [$db,$f,$saved,$gateway,$claims,$binder]=$set;return $binder->project($saved,$f['activation'],$gateway,$claims,$f['now']); }

$set=bindingSetup();$out=bindingProject($set);
bindingCheck($out['status']==='READY' && $out['binding']['status']==='UNIQUE_OBSERVED_MAX2'
    && $out['binding']['initial_common_kopeks']===2636 && $out['identity']['client_id']==='123456'
    && $out['identity']['basis']==='bound_max2_receipt' && $set[3]->lists===1 && $set[3]->rechecks===1,'unique_receipt_exact_inquiry_ready');
// The binding is read-only; only fixture capture wrote its own private frames.
bindingCheck((int)$set[0]->query('SELECT COUNT(*) FROM max2_common_entry_frames_v2')->fetchColumn()===2,'binding_does_not_write_capture_or_old_state');
$set[4]->state='same_inquiry';bindingCheck(bindingProject($set)['status']==='READY','same_inquiry_claim_can_extend_value');
$set[4]->state='other_inquiry';bindingCheck(bindingProject($set)['reason']==='MAX2_RECEIPT_ALREADY_BOUND_ELSEWHERE','other_inquiry_claim_held');

$set=bindingSetup(false);$set[3]=new BindingGatewayFixture($set[1],false);$out=bindingProject($set);
bindingCheck($out['status']==='READY' && $out['binding']['status']==='PROVEN_NO_NEW_EARLY_RESERVE'
    && $out['binding']['initial_common_kopeks']===0 && $out['identity']['client_id']==='654321' && $set[3]->rechecks===0,'complete_empty_ordinary_message_needs_no_hotel');
$set[3]->complete=false;bindingCheck(bindingProject($set)['status']==='HOLD','incomplete_zero_is_not_absence');
$set[3]->complete=true;$set[3]->available=false;bindingCheck(bindingProject($set)['reason']==='OWN_EARLY_LOOKUP_UNAVAILABLE','unavailable_zero_is_not_absence');

$set=bindingSetup();$extra=$set[3]->candidates[0];$extra['tracker_record_id']='72';$set[3]->candidates[]=$extra;
bindingCheck(bindingProject($set)['reason']==='POSSIBLE_MAX2_AMBIGUOUS' && $set[3]->rechecks===0,'multiple_receipts_never_select_latest');
$set=bindingSetup();$set[3]->candidates[0]['recorded_at_unix']=$set[1]['inquiry_recorded_at']+1;
bindingCheck(bindingProject($set)['reason']==='RECEIPT_AFTER_INQUIRY','later_launch_does_not_rebind_prior_inquiry');
$set=bindingSetup();$set[3]->candidates[0]['offer_id']='max_999999';
bindingCheck(bindingProject($set)['reason']==='MAX2_RECEIPT_CONFLICT','wrong_offer_held');
$set=bindingSetup();$set[3]->sameRecheck=false;
bindingCheck(bindingProject($set)['reason']==='EXACT_EARLY_RECHECK_UNAVAILABLE','failed_point_recheck_held');

$db=v2BindingTestDb();$f=v2BindingFixture($db);v2BindingCaptureEntry($db,$f);$second=v2BindingAddEntry($db,$f);v2BindingCaptureEntry($db,$second);v2BindingCaptureMessage($db,$f);
$g=new BindingGatewayFixture($f);$claims=new BindingClaimFixture();$binder=new Max2CommonValueBindingV2($db,$f['map_file'],'bindingIdentity');
bindingCheck($binder->project(bindingSaved($f),$f['activation'],$g,$claims,$f['now'])['reason']==='EXACT_INQUIRY_ENTRY_UNPROVED','multiple_entries_do_not_choose_latest_hotel');

$set=bindingSetup();$set[0]->exec("UPDATE post_versions SET snapshot_json='{\"post_url\":\"https://max.ru/wrong\"}'");
bindingCheck(bindingProject($set)['reason']==='IMMUTABLE_PUBLICATION_PAIR_CHANGED','changed_entry_snapshot_held');
$set=bindingSetup();$set[0]->exec("UPDATE messages SET external_message_id='mid.Other'");
bindingCheck(bindingProject($set)['reason']==='INQUIRY_NATIVE_FRAME_CHANGED','changed_native_message_held');
$set=bindingSetup();$set[0]->exec("UPDATE messages SET sender_id='92'");
bindingCheck(bindingProject($set)['reason']==='INQUIRY_NATIVE_FRAME_CHANGED','changed_native_actor_held');
foreach (['before_inquiry'=>1795263599,'after_saved_profile'=>1795263631,'capture_lag'=>1795263901] as $clockCase=>$at) {
    $set=bindingSetup(); $row=v2BindingFrameRow($set[0],null); $frame=json_decode($row['frame_json'],true,32,JSON_THROW_ON_ERROR);
    $frame['observed_at_unix']=$at;$json=Max2CommonFrameV2::canonical($frame);
    $set[0]->prepare('UPDATE max2_common_entry_frames_v2 SET observed_at_unix=?,frame_json=?,frame_sha256=? WHERE frame_key=?')->execute([$at,$json,hash('sha256',$json),$row['frame_key']]);
    if ($clockCase==='capture_lag') { $set[2]['estimate']['profile_observed_at_unix']=1795264000;$set[1]['now']=1795264100; }
    bindingCheck(bindingProject($set)['reason']==='AUTHENTICATED_INQUIRY_FRAME_CONFLICT' && $set[3]->lists===0,'capture_clock_'.$clockCase);
}
$set=bindingSetup();$row=v2BindingFrameRow($set[0],null);$decoded=json_decode($row['frame_json'],true,32,JSON_THROW_ON_ERROR);
$decoded=array_reverse($decoded,true);$formatted=json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$set[0]->prepare('UPDATE max2_common_entry_frames_v2 SET frame_json=? WHERE frame_key=?')->execute([$formatted,$row['frame_key']]);
bindingCheck(bindingProject($set)['status']==='READY','storage_key_order_and_spaces_preserve_canonical_digest');
$decoded['actor']['external_user_id']='92';$set[0]->prepare('UPDATE max2_common_entry_frames_v2 SET frame_json=? WHERE frame_key=?')->execute([json_encode($decoded,JSON_THROW_ON_ERROR),$row['frame_key']]);
bindingCheck(bindingProject($set)['reason']==='AUTHENTICATED_INQUIRY_FRAME_MISSING','altered_frame_rejected');

$set=bindingSetup(false);$set[2]['subject']['platform']='telegram';$set[2]['inquiry']['subject']=$set[2]['subject'];$set[2]['estimate']['subject']=$set[2]['subject'];
bindingCheck(bindingProject($set)['reason']==='CROSS_CHANNEL_EARLY_RESERVE_UNRESOLVED','telegram_linked_max_not_assumed_zero');
$set[0]->exec("UPDATE customer_channels SET channel='telegram',external_chat_id='91'");$out=bindingProject($set);
bindingCheck($out['status']==='READY' && $out['binding']['initial_common_kopeks']===0 && $set[3]->lists===0,'separate_native_telegram_identity_full_common_value');
echo 'PASS ',$checks," synthetic exact inquiry-binding assertions; no native DB, HTTP auth success or provider calls\n";
