<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/services/ChannelPostAutomationService.php';

/** Same canonical state/transport scenarios on real SQLite and native MySQL. */
function runChannelPostZeroWriteRecoveryChecks(PDO $pdo, callable $check, ?PDO $otherPdo = null): void
{
    $pdo->exec("INSERT INTO projects (id,project_key,display_name,is_active) VALUES (95001,'post_recovery_test','Recovery fixture',1)");
    $pdo->exec("INSERT INTO conversation_sources (id,project_id,source_key,display_name,channel,is_active,handling_mode) VALUES (95001,95001,'tg_recovery','Recovery fixture','telegram',1,'manager')");
    $registry = new ChannelPostRegistryService($pdo);
    $other = new ChannelPostRegistryService($otherPdo ?? $pdo);
    $config = ['project_key'=>'post_recovery_test','platform'=>'telegram','bot_key'=>'recovery_bot',
        'channel_id'=>'-10099887766','source_key'=>'tg_recovery','display_name'=>'Recovery fixture',
        'bot_url'=>'https://t.me/recovery_bot','is_enabled'=>true];
    $registry->configureChannel($config);
    $identity = ['project_key'=>'post_recovery_test','platform'=>'telegram','bot_key'=>'recovery_bot'];
    $mode = 'read_failure'; $calls = []; $writes = 0; $message = []; $afterRights = null;
    $request = static function (string $platform, string $method, string $path, array $query, ?array $body)
        use (&$mode, &$calls, &$writes, &$message, &$afterRights): array {
        $calls[] = $path;
        if ($platform !== 'telegram' || $method !== 'POST') throw new RuntimeException('unexpected_provider_request');
        $bot = ['id'=>551,'is_bot'=>true,'username'=>'recovery_bot'];
        if ($path === 'getMe') $data = $bot;
        elseif ($path === 'getChatMember') {
            if ($mode === 'read_failure') return ['http'=>0,'transport_error'=>true];
            $data = ['user'=>$bot,'status'=>'administrator','can_edit_messages'=>true];
            if ($afterRights) { $callback = $afterRights; $afterRights = null; $callback(); }
        } elseif ($path === 'getChat') $data = ['id'=>'-10099887766','type'=>'channel'];
        elseif ($path === 'editMessageReplyMarkup') {
            $writes++;
            if ($mode === 'lost_write') return ['http'=>0,'transport_error'=>true];
            $data = $message;
            $data['reply_markup'] = $body['reply_markup'];
            $data['edit_date'] = 1794931201;
            if ($mode === 'changed_content') $data['caption'] .= ' altered';
        } else throw new RuntimeException('unexpected_provider_request');
        return ['http'=>200,'data'=>['ok'=>true,'result'=>$data]];
    };
    $transport = new ChannelPostButtonTransport($request);
    $service = new ChannelPostAutomationService($registry, $transport);
    $counter = 88000;
    $failure = static function () use ($registry, $service, &$counter, &$mode, &$message): array {
        $mode = 'read_failure'; $counter++;
        $message = ['message_id'=>$counter,'date'=>1794931200,
            'chat'=>['id'=>'-10099887766','type'=>'channel','username'=>'recovery_fixture'],
            'caption'=>"  Holiday\nOriginal caption  ",
            'photo'=>[['file_id'=>'private-photo','file_unique_id'=>'stable-photo','width'=>1000,'height'=>1000]]];
        $snapshot = ChannelPostIncomingAdapter::telegramSnapshot($message);
        $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
        $snapshot['metadata']['observation_kind'] = 'provider_send_response';
        $snapshot['metadata']['source_event_id'] = 'recovery-fixture:'.$counter;
        $post = $registry->registerPublication('post_recovery_test','telegram','recovery_bot','-10099887766',(string)$counter,$snapshot);
        $service->processPublication('post_recovery_test','telegram','recovery_bot',$post['publication_id'],$post['version_id']);
        return $registry->publication($post['publication_id']);
    };
    $recover = static function (array $post, string $digest = '', bool $apply = false, array $overrides = [])
        use ($service, $identity): array {
        $args = array_replace(['identity'=>$identity,'channel'=>$post['channel_id'],'message'=>$post['external_message_id'],
            'publication'=>$post['publication_id'],'version'=>$post['version_id'],'config'=>$post['channel']['config_version'],
            'hash'=>ChannelPostIncomingAdapter::contentFingerprint($post['snapshot']),'digest'=>$digest,'apply'=>$apply],$overrides);
        return $service->recoverTelegramReadFailure($args['identity'],$args['channel'],$args['message'],
            $args['publication'],$args['version'],$args['config'],$args['hash'],$args['digest'],$args['apply']);
    };
    $databaseState = static fn(): array => $pdo->query('SELECT * FROM post_versions ORDER BY id')->fetchAll();
    $post = $failure();
    $check('zero-write fixture reproduces actual pre-edit network failure',
        $post['state']==='requires_attention' && $post['error_reason']==='provider_read_failed'
        && $post['write_attempts']===0 && $post['attempt_count']===1 && $writes===0
        && ChannelPostRegistryService::telegramReadFailureRecoveryEligible($post));
    $oldFailure = $pdo->query('SELECT * FROM post_versions WHERE id='.(int)$post['version_id'])->fetch();
    $before = $databaseState(); $callCount = count($calls);
    $ordinary = $service->processPublication('post_recovery_test','telegram','recovery_bot',$post['publication_id'],$post['version_id']);
    $check('ordinary selected worker still refuses requires_attention without a provider call',
        $ordinary['reason']==='target_not_claimable' && count($calls)===$callCount && $databaseState()===$before);
    $mode = 'ready'; $calls = [];
    $preview = $recover($post);
    $check('explicit preview makes three capability reads and zero database or provider writes',
        $preview['ok'] && $preview['state']==='preview' && $calls===['getMe','getChatMember','getChat']
        && $writes===0 && $databaseState()===$before);
    foreach (['identity'=>['project_key'=>'other','platform'=>'telegram','bot_key'=>'recovery_bot'],
        'channel'=>'-10099887767','message'=>'1','publication'=>999999,'version'=>999999,'config'=>99,
        'hash'=>str_repeat('0',64),'digest'=>str_repeat('0',64)] as $key=>$value) {
        $calls = [];
        $result = $recover($post,$preview['recovery_digest'],true,[$key=>$value]);
        $check('changed recovery selector rejected before provider access: '.$key,
            !$result['ok'] && $calls===[] && $writes===0 && $databaseState()===$before);
    }
    $calls = [];
    $result = $recover($post,$preview['recovery_digest'],true);
    $current = $registry->publication($post['publication_id']);
    $history = $current['receipt']['previous_failure'] ?? [];
    $check('selected recovery edits only existing reply markup once',
        $result['ok'] && $result['state']==='sent' && $writes===1
        && $calls===['getMe','getChatMember','getChat','getMe','getChatMember','editMessageReplyMarkup']);
    $check('recovery preserves publication version snapshot token and exact original failure',
        $current['publication_id']===$post['publication_id'] && $current['version_id']===$post['version_id']
        && $current['start_payload']===$post['start_payload'] && $current['snapshot']===$post['snapshot']
        && $history===['state'=>'requires_attention','reason'=>'provider_read_failed','attempt_count'=>1,
            'write_attempts'=>0,'receipt_json'=>$oldFailure['receipt_json'],'updated_at'=>$oldFailure['updated_at']]
        && $current['receipt']['content_preserved']===true && $current['receipt']['button_verified']===true
        && $current['write_attempts']===1);
    $calls = []; $again = $recover($post,$preview['recovery_digest'],true);
    $check('consumed recovery cannot be replayed', !$again['ok'] && $calls===[] && $writes===1);

    // All rejected variants are evaluated from the reproduced actual failure,
    // without manufacturing a new provider observation or resetting database state.
    foreach ([
        'unknown'=>['state'=>'unknown'], 'prior_write'=>['write_attempts'=>1],
        'other_reason'=>['error_reason'=>'provider_rejected_edit'], 'live_claim'=>['claim_token'=>'other'],
        'claimed_at'=>['claimed_at'=>'2026-11-17 12:00:00'], 'second_attempt'=>['attempt_count'=>2],
        'album'=>['external_message_id'=>'group:album'], 'other_platform'=>['platform'=>'max'],
        'already_recovered'=>['receipt'=>['recovery_kind'=>'telegram_zero_write_read_failure']],
    ] as $name=>$change) {
        $check('unsafe failure cannot enter recovery: '.$name,
            !ChannelPostRegistryService::telegramReadFailureRecoveryEligible(array_replace($post,$change)));
    }
    $changed = $post; $changed['content_hash'] = str_repeat('0',64);
    $check('legacy or mismatched stored content hashes are ineligible',
        !ChannelPostRegistryService::telegramReadFailureRecoveryEligible($changed));
    $changed = $post; $changed['snapshot']['text'] .= ' different';
    $check('snapshot must match the authenticated provider send response',
        !ChannelPostRegistryService::telegramReadFailureRecoveryEligible($changed));
    $changed = $post; $changed['receipt']['http_results'][] = ['operation'=>'append_button','http'=>0,'transport_error'=>1];
    $check('failure evidence containing a provider write is refused',
        !ChannelPostRegistryService::telegramReadFailureRecoveryEligible($changed));

    $post = $failure(); $mode = 'ready'; $preview = $recover($post);
    $before = $databaseState(); $calls = []; $mode = 'read_failure';
    $result = $recover($post,$preview['recovery_digest'],true);
    $check('fresh capability failure leaves original failed version untouched',
        !$result['ok'] && $writes===1 && $databaseState()===$before);

    $post = $failure(); $mode = 'ready'; $preview = $recover($post); $calls = [];
    $afterRights = static function () use ($other,$post,$config): void {
        $other->configureChannel($config+['expected_version'=>(int)$post['channel']['config_version']]);
    };
    $result = $recover($post,$preview['recovery_digest'],true);
    $current = $registry->publication($post['publication_id']);
    $check('configuration changed during read proof is rejected under the canonical lock',
        !$result['ok'] && $writes===1 && $current['state']==='requires_attention'
        && $current['write_attempts']===0 && !isset($current['receipt']['recovery_kind']));

    $post = $failure(); $mode = 'ready'; $preview = $recover($post);
    $afterRights = static function () use (&$afterRights,$other,$post,$config): void {
        $afterRights = static function () use ($other,$post,$config): void {
            $other->configureChannel($config+['expected_version'=>(int)$post['channel']['config_version']]);
        };
    };
    $result = $recover($post,$preview['recovery_digest'],true);
    $current = $registry->publication($post['publication_id']);
    $check('configuration revision after the claim blocks the final provider write',
        !$result['ok'] && $writes===1 && $current['state']==='requires_attention'
        && $current['error_reason']==='write_claim_or_config_changed' && $current['write_attempts']===0
        && isset($current['receipt']['previous_failure']['receipt_json']));

    $post = $failure(); $mode = 'ready'; $preview = $recover($post);
    $proof = $transport->verifyChannel($post['channel']);
    $claim = $registry->claimTelegramReadFailure($post,$proof,true);
    try { $other->claimTelegramReadFailure($post,$proof,true); $secondClaim = true; }
    catch (RuntimeException $e) { $secondClaim = false; }
    $check('competing connection cannot consume the same recovery twice', !$secondClaim);
    $pdo->exec("UPDATE post_versions SET claimed_at='2000-01-01 00:00:00' WHERE id=".(int)$post['version_id']);
    $calls = [];
    $service->processPending('post_recovery_test','telegram','recovery_bot',5);
    $current = $registry->publication($post['publication_id']);
    $check('expired zero-write recovery never turns into an automatic provider edit',
        $current['state']==='requires_attention' && $current['error_reason']==='explicit_recovery_required'
        && $current['write_attempts']===0 && $calls===[] && $writes===1
        && isset($current['receipt']['previous_failure']['receipt_json']));

    $post = $failure(); $mode = 'ready'; $preview = $recover($post);
    $mode = 'lost_write'; $calls = []; $result = $recover($post,$preview['recovery_digest'],true);
    $current = $registry->publication($post['publication_id']);
    $check('lost edit acknowledgement becomes unknown with original failure retained',
        !$result['ok'] && $result['state']==='unknown' && $current['state']==='unknown'
        && $current['write_attempts']===1 && $writes===2 && isset($current['receipt']['previous_failure']));
    $calls = []; $recover($post,$preview['recovery_digest'],true);
    $service->processPublication('post_recovery_test','telegram','recovery_bot',$post['publication_id'],$post['version_id']);
    $check('unknown edit is never repeated by explicit recovery or ordinary reconciliation',
        $writes===2 && !in_array('editMessageReplyMarkup',$calls,true));
    $confirmed = $message;
    $confirmed['reply_markup'] = ['inline_keyboard'=>[[['text'=>$current['channel']['button_text'],
        'url'=>ChannelPostButtonTransport::buttonUrl($current)]]]];
    $confirmed['edit_date'] = 1794931202;
    $service->receive('telegram',['update_id'=>99001,'edited_channel_post'=>$confirmed],
        'post_recovery_test','recovery_bot');
    $current = $registry->publication($post['publication_id']);
    $check('canonical authenticated edit confirmation also preserves the original failure',
        $current['state']==='sent' && isset($current['receipt']['previous_failure']['receipt_json']));

    $post = $failure(); $mode = 'ready'; $preview = $recover($post);
    $mode = 'changed_content'; $result = $recover($post,$preview['recovery_digest'],true);
    $current = $registry->publication($post['publication_id']);
    $check('changed returned caption cannot falsely confirm a recovered button',
        !$result['ok'] && $current['state']==='unknown' && $writes===3
        && $current['receipt']['content_preserved']===false && isset($current['receipt']['previous_failure']));
}
