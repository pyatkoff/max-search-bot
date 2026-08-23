<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/LegacyActionClassifier.php';
require_once __DIR__ . '/../services/ShadowComparisonReport.php';

$pass=0;$fail=0;
function scCheck(string $name,$actual,$expected):void{global $pass,$fail;if($actual===$expected){echo "PASS  {$name}\n";$pass++;return;}echo "FAIL  {$name}\n";echo '      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo '      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$fail++;}

scCheck('question classified ASK', LegacyActionClassifier::classify('Из какого города планируете вылет?')['action'], 'ASK');
scCheck('search button classified OPEN_SEARCH', LegacyActionClassifier::classify('Готово', [[['text'=>'Посмотреть туры','url'=>'https://example.ru/poisk-turov/']]])['action'], 'OPEN_SEARCH');
scCheck('manager classified MANAGER', LegacyActionClassifier::classify('Передам менеджеру')['action'], 'MANAGER');
scCheck('country buttons classified SHOW_OPTIONS', LegacyActionClassifier::classify('Куда поедем?', [[['text'=>'Турция','callback_data'=>'tr'],['text'=>'Египет','callback_data'=>'eg']]])['action'], 'SHOW_OPTIONS');

$tmp=tempnam(sys_get_temp_dir(),'shadowcmp');
$rows=[
 ['ts'=>'2026-08-23T20:00:00+00:00','component'=>'dialogue_v2_shadow','event'=>'message_evaluated','chat_id'=>1,'data'=>['message'=>'из Москвы в Турцию','extracted'=>['intent'=>'tour_search','changes'=>[]],'rule_action'=>'ASK','missing'=>['dates'],'next_field'=>'dates','reason'=>'search_missing_fields']],
 ['ts'=>'2026-08-23T20:00:01+00:00','component'=>'legacy_dialogue','event'=>'outcome','chat_id'=>1,'data'=>['action'=>'ASK','confidence'=>.9,'reason'=>'question_text','text'=>'Когда примерно хотите вылететь?','buttons'=>[]]],
 ['ts'=>'2026-08-23T20:01:00+00:00','component'=>'dialogue_v2_shadow','event'=>'message_evaluated','chat_id'=>2,'data'=>['message'=>'куда можно','extracted'=>['intent'=>'destination_advice','changes'=>[]],'rule_action'=>'SHOW_OPTIONS','missing'=>[],'next_field'=>null,'reason'=>'destination_advice']],
 ['ts'=>'2026-08-23T20:01:01+00:00','component'=>'legacy_dialogue','event'=>'outcome','chat_id'=>2,'data'=>['action'=>'ASK','confidence'=>.9,'reason'=>'question_text','text'=>'Куда хотите поехать?','buttons'=>[]]],
];
foreach($rows as$r)file_put_contents($tmp,json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND);
$report=ShadowComparisonReport::build($tmp);
@unlink($tmp);
scCheck('paired messages', $report['summary']['paired_messages'], 2);
scCheck('same action count', $report['summary']['same_action'], 1);
scCheck('different action count', $report['summary']['different_action'], 1);
scCheck('agreement percent', $report['summary']['agreement_pct'], 50.0);
scCheck('mismatch shadow action', $report['mismatches'][0]['shadow']['action']??null, 'SHOW_OPTIONS');
scCheck('mismatch legacy action', $report['mismatches'][0]['legacy']['action']??null, 'ASK');

$total=$pass+$fail;echo "\n--------------------------\n";echo "TOTAL {$total} | PASS {$pass} | FAIL {$fail}\n";exit($fail>0?1:0);
