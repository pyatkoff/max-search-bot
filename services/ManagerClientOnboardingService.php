<?php
declare(strict_types=1);
require_once __DIR__.'/AdminDirectoryService.php';
require_once __DIR__.'/ProjectClientRegistry.php';
require_once __DIR__.'/../integrations/ProjectMaxWebhookSetup.php';

/** One explicit operator workflow. Existing channel/history/routing owners stay canonical. */
final class ManagerClientOnboardingService
{
    private PDO $pdo;
    public function __construct(private ProjectClientRegistry $registry,private $provider=null,private $probe=null)
    {
        $this->pdo=$registry->pdo;
    }
    private function admin(int $actor): bool {return ManagerAuthService::isAdmin(ManagerAuthService::byId($actor,true));}
    private function lock(): string {return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private static function operation($value): string
    {
        if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new InvalidArgumentException('invalid_operation');return $value;
    }
    private static function text(array $input,string $key,int $min,int $max,bool $plain=true): string
    {
        $v=$input[$key]??null;
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||strlen($v)>$max||mb_strlen(trim($v),'UTF-8')<$min||str_contains($v,"\0")||($plain&&preg_match('/[\p{C}<>&]/u',$v)))throw new InvalidArgumentException('invalid_'.$key);
        return $plain?trim($v):$v;
    }
    private function input(array $data): array
    {
        if(array_diff(array_keys($data),['operation_id','display_name','business_kind','bot_key','token','materials','staff']))throw new InvalidArgumentException('invalid_client');
        $out=['operation_id'=>self::operation($data['operation_id']??null),'display_name'=>self::text($data,'display_name',1,191)];
        if(($data['business_kind']??null)!=='device_repair')throw new InvalidArgumentException('unsupported_business_kind');$out['business_kind']='device_repair';
        $out['bot_key']=self::text($data,'bot_key',1,191);if(!preg_match('/\A[A-Za-z0-9_]{1,191}\z/D',$out['bot_key']))throw new InvalidArgumentException('invalid_bot_key');
        $out['token']=self::text($data,'token',8,4096,false);if(preg_match('/[\x00-\x20\x7f]/',$out['token']))throw new InvalidArgumentException('invalid_token');
        $out['materials']=self::text($data+['materials'=>''],'materials',0,100000,false);
        $staff=$data['staff']??null;if(!is_array($staff)||!array_is_list($staff)||count($staff)<1||count($staff)>20)throw new InvalidArgumentException('invalid_staff');$out['staff']=[];$logins=[];
        foreach($staff as $person){
            if(!is_array($person)||array_diff(array_keys($person),['login','name','password']))throw new InvalidArgumentException('invalid_staff');
            $login=self::text($person,'login',3,191);if(!preg_match('/\A[A-Za-z0-9._@+-]{3,191}\z/D',$login)||in_array(strtolower($login),$logins,true))throw new InvalidArgumentException('invalid_login');$logins[]=strtolower($login);
            $out['staff'][]=['login'=>$login,'name'=>self::text($person,'name',1,191),'password'=>self::text($person,'password',8,72,false)];
        }
        return $out;
    }
    private function storedOperation(int $actor,string $operation): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM project_client_operations WHERE actor_id=? AND operation_id=?');$q->execute([$actor,$operation]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    private function project(int $id,bool $lock=false): ?array
    {
        $q=$this->pdo->prepare('SELECT p.id,p.project_key,p.display_name,p.is_active,c.* FROM project_client_connections c JOIN projects p ON p.id=c.project_id WHERE p.id=?'.($lock?$this->lock():''));$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    private static function savedResult(array $row): array
    {
        $result=is_string($row['result_json']??null)?json_decode($row['result_json'],true):null;
        return is_array($result)?$result:['ok'=>true,'state'=>'pending','operation_kind'=>$row['operation_kind'],'operation_id'=>$row['operation_id'],'project_id'=>$row['project_id']===null?null:(int)$row['project_id']];
    }
    public function receipt(int $actor,string $operation): array
    {
        if(!$this->admin($actor))return ['ok'=>false,'error'=>'forbidden'];$operation=self::operation($operation);$row=$this->storedOperation($actor,$operation);
        return $row?self::savedResult($row):['ok'=>true,'state'=>'not_found','operation_id'=>$operation];
    }
    /** Tombstone a lost request only before its durable provider claim. */
    public function cancel(int $actor,string $operation): array
    {
        $operation=self::operation($operation);if(!$this->admin($actor))return ['ok'=>false,'error'=>'forbidden'];
        $this->pdo->beginTransaction();
        try{
            // Use the same directory lock order as creation before its tombstone.
            $this->pdo->query("SELECT id FROM managers WHERE role='admin' AND is_active=1 ORDER BY id".$this->lock())->fetchAll();
            if(!$this->admin($actor))throw new RuntimeException('forbidden');
            $op=$this->storedOperation($actor,$operation);
            if($op&&$op['operation_kind']==='activate'){
                $row=$this->project((int)$op['project_id'],true);$op=$this->storedOperation($actor,$operation);
                if($op['state']!=='executing'||(int)$op['mutation_claimed']!==0){$this->pdo->rollBack();return self::savedResult($op);}
                if(!$row||(int)$row['activation_actor_id']!==$actor||$row['activation_operation_id']!==$operation)throw new RuntimeException('context_stale');
                $this->pdo->prepare('UPDATE project_client_connections SET activation_actor_id=NULL,activation_operation_id=NULL WHERE project_id=?')->execute([(int)$op['project_id']]);
            }elseif($op){$this->pdo->rollBack();return self::savedResult($op);}
            $result=['ok'=>true,'state'=>'cancelled','operation_id'=>$operation];$encoded=json_encode($result,JSON_THROW_ON_ERROR);
            if($op)$this->pdo->prepare("UPDATE project_client_operations SET state='cancelled',result_json=? WHERE actor_id=? AND operation_id=?")->execute([$encoded,$actor,$operation]);
            else $this->pdo->prepare("INSERT INTO project_client_operations (actor_id,operation_id,operation_kind,payload_hash,state,result_json) VALUES (?,?,'cancel','', 'cancelled',?)")->execute([$actor,$operation,$encoded]);
            $this->pdo->commit();return $result;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();return ['ok'=>false,'error'=>'save_outcome_unknown','operation_id'=>$operation];}
    }
    public function create(int $actor,array $data): array
    {
        if(!$this->admin($actor))return ['ok'=>false,'error'=>'forbidden'];$input=$this->input($data);$operation=$input['operation_id'];$key='client_'.substr($operation,0,24);
        $count=(int)$this->pdo->query('SELECT COUNT(*) FROM project_client_connections')->fetchColumn();
        $this->registry->credentials->initialize($count===0);$this->registry->assertCredentialKey();$hash=$this->registry->credentials->fingerprint(json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $existing=$this->storedOperation($actor,$operation);if($existing)return hash_equals($existing['payload_hash'],$hash)&&$existing['operation_kind']==='create'?self::savedResult($existing):['ok'=>false,'error'=>'operation_conflict'];
        if($count>=1000)return ['ok'=>false,'error'=>'client_capacity'];
        if(!ProjectMaxConnection::availableForNewClient($key,$input['bot_key'],$input['token']))return ['ok'=>false,'error'=>'connection_in_use'];
        $settings=['id'=>$key,'brand'=>['name'=>$input['display_name']],'business'=>['kind'=>'device_repair','repair'=>['entries'=>[]]],'routing'=>['source_key'=>'max:'.$key.'-main'],'messenger'=>['max'=>['webhook_url'=>'https://app.anytoour.ru/project_max_webhook.php?project='.$key]]];
        $secret=bin2hex(random_bytes(32));$sealed=$this->registry->credentials->seal($key,$input['bot_key'],['token'=>$input['token'],'webhook_secret'=>$secret]);$committing=false;
        $this->pdo->beginTransaction();
        try{
            // Take the existing admin directory in one order before actor locks
            // and grants; capacity is then atomic across provisioning admins.
            $this->pdo->query("SELECT id FROM managers WHERE role='admin' AND is_active=1 ORDER BY id".$this->lock())->fetchAll();
            if(!$this->admin($actor))throw new RuntimeException('forbidden');
            if((int)$this->pdo->query('SELECT COUNT(*) FROM project_client_connections')->fetchColumn()>=1000)throw new RuntimeException('client_capacity');
            $this->pdo->prepare("INSERT INTO project_client_operations (actor_id,operation_id,operation_kind,payload_hash,state) VALUES (?,?,'create',?,'executing')")->execute([$actor,$operation,$hash]);
            $directory=AdminDirectoryService::createClientDirectory($this->pdo,$actor,$key,$input['display_name'],$input['staff']);$id=$directory['project_id'];
            $this->pdo->prepare('INSERT INTO project_client_connections (project_id,bot_key,token_fingerprint,settings_json,credentials_json,materials_text) VALUES (?,?,?,?,?,?)')->execute([$id,$input['bot_key'],$this->registry->credentials->fingerprint($input['token']),json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$sealed,$input['materials']]);
            $result=['ok'=>true,'state'=>'created','operation_id'=>$operation,'project_id'=>$id,'manager_ids'=>$directory['manager_ids']];
            $this->pdo->prepare("UPDATE project_client_operations SET project_id=?,state='complete',result_json=? WHERE actor_id=? AND operation_id=?")->execute([$id,json_encode($result,JSON_THROW_ON_ERROR),$actor,$operation]);
            $committing=true;$this->pdo->commit();return $result;
        }catch(Throwable $error){
            $rolledBack=false;if($this->pdo->inTransaction()){try{$rolledBack=$this->pdo->rollBack();}catch(Throwable $ignored){}}
            if($committing||!$rolledBack)return ['ok'=>false,'error'=>'save_outcome_unknown','operation_id'=>$operation];
            $existing=$this->storedOperation($actor,$operation);if($existing)return hash_equals($existing['payload_hash'],$hash)?self::savedResult($existing):['ok'=>false,'error'=>'operation_conflict'];
            if($error instanceof PDOException&&in_array((string)$error->getCode(),['23000','23505'],true))return ['ok'=>false,'error'=>'duplicate_client'];
            return ['ok'=>false,'error'=>in_array($error->getMessage(),['forbidden','client_capacity'],true)?$error->getMessage():'client_save_failed'];
        }
    }
    public function listing(int $actor): array
    {
        $manager=ManagerAuthService::byId($actor);if(!$manager)return ['ok'=>false,'error'=>'forbidden'];$admin=ManagerAuthService::isAdmin($manager);
        $sql='SELECT p.id,p.project_key,p.display_name,p.is_active,c.bot_key,c.last_check_json,c.activation_operation_id,c.activation_actor_id,c.registration_fingerprint FROM projects p LEFT JOIN project_client_connections c ON c.project_id=p.id';$args=[];
        if(!$admin){$sql.=' JOIN manager_projects mp ON mp.project_id=p.id AND mp.manager_id=? WHERE p.is_active=1';$args[]=$actor;}$sql.=' ORDER BY p.display_name,p.id LIMIT 1001';$q=$this->pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>1000)throw new RuntimeException('client_capacity');
        $clients=[];foreach($rows as $row){
            $check=is_string($row['last_check_json']??null)?json_decode($row['last_check_json'],true):null;
            $registered=$row['registration_fingerprint']!==null;
            $state=$registered?'active':($row['activation_operation_id']!==null?'activation_pending':'created');
            if(is_array($check))$state=empty($check['ok'])?'check_failed':($registered&&($check['state']??'')!=='ready'?'check_failed':($registered?'active':($row['activation_operation_id']!==null?'activation_pending':'verified')));
            $clients[]=['id'=>(int)$row['id'],'display_name'=>$row['display_name'],'is_active'=>(bool)$row['is_active'],'managed'=>$row['bot_key']!==null,'bot_key'=>$row['bot_key'],'state'=>$state,'check'=>$admin?$check:null];
        }
        $fresh=ManagerAuthService::byId($actor);
        if(!$fresh||$fresh['role']!==$manager['role']||$fresh['projects']!==$manager['projects'])return ['ok'=>false,'error'=>'forbidden'];return ['ok'=>true,'is_admin'=>$admin,'clients'=>$clients];
    }
    private function canConfigure(array $row): bool
    {
        if(!(int)$row['is_active']||$row['registration_fingerprint']!==null||$row['activation_operation_id']!==null)return false;
        $q=$this->pdo->prepare('SELECT id FROM conversations WHERE project_key=? LIMIT 1');$q->execute([$row['project_key']]);return !$q->fetchColumn();
    }
    /** Correct an uncommissioned account; never retarget a used connection. */
    public function configure(int $actor,int $id,array $data): array
    {
        if(!$this->admin($actor))return ['ok'=>false,'error'=>'forbidden'];
        if(array_diff(array_keys($data),['operation_id','bot_key','token','materials']))throw new InvalidArgumentException('invalid_client');
        $operation=self::operation($data['operation_id']??null);$bot=self::text($data,'bot_key',1,191);if(!preg_match('/\A[A-Za-z0-9_]{1,191}\z/D',$bot))throw new InvalidArgumentException('invalid_bot_key');
        $token=self::text($data+['token'=>''],'token',0,4096,false);if($token!==''&&(strlen($token)<8||preg_match('/[\x00-\x20\x7f]/',$token)))throw new InvalidArgumentException('invalid_token');
        $materials=self::text($data,'materials',0,100000,false);$this->registry->assertCredentialKey();
        $hash=$this->registry->credentials->fingerprint(json_encode([$id,$bot,$token,$materials],JSON_THROW_ON_ERROR));$existing=$this->storedOperation($actor,$operation);
        if($existing)return $existing['operation_kind']==='configure'&&(int)$existing['project_id']===$id&&hash_equals($existing['payload_hash'],$hash)?self::savedResult($existing):['ok'=>false,'error'=>'operation_conflict'];
        $committing=false;$this->pdo->beginTransaction();
        try{
            $this->pdo->query("SELECT id FROM managers WHERE role='admin' AND is_active=1 ORDER BY id".$this->lock())->fetchAll();
            if(!$this->admin($actor))throw new RuntimeException('forbidden');$row=$this->project($id,true);
            if(!$row||!$this->canConfigure($row))throw new RuntimeException('configuration_locked');
            $secret=$this->registry->credentials->open($row['project_key'],$row['bot_key'],$row['credentials_json']);$actualToken=$token!==''?$token:$secret['token'];
            if(!ProjectMaxConnection::availableForNewClient($row['project_key'],$bot,$actualToken))throw new RuntimeException('connection_in_use');
            if($bot!==$row['bot_key']||$actualToken!==$secret['token'])$secret=['token'=>$actualToken,'webhook_secret'=>bin2hex(random_bytes(32))];
            $sealed=$this->registry->credentials->seal($row['project_key'],$bot,$secret);$result=['ok'=>true,'state'=>'configured','operation_id'=>$operation,'project_id'=>$id];
            $encoded=json_encode($result,JSON_THROW_ON_ERROR);
            $this->pdo->prepare("INSERT INTO project_client_operations(actor_id,operation_id,project_id,operation_kind,payload_hash,state,result_json) VALUES(?,?,?,'configure',?,'complete',?)")->execute([$actor,$operation,$id,$hash,$encoded]);
            $this->pdo->prepare('UPDATE project_client_connections SET bot_key=?,token_fingerprint=?,credentials_json=?,materials_text=?,last_check_json=NULL WHERE project_id=?')->execute([$bot,$this->registry->credentials->fingerprint($actualToken),$sealed,$materials,$id]);
            AuditLogService::record($actor,'project_updated','project',(string)$id,$row['project_key'],null,['connection_corrected'=>true]);
            $committing=true;$this->pdo->commit();return $result;
        }catch(Throwable $e){
            $rolledBack=false;if($this->pdo->inTransaction())$rolledBack=$this->pdo->rollBack();
            if($committing||!$rolledBack)return ['ok'=>false,'error'=>'save_outcome_unknown','operation_id'=>$operation];
            $existing=$this->storedOperation($actor,$operation);if($existing)return $existing['operation_kind']==='configure'&&hash_equals($existing['payload_hash'],$hash)?self::savedResult($existing):['ok'=>false,'error'=>'operation_conflict'];
            if($e instanceof PDOException&&in_array((string)$e->getCode(),['23000','23505'],true))return ['ok'=>false,'error'=>'duplicate_client'];
            return ['ok'=>false,'error'=>in_array($e->getMessage(),['forbidden','configuration_locked','connection_in_use'],true)?$e->getMessage():'client_save_failed'];
        }
    }
    public function detail(int $actor,int $id): array
    {
        $row=$this->project($id);if(!$row||!ManagerAuthService::byId($actor)||!ProjectAccessService::canAccess($actor,(string)$row['project_key']))return ['ok'=>false,'error'=>'not_found'];
        $q=$this->pdo->prepare('SELECT m.id,m.login,m.display_name FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=? AND m.role=? ORDER BY m.id LIMIT 101');$q->execute([$id,'manager']);$staff=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!ManagerAuthService::byId($actor)||!ProjectAccessService::canAccess($actor,(string)$row['project_key']))return ['ok'=>false,'error'=>'not_found'];
        return ['ok'=>true,'client'=>['id'=>$id,'display_name'=>$row['display_name'],'bot_key'=>$row['bot_key'],'materials'=>$row['materials_text'],'staff'=>$staff,'can_configure'=>$this->admin($actor)&&$this->canConfigure($row)]];
    }
    private function fingerprint(ProjectMaxConnection $connection): string {return hash('sha256',serialize([$connection->projectKey(),$connection->botKey(),$connection->token(),$connection->webhookSecret(),$connection->settings()->all()]));}
    public function verify(int $actor,int $id): array
    {
        if(!$this->admin($actor))return ['ok'=>false,'error'=>'forbidden'];$row=$this->project($id);$connection=$row?ProjectMaxConnection::resolve((string)$row['project_key']):null;if(!$connection)return ['ok'=>false,'error'=>'connection_unavailable'];$fingerprint=$this->fingerprint($connection);
        $result=ProjectMaxWebhookSetup::verify($connection,$this->provider,$this->probe,null,static fn()=>($row['registration_fingerprint']??null)===$fingerprint);
        $fresh=$this->project($id);$current=$fresh?ProjectMaxConnection::resolve((string)$fresh['project_key']):null;
        if(!$this->admin($actor)||!$current||!hash_equals($fingerprint,$this->fingerprint($current)))return ['ok'=>false,'error'=>'context_stale'];
        $this->pdo->prepare('UPDATE project_client_connections SET last_check_json=? WHERE project_id=?')->execute([json_encode($result,JSON_THROW_ON_ERROR),$id]);return $result;
    }
    public function activate(int $actor,int $id,string $operation): array
    {
        $operation=self::operation($operation);if(!$this->admin($actor))return ['ok'=>false,'error'=>'forbidden'];
        $existing=$this->storedOperation($actor,$operation);if($existing)return $existing['operation_kind']==='activate'&&(int)$existing['project_id']===$id?self::savedResult($existing):['ok'=>false,'error'=>'operation_conflict'];
        $row=$this->project($id);$connection=$row?ProjectMaxConnection::resolve((string)$row['project_key']):null;if(!$connection)return ['ok'=>false,'error'=>'connection_unavailable'];$fingerprint=$this->fingerprint($connection);$check=json_decode((string)($row['last_check_json']??''),true);
        if(($check['ok']??false)!==true)return ['ok'=>false,'error'=>'verification_required'];
        $this->pdo->beginTransaction();
        try{
            $fresh=$this->project($id,true);if(!$this->admin($actor)||!$fresh||$fresh['activation_operation_id']!==null){$this->pdo->rollBack();return ['ok'=>false,'error'=>'activation_pending'];}
            $this->pdo->prepare("INSERT INTO project_client_operations (actor_id,operation_id,project_id,operation_kind,payload_hash,state) VALUES (?,?,?,'activate',?,'executing')")->execute([$actor,$operation,$id,$fingerprint]);
            $this->pdo->prepare('UPDATE project_client_connections SET activation_actor_id=?,activation_operation_id=? WHERE project_id=?')->execute([$actor,$operation,$id]);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();return ['ok'=>false,'error'=>'save_outcome_unknown','operation_id'=>$operation];}
        $before=function(string $kind,string $url)use($actor,$id,$operation,$fingerprint): bool {
            if($kind!=='register'||!$this->admin($actor))return false;
            $this->pdo->beginTransaction();
            try{
                $row=$this->project($id,true);$entry=$row?ProjectMaxConnection::resolve((string)$row['project_key']):null;$op=$this->storedOperation($actor,$operation);
                if(!$this->admin($actor)||!$entry||!hash_equals($fingerprint,$this->fingerprint($entry))||!ProjectMaxWebhookSetup::activeDirectory($entry)||!$op||$op['state']!=='executing'||(int)$op['mutation_claimed']!==0||(int)$row['activation_actor_id']!==$actor||$row['activation_operation_id']!==$operation){$this->pdo->rollBack();return false;}
                $this->pdo->prepare('UPDATE project_client_operations SET mutation_claimed=1 WHERE actor_id=? AND operation_id=?')->execute([$actor,$operation]);$this->pdo->commit();return true;
            }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();return false;}
        };
        $result=ProjectMaxWebhookSetup::activate($connection,$this->provider,$this->probe,null,$before,static fn()=>($row['registration_fingerprint']??null)===$fingerprint);
        $this->pdo->beginTransaction();
        try{
            $fresh=$this->project($id,true);$op=$this->storedOperation($actor,$operation);
            if($op&&$op['state']==='cancelled'){$this->pdo->rollBack();return $this->admin($actor)?self::savedResult($op):['ok'=>false,'error'=>'forbidden'];}
            if(!$fresh||!$op||(int)$fresh['activation_actor_id']!==$actor||$fresh['activation_operation_id']!==$operation)throw new RuntimeException('context_stale');
            $confirmed=($result['state']??null)==='active'&&!empty($result['receipt']['registration_confirmed']);
            $attempted=(int)$op['mutation_claimed']===1;$state=$confirmed?'complete':($attempted?'unknown':'complete');
            $result+=['operation_id'=>$operation,'project_id'=>$id];
            $this->pdo->prepare('UPDATE project_client_operations SET state=?,result_json=? WHERE actor_id=? AND operation_id=?')->execute([$state,json_encode($result,JSON_THROW_ON_ERROR),$actor,$operation]);
            if($confirmed)$this->pdo->prepare('UPDATE project_client_connections SET registration_fingerprint=?,last_check_json=? WHERE project_id=?')->execute([$fingerprint,json_encode(['ok'=>true,'state'=>'ready','reason'=>'already_active'],JSON_THROW_ON_ERROR),$id]);
            elseif(!$attempted)$this->pdo->prepare('UPDATE project_client_connections SET activation_actor_id=NULL,activation_operation_id=NULL WHERE project_id=?')->execute([$id]);
            $this->pdo->commit();return $this->admin($actor)?$result:['ok'=>false,'error'=>'forbidden'];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();return ['ok'=>false,'error'=>'save_outcome_unknown','operation_id'=>$operation];}
    }
}
