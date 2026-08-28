<?php

declare(strict_types=1);

$passed = 0;
$failed = 0;

function mpCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    $failed++;
}

$pushSource = (string)file_get_contents(__DIR__ . '/../services/ManagerPushService.php');
$pushMigration = (string)file_get_contents(__DIR__ . '/../migrations/018_manager_push_subscriptions.sql');
$swSource = (string)file_get_contents(__DIR__ . '/../manager/sw.js');
$notificationSource = (string)file_get_contents(__DIR__ . '/../manager/assets/workspace-v2-notifications.js');
$conversationSource = (string)file_get_contents(__DIR__ . '/../manager/assets/workspace-v2-conversation.js');

mpCheck(
    'subscription identity is unique per manager and endpoint',
    strpos($pushMigration, 'UNIQUE KEY uq_manager_endpoint (manager_id,endpoint_hash)') !== false
);
mpCheck(
    'selected managers load all registered subscriptions',
    strpos($pushSource, 'SELECT * FROM manager_push_subscriptions WHERE manager_id IN ($in)') !== false
);
mpCheck(
    'one dispatch id is created for one notify call',
    strpos($pushSource, '$dispatchId=self::dispatchId();') !== false
);
mpCheck(
    'priority selection is correlated to push dispatch',
    strpos($pushSource, "'push_selected',['dispatch_id'=>\$dispatchId") !== false
);
mpCheck(
    'push fanout sends once for every selected subscription row with same dispatch id',
    strpos($pushSource, 'foreach($subs as $sub)') !== false
        && strpos($pushSource, 'self::send($sub,(string)$payload,$conversationId,$dispatchId)') !== false
);
mpCheck(
    'push success and missing subscription logs carry dispatch id',
    strpos($pushSource, "'delivery_success',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'no_subscription',['dispatch_id'=>\$dispatchId") !== false
);
mpCheck(
    'push failure paths carry dispatch id',
    strpos($pushSource, "'delivery_exception',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'delivery_failed',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'subscription_expired',['dispatch_id'=>\$dispatchId") !== false
        && strpos($pushSource, "'notify_failed',['dispatch_id'=>\$dispatchId") !== false
);
mpCheck(
    'push payload carries exact conversation id',
    strpos($pushSource, "'conversationId'=>\$conversationId") !== false
);
mpCheck(
    'service worker uses conversation-scoped notification tag',
    strpos($swSource, "'anytour-manager-'+Number(data.conversationId||0)") !== false
);
mpCheck(
    'notification click targets exact conversation id',
    strpos($swSource, "const conversationId=Number(data.conversationId||0)") !== false
        && strpos($swSource, "'#conversation='+conversationId") !== false
);
mpCheck(
    'existing manager window receives one open-conversation command then returns',
    strpos($swSource, "client.postMessage({type:'OPEN_CONVERSATION',conversationId});\n        return;") !== false
);
mpCheck(
    'notification click has openWindow fallback only when no window handled it',
    strpos($swSource, 'if(self.clients.openWindow)return self.clients.openWindow(target);') !== false
);
mpCheck(
    'Workspace V2 maps service-worker open command directly to conversation open',
    strpos($notificationSource, "data.type==='OPEN_CONVERSATION'") !== false
        && strpos($notificationSource, 'WorkspaceV2Conversation?.open(Number(data.conversationId))') !== false
);

$openConversation = '';
if (preg_match('/async function open\(id,options=\{\}\)\{(.*?)\}\nasync function refreshLeadData/s', $conversationSource, $m)) {
    $openConversation = (string)$m[1];
}
mpCheck('Workspace V2 conversation open function found for side-effect contract', $openConversation !== '');
mpCheck(
    'opening a conversation is read-only and does not auto-take it',
    $openConversation !== ''
        && strpos($openConversation, "api('detail'") !== false
        && strpos($openConversation, "change('take')") === false
        && strpos($openConversation, "api('take'") === false
);
mpCheck(
    'take remains an explicit manager action',
    strpos($conversationSource, "action('Взять','primary',()=>change('take'))") !== false
);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
