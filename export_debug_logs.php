<?php
// CLI-only exporter of recent MAX Search logs to static JSON files.
// Run from cron once per minute.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$baseDir = __DIR__;
require_once $baseDir . '/services/ProjectHealth.php';
require_once $baseDir . '/services/ShadowComparisonReport.php';
require_once $baseDir . '/services/AiRuntimeLogger.php';
require_once $baseDir . '/services/WebhookRuntimeLogger.php';

$outputDir = trim((string)(getenv('MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR') ?: ''));
if ($outputDir === '') {
    $outputDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'max-search-diagnostics';
}
if ($outputDir[0] !== DIRECTORY_SEPARATOR) {
    fwrite(STDERR, "MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR must be absolute\n");
    exit(2);
}
if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Cannot create diagnostics output directory\n");
    exit(2);
}
@chmod($outputDir, 0700);
$resolvedBaseDir = realpath($baseDir);
$resolvedOutputDir = realpath($outputDir);
if ($resolvedBaseDir === false || $resolvedOutputDir === false
    || $resolvedOutputDir === $resolvedBaseDir
    || str_starts_with($resolvedOutputDir, $resolvedBaseDir . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Diagnostics output directory must stay outside the document root\n");
    exit(2);
}

$maxLinesByType = [
    'funnel'=>2500,'tmp'=>500,'cron'=>2500,'ai'=>1200,
    'structured'=>1200,'metrika'=>1200,'metrika_queue'=>1200,
];
$logs = [
    'funnel'=>$baseDir.'/funnel.csv',
    'tmp'=>WebhookRuntimeLogger::inputFile(),
    'cron'=>$baseDir.'/cron_followup.log',
    'ai'=>AiRuntimeLogger::debugFile(),
    'structured'=>$baseDir.'/structured_events.log',
    'metrika'=>$baseDir.'/metrika_events.log',
    'metrika_queue'=>$baseDir.'/metrika_offline_queue.csv',
];
$outputs = [
    'funnel'=>$outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-funnel.json',
    'tmp'=>$outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-tmp.json',
    'cron'=>$outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-cron.json',
    'ai'=>$outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-ai.json',
    'structured'=>$outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-structured.json',
    'metrika'=>$outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-metrika.json',
    'metrika_queue'=>$outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-metrika-queue.json',
];
$shadowComparisonOutput = $outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-shadow-comparison.json';
$conversationOutput = $outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-conversations.json';

function tailLines($file,$maxLines){
    if(!is_file($file)||!is_readable($file))return[];$fh=fopen($file,'rb');if(!$fh)return[];
    fseek($fh,0,SEEK_END);$cursor=ftell($fh);$buffer='';
    while($cursor>0&&substr_count($buffer,"\n")<=$maxLines){$read=min(16384,$cursor);$cursor-=$read;fseek($fh,$cursor);$chunk=fread($fh,$read);if($chunk===false)break;$buffer=$chunk.$buffer;}fclose($fh);
    $lines=preg_split("/\r\n|\n|\r/",$buffer);if($lines&&end($lines)==='')array_pop($lines);if(count($lines)>$maxLines)$lines=array_slice($lines,-$maxLines);return$lines;
}
function redactLine($line){$line=preg_replace('/"(first_name|last_name|name|avatar_url|full_avatar_url)"\s*:\s*"[^"]*"/u','"$1":"[redacted]"',$line);return preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u','[phone-redacted]',$line);}
function redactConversationText($text){
    $text=(string)$text;
    $text=preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u','[phone-redacted]',$text);
    $text=preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu','[email-redacted]',$text);
    return $text;
}
function atomicWriteJson($path,$data){$tmp=$path.'.tmp';$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;if(file_put_contents($tmp,$json,LOCK_EX)===false)return false;@chmod($tmp,0600);return rename($tmp,$path);}

function collectConversationSnapshot($baseDir,$limit=150){
    $result=['ok'=>false,'generated_at'=>date('c'),'channel'=>'max','count'=>0,'messages'=>[]];
    try{
        $config=$baseDir.'/config.php';$service=$baseDir.'/services/ConversationDb.php';
        if(!is_file($config)||!is_file($service)){throw new RuntimeException('conversation_db_files_missing');}
        require_once $config;require_once $service;
        if(!ConversationDb::isConfigured()){throw new RuntimeException('conversation_db_not_configured');}
        $pdo=ConversationDb::connection();$limit=max(1,min(500,(int)$limit));
        $sql='SELECT m.id,m.conversation_id,m.direction,m.sender_type,m.channel,m.text,m.created_at '
            .'FROM messages m WHERE m.channel = \'max\' ORDER BY m.id DESC LIMIT '.$limit;
        $rows=$pdo->query($sql)->fetchAll();
        $rows=array_reverse($rows);
        foreach($rows as$row){
            $result['messages'][]=[
                'id'=>(int)$row['id'],
                'conversation_id'=>(int)$row['conversation_id'],
                'direction'=>(string)$row['direction'],
                'sender_type'=>(string)$row['sender_type'],
                'channel'=>'max',
                'text'=>redactConversationText($row['text']),
                'created_at'=>(string)$row['created_at'],
            ];
        }
        $result['count']=count($result['messages']);$result['ok']=true;
    }catch(Throwable $e){$result['error']=get_class($e).': '.$e->getMessage();}
    return $result;
}

function phpEnvironment(){
    $c=[PHP_BINARY,'/usr/bin/php','/usr/bin/php8.4','/usr/bin/php8.3','/usr/bin/php8.2','/usr/bin/php8.1','/usr/bin/php8.0','/usr/bin/php7.4','/opt/php84/bin/php','/opt/php83/bin/php','/opt/php82/bin/php','/opt/php81/bin/php','/opt/php80/bin/php','/opt/php74/bin/php'];
    foreach(['/opt/php*/bin/php','/opt/php*/usr/bin/php']as$p)foreach((array)glob($p)as$b)$c[]=$b;
    $seen=[];$found=[];foreach($c as$b){if(!$b||isset($seen[$b]))continue;$seen[$b]=1;if(!is_file($b)||!is_executable($b))continue;$o=[];$code=0;exec(escapeshellarg($b).' -r '.escapeshellarg('echo PHP_VERSION;').' 2>&1',$o,$code);$v=trim(implode("\n",$o));if($v!=='')$found[]=['binary'=>$b,'version'=>$v,'exit_code'=>$code];}
    usort($found,function($a,$b){return version_compare($b['version'],$a['version']);});return['current_binary'=>PHP_BINARY,'current_version'=>PHP_VERSION,'available'=>$found];
}
function selectRegressionPhp($env){foreach((array)($env['available']??[])as$r)if(!empty($r['binary'])&&!empty($r['version'])&&version_compare($r['version'],'8.2.0','>='))return$r['binary'];foreach((array)($env['available']??[])as$r)if(!empty($r['binary'])&&!empty($r['version'])&&version_compare($r['version'],'7.4.0','>='))return$r['binary'];return PHP_BINARY;}
function runSuite($baseDir,$phpBinary,$relative){
    $file=$baseDir.'/'.$relative;if(!is_file($file)||!is_readable($file))return['ok'=>false,'php_binary'=>$phpBinary,'exit_code'=>127,'total'=>null,'passed'=>null,'failed'=>null,'error'=>'test_file_not_found_or_unreadable','output'=>[]];
    $out=[];$code=0;exec(escapeshellarg($phpBinary).' '.escapeshellarg($file).' 2>&1',$out,$code);$total=$passed=$failed=null;
    foreach(array_reverse($out)as$line)if(preg_match('/TOTAL\s+(\d+)\s+\|\s+PASS\s+(\d+)\s+\|\s+FAIL\s+(\d+)/',$line,$m)){$total=(int)$m[1];$passed=(int)$m[2];$failed=(int)$m[3];break;}
    return['ok'=>($code===0&&$failed===0),'php_binary'=>$phpBinary,'exit_code'=>$code,'total'=>$total,'passed'=>$passed,'failed'=>$failed,'output'=>array_slice($out,-80)];
}

$phpEnv=phpEnvironment();$regressionPhp=selectRegressionPhp($phpEnv);
$manifest=['ok'=>true,'generated_at'=>date('c'),'php'=>$phpEnv,'health'=>ProjectHealth::collect($baseDir),'max_lines_by_type'=>$maxLinesByType,'logs'=>[],'tests'=>[],'reports'=>[]];
foreach($logs as$type=>$file){$max=$maxLinesByType[$type]??1000;$entry=['ok'=>false,'type'=>$type,'source'=>basename($file),'generated_at'=>date('c'),'max_lines'=>$max,'lines'=>[]];
    if(is_file($file)&&is_readable($file)){$lines=tailLines($file,$max);foreach($lines as&$line)$line=redactLine($line);unset($line);$entry['ok']=true;$entry['size_bytes']=filesize($file);$entry['mtime']=date('c',filemtime($file));$entry['count']=count($lines);$entry['lines']=$lines;}else{$entry['error']='file_not_found_or_unreadable';}
    atomicWriteJson($outputs[$type],$entry);$manifest['logs'][$type]=['ok'=>$entry['ok'],'source'=>$entry['source'],'count'=>$entry['count']??0,'max_lines'=>$max,'file'=>basename($outputs[$type])];
}

$conversationSnapshot=collectConversationSnapshot($baseDir,150);
atomicWriteJson($conversationOutput,$conversationSnapshot);
$manifest['reports']['conversations']=[
    'ok'=>$conversationSnapshot['ok'],
    'channel'=>'max',
    'count'=>$conversationSnapshot['count'],
    'file'=>basename($conversationOutput),
];

$comparison = ShadowComparisonReport::build($baseDir.'/structured_events.log');
atomicWriteJson($shadowComparisonOutput, $comparison);
$manifest['reports']['shadow_comparison'] = [
    'ok'=>true,
    'file'=>basename($shadowComparisonOutput),
    'paired_messages'=>$comparison['summary']['paired_messages'] ?? 0,
    'agreement_pct'=>$comparison['summary']['agreement_pct'] ?? null,
    'different_action'=>$comparison['summary']['different_action'] ?? 0,
];

$manifest['tests']['conversation_regression']=runSuite($baseDir,$regressionPhp,'tests/run_conversation_regression.php');
$manifest['tests']['conversation_catalog']=runSuite($baseDir,$regressionPhp,'tests/run_conversation_catalog.php');
foreach($manifest['tests']as$t)if(!$t['ok'])$manifest['ok']=false;
atomicWriteJson($outputDir.'/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-index.json',$manifest);
echo 'OK '.date('c').PHP_EOL;
