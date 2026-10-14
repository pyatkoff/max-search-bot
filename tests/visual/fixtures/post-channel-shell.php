<?php
/** Real PHP publication shell with a CLI-only synthetic authenticated account. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('M2_QA_SOURCE_ROOT') ?: dirname(__DIR__, 3);
$temporary = sys_get_temp_dir().'/post-channel-browser-'.bin2hex(random_bytes(6));
mkdir($temporary, 0700); mkdir($temporary.'/manager', 0700); mkdir($temporary.'/sessions', 0700);
try {
    require_once $root.'/services/ManagerRequestContext.php';
    $fixture = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $fixture->exec("CREATE TABLE projects (id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE managers (id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE manager_projects (manager_id INTEGER,project_id INTEGER);
        INSERT INTO projects VALUES (1,'post-fixture','Synthetic AnyTour',1);
        INSERT INTO managers VALUES (7,'synthetic-admin','Synthetic administrator','admin','',1,1);
        INSERT INTO manager_projects VALUES (7,1);");
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $fixture);
    ProjectConfig::resetForTests(['id'=>'post-fixture', 'brand'=>['name'=>'Synthetic AnyTour']]);
    $fixture->exec('PRAGMA query_only=ON');
    ini_set('session.save_path', $temporary.'/sessions');
    require_once $root.'/manager/lib/ManagerHttp.php';
    ManagerHttp::start(); $_SESSION['manager_id'] = 7; $_SESSION['csrf'] = 'fixture-csrf';
    file_put_contents($temporary.'/config.php', '<?php // CLI fixture: canonical services already loaded.');
    symlink($root.'/manager/lib', $temporary.'/manager/lib');
    symlink($root.'/manager/assets', $temporary.'/manager/assets');
    copy($root.'/manager/publications.php', $temporary.'/manager/publications.php');
    require $temporary.'/manager/publications.php';
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $remove = static function(string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') $remove($path.'/'.$item);
        rmdir($path);
    };
    $remove($temporary);
}
