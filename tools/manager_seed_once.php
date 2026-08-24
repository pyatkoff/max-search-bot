<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/services/ConversationDb.php';
require_once $base . '/services/ManagerAuthService.php';

ManagerAuthService::ensureSchema();
$pdo = ConversationDb::connection();
$login = 'manager1';
$hash = '$2y$12$NvK/nLlXQeSmS6O.Qrrl8OPITlSShWACFBiM7YChqCuEnd/ROBqiK';
$name = 'Менеджер AnyTour';

$q = $pdo->prepare('SELECT id,password_hash FROM managers WHERE login=? LIMIT 1');
$q->execute([$login]);
$row = $q->fetch();
if ($row) {
    if (empty($row['password_hash'])) {
        $pdo->prepare('UPDATE managers SET password_hash=?,display_name=COALESCE(NULLIF(display_name,\'\'),?),is_active=1 WHERE id=?')
            ->execute([$hash,$name,(int)$row['id']]);
        echo "MANAGER SEEDED\nLOGIN: {$login}\n";
    } else {
        echo "MANAGER EXISTS\nLOGIN: {$login}\n";
    }
    exit(0);
}

$q = $pdo->prepare('INSERT INTO managers (login,password_hash,display_name,is_active) VALUES (?,?,?,1)');
$q->execute([$login,$hash,$name]);
echo "MANAGER SEEDED\nLOGIN: {$login}\n";
