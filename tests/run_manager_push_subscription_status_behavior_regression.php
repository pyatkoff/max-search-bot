<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}

require_once __DIR__.'/../services/ManagerPushHealth.php';
require_once __DIR__.'/fixtures/manager_push_subscription_fixture.php';

$passed=0;
function pushSubscriptionCheck(string $name,$actual,$expected): void
{
    global $passed;
    if ($actual!==$expected) throw new RuntimeException('FAIL '.$name.' expected '.json_encode($expected).' actual '.json_encode($actual));
    $passed++;echo 'PASS '.$name."\n";
}
function pushSubscriptionProof(PDO $pdo,int $managerId,string $suffix): array
{
    $proof=ManagerPushHealth::statusForSubscription($pdo,$managerId,'https://push-fixture.invalid/subscription-'.$suffix);
    pushSubscriptionCheck('proof exposes only two safe fields for manager '.$managerId.' / '.$suffix,array_keys($proof),['notification_path_usable','notification_path_reason']);
    return $proof;
}
$healthy=['notification_path_usable'=>true,'notification_path_reason'=>'healthy_subscription'];
$unhealthy=['notification_path_usable'=>false,'notification_path_reason'=>'subscription_unhealthy'];
$missing=['notification_path_usable'=>false,'notification_path_reason'=>'no_subscription'];
$pdo=new PDO('sqlite::memory:');
managerPushSubscriptionFixture($pdo);managerPushSubscriptionFixtureSeed($pdo);
$before=$pdo->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll();
$aggregate=ManagerPushHealth::statusForManager($pdo,7);$collected=ManagerPushHealth::collect($pdo);
$pdo->exec('PRAGMA query_only=ON');
pushSubscriptionCheck('aggregate retains healthy other-device status',[$aggregate['subscription_count'],$aggregate['healthy_subscription_count'],$aggregate['notification_path_usable']],[2,1,true]);
pushSubscriptionCheck('healthy current subscription is confirmed',pushSubscriptionProof($pdo,7,'a'),$healthy);
pushSubscriptionCheck('unhealthy current device remains unhealthy despite healthy sibling',pushSubscriptionProof($pdo,7,'b'),$unhealthy);
pushSubscriptionCheck('same endpoint healthy on another account cannot heal current manager',pushSubscriptionProof($pdo,8,'b'),$healthy);
pushSubscriptionCheck('endpoint belonging only to another manager is missing',pushSubscriptionProof($pdo,7,'c'),$missing);
pushSubscriptionCheck('missing endpoint never inherits account aggregate',pushSubscriptionProof($pdo,7,'missing'),$missing);
pushSubscriptionCheck('endpoint text cannot alter manager or hash predicates',pushSubscriptionProof($pdo,7,"b' OR 1=1 --"),$missing);
pushSubscriptionCheck('off-shift does not invalidate a healthy current device',pushSubscriptionProof($pdo,10,'a'),$healthy);
pushSubscriptionCheck('inactive manager cannot confirm stored device',pushSubscriptionProof($pdo,9,'a'),['notification_path_usable'=>false,'notification_path_reason'=>'manager_inactive']);
pushSubscriptionCheck('unknown manager cannot confirm another account device',pushSubscriptionProof($pdo,99,'a'),['notification_path_usable'=>false,'notification_path_reason'=>'manager_inactive']);
pushSubscriptionCheck('all current-device reads preserve account aggregate',ManagerPushHealth::statusForManager($pdo,7),$aggregate);
pushSubscriptionCheck('all current-device reads preserve global health diagnostics',ManagerPushHealth::collect($pdo),$collected);
pushSubscriptionCheck('read-only proof leaves every subscription byte intact',$pdo->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll(),$before);
$pdo->exec('PRAGMA query_only=OFF');
$pdo->prepare('UPDATE manager_push_subscriptions SET endpoint=? WHERE manager_id=7 AND endpoint_hash=?')->execute(['https://push-fixture.invalid/raw-endpoint-mismatch',hash('sha256','https://push-fixture.invalid/subscription-a')]);
pushSubscriptionCheck('stored SHA-256 identity is authoritative rather than raw endpoint text',pushSubscriptionProof($pdo,7,'a'),$healthy);
$pdo->prepare('UPDATE manager_push_subscriptions SET endpoint_hash=? WHERE manager_id=7 AND endpoint=?')->execute([hash('sha1','https://push-fixture.invalid/subscription-b'),'https://push-fixture.invalid/subscription-b']);
pushSubscriptionCheck('matching raw endpoint with a different hash cannot confirm subscription',pushSubscriptionProof($pdo,7,'b'),$missing);
$pdo->prepare('UPDATE manager_push_subscriptions SET endpoint_hash=? WHERE manager_id=7 AND endpoint=?')->execute([hash('sha256','https://push-fixture.invalid/subscription-b'),'https://push-fixture.invalid/subscription-b']);
$pdo->exec("UPDATE manager_push_subscriptions SET last_success_at=last_error_at WHERE manager_id=7 AND endpoint LIKE '%-b'");
pushSubscriptionCheck('equal success and error timestamps are healthy',pushSubscriptionProof($pdo,7,'b'),$healthy);
$pdo->exec("UPDATE manager_push_subscriptions SET last_success_at=NULL WHERE manager_id=7 AND endpoint LIKE '%-b'");
pushSubscriptionCheck('error with no success is unhealthy',pushSubscriptionProof($pdo,7,'b'),$unhealthy);
$pdo->exec("UPDATE manager_push_subscriptions SET last_error_at=NULL,last_success_at=NULL WHERE manager_id=7 AND endpoint LIKE '%-b'");
pushSubscriptionCheck('new saved subscription without error is usable',pushSubscriptionProof($pdo,7,'b'),$healthy);
$pdo->exec("DELETE FROM information_schema.tables WHERE table_name='manager_push_subscriptions'");
pushSubscriptionCheck('missing subscription table retains existing reason',pushSubscriptionProof($pdo,7,'a'),['notification_path_usable'=>false,'notification_path_reason'=>'subscription_table_missing']);
$pdo->exec("DELETE FROM information_schema.tables WHERE table_name='managers'");
pushSubscriptionCheck('missing managers table retains existing reason',pushSubscriptionProof($pdo,7,'a'),['notification_path_usable'=>false,'notification_path_reason'=>'managers_table_missing']);
$broken=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
pushSubscriptionCheck('database failure returns safe reason without exception details',pushSubscriptionProof($broken,7,'a'),['notification_path_usable'=>false,'notification_path_reason'=>'health_check_failed']);
echo "TOTAL {$passed} passed\n";
