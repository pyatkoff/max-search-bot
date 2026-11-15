<?php
declare(strict_types=1);
// Execute only pure helpers extracted from the real editor, never its production body.
$source=file_get_contents(__DIR__.'/update-commercial-price-20261104-remaining1.php');
if(!is_string($source))throw new RuntimeException('editor source missing');
$start=strpos($source,'// BEGIN MONITOR_PRICE_LINK_V1:');$end=strpos($source,'// END MONITOR_PRICE_LINK_V1.');
if($start===false||$end===false||$end<=$start)throw new RuntimeException('price-link implementation missing');
function need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
eval(substr($source,$start,$end-$start));
$checks=0;
function pl_check(bool $ok,string $why):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function pl_refuse(callable $fn,string $why):void{$rejected=false;try{$fn();}catch(RuntimeException $e){$rejected=true;}pl_check($rejected,$why);}
$urls=['max'=>'https://max.ru/id9704048781_1_bot?start=p_fixture_123','telegram'=>'https://t.me/anyru_bot?start=p_fixture_123'];
$make=static fn(string $amount):string=>"✈️ Туры в A&B <Resort> 🌴\n📍 Хургада, Египет.\n\n💰 От $amount ₽ за двоих\n✈️ Вылет из Москвы 27.11.2026 · 7 ночей\n👥 2 взрослых\n🛏 Standard <script>x</script> & Sea\n🍽 Всё включено\n\nЦена найдена мониторингом 15.11.2026, 13:00 МСК. Стоимость и наличие могут измениться.\n\n👇 Узнать стоимость тура";
foreach(['1','999','1 234','100 000','1 000 000','123,4','77 819,25'] as $amount){
    $text=$make($amount);
    foreach($urls as $platform=>$url){
        $link=monitorEditorPriceLink($text,$platform,$url);
        pl_check($link['url']===$url&&$link['label']==="От $amount ₽",'exact amount and source-post URL');
        $encoded=iconv('UTF-8','UTF-16LE',$text);
        $slice=iconv('UTF-16LE','UTF-8',substr($encoded,$link['offset']*2,$link['length']*2));
        pl_check($slice===$link['label'],'UTF16 offset after emoji');
        pl_check(html_entity_decode(strip_tags($link['max_html']),ENT_QUOTES|ENT_HTML5,'UTF-8')===$text,'rendering leaves every text byte intact');
        pl_check(substr_count($link['max_html'],'<a href=')===1&&substr_count($link['max_html'],'<b>')===1&&!str_contains($link['max_html'],'<script>'),'one escaped anchor, retained MAX header');
        $e=$platform==='max'?['type'=>'link','from'=>$link['offset'],'length'=>$link['length'],'url'=>$url]:$link['telegram_entities'][0];
        $message=$platform==='max'?['body'=>['text'=>$text,'markup'=>[$e]]]:['caption'=>$text,'caption_entities'=>[$e]];
        pl_check(monitorEditorPriceLinkVerified($platform,$message,$text,$link),'exact native hyperlink proof');
        foreach(['url','offset','length','missing','duplicate','text','overlap'] as $fault){
            $bad=$message;$ents=[$e];
            if($fault==='url')$ents[0]['url']=$url.'x';
            if($fault==='offset')$ents[0][$platform==='max'?'from':'offset']++;
            if($fault==='length')$ents[0]['length']--;
            if($fault==='missing')$ents=[];
            if($fault==='duplicate')$ents[]=$e;
            if($fault==='overlap'){$extra=$e;$extra['url']='https://invalid.example/';$extra['length']--;$ents[]=$extra;}
            if($platform==='max'){$bad['body']['markup']=$ents;if($fault==='text')$bad['body']['text'].=' ';}
            else{$bad['caption_entities']=$ents;if($fault==='text')$bad['caption'].=' ';}
            pl_check(!monitorEditorPriceLinkVerified($platform,$bad,$text,$link),'reject native mismatch '.$fault);
        }
    }
}
$text=$make('77 819');
foreach(['https://evil.example/?start=p_fixture_123','javascript:alert(1)','http://t.me/anyru_bot?start=p_fixture_123',
 'https://t.me/anyru_bot?start=p_fixture_123#other','https://t.me/anyru_bot?start=p_fixture_123&x=1',
 'https://t.me/anyru_bot?start=p_short','https://t.me/anyru_bot?start=fixture_123','https://t.me/another_bot?start=p_fixture_123',
 'https://max.ru/id9704048781_1_bot?start=p_fixture_123','https://t.me/anyru_bot?start=p_fixture_123%22'] as $bad)
 pl_refuse(static fn()=>monitorEditorPriceLink($text,'telegram',$bad),'reject unbound/noncanonical destination');
foreach(["✈️ Туры в A\nЦена по запросу",$text."\n💰 От 50 000 ₽ за двоих",str_replace('77 819','-77 819',$text),str_replace('77 819','77 819,999',$text),"\xFF".$text] as $bad)
 pl_refuse(static fn()=>monitorEditorPriceLink($bad,'telegram',$urls['telegram']),'no invented price or malformed text');
pl_refuse(static fn()=>monitorEditorPriceLink($text,'website',$urls['telegram']),'unsupported platform');
// Guard the actual editor wiring, including readback before a successful receipt.
foreach(["\$body['caption_entities']=json_encode(\$plan['price_link']['telegram_entities']", "if(\$monitorMode)\$html=\$plan['price_link']['max_html'];", "'telegram_price_link_not_verified_no_retry'", "'max_price_link_not_verified_no_retry'", "'max_final_price_link_not_verified_no_retry'", "'monitor_price_link_target_changed'", "\$plans[\$key]['price_link']=monitorEditorPriceLink(\$r['new_text'],\$platform,ChannelPostButtonTransport::buttonUrl(\$p));", "\$plans[\$key]['update_text']=true;"] as $guard)
 pl_check(str_contains($source,$guard),'editor wires '.$guard);
pl_check(strpos($source,"'max_final_price_link_not_verified_no_retry'")<strpos($source,"\$receipt['price_link_verified']=true"),'native proof before successful receipt');
pl_check(str_contains($source,"if(isset(\$state['posts'][\$key])){\$out['posts'][]=\$state['posts'][\$key]['receipt'];continue;}"),'completed operation remains skipped');
pl_check(substr_count($source,'MaxTransport::request(')===1&&substr_count($source,'$response=tg(')===1,'no extra provider write path');
echo 'MONITOR_PRICE_LINK_OK checks='.$checks.' supplier_calls=0 provider_calls=0 production_writes=0',PHP_EOL;
