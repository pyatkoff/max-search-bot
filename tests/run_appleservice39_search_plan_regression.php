<?php
declare(strict_types=1);
require_once __DIR__.'/../services/AppleService39PilotManifest.php';
$passed = 0;
function searchPlanCheck(string $name, bool $ok): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++; echo 'PASS '.$name.PHP_EOL;
}
function searchPlanReject(string $name, callable $call, string $reason): void {
    try { $call(); } catch (RuntimeException $error) {
        searchPlanCheck($name, $error->getMessage() === $reason); return;
    }
    searchPlanCheck($name, false);
}
searchPlanCheck('bounded successor compiler exists', class_exists('AppleService39SearchIntentRevision'));
$original = AppleService39PilotManifest::installed();
$revision = new AppleService39SearchIntentRevision($original);
$root = dirname(__DIR__).'/resources/appleservice39/search-intent-v1';
$before = [];
foreach (['pilot/manifest.json','landing-v2/manifest.json','catalogue-v1/manifest.json','catalogue-v1/catalog.json'] as $file) {
    $before[$file] = hash_file('sha256', dirname(__DIR__).'/resources/appleservice39/'.$file);
}
searchPlanCheck('exact eight pages without expanding the old twenty-four', count($revision->posts()) === 8 && count($original->posts()) === 24);
searchPlanCheck('new graph preserves old links and totals99', $revision->totalLinks() === 99);
searchPlanCheck('pin stays the same approved original menu MID', $revision->expectedPinMid() === $revision->historicalBinding('menu')['message_id']);
$posts = array_column($revision->posts(), null, 'id');
searchPlanCheck('exact2updates and6creates', count(array_filter($posts,static fn(array $p):bool=>$p['action']==='update_existing')) === 2
    && count(array_filter($posts,static fn(array $p):bool=>$p['action']==='create_new')) === 6);
$addresses = [];
foreach ($original->posts() as $old) $addresses[$old['id']] = $revision->historicalBinding($old['id'])['url'];
// These6 fixture strings are NOT provider receipts and never become accepted post URLs.
$i = 0;
foreach ($posts as $id=>$post) if ($post['action'] === 'create_new') $addresses[$id] = 'https://max.ru/channel_appleservice39ru/FixtureOnlySaved'.(++$i);
$uploadFor = static fn(array $post):array=>['token'=>'synthetic_fixture_upload_not_real','photo_id'=>'123456789','source_sha256'=>$post['image']['sha256']];
foreach ($revision->posts() as $post) {
    $id = $post['id']; $plan = $revision->plan($id,$uploadFor($post),$addresses); $body = $plan['body'];
    searchPlanCheck('compiled exact searched title and paragraphs '.$id,
        str_starts_with($plan['native_text'],$post['title']."\n\n")
        && str_contains($plan['native_text'],$post['paragraphs'][0]) && $body['format'] === 'html');
    searchPlanCheck('exact one native bot button and one image '.$id, count($body['attachments']) === 2
        && $body['attachments'][0]['type'] === 'image'
        && $body['attachments'][1]['type'] === 'inline_keyboard'
        && $body['attachments'][1]['payload']['buttons'] === [[[
            'type'=>'link','text'=>'Написать менеджеру',
            'url'=>'https://max.ru/id390521129062_bot?start=as39_'.$id,
        ]]]);
    $links = array_values(array_filter($plan['native_markup'],static fn(array $m):bool=>$m['type']==='link'));
    searchPlanCheck('navigation remains text links '.$id,count($links) === count($post['links']) && count($plan['native_markup']) === 1+count($links));
    foreach ($links as $n=>$markup) {
        searchPlanCheck('exact native link text and address '.$id.'/'.$n,
            mb_substr($plan['native_text'],$markup['from'],$markup['length'],'UTF-8') === $post['links'][$n]['label']
            && $markup['url'] === $addresses[$post['links'][$n]['id']]);
    }
    searchPlanCheck('compiler never claims native success or permits pin '.$id,
        $plan['state'] === 'compiled_unpublished' && $plan['native_verified'] === false
        && $plan['operation_claim_created'] === false && $plan['pin_write_permitted'] === false);
    searchPlanCheck('new8covers use exact approved source bytes '.$id,
        hash_file('sha256',$revision->validateArtwork($id)) === $post['image']['sha256']);
}
$base = $addresses;
foreach ($posts as $id=>$post) if ($post['action'] === 'create_new') unset($base[$id]);
$rendered = [];
foreach ($revision->actionOrder() as $id) {
    $body = $revision->plan($id,$uploadFor($posts[$id]),$base);
    $rendered[]=$id;
    if ($posts[$id]['action']==='create_new') $base[$id]=$addresses[$id];
}
searchPlanCheck('dependency order needs no placeholder posts or second planned edits', count($rendered) === 8 && count($base) === 30);
$missing = $addresses; unset($missing['repair-iphone-16-display']);
searchPlanReject('uncreated destination blocks existing display update',static fn()=>$revision->plan('service-display',$uploadFor($posts['service-display']),$missing),'search_link_address_missing');
$bad = $addresses; $bad['menu']='https://evil.example/receipt';
searchPlanReject('foreign URL cannot enter the body',static fn()=>$revision->plan('menu',$uploadFor($posts['menu']),$bad),'search_address_invalid');
$bad = $addresses; $bad['menu']='https://max.ru/channel_appleservice39ru/DifferentOriginal';
searchPlanReject('an original URL cannot be replaced by a plausible new URL',static fn()=>$revision->plan('menu',$uploadFor($posts['menu']),$bad),'search_original_url_changed');
$bad = $addresses; $bad['service-rear-camera-replacement']=$bad['service-rear-glass-replacement'];
searchPlanReject('two destinations cannot share a provider address',static fn()=>$revision->plan('menu',$uploadFor($posts['menu']),$bad),'search_address_invalid');
$bad = $addresses; $bad['foreign-service']='https://max.ru/channel_appleservice39ru/FixtureForeign';
searchPlanReject('unreviewed post key is excluded',static fn()=>$revision->plan('menu',$uploadFor($posts['menu']),$bad),'search_address_invalid');
$bad = $addresses; unset($bad['repair-iphone-x-display']);
searchPlanReject('missing old binding blocks compilation',static fn()=>$revision->plan('menu',$uploadFor($posts['menu']),$bad),'search_original_url_missing');
$badUpload = $uploadFor($posts['menu']);$badUpload['source_sha256']=str_repeat('0',64);
searchPlanReject('upload for a different image cannot be substituted',static fn()=>$revision->plan('menu',$badUpload,$addresses),'search_own_upload_required');
$badUpload = $uploadFor($posts['menu']);$badUpload['token']='';
searchPlanReject('missing token blocks body compilation',static fn()=>$revision->plan('menu',$badUpload,$addresses),'search_own_upload_required');
$badUpload = $uploadFor($posts['menu']);$badUpload['photo_id']='-1';
searchPlanReject('invalid photo ID blocks body compilation',static fn()=>$revision->plan('menu',$badUpload,$addresses),'search_own_upload_required');
$zeroUpload = $uploadFor($posts['menu']);$zeroUpload['photo_id']='0';
searchPlanCheck('zero upload index is accepted without asserting native image identity',
    $revision->plan('menu',$zeroUpload,$addresses)['native_verified'] === false);
$badUpload = $uploadFor($posts['menu']);$badUpload['photo_id']='987654321';
searchPlanCheck('arbitrary plausible new photoID remains unverified', $revision->plan('menu',$badUpload,$addresses)['native_verified'] === false);
searchPlanReject('body outside finite8pages is rejected',static fn()=>$revision->plan('repair-iphone-17-ultra-display',$uploadFor($posts['menu']),$addresses),'search_post_outside_revision');
$tmp=sys_get_temp_dir().'/search-plan-'.bin2hex(random_bytes(5));mkdir($tmp.'/covers',0700,true);
copy($root.'/manifest.json',$tmp.'/manifest.json');
foreach ($posts as $post) copy($root.'/'.$post['image']['path'],$tmp.'/'.$post['image']['path']);
$raw = file_get_contents($tmp.'/manifest.json');
file_put_contents($tmp.'/manifest.json',str_replace('"warranty_months": 3','"warranty_months": 12',$raw));
searchPlanReject('source checksum is fixed by code rather than supplied by caller',static fn()=>new AppleService39SearchIntentRevision($original,$tmp.'/manifest.json'),'search_source_changed');
file_put_contents($tmp.'/manifest.json',$raw);
$cover=$tmp.'/covers/menu.png';$bytes=file_get_contents($cover);
file_put_contents($cover,$bytes.'corruption');
searchPlanReject('even a recognizable PNG with appended bytes is rejected',static fn()=>new AppleService39SearchIntentRevision($original,$tmp.'/manifest.json'),'search_source_image_changed');
file_put_contents($cover,$bytes);
searchPlanCheck('exact copied source and eight exact images remain usable',(new AppleService39SearchIntentRevision($original,$tmp.'/manifest.json'))->sha256()===$revision->sha256());
foreach ($before as $file=>$sha) {
    searchPlanCheck('historic resource bytes remain intact '.$file, hash_file('sha256',dirname(__DIR__).'/resources/appleservice39/'.$file)===$sha);
}
echo 'TOTAL '.$passed.' passed'.PHP_EOL;
