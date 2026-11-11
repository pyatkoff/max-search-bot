<?php
declare(strict_types=1);

function quickReplyMaterialHttpCases(PDO $db,callable $request,string $address,string &$cookie,string $root,callable $check): void
{
    $private=$root.'-private-materials';$source=dirname(__DIR__,2);
    file_put_contents($root.'/config.php',"\nrequire_once ".var_export($source.'/services/ManagerQuickReplyMaterials.php',true).";\n(new ReflectionProperty(ManagerQuickReplyMaterials::class,'testRoot'))->setValue(null,".var_export($private,true).");\n",FILE_APPEND);
    $db->exec("CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
        CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
        CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
        CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT);
        CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,PRIMARY KEY(manager_id,conversation_id));
        INSERT INTO customers VALUES(1,'Synthetic material customer','','');
        INSERT INTO conversations VALUES(501,1,'quick-http-fixture',0,'telegram','','','','manager','new',101,'2026-11-11 00:00:00','2026-11-11 00:00:00',NULL,'synthetic-only');
        INSERT INTO manager_conversation_reads VALUES(101,501,7);
        INSERT INTO projects VALUES(2,'http-company-b','Other synthetic company',1);
        INSERT INTO managers VALUES(104,'material-b','Synthetic other manager','manager','',1,0);
        INSERT INTO manager_projects VALUES(104,2);");
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lWsAAAAASUVORK5CYII=');
    $uploads=[['name'=>'image.png','mime'=>'image/png','bytes'=>$png],['name'=>'Условия.txt','mime'=>'image/jpeg','bytes'=>"Synthetic exact text\nNo real customer\n"]];
    $payload=['action'=>'create','csrf'=>'fixture-csrf-only','expected_manager_id'=>101,'project_key'=>'quick-http-fixture','title'=>'Native multipart bundle','body'=>'Synthetic caption','scope'=>'personal','source_label'=>'Synthetic source v1','reviewed'=>true,'materials'=>[['upload_index'=>1],['upload_index'=>0]],'upload_hashes'=>array_map(static fn(array $f):string=>hash('sha256',$f['bytes']),$uploads),'operation_id'=>'http-material-stable-00001'];
    $multipart=static function(array $data,array $files,string $cookie)use($address):array {
        $boundary='material-fixture-'.bin2hex(random_bytes(10));$body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"command\"\r\n\r\n".json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\r\n";
        foreach ($files as $file) $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"materials[]\"; filename=\"".$file['name']."\"\r\nContent-Type: ".$file['mime']."\r\n\r\n".$file['bytes']."\r\n";
        $body.='--'.$boundary."--\r\n";
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: multipart/form-data; boundary=".$boundary."\r\nCookie: ".$cookie,'content'=>$body,'ignore_errors'=>true,'timeout'=>5]]);
        $raw=file_get_contents('http://'.$address.'/manager/quick-replies-api.php',false,$context);$headers=$http_response_header??[];preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($headers[0]??''),$status);
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR)];
    };
    try {
        $created=$multipart($payload,$uploads,$cookie);$reply=$created['body']['reply'];
        $check('real multipart creates ordered company materials with detected content MIME',$created['status']===200&&array_column($reply['materials'],'name')===['Условия.txt','image.png']&&$reply['materials'][0]['mime']==='text/plain'&&$reply['materials'][1]['mime']==='image/png'&&!$reply['requires_review']);
        $count=count(glob($private.'/*/*.bin')?:[]);$repeated=$multipart($payload,$uploads,$cookie);
        $check('real multipart replay returns exact result and no second private copy',$repeated===$created&&count(glob($private.'/*/*.bin')?:[])===$count);
        $bad=$payload;$bad['operation_id']='http-material-bad-hash-001';$bad['upload_hashes'][0]=str_repeat('0',64);$denied=$multipart($bad,$uploads,$cookie);
        $check('real changed upload fingerprint is rejected before a record or byte write',$denied['body']['error']==='material_changed'&&count(glob($private.'/*/*.bin')?:[])===$count&&(int)$db->query("SELECT COUNT(*) FROM manager_quick_reply_operations WHERE operation_id='http-material-bad-hash-001'")->fetchColumn()===0);
        $input=['id'=>$reply['id'],'version'=>1,'conversation_id'=>501,'project_key'=>'quick-http-fixture','csrf'=>'fixture-csrf-only','expected_manager_id'=>101];
        $prepared=$request('/manager/quick-replies-api.php',['action'=>'prepare_insert']+$input,$cookie);
        $check('real prepare uses the current assigned conversation without read acknowledgement',$prepared['status']===200&&$prepared['body']['reply']['materials']===$reply['materials']&&$prepared['body']['constraints']['channel']==='telegram'&&(int)$db->query('SELECT last_read_message_id FROM manager_conversation_reads WHERE manager_id=101 AND conversation_id=501')->fetchColumn()===7);
        foreach ($reply['materials'] as $index=>$item) {
            $response=$request('/manager/quick-replies-api.php',['action'=>'material','material_id'=>$item['id']]+$input,$cookie);
            $headers=strtolower(implode("\n",$response['headers']));
            $check('real protected download preserves exact bytes and safe response headers',$response['status']===200&&hash('sha256',$response['raw'])===$item['sha256']&&strlen($response['raw'])===$item['size']&&str_contains($headers,'content-type: application/octet-stream')&&str_contains($headers,'x-content-type-options: nosniff')&&str_contains($headers,'content-disposition: attachment;')&&str_contains($headers,'cache-control: no-store'));
        }
        $denied=$request('/manager/quick-replies-api.php',['action'=>'material','material_id'=>str_repeat('f',32)]+$input,$cookie);
        $check('real guessed material ID never opens another private file',$denied['status']===404&&$denied['body']['error']==='not_found');
        $denied=$request('/manager/quick-replies-api.php',array_replace(['action'=>'material','material_id'=>$reply['materials'][0]['id']]+$input,['project_key'=>'http-company-b']),$cookie);
        $check('real cross-company request fails before file disclosure',$denied['status']===404&&!str_contains($denied['raw'],'Synthetic exact text'));
        $login=$request('/manager/fixture-session.php?id=104',[],$cookie,'GET');$cookie=$login['cookie']?:$cookie;
        $denied=$request('/manager/quick-replies-api.php',['action'=>'material','material_id'=>$reply['materials'][0]['id']]+$input,$cookie);
        $check('real account switch with the same synthetic CSRF rejects the captured old actor',$denied['status']===409&&$denied['body']['error']==='actor_changed');
        $denied=$request('/manager/quick-replies-api.php',array_replace(['action'=>'material','material_id'=>$reply['materials'][0]['id']]+$input,['project_key'=>'http-company-b','expected_manager_id'=>104]),$cookie);
        $check('real other-company manager cannot use an accessible company to fetch the original files',$denied['status']===404&&!str_contains($denied['raw'],'Synthetic exact text'));
        $login=$request('/manager/fixture-session.php?id=101',[],$cookie,'GET');$cookie=$login['cookie']?:$cookie;
        $db->exec('UPDATE conversations SET manager_id=102 WHERE id=501');
        $denied=$request('/manager/quick-replies-api.php',['action'=>'material','material_id'=>$reply['materials'][0]['id']]+$input,$cookie);
        $check('real reassignment invalidates a previously prepared material download',$denied['status']===404);
        $db->exec('UPDATE conversations SET manager_id=101 WHERE id=501');
        $update=$request('/manager/quick-replies-api.php',['action'=>'update','id'=>$reply['id'],'version'=>1,'title'=>'Revised conditions','body'=>'New synthetic caption','materials'=>array_map(static fn(array $m):array=>['id'=>$m['id']],array_reverse($reply['materials'])),'operation_id'=>'http-material-update-00001','csrf'=>'fixture-csrf-only'],$cookie);
        $denied=$request('/manager/quick-replies-api.php',['action'=>'material','material_id'=>$reply['materials'][0]['id']]+$input,$cookie);
        $check('real new template version retains its order and blocks the stale saved version',$update['status']===200&&$update['body']['reply']['requires_review']&&array_column($update['body']['reply']['materials'],'id')===array_reverse(array_column($reply['materials'],'id'))&&$denied['status']===409&&$denied['body']['error']==='conflict');
        $db->exec('DELETE FROM manager_projects WHERE manager_id=101 AND project_id=1');
        $denied=$request('/manager/quick-replies-api.php',['action'=>'operation','operation_id'=>$payload['operation_id'],'csrf'=>'fixture-csrf-only'],$cookie);
        $check('real project revocation also removes immutable material receipt visibility',$denied['status']===404);
        $db->exec('INSERT INTO manager_projects VALUES(101,1)');
    } finally {
        $remove=static function(string $path)use(&$remove):void {if(is_link($path)||is_file($path)){unlink($path);return;}if(is_dir($path)){foreach(scandir($path)?:[] as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}};
        $remove($private);
    }
}
