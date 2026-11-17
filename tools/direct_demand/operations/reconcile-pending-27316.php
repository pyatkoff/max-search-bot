<?php
declare(strict_types=1);
// Built after the exact accepted hook function prefix and its editor's helpers.
// Inspect is the default. The explicit apply gate completes only this original operation.
const RECONCILE_ROOT = '/var/www/anytoour/data/channel-post-operations';
const RECONCILE_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const RECONCILE_RUNTIME_SHA = '62a849e4ea1480700ed62c0bd3cc4c33adb0674c';
const RECONCILE_TV = 27316;
const RECONCILE_OPERATION = 'commercial-monitor-price-h27316-7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4';
const RECONCILE_RECORD = '7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4';
const RECONCILE_MANIFEST = '94ca6dd9be5c0fb66f0bf3ee6a09ca4e7d8319bb1cd1e3ae2f8a7f7390f89a78';
const RECONCILE_GLOBAL_SHA = '6a381375a03566c2853f31a4f57d4177df17e0ac950bf91ad7b43edc9734474d';
const RECONCILE_ORIGINAL_SHA = '010c6ecfbdae1156a772160bbadb5fec4cbd84555e14acf10baa791c36da2988';
const RECONCILE_KEY = 'regnum-carya-belek';
const RECONCILE_MAX_ID = 400;
const RECONCILE_MAX_OLD_VERSION = 4199;
const RECONCILE_MAX_MID = 'mid.ffffb6caf0606fed01a1a3b88da95daa';
const RECONCILE_MAX_CURRENT_TEXT_SHA = '057223c8a1d3bbfb76a1ad69b141a356d174f420ecc6d9f1716e95a18b5237a6';
const RECONCILE_REQUEST_START_MS = 1794888551018;

function reconcile_need(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
function reconcile_json(array $value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function reconcile_canonical(array $value): array {
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as &$item) if (is_array($item)) $item = reconcile_canonical($item);
    unset($item);
    return $value;
}
function reconcile_stable_offer(array $offer): array {
    unset($offer['ageSecondsAtExport'], $offer['expiresAt']);
    return reconcile_canonical($offer);
}
/** Fresh admission is mandatory for the first Telegram edit, independent of historical receipts. */
function reconcile_price_admission(array $original, array $fresh, int $now): array {
    $historical = monitor_price_observation($original, RECONCILE_TV, $now, false);
    $current = monitor_price_observation($fresh, RECONCILE_TV, $now, true);
    reconcile_need($historical['record_id'] === RECONCILE_RECORD && $current['record_id'] === RECONCILE_RECORD
        && monitor_price_operation($historical) === RECONCILE_OPERATION, 'reconcile_selected_record_changed');
    reconcile_need(reconcile_stable_offer($historical['offer']) === reconcile_stable_offer($current['offer'])
        && $historical['observed_at'] === $current['observed_at'], 'reconcile_immutable_offer_changed');
    reconcile_need(array_map('reconcile_stable_offer', $original['item']['offers']) === array_map('reconcile_stable_offer', $fresh['item']['offers']),
        'reconcile_immutable_offer_set_changed');
    reconcile_need(monitor_price_time($fresh['generatedAt']) >= monitor_price_time($original['generatedAt']), 'reconcile_export_time_regressed');
    foreach (['departureId','dateFrom','dateTo','nightsFrom','nightsTo','adults','childrenCount','currency'] as $field) {
        reconcile_need(($fresh['scope'][$field] ?? null) === ($original['scope'][$field] ?? null), 'reconcile_fresh_scope_changed');
    }
    return ['historical' => $historical, 'current' => $current];
}
/** The only admitted original prefix has one returned MAX intent and no Telegram intent. */
function reconcile_original_state(array $state): array {
    reconcile_need(($state['operation'] ?? null) === RECONCILE_OPERATION && ($state['manifest_hash'] ?? null) === RECONCILE_MANIFEST
        && ($state['configs'] ?? null) === [] && !isset($state['terminal']) && ($state['complete'] ?? false) === false,
        'reconcile_original_state_changed');
    $posts = $state['posts'] ?? null;
    reconcile_need(is_array($posts) && array_keys($posts) === ['max:' . RECONCILE_KEY], 'reconcile_unexpected_post_intent');
    $post = $posts['max:' . RECONCILE_KEY]; $plan = $post['plan'] ?? null;
    reconcile_need(($post['state'] ?? null) === 'text_edit_returned' && ($post['provider_edit_returned'] ?? null) === true
        && ($post['edit_request_start_ms'] ?? null) === RECONCILE_REQUEST_START_MS
        && !isset($post['receipt']) && !isset($post['button_result']) && is_array($plan), 'reconcile_max_intent_changed');
    reconcile_need(($plan['platform'] ?? null) === 'max' && ($plan['key'] ?? null) === RECONCILE_KEY
        && ($plan['logical_id'] ?? null) === RECONCILE_KEY && ($plan['publication_id'] ?? null) === RECONCILE_MAX_ID
        && ($plan['old_version_id'] ?? null) === RECONCILE_MAX_OLD_VERSION && ($plan['message_id'] ?? null) === RECONCILE_MAX_MID
        && ($plan['post_url'] ?? null) === 'https://max.ru/channel_anytour/AaGjuI2pXao'
        && ($plan['photo_signature'] ?? null) === 'max:49106363656' && ($plan['update_text'] ?? null) === true
        && is_string($plan['text'] ?? null) && $plan['text'] !== ''
        && is_string($plan['token'] ?? null) && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $plan['token']) === 1
        && ($plan['monitor_price']['record_id'] ?? null) === RECONCILE_RECORD, 'reconcile_max_plan_changed');
    return $plan;
}
function reconcile_prefix_guard(string $raw, string $expected, int $bytes, int $lines): array {
    reconcile_need(strlen($raw) === $bytes && hash('sha256', $raw) === $expected && str_ends_with($raw, "\n"), 'reconcile_journal_prefix_changed');
    $all = explode("\n", substr($raw, 0, -1));
    reconcile_need(count($all) === $lines, 'reconcile_journal_line_count_changed');
    $last = null;
    foreach ($all as $line) {
        $last = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        reconcile_need(is_array($last), 'reconcile_journal_shape_invalid');
    }
    return $last;
}
/** Safe diagnostics from the pinned verifyChannel result; never include provider data. */
function reconcile_capability_evidence(array $rights): array {
    $states = ['ready','unknown','requires_attention'];
    $reasons = ['capability_verified','unsupported_platform','invalid_channel_id','canonical_bot_url_mismatch',
        'invalid_expected_public_channel_url','canonical_bot_identity_mismatch','membership_identity_mismatch',
        'channel_admin_required','channel_read_permission_required','channel_edit_permission_required',
        'channel_type_mismatch','public_channel_identity_mismatch','provider_read_failed','verification_failure','request_budget_exhausted'];
    $receipt = is_array($rights['receipt'] ?? null) ? $rights['receipt'] : [];
    $results = [];
    foreach (array_slice(is_array($receipt['http_results'] ?? null) ? $receipt['http_results'] : [], 0, 5) as $result) {
        if (!is_array($result)) continue;
        $results[] = ['operation' => in_array($result['operation'] ?? null, ['bot_identity','channel_rights','channel_type'], true) ? $result['operation'] : 'invalid_operation',
            'http' => is_int($result['http'] ?? null) && $result['http'] >= 0 && $result['http'] <= 599 ? $result['http'] : null,
            'transport_error' => is_bool($result['transport_error'] ?? null) ? $result['transport_error'] : null];
    }
    return ['checked' => true, 'state' => in_array($rights['state'] ?? null, $states, true) ? $rights['state'] : 'invalid_state',
        'reason' => in_array($rights['reason'] ?? null, $reasons, true) ? $rights['reason'] : 'invalid_reason',
        'receipt' => ['request_count' => is_int($receipt['request_count'] ?? null) && $receipt['request_count'] >= 0 && $receipt['request_count'] <= 5 ? $receipt['request_count'] : null,
            'write_attempts' => is_int($receipt['write_attempts'] ?? null) && $receipt['write_attempts'] >= 0 && $receipt['write_attempts'] <= 1 ? $receipt['write_attempts'] : null,
            'http_results' => $results], 'transport' => 'HTTP POST'];
}
/** The pinned transport nests both counters and read receipts under receipt. */
function reconcile_capability_admission(array $rights): void {
    $receipt = $rights['receipt'] ?? null;
    reconcile_need(is_array($receipt) && ($receipt['write_attempts'] ?? null) === 0
        && ($receipt['request_count'] ?? null) === 3 && is_array($receipt['http_results'] ?? null)
        && array_is_list($receipt['http_results']) && count($receipt['http_results']) === 3,
        'reconcile_telegram_capability_receipt_invalid');
    foreach (['bot_identity','channel_rights','channel_type'] as $index => $purpose) {
        $result = $receipt['http_results'][$index];
        reconcile_need(is_array($result) && ($result['operation'] ?? null) === $purpose && is_int($result['http'] ?? null)
            && $result['http'] >= 200 && $result['http'] < 300 && ($result['transport_error'] ?? null) === false,
            'reconcile_telegram_capability_receipt_invalid');
    }
    reconcile_need(($rights['state'] ?? null) === 'ready' && ($rights['reason'] ?? null) === 'capability_verified',
        'reconcile_telegram_channel_rights_changed');
}
function reconcile_open(string $path, int $cap, bool $apply, array &$locks, bool $streamOnly=false): array {
    reconcile_need(!is_link(dirname($path)) && realpath(dirname($path)) === dirname($path)
        && !is_link($path) && is_file($path) && realpath($path) === $path, 'reconcile_journal_path_invalid');
    $f = fopen($path, $apply ? 'r+b' : 'rb');
    reconcile_need(is_resource($f), 'reconcile_journal_open_failed'); $locks[] = $f;
    reconcile_need(flock($f, ($apply ? LOCK_EX : LOCK_SH) | LOCK_NB), 'reconcile_original_operation_busy');
    $stat = fstat($f); $named = lstat($path);
    reconcile_need(is_array($stat) && is_array($named) && $stat['ino'] === $named['ino'] && $stat['dev'] === $named['dev']
        && $stat['size'] > 0 && $stat['size'] <= $cap, 'reconcile_journal_identity_changed');
    if ($streamOnly) return [$f,null];
    $raw = stream_get_contents($f, $cap + 1);
    reconcile_need(is_string($raw) && strlen($raw) === $stat['size'], 'reconcile_journal_read_failed');
    return [$f, $raw];
}

/** Stream the 443MB original journal under the existing nonblocking lock. */
function reconcile_global_state($stream):array {
    reconcile_need(is_resource($stream)&&rewind($stream),'reconcile_global_rewind_failed');
    $hash=hash_init('sha256');$bytes=0;$lines=0;$last='';
    while(($line=fgets($stream))!==false) {
        reconcile_need(str_ends_with($line,"\n"),'reconcile_global_incomplete_line');
        hash_update($hash,$line);$bytes+=strlen($line);$lines++;$last=$line;
        reconcile_need($bytes<=443818242,'reconcile_global_grew');
    }
    reconcile_need(feof($stream)&&$bytes===443818242&&$lines===4352
        &&hash_final($hash)===RECONCILE_GLOBAL_SHA
        &&hash('sha256',$last)==='5660cc6dad9e6a19900dd251da2cd0d8470182f114ac2dc98a2cccf2e38314b5',
        'reconcile_global_prefix_changed');
    $state=json_decode($last,true,64,JSON_THROW_ON_ERROR);
    reconcile_need(is_array($state),'reconcile_global_state_invalid');
    return $state;
}
/** Once an append occurs, verify that the entire preexisting prefix still matches. */
function reconcile_assert_global_prefix($stream):void {
    reconcile_need(is_resource($stream)&&rewind($stream),'reconcile_global_rewind_failed');
    $hash=hash_init('sha256');$remaining=443818242;
    while($remaining>0){
        $chunk=fread($stream,min($remaining,1048576));
        reconcile_need(is_string($chunk)&&$chunk!=='','reconcile_global_prefix_short');
        hash_update($hash,$chunk);$remaining-=strlen($chunk);
    }
    reconcile_need(hash_final($hash)===RECONCILE_GLOBAL_SHA,'reconcile_global_prefix_changed');
}

function reconcile_assert_prefix($stream, string $prefix): void {
    reconcile_need(rewind($stream), 'reconcile_journal_rewind_failed');
    $read = stream_get_contents($stream, strlen($prefix));
    reconcile_need($read === $prefix, 'reconcile_original_prefix_not_preserved');
}
/** Exactly the installed hook's publisher safety surface, not unrelated dialogue HEAD. */
function reconcile_runtime(): string {
    reconcile_need(realpath(RECONCILE_RUNTIME)===RECONCILE_RUNTIME
        &&chdir(RECONCILE_RUNTIME),'reconcile_runtime_path_changed');
    $head=trim((string)shell_exec('GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false rev-parse --verify HEAD 2>/dev/null'));
    $dirty=trim((string)shell_exec('GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false status --porcelain --untracked-files=no 2>/dev/null'));
    reconcile_need(preg_match('/\A[a-f0-9]{40}\z/D',$head)===1&&$dirty==='',
        'reconcile_runtime_changed');
    $base=trim((string)shell_exec('GIT_OPTIONAL_LOCKS=0 git merge-base '.escapeshellarg(RECONCILE_RUNTIME_SHA).' '.escapeshellarg($head).' 2>/dev/null'));
    reconcile_need($base===RECONCILE_RUNTIME_SHA,'reconcile_runtime_not_accepted_descendant');
    $pins=json_decode(base64_decode(HOOK_RUNTIME_PINS_B64,true),true,32,JSON_THROW_ON_ERROR);
    reconcile_need(is_array($pins)&&count($pins)===23,'reconcile_runtime_pin_count_changed');
    foreach($pins as $path=>$expected){
        reconcile_need(preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_.-]+\z~D',$path)===1
            &&!is_link($path)&&is_file($path),'reconcile_runtime_file_invalid');
        $hash=trim((string)shell_exec('GIT_OPTIONAL_LOCKS=0 git hash-object -- '.escapeshellarg($path).' 2>/dev/null'));
        reconcile_need($hash===$expected,'reconcile_runtime_pin_changed');
    }
    return $head;
}
function reconcile_identity(array $p, string $platform, array $proof, array $row): void {
    $targets = ['max' => ['id9704048781_1_bot','-80492244209683','max_anytour_msk1'], 'telegram' => ['anyru_bot','-1002262666616','tg_anytour_msk']];
    $target = $targets[$platform];
    reconcile_need(($p['project_key'] ?? null) === 'anytour' && ($p['platform'] ?? null) === $platform
        && ($p['bot_key'] ?? null) === $target[0] && ($p['channel_id'] ?? null) === $target[1]
        && ($p['source_key'] ?? null) === $target[2] && (int)($p['publication_id'] ?? 0) === (int)$proof['publication_id']
        && ($p['external_message_id'] ?? null) === (string)$proof['message_id'] && ($p['snapshot']['post_url'] ?? null) === $row['posts'][$platform]
        && ($p['state'] ?? null) === 'sent' && is_string($p['start_payload'] ?? null)
        && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $p['start_payload']) === 1
        && (int)($p['channel']['id'] ?? 0) === ($platform === 'max' ? 9 : 10)
        && (int)($p['channel']['config_version'] ?? 0) === 2 && ($p['channel']['button_text'] ?? null) === '✈️ Узнать стоимость тура', 'reconcile_publication_identity_changed');
}
/** Rebuild the original canonical manifest with the exact old MAX version and untouched Telegram. */
function reconcile_context(ChannelPostRegistryService $registry, array $state, array $maxPlan): array {
    $roster = json_decode(gzdecode(base64_decode(HOOK_ROSTER_GZIP_B64,true)),true,64,JSON_THROW_ON_ERROR)['rows'];
    $found = array_values(array_filter($roster,static fn($r):bool=>($r['tourvisor_hotel_id']??null)===RECONCILE_TV));
    reconcile_need(count($roster)===660&&count($found)===1,'reconcile_frozen_roster_changed');
    $row=$found[0];
    reconcile_need(($row['key']??null)===RECONCILE_KEY&&($row['logical_id']??null)===RECONCILE_KEY
        &&($row['feed_binding']??null)===($state['feed_binding']??null)
        &&($row['posts']['max']??null)==='https://max.ru/channel_anytour/AaGjuI2pXao'
        &&($row['posts']['telegram']??null)==='https://t.me/any_toour/156'
        &&hash('sha256',reconcile_json($row))==='d172b122038978379ea66408fdcc2496f7ac0a9e4be9803c8984b3fffe1424b1',
        'reconcile_frozen_binding_changed');
    $price=monitor_price_observation($state['monitor_snapshot'],RECONCILE_TV,time(),false);
    reconcile_need($price===$maxPlan['monitor_price'],'reconcile_original_price_changed');
    $priorOp='commercial-monitor-price-h27316-73871d808f646687f94862c74b7dfb78cc7b0c1cee07b74e63690ad16957198f';
    $priorRaw=hook_read(RECONCILE_ROOT.'/'.$priorOp.'/receipt.jsonl',300000);
    reconcile_need(strlen($priorRaw)===132335
        &&hash('sha256',$priorRaw)==='2f6ad68708da0b3cafdc166f292e82f158ac275ad63f8c41210d0e215f21d29b',
        'reconcile_prior_price_receipt_changed');
    $prior=monitor_hook_parse_journal($priorRaw,$priorOp);
    reconcile_need(($prior['operation']??null)===$priorOp&&($prior['complete']??null)===true
        &&($prior['feed_binding']??null)===$state['feed_binding'],'reconcile_prior_price_not_completed');
    $priorPost=[];
    foreach(['max'=>[400,4199],'telegram'=>[395,4200]] as $platform=>$expected){
        $post=$prior['posts'][$platform.':'.RECONCILE_KEY]??null;
        reconcile_need(is_array($post)&&($post['state']??null)==='confirmed'
            &&($post['receipt']['state']??null)==='sent'
            &&($post['receipt']['platform']??null)===$platform
            &&($post['receipt']['publication_id']??null)===$expected[0]
            &&($post['receipt']['version_id']??null)===$expected[1]
            &&is_string($post['receipt']['text_sha256']??null)
            &&hash('sha256',(string)($post['receipt']['text']??''))===$post['receipt']['text_sha256'],
            'reconcile_prior_'.$platform.'_receipt_invalid');
        $priorPost[$platform]=$post['receipt'];
    }
    $publications=[];$old=[];$proofs=[];
    foreach(['max','telegram'] as $platform){
        $file=RECONCILE_ROOT.'/'.$row['original_publication_operation'].'/'.$platform.'-'.$row['logical_id'].'-approved.json';
        $original=json_decode(hook_read($file,1048576),true,64,JSON_THROW_ON_ERROR);
        $proof=$original['receipt']??[];
        reconcile_need(($original['state']??null)==='confirmed'
            &&($original['text_sha256']??null)===$row['original_text_sha256']
            &&($original['photo_sha256']??null)===$row['cover_sha256']
            &&($proof['logical_id']??null)===$row['logical_id']
            &&($proof['hotel_id']??null)===$row['hotel_id']
            &&($proof['platform']??null)===$platform
            &&($proof['post_url']??null)===$row['posts'][$platform]
            &&($proof['body_photo_button_verified']??null)===true,
            'reconcile_original_publication_receipt_changed');
        $p=$registry->publication((int)$proof['publication_id']);
        reconcile_need(is_array($p),'reconcile_publication_missing');
        $publications[$platform]=$p;$proofs[$platform]=$proof;
        if($platform==='max'){
            reconcile_need(($p['project_key']??null)==='anytour'&&($p['platform']??null)==='max'
                &&($p['publication_id']??null)===RECONCILE_MAX_ID
                &&($p['version_id']??null)===7024
                &&($p['state']??null)==='requires_attention'
                &&($p['error_reason']??null)==='provider_read_failed'
                &&($p['write_attempts']??null)===0&&empty($p['claim_token'])&&empty($p['claimed_at'])
                &&($p['bot_key']??null)==='id9704048781_1_bot'
                &&($p['channel_id']??null)==='-80492244209683'
                &&($p['source_key']??null)==='max_anytour_msk1'
                &&($p['external_message_id']??null)===RECONCILE_MAX_MID
                &&($p['snapshot']['post_url']??null)===$row['posts']['max']
                &&($p['snapshot']['metadata']['monitor_price_record_id']??null)===RECONCILE_RECORD
                &&($p['snapshot']['metadata']['source_revision_ms']??null)===(string)RECONCILE_REQUEST_START_MS
                &&($p['channel']['id']??null)===9
                &&($p['channel']['config_version']??null)===2
                &&($p['channel']['button_text']??null)==='✈️ Узнать стоимость тура'
                &&hash_equals($maxPlan['token'],$p['start_payload']),
                'reconcile_current_max_failed_version_changed');
            $oldMax=$registry->versionForPublication(RECONCILE_MAX_ID,RECONCILE_MAX_OLD_VERSION);
            reconcile_need(is_array($oldMax)&&($oldMax['project_key']??null)==='anytour'
                &&($oldMax['platform']??null)==='max'
                &&($oldMax['external_message_id']??null)===RECONCILE_MAX_MID
                &&($oldMax['version_id']??null)===RECONCILE_MAX_OLD_VERSION
                &&hash('sha256',(string)($oldMax['snapshot']['text']??''))===$priorPost['max']['text_sha256']
                &&photoSignature($oldMax['snapshot'])===$maxPlan['photo_signature']
                &&($oldMax['snapshot']['post_url']??null)===$row['posts']['max'],
                'reconcile_old_max_snapshot_changed');
            $old['max']=['version_id'=>RECONCILE_MAX_OLD_VERSION,'snapshot'=>$oldMax['snapshot']];
            reconcile_need(($p['snapshot']['text']??null)===$maxPlan['text']
                &&photoSignature($p['snapshot'])===$maxPlan['photo_signature'],
                'reconcile_current_max_caption_changed');
        }else{
            reconcile_identity($p,'telegram',$proof,$row);
            reconcile_need(($p['publication_id']??null)===395&&($p['version_id']??null)===4200
                &&($p['external_message_id']??null)==='156'
                &&($p['write_attempts']??null)===0&&empty($p['claim_token'])&&empty($p['claimed_at'])
                &&hash('sha256',(string)($p['snapshot']['text']??''))===$priorPost['telegram']['text_sha256']
                &&($p['snapshot']['metadata']['monitor_price_record_id']??null)==='73871d808f646687f94862c74b7dfb78cc7b0c1cee07b74e63690ad16957198f'
                &&($p['snapshot']['post_url']??null)==='https://t.me/any_toour/156',
                'reconcile_telegram_original_version_changed');
            $old['telegram']=['version_id'=>4200,'snapshot'=>$p['snapshot']];
        }
    }
    $r=array_intersect_key($row,array_flip(['key','hotel_id','tourvisor_hotel_id','logical_id','original_publication_operation','cover_sha256']));
    $r['channel_texts']=[];
    foreach(['max','telegram'] as $platform){
        $text=monitor_hook_draft($row['templates'][$platform],$price);
        $before=$old[$platform]['snapshot']['text'];
        $r['channel_texts'][$platform]=[
            'old_text'=>$before,'old_text_sha256'=>hash('sha256',$before),
            'new_text'=>$text,'new_text_sha256'=>hash('sha256',$text),
            'original_text_sha256'=>$row['original_text_sha256'],
            'expected_version_id'=>$old[$platform]['version_id'],
            'expected_post_url'=>$row['posts'][$platform],
        ];
    }
    $payload=['operation'=>RECONCILE_OPERATION,'price_basis'=>'scheduled_monitor_result',
        'monitor_snapshot'=>$state['monitor_snapshot'],
        'button_text'=>'✈️ Узнать стоимость тура','feed_binding'=>$row['feed_binding'],'rows'=>[$r]];
    reconcile_need(hash('sha256',reconcile_json($payload))===RECONCILE_MANIFEST,
        'reconcile_exact_original_manifest_not_reconstructed');
    reconcile_need($r['channel_texts']['max']['new_text']===$maxPlan['text'],
        'reconcile_original_max_target_changed');
    $tg=$publications['telegram'];$tgText=$r['channel_texts']['telegram']['new_text'];
    $tgPlan=['platform'=>'telegram','key'=>$row['key'],'logical_id'=>$row['logical_id'],
        'message_id'=>$tg['external_message_id'],'publication_id'=>(int)$tg['publication_id'],
        'old_version_id'=>(int)$tg['version_id'],'token'=>$tg['start_payload'],
        'post_url'=>$tg['snapshot']['post_url'],'photo_signature'=>photoSignature($tg['snapshot']),
        'text'=>$tgText,'update_text'=>$tgText!==$tg['snapshot']['text'],'monitor_price'=>$price];
    reconcile_need($tgPlan['update_text']===true,'reconcile_telegram_not_first_caption_change');
    return ['row'=>$row,'price'=>$price,'payload'=>$payload,
        'publications'=>$publications,'proofs'=>$proofs,'telegram_plan'=>$tgPlan,
        'old_max'=>$old['max'],'prior_complete_sha256'=>hash('sha256',$priorRaw)];
}
function reconcile_read_max(array $plan, array $publication, array &$out): array {
    $out['max_gets']++;
    $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages/' . RECONCILE_MAX_MID, [], null);
    reconcile_need(empty($response['transport_error']) && (int)($response['http'] ?? 0) === 200 && is_array($response['data'] ?? null), 'reconcile_max_read_failed');
    $raw = $response['data'];
    reconcile_need(($raw['body']['mid'] ?? null) === RECONCILE_MAX_MID && ($raw['recipient']['chat_type'] ?? null) === 'channel'
        && (string)($raw['recipient']['chat_id'] ?? '') === '-80492244209683', 'reconcile_max_provider_identity_changed');
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($raw);
    reconcile_need(is_array($snapshot) && ($snapshot['metadata']['capability'] ?? null) === 'supported'
        && snapshotMatches($publication, $snapshot, $plan), 'reconcile_max_target_not_proven');
    $priceLink=monitorEditorPriceLink($plan['text'],'max',ChannelPostButtonTransport::buttonUrl($publication));
    reconcile_need(monitorEditorPriceLinkVerified('max',$raw,$plan['text'],$priceLink),
        'reconcile_max_inline_price_not_verified');
    $out['max_target_readback'] = ['http' => 200, 'message_id' => RECONCILE_MAX_MID, 'text_sha256' => hash('sha256', $snapshot['text']),
        'photo_signature' => photoSignature($snapshot), 'post_url' => $snapshot['post_url'], 'only_expected_button' => true,
        'response_sha256' => hash('sha256', reconcile_json($raw)), 'captured_at' => gmdate('c')];
    return $snapshot;
}
function reconcile_fresh_source(array $original, array &$out): array {
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow'));
    $dateFrom = $today->modify('+1 day')->format('Y-m-d');
    $dateTo = $today->modify('first day of this month')->modify('+12 months')->modify('-1 day')->format('Y-m-d');
    reconcile_need(($original['scope']['dateFrom'] ?? null) === $dateFrom && ($original['scope']['dateTo'] ?? null) === $dateTo, 'reconcile_calendar_scope_changed');
    $url = 'https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?' . http_build_query(
        ['tourvisorHotelIds' => [RECONCILE_TV], 'departureId' => 1, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo], '', '&', PHP_QUERY_RFC3986);
    $out['saved_monitor_gets']++; $raw = hook_get($url, 2000000); $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    reconcile_need(($payload['ok'] ?? null) === true && count($payload['items'] ?? []) === 1
        && ($payload['scope']['tourvisorHotelIds'] ?? null) === [RECONCILE_TV], 'reconcile_fresh_reader_scope_changed');
    $fresh = monitor_hook_source($payload, RECONCILE_TV, $url); $prices = reconcile_price_admission($original, $fresh, time());
    $out['fresh_admission'] = ['record_id' => $prices['current']['record_id'], 'price' => $prices['current']['price'],
        'generated_at' => $fresh['generatedAt'], 'expires_at' => $prices['current']['offer']['expiresAt'],
        'validated_at' => gmdate('c'), 'all_offers_validated' => count($fresh['item']['offers']),
        'passed' => true, 'immutable_selected_offer_matches' => true, 'all_offers_immutable_equal' => true, 'response_sha256' => hash('sha256', $raw),
        'snapshot_sha256' => hash('sha256', reconcile_json($fresh))];
    return $fresh;
}
/** Confirm an already authenticated, exact provider outcome. This never invokes a transport. */
function reconcile_confirm_observed(PDO $pdo, ChannelPostRegistryService $registry, array $publication, array $snapshot, array $plan, string $event): array {
    $version = (int)$publication['version_id']; $id = (int)$publication['publication_id'];
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM post_publications WHERE id=? FOR UPDATE'); $q->execute([$id]);
        reconcile_need((int)$q->fetchColumn() === $id, 'reconcile_publication_lock_failed');
        $q = $pdo->prepare('SELECT id FROM post_versions WHERE id=? AND publication_id=? FOR UPDATE'); $q->execute([$version,$id]);
        reconcile_need((int)$q->fetchColumn() === $version, 'reconcile_version_lock_failed');
        $p = $registry->publication($id);
        reconcile_need(is_array($p) && (int)$p['version_id'] === $version && hash_equals($plan['token'], (string)$p['start_payload'])
            && snapshotMatches($p, $snapshot, $plan) && snapshotMatches($p, $p['snapshot'], $plan)
            && ($p['snapshot']['metadata']['source_event_id'] ?? null) === $event
            && ($p['snapshot']['metadata']['observation_kind'] ?? null) === 'authorized_admin_content_edit'
            && ($p['snapshot']['metadata']['monitor_price_record_id'] ?? null) === RECONCILE_RECORD,
            'reconcile_registered_outcome_changed');
        if ($plan['platform'] === 'max') {
            reconcile_need(($p['snapshot']['metadata']['source_revision_ms'] ?? null) === (string)RECONCILE_REQUEST_START_MS
                && ($p['snapshot']['metadata']['revision_clock_origin'] ?? null) === 'authorized_edit_request_start',
                'reconcile_original_max_revision_not_preserved');
        }
        if ($p['state'] !== 'sent') {
            reconcile_need($p['state'] === 'pending' && (int)$p['write_attempts'] === 0 && empty($p['claim_token']) && empty($p['claimed_at']), 'reconcile_new_version_not_unclaimed');
            reconcile_need($registry->confirmObservedButton($id, $version, ['event_id' => $event, 'message_id' => $plan['message_id'],
                'http_status' => 200, 'verified' => true, 'reconciled' => true, 'request_count' => 1,
                'write_attempts' => 0, 'content_preserved' => true, 'button_verified' => true]), 'reconcile_observed_button_not_confirmed');
        }
        $p = $registry->publication($id);
        reconcile_need(is_array($p) && (int)$p['version_id'] === $version && $p['state'] === 'sent', 'reconcile_registry_not_sent');
        $pdo->commit(); return $p;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function reconcile_final_receipt(ChannelPostRegistryService $registry, array $p, array $plan): array {
    $current = $registry->publication((int)$p['publication_id']);
    reconcile_need(is_array($current) && $current['state'] === 'sent' && $current['platform'] === $plan['platform']
        && (int)$current['version_id'] === (int)$p['version_id'] && hash_equals($plan['token'], (string)$current['start_payload'])
        && snapshotMatches($current, $current['snapshot'], $plan), 'reconcile_final_registry_mismatch');
    $resolved = ChannelPostEntryService::resolve($plan['platform'], $plan['token'], $registry);
    reconcile_need(is_array($resolved) && (int)$resolved['version_id'] === (int)$current['version_id']
        && ($resolved['snapshot']['text'] ?? null) === $plan['text'] && $resolved['source_key'] === $current['source_key'], 'reconcile_token_resolution_failed');
    $receipt=safeResult($current,$plan);
    $link=monitorEditorPriceLink($plan['text'],$plan['platform'],ChannelPostButtonTransport::buttonUrl($current));
    $receipt['price_link_verified']=true;
    $receipt['price_link_url']=$link['url'];
    return $receipt;
}

function reconcile_self_test(): void {
    $cases=0;
    $ok=static function(bool $condition,string $reason)use(&$cases):void{
        reconcile_need($condition,'selftest_'.$reason);$cases++;
    };
    $refuse=static function(callable $fn,string $reason)use(&$cases):void{
        try{$fn();}catch(Throwable $e){$cases++;return;}
        throw new RuntimeException('selftest_unsafe_admitted_'.$reason);
    };
    $plan=['platform'=>'max','key'=>RECONCILE_KEY,'logical_id'=>RECONCILE_KEY,
        'publication_id'=>400,'old_version_id'=>4199,'message_id'=>RECONCILE_MAX_MID,
        'post_url'=>'https://max.ru/channel_anytour/AaGjuI2pXao',
        'photo_signature'=>'max:49106363656','update_text'=>true,
        'text'=>'fixture-verified-original-new-text',
        'token'=>'p_'.str_repeat('x',24),
        'monitor_price'=>['record_id'=>RECONCILE_RECORD]];
    $state=['operation'=>RECONCILE_OPERATION,'manifest_hash'=>RECONCILE_MANIFEST,
        'configs'=>[],'posts'=>['max:'.RECONCILE_KEY=>[
            'state'=>'text_edit_returned','provider_edit_returned'=>true,
            'edit_request_start_ms'=>RECONCILE_REQUEST_START_MS,'plan'=>$plan]]];
    $ok(reconcile_original_state($state)===$plan,'exact_original_max_intent');
    $mutations=[
        ['stage',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['state']='unknown';return $state;}],
        ['provider',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['provider_edit_returned']=false;return $state;}],
        ['time',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['edit_request_start_ms']++;return $state;}],
        ['new_version',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['plan']['old_version_id']=7024;return $state;}],
        ['new_message',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['plan']['message_id']='mid.unexpected';return $state;}],
        ['other_post',static function(array $state):array {$state['posts']['telegram:'.RECONCILE_KEY]=['state'=>'edit_started'];return $state;}],
        ['prefix_loss',static function(array $state):array {$state['operation']='different';return $state;}],
        ['hash_changed',static function(array $state):array {$state['manifest_hash']=str_repeat('f',64);return $state;}],
        ['photo_changed',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['plan']['photo_signature']='max:123';return $state;}],
        ['post_url_changed',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['plan']['post_url']='https://max.ru/wrong';return $state;}],
        ['record_changed',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['plan']['monitor_price']['record_id']=str_repeat('a',64);return $state;}],
        ['token_changed',static function(array $state):array {$state['posts']['max:'.RECONCILE_KEY]['plan']['token']='wrong';return $state;}],
    ];
    foreach($mutations as [$reason,$modify])$refuse(static fn()=>reconcile_original_state($modify($state)),$reason);
    $url='https://t.me/anyru_bot?start=p_'.str_repeat('A',24);
    $caption="✈️ Туры в REGNUM CARYA\n🌴 Белек\n\n💰 От 1 108 872 ₽ за двоих\n✈️ Вылет из Москвы 21.11.2026 · 5 ночей\n👥 2 взрослых\n🛏 sapphire villa\n🍽 Ultra All Inclusive\n\nЦена найдена мониторингом 17.11.2026, 09:55 МСК. Стоимость и наличие могут измениться.\n\nУзнать стоимость тура";
    $link=monitorEditorPriceLink($caption,'telegram',$url);
    $ok(count($link['telegram_entities']??[])===1
        &&($link['telegram_entities'][0]['type']??null)==='text_link'
        &&($link['telegram_entities'][0]['url']??null)===$url,
        'existing_telegram_price_link');
    $replaced=preg_replace('/От 1 108 872 ₽/u','От 1 108 873 ₽',$caption);
    $refuse(static fn()=>monitorEditorPriceLink($replaced,'telegram','https://t.me/wrong_bot?start=abc'),
        'changed_telegram_bot_url');
    $ok(RECONCILE_TV===27316 && RECONCILE_MAX_ID===400
        &&RECONCILE_MAX_OLD_VERSION===4199
        &&RECONCILE_KEY==='regnum-carya-belek','original_identity_pins');
    $ok(RECONCILE_RUNTIME_SHA==='62a849e4ea1480700ed62c0bd3cc4c33adb0674c',
        'frozen_runtime_pin');
    $ok(RECONCILE_GLOBAL_SHA==='6a381375a03566c2853f31a4f57d4177df17e0ac950bf91ad7b43edc9734474d',
        'streamed_original_prefix_pin');
    echo 'MONITOR_PENDING_27316_SELFTEST_OK cases='.$cases.' no_network=1 no_write=1 no_replay=1',"\n";
}

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('HOOK_EDITOR_B64') || !function_exists('safeResult') || !function_exists('hook_finish_feed')) {
    fwrite(STDERR, "reconcile_frozen_bundle_required\n"); exit(2);
}
if (count($argv) === 2 && $argv[1] === '--self-test') { try { reconcile_self_test(); exit(0); } catch (Throwable $e) { fwrite(STDERR,'SELFTEST_STOP: '.$e->getMessage()."\n"); exit(2); } }
$apply = getenv('ANYTOUR_MONITOR_27316_APPLY') === '1';
if ($apply) { fwrite(STDERR, "PRICE27316_APPLY_DISABLED_UNTIL_REVIEWED\n"); exit(2); }
$out = ['ok'=>false, 'operation'=>RECONCILE_OPERATION, 'mode'=>$apply?'apply_original_pending_only':'inspect_original_pending_only',
    'checked_at'=>gmdate('c'),'driver_sha256'=>RECONCILE_DRIVER_SHA256,'embedded_prefix_sha256'=>RECONCILE_EMBEDDED_PREFIX_SHA256,
    'max_puts'=>0,'max_gets'=>0,'saved_monitor_gets'=>0,'telegram_edit_attempts'=>0,'new_posts'=>0,'supplier_calls'=>0,
    'telegram_capability_checks'=>0,'write_admission_complete'=>false,
    'database_write_calls'=>0,'journal_appends'=>0,'feed_resume_calls'=>0,'original_completed_pairs_replayed'=>0];
$locks = []; $pdo = null;
try {
    reconcile_need(count($argv) === 1 && in_array(getenv('ANYTOUR_MONITOR_27316_APPLY'), [false,'','1'], true), 'reconcile_arguments_invalid');
    $installed=reconcile_runtime(); $out['runtime_sha']=$installed;
    reconcile_need(!is_link(RECONCILE_ROOT) && realpath(RECONCILE_ROOT) === RECONCILE_ROOT, 'reconcile_private_root_changed');
    [$globalLock] = reconcile_open(RECONCILE_ROOT.'/monitor-price-after-collection-v1.jsonl',500000000,$apply,$locks,true);
    $global = reconcile_global_state($globalLock);
    reconcile_need(($global['schema']??null)===1 && count($global['completed']??[])===589
        && ($global['pending']['tourvisor_hotel_id']??null)===RECONCILE_TV && ($global['pending']['operation']??null)===RECONCILE_OPERATION
        && ($global['pending']['phase']??null)==='post_started'
        && is_array($global['pending']['child_failure']??null)
        && count($global['pending']['child_failure'])===3
        && ($global['pending']['child_failure']['exit_code']??null)===2
        && ($global['pending']['child_failure']['error']??null)==='max_button_outcome_not_confirmed'
        && is_array($global['pending']['child_failure']['channel_preflight']??null)
        && count($global['pending']['child_failure']['channel_preflight'])===9
        && ($global['pending']['child_failure']['channel_preflight']['platform']??null)==='telegram'
        && ($global['pending']['child_failure']['channel_preflight']['state']??null)==='ready'
        && ($global['pending']['child_failure']['channel_preflight']['reason']??null)==='capability_verified'
        && ($global['pending']['child_failure']['channel_preflight']['request_count']??null)===3
        && ($global['pending']['child_failure']['channel_preflight']['write_attempts']??null)===0
        && ($global['pending']['child_failure']['channel_preflight']['http_results_truncated']??null)===false
        && ($global['pending']['child_failure']['channel_preflight']['receipt_valid']??null)===true
        && ($global['pending']['child_failure']['channel_preflight']['error']??'non_null')===null
        && ($global['pending']['child_failure']['channel_preflight']['http_results']??null)===[
            ['operation'=>'bot_identity','http'=>200,'transport_error'=>false],
            ['operation'=>'channel_rights','http'=>200,'transport_error'=>false],
            ['operation'=>'channel_type','http'=>200,'transport_error'=>false],
        ]
        && ($global['completed'][RECONCILE_TV]['operation']??null)==='commercial-monitor-price-h27316-73871d808f646687f94862c74b7dfb78cc7b0c1cee07b74e63690ad16957198f', 'reconcile_original_pending_changed');
    [$journalLock,$journalRaw] = reconcile_open(RECONCILE_ROOT.'/'.RECONCILE_OPERATION.'/receipt.jsonl',10000000,$apply,$locks);
    $state = reconcile_prefix_guard($journalRaw,RECONCILE_ORIGINAL_SHA,40405,4); $maxPlan = reconcile_original_state($state);
    $originalSnapshot=$state['monitor_snapshot'];
    $initialCompleted=$global['completed']; $initialHolds=$global['holds']??[];
    ob_start(); try {
        require RECONCILE_RUNTIME.'/config.php'; require_once RECONCILE_RUNTIME.'/services/ChannelPostRuntime.php';
        require_once RECONCILE_RUNTIME.'/services/ChannelPostAdminService.php'; require_once RECONCILE_RUNTIME.'/services/ChannelPostEntryService.php';
        require_once RECONCILE_RUNTIME.'/integrations/ChannelPostApiTransport.php'; require_once RECONCILE_RUNTIME.'/services/ChannelPostButtonTransport.php';
        require_once RECONCILE_RUNTIME.'/services/ChannelPostReconciliationService.php';
    } finally { ob_end_clean(); }
    foreach (['max'=>'id9704048781_1_bot','telegram'=>'anyru_bot'] as $platform=>$bot) {
        $identity=ChannelPostRuntime::identity($platform); reconcile_need(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$bot,'reconcile_runtime_bot_changed');
    }
    $pdo=ConversationDb::connection();
    if (!$apply) { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); $pdo->beginTransaction(); $out['database_session_read_only']=true; }
    foreach (['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration) {
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?'); $q->execute([$migration]);
        reconcile_need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$migration),'reconcile_publishing_schema_not_accepted');
    }
    $out['publishing_schema_accepted']=true;
    $registry=new ChannelPostRegistryService($pdo); $context=reconcile_context($registry,$state,$maxPlan);
    $maxSnapshot=reconcile_read_max($maxPlan,$context['publications']['max'],$out);
    $fresh=reconcile_fresh_source($state['monitor_snapshot'],$out);
    $admission=reconcile_price_admission($state['monitor_snapshot'],$fresh,time());
    $tgPlan=$context['telegram_plan'];
    $out['admission']=['original_manifest_reconstructed'=>true,'manifest_sha256'=>RECONCILE_MANIFEST,'original_prefix_sha256'=>RECONCILE_ORIGINAL_SHA,
        'global_prefix_sha256'=>RECONCILE_GLOBAL_SHA,'max_original_version_id'=>RECONCILE_MAX_OLD_VERSION,
        'telegram_publication_id'=>$tgPlan['publication_id'],'telegram_old_version_id'=>$tgPlan['old_version_id'],
        'telegram_message_id'=>$tgPlan['message_id'],'telegram_target_text_sha256'=>hash('sha256',$tgPlan['text']),
        'telegram_original_intent_absent'=>true,'original_monitor_snapshot_preserved'=>true,'same_record_fresh_admission'=>true,
        'frozen_callback_loop_used'=>false,'expected_max_puts'=>0,'expected_telegram_first_edits'=>1,'previous_completed_count'=>count($initialCompleted)];
    $out['original_operation']=RECONCILE_OPERATION;
    $out['original_manifest_sha256']=RECONCILE_MANIFEST;
    $out['original_journal_prefix_sha256']=RECONCILE_ORIGINAL_SHA;
    $out['original_global_prefix_sha256']=RECONCILE_GLOBAL_SHA;
    $out['original_monitor_snapshot_sha256']=hash('sha256',reconcile_json($originalSnapshot));
    if (!$apply) {
        $out['telegram_capability_read']=['checked'=>false,'required_before_apply_mutation'=>true];
        $out['ready_for_explicit_apply']=true; $out['ok']=true;
    } else {
        // All validation above precedes every journal or database mutation.
        // verifyChannel performs bounded read-only capability RPCs, even though Telegram uses HTTP POST.
        $out['telegram_capability_checks']++;
        $rights=(new ChannelPostButtonTransport())->verifyChannel($context['publications']['telegram']['channel']);
        $out['telegram_capability_read']=reconcile_capability_evidence($rights);
        reconcile_capability_admission($rights);
        reconcile_need(reconcile_runtime()===$installed,'reconcile_runtime_changed_during_operation');
        $maxBefore=$registry->publication(RECONCILE_MAX_ID);
        reconcile_need(is_array($maxBefore)
            &&(int)$maxBefore['publication_id']===400
            &&(int)$maxBefore['version_id']===7024
            &&($maxBefore['state']??null)==='requires_attention'
            &&($maxBefore['error_reason']??null)==='provider_read_failed'
            &&(int)($maxBefore['write_attempts']??-1)===0
            &&empty($maxBefore['claim_token'])&&empty($maxBefore['claimed_at'])
            &&hash('sha256',(string)$maxBefore['snapshot']['text'])===RECONCILE_MAX_CURRENT_TEXT_SHA
            &&hash_equals($maxPlan['token'],(string)$maxBefore['start_payload']),
            'reconcile_max_current_requires_attention_changed');
        reconcile_need(snapshotMatches($maxBefore,$maxSnapshot,$maxPlan),
            'reconcile_max_snapshot_changed_before_confirmation');
        reconcile_price_admission($state['monitor_snapshot'],$fresh,time());
        $identity=ChannelPostRuntime::identity('max');
        reconcile_need(is_array($identity)&&$identity['project_key']==='anytour'
            &&$identity['bot_key']==='id9704048781_1_bot','reconcile_max_identity_changed');
        $contentHash='9e189b1293ff17c0598d801e87aa93c538f82b2ef6fc9b8b9687ba291fdce7a1';
        reconcile_need(hash_equals($contentHash,ChannelPostIncomingAdapter::contentFingerprint($maxBefore['snapshot'])),
            'reconcile_max_content_hash_changed');
        $service=new ChannelPostReconciliationService($pdo);
        $preview=$service->confirmReadFailure($identity,'-80492244209683',400,7024,2,$contentHash,false);
        reconcile_need(($preview['ok']??null)===true&&($preview['state']??null)==='preview'
            &&($preview['reason']??null)==='already_present'
            &&($preview['provider_writes']??null)===0&&($preview['database_writes']??null)===0
            &&($preview['receipt']['request_count']??null)===3
            &&($preview['receipt']['write_attempts']??null)===0,
            'reconcile_max_preview_no_longer_admitted');
        reconcile_need(reconcile_runtime()===$installed,'reconcile_runtime_changed_during_operation');
        // One exact CAS only; never issue a MAX PUT. A lost DB acknowledgement
        // is UNKNOWN and must not be retried by an automated runner.
        $out['database_write_calls']++;
        $out['max_confirm_calls']++;
        $confirmed=$service->confirmReadFailure($identity,'-80492244209683',400,7024,2,$contentHash,true);
        reconcile_need(($confirmed['ok']??null)===true&&($confirmed['state']??null)==='sent'
            &&($confirmed['reason']??null)==='already_present'
            &&($confirmed['database_writes']??null)===1
            &&($confirmed['provider_writes']??null)===0
            &&($confirmed['publication_id']??null)===400
            &&($confirmed['version_id']??null)===7024,
            'reconcile_max_cas_unconfirmed_stop');
        $maxCurrent=$registry->publication(RECONCILE_MAX_ID);
        reconcile_need(is_array($maxCurrent)
            &&(int)$maxCurrent['version_id']===7024&&$maxCurrent['state']==='sent'
            &&hash_equals($maxPlan['token'],(string)$maxCurrent['start_payload'])
            &&($maxCurrent['snapshot']['metadata']['monitor_price_record_id']??null)===RECONCILE_RECORD,
            'reconcile_max_readback_after_cas_changed');
        $maxReceipt=reconcile_final_receipt($registry,$maxCurrent,$maxPlan);
        $state['posts']['max:'.RECONCILE_KEY]['state']='confirmed';
        $state['posts']['max:'.RECONCILE_KEY]['receipt']=$maxReceipt;
        $state['reconciliation']=[
            'kind'=>'original_provider_observed_max_cas_then_first_telegram_edit',
            'original_prefix_sha256'=>RECONCILE_ORIGINAL_SHA,
            'original_manifest_sha256'=>RECONCILE_MANIFEST,
            'max_puts'=>0,'max_confirm_calls'=>1,
            'new_posts'=>0,'telegram_first_edit_attempts'=>0,
            'max_readback'=>$out['max_target_readback'],
            'max_confirmation'=>[
                'publication_id'=>400,'version_id'=>7024,
                'confirmation_kind'=>'max_observed_after_read_failure',
                'content_hash'=>$contentHash,'provider_writes'=>0,'db_writes'=>1,
            ],
            'fresh_source_attestation'=>[
                'validated_at'=>gmdate('c'),'snapshot_sha256'=>hash('sha256',reconcile_json($fresh)),
                'snapshot'=>$fresh,
            ],
        ];
        durable($journalLock,$state);$out['journal_appends']++;
        $tgBefore=$registry->publication($tgPlan['publication_id']);
        reconcile_need(is_array($tgBefore)&&(int)$tgBefore['version_id']===$tgPlan['old_version_id']&&$tgBefore['state']==='sent'
            &&$tgBefore['snapshot']['text']===$context['payload']['rows'][0]['channel_texts']['telegram']['old_text']
            &&hash_equals($tgPlan['token'],$tgBefore['start_payload'])&&photoSignature($tgBefore['snapshot'])===$tgPlan['photo_signature'], 'reconcile_telegram_changed_before_first_edit');
        reconcile_identity($tgBefore,'telegram',$context['proofs']['telegram'],$context['row']);
        reconcile_need(!isset($state['posts']['telegram:'.RECONCILE_KEY]), 'reconcile_telegram_intent_already_exists');
        // Strict current validation is required immediately before this first Telegram intent.
        reconcile_need(reconcile_runtime()===$installed,'reconcile_runtime_changed_during_operation');
        reconcile_price_admission($state['monitor_snapshot'],$fresh,time());
        monitor_price_validate_text($tgPlan['text'],$context['price']);
        $out['fresh_admission']['validated_before_telegram_intent_at']=gmdate('c');
        $out['write_admission_complete']=true;
        $state['reconciliation']['telegram_first_edit_attempts']=1;
        $state['posts']['telegram:'.RECONCILE_KEY]=['state'=>'edit_started','plan'=>$tgPlan,'edit_request_start_ms'=>(int)floor(microtime(true)*1000),
            'fresh_attestation_sha256'=>hash('sha256',reconcile_json($fresh))];
        durable($journalLock,$state); $out['journal_appends']++;
        $out['telegram_edit_attempts']++;
        $tgLink=monitorEditorPriceLink($tgPlan['text'],'telegram',ChannelPostButtonTransport::buttonUrl($tgBefore));
        $response=tg('editMessageCaption',[
            'chat_id'=>'-1002262666616',
            'message_id'=>$tgPlan['message_id'],
            'caption'=>$tgPlan['text'],
            'caption_entities'=>reconcile_json($tgLink['telegram_entities']),
            'reply_markup'=>reconcile_json(['inline_keyboard'=>[[['text'=>'✈️ Узнать стоимость тура',
                'url'=>ChannelPostButtonTransport::buttonUrl($tgBefore)]]]]),
        ]);
        $state['posts']['telegram:'.RECONCILE_KEY]['provider_response']=$response;
        $state['posts']['telegram:'.RECONCILE_KEY]['state']='provider_response_recorded'; durable($journalLock,$state); $out['journal_appends']++;
        reconcile_need($response['http']===200&&($response['data']['ok']??null)===true&&is_array($response['data']['result']??null), 'reconcile_telegram_outcome_unknown_no_retry');
        $message=$response['data']['result'];
        reconcile_need((string)($message['chat']['id']??'')==='-1002262666616'&&($message['chat']['username']??null)==='any_toour'
            &&(string)($message['message_id']??'')===$tgPlan['message_id'], 'reconcile_telegram_response_identity_changed');
        $tgSnapshot=ChannelPostIncomingAdapter::telegramSnapshot($message);
        reconcile_need(is_array($tgSnapshot)&&($tgSnapshot['metadata']['capability']??null)==='supported'&&snapshotMatches($tgBefore,$tgSnapshot,$tgPlan), 'reconcile_telegram_target_not_proven');
        reconcile_need(monitorEditorPriceLinkVerified('telegram',$message,$tgPlan['text'],$tgLink),
            'reconcile_telegram_inline_price_not_verified_no_retry');
        $tgSnapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($tgSnapshot,'',(string)$tgBefore['channel']['bot_url']);
        $tgSnapshot['metadata']=array_replace($tgBefore['snapshot']['metadata']??[],$tgSnapshot['metadata']??[]);
        $tgSnapshot['metadata']=priceObservationMetadata($tgSnapshot['metadata'],$context['payload'],$context['price']);
        $tgEvent=RECONCILE_OPERATION.':telegram:'.$tgPlan['message_id']; $tgSnapshot['metadata']['source_event_id']=$tgEvent;
        $tgSnapshot['metadata']['observation_kind']='authorized_admin_content_edit';
        $out['database_write_calls']++;
        $tgCurrent=$registry->registerPublication('anytour','telegram','anyru_bot','-1002262666616',$tgPlan['message_id'],$tgSnapshot);
        reconcile_need(is_array($tgCurrent)&&(int)$tgCurrent['publication_id']===$tgPlan['publication_id']
            &&(int)$tgCurrent['version_id']>$tgPlan['old_version_id']&&empty($tgCurrent['stale_observation'])&&empty($tgCurrent['duplicate_event']), 'reconcile_telegram_registration_changed');
        $out['database_write_calls']++;
        $tgCurrent=reconcile_confirm_observed($pdo,$registry,$tgCurrent,$tgSnapshot,$tgPlan,$tgEvent);
        $tgReceipt=reconcile_final_receipt($registry,$tgCurrent,$tgPlan);
        $state['posts']['telegram:'.RECONCILE_KEY]['state']='confirmed'; $state['posts']['telegram:'.RECONCILE_KEY]['receipt']=$tgReceipt;
        durable($journalLock,$state); $out['journal_appends']++;
        $state['posts']['max:'.RECONCILE_KEY]['receipt']=reconcile_final_receipt($registry,$maxCurrent,$maxPlan);
        $state['complete']=true; durable($journalLock,$state); $out['journal_appends']++;
        reconcile_assert_prefix($journalLock,$journalRaw); reconcile_assert_global_prefix($globalLock);
        $binding=$state['feed_binding']+['operation'=>RECONCILE_OPERATION,'manifest_hash'=>RECONCILE_MANIFEST];
        monitor_price_confirmed_journal($state,$binding,time());
        $out['feed_resume_calls']++;
        reconcile_need(hook_finish_feed($global,$globalLock,$installed)==='feed_completed', 'reconcile_original_feed_not_completed');
        reconcile_need(fseek($globalLock,443818242,SEEK_SET)===0,'reconcile_feed_evidence_seek_failed');
        $appended=stream_get_contents($globalLock,1000001);
        reconcile_need(is_string($appended)&&strlen($appended)<=1000000&&str_ends_with($appended,"\n"),'reconcile_feed_evidence_read_failed');
        $feedFiles=null; $feedOperation=null;
        foreach(explode("\n",substr($appended,0,-1)) as $line){
            $entry=json_decode($line,true,64,JSON_THROW_ON_ERROR);
            if(($entry['pending']['operation']??null)===RECONCILE_OPERATION&&($entry['pending']['phase']??null)==='feed_started'){
                reconcile_need($feedFiles===null,'reconcile_duplicate_feed_start_evidence');
                $feedFiles=$entry['pending']['files'];$feedOperation=$entry['pending']['feed_operation'];
            }
        }
        reconcile_need(is_array($feedFiles)&&array_column($feedFiles,'name')===['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']
            &&$feedOperation===($global['completed'][RECONCILE_TV]['feed_operation']??null),'reconcile_feed_hash_evidence_missing');
        foreach($feedFiles as $file)reconcile_need(is_string($file['sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$file['sha256'])===1,'reconcile_feed_hash_evidence_invalid');
        $out['feed_evidence']=['operation'=>$feedOperation,'files'=>$feedFiles,'local_and_public_hashes_verified'=>true,
            'verification_owner'=>'frozen_hook_finish_feed','additional_public_gets'=>0,'appended_global_states_sha256'=>hash('sha256',$appended)];
        $remaining=$global['completed']; unset($remaining[RECONCILE_TV]);
        $unchangedOtherHotels=$initialCompleted;unset($unchangedOtherHotels[RECONCILE_TV]);
        reconcile_need($global['pending']===null&&$remaining===$unchangedOtherHotels&&($global['holds']??[])===$initialHolds
            &&($global['completed'][RECONCILE_TV]['operation']??null)===RECONCILE_OPERATION, 'reconcile_unrelated_global_state_changed');
        reconcile_assert_prefix($journalLock,$journalRaw); reconcile_assert_global_prefix($globalLock);
        $completedJournal=hook_journal(RECONCILE_OPERATION);
        reconcile_need(is_array($completedJournal)&&($completedJournal['complete']??null)===true&&$completedJournal['manifest_hash']===RECONCILE_MANIFEST
            &&$completedJournal['monitor_snapshot']===$originalSnapshot,'reconcile_original_source_not_preserved');
        $out['ok']=true; $out['updated_pairs']=1; $out['updates']=[['tourvisor_hotel_id'=>RECONCILE_TV,'operation'=>RECONCILE_OPERATION,
            'price'=>$context['price']['price'],'record_id'=>RECONCILE_RECORD,'posts'=>[$state['posts']['max:'.RECONCILE_KEY]['receipt'],$tgReceipt],
            'feed_operation'=>$global['completed'][RECONCILE_TV]['feed_operation']]];
        $out['original_prefix_preserved']=true; $out['other_hotel_completed_entries_preserved']=count($initialCompleted)-1; $out['completed_count_unchanged']=count($global['completed'])===count($initialCompleted); $out['pending_cleared']=true;
        $out['original_journal_complete']=true;
        $out['original_manifest_preserved']=true; $out['original_monitor_snapshot_preserved']=true; $out['pending']=null;
        $out['completed_original_operation']=$global['completed'][RECONCILE_TV];
        $out['original_cycle_interrupted']=true;
        $out['global_cycle_closure_pending']=true;
        $out['all_660_completed']=false;
    }
    $out['completed_at']=gmdate('c');
} catch (Throwable $e) {
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'reconcile_failed_read_original_journal';
}
if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); $out['database_transaction_rolled_back']=true; }
foreach (array_reverse($locks) as $lock) if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
echo 'MONITOR_PENDING_27316_RECONCILIATION='.reconcile_json($out)."\n"; exit($out['ok']?0:2);
