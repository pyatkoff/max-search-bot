<?php
declare(strict_types=1);

/** The same canonical service cases run on SQLite and native MySQL. No provider or customer I/O. */
function preparedInputCases(): void
{
    $pdo=preparedReplyFixture();$before=preparedReplySourceState($pdo);$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);
    $packet=ManagerPreparedReplyService::context($job);$images=$packet['image_inputs'];unset($packet['image_inputs']);
    $packet['context']['selection_snapshots']=[['observation'=>'Synthetic A — unverified']];
    $packet['instructions'].="\nExact A: «даты те же» 🧳 e\u{0301}";
    $packetB=$packet;$packetB['context']['selection_snapshots'][0]['observation']='Synthetic B — unverified';$packetB['instructions'].="\nB";
    $authorize=static function():void{};$conn=str_repeat('a',32);
    $issue=static fn($p,$im=[])=>ManagerPreparedReplyService::issueInput($p,$im,1,$conn,$authorize);
    $a=$issue($packet)['capture'];$b=$issue($packetB)['capture'];
    preparedReplyCheck('two same-revision reads retain separate exact packets',!empty($a['captured'])&&!empty($b['captured'])&&$a['receipt_id']!==$b['receipt_id']&&$a['packet_sha256']!==$b['packet_sha256']);
    $ea=ManagerPreparedInputService::receipt($pdo,$a['receipt_id'],$pdo->query('SELECT * FROM manager_prepared_replies')->fetch(),1,$conn);
    preparedReplyCheck('receipt A preserves exact observations instructions and Unicode', $ea&&$ea->packet()===$packet&&$ea->input()->instructionHash()===hash('sha256',$packet['instructions']));
    $image=[['type'=>'input_image','image_url'=>'data:image/png;base64,U1lOVEhFVElD','detail'=>'high']];
    $ei=ManagerPreparedInputEvidence::capture($packet,$image);$value=json_decode($ei->json(),true);
    preparedReplyCheck('image manifest hashes normalized data URL and omits raw bytes',$value['coverage']==='images_omitted'&&$value['images'][0]['payload_sha256']===hash('sha256',$image[0]['image_url'])&&!str_contains($ei->json(),'U1lOVEhFVElD'));
    $bad=json_decode($ei->json(),true);$bad['external_model_input']='verified';
    preparedReplyCheck('receipt envelope cannot promote external model input to verified',ManagerPreparedInputEvidence::fromJson(json_encode($bad,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))===null);
    preparedReplyCheck('noncanonical JSON and malformed oversized envelopes are rejected',ManagerPreparedInputEvidence::fromJson(' '.$ei->json())===null&&ManagerPreparedInputEvidence::fromJson(str_repeat('x',ManagerPreparedInputEvidence::MAX_BYTES+1))===null&&ManagerPreparedInputEvidence::capture(['instructions'=>42])===null);
    foreach([[2,$conn], [1,str_repeat('b',32)]] as [$actor,$connection]){
        $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(),$a['receipt_id'],$actor,$connection);
        preparedReplyCheck('foreign actor or connection cannot bind issued input '.$actor.'/'.$connection[0],($r['error']??null)==='invalid_input_receipt'&&$pdo->query('SELECT status FROM manager_prepared_replies')->fetchColumn()==='pending');
    }
    $otherJob=hash('sha256','synthetic other job same context');
    $pdo->prepare("INSERT INTO manager_prepared_replies(job_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,status,created_at,expires_at) SELECT ?,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,status,created_at,expires_at FROM manager_prepared_replies WHERE job_id=?")->execute([$otherJob,$job]);
    $wrong=ManagerPreparedReplyService::complete($otherJob,$snap['revision'],preparedReplyProposal(),$a['receipt_id'],1,$conn);
    preparedReplyCheck('same-revision foreign job cannot borrow an issued receipt',($wrong['error']??null)==='invalid_input_receipt');
    $pdo->prepare('DELETE FROM manager_prepared_replies WHERE job_id=?')->execute([$otherJob]);
    $wrong=$packet;$wrong['company_knowledge']['revision']=str_repeat('f',64);
    preparedReplyCheck('changed knowledge revision cannot be issued as current',empty(ManagerPreparedReplyService::issueInput($wrong,[],1,$conn,$authorize)['ok']));
    $wrong=$packet;$wrong['revision']=str_repeat('f',64);
    preparedReplyCheck('wrong context revision cannot create a usable receipt',empty(ManagerPreparedReplyService::issueInput($wrong,[],1,$conn,$authorize)['ok']));
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(),str_repeat('f',64),1,$conn);
    preparedReplyCheck('unknown receipt cannot complete a proposal',($r['error']??null)==='invalid_input_receipt');
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(),$a['receipt_id'],1,$conn);
    preparedReplyCheck('save binds explicitly selected A after later read B',!empty($r['ok'])&&!$r['idempotent']&&ManagerPreparedInputService::linked($pdo,$job)===$a['receipt_id']);
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(),$a['receipt_id'],1,$conn);
    preparedReplyCheck('identical proposal receipt retry retains immutable pair',!empty($r['ok'])&&$r['idempotent']&&ManagerPreparedInputService::linked($pdo,$job)===$a['receipt_id']);
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(),$b['receipt_id'],1,$conn);
    preparedReplyCheck('same proposal cannot replace original receipt with later read',($r['error']??null)==='completion_conflict'&&ManagerPreparedInputService::linked($pdo,$job)===$a['receipt_id']);
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(['draft'=>'Другой ответ']),$a['receipt_id'],1,$conn);
    preparedReplyCheck('altered proposal cannot replace original pair',($r['error']??null)==='completion_conflict');
    $row=$pdo->query('SELECT * FROM manager_prepared_replies')->fetch();
    $draft=['preparation_job_id'=>$job,'project_key'=>'anytour','conversation_id'=>101,'latest_message_id'=>2,'proposal_json'=>$row['proposal_json'],'instruction_revision'=>$row['instruction_revision']];
    $read=ManagerPreparedInputService::read($pdo,$draft);
    preparedReplyCheck('owner packet readback is server-issued with unknown full model input',$read['available']&&$read['prepared_packet']===$packet&&$read['external_model_input']==='unknown'&&$read['provenance']==='server_issued_context');
    $draft['project_key']='other';preparedReplyCheck('wrong project draft cannot expose linked input',(ManagerPreparedInputService::read($pdo,$draft)['reason']??'')==='invalid_evidence');
    preparedReplyCheck('receipt workflow changes no transcript task read or shift state',preparedReplySourceState($pdo)===$before&&$pdo->sourceWriteAttempts===0);

    $pdo=preparedReplyFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);$packet=ManagerPreparedReplyService::context($job);unset($packet['image_inputs']);
    $issue=static fn()=>ManagerPreparedReplyService::issueInput($packet,[],1,$conn,$authorize);
    for($n=0;$n<ManagerPreparedInputService::MAX_PER_JOB;$n++)$issue();
    preparedReplyCheck('receipt issuance is bounded per job without choosing another input',($issue()['capture']['reason']??'')==='receipt_limit'&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn()===ManagerPreparedInputService::MAX_PER_JOB);
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal());
    preparedReplyCheck('legacy save remains usable with explicitly uncaptured input',!empty($r['ok'])&&$r['input_provenance']==='not_captured'&&ManagerPreparedInputService::linked($pdo,$job)===null);
    $draft=['preparation_job_id'=>$job];preparedReplyCheck('unlinked issued receipts are never implicitly selected',(ManagerPreparedInputService::read($pdo,$draft)['reason']??'')==='prepared_input_not_captured');

    $pdo=preparedReplyFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);
    $pdo->guard=false;$pdo->exec('DROP TABLE manager_prepared_reply_input_links');$pdo->exec('DROP TABLE manager_prepared_reply_inputs');$pdo->guard=true;
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal());
    preparedReplyCheck('optional audit storage outage preserves legacy pending save',!empty($r['ok'])&&$r['input_provenance']==='not_captured'&&$pdo->query('SELECT status FROM manager_prepared_replies')->fetchColumn()==='ready');
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal());
    preparedReplyCheck('legacy retry never replaces original evidence during audit outage',!empty($r['ok'])&&$r['idempotent']&&$r['input_provenance']==='unknown');
    $pdo=preparedReplyFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);$packet=ManagerPreparedReplyService::context($job);unset($packet['image_inputs']);
    $a=ManagerPreparedReplyService::issueInput($packet,[],1,$conn,$authorize)['capture'];
    $pdo->beforeReceiptLink=static function():void{throw new RuntimeException('synthetic link failure');};
    $r=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(),$a['receipt_id'],1,$conn);
    preparedReplyCheck('link storage failure rolls back proposal and receipt association',empty($r['ok'])&&$pdo->query('SELECT status FROM manager_prepared_replies')->fetchColumn()==='pending'&&ManagerPreparedInputService::linked($pdo,$job)===null);
    $pdo->mutate("UPDATE messages SET text='Изменены даты' WHERE id=2");
    preparedReplyCheck('edited source rejects stale receipt completion',(ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal(),$a['receipt_id'],1,$conn)['error']??'')==='stale_context');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn();
    preparedReplyCheck('stale issuance does not record reconstructed current input',empty(ManagerPreparedReplyService::issueInput($packet,[],1,$conn,$authorize)['ok'])&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn()===$count);
}

function preparedInputMcpCases(): void
{
    require_once dirname(__DIR__,2).'/services/ManagerPreparationMcpService.php';
    $pdo=preparedReplyFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);
    $packet=ManagerPreparedReplyService::context($job);$packet['image_inputs']=[['type'=>'input_image','image_url'=>'data:image/png;base64,U1lOVEhFVElD','detail'=>'high']];
    $live=true;$issues=0;$conn=str_repeat('a',32);
    $store=static function($op,$args)use(&$packet,&$issues){
        if($op==='context')return $packet;
        if($op==='issueInput')++$issues;
        return call_user_func_array([ManagerPreparedReplyService::class,$op],$args);
    };
    $service=new ManagerPreparationMcpService($pdo,$store,connectionActive:static function()use(&$live){return $live;});
    $rpc=['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'get_reply_context','arguments'=>['job_id'=>$job]]];
    $deferred=$service->handle($rpc,2,$conn,null,null,true);
    preparedReplyCheck('deferred context is packaged without premature audit issuance',isset($deferred['result']['content'][1])&&$issues===0&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn()===0);
    $denied=false;
    try{$service->finalizePreparedContext($deferred,2,$conn,static function(){throw new DomainException('synthetic outer revocation');});}catch(DomainException $e){$denied=true;}
    preparedReplyCheck('outer authorization revocation leaves no usable or exposed receipt',$denied&&$issues===0&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn()===0);
    $response=$service->handle($rpc,2,$conn);$data=$response['result']['structuredContent'];
    preparedReplyCheck('wire context includes one explicit private receipt and matching text content',isset($data['input_receipt'])&&$data['input_capture']['captured']&&json_decode($response['result']['content'][0]['text'],true)===$data&&$issues===1);
    $q=$pdo->query('SELECT packet_json FROM manager_prepared_reply_inputs');$e=ManagerPreparedInputEvidence::fromJson($q->fetchColumn());
    preparedReplyCheck('MCP image transformation preserves exact manifest semantics',$e&&json_decode($e->input()->json(),true)['images'][0]['payload_sha256']===hash('sha256',$packet['image_inputs'][0]['image_url'])&&$response['result']['content'][1]===['type'=>'image','mimeType'=>'image/png','data'=>'U1lOVEhFVElD']);
    $defs=$service->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],2,$conn)['result']['tools'];$defs=array_column($defs,null,'name');
    preparedReplyCheck('tool metadata declares audit writes and optional explicit receipt',$defs['get_reply_context']['annotations']['readOnlyHint']===false&&$defs['get_reply_context']['annotations']['idempotentHint']===false&&isset($defs['save_prepared_reply']['inputSchema']['properties']['input_receipt'])&&!in_array('input_receipt',$defs['save_prepared_reply']['inputSchema']['required'],true));
    $bad=$packet;$packet['image_inputs'][0]['image_url']='invalid';$response=$service->handle($rpc,2,$conn);$packet=$bad;
    preparedReplyCheck('invalid image packaging never persists a receipt',isset($response['error'])&&$issues===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn()===1);
    $save=$rpc;$save['params']=['name'=>'save_prepared_reply','arguments'=>['job_id'=>$job,'revision'=>$snap['revision'],'proposal'=>preparedReplyProposal(),'input_receipt'=>$data['input_receipt']]];
    $result=$service->handle($save,2,$conn)['result']['structuredContent'];
    preparedReplyCheck('MCP save passes authenticated receipt binding to canonical completion',!empty($result['ok'])&&$result['input_provenance']==='server_issued_context'&&ManagerPreparedInputService::linked($pdo,$job)===$data['input_receipt']);
    $save['params']['arguments']['input_receipt']=null;
    preparedReplyCheck('explicit null receipt cannot silently become a legacy save',($service->handle($save,2,$conn)['error']['code']??0)===-32602);
    $pdo=preparedReplyFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);$packet=ManagerPreparedReplyService::context($job);$live=true;
    $store=static function($op,$args)use(&$live,$packet){if($op==='context')return $packet;$r=call_user_func_array([ManagerPreparedReplyService::class,$op],$args);if($op==='issueInput')$live=false;return $r;};
    $service=new ManagerPreparationMcpService($pdo,$store,connectionActive:static function()use(&$live){return $live;});
    $rpc['params']['arguments']['job_id']=$job;$denied=$service->handle($rpc,2,$conn);
    preparedReplyCheck('revocation during issuance withholds the entire private response',($denied['error']['code']??0)===-32001&&!isset($denied['result'])&&!str_contains(json_encode($denied),'instructions')&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn()===1);
    $privateReceipt=$pdo->query('SELECT receipt_id FROM manager_prepared_reply_inputs')->fetchColumn();
    $save['params']['arguments']=['job_id'=>$job,'revision'=>$snap['revision'],'proposal'=>preparedReplyProposal(),'input_receipt'=>$privateReceipt];
    preparedReplyCheck('unexposed receipt gives a revoked connection no completion authority',($service->handle($save,2,$conn)['error']['code']??0)===-32001&&ManagerPreparedInputService::linked($pdo,$job)===null&&$pdo->query('SELECT status FROM manager_prepared_replies')->fetchColumn()==='pending');

}

function preparedInputNativeRace(): void
{
    if(!PreparedReplyPdo::$mysqlFixture)return;
    $pdo=preparedReplyFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);$p=ManagerPreparedReplyService::context($job);unset($p['image_inputs']);
    $issue=static fn()=>ManagerPreparedReplyService::issueInput($p,[],1,str_repeat('a',32),static function(){});
    $a=$issue()['capture']['receipt_id'];$b=$issue()['capture']['receipt_id'];
    $dir=sys_get_temp_dir().'/prepared-input-race-'.bin2hex(random_bytes(8));mkdir($dir,0700);$children=[];
    try{
        $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM manager_prepared_replies WHERE job_id=? FOR UPDATE');$q->execute([$job]);$q->fetch();$q->closeCursor();
        foreach(['a'=>$a,'b'=>$b] as $name=>$id){
            $process=proc_open([PHP_BINARY,__DIR__.'/prepared-input-child.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('FAIL native receipt process spawn');
            fwrite($pipes[0],json_encode(['job'=>$job,'revision'=>$snap['revision'],'proposal'=>preparedReplyProposal(),'receipt'=>$id,'signal'=>$dir.'/'.$name],JSON_THROW_ON_ERROR));fclose($pipes[0]);
            stream_set_timeout($pipes[1],12);stream_set_timeout($pipes[2],12);$children[]=[$process,$pipes];
        }
        $until=microtime(true)+8;while((!file_exists($dir.'/a')||!file_exists($dir.'/b'))&&microtime(true)<$until)usleep(10000);
        if(!file_exists($dir.'/a')||!file_exists($dir.'/b'))throw new RuntimeException('FAIL native receipt processes did not contend');
        $pdo->commit();$results=[];
        foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0||$err!=='')throw new RuntimeException('FAIL native receipt child');$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}
        $children=[];$wins=array_values(array_filter($results,static fn($r)=>!empty($r['ok'])));$losses=array_values(array_filter($results,static fn($r)=>($r['error']??'')==='completion_conflict'));
        preparedReplyCheck('native two-process completions preserve exactly one proposal-receipt pair',count($wins)===1&&count($losses)===1&&in_array(ManagerPreparedInputService::linked($pdo,$job),[$a,$b],true)&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_input_links')->fetchColumn()===1);
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();foreach($children as [$process,$pipes]){foreach([1,2]as $n)if(is_resource($pipes[$n]))fclose($pipes[$n]);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
        foreach(['a','b']as $name)if(file_exists($dir.'/'.$name))unlink($dir.'/'.$name);rmdir($dir);
    }
}
