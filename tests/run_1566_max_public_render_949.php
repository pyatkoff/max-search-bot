<?php
declare(strict_types=1);
$src=dirname(__DIR__).'/tools/direct_demand/operations/max-existing-photo';
require $src.'/editor.php';
require $src.'/public_render.php';
require dirname(__DIR__).'/integrations/ChannelPostIncomingAdapter.php';
$proof=json_decode(file_get_contents($src.'/public-render-949.json'),true);
$rows=json_decode(file_get_contents($src.'/scope.json'),true)['rows'];
$row=array_column($rows,null,'hotel_id')[949];$p=$proof['post'];$text=$row['caption_without_price'];
$ok=static function(bool $test,string $why): void {if(!$test)throw new LogicException($why);};
$body=maxRenderPayload($text,$p['caption_sha256']);
$ok(array_keys($body)===['text','format'] && $body['format']==='html' && $body['text']===$text,'same official text-only body without attachments');
$ok($body['text']!==htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') && !str_contains($body['text'],'&amp;'),'different wire representation');
$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="UTF-8"><body><p>'.$body['text'].'</p>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
$ok($dom->getElementsByTagName('p')->item(0)->textContent===$text,'literal ampersand separated by whitespace retains canonical name');
foreach ([$text.' <b>more</b>',$text.' https://example.com',$text.' &more;'] as $bad) {
    try {maxRenderPayload($bad,hash('sha256',$bad));throw new LogicException('unexpected formatting accepted');} catch (RuntimeException $expected) {}
}
$url='https://i.oneme.ru/fixture'; $fixture=$proof;$fixture['public_photo_sha256']=hash('sha256',$url);
$build=static fn(string $name): string=>'<div class="card"><div class="footnote">@anytour</div><div class="description"><img class="attachmentThumb" src="'.$url.'"> '.htmlspecialchars(str_replace('Adam & Eve',$name,$text),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div></div>';
$before=maxRenderPublic($build('Adam &amp; Eve'),$fixture);$after=maxRenderPublic($build('Adam & Eve'),$fixture);
$ok(hash('sha256',$before)===$proof['public_caption_before_sha256'] && $after===$text && hash('sha256',$after)===$p['caption_sha256'],'one DOM parse distinguishes both actual strings');
try {maxRenderPublic(str_replace($url,$url.'x',$build('Adam & Eve')),$fixture);throw new LogicException('changed photo accepted');} catch(RuntimeException $expected) {}
$ok($p['hotel']===949 && $p['publication_id']===3743 && $p['version_id']===14319 && maxEditUrl($row['target_mid'],MAIN_MAX)===$p['target']
    && strlen($p['button_url_sha256'])===64 && strlen($p['button_label_sha256'])===64,'current target identity and exact original button');
$button='https://max.ru/fixture?start=abc';
$label='Fixture';
$photo=['type'=>'image','provider'=>'max','photo_id'=>'349'];
$p['photo_sha256']=hash('sha256',$photo['photo_id']);
$entry=['snapshot'=>['attachments'=>[$photo]]];
$snap=['text'=>$text,'post_url'=>$p['target'],'attachments'=>[$photo],'markup'=>[],'keyboard'=>[[['type'=>'link','text'=>$label,'url'=>$button]]]];
$seen=['snapshot'=>$snap,'message'=>['timestamp'=>$p['target_ms']]];
$resolved=['publication_id'=>3743,'version_id'=>14319,'snapshot'=>['post_url'=>$p['target'],'metadata'=>['hotel_catalog_id'=>949]]];
maxRenderVerifyRepresentation($row,$p,$text,$entry,$seen,$resolved,$button,$label);
foreach (['photo','second_button','foreign_hotel','other_url','new_version'] as $case) {
    $bad=$seen;$ctx=$resolved;
    if ($case==='photo') $bad['snapshot']['attachments'][0]['photo_id']='350';
    if ($case==='second_button') $bad['snapshot']['keyboard'][]=$snap['keyboard'][0];
    if ($case==='foreign_hotel') $ctx['snapshot']['metadata']['hotel_catalog_id']=800;
    if ($case==='other_url') $ctx['snapshot']['post_url']=$row['source_url'];
    if ($case==='new_version') $ctx['version_id']++;
    try {maxRenderVerifyRepresentation($row,$p,$text,$entry,$bad,$ctx,$button,$label);throw new LogicException($case.' accepted');} catch (RuntimeException $expected) {}
}
echo "TV949 exact text, single public entity layer, original identity and omitted attachments passed\n";
