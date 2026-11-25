<?php
declare(strict_types=1);

require_once __DIR__.'/../services/PromotedHotelPriceWriter.php';
require_once __DIR__.'/../services/ChannelPostRegistryService.php';
require_once __DIR__.'/../services/ChannelPostButtonTransport.php';
require_once __DIR__.'/fixtures/channel_post_registry_fixture.php';

function promotedPriceCheck(bool $ok, string $case): void
{
    if (!$ok) throw new RuntimeException($case);
}

$caption = "✈️ Seagull & Sea\n\nХургада, Египет";
$price = "💰 От 102 233 ₽ за двоих\n✈️ Вылет из Москвы 16.01.2027 · 5 ночей\n"
    ."👥 2 взрослых\n🛏 standard room\n🍽 Всё включено\n"
    ."Цена найдена мониторингом 19.11.2026, 04:01 МСК. Стоимость и наличие могут измениться.";
$first = PromotedHotelPriceWriter::revision($caption,$price,true);
promotedPriceCheck($first === $caption."\n\n".$price, 'caption_copy_changed');
$next = str_replace(['102 233','04:01'],['102 222','04:02'],$price);
promotedPriceCheck(PromotedHotelPriceWriter::revision($first,$next,true) === $caption."\n\n".$next, 'duplicate_price_blocks');
promotedPriceCheck(PromotedHotelPriceWriter::revision($caption."\n\n".$next,$price,true) === $caption."\n\n".$next, 'older_price_overwrite');
promotedPriceCheck(PromotedHotelPriceWriter::revision($first,$price,true) === $first, 'same_price_not_idempotent');
$length = intdiv(strlen(mb_convert_encoding('✈️ Seagull & Sea','UTF-16LE','UTF-8')),2);
$html = PromotedHotelPriceWriter::maxHtml($caption,$first,[['type'=>'strong','from'=>0,'length'=>$length]]);
promotedPriceCheck(str_starts_with($html,"<b>✈️ Seagull &amp; Sea</b>\n\n") && str_ends_with($html,$price), 'max_markup_lost');
promotedPriceCheck(PromotedHotelPriceWriter::maxPublicCaption('message:{text:"JAZ Fanara &amp; Residence"}')
    === 'JAZ Fanara & Residence', 'max_public_html_entities_not_decoded');

$maxPublicUrl = 'https://max.ru/anytour/AaC42fW_AY0';
$otherMaxPublicUrl = 'https://max.ru/anytour/AZ-d-XrfADw';
$currentMaxPayload = static fn(string $url, string $text): string =>
    'message:{id:117296836581982610,link:'.json_encode($url, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    .',createdAt:"2026-09-19T08:48:07.103Z",updatedAt:"2026-11-25T03:47:56.337Z",text:'
    .json_encode($text, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).',attachments:[]}';
$currentMax = $currentMaxPayload($maxPublicUrl, "✈️ Туры в Sun Siyam\nМоре &amp; пляж");
$foreignMax = $currentMaxPayload($otherMaxPublicUrl, 'Другой пост');
promotedPriceCheck(PromotedHotelPriceWriter::maxPublicCaption($foreignMax."\n".$currentMax, $maxPublicUrl)
    === "✈️ Туры в Sun Siyam\nМоре & пляж", 'current_max_public_link_or_caption_lost');
foreach ([$foreignMax, $currentMax."\n".$currentMax] as $unsafeMaxPublic) {
    try {
        PromotedHotelPriceWriter::maxPublicCaption($unsafeMaxPublic, $maxPublicUrl);
    } catch (RuntimeException $error) {
        promotedPriceCheck($error->getMessage()==='max_public_caption_unavailable', 'unexpected_max_public_failure');
        continue;
    }
    throw new RuntimeException('wrong_or_ambiguous_max_public_link_accepted');
}

foreach ([
    fn()=>PromotedHotelPriceWriter::revision('Старая цена 100 000 ₽',$price,true),
    fn()=>PromotedHotelPriceWriter::revision(str_repeat('😀',510),$price,true),
    fn()=>PromotedHotelPriceWriter::maxHtml('😀 open','😀 open tail',[['type'=>'strong','from'=>1,'length'=>1]]),
    fn()=>PromotedHotelPriceWriter::maxHtml('old','new',[['type'=>'strong','from'=>0,'length'=>3]]),
] as $reject) {
    try { $reject(); } catch (RuntimeException) { continue; }
    throw new RuntimeException('unsafe_revision_accepted');
}

$tg = ['hotel_id'=>415, 'platform'=>'telegram', 'publication_id'=>20, 'url'=>'https://t.me/anytour/1754'];
$max = ['hotel_id'=>174, 'platform'=>'max', 'publication_id'=>3574, 'url'=>'https://max.ru/anytour/AaHzN34rD6Q'];
$targets = [$tg, $max];
$targetBytes = json_encode($targets, JSON_THROW_ON_ERROR);
$feedHash = hash('sha256', 'offline-price-fixture');
$plan = ['feed_sha256'=>$feedHash, 'targets_sha256'=>hash('sha256', $targetBytes),
    'target_hotels'=>2, 'eligible_hotels'=>2, 'held'=>[], 'plans'=>[
        ['hotel_id'=>415, 'price_block'=>$price, 'telegram_url'=>$tg['url'], 'telegram_publication_id'=>20],
        ['hotel_id'=>174, 'price_block'=>$price, 'max_url'=>$max['url'], 'max_publication_id'=>3574],
    ]];
$jobs = PromotedHotelPriceWriter::jobsForPlan($plan, $feedHash, $targetBytes);
promotedPriceCheck(array_column($jobs, 'publication') === [20,3574]
    && array_column($jobs, 'platform') === ['telegram','max'] && array_column($jobs, 'url') === [$tg['url'],$max['url']],
    'single_platform_misrouted');
$held = $plan;
array_pop($held['plans']);
$held['eligible_hotels'] = 1;
$held['held'][] = ['hotel_id'=>174,'reason'=>'missing_or_duplicate_feed_offer'];
promotedPriceCheck(count(PromotedHotelPriceWriter::jobsForPlan($held, $feedHash, $targetBytes)) === 1, 'held_hotel_edited');

$rejectPlan = static function (array $p, array $scope, string $reason) use ($feedHash): void {
    $raw = json_encode($scope, JSON_THROW_ON_ERROR);
    $p['targets_sha256'] = hash('sha256', $raw);
    try {
        PromotedHotelPriceWriter::jobsForPlan($p, $feedHash, $raw);
    } catch (RuntimeException $error) {
        promotedPriceCheck($error->getMessage() === $reason, 'unexpected_plan_rejection');
        return;
    }
    throw new RuntimeException('unsafe_plan_accepted');
};
foreach (['max_publication_id'=>20, 'max_url'=>'https://max.ru/anytour/Other',
    'telegram_url'=>$tg['url'], 'telegram_publication_id'=>20, 'other_url'=>$tg['url'], 'hotel_id'=>415] as $field=>$wrong) {
    $new = $plan;
    $new['plans'][1][$field] = $wrong;
    $rejectPlan($new, $targets, 'price_plan_mismatch');
}
$mismatch = $held;
$mismatch['held'] = [];
$rejectPlan($mismatch, $targets, 'price_plan_mismatch');
$mismatch = $held;
$mismatch['held'][0]['hotel_id'] = 415;
$rejectPlan($mismatch, $targets, 'price_plan_mismatch');
$mismatch = $plan;
$mismatch['feed_sha256'] = hash('sha256', 'different snapshot');
$rejectPlan($mismatch, $targets, 'price_plan_mismatch');
try {
    PromotedHotelPriceWriter::jobsForPlan($plan, $feedHash, $targetBytes."\n");
    throw new LogicException('roster_hash_mismatch_accepted');
} catch (RuntimeException $error) {
    promotedPriceCheck($error->getMessage() === 'price_plan_mismatch', 'wrong_scope_rejection');
}
$rejectPlan($plan, [$tg, array_replace($max, ['publication_id'=>20])], 'duplicate_promoted_target');
$rejectPlan($plan, [$tg, array_replace($tg, ['hotel_id'=>174,'publication_id'=>3574])], 'duplicate_promoted_target');
$rejectPlan($plan, [$tg, array_replace($tg, ['url'=>'https://t.me/anytour/1792','publication_id'=>3575])], 'duplicate_promoted_target');
$rejectPlan($plan, [$tg, array_replace($max, ['url'=>'https://max.ru/channel_anytour/wrong'])], 'invalid_promoted_target');

/** A successful native edit whose public readback was delayed, entirely offline. */
function promotedPriceReconciliationFixture(string $platform): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO projects VALUES (1,'anytour','AnyTour',1)");
    $pdo->prepare('INSERT INTO conversation_sources VALUES (11,1,?,?,?,?,?)')
        ->execute(['price_fixture', 'Price fixture', $platform, 1, 'manager']);
    $registry = new ChannelPostRegistryService($pdo);
    $channelId = $platform === 'telegram' ? '-1001845835645' : '-68765999669267';
    $channel = $registry->configureChannel(['project_key'=>'anytour', 'platform'=>$platform,
        'bot_key'=>'fixture_bot', 'channel_id'=>$channelId, 'source_key'=>'price_fixture',
        'display_name'=>'Price fixture', 'bot_url'=>($platform === 'telegram' ? 'https://t.me/' : 'https://max.ru/').'fixture_bot',
        'is_enabled'=>true]);
    $hotel = 415;
    $mid = $platform === 'telegram' ? '1754' : 'mid.'.str_repeat('a',32);
    $url = $platform === 'telegram' ? 'https://t.me/anytour/1754' : 'https://max.ru/anytour/AaHzN34rD6Q';
    $observed = new DateTimeImmutable('-1 hour', new DateTimeZone('Europe/Moscow'));
    $block = "💰 От 102 233 ₽ за двоих\n✈️ Вылет из Москвы ".$observed->modify('+30 days')->format('d.m.Y')." · 5 ночей\n"
        ."👥 2 взрослых\n🛏 standard room\n🍽 Всё включено\nЦена найдена мониторингом "
        .$observed->format('d.m.Y, H:i')." МСК. Стоимость и наличие могут измениться.";
    $text = "✈️ Seagull & Sea\n\nХургада, Египет";
    if ($platform === 'telegram') {
        $raw = ['message_id'=>(int)$mid, 'date'=>time()-7200,
            'chat'=>['id'=>$channelId, 'type'=>'channel', 'username'=>'anytour'], 'caption'=>$text,
            'photo'=>[['file_id'=>'fixture-private', 'file_unique_id'=>'fixture-stable', 'width'=>1000, 'height'=>1000]]];
    } else {
        $raw = ['timestamp'=>(time()-7200)*1000, 'url'=>$url,
            'recipient'=>['chat_id'=>$channelId, 'chat_type'=>'channel'],
            'body'=>['mid'=>$mid, 'text'=>$text, 'attachments'=>[['type'=>'image', 'payload'=>['photo_id'=>'12345']]]]];
    }
    $adapt = $platform === 'telegram' ? ChannelPostIncomingAdapter::telegramSnapshot(...) : ChannelPostIncomingAdapter::maxSnapshot(...);
    $snapshot = $adapt($raw);
    $snapshot['metadata']['hotel_catalog_id'] = $hotel;
    $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
    $original = $registry->registerPublication('anytour', $platform, 'fixture_bot', $channelId, $mid, $snapshot);
    promotedPriceCheck(is_array($original) && $registry->confirmObservedButton($original['publication_id'], $original['version_id']),
        'reconciliation_original_fixture_failed');
    $button = ['text'=>$channel['button_text'], 'url'=>ChannelPostButtonTransport::buttonUrl($original)];
    $new = PromotedHotelPriceWriter::revision($text, $block, $platform === 'telegram');
    $at = (time()-60)*1000;
    if ($platform === 'telegram') {
        $raw['caption'] = $new;
        $raw['edit_date'] = intdiv($at,1000);
        $raw['reply_markup'] = ['inline_keyboard'=>[[$button]]];
    } else {
        $raw['body']['text'] = $new;
        $raw['body']['attachments'][] = ['type'=>'inline_keyboard', 'payload'=>['buttons'=>[[['type'=>'link']+$button]]]];
    }
    $snapshot = $adapt($raw);
    $snapshot['metadata'] += ['hotel_catalog_id'=>$hotel, 'source_event_id'=>'promoted-price:'.$original['publication_id'].':'.$at,
        'observation_kind'=>'authorized_admin_content_edit', 'source_revision_ms'=>(string)$at];
    $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot, '', $channel['bot_url']);
    $post = $registry->registerPublication('anytour', $platform, 'fixture_bot', $channelId, $mid, $snapshot);
    promotedPriceCheck(is_array($post) && $post['version_id'] !== $original['version_id']
        && $registry->confirmObservedButton($post['publication_id'], $post['version_id'],
            ['verified'=>true, 'button_verified'=>true, 'content_preserved'=>true, 'write_attempts'=>0]),
        'reconciliation_provider_fixture_failed');
    $post = $registry->publication($post['publication_id']);
    $receipt = ['state'=>'provider_verified', 'platform'=>$platform, 'hotel'=>$hotel, 'url'=>$url,
        'version'=>$original['version_id'], 'at'=>$at, 'next_hash'=>hash('sha256',$new), 'new_version'=>$post['version_id']];
    $directory = sys_get_temp_dir().'/promoted-price-regression-'.bin2hex(random_bytes(8));
    promotedPriceCheck(mkdir($directory,0700), 'reconciliation_temp_directory_failed');
    $path = $directory.'/'.$post['publication_id'].'.json';
    file_put_contents($path, json_encode($receipt,JSON_THROW_ON_ERROR)."\n");
    return compact('pdo','registry','post','original','platform','hotel','url','block','receipt','directory','path')
        + ['public'=>['caption'=>$new, 'buttons'=>$platform === 'telegram' ? [$button] : []]];
}

function promotedPriceRegistryState(PDO $pdo): array
{
    $rows = [];
    foreach (['post_channels','post_publications','post_versions','post_observations'] as $table) {
        $rows[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
    }
    return $rows;
}

/** Execute the real private update path; the SQLite registry is read-only here. */
function promotedPriceReconcile(array $fixture, bool $apply, ?Closure $duringRead = null): array
{
    $pdo = $fixture['pdo'];
    $expected = promotedPriceRegistryState($pdo);
    $changes = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $reads = 0;
    $reader = static function (string $platform, string $url) use ($fixture,$duringRead,$pdo,&$expected,&$changes,&$reads): array {
        promotedPriceCheck($platform === $fixture['platform'] && $url === $fixture['url'], 'reconciliation_public_target_changed');
        $reads++;
        if ($duringRead !== null) {
            $pdo->exec('PRAGMA query_only=OFF');
            try { $duringRead($fixture); } finally { $pdo->exec('PRAGMA query_only=ON'); }
            $expected = promotedPriceRegistryState($pdo);
            $changes = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
        }
        return $fixture['public'];
    };
    $writer = new PromotedHotelPriceWriter($pdo, $fixture['registry'], $fixture['directory'], $reader);
    $attempted = false;
    $answer = null;
    $error = null;
    $pdo->exec('PRAGMA query_only=ON');
    try {
        $answer = (new ReflectionMethod($writer,'update'))->invokeArgs($writer,
            [$fixture['post']['publication_id'], $fixture['platform'], $fixture['url'], $fixture['hotel'], $fixture['block'], $apply, &$attempted]);
    } catch (Throwable $failure) {
        $error = $failure->getMessage();
    } finally {
        $pdo->exec('PRAGMA query_only=OFF');
    }
    promotedPriceCheck(!$attempted, 'reconciliation_attempted_provider_write');
    promotedPriceCheck(promotedPriceRegistryState($pdo) === $expected
        && (int)$pdo->query('SELECT total_changes()')->fetchColumn() === $changes, 'reconciliation_mutated_registry');
    return compact('answer','error','reads');
}

function promotedPriceReconciliationCase(string $platform, Closure $check): void
{
    $fixture = promotedPriceReconciliationFixture($platform);
    try { $check($fixture); } finally {
        foreach (glob($fixture['directory'].'/*') as $path) unlink($path);
        rmdir($fixture['directory']);
    }
}

function promotedPriceJournalIdentity(string $path): array
{
    clearstatcache(true,$path);
    return ['bytes'=>file_get_contents($path), 'inode'=>fileinode($path)];
}

function promotedPriceReconciliationHeld(array $fixture, string $reason, ?Closure $duringRead = null, int $reads = 1): void
{
    $before = promotedPriceJournalIdentity($fixture['path']);
    $result = promotedPriceReconcile($fixture, true, $duringRead);
    promotedPriceCheck($result['error'] === $reason && $result['answer'] === null && $result['reads'] === $reads,
        'reconciliation_wrong_rejection:'.$reason.':'.($result['error'] ?? 'none'));
    promotedPriceCheck(promotedPriceJournalIdentity($fixture['path']) === $before, 'held_reconciliation_changed_journal');
}

// No bootstrap or provider credentials: an accidental native branch cannot use live APIs.
promotedPriceCheck(!defined('MAX_SEARCH_TOKEN') && !defined('TELEGRAM_BOT_TOKEN'), 'reconciliation_fixture_loaded_provider_credentials');
foreach (['telegram','max'] as $platform) {
    promotedPriceReconciliationCase($platform, static function (array $fixture): void {
        $before = promotedPriceJournalIdentity($fixture['path']);
        $inspect = promotedPriceReconcile($fixture, false);
        promotedPriceCheck($inspect['error'] === null && $inspect['reads'] === 1
            && $inspect['answer'] === ['result'=>'already_current', 'public_verified'=>true]
            && promotedPriceJournalIdentity($fixture['path']) === $before, 'reconciliation_inspect_changed_journal');
        $apply = promotedPriceReconcile($fixture, true);
        promotedPriceCheck($apply['error'] === null && $apply['reads'] === 1
            && $apply['answer'] === ['result'=>'already_current', 'public_verified'=>true], 'reconciliation_apply_failed');
        $verified = json_decode(file_get_contents($fixture['path']),true,16,JSON_THROW_ON_ERROR);
        promotedPriceCheck($verified === array_replace($fixture['receipt'], ['state'=>'verified']),
            'delayed_readback_journal_not_verified:'.$fixture['platform']);
        $verifiedFile = promotedPriceJournalIdentity($fixture['path']);
        $repeat = promotedPriceReconcile($fixture, true);
        promotedPriceCheck($repeat['error'] === null && $repeat['answer'] === $apply['answer']
            && promotedPriceJournalIdentity($fixture['path']) === $verifiedFile
            && glob($fixture['directory'].'/*') === [$fixture['path']], 'reconciliation_repeat_changed_journal');
    });
}

promotedPriceReconciliationCase('telegram', static function (array $fixture): void {
    unlink($fixture['path']);
    $result = promotedPriceReconcile($fixture, true);
    promotedPriceCheck($result['error'] === null && $result['answer'] === ['result'=>'already_current','public_verified'=>true]
        && glob($fixture['directory'].'/*') === [], 'already_current_without_receipt_created_journal');
});

promotedPriceReconciliationCase('telegram', static function (array $fixture): void {
    $receipt = array_replace($fixture['receipt'], ['state'=>'started']);
    unset($receipt['new_version']);
    file_put_contents($fixture['path'],json_encode($receipt,JSON_THROW_ON_ERROR)."\n");
    promotedPriceReconciliationHeld($fixture,'previous_price_edit_unknown',null,0);
    $before = promotedPriceJournalIdentity($fixture['path']);
    $inspect = promotedPriceReconcile($fixture,false);
    promotedPriceCheck($inspect['error'] === 'previous_price_edit_unknown' && $inspect['reads'] === 0
        && promotedPriceJournalIdentity($fixture['path']) === $before, 'started_inspect_lost_unknown_fence');
});

foreach (['telegram','max'] as $platform) {
    promotedPriceReconciliationCase($platform, static function (array $fixture): void {
        $fixture['public']['caption'] = 'The public cache still contains the previous caption';
        promotedPriceReconciliationHeld($fixture,'public_caption_differs_from_registry');
    });
}
foreach (['missing','wrong_url','extra'] as $case) {
    promotedPriceReconciliationCase('telegram', static function (array $fixture) use ($case): void {
        if ($case === 'missing') $fixture['public']['buttons'] = [];
        if ($case === 'wrong_url') $fixture['public']['buttons'][0]['url'] = 'https://t.me/fixture_bot?start=p_foreign';
        if ($case === 'extra') $fixture['public']['buttons'][] = ['text'=>'Other','url'=>'https://example.invalid'];
        promotedPriceReconciliationHeld($fixture,'public_telegram_button_unverified');
    });
}

// A cached public match cannot authorize a different receipt, even when its types can be coerced.
foreach (['platform','hotel','url','version','new_version','at','next_hash','missing_at',
    'hotel_type','version_type','new_version_type','at_type'] as $case) {
    promotedPriceReconciliationCase('telegram', static function (array $fixture) use ($case): void {
        $receipt = $fixture['receipt'];
        switch ($case) {
            case 'platform': $receipt['platform'] = 'max'; break;
            case 'hotel': $receipt['hotel']++; break;
            case 'url': $receipt['url'] = 'https://t.me/anytour/1755'; break;
            case 'version': $receipt['version'] = 0; break;
            case 'new_version': $receipt['new_version']++; break;
            case 'at': $receipt['at'] = 0; break;
            case 'next_hash': $receipt['next_hash'] = hash('sha256','different caption'); break;
            case 'missing_at': unset($receipt['at']); break;
            default:
                $field = substr($case,0,-5);
                $receipt[$field] = (string)$receipt[$field];
        }
        file_put_contents($fixture['path'],json_encode($receipt,JSON_THROW_ON_ERROR)."\n");
        promotedPriceReconciliationHeld($fixture,'previous_price_receipt_mismatch');
    });
}
foreach (['different_publication','different_attempt','observation_kind'] as $case) {
    promotedPriceReconciliationCase('telegram', static function (array $fixture) use ($case): void {
        $snapshot = $fixture['post']['snapshot'];
        if ($case === 'different_publication') {
            $snapshot['metadata']['source_event_id'] = 'promoted-price:'.($fixture['post']['publication_id']+1).':'.$fixture['receipt']['at'];
        } elseif ($case === 'different_attempt') {
            $snapshot['metadata']['source_event_id'] = 'promoted-price:'.$fixture['post']['publication_id'].':'.($fixture['receipt']['at']+1);
        } else {
            $snapshot['metadata']['observation_kind'] = 'public_read';
        }
        $fixture['pdo']->prepare('UPDATE post_versions SET snapshot_json=? WHERE id=?')
            ->execute([json_encode($snapshot,JSON_THROW_ON_ERROR),$fixture['post']['version_id']]);
        promotedPriceReconciliationHeld($fixture,'previous_price_receipt_mismatch');
    });
}

promotedPriceReconciliationCase('telegram', static function (array $fixture): void {
    promotedPriceReconciliationHeld($fixture,'publication_changed_before_reconcile',static function (array $raced): void {
        $raced['pdo']->prepare('UPDATE post_publications SET current_version_id=? WHERE id=?')
            ->execute([$raced['original']['version_id'],$raced['post']['publication_id']]);
    });
});
promotedPriceReconciliationCase('telegram', static function (array $fixture): void {
    // An older version's new claim is invisible in publication(), so the claim query must be fresh too.
    promotedPriceReconciliationHeld($fixture,'publication_claim_changed_before_reconcile',static function (array $raced): void {
        $raced['pdo']->prepare("UPDATE post_versions SET claim_token='other-worker',claimed_at=? WHERE id=?")
            ->execute([gmdate('Y-m-d H:i:s'),$raced['original']['version_id']]);
    });
});
promotedPriceReconciliationCase('telegram', static function (array $fixture): void {
    $fixture['pdo']->prepare("UPDATE post_versions SET state='unknown' WHERE id=?")
        ->execute([$fixture['original']['version_id']]);
    promotedPriceReconciliationHeld($fixture,'original_operation_held',null,0);
});
echo "promoted hotel price editor regression passed\n";
