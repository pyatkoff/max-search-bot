<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/HandoffTimelineService.php';

$failed = 0;
function htCheck(string $name, $actual, $expected): void {
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$conversation = ['id'=>308,'project_key'=>'anytour','channel'=>'max','status'=>'manager','manager_id'=>5];
$dbEvents = [
    ['id'=>1,'event_type'=>'waiting_manager','actor_type'=>'customer','created_at'=>'2026-08-26 12:33:24'],
    ['id'=>2,'event_type'=>'manager_taken','actor_type'=>'manager','actor_id'=>5,'created_at'=>'2026-08-26 12:34:00'],
    ['id'=>3,'event_type'=>'manager_message','actor_type'=>'manager','actor_id'=>5,'created_at'=>'2026-08-26 12:35:00'],
];
$messages = [
    ['id'=>10,'direction'=>'outbound','sender_type'=>'manager','created_at'=>'2026-08-26 12:35:00'],
];
$priority = [[
    'ts'=>'2026-08-26T12:33:24+02:00','component'=>'manager_priority','event'=>'push_selected',
    'data'=>['conversation_id'=>308,'dispatch_id'=>'d-308-1','eligible_manager_ids'=>[4,5],'selected_manager_ids'=>[4,5],'scores'=>['4'=>0,'5'=>0]],
]];
$push = [
    ['ts'=>'2026-08-26T12:33:24+02:00','component'=>'manager_push','event'=>'no_subscription','data'=>['conversation_id'=>308,'dispatch_id'=>'d-308-1','manager_id'=>4]],
    ['ts'=>'2026-08-26T12:33:24+02:00','component'=>'manager_push','event'=>'delivery_success','data'=>['conversation_id'=>308,'dispatch_id'=>'d-308-1','manager_id'=>5,'subscription_id'=>2,'http_code'=>201]],
    ['ts'=>'2026-08-26T12:33:24+02:00','component'=>'manager_push','event'=>'delivery_success','data'=>['conversation_id'=>308,'dispatch_id'=>'d-308-1','manager_id'=>5,'subscription_id'=>3,'http_code'=>201]],
    ['ts'=>'2026-08-26T12:40:00+02:00','component'=>'manager_push','event'=>'delivery_success','data'=>['conversation_id'=>308,'dispatch_id'=>'d-308-2','manager_id'=>5,'subscription_id'=>2,'http_code'=>201]],
];

$r = HandoffTimelineService::build($conversation,$dbEvents,$messages,$priority,$push);
htCheck('conversation id', $r['conversation_id'], 308);
htCheck('dispatch correlation uses selected dispatch', $r['dispatch_id'], 'd-308-1');
htCheck('fanout contains only correlated dispatch results', $r['summary']['push_result_count'], 3);
htCheck('two successful subscription deliveries', $r['summary']['push_success_count'], 2);
htCheck('one non-success push result', $r['summary']['push_failure_count'], 1);
htCheck('taken stage present', $r['summary']['taken'], true);
htCheck('first reply present', $r['summary']['first_reply'], true);
htCheck('delivery success present', $r['summary']['delivery_success'], true);
$stages = array_column($r['timeline'],'stage');
htCheck('timeline starts requested', $stages[0] ?? null, 'requested');
htCheck('timeline includes selected', in_array('selected',$stages,true), true);
htCheck('timeline includes push result', in_array('push_result',$stages,true), true);
htCheck('timeline includes taken', in_array('taken',$stages,true), true);
htCheck('timeline includes first reply', in_array('first_reply',$stages,true), true);

$legacy = HandoffTimelineService::build($conversation,$dbEvents,$messages,[
    ['ts'=>'2026-08-26T12:33:24+02:00','event'=>'push_selected','data'=>['conversation_id'=>308,'eligible_manager_ids'=>[5],'selected_manager_ids'=>[5]]],
],[
    ['ts'=>'2026-08-26T12:33:24+02:00','event'=>'delivery_success','data'=>['conversation_id'=>308,'manager_id'=>5,'subscription_id'=>2,'http_code'=>201]],
]);
htCheck('legacy no-dispatch remains observable', $legacy['dispatch_id'], null);
htCheck('legacy push result retained', $legacy['summary']['push_result_count'], 1);

exit($failed > 0 ? 1 : 0);
