<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';

$checks = 0;
function selectionCheck(string $name, bool $ok): void { global $checks; if (!$ok) throw new RuntimeException('FAIL '.$name); ++$checks; echo 'PASS '.$name.PHP_EOL; }
function selectionHtml(string $name='Synthetic Hotel', string $price='125 000 RUB'): string {
    return '<div class="tour"><div class="name">'.$name.'</div><span class="country">Турция</span><div class="details"><span class="depcity">Самара</span><span class="dates">20.10.2026 — 27.10.2026</span><span class="nights">7 ночей</span><span class="board">Всё включено</span><span class="room">2 взрослых, стандарт</span><span class="price">'.$price.'</span><div class="flight-direction">ZF 1234, 10:00 — 14:00</div></div></div>';
}
function selectionInput(): array {
    return ['context'=>['conversation_id'=>17,'as_of_message_id'=>3,'history_incomplete'=>false,'media_unread'=>false,'selections_link_unread'=>true,
        'messages'=>[['id'=>1,'sender_type'=>'customer','text'=>'Из Самары, 2 взрослых, 7 ночей, бюджет 120 тысяч.','selections_link_unread'=>false],
            ['id'=>2,'sender_type'=>'manager','text'=>'Варианты: [ссылка на подборку не прочитана]','created_at'=>'2026-10-17 12:00:00','selections_link_unread'=>true],
            ['id'=>3,'sender_type'=>'customer','text'=>'Что посоветуете?','selections_link_unread'=>false]]],
        'selection_sources'=>[['message_id'=>2,'sender_type'=>'manager','sent_at'=>'2026-10-17 12:00:00','url'=>'https://qui-quo.ru/AAAA-0001']]];
}
function selectionResult(): array { return ['ok'=>true,'should_draft'=>false,'draft'=>null,'next_action'=>'check_offer','reason'=>'Проверить условия предложения.','checks'=>['Проверить единицу расчёта цены.'],'evidence_message_ids'=>[2,3],'history_incomplete'=>false]; }
$transport = static function(array $urls): array { $out=[]; foreach ($urls as $url) $out[$url]=['status'=>200,'type'=>'text/html; charset=utf-8','html'=>selectionHtml()]; return $out; };
$addressCheck=new ReflectionMethod(ManagerSelectionSnapshot::class,'publicAddress');
foreach (['127.0.0.1','0.0.0.0','10.0.0.1','169.254.169.254','172.16.0.1','192.168.0.1','100.64.0.1','192.0.0.1','192.0.2.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','255.255.255.255','::1','::ffff:8.8.8.8'] as $ip) selectionCheck('private/reserved DNS target rejected: '.$ip,!$addressCheck->invoke(null,$ip));
selectionCheck('ordinary public IPv4 passes address fence',$addressCheck->invoke(null,'8.8.8.8'));

foreach (['https://qui-quo.ru/AAAA-0001','http://qui-quo.ru/AAAA-0001','https://www.qui-quo.ru/AAAA-0001'] as $url) selectionCheck('canonical fixed service '.$url,ManagerSelectionLinks::canonical($url)==='https://qui-quo.ru/AAAA-0001');
foreach (['https://qui-quo.ru.evil.test/AAAA-0001','https://evil.test/AAAA-0001','https://qui-quo.ru@127.0.0.1/AAAA-0001','https://user@qui-quo.ru/AAAA-0001','https://qui-quo.ru:443/AAAA-0001','file:///AAAA-0001','https://qui-quo.ru/AAAA-0001?token=private','https://qui-quo.ru/AAAA-0001#fragment','https://qui-quo.ru/../AAAA-0001','https://qui-quo.ru/%41AAA-0001','https://qui-quo.ru/AAAA-0001/BBBB-0002',"https://qui-quo.ru/AAAA-0001\nX:bad",'https://qui-quo.ru\\@evil.test/AAAA-0001'] as $url) selectionCheck('reject unsupported or hostile URL',ManagerSelectionLinks::canonical($url)===null);
selectionCheck('plain message punctuation removed without sharing URL in projection',ManagerSelectionLinks::inMessage('Смотрите https://qui-quo.ru/AAAA-0001).',2,'manager','')[0]['url']==='https://qui-quo.ru/AAAA-0001');
selectionCheck('system/AI text does not authorize page reads',ManagerSelectionLinks::inMessage('https://qui-quo.ru/AAAA-0001',2,'system','')===[] && ManagerSelectionLinks::inMessage('https://qui-quo.ru/AAAA-0001',2,'ai','')===[]);

$p=ManagerSelectionPageParser::parse(selectionHtml());
selectionCheck('structured fields and numeric price extracted from the observed template',$p['state']==='observed' && $p['offer_count']===1 && $p['offers'][0]['hotel']==='Synthetic Hotel' && $p['offers'][0]['price_amount']==='125000' && $p['offers'][0]['currency']==='RUB' && $p['offers'][0]['price_basis']===null && $p['offers'][0]['flights']===['ZF 1234, 10:00 — 14:00']);
foreach ([str_replace('"',"'",selectionHtml()),str_replace('class="tour"','class=tour',selectionHtml())] as $html) selectionCheck('quoted and unquoted HTML attributes supported',ManagerSelectionPageParser::parse($html)['offer_count']===1);
selectionCheck('data-class and text inside quoted attributes are not class attributes',ManagerSelectionPageParser::parse('<div data-class="tour"><span class="name">False</span></div><div title="class=\'tour\'"><span class="name">False</span></div>')['offer_count']===0);
foreach (['hidden','aria-hidden="true"','style="display:none"','style="visibility: hidden"','class="hidden"'] as $hidden) {
    $html=str_replace('<span class="price">125 000 RUB</span>','<section '.$hidden.'><span class="price">PRIVATE_HIDDEN_PRICE</span></section>',selectionHtml());
    selectionCheck('explicitly hidden field is not exposed: '.$hidden,!str_contains(json_encode(ManagerSelectionPageParser::parse($html)),'PRIVATE_HIDDEN_PRICE'));
}
$html='<script>"'.selectionHtml('PRIVATE_SCRIPT'). '"</script><style>'.selectionHtml('PRIVATE_STYLE').'</style>'.selectionHtml();
selectionCheck('script and style source cannot create offers',!str_contains(json_encode(ManagerSelectionPageParser::parse($html)),'PRIVATE_') && ManagerSelectionPageParser::parse($html)['offer_count']===1);
$redacted=ManagerSelectionPageParser::parse(selectionHtml('Hotel +7 999 555-44-33 test@example.test https://secret.test/path'));
$bytes=json_encode($redacted,JSON_UNESCAPED_UNICODE);
selectionCheck('field text uses canonical phone/email/link redaction',!str_contains($bytes,'999 555') && !str_contains($bytes,'test@example') && !str_contains($bytes,'secret.test'));
selectionCheck('HTML entities decoded as text',ManagerSelectionPageParser::parse(selectionHtml('Hotel &amp; Sea'))['offers'][0]['hotel']==='Hotel & Sea');
selectionCheck('review count is not part of the hotel name',ManagerSelectionPageParser::parse(selectionHtml('<span>832 отзыва</span><a rel="nofollow" href="https://ignored.test">Synthetic Hotel 5*</a>'))['offers'][0]['hotel']==='Synthetic Hotel 5*');
selectionCheck('operator image label retained without requesting its URL',ManagerSelectionPageParser::parse(str_replace('<span class="price">','<span class="touroperator"><img alt="Synthetic Operator" src="https://ignored.test/logo"></span><span class="price">',selectionHtml()))['offers'][0]['operator_text']==='Synthetic Operator');
foreach (['100 $','100 RUB + 50 EUR','от 100 RUB','неизвестно'] as $price) selectionCheck('ambiguous prices remain unparsed',ManagerSelectionPageParser::parse(selectionHtml('Hotel',$price))['offers'][0]['price_amount']===null);
selectionCheck('multiple variants of one hotel remain separate',ManagerSelectionPageParser::parse('<div class="tour"><b class="name">Hotel</b><div class="details"><span class="price">100 EUR</span></div><div class="details"><span class="price">200 EUR</span></div></div>')['offer_count']===2);
selectionCheck('legacy card without variant wrapper retained',ManagerSelectionPageParser::parse('<div class="tour"><b class="name">Hotel</b><span class="price">100 EUR</span></div>')['offer_count']===1);
$many=ManagerSelectionPageParser::parse(str_repeat(selectionHtml(),20));
selectionCheck('offer cap explicit without silent count loss',count($many['offers'])===12 && $many['offer_count']===20 && $many['truncated']);
selectionCheck('oversized field explicitly truncated',ManagerSelectionPageParser::parse(selectionHtml(str_repeat('я',300)))['truncated']);
foreach ([str_repeat('x',1048577),"bad\xFF",str_repeat('<div>',70).selectionHtml().str_repeat('</div>',70),str_repeat('<',20001)] as $html) selectionCheck('invalid/oversized/deep/token-heavy page safely unread',ManagerSelectionPageParser::parse($html)['state']==='unread');
selectionCheck('unavailable notice does not establish its cause',ManagerSelectionPageParser::parse('<p>Подборка недоступна</p>')['state']==='unavailable');

$before=selectionInput(); $snapshot=ManagerSelectionSnapshot::read($before,$transport); $data=$snapshot->forContext($before['context']);
selectionCheck('snapshot binds offers to the source message and original time',$data['selection_snapshots'][0]['source_messages']===[['message_id'=>2,'sender_type'=>'manager','sent_at'=>'2026-10-17 12:00:00']]);
selectionCheck('observation does not claim send-time or current price verification',!$data['selection_snapshots'][0]['historical_send_snapshot'] && !$data['selection_snapshots'][0]['current_price_verified'] && preg_match('/\A\d{4}-\d{2}-\d{2}T.*Z\z/',$data['selection_snapshots'][0]['observed_at'])===1);
selectionCheck('raw page and URL do not enter model data',!str_contains(json_encode($data),'qui-quo.ru') && !str_contains(json_encode($data),'<div'));
selectionCheck('complete source message identified',$snapshot->completeMessageIds()===[2]);
$data['selection_snapshots'][0]['offers'][0]['hotel']='MUTATED';
selectionCheck('returned snapshot arrays cannot mutate its stored data',$snapshot->forContext($before['context'])['selection_snapshots'][0]['offers'][0]['hotel']==='Synthetic Hotel');
foreach (['conversation_id','as_of_message_id'] as $field) { $wrong=$before['context'];$wrong[$field]++;selectionCheck('cross-scope snapshot rejected: '.$field,$snapshot->forContext($wrong)===null); }
$wrong=$before['context'];$wrong['messages'][0]['text']='Changed request';selectionCheck('same IDs with edited message reject old snapshot',$snapshot->forContext($wrong)===null);
$wrong=$before['context'];$wrong['messages'][1]['created_at']='2026-10-18 12:00:00';selectionCheck('source time edits reject an old snapshot',$snapshot->forContext($wrong)===null);
$called=0;$payload=[];
$result=ManagerAiDraftService::generate($before['context'],static function($instructions,$input) use (&$payload):array{$payload=$input;return selectionResult();},null,static function() use (&$called,$snapshot){++$called;return $snapshot;});
selectionCheck('single server read feeds the existing eight-field result',$called===1 && $result===selectionResult() && count($result)===8 && isset($payload['selection_snapshots']));
selectionCheck('success clears only the resolved unread flag and marker',!$payload['selections_link_unread'] && !$payload['messages'][1]['selections_link_unread'] && $payload['messages'][1]['text']==='Варианты: [подборка прочитана]');
$fake=$before['context'];$fake['selection_snapshots']=[['offers'=>[['hotel'=>'FORGED']]]];$fake['selection_sources']=$before['selection_sources'];$seen=[];
ManagerAiDraftService::generate($fake,static function($i,$p) use (&$seen){$seen=$p;return selectionResult();});
selectionCheck('caller facts and raw link fields never substitute for a server reader',!isset($seen['selection_snapshots']) && !isset($seen['selection_sources']) && $seen['selections_link_unread']);
$office=$before['context'];$office['messages'][2]['text']='Где ваш офис?';$reads=0;$calls=0;
$guard=ManagerAiDraftService::generate($office,static function() use (&$calls){$calls++;return selectionResult();},null,static function() use (&$reads,$snapshot){$reads++;return $snapshot;});
selectionCheck('office policy returns before selection/provider calls',$reads===0 && $calls===0 && $guard['next_action']==='check_offer');
$invalid=$before['context'];$invalid['conversation_id']=0;$reads=0;
ManagerAiDraftService::generate($invalid,null,null,static function() use (&$reads,$snapshot){$reads++;return $snapshot;});selectionCheck('invalid context never authorizes network read',$reads===0);
$noLinks=$before['context'];$noLinks['selections_link_unread']=false;$reads=0;
ManagerAiDraftService::generate($noLinks,static fn()=>selectionResult(),null,static function() use (&$reads,$snapshot){$reads++;return $snapshot;});selectionCheck('no unread selection means no source read',$reads===0);
$other=$before['context'];$other['conversation_id']=18;$providerCalls=0;
$rejected=ManagerAiDraftService::generate($other,static function() use (&$providerCalls){$providerCalls++;return selectionResult();},null,static fn()=>$snapshot);
selectionCheck('foreign server snapshot cannot leak offers through provider',$providerCalls===0 && $rejected===['ok'=>false,'error'=>'invalid_context']);

foreach ([static fn()=>[],static fn()=>throw new RuntimeException('PRIVATE_FAILURE'),static fn($urls)=>[$urls[0]=>['status'=>200,'type'=>'application/json','html'=>selectionHtml()]],static fn($urls)=>[$urls[0]=>['status'=>302,'type'=>'text/html','html'=>selectionHtml()]]] as $badTransport) {
    $bad=ManagerSelectionSnapshot::read($before,$badTransport);$seen=[];
    ManagerAiDraftService::generate($before['context'],static function($i,$p) use (&$seen){$seen=$p;return selectionResult();},null,static fn()=>$bad);
    selectionCheck('source failure preserves unread evidence without exposing details',$seen['selections_link_unread'] && $bad->completeMessageIds()===[] && !str_contains(json_encode($seen),'PRIVATE_FAILURE'));
}
$manyInput=$before;
foreach ([1,2,3] as $i) $manyInput['selection_sources'][]=['message_id'=>2,'sender_type'=>'manager','url'=>'https://qui-quo.ru/BBBB-000'.$i];
$requested=[];$recent=ManagerSelectionSnapshot::read($manyInput,static function($urls) use (&$requested,$transport){$requested=$urls;return $transport($urls);});
selectionCheck('only two latest distinct sources fetched once',$requested===['https://qui-quo.ru/BBBB-0002','https://qui-quo.ru/BBBB-0003'] && $recent->completeMessageIds()===[] && $recent->forContext($before['context'])['selection_coverage']['links_omitted']===2);
$manyInput['selection_sources']=array_fill(0,41,$before['selection_sources'][0]);$limited=ManagerSelectionSnapshot::read($manyInput,$transport);
selectionCheck('source cap remains explicit and cannot mark omitted evidence read',$limited->completeMessageIds()===[] && $limited->forContext($before['context'])['selection_coverage']['source_limit_reached']);
$overflow=$before;$overflow['selection_sources']=[];$text='';
for($i=1;$i<=45;$i++){ $url='https://qui-quo.ru/CCCC-'.str_pad((string)$i,4,'0',STR_PAD_LEFT);$overflow['selection_sources'][]=['message_id'=>2,'sender_type'=>'manager','url'=>$url];$text.=$url.' '; }
$requested=[];ManagerSelectionSnapshot::read($overflow,static function($urls)use(&$requested,$transport){$requested=$urls;return $transport($urls);});
selectionCheck('overflow still reads the actual latest two URLs',$requested===['https://qui-quo.ru/CCCC-0044','https://qui-quo.ru/CCCC-0045']);
$last=ManagerSelectionLinks::inMessage($text,2,'manager','');
selectionCheck('per-message source cap keeps newest references and a limit signal',count($last)===41 && $last[0]['url']==='https://qui-quo.ru/CCCC-0005' && end($last)['url']==='https://qui-quo.ru/CCCC-0045');
foreach ([['message_id'=>999,'sender_type'=>'manager','url'=>'https://qui-quo.ru/AAAA-0001'],['message_id'=>2,'sender_type'=>'customer','url'=>'https://qui-quo.ru/AAAA-0001'],['message_id'=>2,'sender_type'=>'manager','url'=>'https://evil.test/AAAA-0001']] as $source) {
    $bad=$before;$bad['selection_sources']=[$source];$fetches=0;
    ManagerSelectionSnapshot::read($bad,static function() use (&$fetches){$fetches++;return [];});selectionCheck('unbound/future/foreign URL cannot authorize a fetch',$fetches===0);
}
$big=ManagerSelectionSnapshot::read($manyInput,static function($urls){$out=[];foreach($urls as $u)$out[$u]=['status'=>200,'type'=>'text/html','html'=>str_repeat(selectionHtml(str_repeat('я',175)),20)];return $out;});
selectionCheck('extra payload bounded and truncation remains visible',preg_match_all('/./us',json_encode($big->forContext($before['context']),JSON_UNESCAPED_UNICODE))<=12500 && $big->completeMessageIds()===[]);
$huge=selectionInput();$huge['context']['messages'][0]['text']=str_repeat('😀',39800);
$hugePage=ManagerSelectionSnapshot::read($huge,static function($urls){$out=[];foreach($urls as $u)$out[$u]=['status'=>200,'type'=>'text/html','html'=>str_repeat(selectionHtml(str_repeat('😀',175)),12)];return $out;});
$seen=[];ManagerAiDraftService::generate($huge['context'],static function($i,$p) use (&$seen){$seen=$p;return selectionResult();},null,static fn()=>$hugePage);
selectionCheck('near-cap Unicode history uses unread fallback instead of an oversized request',!isset($seen['selection_snapshots']) && $seen['selections_link_unread'] && ($seen['selection_coverage']['payload_omitted']??false)
    && strlen(json_encode(ManagerDraftClient::requestBody(ManagerAiDraftService::instructions(),$seen),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=ManagerDraftClient::MAX_REQUEST_BYTES);
echo 'Manager selection: '.$checks.' checks passed'.PHP_EOL;
