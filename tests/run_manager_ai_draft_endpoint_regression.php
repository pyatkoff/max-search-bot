<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__.'/fixtures/manager_ai_draft_http_fixture.php';

// Execute unmodified endpoint/HTTP/session source in a temporary app. No real
// bootstrap, configuration, database, model or customer transport is loaded.
$root = dirname(__DIR__);
$fixture = __DIR__.'/fixtures/manager_ai_draft_http_fixture.php';
$tmp = sys_get_temp_dir().'/manager-ai-http-'.bin2hex(random_bytes(6));
$server = null;
$children = [];
$passed = 0;

function managerAiEndpointCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name."\n";
}

try {
    foreach ([$tmp,$tmp.'/manager',$tmp.'/manager/lib',$tmp.'/services',$tmp.'/sessions'] as $directory) mkdir($directory,0700);
    $loader = "<?php\nrequire_once ".var_export($fixture,true).";\n";
    file_put_contents($tmp.'/config.php', $loader.'$GLOBALS["manager_ai_http_fixture_directory"] = '.var_export($tmp,true).";\n");
    foreach (['ManagerAuthService','ManagerConversationAccessPolicy','ManagerAiDraftContextService','ManagerAiDraftService','ManagerAiDraftFeedbackService','ManagerPreparedReplyService'] as $service) file_put_contents($tmp.'/services/'.$service.'.php',$loader);
    foreach (['manager/ai-draft.php','manager/lib/ManagerHttp.php','services/ManagerRequestContext.php'] as $source) copy($root.'/'.$source,$tmp.'/'.$source);
    managerAiEndpointCheck('isolated app executes exact current endpoint bytes',hash_file('sha256',$root.'/manager/ai-draft.php') === hash_file('sha256',$tmp.'/manager/ai-draft.php'));
    file_put_contents($tmp.'/manager/fixture-session.php', '<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=7;$_SESSION["csrf"]="synthetic-csrf";ManagerHttp::respond(["ok"=>true]);');
    $proposal = ['ok'=>true,'should_draft'=>true,'draft'=>'Здравствуйте! Какие даты вылета вам подходят?',
        'next_action'=>'clarify','reason'=>'Нужно уточнить даты.','checks'=>[],
        'evidence_message_ids'=>[10],'history_incomplete'=>false];
    $baseState = ['active'=>true,'access'=>true,'context_error'=>'','latest_message_id'=>10,
        'revision'=>hash('sha256','synthetic-revision-1'),'provider_mode'=>'normal','proposal'=>$proposal];
    $writeState = static function(array $changes = []) use ($tmp,$baseState): array {
        $state = array_replace($baseState,$changes);
        file_put_contents($tmp.'/state.json',json_encode($state),LOCK_EX);
        return $state;
    };
    $clearEvents = static function() use ($tmp): void { file_put_contents($tmp.'/events.jsonl',''); };
    $events = static function() use ($tmp): array {
        return array_map(static fn(string $line): array=>json_decode($line,true),file($tmp.'/events.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: []);
    };
    $writeState(); $clearEvents();
    $socket = stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$socket) throw new RuntimeException('Cannot allocate loopback fixture');
    $address = (string) stream_socket_get_name($socket,false); fclose($socket);
    $server = proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-d','display_errors=0','-S',$address,'-t',$tmp],
        [0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
    if (!is_resource($server)) throw new RuntimeException('Cannot start loopback fixture');
    fclose($pipes[0]);
    $ready = false;
    for ($i=0;$i<100;$i++) {
        if ($probe = @stream_socket_client('tcp://'.$address,$e,$s,.05)) { fclose($probe); $ready = true; break; }
        usleep(10000);
    }
    if (!$ready) throw new RuntimeException('Loopback fixture did not start');
    $request = static fn(array $data = [],string $cookie = '',string $method = 'POST'): array=>managerAiHttpFixtureRequest($address,'/manager/ai-draft.php',$data,$cookie,$method);
    $login = static fn(): string=>managerAiHttpFixtureRequest($address,'/manager/fixture-session.php',[],'','GET')['cookie'];
    $body = ['action'=>'generate','csrf'=>'synthetic-csrf','conversation_id'=>42,'latest_message_id'=>10];
    $expectError = static function(string $name,array $response,int $status,string $error): void {
        managerAiEndpointCheck($name,$response['status'] === $status && $response['json'] === ['ok'=>false,'error'=>$error]);
    };
    $assertNoProvider = static function(string $name) use ($events): void {
        managerAiEndpointCheck($name,!in_array('provider',array_column($events(),'event'),true));
    };
    foreach (['GET','PUT','DELETE'] as $method) {
        $clearEvents(); $expectError($method.' cannot invoke drafting',$request([], '', $method),405,'method_not_allowed');
        managerAiEndpointCheck($method.' rejects before auth and context',$events() === []);
    }
    $clearEvents(); $expectError('anonymous POST is unauthorized',$request($body),401,'unauthorized');
    $assertNoProvider('anonymous POST never calls provider');
    $cookie = $login(); managerAiEndpointCheck('uses real canonical manager session cookie',$cookie !== '');
    foreach (['', 'wrong-csrf'] as $csrf) {
        $clearEvents(); $expectError('missing or invalid CSRF is forbidden',$request(array_replace($body,['csrf'=>$csrf]),$cookie),403,'csrf');
        managerAiEndpointCheck('CSRF rejection precedes context lookup',!in_array('snapshot',array_column($events(),'event'),true));
    }
    $clearEvents(); $expectError('unknown action rejected',$request(array_replace($body,['action'=>'send']),$cookie),400,'unknown_action');
    $assertNoProvider('unknown action never calls provider');
    foreach (['conversation_id'=>[null,0,-1,'42',42.5,true,[]], 'latest_message_id'=>[null,-1,'10',10.5,true,[]]] as $field=>$values) {
        foreach ($values as $value) {
            $clearEvents(); $response = $request(array_replace($body,[$field=>$value]),$cookie);
            $expectError('strict '.$field.' type '.json_encode($value),$response,400,'invalid_request');
            $assertNoProvider('invalid request does not invoke provider');
        }
    }
    $clearEvents(); $expectError('unknown conversation remains 404',$request(array_replace($body,['conversation_id'=>404]),$cookie),404,'not_found');
    $assertNoProvider('missing conversation does not invoke provider');
    foreach (['forbidden'=>403,'reply_not_allowed'=>403,'suspended'=>409,'context_unavailable'=>503] as $errorCode=>$status) {
        $writeState(['context_error'=>$errorCode]); $clearEvents();
        $expectError('snapshot '.$errorCode.' fails closed',$request($body,$cookie),$status,$errorCode);
        $assertNoProvider('snapshot error does not invoke provider');
    }
    $writeState(); $clearEvents();
    $expectError('latest message mismatch rejects before generation',$request(array_replace($body,['latest_message_id'=>9]),$cookie),409,'context_stale');
    $assertNoProvider('latest mismatch does not invoke provider');
    $prepared=array_replace($body,['action'=>'prepared']);
    $writeState();$clearEvents();
    $missingPrepared=$request($prepared,$cookie);
    managerAiEndpointCheck('prepared cache miss is explicit and never generates',
        $missingPrepared['status']===200&&$missingPrepared['json']===['ok'=>true,'prepared'=>false]
        &&in_array('prepared_read',array_column($events(),'event'),true));
    $assertNoProvider('prepared cache miss has zero provider calls');
    managerAiEndpointCheck('prepared lookup makes no feedback observation',
        !array_intersect(['feedback_record','feedback_insert_request'],array_column($events(),'event')));
    $cached=$proposal+['prepared'=>true,'revision'=>$baseState['revision'],'latest_message_id'=>10,'source'=>'chatgpt','prepared_at'=>'2026-10-19 10:00:00'];
    $stored=array_diff_key($cached,$proposal)+['ok'=>true,'proposal'=>$proposal];
    $writeState(['prepared_result'=>$stored]);$clearEvents();
    $ready=$request($prepared,$cookie);
    managerAiEndpointCheck('prepared action returns only current owner cache result',
        $ready['status']===200&&$ready['json']===$cached);
    $assertNoProvider('prepared hit has zero provider calls');
    $statusRequest=array_replace($body,['action'=>'prepared_status']);
    $statusStored=$stored+['expires_at'=>'2026-10-19 11:00:00','preparation_source'=>'chatgpt_assistant','job_id'=>str_repeat('a',64)];
    $writeState(['prepared_result'=>$statusStored]);$clearEvents();
    $readyStatus=$request($statusRequest,$cookie);
    managerAiEndpointCheck('readiness returns current metadata without proposal, job ID or customer text',
        $readyStatus['status']===200&&$readyStatus['json']===['ok'=>true,'prepared'=>true,'should_draft'=>true,
            'revision'=>$baseState['revision'],'latest_message_id'=>10,'prepared_at'=>'2026-10-19 10:00:00',
            'expires_at'=>'2026-10-19 11:00:00','preparation_source'=>'chatgpt_assistant']);
    $assertNoProvider('automatic readiness never calls the provider');
    $statusStored['proposal']['should_draft']=false;$statusStored['proposal']['draft']=null;
    $writeState(['prepared_result'=>$statusStored]);
    managerAiEndpointCheck('readiness distinguishes recommendations without a customer draft',$request($statusRequest,$cookie)['json']['should_draft']===false);
    $writeState(['context_error'=>'reply_not_allowed']);$clearEvents();
    $expectError('readiness keeps strict current reply ownership',$request($statusRequest,$cookie),403,'reply_not_allowed');
    managerAiEndpointCheck('forbidden readiness never reads cache',!in_array('prepared_read',array_column($events(),'event'),true));
    $writeState(['prepared_result'=>$stored]);$clearEvents();$request($prepared,$cookie);
    $readEvents=array_values(array_filter($events(),static fn(array $e):bool=>$e['event']==='prepared_read'));
    managerAiEndpointCheck('prepared cache receives session actor and released session',
        count($readEvents)===1&&$readEvents[0]['manager_id']===7&&$readEvents[0]['session_closed']===true);
    $writeState(['context_error'=>'reply_not_allowed']);$clearEvents();
    $expectError('reassigned prepared conversation is refused before cache',$request($prepared,$cookie),403,'reply_not_allowed');
    managerAiEndpointCheck('unauthorized prepared lookup does not reach cache',!in_array('prepared_read',array_column($events(),'event'),true));
    $writeState();$clearEvents();
    $expectError('prepared request also checks latest message',$request(array_replace($prepared,['latest_message_id'=>9]),$cookie),409,'context_stale');
    managerAiEndpointCheck('stale prepared request does not reach cache',!in_array('prepared_read',array_column($events(),'event'),true));
    $writeState();
    $validate = array_replace($body,['action'=>'validate','revision'=>$baseState['revision']]);
    foreach ([null,'',str_repeat('g',64),str_repeat('a',63),17,[]] as $revision) {
        $clearEvents(); $expectError('validate revision syntax '.json_encode($revision),$request(array_replace($validate,['revision'=>$revision]),$cookie),400,'invalid_request');
        $assertNoProvider('invalid validate revision never invokes provider');
    }
    $clearEvents(); $expectError('validate rejects stale content revision',$request(array_replace($validate,['revision'=>hash('sha256','old')]),$cookie),409,'context_stale');
    $assertNoProvider('stale validate revision never invokes provider');
    $clearEvents(); $valid = $request($validate,$cookie);
    managerAiEndpointCheck('legacy validate passes current revision without generating or feedback attribution',$valid['status'] === 200 && $valid['json'] === ['ok'=>true,'revision'=>$baseState['revision'],'latest_message_id'=>10,'draft_id'=>null]
        && !in_array('feedback_insert_request',array_column($events(),'event'),true));
    $assertNoProvider('valid validate has zero provider calls');
    foreach (['',str_repeat('g',32),str_repeat('d',31),5,[]] as $id) {
        $clearEvents(); $expectError('invalid draft identity is rejected before feedback',$request($validate+['draft_id'=>$id],$cookie),400,'invalid_request');
        managerAiEndpointCheck('invalid identity creates no feedback observation',!in_array('feedback_insert_request',array_column($events(),'event'),true));
    }
    $clearEvents(); $tracked = $request($validate+['draft_id'=>str_repeat('d',32),'manager_id'=>8],$cookie);
    managerAiEndpointCheck('explicit validate observes only authenticated insertion request',$tracked['status']===200 && $tracked['json']['draft_id']===str_repeat('d',32)
        && (array_reverse($events())[0]??null)===['event'=>'feedback_insert_request','conversation_id'=>42,'manager_id'=>7,'draft_id'=>str_repeat('d',32),'revision'=>$baseState['revision'],'latest_message_id'=>10]);
    $clearEvents(); $untracked=$request($validate+['draft_id'=>str_repeat('e',32)],$cookie);
    managerAiEndpointCheck('unbound identity is not echoed as attribution',$untracked['status']===200 && $untracked['json']['draft_id']===null);
    $clearEvents(); $expectError('stale insert request never records feedback',$request(array_replace($validate,['revision'=>hash('sha256','old'),'draft_id'=>str_repeat('d',32)]),$cookie),409,'context_stale');
    managerAiEndpointCheck('stale validation has no feedback observation',!in_array('feedback_insert_request',array_column($events(),'event'),true));
    $writeState(['proposal'=>['ok'=>false,'error'=>'provider_unavailable']]); $clearEvents();
    $expectError('provider error is safe and contains no details',$request($body,$cookie),503,'provider_unavailable');
    managerAiEndpointCheck('returned provider error still checks fresh context',count(array_filter($events(),static fn(array $event): bool=>$event['event'] === 'snapshot')) === 2);
    $writeState(['provider_mode'=>'throw']); $clearEvents();
    $thrown = $request($body,$cookie); $expectError('provider exception uses generic HTTP error',$thrown,503,'context_unavailable');
    managerAiEndpointCheck('exception details never enter response or server log',!str_contains($thrown['body'].file_get_contents($tmp.'/server.log'),'SYNTHETIC_PROVIDER_DETAIL_MUST_STAY_PRIVATE'));
    $writeState(); $clearEvents(); $valid = $request($body+['manager_id'=>8,
        'verified_company_facts'=>[['text'=>'UNTRUSTED_COMPANY_FACT']], 'project_key'=>'anex'],$cookie); $flow = $events();
    managerAiEndpointCheck('valid proposal passes with server revision, latest ID and draft identity',$valid['status'] === 200 && $valid['json'] === $proposal+['revision'=>$baseState['revision'],'latest_message_id'=>10,'draft_id'=>str_repeat('d',32)]);
    managerAiEndpointCheck('feedback persistence follows fresh post-provider authorization',array_column($flow,'event') === ['initialize_read_only','auth','snapshot','provider','snapshot','feedback_record']
        && $flow[5]['manager_id']===7 && $flow[5]['conversation_id']===42 && $flow[5]['revision']===$baseState['revision']);
    managerAiEndpointCheck('provider receives authenticated actor context, not posted manager ID',array_column(array_values(array_filter($flow,static fn(array $event): bool=>$event['event'] === 'snapshot')),'manager_id') === [7,7]);
    managerAiEndpointCheck('provider runs after session lock closes',$flow[3]['session_closed'] === true);
    managerAiEndpointCheck('endpoint passes the authorized server knowledge, ignoring posted project/facts',
        $flow[3]['knowledge_ids'] === ['anytour.public_name','anytour.search_site']
        && !str_contains($valid['body'],'UNTRUSTED_COMPANY_FACT'));
    managerAiEndpointCheck('endpoint passes source evidence from its fresh authorized snapshot',
        $flow[3]['reference_revision'] === $baseState['revision']);
    managerAiEndpointCheck('selection reader is composed outside session lock without posted source capabilities',
        $flow[3]['selection_reader_received']===true && $flow[3]['selection_count']===0 && $flow[3]['session_closed']);
    managerAiEndpointCheck('fresh after snapshot runs with reopened read-and-close session',$flow[4]['session_status'] === PHP_SESSION_NONE);
    $writeState(['feedback_available'=>false]); $clearEvents();
    $untracked=$request($body,$cookie);
    managerAiEndpointCheck('feedback unavailable preserves usable generated text without an identity',$untracked['status']===200 && $untracked['json']===$proposal+['revision'=>$baseState['revision'],'latest_message_id'=>10,'draft_id'=>null]);
    $untracked=$request($validate+['draft_id'=>str_repeat('d',32)],$cookie);
    managerAiEndpointCheck('feedback unavailable preserves fresh validation without attribution',$untracked['status']===200 && $untracked['json']['draft_id']===null);
    $writeState();
    foreach ([$valid,$thrown] as $response) {
        managerAiEndpointCheck('responses retain no-store JSON headers',count(array_filter($response['headers'],static fn(string $header): bool=>str_starts_with(strtolower($header),'cache-control:') && preg_match('/(?:^|[ ,:])no-store(?:[ ,]|$)/i',$header) === 1)) === 1 && count(array_filter($response['headers'],static fn(string $header): bool=>str_starts_with(strtolower($header),'content-type: application/json'))) === 1);
    }

    $race = static function(string $name,array $change,?string $sessionMutation,int $status,string $error,int $snapshotCount) use ($tmp,$fixture,$address,$body,$writeState,$clearEvents,$events,$login,$expectError,&$children): void {
        $writeState(); $cookie = $login();
        $writeState(['provider_mode'=>'wait']); $clearEvents();
        foreach (['provider-ready','provider-release','result.json'] as $file) if (is_file($tmp.'/'.$file)) unlink($tmp.'/'.$file);
        $worker = proc_open([PHP_BINARY,$fixture,'http-request'],[0=>['pipe','r'],1=>['file',$tmp.'/client.log','a'],2=>['file',$tmp.'/client.log','a']],$pipes);
        if (!is_resource($worker)) throw new RuntimeException('Cannot start asynchronous HTTP client');
        $children[] = $worker;
        fwrite($pipes[0],json_encode(['address'=>$address,'data'=>$body,'cookie'=>$cookie,'result_path'=>$tmp.'/result.json'])); fclose($pipes[0]);
        try {
            $deadline = microtime(true)+3;
            while (!is_file($tmp.'/provider-ready') && microtime(true) < $deadline) usleep(10000);
            managerAiEndpointCheck($name.' reaches provider wait',is_file($tmp.'/provider-ready'));
            if ($sessionMutation !== null) {
                preg_match('/^anytour_manager_panel=(.+)$/',$cookie,$session);
                $mutator = proc_open([PHP_BINARY,$fixture,'mutate-session',$tmp.'/sessions',$session[1],$sessionMutation],
                    [0=>['pipe','r'],1=>['file',$tmp.'/mutator.log','a'],2=>['file',$tmp.'/mutator.log','a']],$mutationPipes);
                if (!is_resource($mutator)) throw new RuntimeException('Cannot start session mutation');
                $children[] = $mutator; fclose($mutationPipes[0]);
                $deadline = microtime(true)+2;
                do { $processState = proc_get_status($mutator); if (!$processState['running']) break; usleep(10000); } while (microtime(true) < $deadline);
                managerAiEndpointCheck($name.' can change real session while provider waits',!$processState['running'] && $processState['exitcode'] === 0);
                proc_close($mutator); array_pop($children);
            }
            if ($change) $writeState(['provider_mode'=>'wait']+$change);
        } finally { file_put_contents($tmp.'/provider-release','release'); }
        $exit = proc_close($worker); array_pop($children);
        managerAiEndpointCheck($name.' completes HTTP worker',$exit === 0 && is_file($tmp.'/result.json'));
        $response = json_decode((string) file_get_contents($tmp.'/result.json'),true);
        $expectError($name.' rejects stale proposal',$response,$status,$error);
        managerAiEndpointCheck($name.' does not leak generated text',!str_contains($response['body'],'Какие даты вылета'));
        managerAiEndpointCheck($name.' uses expected fresh authorization boundary',count(array_filter($events(),static fn(array $event): bool=>$event['event'] === 'snapshot')) === $snapshotCount);
    };
    $race('new customer message',['latest_message_id'=>11,'revision'=>hash('sha256','new-message')],null,409,'context_stale',2);
    $race('same-ID message edit',['revision'=>hash('sha256','edited-message')],null,409,'context_stale',2);
    $race('company knowledge changes during generation',['revision'=>hash('sha256','changed-knowledge')],null,409,'context_stale',2);
    $race('assignment access lost',['access'=>false],null,403,'forbidden',2);
    $race('manager deactivated',['active'=>false],null,401,'unauthorized',2);
    $race('logout during provider',[],'logout',401,'unauthorized',1);
    $race('CSRF rotates during provider',[],'rotate_csrf',401,'unauthorized',1);
    $race('account changes during provider',[],'change_account',401,'unauthorized',1);
    managerAiEndpointCheck('temporary application never creates a production-style runtime/config directory',!is_dir($tmp.'/.runtime'));
} finally {
    foreach ($children as $child) if (is_resource($child)) { proc_terminate($child); proc_close($child); }
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $remove = static function(string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (is_dir($path)) { foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path.'/'.$entry); rmdir($path); }
    };
    $remove($tmp);
}
echo "TOTAL {$passed} passed\n";
