<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/HandoffIntegrityHealth.php';

$failed=0;
function checkCase(string $name,array $rows,bool $ok,array $reasons=[]): void {
    global $failed;
    $result=HandoffIntegrityHealth::evaluate($rows);
    $actualReasons=array_values(array_map(static function($row){return(string)($row['reason']??'');},$result['anomalies']??[]));
    sort($actualReasons);sort($reasons);
    if(($result['ok']??null)===$ok && $actualReasons===$reasons){echo "PASS  {$name}\n";return;}
    echo "FAIL  {$name}\n";
    echo '      result: '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      expected reasons: '.json_encode($reasons,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

checkCase('healthy waiting request',[[
    'conversation_id'=>1,'status'=>'waiting_manager','manager_id'=>null,'manager_request_count'=>1,'active_assignment_count'=>0,'active_assignment_manager_id'=>null,
]],true);

checkCase('healthy manager assignment',[[
    'conversation_id'=>2,'status'=>'manager','manager_id'=>4,'manager_request_count'=>1,'active_assignment_count'=>1,'active_assignment_manager_id'=>4,
]],true);

checkCase('waiting state requires request event',[[
    'conversation_id'=>3,'status'=>'waiting_manager','manager_id'=>null,'manager_request_count'=>0,'active_assignment_count'=>0,'active_assignment_manager_id'=>null,
]],false,['waiting_manager_missing_request_event']);

checkCase('manager state requires manager and assignment',[[
    'conversation_id'=>4,'status'=>'manager','manager_id'=>null,'manager_request_count'=>1,'active_assignment_count'=>0,'active_assignment_manager_id'=>null,
]],false,['manager_status_missing_active_assignment','manager_status_missing_manager']);

checkCase('manager assignment mismatch is visible',[[
    'conversation_id'=>5,'status'=>'manager','manager_id'=>4,'manager_request_count'=>1,'active_assignment_count'=>1,'active_assignment_manager_id'=>5,
]],false,['active_assignment_manager_mismatch']);

checkCase('duplicate active assignment is visible',[[
    'conversation_id'=>6,'status'=>'manager','manager_id'=>4,'manager_request_count'=>1,'active_assignment_count'=>2,'active_assignment_manager_id'=>4,
]],false,['duplicate_active_assignments']);

checkCase('ai state cannot retain manager or active assignment',[[
    'conversation_id'=>7,'status'=>'ai','manager_id'=>4,'manager_request_count'=>1,'active_assignment_count'=>1,'active_assignment_manager_id'=>4,
]],false,['ai_status_has_manager','inactive_status_has_active_assignment']);

exit($failed?1:0);
