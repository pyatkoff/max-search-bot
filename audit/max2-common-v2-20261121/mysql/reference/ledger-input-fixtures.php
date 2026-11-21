<?php
declare(strict_types=1);

function ledgerActivation(string $mode='collect'): array {
    return ['mode'=>$mode,'version'=>1,'counter_id'=>98615635,'goal_id'=>687941321,
        'target'=>Max2ValueBindingPolicyV2::TARGET,'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,
        'activation_sha256'=>hash('sha256','synthetic activation only, not a live release'), 'activated_at_unix'=>1795263000];
}
function ledgerCase(bool $linked=true,string $conversation='29',string $tracker='71',string $journal='103'): array {
    $s=['project_key'=>'anytour','platform'=>'max','customer_key'=>'17','conversation_id'=>$conversation];
    $q=['subject'=>$s,'journal_id'=>$journal,'native_message_id'=>'97','kind'=>'substantive_interest',
        'source_version'=>1,'verified_customer_message'=>true,'recorded_at_unix'=>1795263600];
    $key=Max2ValueBindingPolicyV2::inquiryKey($s,$journal);
    $actor=['authenticated_actor'=>true,'subject'=>$s,'external_user_id'=>'91','external_chat_id'=>'-91',
        'customer_channel_id'=>'25','subject_actor_binding_sha256'=>str_repeat('e',64)];
    $proof=['version'=>2,'counter_id'=>98615635,'subject'=>$s,'inquiry_key'=>$key,
        'source_receipt_sha256'=>str_repeat('a',64),'complete'=>true,'cutoff_unix'=>1795263600,
        'observed_at_unix'=>1795263650,'blank_row_count'=>4,'own_reserve_only'=>true,
        'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,
        'status'=>$linked?'complete':'complete_empty','coverage'=>'all_exact_actor_own_early_reserves_at_observation',
        'possible_max2_unresolved'=>false,'candidates'=>[],'actor_binding'=>$actor];
    if ($linked) {
        $proof['entry']=$actor+['exact_inquiry_context'=>true,'entry_id'=>'41','entry_key'=>str_repeat('b',64),
            'publication_id'=>'53','version_id'=>'59','pair_mapping_sha256'=>str_repeat('c',64),'offer_id'=>'max_61'];
        $proof['candidates'][]=['tracker_record_id'=>$tracker,'immutable_tuple_sha256'=>hash('sha256','synthetic tuple '.$tracker),
            'counter_id'=>98615635,'metric_project_id'=>'1538','external_user_id'=>'91','post_id'=>'53','offer_id'=>'max_61',
            'client_id'=>'1234567890123456789','early_kopeks'=>'2636','common_kopeks'=>'2636',
            'model_version'=>Max2ValueBindingPolicyV2::MAX2_MODEL,'issuance_state'=>'issued_unconfirmed',
            'recorded_at_unix'=>1795263540,'binding_state'=>'unbound'];
    }
    $binding=Max2ValueBindingPolicyV2::resolve($s,$q,$proof);
    if (($binding['status']??null)==='HOLD') throw new RuntimeException('SYNTHETIC_BINDING_INVALID');
    $estimate=['admitted_saved_profile'=>true,'subject'=>$s,'inquiry_journal_id'=>$journal,'profile_id'=>'107',
        'profile_sha256'=>hash('sha256','synthetic profile 107'),'model_version'=>'synthetic_saved_model_v1',
        'currency'=>'RUB','value_rub'=>'100.00','input_cutoff_unix'=>1795263650,'event_unix'=>1795263655,
        'profile_observed_at_unix'=>1795263660];
    $identity=['admitted'=>true,'subject'=>$s,'inquiry_key'=>$key,'client_id'=>'1234567890123456789',
        'source_sha256'=>hash('sha256','synthetic identity'),'basis'=>$linked?'bound_max2_receipt':'saved_v1_identity_read_only'];
    $recheck=$linked?['status'=>'match','action'=>'recheck','coverage'=>'exact_selected_record_only',
        'tracker_record_id'=>$tracker,'immutable_tuple_sha256'=>$binding['immutable_tuple_sha256'],
        'source_sha256'=>hash('sha256','synthetic recheck'),'checked_at_unix'=>1795263655]:[];
    return [$binding,$estimate,$identity,$recheck,1795263660];
}
function ledgerNext(array $args,$total='150.00',string $profile='109'): array {
    $args[1]['value_rub']=$total; $args[1]['profile_id']=$profile;
    $args[1]['profile_sha256']=hash('sha256','synthetic profile '.$profile); return $args;
}
