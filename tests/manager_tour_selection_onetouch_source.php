<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/onetouch-public.php';
require_once __DIR__.'/../services/ManagerTourSelectionOneTouchSource.php';
require_once __DIR__.'/../services/ManagerTourSelectionImportDraft.php';
require_once __DIR__.'/../services/ManagerTourSelectionPublicationDraft.php';
$passed=0;
function onetouchCheck(bool $v,string $name):void{global $passed;if(!$v)throw new RuntimeException('FAIL '.$name);$passed++;}
function onetouchReject(callable $f,string $name):void{try{$f();}catch(RuntimeException $e){onetouchCheck(true,$name);return;}onetouchCheck(false,$name);}
$f=syntheticOneTouchSource();$quotes=ManagerTourSelectionOneTouchSource::parse($f['response'],$f['url'],$f['form'],'2077-01-01T00:00:00Z');
onetouchCheck(count($quotes)===1,'one bound synthetic source quote');$q=$quotes[0];
onetouchCheck($q['offer']['room']==='STANDARD / DBL'&&$q['offer']['meal']==='BB'&&$q['offer']['date_from']==='2077-01-07','exact displayed room meal and date');
onetouchCheck($q['offer']['price_qualifier']==='from'&&$q['offer']['amount']==='123456.78'&&$q['raw']['base_currency']==='EUR','converted and base currencies remain distinct');
onetouchCheck(!str_contains(json_encode($q),'AGENT ONLY'),'agent tooltip not customer terms');
onetouchCheck(str_contains($q['raw']['availability'],'по запросу'),'native on-request availability preserved without confirmation claim');
$nested=ManagerTourSelectionOneTouchSource::parse(str_replace('(Synthetic Resort)','(Synthetic Resort (Synthetic City))',$f['response']),$f['url'],$f['form'],'2077-01-01T00:00:00Z');
onetouchCheck($nested[0]['offer']['destination']==='Synthetic Country · Synthetic Resort (Synthetic City)','inner city parentheses retained');
$q['photos']=ManagerTourSelectionOneTouchSource::gallery($f['hotel'],$q);onetouchCheck($q['photos']===[$f['photo']],'one native photo deduplicated');
$import=ManagerTourSelectionImportDraft::normalize($q);onetouchCheck($import!==null,'canonical source normalization');$offer=$import['offer'];$offer['id']=str_repeat('a',32);$offer['import_source']=ManagerTourSelectionImportDraft::source($import);$offer['amount']='130000';
$draft=ManagerTourSelectionDraft::normalize(['title'=>'Synthetic fixture','intro'=>'','offers'=>[$offer]]);onetouchCheck($draft!==null&&$draft['offers'][0]['import_source']['fields']['amount']==='123456.78','manual amount cannot overwrite original');
$p=ManagerTourSelectionPublicationDraft::build($draft);onetouchCheck($p['ok']&&$p['offers'][0]['snapshot']['price_qualifier']==='from','frozen publication retains minimum after edit');
$e=$p['offers'][0];$card=TourOfferCardRenderer::render($e['snapshot'],$e['presentation'],'tour_like_1_'.str_repeat('0',24));onetouchCheck(str_contains(explode("\n",$card['plain_text'])[1],'от 130 000'),'rendered immutable amount visibly from');
$bad=$draft;unset($bad['offers'][0]['price_qualifier']);onetouchCheck(ManagerTourSelectionDraft::normalize($bad)===null,'minimum cannot silently disappear');
$bad=$q;$bad['offer']['amount']='1';onetouchCheck(ManagerTourSelectionImportDraft::normalize($bad)===null,'forged amount rejected');
$bad=$q;$bad['offer']['price_qualifier']='fixed';onetouchCheck(ManagerTourSelectionImportDraft::normalize($bad)===null,'source minimum cannot become fixed');
$bad=$q;$bad['photos']=['https://image.onetouch.travel.evil.test/example.jpg'];onetouchCheck(ManagerTourSelectionImportDraft::normalize($bad)===null,'foreign image host rejected');
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse($f['response'],$f['url'].'&ADULT=2',$f['form'],'2077-01-01T00:00:00Z'),'duplicate query refused');
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse($f['response'],str_replace('CHILD=0','CHILD=1',$f['url']),$f['form'],'2077-01-01T00:00:00Z'),'unobserved child contract refused');
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse(str_replace('adult-2','adult-3',$f['response']),$f['url'],$f['form'],'2077-01-01T00:00:00Z'),'row and query party mismatch refused');
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::gallery(str_replace('Synthetic Fixture Hotel','Different Fixture Hotel',$f['hotel']),$q),'foreign hotel identity refused');
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::resultHtml('samo.jQuery(samo.controls.resultset).ehtml(alert(1));'),'executable argument refused');
echo 'OneTouch synthetic source: '.$passed." checks passed; no network or selection writes.\n";
