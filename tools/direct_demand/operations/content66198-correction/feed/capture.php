<?php
declare(strict_types=1);
// Compiled with contract.php; read-only phases of the same 66198 correction.
function wf_capture_files(): array {
    $dir='/var/www/anytoour/data/www/app.anytoour.ru/feed';
    wf_need(!is_link($dir) && realpath($dir)===$dir,'waldorf_feed_directory_changed'); $files=[];
    foreach (wf_names() as $name) {
        $p=$dir.'/'.$name;
        wf_need(!is_link($p) && is_file($p) && realpath($p)===$p,'waldorf_feed_path_changed'); $a=lstat($p);
        wf_need(is_array($a) && $a['size']>1000 && $a['size']<4000000 && $a['nlink']===1,'waldorf_feed_size_changed');
        $f=fopen($p,'rb'); wf_need(is_resource($f),'waldorf_feed_open_failed');
        try { $opened=fstat($f); $raw=stream_get_contents($f,4000001); $closed=fstat($f); } finally { fclose($f); }
        clearstatcache(true,$p); $named=lstat($p);
        wf_need(is_string($raw) && strlen($raw)===$a['size'] && !is_link($p),'waldorf_feed_read_changed');
        foreach ([$opened,$closed,$named] as $s) foreach (['ino','dev','size','mtime','ctime','nlink'] as $k)
            wf_need(is_array($s) && $s[$k]===$a[$k],'waldorf_feed_read_changed');
        $parsed=wf_xml($raw);
        $files[]=['name'=>$name,'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'rows'=>count($parsed['nodes']),'content_base64'=>base64_encode($raw)];
    }
    return $files;
}
function wf_resolve(array $posts,array $files): array {
    $posts=wf_posts($posts); $parsed=[];
    wf_need(array_column($files,'name')===wf_names(),'waldorf_files_changed');
    foreach ($files as $f) $parsed[$f['name']]=wf_xml(base64_decode($f['content_base64'],true));
    $bindings=[];
    foreach (['telegram','max'] as $platform) {
        $p=$posts[$platform]; $name=$platform==='max'?'hotel-posts-max.xml':'hotel-posts-telegram.xml'; $found=[];
        foreach ($parsed[$name]['nodes'] as $id=>$node) {
            if (!str_starts_with($id,$platform==='max'?'max_':'tg_')) continue;
            $url=wf_field($node,'url');
            if ($url===wf_feed_url($platform,$id,$p['post_url'])) $found[]=['id'=>$id,'url'=>$url,'node'=>$node];
        }
        wf_need(count($found)===1,'waldorf_actual_offer_not_unique'); $f=$found[0];
        wf_need($f['id']===($platform==='max'?'max_':'tg_').'66198','waldorf_exact_offer_changed');
        $all=$parsed['hotel-posts-all.xml']['nodes'][$f['id']]??null;
        wf_need($all instanceof DOMElement && wf_field($all,'url')===$f['url']
            && wf_field($all,'custom_label_0')===$platform && wf_field($f['node'],'custom_label_0')===$platform
            && wf_variant(wf_field($all,'description'),$p['caption']) && wf_variant(wf_field($f['node'],'description'),$p['caption']),
            'waldorf_feed_caption_not_current');
        $bindings[]=['operation'=>wf_binding()['caption_operation'],'hotel_id'=>66198,'platform'=>$platform,
            'publication_id'=>$p['publication_id'],'message_id'=>$p['message_id'],'post_url'=>$p['post_url'],
            'previous_version_id'=>$p['version_id'],'previous_text_sha256'=>$p['caption_sha256'],
            'text_sha256'=>hash('sha256',wf_correct($p['caption'])),'photo_signature'=>$p['photo_signature'],
            'attachments_sha256'=>$p['attachments_sha256'],'button_url_sha256'=>$p['button_url_sha256'],
            'source_key'=>$p['source_key'],'offer_id'=>$f['id'],'feed_url'=>$f['url'],
            'previous_caption_correction_operation'=>$p['caption_correction_operation']];
    }
    $t=array_keys($parsed['hotel-posts-telegram.xml']['nodes']); $m=array_keys($parsed['hotel-posts-max.xml']['nodes']);
    $all=array_keys($parsed['hotel-posts-all.xml']['nodes']); $union=array_merge($t,$m); sort($union); sort($all);
    wf_need(count($t)>0 && count($t)===count($m) && $union===$all,'waldorf_feed_inventory_changed');
    return $bindings;
}
function wf_public(string $url): array {
    $ch=curl_init($url); wf_need($ch!==false,'waldorf_public_init_failed');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Cache-Control: no-cache'],CURLOPT_MAXREDIRS=>0]);
    try { $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); }
    finally { curl_close($ch); }
    wf_need(is_string($raw) && strlen($raw)>1000 && strlen($raw)<4000000 && $status===200,'waldorf_public_read_failed');
    return ['raw'=>$raw,'http_status'=>$status];
}
function wf_resume_original_receipts(array $state,array $ctx): void {
    $versions=['telegram:1500'=>14280,'max:1510'=>14281];
    wf_need(wf_resume_context($ctx)
        && ($state['complete']??null)===true && is_array($state['posts']??null) && count($state['posts'])===2
        && is_array($ctx['confirmed_receipts']??null) && count($ctx['confirmed_receipts'])===2,
        'waldorf_resume_original_receipt_missing');
    foreach ($versions as $key=>$version) {
        $expected=$ctx['confirmed_receipts'][$key]??null; $post=$state['posts'][$key]??null; $actual=$post['receipt']??null;
        wf_need(is_array($expected) && ($expected['version_id']??null)===$version && ($post['state']??null)==='confirmed'
            && is_array($actual) && count($actual)===count($expected),'waldorf_resume_original_receipt_changed');
        foreach ($expected as $field=>$value)
            wf_need(array_key_exists($field,$actual) && $actual[$field]===$value,'waldorf_resume_original_receipt_changed');
    }
}
function wf_receipt_preimages_unchanged(array $files,array $expected): void {
    wf_need(array_column($files,'name')===wf_names() && array_column($expected,'name')===wf_names(),'waldorf_pre_edit_feed_changed');
    foreach ($files as $i=>$f) wf_need($f['sha256']===$expected[$i]['sha256'] && $f['bytes']===$expected[$i]['bytes'],
        'waldorf_pre_edit_feed_changed');
}
$locks=[]; $out=['ok'=>false,'kind'=>'CONTENT66198_FEED_READ','filesystem_writes'=>0,'provider_calls'=>0,'database_writes'=>0];
try {
    wf_source(); $b=wf_binding(); $ctx=wf_context(); $mode=FEED66198_PHASE;
    $global=wf_monitor($ctx['journal_pin'],$locks);
    $out+=['operation'=>$b['caption_operation'],'feed_operation'=>$b['feed_operation'],'installed_sha'=>$b['installed_sha'],
        'approval_comment_id'=>7072600922,'journal_pin'=>$ctx['journal_pin'],'observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
    if ($mode==='capture') {
        $posts=array_values(wf_posts($ctx['posts'])); wf_registry($posts); $files=wf_capture_files(); $bindings=wf_resolve($posts,$files);
        $updates=[];
        foreach ($bindings as $u) { $p=array_values(array_filter($posts,static fn($p)=>$p['platform']===$u['platform']))[0]; $updates[]=$u+['old_text'=>$p['caption'],'text'=>wf_correct($p['caption'])]; }
        $changed=0;
        foreach ($files as $f) {
            $raw=base64_decode($f['content_base64'],true); $next=caption_feed_render($raw,$updates,$f['name']);
            $changed+=substr_count($next,'Десять ресторанов, баров и лаунжей')-substr_count($raw,'Десять ресторанов, баров и лаунжей');
        }
        $out+=['phase'=>'pre_edit_capture','posts'=>$posts,'files'=>$files,'caption_updates'=>$bindings,
            'native_read_sha256'=>$ctx['native_read_sha256'],'target_description_occurrences'=>4,'description_changes'=>$changed];
    } elseif ($mode==='receipts' || $mode==='resume-receipts') {
        $read=wf_locked_jsonl('/var/www/anytoour/data/channel-post-operations/'.$b['caption_operation'].'/receipt.jsonl',134217728,LOCK_SH,$locks);
        $op=['operation'=>$b['caption_operation'],'manifest_sha256'=>$ctx['manifest_sha256'],'installed_sha'=>$read['state']['installed_sha']??null,
            'journal_sha256'=>$read['sha256'],'row_count'=>2];
        if ($mode==='resume-receipts') wf_resume_original_receipts($read['state'],$ctx);
        $updates=wf_confirmed($read['state'],$op,$ctx['caption_updates'],$mode==='resume-receipts'); wf_registry($updates,true);
        $files=wf_capture_files();
        wf_receipt_preimages_unchanged($files,$ctx['files']);
        $safe=['operation'=>$b['caption_operation'],'manifest_sha256'=>$ctx['manifest_sha256'],'installed_sha'=>$read['state']['installed_sha'],
            'complete'=>true,'row_count'=>2,'posts'=>[]];
        $planFields=['hotel_id','platform','publication_id','version_id','message_id','post_url','old_text','new_text','old_text_sha256','new_text_sha256',
            'photo_signature','attachments_sha256','button_url_sha256','source_key','monitor_price_record_id'];
        $receiptFields=['hotel_id','platform','publication_id','previous_version_id','version_id','message_id','post_url','text_sha256','previous_text_sha256',
            'photo_signature','previous_attachments_sha256','attachments_sha256','button_url_sha256','button_text','source_key','state','monitor_price_record_id',
            'caption_correction_operation','body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version','same_caption_skipped'];
        foreach ($read['state']['posts'] as $key=>$p) $safe['posts'][$key]=['state'=>'confirmed',
            'plan'=>array_intersect_key($p['plan'],array_flip($planFields)),'receipt'=>array_intersect_key($p['receipt'],array_flip($receiptFields))];
        $out+=['phase'=>$mode==='resume-receipts'?'post_edit_resume_receipts':'post_edit_receipts',
            'capture_sha256'=>$ctx['capture_sha256'],'edit_result_sha256'=>$ctx['edit_result_sha256'],
            'caption_operations'=>[$op],'caption_states'=>[$b['caption_operation']=>$safe], 'pre_edit_feeds_unchanged'=>true];
        if ($mode==='resume-receipts') $out+=['continuation_kind'=>$ctx['continuation_kind'],
            'original_caption_installed_sha'=>$read['state']['installed_sha'],
            'original_caption_result_matched'=>true,'historical_capture_revalidated'=>true];
    } elseif ($mode==='readback') {
        $files=wf_capture_files(); $results=[];
        foreach ($files as $i=>$f) {
            $e=$ctx['files'][$i];
            wf_need($f['name']===$e['name'] && $f['sha256']===$e['sha256'] && $f['bytes']===$e['bytes'] && $f['rows']===$e['rows'],
                'waldorf_local_readback_changed');
            $url='https://app.anytoour.ru/feed/'.$f['name']; $public=wf_public($url);
            wf_need(hash('sha256',$public['raw'])===$e['sha256'],'waldorf_public_hash_changed');
            $results[]=['name'=>$f['name'],'local_sha256'=>$f['sha256'],'public_sha256'=>hash('sha256',$public['raw']),
                'bytes'=>strlen($public['raw']),'rows'=>count(wf_xml($public['raw'])['nodes']),'url'=>$url,'http_status'=>200];
        }
        $out+=['phase'=>'public_readback','files'=>$results,'input_sha256'=>$ctx['input_sha256'],'public_feed_gets'=>3,
            'target_description_occurrences'=>4,'all_other_bytes_preserved'=>true];
    } else throw new RuntimeException('waldorf_phase_invalid');
    $out['ok']=true; $out['locks_released_before_return']=true; $out['monitor_journal_unchanged']=true;
} catch (Throwable $e) { $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'waldorf_feed_capture_failed'; }
finally { foreach (array_reverse($locks) as $f) if (is_resource($f)) { flock($f,LOCK_UN); fclose($f); } }
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($out['ok']?0:2);
