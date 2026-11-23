<?php
declare(strict_types=1);
// Exact approved 66198 binding for the existing schema-5 feed publisher.
// No top-level action and no new writer. The publisher remains the d44ff7f blob.

function wf_need(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
}
function wf_names(): array { return ['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']; }
function wf_urls(): array { return ['telegram'=>'https://t.me/any_toour/678','max'=>'https://max.ru/channel_anytour/AaHo8ukNYB8']; }
function wf_hash(mixed $v): bool { return is_string($v) && preg_match('/\A[a-f0-9]{64}\z/D',$v)===1; }
function wf_binding(): array {
    $b=json_decode(base64_decode(FEED66198_BINDING_B64,true),true,16,JSON_THROW_ON_ERROR);
    wf_need(array_keys($b)===['schema','kind','installed_sha','caption_operation','feed_operation','approval_comment_id']
        && $b['schema']===1 && $b['kind']==='CONTENT66198_FEED_BINDING'
        && $b['installed_sha']==='7320b3ac1f178e28d2144ab4424a13cc0fd4e696'
        && $b['caption_operation']==='commercial-waldorf66198-caption-correction-20261123-v1'
        && $b['feed_operation']==='commercial-channel-hotel-feeds-20261123-update66198'
        && $b['approval_comment_id']===7072600922,'waldorf_binding_changed');
    return $b;
}
function wf_context(): array {
    return json_decode(base64_decode(FEED66198_CONTEXT_B64,true),true,128,JSON_THROW_ON_ERROR);
}
function wf_correct(string $text): string {
    $old='Одиннадцать ресторанов'; $new='Десять ресторанов, баров и лаунжей';
    wf_need(preg_match('//u',$text)===1 && strlen($text)>0 && strlen($text)<30000,'waldorf_text_invalid');
    if (substr_count($text,$old)===1 && substr_count($text,$new)===0) return str_replace($old,$new,$text);
    wf_need(substr_count($text,$old)===0 && substr_count($text,$new)===1,'waldorf_phrase_not_unique');
    return $text;
}
function wf_variant(string $actual,string $current): bool {
    $fixed=wf_correct($current);
    return $actual===$fixed || $actual===str_replace('Десять ресторанов, баров и лаунжей','Одиннадцать ресторанов',$fixed);
}
function wf_source(): void {
    $b=wf_binding();
    wf_need(PHP_SAPI==='cli' && getcwd()==='/var/www/anytoour/data/www/app.anytoour.ru'
        && trim((string)shell_exec('git rev-parse HEAD'))===$b['installed_sha']
        && trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','waldorf_installed_source_changed');
}
function wf_locked_jsonl(string $path, int $cap, int $kind, array &$locks): array {
    wf_need(!is_link(dirname($path)) && realpath(dirname($path))===dirname($path)
        && !is_link($path) && is_file($path) && realpath($path)===$path,'waldorf_journal_path_changed');
    $before=lstat($path); $f=fopen($path,'rb');
    wf_need(is_array($before) && is_resource($f),'waldorf_journal_open_failed'); $locks[]=$f;
    wf_need(flock($f,$kind|LOCK_NB),'waldorf_existing_operation_busy'); $s=fstat($f);
    wf_need(is_array($s) && $s['ino']===$before['ino'] && $s['dev']===$before['dev']
        && ($s['mode']&0170000)===0100000 && $s['nlink']===1 && $s['size']>0 && $s['size']<=$cap,'waldorf_journal_identity_changed');
    $h=hash_init('sha256'); $bytes=0; $lines=0; $last=null; $seen=[];
    while (!feof($f)) {
        $line=fgets($f,16777218);
        if ($line===false) { wf_need(feof($f),'waldorf_journal_read_failed'); break; }
        $bytes+=strlen($line); $lines++;
        wf_need($bytes<=$cap && strlen($line)<=16777216 && str_ends_with($line,"\n"),'waldorf_journal_incomplete');
        hash_update($h,$line); $last=$line;
        preg_match_all('/"(commercial-channel-hotel-feeds-[0-9]{8}-update[1-9][0-9]*)"/',$line,$m);
        foreach ($m[1] as $op) $seen[$op]=true;
    }
    clearstatcache(true,$path); $after=fstat($f); $named=lstat($path);
    wf_need(is_array($after) && is_array($named) && !is_link($path) && realpath($path)===$path
        && $bytes===$s['size'] && is_string($last),'waldorf_journal_read_changed');
    foreach ([$after,$named] as $a) foreach (['ino','dev','size','mtime','ctime','nlink'] as $k)
        wf_need($a[$k]===$s[$k],'waldorf_journal_read_changed');
    $state=json_decode($last,true,128,JSON_THROW_ON_ERROR);
    wf_need(is_array($state),'waldorf_journal_state_invalid');
    return ['state'=>$state,'sha256'=>hash_final($h),'bytes'=>$bytes,'lines'=>$lines,'feed_operations_seen'=>array_keys($seen)];
}
function wf_monitor(array $pin, array &$locks): array {
    wf_need(array_keys($pin)===['sha256','bytes','lines','collector_run_id','cycle_state'] && wf_hash($pin['sha256'])
        && is_int($pin['bytes']) && $pin['bytes']>0 && $pin['bytes']<=2147483648
        && is_int($pin['lines']) && $pin['lines']>0,'waldorf_monitor_pin_invalid');
    $r=wf_locked_jsonl('/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl',$pin['bytes'],LOCK_EX,$locks);
    $s=$r['state']; wf_monitor_state($s,$pin);
    foreach (['sha256','bytes','lines'] as $k) wf_need($r[$k]===$pin[$k],'waldorf_monitor_pin_changed');
    $op=wf_binding()['feed_operation'];
    wf_need(!in_array($op,$r['feed_operations_seen'],true),'waldorf_feed_operation_used');
    $dir='/var/www/anytoour/data/channel-post-operations/'.$op;
    wf_need(!file_exists($dir) && !is_link($dir),'waldorf_prior_feed_requires_reconciliation');
    foreach (wf_names() as $name) {
        $tmp='/var/www/anytoour/data/www/app.anytoour.ru/feed/.'.$op.'-'.$name.'.tmp';
        wf_need(!file_exists($tmp) && !is_link($tmp),'waldorf_prior_feed_requires_reconciliation');
    }
    return $r;
}
function wf_monitor_state(array $s,array $pin): void {
    wf_need(($s['schema']??null)===1 && array_key_exists('pending',$s) && $s['pending']===null,'waldorf_monitor_pending');
    $cycle=$s['nightly_cycle']??null;
    wf_need(is_array($cycle) && ($cycle['collector_run_id']??null)===$pin['collector_run_id']
        && ($cycle['state']??null)===$pin['cycle_state'],'waldorf_monitor_state_changed');
}
function wf_xml(string $xml): array {
    wf_need($xml!=='' && strlen($xml)<4000000 && preg_match('//u',$xml)===1
        && stripos($xml,'<!DOCTYPE')===false && stripos($xml,'<!ENTITY')===false
        && stripos($xml,'available=')===false,'waldorf_xml_invalid');
    $d=new DOMDocument(); $prior=libxml_use_internal_errors(true);
    try { $ok=$d->loadXML($xml,LIBXML_NONET); } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    wf_need($ok && $d->documentElement?->tagName==='yml_catalog','waldorf_xml_invalid');
    $nodes=[];
    foreach ($d->getElementsByTagName('offer') as $node) {
        $id=$node->getAttribute('id');
        wf_need(preg_match('/\A[A-Za-z0-9_-]+\z/D',$id)===1 && !isset($nodes[$id]),'waldorf_offer_duplicate'); $nodes[$id]=$node;
    }
    $n=preg_match_all('/<offer id="([A-Za-z0-9_-]+)">.*?<\/offer>/s',$xml,$m,PREG_SET_ORDER); $blocks=[];
    wf_need($n===count($nodes),'waldorf_offer_envelope_changed');
    foreach ($m as $x) { wf_need(!isset($blocks[$x[1]]),'waldorf_offer_duplicate'); $blocks[$x[1]]=$x[0]; }
    wf_need(array_keys($nodes)===array_keys($blocks),'waldorf_offer_order_changed'); return ['nodes'=>$nodes,'blocks'=>$blocks];
}
function wf_field(DOMElement $node,string $tag): string {
    $n=$node->getElementsByTagName($tag);
    wf_need($n->length===1 && $n->item(0)?->parentNode?->isSameNode($node)===true,'waldorf_field_not_unique');
    return $n->item(0)->textContent;
}
function wf_feed_url(string $platform,string $id,string $url): string {
    if ($platform==='telegram') return $url;
    $alias='h1_'.substr(hash('sha256','anytour|'.$id.'|'.$url),0,32);
    $ctx=json_encode(['post_id'=>$alias,'offer_id'=>$id],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return 'https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'.rtrim(strtr(base64_encode($ctx),'+/','-_'),'=');
}
function wf_posts(array $posts): array {
    wf_need(array_is_list($posts) && count($posts)===2,'waldorf_post_scope_changed'); $out=[];
    foreach ($posts as $p) {
        $platform=$p['platform']??'';
        wf_need(isset(wf_urls()[$platform]) && !isset($out[$platform]) && ($p['hotel_id']??null)===66198
            && ($p['post_url']??null)===wf_urls()[$platform] && is_int($p['publication_id']??null) && $p['publication_id']>0
            && is_int($p['version_id']??null) && $p['version_id']>0 && is_string($p['message_id']??null)
            && $p['message_id']!=='' && is_string($p['caption']??null) && wf_hash($p['caption_sha256']??null)
            && hash('sha256',$p['caption'])===$p['caption_sha256'],'waldorf_post_identity_changed');
        wf_need($platform!=='telegram' || $p['message_id']==='678','waldorf_telegram_message_changed');
        wf_correct($p['caption']); $out[$platform]=$p;
    }
    wf_need(isset($out['telegram'],$out['max']),'waldorf_pair_incomplete'); return $out;
}
function wf_registry(array $posts, bool $confirmed=false): void {
    ob_start();
    try { require_once getcwd().'/config.php'; require_once getcwd().'/services/ChannelPostRuntime.php'; } finally { ob_end_clean(); }
    $pdo=ConversationDb::connection(); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    try {
        $registry=new ChannelPostRegistryService($pdo);
        foreach ($posts as $u) {
            wf_no_publication_claim($pdo,$u['publication_id']);
            $p=$registry->publication($u['publication_id']);
            wf_need(is_array($p) && (int)$p['publication_id']===$u['publication_id']
                && (int)$p['version_id']===($confirmed?$u['confirmed_version_id']:$u['version_id'])
                && ($p['state']??null)==='sent' && ($p['platform']??null)===$u['platform']
                && ($p['external_message_id']??null)===$u['message_id'] && ($p['source_key']??null)===$u['source_key']
                && ($p['snapshot']['post_url']??null)===$u['post_url']
                && ($p['snapshot']['text']??null)===($confirmed?$u['text']:$u['caption']),'waldorf_current_registry_changed');
        }
    } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
}
function wf_no_publication_claim(PDO $pdo,int $publicationId): void {
    $q=$pdo->prepare("SELECT COUNT(*) FROM post_versions WHERE publication_id=? AND (
        state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)");
    wf_need($q!==false && $q->execute([$publicationId]) && (int)$q->fetchColumn()===0,'waldorf_publication_claim_or_unknown');
}
function wf_confirmed(array $state,array $op,array $bindings): array {
    $b=wf_binding();
    wf_need($op['operation']===$b['caption_operation'] && $op['installed_sha']===$b['installed_sha']
        && $op['row_count']===2 && wf_hash($op['manifest_sha256']) && wf_hash($op['journal_sha256'])
        && ($state['operation']??null)===$op['operation'] && ($state['installed_sha']??null)===$op['installed_sha']
        && ($state['manifest_sha256']??null)===$op['manifest_sha256'] && ($state['complete']??null)===true
        && ($state['row_count']??null)===2 && is_array($state['posts']??null) && count($state['posts'])===2
        && array_is_list($bindings) && count($bindings)===2,'waldorf_caption_terminal_invalid');
    $out=[]; $platforms=[];
    foreach ($bindings as $u) {
        $platform=$u['platform']??''; $key=$platform.':'.($u['publication_id']??'');
        wf_need(isset(wf_urls()[$platform]) && !isset($platforms[$platform]) && $u['hotel_id']===66198
            && $u['post_url']===wf_urls()[$platform] && $u['operation']===$op['operation'],'waldorf_caption_binding_changed');
        $platforms[$platform]=true; $post=$state['posts'][$key]??null; $p=$post['plan']??null; $r=$post['receipt']??null;
        wf_need(($post['state']??null)==='confirmed' && is_array($p) && is_array($r),'waldorf_caption_unconfirmed');
        foreach (['hotel_id','platform','publication_id','message_id','post_url','photo_signature','button_url_sha256','source_key'] as $k)
            wf_need(($p[$k]??null)===$u[$k] && ($r[$k]??null)===$u[$k],'waldorf_caption_identity_changed');
        $old=$p['old_text']??null; $new=$p['new_text']??null;
        wf_need(is_string($old) && is_string($new) && wf_correct($old)===$new
            && hash('sha256',$old)===$u['previous_text_sha256'] && hash('sha256',$new)===$u['text_sha256']
            && ($p['old_text_sha256']??null)===$u['previous_text_sha256'] && ($p['new_text_sha256']??null)===$u['text_sha256']
            && ($r['previous_text_sha256']??null)===$u['previous_text_sha256'] && ($r['text_sha256']??null)===$u['text_sha256']
            && ($p['version_id']??null)===$u['previous_version_id'] && ($r['previous_version_id']??null)===$u['previous_version_id']
            && is_int($r['version_id']??null) && $r['version_id']>0,'waldorf_caption_text_or_version_changed');
        $skip=$old===$new;
        wf_need(($r['same_caption_skipped']??null)===$skip && (($r['version_id']===$u['previous_version_id'])===$skip)
            && ($r['caption_correction_operation']??null)===($skip?$u['previous_caption_correction_operation']:$op['operation'])
            && ($r['monitor_price_record_id']??null)===($p['monitor_price_record_id']??null),'waldorf_caption_lineage_changed');
        wf_need(($p['attachments_sha256']??null)===$u['attachments_sha256']
            && ($r['previous_attachments_sha256']??null)===$u['attachments_sha256'] && wf_hash($r['attachments_sha256']??null)
            && ($r['state']??null)==='sent' && ($r['button_text']??null)==='✈️ Узнать стоимость тура'
            && $u['source_key']===($platform==='max'?'max_anytour_msk1':'tg_anytour_msk'),'waldorf_caption_preservation_changed');
        foreach (['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version'] as $k)
            wf_need(($r[$k]??null)===true,'waldorf_caption_verification_missing');
        $prefix=$platform==='max'?'max_':'tg_';
        wf_need(($u['offer_id']??null)===$prefix.'66198'
            && $u['feed_url']===wf_feed_url($platform,$u['offer_id'],$u['post_url']),'waldorf_feed_binding_changed');
        $out[]=$u+['old_text'=>$old,'text'=>$new,'confirmed_version_id'=>$r['version_id']];
    }
    wf_need(count($platforms)===2,'waldorf_pair_incomplete'); return $out;
}
function caption_feed_admit(array $input,string $installed,array &$locks): array {
    $b=wf_binding(); $ctx=wf_context();
    wf_need($installed===$b['installed_sha'] && $input['operation']===$b['feed_operation']
        && count($input['caption_operations'])===1,'waldorf_feed_admission_scope_changed');
    wf_monitor($ctx['journal_pin'],$locks); $op=$input['caption_operations'][0];
    wf_need($op['operation']===$b['caption_operation'],'waldorf_caption_operation_changed');
    $r=wf_locked_jsonl('/var/www/anytoour/data/channel-post-operations/'.$op['operation'].'/receipt.jsonl',134217728,LOCK_SH,$locks);
    wf_need($r['sha256']===$op['journal_sha256'],'waldorf_caption_journal_changed');
    $updates=wf_confirmed($r['state'],$op,$input['caption_updates']); wf_registry($updates,true); return $updates;
}
function caption_feed_render(string $xml,array $updates,string $name): string {
    wf_need(in_array($name,wf_names(),true) && count($updates)===2,'waldorf_render_scope_changed');
    $parsed=wf_xml($xml); $targets=[];
    foreach ($updates as $u) {
        if (($name==='hotel-posts-telegram.xml' && $u['platform']!=='telegram') || ($name==='hotel-posts-max.xml' && $u['platform']!=='max')) continue;
        wf_need(!isset($targets[$u['offer_id']]),'waldorf_target_duplicate'); $targets[$u['offer_id']]=$u;
    }
    wf_need(count($targets)===($name==='hotel-posts-all.xml'?2:1),'waldorf_target_count_changed');
    foreach ($targets as $id=>$u) {
        $node=$parsed['nodes'][$id]??null;
        wf_need($node instanceof DOMElement && wf_field($node,'url')===$u['feed_url']
            && wf_field($node,'custom_label_0')===$u['platform'] && wf_variant(wf_field($node,'description'),$u['old_text'])
            && wf_correct($u['old_text'])===$u['text'],'waldorf_description_preimage_changed');
    }
    $changed=0;
    $out=preg_replace_callback('/<offer id="([A-Za-z0-9_-]+)">.*?<\/offer>/s',static function(array $m) use($targets,&$changed): string {
        if (!isset($targets[$m[1]])) return $m[0]; $u=$targets[$m[1]]; $count=0;
        $block=preg_replace_callback('~(<description>)(.*?)(</description>)~s',static function(array $n) use($u,&$changed): string {
            $old='Одиннадцать ресторанов'; $new='Десять ресторанов, баров и лаунжей';
            if (substr_count($n[2],$old)===0 && substr_count($n[2],$new)===1) return $n[0];
            wf_need(substr_count($n[2],$old)===1 && substr_count($n[2],$new)===0,'waldorf_raw_phrase_changed'); $changed++;
            return $n[1].str_replace($old,$new,$n[2]).$n[3];
        },$m[0],-1,$count);
        wf_need(is_string($block) && $count===1,'waldorf_description_not_unique'); return $block;
    },$xml);
    wf_need(is_string($out),'waldorf_render_failed'); $after=wf_xml($out);
    wf_need(array_keys($parsed['blocks'])===array_keys($after['blocks']),'waldorf_offers_changed');
    foreach ($targets as $id=>$u) wf_need(wf_field($after['nodes'][$id],'description')===$u['text'],'waldorf_description_projection_changed');
    return $out;
}
