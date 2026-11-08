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
// New parametric party contract; all source material remains synthetic.
foreach([1,3,14] as $adults){
    $adultFixture=syntheticOneTouchSource($adults);
    $adultQuote=ManagerTourSelectionOneTouchSource::parse($adultFixture['response'],$adultFixture['url'],$adultFixture['form'],'2077-01-01T00:00:00Z')[0];
    onetouchCheck($adultQuote['offer']['adults']===$adults&&$adultQuote['offer']['child_ages']===[]&&$adultQuote['raw']['party']===$adults.'AD','exact query row offer raw party '.$adults);
    onetouchCheck($adultQuote['offer']['amount']==='123456.78'&&$adultQuote['offer']['price_qualifier']==='from'&&$adultQuote['raw']['price']==='от 123456.78 RUB','whole-party minimum is never scaled '.$adults);
    onetouchCheck($adultQuote['offer']['room']==='STANDARD / DBL','native room label remains literal rather than an inferred bed claim '.$adults);
    $adultImport=ManagerTourSelectionImportDraft::normalize($adultQuote);
    onetouchCheck($adultImport!==null&&ManagerTourSelectionImportDraft::source($adultImport)['fields']['adults']===$adults,'canonical source party retained '.$adults);
}
$urlFor=static fn(array $query):string=>'https://b2b.onetouch.travel/search_tour?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
foreach(['0','15','16','17','18','19','20','1.5','03','','-1'] as $invalidAdult){
    $invalidQuery=$f['query'];$invalidQuery['ADULT']=$invalidAdult;
    onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::query($urlFor($invalidQuery)),'unsupported or noncanonical adult count '.$invalidAdult);
}
$missingAdult=$f['query'];unset($missingAdult['ADULT']);
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::query($urlFor($missingAdult)),'missing adult count cannot default to two');
$extraAges=$f['query'];$extraAges['AGES']='7';
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::query($urlFor($extraAges)),'children ages are not admitted by adult range');
$three=syntheticOneTouchSource(3);
foreach(['adult-2 adult-3','adult-3 adult-3','adult-03','adult-0',''] as $tokens){
    onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse(str_replace('adult-3',$tokens,$three['response']),$three['url'],$three['form'],'2077-01-01T00:00:00Z'),'native adult token must be unique and exact '.$tokens);
}
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse(str_replace('child-0','child-0 child-1',$three['response']),$three['url'],$three['form'],'2077-01-01T00:00:00Z'),'native child token cannot contradict child-free query');
$threeQuote=ManagerTourSelectionOneTouchSource::parse($three['response'],$three['url'],$three['form'],'2077-01-01T00:00:00Z')[0];
$badParty=$threeQuote;$badParty['offer']['adults']=2;
onetouchCheck(ManagerTourSelectionImportDraft::normalize($badParty)===null,'normalized offer cannot disagree with query party');
$badParty=$threeQuote;$badParty['raw']['party']='2AD';
onetouchCheck(ManagerTourSelectionImportDraft::normalize($badParty)===null,'raw party cannot disagree with canonical three adults');
$badParty=$threeQuote;$badParty['offer']['child_ages']=[7];
onetouchCheck(ManagerTourSelectionImportDraft::normalize($badParty)===null,'child-free capture cannot acquire a child through normalization');
$twoSource=ManagerTourSelectionImportDraft::source(ManagerTourSelectionImportDraft::normalize($quotes[0]));
$threeSource=ManagerTourSelectionImportDraft::source(ManagerTourSelectionImportDraft::normalize($threeQuote));
onetouchCheck(ManagerTourSelectionImportDraft::fingerprint($twoSource,false)!==ManagerTourSelectionImportDraft::fingerprint($threeSource,false),'otherwise identical source conditions retain distinct party identity');


// Family controls describe requested ages; native rows independently bind counts only.
$familyQuotes=[];
foreach([[0],[8],[17],[8,8],[0,8,17]] as $ages){
    $ff=syntheticOneTouchSource(2,$ages);$fq=ManagerTourSelectionOneTouchSource::parse($ff['response'],$ff['url'],$ff['form'],'2077-01-01T00:00:00Z')[0];
    onetouchCheck($fq['offer']['adults']===2&&$fq['offer']['child_ages']===$ages&&$fq['raw']['party']==='2AD+'.count($ages).'CH('.implode(',',$ages).')','requested ages retain multiplicity '.implode(',',$ages));
    $fq['photos']=ManagerTourSelectionOneTouchSource::gallery($ff['hotel'],$fq);$fi=ManagerTourSelectionImportDraft::normalize($fq);
    onetouchCheck($fi!==null&&$fi['offer']['child_ages']===$ages,'family normalizes through unchanged generic draft '.implode(',',$ages));
    onetouchCheck($fi['offer']['amount']==='123456.78'&&$fi['offer']['price_qualifier']==='from'&&$fi['offer']['room']==='STANDARD ROOM / 2 ADL + '.count($ages).' CHD(0-17)'&&$fi['offer']['meal']==='BB','whole-party from and literal placement retained '.implode(',',$ages));
    onetouchCheck(str_contains($fi['offer']['constraints'],'Возраст детей — из параметров поиска.')&&str_contains($fi['offer']['constraints'],'требуют подтверждения у оператора'),'requested age and unknown beds explained '.implode(',',$ages));
    $familyQuotes[implode(',',$ages)]=$fq;
}
$ff=syntheticOneTouchSource(2,[8]);$fq=$familyQuotes['8'];
$mixed='<table>'.preg_replace('~</?table>~','',$three['html']).preg_replace('~</?table>~','',$ff['html']).'</table>';
$mixedResponse='samo.jQuery(samo.controls.resultset).ehtml('.json_encode($mixed,JSON_THROW_ON_ERROR).');';
$mixedQuotes=ManagerTourSelectionOneTouchSource::parse($mixedResponse,$ff['url'],$ff['form'],'2077-01-01T00:00:00Z');
onetouchCheck(count($mixedQuotes)===1&&$mixedQuotes[0]['offer']['child_ages']===[8]&&$mixedQuotes[0]['offer']['adults']===2,'nearby adult-only alternative is excluded rather than relabelled');
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse($three['response'],$ff['url'],$ff['form'],'2077-01-01T00:00:00Z'),'no exact actual party cannot produce a family quote');
foreach(['adult-2 adult-2','adult-2 adult-3','adult-02','adult-',''] as $token)onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse(str_replace('adult-2',$token,$ff['response']),$ff['url'],$ff['form'],'2077-01-01T00:00:00Z'),'family adult token ambiguous or malformed '.$token);
foreach(['child-1 child-1','child-1 child-0','child-01','child-4','child-',''] as $token)onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::parse(str_replace('child-1',$token,$ff['response']),$ff['url'],$ff['form'],'2077-01-01T00:00:00Z'),'family child token ambiguous or malformed '.$token);
foreach([['CHILD'=>'1','AGES'=>''],['CHILD'=>'1','AGES'=>'18'],['CHILD'=>'1','AGES'=>'08'],['CHILD'=>'1','AGES'=>'-1'],['CHILD'=>'1','AGES'=>'8.0'],['CHILD'=>'2','AGES'=>'8'],['CHILD'=>'1','AGES'=>'8,8'],['CHILD'=>'2','AGES'=>'9,8'],['CHILD'=>'4','AGES'=>'0,1,2,3'],['CHILD'=>'0','AGES'=>'']] as $change){$query=array_replace($f['query'],$change);onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::query($urlFor($query)),'noncanonical child query refused '.json_encode($change));}
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::query($ff['url'].'&AGES=8'),'duplicate age query refused');
onetouchReject(fn()=>ManagerTourSelectionOneTouchSource::query($ff['url'].'&AGE1=8'),'extra age query key refused');
foreach([[9],['8'],[8.0],[true],[],[8,8],[1=>8]] as $wrong){$bad=$fq;$bad['offer']['child_ages']=$wrong;onetouchCheck(ManagerTourSelectionImportDraft::normalize($bad)===null,'canonical offer cannot substitute requested age '.json_encode($wrong));}
$bad=$fq;$bad['raw']['party']='2AD+1CH(9)';onetouchCheck(ManagerTourSelectionImportDraft::normalize($bad)===null,'raw party age cannot diverge from query');
$nineFixture=syntheticOneTouchSource(2,[9]);$nine=ManagerTourSelectionOneTouchSource::parse($nineFixture['response'],$nineFixture['url'],$nineFixture['form'],'2077-01-01T00:00:00Z')[0];$nine['photos']=[$nineFixture['photo']];
$eightSource=ManagerTourSelectionImportDraft::source(ManagerTourSelectionImportDraft::normalize($fq));$nineSource=ManagerTourSelectionImportDraft::source(ManagerTourSelectionImportDraft::normalize($nine));
onetouchCheck(ManagerTourSelectionImportDraft::fingerprint($eightSource,false)!==ManagerTourSelectionImportDraft::fingerprint($nineSource,false),'same minimum and hotel retain different requested-age identity');
$twins=$familyQuotes['8,8'];$twins['offer']['child_ages']=[8,8];onetouchCheck(ManagerTourSelectionImportDraft::normalize($twins)['offer']['child_ages']===[8,8],'equal ages never collapse');


echo 'OneTouch synthetic source: '.$passed." checks passed; no network or selection writes.\n";
