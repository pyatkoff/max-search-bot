<?php

declare(strict_types=1);

if(!class_exists('MaxSearchApi')){
    class MaxSearchApi{
        public static $statusStart=64;
        public static $statusCityChoose=65;
        public static $statusContryChoose=66;
        public static $statusAdults=67;
        public static $statusChild=68;
        public static $statusAge=69;
        public static $statusStars=70;
        public static $statusMeal=71;
        public static $statusNights=72;
        public static $statusDate=73;
        public static $statusCheck=74;
        public static $statusPhone=75;
        public static $statusAi=76;
    }
}

require_once __DIR__.'/../services/DialogueStateMachine.php';
require_once __DIR__.'/../services/InteractionGuard.php';
require_once __DIR__.'/support/ScenarioEngine.php';

$handlers=[
    'duplicate'=>static function(array $step,array &$context):array{
        $state=&$context['state'];
        $previousPayload=(string)($state['previous_payload']??'');
        $previousAt=(float)($state['previous_at']??0.0);
        $payload=(string)($step['payload']??'');
        $at=(float)($step['at']??0);
        $window=(float)($step['window_seconds']??0);
        $expected=(bool)($step['expect_duplicate']??false);
        $actual=InteractionGuard::isDuplicate($previousPayload,$previousAt,$payload,$at,$window);
        if(!$actual){$state['previous_payload']=$payload;$state['previous_at']=$at;}
        return ['label'=>'duplicate decision','actual'=>$actual,'expected'=>$expected];
    },
    'transition'=>static function(array $step,array &$context):array{
        $from=(string)($step['from']??'');
        $to=(string)($step['to']??'');
        $mode=(string)($step['mode']??'forward');
        $expected=(bool)($step['expect_allowed']??false);
        return ['label'=>'transition decision','actual'=>DialogueStateMachine::canTransition($from,$to,$mode),'expected'=>$expected];
    },
];

$engine=new ScenarioEngine($handlers);
$failed=0;
$root=__DIR__.'/scenarios';
$requested=array_values(array_filter(array_slice($argv,1),static fn(string $v):bool=>$v!==''));
$suites=$requested?:['dialogue'];
foreach($suites as $suite){
    $dir=$root.'/'.$suite;
    echo "== scenario suite: {$suite} ==\n";
    if(!is_dir($dir)){
        fwrite(STDERR,"Missing scenario suite: {$suite}\n");
        $failed++;
        continue;
    }
    $failed+=$engine->runDirectory($dir);
}
$engine->report();
exit($failed>0?1:0);
