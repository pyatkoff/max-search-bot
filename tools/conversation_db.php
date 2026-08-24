<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$baseDir=dirname(__DIR__); $configFile=$baseDir.'/config.php';
if(!is_file($configFile)){fwrite(STDERR,"ERROR: config.php not found\n");exit(2);}
require_once $configFile;
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/MigrationRunner.php';
$command=$argv[1]??'check';
try {
    if($command==='check'){
        if(!ConversationDb::isConfigured()){fwrite(STDERR,'ERROR: missing config: '.implode(', ',ConversationDb::missingConfig())."\n");exit(2);} $r=ConversationDb::ping();
        echo "CONVERSATION DB CHECK\nRESULT: ".($r['ok']?'OK':'ERROR')."\nDATABASE: {$r['database']}\nHOST: {$r['host']}\nCHARSET: {$r['charset']}\nLATENCY_MS: {$r['latency_ms']}\n"; exit($r['ok']?0:1);
    }
    if($command==='migrate'){
        $result=(new MigrationRunner())->migrate();
        echo "CONVERSATION DB MIGRATION\nRESULT: OK\nDATABASE: ".CONVERSATION_DB_NAME."\n";
        echo 'BASELINED: '.count($result['baselined'])."\n";
        foreach($result['baselined'] as$v)echo "  BASELINE {$v}\n";
        echo 'EXECUTED: '.count($result['executed'])."\n";
        foreach($result['executed'] as$r)echo "  APPLY {$r['version']} {$r['execution_ms']}ms\n";
        echo 'PENDING: '.count($result['pending'])."\n";
        exit(0);
    }
    if($command==='migration-status'){
        $rows=(new MigrationRunner())->status();
        echo "CONVERSATION DB MIGRATION STATUS\n";
        foreach($rows as$r){$state=$r['applied']?($r['checksum_ok']?'APPLIED':'CHECKSUM_MISMATCH'):'PENDING';$base=$r['baseline']?' baseline':'';echo "{$r['version']}: {$state}{$base}".($r['applied_at']?' '.$r['applied_at']:'')."\n";}
        exit(0);
    }
    if($command==='stats'){
        $pdo=ConversationDb::connection(); echo "CONVERSATION DB STATS\n"; foreach(['customers','customer_channels','conversations','messages','managers','manager_assignments','conversation_events']as$table){$count=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();echo strtoupper($table).': '.$count."\n";}exit(0);
    }
    if($command==='recent'){
        $pdo=ConversationDb::connection();$limit=isset($argv[2])?max(1,min(100,(int)$argv[2])):30;$stmt=$pdo->query('SELECT id,conversation_id,direction,sender_type,channel,text,created_at FROM messages ORDER BY id DESC LIMIT '.$limit);$rows=$stmt->fetchAll();echo "RECENT CONVERSATION MESSAGES\nCOUNT: ".count($rows)."\n\n";if(!$rows){echo "No messages recorded yet.\n";exit(0);}foreach($rows as$row){$text=preg_replace('/\s+/u',' ',trim((string)$row['text']));if(function_exists('mb_strlen')&&mb_strlen($text,'UTF-8')>160)$text=mb_substr($text,0,157,'UTF-8').'...';elseif(strlen($text)>160)$text=substr($text,0,157).'...';printf("#%d conv=%d %s %s/%s %s\n  %s\n",$row['id'],$row['conversation_id'],$row['created_at'],$row['channel'],$row['direction'].':'.$row['sender_type'],$text===''?'[no text]':$text,str_repeat('-',60));}exit(0);
    }
    fwrite(STDERR,"Usage: php tools/conversation_db.php [check|migrate|migration-status|stats|recent [limit]]\n");exit(2);
} catch(Throwable $e){fwrite(STDERR,"RESULT: ERROR\nERROR: ".$e->getMessage()."\n");exit(1);}
