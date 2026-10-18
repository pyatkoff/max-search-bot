<?php

declare(strict_types=1);

// One owner-authorized, SELECT-only source/read probe. No provider or send path.
$root='/var/www/anytoour/data/www/app.anytoour.ru';
$phase='source';
ob_start(static fn(string $buffer): string=>'');
function selectionProbeHead(string $root): string {
    $p=proc_open(['git','-C',$root,'rev-parse','HEAD'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('source');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
    if (proc_close($p)!==0) throw new RuntimeException('source');
    return trim($out);
}
try {
    $expected=$argv[1]??'';$candidate=$argv[2]??'';
    if (!preg_match('/\A[a-f0-9]{40}\z/D',$expected) || !preg_match('/\A[a-f0-9]{40}\z/D',$candidate)
        || selectionProbeHead($root)!==$expected) throw new RuntimeException('source');
    $installed=($argv[3]??'')==='installed';
    if (($argv[3]??'')!=='' && !$installed) throw new RuntimeException('mode');
    if ($installed) foreach (['ManagerSelectionSnapshot.php','ManagerSelectionPageParser.php','ManagerSelectionLinks.php','ManagerAiTextRedactor.php'] as $file) {
        if (!is_file($root.'/services/'.$file) || !hash_equals(hash_file('sha256',__DIR__.'/'.$file),hash_file('sha256',$root.'/services/'.$file))) throw new RuntimeException('installed_source');
    }
    require_once ($installed?$root.'/services':__DIR__).'/ManagerSelectionSnapshot.php';
    require_once $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/ProjectConfig.php';
    $phase='scope';
    if (ProjectConfig::projectId()!=='anytour') throw new RuntimeException('scope');
    $pdo=ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $pdo->inTransaction()) throw new RuntimeException('storage');
    $phase='read';
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $q=$pdo->prepare("SELECT m.id,m.conversation_id,m.sender_type,m.text,m.created_at FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.project_key=? AND m.sender_type='manager' AND m.text LIKE '%qui-quo.ru/%' AND CHAR_LENGTH(m.text)<=40000 ORDER BY m.id DESC LIMIT 20");
    $q->execute(['anytour']);$rows=$q->fetchAll();$pdo->commit();
    $before=null;
    foreach ($rows as $row) {
        $links=ManagerSelectionLinks::inMessage((string)$row['text'],(int)$row['id'],'manager',(string)$row['created_at']);
        if (!array_filter($links,static fn($l)=>$l['url']!==null)) continue;
        $unread=false;$redacted=false;
        $text=ManagerAiTextRedactor::redact((string)$row['text'],$unread,$redacted);
        $before=['context'=>['conversation_id'=>(int)$row['conversation_id'],'as_of_message_id'=>(int)$row['id'],
            'messages'=>[['id'=>(int)$row['id'],'sender_type'=>'manager','text'=>$text,'created_at'=>(string)$row['created_at']]]],'selection_sources'=>$links];
        break;
    }
    $phase='selection';$start=hrtime(true);
    $data=$before ? ManagerSelectionSnapshot::read($before)->forContext($before['context']) : ['selection_snapshots'=>[]];
    $elapsed=round((hrtime(true)-$start)/1000000,1);
    $phase='final_source';
    if (selectionProbeHead($root)!==$expected) throw new RuntimeException('source_changed');
    $report=['ok'=>true,'candidate_sha'=>$candidate,'runtime_sha'=>$expected,'installed_modules'=>$installed,'source_rows_inspected'=>count($rows),
        'source_found'=>$before!==null,'pages_attempted'=>count($data['selection_snapshots']),
        'states'=>array_column($data['selection_snapshots'],'state'),'http_statuses'=>array_column($data['selection_snapshots'],'http_status'),
        'offer_counts'=>array_column($data['selection_snapshots'],'offer_count'),
        'returned_offer_counts'=>array_map(static fn($s)=>count($s['offers']),$data['selection_snapshots']),
        'truncated'=>array_column($data['selection_snapshots'],'offers_truncated'),'elapsed_ms'=>$elapsed,
        'provider_calls'=>0,'business_writes'=>0];
    ob_end_clean();echo json_encode($report,JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $ignored) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();echo json_encode(['ok'=>false,'phase'=>$phase],JSON_THROW_ON_ERROR),PHP_EOL;
    exit(1);
}
