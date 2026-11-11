<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

final class RegionalManagerProvisionFailure extends RuntimeException {}

/** Fixed operator program. No HTTP entrypoint, account reset, or replay of an unknown write. */
final class RegionalManagerProvision
{
    public const CONTRACT='anytour-regional-manager-provision-v1';
    public const ROOT='/var/www/anytoour/data/www/app.anytoour.ru';
    public const CONFIG='/var/www/anytoour/data/config/max-search.php';
    public const ACTOR=2;
    public const PROJECT_ID=1;
    public const COMMON_GROUP_ID=1;
    public const LINK_CONTEXT='c11c1105804124449759d8327664fc731c3905f30f1c6dd1371eddb1fa965c34';
    public const TTL=1800;
    private const JSON=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    private string $config;
    private string $recipient;
    private $provenance;
    private $authority;
    private $directory;
    private $clock;
    private $keyFactory;
    private array $keyExportOptions;
    private $uidProvider;

    /** Fixture injection is PHP-CLI-only; production paths and callbacks are fixed below. */
    public function __construct(string$config,string$recipient,callable$provenance,callable$authority,callable$directory,?callable$clock=null,?callable$keyFactory=null,array$keyExportOptions=[],?callable$uidProvider=null)
    {
        $this->config=$config;$this->recipient=$recipient;$this->provenance=$provenance;
        $this->authority=$authority;$this->directory=$directory;$this->clock=$clock??static fn():int=>time();
        $this->keyFactory=$keyFactory??static fn()=>openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>3072]);
        $this->keyExportOptions=$keyExportOptions;
        $this->uidProvider=$uidProvider??static fn()=>function_exists('posix_geteuid')?posix_geteuid():null;
        self::publicKey($recipient);
    }
    public static function check(bool$ok,string$reason):void{if(!$ok)throw new RegionalManagerProvisionFailure($reason);}
    private static function json(array$value):string{return json_encode($value,self::JSON);}
    private function path(string$suffix):string{return$this->config.'.regional-managers-v1-'.$suffix;}
    private static function publicKey(string$pem)
    {
        self::check(strlen($pem)<=8192,'RECIPIENT_INVALID');$key=openssl_pkey_get_public($pem);
        $details=$key?openssl_pkey_get_details($key):false;
        self::check(is_array($details)&&$details['type']===OPENSSL_KEYTYPE_RSA&&$details['bits']===3072,'RECIPIENT_INVALID');return$key;
    }
    public static function seal(array$payload,string$publicKey,string$aad):array
    {
        $plain=self::json($payload);self::check(strlen($plain)<=262144,'PACKET_LIMIT');
        $key=random_bytes(32);$nonce=random_bytes(12);$tag='';
        try{
            $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
            self::check(is_string($cipher)&&openssl_public_encrypt($key,$wrapped,self::publicKey($publicKey),OPENSSL_PKCS1_OAEP_PADDING),'ENCRYPTION_FAILED');
            return['version'=>1,'algorithm'=>'RSA-OAEP-SHA1+A256GCM','encrypted_key'=>base64_encode($wrapped),
                'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)];
        }finally{if(function_exists('sodium_memzero')){sodium_memzero($key);sodium_memzero($plain);}}
    }
    public static function open(array$packet,string$privateKey,string$aad):array
    {
        $keys=array_keys($packet);sort($keys);
        self::check($keys===['algorithm','ciphertext','encrypted_key','nonce','tag','version']
            &&($packet['version']??null)===1&&($packet['algorithm']??'')==='RSA-OAEP-SHA1+A256GCM','ENVELOPE_INVALID');
        $values=[];
        foreach(['encrypted_key','nonce','tag','ciphertext']as$field){
            self::check(is_string($packet[$field])&&strlen($packet[$field])<=349528,'ENVELOPE_INVALID');
            $value=base64_decode($packet[$field],true);self::check(is_string($value)&&base64_encode($value)===$packet[$field],'ENVELOPE_INVALID');$values[$field]=$value;
        }
        self::check(strlen($values['encrypted_key'])===384&&strlen($values['nonce'])===12&&strlen($values['tag'])===16&&strlen($values['ciphertext'])<=262144,'ENVELOPE_INVALID');
        self::check(openssl_private_decrypt($values['encrypted_key'],$key,$privateKey,OPENSSL_PKCS1_OAEP_PADDING)&&strlen($key)===32,'DECRYPTION_FAILED');
        try{
            $plain=openssl_decrypt($values['ciphertext'],'aes-256-gcm',$key,OPENSSL_RAW_DATA,$values['nonce'],$values['tag'],$aad);
            self::check(is_string($plain)&&strlen($plain)<=262144,'DECRYPTION_FAILED');
            $payload=json_decode($plain,true,16,JSON_THROW_ON_ERROR);self::check(is_array($payload),'ENVELOPE_INVALID');return$payload;
        }finally{if(function_exists('sodium_memzero')){sodium_memzero($key);if(isset($plain)&&is_string($plain))sodium_memzero($plain);}}
    }
    private function owner(array$stat):bool{$uid=($this->uidProvider)();return is_int($uid)&&(int)$stat['uid']===$uid;}
    private function privateDirectory():void
    {
        $dir=dirname($this->config);$stat=@lstat($dir);$root=self::ROOT;
        self::check(is_array($stat)&&($stat['mode']&0170000)===0040000&&($stat['mode']&0022)===0&&$this->owner($stat)
            &&realpath($dir)===$dir&&$dir!==$root&&!str_starts_with($dir,$root.'/'),'PRIVATE_DIRECTORY_UNAVAILABLE');
        $config=@lstat($this->config);
        self::check(is_array($config)&&($config['mode']&0170000)===0100000&&($config['mode']&0007)===0&&$this->owner($config),'PRIVATE_CONFIG_UNAVAILABLE');
    }
    private function readPrivate(string$path,int$limit):string
    {
        clearstatcache(true,$path);$named=@lstat($path);
        self::check(is_array($named)&&($named['mode']&0170000)===0100000&&($named['mode']&0777)===0600
            &&(int)$named['nlink']===1&&$this->owner($named)&&$named['size']>0&&$named['size']<=$limit,'PRIVATE_STATE_INVALID');
        $handle=@fopen($path,'rb');self::check(is_resource($handle),'PRIVATE_STATE_INVALID');
        try{$opened=fstat($handle);self::check($opened['ino']===$named['ino']&&$opened['dev']===$named['dev'],'PRIVATE_STATE_CHANGED');
            $raw=stream_get_contents($handle,$limit+1);self::check(is_string($raw)&&strlen($raw)<=$limit,'PRIVATE_STATE_INVALID');return$raw;
        }finally{fclose($handle);}
    }
    private function writeNew(string$path,string$raw):void
    {
        $old=umask(0077);try{$handle=@fopen($path,'x+b');}finally{umask($old);}
        self::check(is_resource($handle),'PRIVATE_STATE_EXISTS');
        try{self::check(chmod($path,0600)&&fwrite($handle,$raw)===strlen($raw)&&fflush($handle)
            &&function_exists('fsync')&&fsync($handle),'PRIVATE_WRITE_FAILED');}finally{fclose($handle);}
        $this->syncDirectory();
    }
    private function syncDirectory():void
    {
        self::check(function_exists('fsync'),'PRIVATE_IO_CAPABILITY_REQUIRED');
        $handle=@fopen(dirname($this->config),'rb');self::check(is_resource($handle),'PRIVATE_DIRECTORY_SYNC_FAILED');
        try{self::check(fsync($handle),'PRIVATE_DIRECTORY_SYNC_FAILED');}finally{fclose($handle);}
    }
    private function save(array$state):void
    {
        $raw=self::json($state)."\n";self::check(strlen($raw)<=1048576,'STATE_LIMIT');
        $temporary=$this->path('state-'.bin2hex(random_bytes(12)).'.tmp');
        try{$this->writeNew($temporary,$raw);self::check(rename($temporary,$this->path('state.json')),'PRIVATE_WRITE_FAILED');$this->syncDirectory();}
        finally{if(is_file($temporary))@unlink($temporary);}
    }
    private function state():?array
    {
        $path=$this->path('state.json');if(!file_exists($path)&&!is_link($path))return null;
        $state=json_decode($this->readPrivate($path,1048576),true,32,JSON_THROW_ON_ERROR);
        self::check(is_array($state)&&($state['contract']??null)===self::CONTRACT
            &&in_array($state['phase']??'', ['prepared','applying','configured'],true)
            &&is_string($state['request_id']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$state['request_id'])
            &&is_string($state['application_sha']??null)&&preg_match('/\A[a-f0-9]{40}\z/D',$state['application_sha'])
            &&is_int($state['expires_at']??null)&&is_string($state['recipient_sha256']??null)
            &&hash_equals(hash('sha256',$this->recipient),$state['recipient_sha256']),'PRIVATE_STATE_INVALID');return$state;
    }
    private function locked(string$sha,string$mode,callable$action):array
    {
        self::check(preg_match('/\A[a-f0-9]{40}\z/D',$sha)===1&&($this->provenance)($sha)===true,'APPLICATION_SHA_MISMATCH');
        $this->privateDirectory();$path=$this->path('lock');
        if($mode==='status'&&!file_exists($path)&&!is_link($path)){
            self::check(!file_exists($this->path('state.json'))&&!is_link($this->path('state.json'))
                &&!file_exists($this->path('private.pem'))&&!is_link($this->path('private.pem')),'PRIVATE_STATE_INVALID');return$action(null);
        }
        if(!file_exists($path)&&!is_link($path))$this->writeNew($path,"regional-manager-provision-lock\n");
        $this->readPrivate($path,128);$named=lstat($path);$handle=@fopen($path,$mode==='status'?'rb':'r+b');
        self::check(is_resource($handle),'LOCK_UNAVAILABLE');
        try{$opened=fstat($handle);self::check($opened['ino']===$named['ino']&&$opened['dev']===$named['dev'],'PRIVATE_STATE_CHANGED');
            self::check(flock($handle,($mode==='status'?LOCK_SH:LOCK_EX)|LOCK_NB),'OPERATION_BUSY');return$action($this->state());
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    private function packet(array$payload):array{return self::seal($payload,$this->recipient,self::CONTRACT);}
    private function current(string$sha,bool$write):array
    {
        self::check(($this->provenance)($sha)===true,'APPLICATION_SHA_MISMATCH');($this->authority)($write);
        return($this->directory)('preview',[]);
    }
    public function prepare(string$sha):array
    {
        return$this->locked($sha,'prepare',function(?array$state)use($sha):array{
            $preview=$this->current($sha,true);$digest=hash('sha256',self::json($preview));
            if($state!==null){
                self::check($state['application_sha']===$sha,'REQUEST_BINDING_MISMATCH');
                if($state['phase']==='configured')return$state['result_packet'];
                self::check($state['phase']==='prepared','UNKNOWN_WRITE_HELD');
                self::check($state['expires_at']>=($this->clock)(),'REQUEST_EXPIRED');
                self::check(hash_equals($state['preview_digest'],$digest),'PREVIEW_CHANGED');return$state['prepare_packet'];
            }
            self::check(!file_exists($this->path('private.pem'))&&!is_link($this->path('private.pem')),'PRIVATE_KEY_EXISTS');
            $key=($this->keyFactory)();
            self::check($key!==false&&openssl_pkey_export($key,$private,null,$this->keyExportOptions),'KEY_GENERATION_FAILED');$details=openssl_pkey_get_details($key);
            self::check(is_array($details)&&$details['bits']===3072,'KEY_GENERATION_FAILED');
            $state=['contract'=>self::CONTRACT,'phase'=>'prepared','application_sha'=>$sha,'actor_id'=>self::ACTOR,'project_id'=>self::PROJECT_ID,
                'request_id'=>bin2hex(random_bytes(16)),'created_at'=>($this->clock)(),'expires_at'=>($this->clock)()+self::TTL,
                'recipient_sha256'=>hash('sha256',$this->recipient),'preview_digest'=>$digest];
            $state['prepare_packet']=$this->packet($state+['server_public_key_pem'=>$details['key'],'preview'=>$preview,'database_changed'=>false]);
            $this->writeNew($this->path('private.pem'),$private);
            try{$this->save($state);}catch(Throwable$error){@unlink($this->path('private.pem'));throw$error;}
            finally{if(function_exists('sodium_memzero'))sodium_memzero($private);}
            return$state['prepare_packet'];
        });
    }
    private static function label($value):bool{return is_string($value)&&$value!==''&&trim($value)===$value&&strlen($value)<=191&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1f\x7f]/',$value);}
    private static function plan(array$plan,array$state):array
    {
        $keys=array_keys($plan);sort($keys);
        self::check($keys===['actor_id','application_sha','contract','expires_at','preview_digest','project_id','project_key','request_id','targets']
            &&$plan['contract']===self::CONTRACT&&$plan['actor_id']===self::ACTOR&&$plan['project_id']===self::PROJECT_ID&&$plan['project_key']==='anytour'
            &&$plan['application_sha']===$state['application_sha']&&$plan['request_id']===$state['request_id']&&$plan['expires_at']===$state['expires_at']
            &&is_string($plan['preview_digest'])&&hash_equals($state['preview_digest'],$plan['preview_digest'])
            &&is_array($plan['targets'])&&array_is_list($plan['targets'])&&count($plan['targets'])===2,'PLAN_INVALID');
        $targets=[];$logins=[];$groups=[];
        foreach($plan['targets']as$target){
            self::check(is_array($target),'PLAN_INVALID');$keys=array_keys($target);sort($keys);
            self::check($keys===['display_name','group_key','group_name','login','territory_region_id']
                &&in_array($target['territory_region_id']??null,['62','11131'],true)
                &&is_string($target['login']??null)&&preg_match('/\A[A-Za-z0-9._@+-]{3,191}\z/D',$target['login'])
                &&is_string($target['group_key']??null)&&preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$target['group_key'])
                &&self::label($target['display_name']??null)&&self::label($target['group_name']??null),'PLAN_INVALID');
            $territory=$target['territory_region_id'];self::check(!isset($targets[$territory])&&!isset($logins[strtolower($target['login'])])&&!isset($groups[$target['group_key']]),'PLAN_INVALID');
            $targets[$territory]=$target;$logins[strtolower($target['login'])]=true;$groups[$target['group_key']]=true;
        }
        self::check(count($targets)===2&&isset($targets['62'],$targets['11131']),'PLAN_INVALID');return array_values($targets);
    }
    public function apply(string$sha,string$raw):array
    {
        self::check(strlen($raw)>0&&strlen($raw)<=524288,'ENVELOPE_INVALID');
        return$this->locked($sha,'apply',function(?array$state)use($sha,$raw):array{
            self::check($state!==null&&$state['application_sha']===$sha,'REQUEST_BINDING_MISMATCH');
            $envelopeHash=hash('sha256',$raw);
            if($state['phase']==='configured'){
                self::check(hash_equals($state['apply_envelope_sha256'],$envelopeHash),'REQUEST_BINDING_MISMATCH');
                if(file_exists($this->path('private.pem'))||is_link($this->path('private.pem'))){$this->readPrivate($this->path('private.pem'),8192);self::check(unlink($this->path('private.pem')),'PRIVATE_KEY_CLEANUP_REQUIRED');$this->syncDirectory();}
                return$state['result_packet'];
            }
            self::check($state['phase']==='prepared','UNKNOWN_WRITE_HELD');self::check($state['expires_at']>=($this->clock)(),'REQUEST_EXPIRED');
            $packet=json_decode($raw,true,8,JSON_THROW_ON_ERROR);self::check(is_array($packet),'ENVELOPE_INVALID');
            $private=$this->readPrivate($this->path('private.pem'),8192);
            try{$plan=self::open($packet,$private,self::CONTRACT.':apply:'.$state['request_id']);}
            finally{if(function_exists('sodium_memzero'))sodium_memzero($private);}
            $targets=self::plan($plan,$state);$preview=$this->current($sha,true);
            self::check(hash_equals($state['preview_digest'],hash('sha256',self::json($preview))),'PREVIEW_CHANGED');
            $admission=($this->directory)('validate_plan',['targets'=>$targets]);
            self::check(($admission['common_group_scope_empty']??null)===true,'COMMON_GROUP_SCOPED');
            $credentials=[];$refs=[];
            foreach($targets as$target){
                $password=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
                $credentials[]=['territory_region_id'=>$target['territory_region_id'],'login'=>$target['login'],'password'=>$password];
                $refs[]=['territory_region_id'=>$target['territory_region_id'],'login_sha256'=>hash('sha256',$target['login']),
                    'display_name_sha256'=>hash('sha256',$target['display_name']),'group_key_sha256'=>hash('sha256',$target['group_key']),
                    'group_name_sha256'=>hash('sha256',$target['group_name']),'manager_id'=>0,'group_id'=>0];
            }
            $state['phase']='applying';$state['apply_envelope_sha256']=$envelopeHash;$state['plan_sha256']=hash('sha256',self::json($plan));
            $state['refs']=$refs;$state['admission']=$admission;$state['completed_steps']=[];
            $state['credentials_packet']=$this->packet(['contract'=>self::CONTRACT,'request_id'=>$state['request_id'],'credentials'=>$credentials,'warning'=>'Do not deliver until final configuration is verified']);
            // Durable encrypted recovery and UNKNOWN fence precede the FIRST DB write.
            $this->save($state);
            try{
                foreach($targets as$index=>$target){
                    $this->current($sha,true);
                    $result=($this->directory)('create_manager',['target'=>$target,'password'=>$credentials[$index]['password']]);
                    self::check(!empty($result['ok'])&&is_int($result['manager_id']??null)&&$result['manager_id']>0,'MANAGER_CREATE_UNKNOWN');
                    $state['refs'][$index]['manager_id']=$result['manager_id'];$state['completed_steps'][]='manager:'.$target['territory_region_id'];$this->save($state);
                    $this->current($sha,true);
                    $result=($this->directory)('create_group',['target'=>$target,'manager_id'=>$state['refs'][$index]['manager_id']]);
                    self::check(!empty($result['ok'])&&is_int($result['group_id']??null)&&$result['group_id']>0,'GROUP_CREATE_UNKNOWN');
                    $state['refs'][$index]['group_id']=$result['group_id'];$state['completed_steps'][]='group:'.$target['territory_region_id'];$this->save($state);
                }
                $this->current($sha,true);$result=($this->directory)('append_common',['manager_ids'=>array_column($state['refs'],'manager_id')]);
                self::check(!empty($result['ok']),'COMMON_APPEND_UNKNOWN');$state['completed_steps'][]='common_group';$this->save($state);
                $this->current($sha,true);$readback=($this->directory)('readback',['refs'=>$state['refs'],'admission'=>$admission]);
                self::check(($readback['complete']??null)===true,'FINAL_READBACK_MISMATCH');
                $state['phase']='configured';$state['result_packet']=$this->packet(['contract'=>self::CONTRACT,'phase'=>'configured','request_id'=>$state['request_id'],
                    'application_sha'=>$sha,'actor_id'=>self::ACTOR,'project_key'=>'anytour','project_id'=>self::PROJECT_ID,
                    'credentials'=>$credentials,'targets'=>$state['refs'],'readback'=>$readback,'database_changed'=>true]);
                $this->save($state);self::check(unlink($this->path('private.pem')),'PRIVATE_KEY_CLEANUP_REQUIRED');$this->syncDirectory();return$state['result_packet'];
            }finally{
                foreach($credentials as&$credential)if(function_exists('sodium_memzero'))sodium_memzero($credential['password']);unset($credential);
            }
        });
    }
    public function status(string$sha):array
    {
        return$this->locked($sha,'status',function(?array$state)use($sha):array{
            if($state===null){
                self::check(!file_exists($this->path('private.pem'))&&!is_link($this->path('private.pem')),'PRIVATE_STATE_INVALID');
                return$this->packet(['contract'=>self::CONTRACT,'phase'=>'absent','database_changed'=>false]);
            }
            self::check($state['application_sha']===$sha,'REQUEST_BINDING_MISMATCH');$this->current($sha,false);
            $readback=isset($state['refs'])?($this->directory)('readback',['refs'=>$state['refs'],'admission'=>$state['admission']]):null;
            return$this->packet(['contract'=>self::CONTRACT,'phase'=>$state['phase'],'request_id'=>$state['request_id'],
                'application_sha'=>$sha,'expires_at'=>$state['expires_at'],'expired'=>($this->clock)()>$state['expires_at'],
                'completed_steps'=>$state['completed_steps']??[],'targets'=>$state['refs']??[],'readback'=>$readback,
                'prepare_packet'=>$state['prepare_packet']??null,'credentials_packet'=>$state['credentials_packet']??null,
                'result_packet'=>$state['result_packet']??null,'private_key_cleanup_required'=>$state['phase']==='configured'&&(file_exists($this->path('private.pem'))||is_link($this->path('private.pem'))),
                'write_outcome'=>$state['phase']==='applying'?'unknown':$state['phase'],'database_changed'=>false]);
        });
    }
}

/** Fixed production directory adapter. Reads only staff/configuration metadata. */
final class RegionalManagerProvisionDirectory
{
    private static bool $loaded=false;
    private static function pdo():PDO
    {
        if(!self::$loaded){
            $level=ob_get_level();ob_start();
            try{
                require_once RegionalManagerProvision::ROOT.'/config.php';
                foreach(['AdminDirectoryService','RoutingAdminService','RegionalRoutingScopeService','ManagerPreparationOAuth']as$class)
                    require_once RegionalManagerProvision::ROOT.'/services/'.$class.'.php';
                RegionalManagerProvision::check(ProjectConfig::projectId()==='anytour','PROJECT_MISMATCH');
                ProjectAccessService::initializeReadOnly();self::$loaded=true;
            }finally{while(ob_get_level()>$level)ob_end_clean();}
        }
        return ConversationDb::connection();
    }
    private static function rows(string$sql,array$args=[],int$limit=500):array
    {
        $q=self::pdo()->prepare($sql);$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        RegionalManagerProvision::check(count($rows)<=$limit,'DIRECTORY_LIMIT');return$rows;
    }
    public static function authority(bool$write):void
    {
        $pdo=self::pdo();$project=self::rows("SELECT id,project_key,display_name,is_active FROM projects WHERE id=1 AND project_key='anytour' LIMIT 1",[],1)[0]??null;
        RegionalManagerProvision::check($project!==null&&(int)$project['is_active']===1,'PROJECT_MISMATCH');
        $actor=ManagerAuthService::byId(RegionalManagerProvision::ACTOR);
        RegionalManagerProvision::check(ManagerAuthService::isAdmin($actor)&&ProjectAccessService::canAccess(RegionalManagerProvision::ACTOR,'anytour'),'ACTOR_UNAVAILABLE');
        if(!$write)return;
        // This matches the already authenticated caller receipt. It never selects
        // a first administrator or reads a token/password/hash. SSH plus the
        // explicit owner instruction provides the separate write authority.
        $grants=self::rows("SELECT grant_id,admin_id FROM manager_preparation_oauth_grants WHERE project_key='anytour' AND revoked_at IS NULL AND expires_at>? ORDER BY grant_id LIMIT 501",[time()]);
        $oauth=new ManagerPreparationOAuth($pdo);$actors=[];
        foreach($grants as$grant){
            $metadata=$oauth->currentAuthorizationMetadata((string)$grant['grant_id'],(int)$grant['admin_id'],[ManagerPreparationOAuth::BOOKING_SCOPE]);
            if($metadata&&hash_equals(RegionalManagerProvision::LINK_CONTEXT,$metadata['link_context']))$actors[(int)$grant['admin_id']]=true;
        }
        RegionalManagerProvision::check(array_keys($actors)===[RegionalManagerProvision::ACTOR],'ACTOR_RECEIPT_MISMATCH');
    }
    private static function common():array
    {
        $group=self::rows('SELECT id,project_id,group_key,display_name,is_active FROM manager_groups WHERE id=1 LIMIT 1',[],1)[0]??null;
        RegionalManagerProvision::check($group!==null&&(int)$group['project_id']===1&&(int)$group['is_active']===1,'COMMON_GROUP_MISMATCH');
        $members=array_map('intval',array_column(self::rows('SELECT manager_id FROM manager_group_members WHERE group_id=1 ORDER BY manager_id LIMIT 10001',[],10000),'manager_id'));
        $headers=self::rows('SELECT group_id,territory_region_id,anchor_hierarchy_json,prefix_json,catalog_sha256 FROM manager_group_territories WHERE group_id=1 ORDER BY territory_region_id LIMIT 21',[],20);
        $q=self::pdo()->query('SELECT territory_region_id,region_id FROM manager_group_territory_regions WHERE group_id=1 ORDER BY territory_region_id,region_id LIMIT 200001');
        $hash=hash_init('sha256');$count=0;
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            RegionalManagerProvision::check(++$count<=200000,'DIRECTORY_LIMIT');hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");
        }
        return['group'=>$group,'member_ids'=>$members,'header_count'=>count($headers),'headers_sha256'=>hash('sha256',json_encode($headers,JSON_THROW_ON_ERROR)),
            'descendants_sha256'=>hash_final($hash),'descendant_count'=>$count];
    }
    private static function sources():array
    {
        return self::rows('SELECT id,source_key,channel,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes,is_active FROM conversation_sources WHERE project_id=1 ORDER BY id LIMIT 501');
    }
    private static function preview():array
    {
        $project=self::rows("SELECT id,project_key,display_name,is_active FROM projects WHERE id=1 AND project_key='anytour' LIMIT 1",[],1)[0]??null;
        $managers=self::rows('SELECT m.id,m.login,m.display_name,m.role,m.is_active FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=1 ORDER BY m.id LIMIT 501');
        $groups=self::rows('SELECT id,project_id,group_key,display_name,is_active FROM manager_groups WHERE project_id=1 ORDER BY id LIMIT 201',[],200);
        return['actor_id'=>RegionalManagerProvision::ACTOR,'project'=>$project,'managers'=>$managers,'groups'=>$groups,'common_group'=>self::common(),
            'sources'=>self::sources(),'catalog_manifest_sha256'=>RegionalTerritoryCatalog::manifestHash()];
    }
    private static function regionDigest(array$ids):string{sort($ids,SORT_STRING);return hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));}
    public static function run(string$action,array$input):array
    {
        $pdo=self::pdo();
        if($action==='preview')return self::preview();
        if($action==='validate_plan'){
            foreach($input['targets']as$target){
                RegionalManagerProvision::check(self::rows('SELECT id FROM managers WHERE login=? LIMIT 1',[$target['login']],1)===[],'ACCOUNT_CONFLICT');
                RegionalManagerProvision::check(self::rows('SELECT id FROM manager_groups WHERE project_id=1 AND group_key=? LIMIT 1',[$target['group_key']],1)===[],'GROUP_CONFLICT');
            }
            $receipts=[];
            foreach(RegionalTerritoryCatalog::selected(['62','11131'])as$scope)$receipts[$scope['region_id']]=[
                'region_count'=>count($scope['region_ids']),'regions_sha256'=>self::regionDigest($scope['region_ids']),'catalog_sha256'=>$scope['catalog_sha256']];
            RegionalManagerProvision::check(count($receipts)===2,'TERRITORIES_UNAVAILABLE');
            $common=self::common();RegionalManagerProvision::check($common['header_count']===0&&$common['descendant_count']===0,'COMMON_GROUP_SCOPED');
            return['scope_receipts'=>$receipts,'common_group'=>$common,'common_group_scope_empty'=>true,'sources_sha256'=>hash('sha256',json_encode(self::sources(),JSON_THROW_ON_ERROR))];
        }
        if($action==='create_manager'){
            $target=$input['target'];
            return AdminDirectoryService::saveManagerForProject(['manager_id'=>0,'login'=>$target['login'],'display_name'=>$target['display_name'],
                'password'=>$input['password'],'email'=>'','role'=>'manager','is_active'=>1,'priority'=>0,
                'project_ids'=>[1],'regional_project_ids'=>[1]],RegionalManagerProvision::ACTOR,'anytour');
        }
        if($action==='create_group'){
            $target=$input['target'];
            return RoutingAdminService::saveGroupResult(RegionalManagerProvision::ACTOR,'anytour',0,$target['group_key'],$target['group_name'],
                [$input['manager_id']],[$target['territory_region_id']]);
        }
        if($action==='append_common')return RoutingAdminService::appendMembersResult(RegionalManagerProvision::ACTOR,'anytour',1,$input['manager_ids']);
        if($action==='readback'){
            $complete=true;$results=[];$newIds=[];$admission=$input['admission'];
            foreach($input['refs']as$ref){
                $mid=(int)$ref['manager_id'];$gid=(int)$ref['group_id'];$territory=$ref['territory_region_id'];$valid=$mid>0&&$gid>0;
                $manager=$mid>0?(self::rows('SELECT id,login,display_name,role,is_active,is_working,priority FROM managers WHERE id=? LIMIT 1',[$mid],1)[0]??null):null;
                $group=$gid>0?(self::rows('SELECT id,project_id,group_key,display_name,is_active FROM manager_groups WHERE id=? LIMIT 1',[$gid],1)[0]??null):null;
                $valid=$valid&&$manager!==null&&$group!==null;
                if($manager!==null){
                    $projects=array_map('intval',array_column(self::rows('SELECT project_id FROM manager_projects WHERE manager_id=? ORDER BY project_id',[$mid],100),'project_id'));
                    $managerGroups=array_map('intval',array_column(self::rows('SELECT group_id FROM manager_group_members WHERE manager_id=? ORDER BY group_id',[$mid],100),'group_id'));
                    $expectedGroups=[1,$gid];sort($expectedGroups,SORT_NUMERIC);
                    $valid=$valid&&hash_equals($ref['login_sha256'],hash('sha256',$manager['login']))&&hash_equals($ref['display_name_sha256'],hash('sha256',(string)$manager['display_name']))
                        &&$manager['role']==='manager'&&(int)$manager['is_active']===1&&(int)$manager['is_working']===0&&(int)$manager['priority']===0
                        &&$projects===[1]&&RegionalRoutingScopeService::regionalProjectIds($mid)===[1]&&$managerGroups===$expectedGroups;
                }
                if($group!==null){
                    $members=array_map('intval',array_column(self::rows('SELECT manager_id FROM manager_group_members WHERE group_id=? ORDER BY manager_id',[$gid],100),'manager_id'));
                    $headers=RegionalRoutingScopeService::groupScopes($gid);
                    $ids=array_column(self::rows('SELECT region_id FROM manager_group_territory_regions WHERE group_id=? AND territory_region_id=? ORDER BY region_id',[$gid,$territory],200000),'region_id');
                    $receipt=$admission['scope_receipts'][$territory]??null;
                    $valid=$valid&&(int)$group['project_id']===1&&(int)$group['is_active']===1
                        &&hash_equals($ref['group_key_sha256'],hash('sha256',$group['group_key']))&&hash_equals($ref['group_name_sha256'],hash('sha256',$group['display_name']))
                        &&$members===[$mid]&&count($headers)===1&&$headers[0]['region_id']===$territory&&$headers[0]['valid']===true
                        &&is_array($receipt)&&count($ids)===$receipt['region_count']&&hash_equals($receipt['regions_sha256'],self::regionDigest($ids))
                        &&hash_equals($receipt['catalog_sha256'],$headers[0]['catalog_sha256']);
                }
                $complete=$complete&&$valid;$newIds[]=$mid;$results[]=['manager_id'=>$mid,'group_id'=>$gid,'territory_region_id'=>$territory,'matches'=>$valid];
            }
            $common=self::common();$before=$admission['common_group'];
            $commonValid=$common['header_count']===0&&$common['descendant_count']===0
                &&$common['group']===$before['group']&&$common['headers_sha256']===$before['headers_sha256']
                &&$common['descendants_sha256']===$before['descendants_sha256']&&$common['descendant_count']===$before['descendant_count']
                &&!array_diff([...$before['member_ids'],...$newIds],$common['member_ids']);
            $sourcesValid=hash_equals($admission['sources_sha256'],hash('sha256',json_encode(self::sources(),JSON_THROW_ON_ERROR)));
            return['complete'=>$complete&&$commonValid&&$sourcesValid,'targets'=>$results,'common_group_preserved'=>$commonValid,'sources_unchanged'=>$sourcesValid];
        }
        throw new RegionalManagerProvisionFailure('INVALID_OPERATION');
    }
}

if(!defined('REGIONAL_MANAGER_PROVISION_LIBRARY_ONLY')){
    ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);$level=ob_get_level();ob_start();
    try{
        $mode=defined('REGIONAL_PROVISION_MODE')?REGIONAL_PROVISION_MODE:($argv[1]??'');
        $sha=defined('REGIONAL_PROVISION_EXPECTED_SHA')?REGIONAL_PROVISION_EXPECTED_SHA:($argv[2]??'');
        RegionalManagerProvision::check(in_array($mode,['prepare','apply','status'],true),'INVALID_OPERATION');
        RegionalManagerProvision::check(function_exists('posix_geteuid')&&function_exists('fsync'),'PRIVATE_IO_CAPABILITY_REQUIRED');
        RegionalManagerProvision::check($mode==='status'||getenv('ALLOW_REGIONAL_MANAGER_PROVISION_WRITE')==='1','EXPLICIT_WRITE_GUARD_REQUIRED');
        $recipient=<<<'PUBLIC_KEY'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAyFF+OvrOZbKf5G2n8coS
l5TXQLsI3lDnVlqlNJDOalEMZhWMehwK4TUM9DILEeIudGAlPZHLIca/TbzSuU2+
/gFtN8q8pgFD703bOAHbLtlBg3iVB5qDnCMJXHlZ1Yrs44ZEaefDqvHDeLLuuJRd
nreBn8CGh8RVMLQsAPEepRRnq8rH63AvU2NiX62wxvAEGTvhIkb3OKcvM1HFEF41
SgEA9pGST585ZXECQ0wr4qB9BO3pXZ/UJXDlFOjn2MfoAdFAYE53zFEs3DvMvcZ3
u1NZhG2RnLWWpDg2lXxXWj78py5A1104ry3mOr/PL0pR1U8UOLgQ3/aZhwAX3BjF
Yhk4E0V3hEwD6GlmLeans2lAB/wQMXNNVHMQq8FfhmPusprkuaZ2IXzHwiSdO2Ux
xoSGzhACbswbocxpT05xI0ziKFrfTjcYys1AnS6PUbq+VafwXjvGdvzRTmtuaxlD
wHU0TS+nUDPV+5LMTfqe91PgrgIQDEdnlOXFoNvnvY0PAgMBAAE=
-----END PUBLIC KEY-----
PUBLIC_KEY;
        $provenance=static function(string$expected):bool{
            foreach([['rev-parse','HEAD'],['diff','--quiet'],['diff','--cached','--quiet']]as$index=>$args){
                $process=@proc_open(['git','-C',RegionalManagerProvision::ROOT,...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
                if(!is_resource($process))return false;$body=stream_get_contents($pipes[1],256);fclose($pipes[1]);$code=proc_close($process);
                if($code!==0||($index===0&&trim((string)$body)!==$expected))return false;
            }return true;
        };
        $operator=new RegionalManagerProvision(RegionalManagerProvision::CONFIG,$recipient,$provenance,
            [RegionalManagerProvisionDirectory::class,'authority'],[RegionalManagerProvisionDirectory::class,'run']);
        set_time_limit(180);
        if($mode==='prepare')$packet=$operator->prepare($sha);
        elseif($mode==='status')$packet=$operator->status($sha);
        else{
            $input=defined('REGIONAL_PROVISION_ENVELOPE')?REGIONAL_PROVISION_ENVELOPE:stream_get_contents(STDIN,524289);
            RegionalManagerProvision::check(is_string($input),'ENVELOPE_INVALID');$packet=$operator->apply($sha,$input);
        }
        while(ob_get_level()>$level)ob_end_clean();
        echo 'REGIONAL_MANAGER_PROVISION_PACKET='.json_encode($packet,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }catch(Throwable$error){
        while(ob_get_level()>$level)ob_end_clean();
        $code=$error instanceof RegionalManagerProvisionFailure?$error->getMessage():'OPERATION_REFUSED';
        if(!preg_match('/\A[A-Z_]{1,80}\z/D',$code))$code='OPERATION_REFUSED';
        fwrite(STDERR,'REGIONAL_MANAGER_PROVISION_ERROR='.$code."\n");exit(1);
    }
}
