<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerQuickReplyService.php';

final class QuickReplyDisclosureHook {public $afterList=null;}
final class QuickReplyDisclosureStatement extends PDOStatement
{
    protected function __construct(private QuickReplyDisclosureHook $hook) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        $rows=parent::fetchAll($mode,...$args);
        if (str_starts_with($this->queryString,'SELECT r.*,CASE')&&$this->hook->afterList) {
            $callback=$this->hook->afterList;$this->hook->afterList=null;$callback();
        }
        return $rows;
    }
}

/** Same real service/file behavior on SQLite and native InnoDB. No provider. */
function quickReplyMaterialCases(PDO $pdo,callable $check): void
{
    $tag='qr-'.bin2hex(random_bytes(5));$projects=[];$actors=[];$ids=[];
    $private=(realpath(sys_get_temp_dir())?:sys_get_temp_dir()).'/'.$tag;
    mkdir($private,0700);
    $storeProperty=new ReflectionProperty(ManagerQuickReplyMaterials::class,'testRoot');
    $previousStore=$storeProperty->getValue();$storeProperty->setValue(null,$private.'/materials');
    $previousStatements=$pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    try {
        foreach (['a','b'] as $letter) {
            $key=$tag.'-'.$letter;
            $pdo->prepare('INSERT INTO projects(project_key,display_name,is_active) VALUES (?,?,1)')->execute([$key,'Synthetic company '.$letter]);
            $projects[$letter]=['id'=>(int)$pdo->lastInsertId(),'key'=>$key];
        }
        foreach (['admin'=>'admin','a'=>'manager','b'=>'manager','dual'=>'manager'] as $name=>$role) {
            $pdo->prepare('INSERT INTO managers(login,display_name,role,email,is_active,is_working) VALUES (?,?,?,?,1,0)')->execute([$tag.'-'.$name,'Synthetic '.$name,$role,'']);
            $actors[$name]=(int)$pdo->lastInsertId();
            foreach (['a','b'] as $letter) if ($name==='admin'||$name==='dual'||$name===$letter) {
                $pdo->prepare('INSERT INTO manager_projects(manager_id,project_id) VALUES (?,?)')->execute([$actors[$name],$projects[$letter]['id']]);
            }
        }
        $sequence=0;
        $command=static function(string $actor,string $project,string $action,array $input=[],array $files=[]) use ($actors,$projects,&$sequence): array {
            $input+=['project_key'=>$projects[$project]['key'],'operation_id'=>'material-case-'.str_pad((string)++$sequence,16,'0',STR_PAD_LEFT)];
            return ManagerQuickReplyService::mutate($actors[$actor],true,$action,$input,$files);
        };
        $list=static fn(string $actor,string $project):array=>ManagerQuickReplyService::list($actors[$actor],true,['project_key'=>$projects[$project]['key']]);
        $beforeOps=(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_reply_operations')->fetchColumn();
        $check('company library read is empty, read-only and creates no private file directory',$list('a','a')['replies']===[]&&!is_dir($private.'/materials')&&(int)$pdo->query('SELECT COUNT(*) FROM manager_quick_reply_operations')->fetchColumn()===$beforeOps);
        $a=$command('admin','a','create',['scope'=>'shared','title'=>'Company A conditions','body'=>'Synthetic A only','category'=>'A-only']);$ids[]=$a['reply']['id'];
        $b=$command('admin','b','create',['scope'=>'shared','title'=>'Company B secret','body'=>'Synthetic B only','category'=>'B-only']);$ids[]=$b['reply']['id'];
        $check('shared text, categories and count are company scoped',array_column($list('a','a')['replies'],'id')===[$a['reply']['id']]&&$list('a','a')['categories']===['A-only']&&!str_contains(json_encode($list('a','a'),JSON_THROW_ON_ERROR),'Company B'));
        $check('an administrator boolean cannot forge a current administrator role',$command('a','a','create',['scope'=>'shared','title'=>'forged','body'=>'Synthetic'])['error']==='forbidden');
        $check('two-company manager still sees only the explicitly selected company',array_column($list('dual','a')['replies'],'id')===[$a['reply']['id']]&&array_column($list('dual','b')['replies'],'id')===[$b['reply']['id']]);
        $check('direct foreign template ID is denied even to a manager of both companies',$command('dual','a','copy',['id'=>$b['reply']['id']])['error']==='not_found'&&$command('dual','a','favorite',['id'=>$b['reply']['id'],'is_favorite'=>true])['error']==='not_found');
        $check('unauthorized company and source-free verification are rejected',$list('a','b')['error']==='not_found'&&$command('a','a','create',['title'=>'Unproven','body'=>'Synthetic','reviewed'=>true])['error']==='source_required');

        file_put_contents($private.'/image',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lWsAAAAASUVORK5CYII='));
        file_put_contents($private.'/document',"Synthetic private material\nNo real customer data\n");
        $files=[['name'=>'photo.png','tmp_name'=>$private.'/image'],['name'=>'../../Прайс.txt','tmp_name'=>$private.'/document']];
        $input=['project_key'=>$projects['a']['key'],'operation_id'=>'material-stable-'.$tag,'title'=>'Ordered material bundle','body'=>'Synthetic prepared answer','source_label'=>'Synthetic conditions v1','reviewed'=>true,'materials'=>[['upload_index'=>1],['upload_index'=>0]],'upload_hashes'=>array_map(static fn(array $f):string=>hash_file('sha256',$f['tmp_name']),$files)];
        $bundle=ManagerQuickReplyService::mutate($actors['a'],false,'create',$input,$files);$ids[]=$bundle['reply']['id'];
        $materials=$bundle['reply']['materials'];
        $check('ordered bundle persists normalized names, exact bytes, detected MIME and dimensions',$bundle['ok']&&array_column($materials,'name')===['Прайс.txt','photo.png']&&$materials[0]['sha256']===hash_file('sha256',$private.'/document')&&$materials[1]['sha256']===hash_file('sha256',$private.'/image')&&$materials[1]['mime']==='image/png'&&$materials[1]['width']===1&&$materials[1]['height']===1);
        $check('source and explicit review have an unambiguous instant',$bundle['reply']['source_label']==='Synthetic conditions v1'&&!$bundle['reply']['requires_review']&&str_ends_with($bundle['reply']['reviewed_at'],'Z'));
        foreach ($materials as $item) {
            $opened=ManagerQuickReplyMaterials::open($projects['a']['id'],$item);
            $bytes=stream_get_contents($opened['stream']);fclose($opened['stream']);
            $path=$private.'/materials/'.$projects['a']['id'].'/'.$item['id'].'.bin';
            $check('stored material is private, immutable and exactly readable',hash('sha256',$bytes)===$item['sha256']&&strlen($bytes)===$item['size']&&(fileperms($path)&0777)===0600&&(fileperms(dirname($path))&0777)===0700);
        }
        $countFiles=static fn():int=>count(glob($private.'/materials/*/*.bin')?:[]);
        $fileCount=$countFiles();
        $replay=ManagerQuickReplyService::mutate($actors['a'],false,'create',$input,$files);
        $check('lost-response recovery reuses the exact receipt without duplicate file writes',$replay===$bundle&&$countFiles()===$fileCount&&ManagerQuickReplyService::operation($actors['a'],$input['operation_id'],['project_key'=>$projects['a']['key']])['result']===$bundle);
        $foreignReceipt=ManagerQuickReplyService::operation($actors['dual'],$input['operation_id'],['project_key'=>$projects['b']['key']]);
        $check('material receipt is not an alternate account or company disclosure path',!$foreignReceipt['found']&&!ManagerQuickReplyService::operation($actors['a'],$input['operation_id'],['project_key'=>$projects['b']['key']])['ok']);
        $altered=$input;$altered['source_label']='Different source';
        $check('same operation cannot silently replace source, material order or bytes',ManagerQuickReplyService::mutate($actors['a'],false,'create',$altered,$files)['error']==='operation_mismatch'&&$countFiles()===$fileCount);
        $update=$command('a','a','update',['id'=>$bundle['reply']['id'],'version'=>1,'title'=>'Updated bundle','body'=>'Changed synthetic conditions','source_label'=>'Synthetic conditions v2','materials'=>array_map(static fn(array $m):array=>['id'=>$m['id']],array_reverse($materials))]);
        $check('edit preserves exact material IDs/order and requires fresh review',$update['reply']['version']===2&&array_column($update['reply']['materials'],'id')===array_reverse(array_column($materials,'id'))&&$update['reply']['requires_review']&&$update['reply']['reviewed_at']===null&&$countFiles()===$fileCount);
        $stale=$command('a','a','update',['id'=>$bundle['reply']['id'],'version'=>1,'title'=>'Stale','body'=>'stale','materials'=>[]]);
        $check('stale save cannot remove or replace a newer material bundle',$stale['error']==='conflict'&&$stale['current']['materials']===$update['reply']['materials']&&$countFiles()===$fileCount);
        $invalid=$command('a','a','update',['id'=>$bundle['reply']['id'],'version'=>2,'title'=>'Foreign file','body'=>'Synthetic','materials'=>[['id'=>str_repeat('0',32)]]]);
        $check('unowned and repeated material IDs never become a path or stored reference',$invalid['error']==='invalid_materials'&&$countFiles()===$fileCount);
        $copy=$command('dual','a','copy',['id'=>$a['reply']['id']]);$ids[]=$copy['reply']['id'];
        $check('same-company copy remains a private editable reply',$copy['reply']['project_key']===$projects['a']['key']&&$copy['reply']['scope']==='personal'&&$copy['reply']['can_edit']);

        $hook=new QuickReplyDisclosureHook();$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[QuickReplyDisclosureStatement::class,[$hook]]);
        $hook->afterList=static function()use($pdo,$actors,$projects):void {$pdo->prepare('DELETE FROM manager_projects WHERE manager_id=? AND project_id=?')->execute([$actors['a'],$projects['a']['id']]);};
        $revoked=$list('a','a');
        $check('revocation after SQL read suppresses the entire already-read material result',($revoked['error']??'')==='not_found'&&!str_contains(json_encode($revoked,JSON_THROW_ON_ERROR),'Synthetic private'));
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$previousStatements);
        $check('revoked company cannot recover its earlier immutable receipt',ManagerQuickReplyService::operation($actors['a'],$input['operation_id'],['project_key'=>$projects['a']['key']])['error']==='not_found');
        $pdo->prepare('INSERT INTO manager_projects(manager_id,project_id) VALUES (?,?)')->execute([$actors['a'],$projects['a']['id']]);
        $before=$countFiles();$rollbackOp='material-rollback-'.$tag;
        $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if ($mysql) $pdo->exec("CREATE TRIGGER reject_material_receipt BEFORE UPDATE ON manager_quick_reply_operations FOR EACH ROW BEGIN IF NEW.operation_id='".$rollbackOp."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic material receipt failure'; END IF; END");
        else $pdo->exec("CREATE TRIGGER reject_material_receipt BEFORE UPDATE ON manager_quick_reply_operations WHEN NEW.operation_id='".$rollbackOp."' BEGIN SELECT RAISE(FAIL,'synthetic material receipt failure'); END");
        $rollbackInput=array_replace($input,['operation_id'=>$rollbackOp]);$failed=false;
        try {ManagerQuickReplyService::mutate($actors['a'],false,'create',$rollbackInput,$files);} catch (PDOException $e) {$failed=true;}
        $pdo->exec('DROP TRIGGER reject_material_receipt');
        $check('acknowledged receipt rollback removes only that command private files',$failed&&$countFiles()===$before&&!ManagerQuickReplyService::operation($actors['a'],$rollbackOp,['project_key'=>$projects['a']['key']])['found']);
        $retry=ManagerQuickReplyService::mutate($actors['a'],false,'create',$rollbackInput,$files);$ids[]=$retry['reply']['id'];
        $check('same command after confirmed rollback creates one complete new bundle',$retry['ok']&&$countFiles()===$before+2);

        $item=$materials[0];$path=$private.'/materials/'.$projects['a']['id'].'/'.$item['id'].'.bin';$original=file_get_contents($path);
        file_put_contents($path,str_repeat('x',strlen($original)));$rejected=false;
        try {$bad=ManagerQuickReplyMaterials::open($projects['a']['id'],$item);fclose($bad['stream']);} catch (RuntimeException $e) {$rejected=true;}
        $check('same-size damaged bytes fail the saved content hash',$rejected);
        unlink($path);symlink($private.'/document',$path);$rejected=false;
        try {$bad=ManagerQuickReplyMaterials::open($projects['a']['id'],$item);fclose($bad['stream']);} catch (RuntimeException $e) {$rejected=true;}
        $check('private material lookup never follows a substituted symlink',$rejected);unlink($path);file_put_contents($path,$original);chmod($path,0600);
        $pdo->prepare('UPDATE managers SET is_active=0 WHERE id=?')->execute([$actors['a']]);
        $check('disabled account cannot read or reconcile saved file metadata',$list('a','a')['error']==='not_found'&&ManagerQuickReplyService::operation($actors['a'],$input['operation_id'],['project_key'=>$projects['a']['key']])['error']==='not_found');
    } finally {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$previousStatements);$storeProperty->setValue(null,$previousStore);
        foreach ($actors as $actor) {
            $pdo->prepare('DELETE FROM manager_quick_reply_favorites WHERE manager_id=?')->execute([$actor]);
            $pdo->prepare('DELETE FROM manager_quick_reply_operations WHERE manager_id=?')->execute([$actor]);
            $pdo->prepare('DELETE FROM manager_quick_reply_toolbar_states WHERE manager_id=?')->execute([$actor]);
            $pdo->prepare('DELETE FROM admin_audit_log WHERE actor_manager_id=?')->execute([$actor]);
            $pdo->prepare('DELETE FROM manager_quick_replies WHERE created_by_manager_id=?')->execute([$actor]);
            $pdo->prepare('DELETE FROM manager_projects WHERE manager_id=?')->execute([$actor]);
            $pdo->prepare('DELETE FROM managers WHERE id=?')->execute([$actor]);
        }
        foreach ($projects as $project) $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$project['id']]);
        $remove=static function(string $path)use(&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach(scandir($path)?:[] as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}};
        $remove($private);
    }
}
