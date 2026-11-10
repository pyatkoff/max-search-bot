<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/NeedValueResolver.php';

$passed=0;$failed=0;
function livePartyCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";echo '      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo '      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$rich=NeedValueResolver::resolve('adults','Два взрослых двое детей 13-9 лет');
livePartyCheck('conversation 499 rich party answer recovers adults',$rich['recognized'],true);
livePartyCheck('conversation 499 rich party answer adult count',$rich['value'],2);

foreach(['Двое детей','Детей двое'] as $phrase){
    $r=NeedValueResolver::resolve('children',$phrase);
    livePartyCheck("conversation 499 child phrase {$phrase} recognized",$r['recognized'],true);
    livePartyCheck("conversation 499 child phrase {$phrase} count",$r['value'],2);
}

livePartyCheck('age-only phrase is not mistaken for adults',NeedValueResolver::resolve('adults','13-9 лет')['recognized'],false);
livePartyCheck('bare affirmative still does not invent children',NeedValueResolver::resolve('children','Да')['recognized'],false);
livePartyCheck('four children stays outside supported range',NeedValueResolver::resolve('children','4 детей')['recognized'],false);

// Complete short replies to the adult question retain the canonical resolver.
foreach ([
    ['2ч.',2], ['2ч',2], ['2 ч.',2], ['2 чел.',2], ['2чел',2], ['1Ч.',1], ['6ч.',6],
] as [$phrase,$expected]) {
    $resolved=NeedValueResolver::resolve('adults',$phrase);
    livePartyCheck('short adult reply resolves canonically: '.$phrase,
        [$resolved['recognized'],$resolved['value'],$resolved['source']],
        [true,$expected,'deterministic:adults_parser']);
}
foreach ([
    '0ч.','7ч.','12ч.','2 часа','2ч30мин','2ч. и ребенок','ребёнку 2ч.',
    '2ч-3ч','не 2ч., а 3ч.','до 2ч.',
] as $phrase) {
    livePartyCheck('unsupported adult shorthand stays unrecognized: '.$phrase,
        NeedValueResolver::resolve('adults',$phrase)['recognized'],false);
}
foreach (['2ч.','2 чел.'] as $phrase) {
    livePartyCheck('context-free adult consumers do not infer shorthand: '.$phrase,
        AdultsParser::parse($phrase),null);
}
livePartyCheck('adult shorthand is not a night duration',
    NeedValueResolver::resolve('nights','2ч.')['recognized'],false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
