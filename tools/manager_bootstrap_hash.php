<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/services/ConversationDb.php';
require_once $base . '/services/ManagerAuthService.php';
$login = trim((string)($argv[1] ?? ''));
$hash = (string)($argv[2] ?? '');
$name = trim((string)($argv[3] ?? ''));
if ($login === '' || strpos($hash, '$2') !== 0) { fwrite(STDERR, "Usage: php tools/manager_bootstrap_hash.php <login> <bcrypt-hash> [display name]\n"); exit(2); }
try {
    ManagerAuthService::ensureSchema();
    $pdo = ConversationDb::connection();
    $q = $pdo->prepare('SELECT id FROM managers WHERE login=? LIMIT 1');
    $q->execute([$login]);
    $id = (int)$q->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE managers SET password_hash=?, display_name=COALESCE(NULLIF(?,\'\'),display_name), is_active=1 WHERE id=?')->execute([$hash,$name,$id]);
        echo "MANAGER UPDATED\n";
    } else {
        $q = $pdo->prepare('INSERT INTO managers (login,password_hash,display_name,is_active) VALUES (?,?,?,1)');
        $q->execute([$login,$hash,$name !== '' ? $name : null]);
        echo "MANAGER CREATED\n";
    }
} catch (Throwable $e) { fwrite(STDERR, 'ERROR: '.$e->getMessage()."\n"); exit(1); }
