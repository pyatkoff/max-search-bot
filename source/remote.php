<?php
declare(strict_types=1);
// Dedicated one-shot process. stdout is captured privately, never a public Actions log.
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
$level=ob_get_level();ob_start();$result=null;$ok=false;
try {
    if (PHP_SAPI!=='cli'||count($argv)!==3||$argv[1]!=='/var/www/anytoour/data/www/app.anytoour.ru'||!preg_match('/\A[0-9a-f]{40}\z/D',$argv[2])) throw new RuntimeException('arguments');
    $root=$argv[1];chdir($root);
    // Guarded source hashes are checked by the enclosing strict carrier before PHP.
    // Config is used only as the existing internal connection bootstrap, never inspected/exported.
    // Reuses the existing trusted definition-only config contract and public boot call graph.
    // No runtime config values, credentials, schema repair or new config attestation is read here.
    require_once $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/ProjectAccessService.php';
    require_once __DIR__.'/projection.php';
    $db=ConversationDb::connection();
    $result=f1142ReadOnlyProjection($db); // Exactly one call; approved immutable module owns RO/transaction/rollback.
    if (($result['read_only_rollback']??null)!==true||($result['database_writes']??null)!==0)throw new RuntimeException('rollback');
    $ok=true;
} catch (Throwable $ignored) { $result=null;$ok=false; }
finally {while(ob_get_level()>$level)ob_end_clean();$db=null;}
if (!$ok) {fwrite(STDERR,"audit_process_failed_no_retry\n");exit(1);}
$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if (!is_string($json)||strlen($json)>8192)exit(1);
echo $json;
exit(0); // Discards canonical static aliases; never reuse this PHP process.
