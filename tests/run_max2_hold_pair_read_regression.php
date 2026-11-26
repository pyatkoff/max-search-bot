<?php
declare(strict_types=1);

/** Mandatory synthetic checks for the one fixed historical reader. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$root = dirname(__DIR__);
if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MAX2_HOLD_PDO_SQLITE_REQUIRED');
$files = ['tools/max2_hold_pair_read_20261123/diagnose.php','tools/max2_hold_pair_read_20261123/read-pair.php',
    'tools/max2_hold_pair_read_20261123/program.php','tests/max2_hold_pair_read_20261123/test-diagnose.php',
    'tests/max2_hold_pair_read_20261123/test-read-pair.php'];
foreach ($files as $file) {
    $command = [PHP_BINARY,'-d','log_errors=0','-l',$root.'/'.$file];
    $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('MAX2_HOLD_LINT_START');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if ($code !== 0) throw new RuntimeException('MAX2_HOLD_LINT_FAILED_'.$file);
}
foreach (['test-diagnose.php'=>$root.'/services','test-read-pair.php'=>$root] as $file=>$argument) {
    $disabled='curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail';
    $command=['timeout','45s',PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0','-d','allow_url_fopen=0',
        '-d','allow_url_include=0','-d','disable_functions='.$disabled,$root.'/tests/max2_hold_pair_read_20261123/'.$file,$argument];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('MAX2_HOLD_TEST_START');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    echo $stdout;
    if($code!==0){fwrite(STDERR,$stderr);throw new RuntimeException('MAX2_HOLD_TEST_FAILED_'.$file);}
}
echo "MAX2 original HOLD pair synthetic suites: PASS\n";
