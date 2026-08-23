<?php

declare(strict_types=1);

require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';

class MissingQuestionTestMessenger implements MessengerInterface {
    public array $sent = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId,$text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}
class MaxSearchApi {
    public static $statusAi = 76;
    public static array $statuses = [];
    public static function setStatus($chatId,$status,$mess=false){ self::$statuses[]=[(int)$chatId,(int)$status]; }
}
require_once __DIR__ . '/../services/MissingFieldQuestionService.php';

$passed=0;$failed=0;
function mfqCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$m = new MissingQuestionTestMessenger();
IntegrationRegistry::resetForTests($m,null,null);

mfqCheck('city question', MissingFieldQuestionService::question('city'), 'Из какого города планируете вылет?');
mfqCheck('country default question', MissingFieldQuestionService::question('country'), 'Куда хотите поехать?');
mfqCheck('country explicit question', MissingFieldQuestionService::question('country',['country_explicit'=>true]), 'В какую страну хотите поехать?');
mfqCheck('month-only date question', MissingFieldQuestionService::question('date',['month_only'=>true]), 'Подскажите ориентировочную дату вылета в этом месяце — например, в начале, середине или конце.');
mfqCheck('unknown fallback', MissingFieldQuestionService::question('unknown'), 'Уточните, пожалуйста, параметры поездки.');

MissingFieldQuestionService::sendForMissing(321,['children']);
mfqCheck('send uses messenger', $m->sent[0] ?? null, [321,'Будут дети? Если да — сколько?']);
mfqCheck('send restores ai status', MaxSearchApi::$statuses[0] ?? null, [321,76]);

$source = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
mfqCheck('AiMessageHandler has no direct MaxSend', strpos($source,'MaxSearchApi::MaxSend(') === false, true);
mfqCheck('AiMessageHandler uses shared service', strpos($source,'MissingFieldQuestionService::sendForMissing') !== false, true);

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);
$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
