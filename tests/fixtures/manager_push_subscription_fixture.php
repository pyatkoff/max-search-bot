<?php
declare(strict_types=1);

// Synthetic SQLite storage for the real push-health SQL. information_schema and
// DATABASE() provide metadata only; no production SELECT is rewritten.
function managerPushSubscriptionFixture(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'push_fixture');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema;
        CREATE TABLE information_schema.tables(table_schema TEXT,table_name TEXT);
        INSERT INTO information_schema.tables VALUES('push_fixture','managers'),('push_fixture','manager_push_subscriptions');");
}

function managerPushSubscriptionFixtureSeed(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        CREATE TABLE manager_push_subscriptions(id INTEGER PRIMARY KEY,manager_id INTEGER,endpoint TEXT,endpoint_hash TEXT,p256dh TEXT,auth_secret TEXT,user_agent TEXT,last_success_at TEXT,last_error_at TEXT,last_error TEXT,updated_at TEXT,UNIQUE(manager_id,endpoint_hash));
        INSERT INTO managers VALUES(7,'seven','Manager Seven','manager','',1,1),(8,'eight','Manager Eight','manager','',1,1),(9,'inactive','Inactive','manager','',0,1),(10,'offshift','Off shift','manager','',1,0);
        INSERT INTO projects VALUES(1,'push-fixture','Push fixture',1);
        INSERT INTO manager_projects VALUES(7,1),(8,1),(9,1),(10,1);");
    $insert=$pdo->prepare('INSERT INTO manager_push_subscriptions(manager_id,endpoint,endpoint_hash,p256dh,auth_secret,user_agent,last_success_at,last_error_at,last_error) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach ([
        [7,'a','2026-10-15 12:00:00',null,null],
        [7,'b','2026-10-15 11:00:00','2026-10-15 12:00:00','private-provider-failure-seven'],
        [8,'b','2026-10-15 13:00:00','2026-10-15 12:00:00','private-provider-failure-eight'],
        [8,'c',null,null,null],
        [9,'a',null,null,null],
        [10,'a',null,null,null],
    ] as [$manager,$key,$success,$errorAt,$error]) {
        $endpoint='https://push-fixture.invalid/subscription-'.$key;
        $insert->execute([$manager,$endpoint,hash('sha256',$endpoint),'private-p256dh-'.$manager.'-'.$key,'private-auth-'.$manager.'-'.$key,'private-user-agent-'.$manager,$success,$errorAt,$error]);
    }
}

// Only the existing MySQL write syntax is adapted for the HTTP subscribe case.
// Auth, endpoint code, health queries, hashes and native SQLite execution remain
// real; the read-only status cases additionally enable PRAGMA query_only.
final class ManagerPushSubscriptionHttpFixturePDO extends PDO
{
    public function prepare(string $query, array $options=[]): PDOStatement|false
    {
        if (str_starts_with($query,'INSERT INTO manager_push_subscriptions ') && str_contains($query,' ON DUPLICATE KEY UPDATE ')) {
            $query='INSERT INTO manager_push_subscriptions (manager_id,endpoint,endpoint_hash,p256dh,auth_secret,user_agent) VALUES (?,?,?,?,?,?)
                ON CONFLICT(manager_id,endpoint_hash) DO UPDATE SET
                last_error_at=CASE WHEN manager_push_subscriptions.p256dh<>excluded.p256dh OR manager_push_subscriptions.auth_secret<>excluded.auth_secret THEN NULL ELSE manager_push_subscriptions.last_error_at END,
                last_error=CASE WHEN manager_push_subscriptions.p256dh<>excluded.p256dh OR manager_push_subscriptions.auth_secret<>excluded.auth_secret THEN NULL ELSE manager_push_subscriptions.last_error END,
                endpoint=excluded.endpoint,p256dh=excluded.p256dh,auth_secret=excluded.auth_secret,user_agent=excluded.user_agent,updated_at=CURRENT_TIMESTAMP';
        }
        return parent::prepare($query,$options);
    }
}
