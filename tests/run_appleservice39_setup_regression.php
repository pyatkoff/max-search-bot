<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/tools/setup_appleservice39_connection.php';

$passed = 0;
function as39Check(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++; echo 'PASS '.$name."\n";
}
function as39Refuses(string $name, callable $call, string $reason): void
{
    try { $call(); } catch (AppleService39SetupFailure $error) { as39Check($name, $error->getMessage() === $reason); return; }
    as39Check($name, false);
}
function as39Remove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) as39Remove($file->getPathname());
    rmdir($path);
}
function as39Command(array $command, string $input = ''): array
{
    $process = proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('fixture_command_unavailable');
    if ($input !== '') fwrite($pipes[0], $input);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $error];
}
function as39Manifest(string $directory): array
{
    $result = [];
    foreach (new FilesystemIterator($directory,FilesystemIterator::SKIP_DOTS) as $file) {
        $result[$file->getFilename()] = [$file->getPerms() & 0777, $file->isLink() ? readlink($file->getPathname()) : hash_file('sha256',$file->getPathname())];
    }
    ksort($result); return $result;
}
function as39Connection(AppleSetupFixture $fixture, ?string $secret = null): ProjectMaxConnection
{
    require_once dirname(__DIR__).'/services/ProjectMaxConnection.php';
    $registry=AppleService39ConnectionSetup::registry($fixture->source())['value'];
    if ($secret!==null) $registry['appleservice39']['max']['webhook_secret']=$secret;
    ProjectMaxConnection::resetForTests($registry);
    try { $connection=ProjectMaxConnection::resolve('appleservice39'); }
    finally { ProjectMaxConnection::resetForTests(null); }
    if ($connection===null) throw new RuntimeException('fixture_connection_unavailable');
    return $connection;
}

final class AppleSetupFixture
{
    public string $dir;
    public string $root;
    public string $config;
    public string $sha;
    public int $now = 1900000000;
    public int $identityCalls = 0;
    public int $directoryWrites = 0;
    public string $directoryState = 'missing';
    public string $identityMode = 'good';
    public bool $directoryFails = false;
    public bool $activeAdmin = true;
    public AppleService39ConnectionSetup $setup;
    public string $token = 'SYNTHETIC_APPLE_TOKEN_not_a_credential';

    public function __construct(?string $source = null)
    {
        $this->dir = sys_get_temp_dir().'/appleservice39-setup-'.bin2hex(random_bytes(8));
        mkdir($this->dir,0700); $this->root=$this->dir.'/application'; mkdir($this->root,0700);
        mkdir($this->dir.'/config',0700); $this->config=$this->dir.'/config/max-search.php';
        $this->sha=str_repeat('a',40);
        $this->put($source ?? "<?php\n// Keep other business settings byte-for-byte.\ndefine('MAX_SEARCH_TOKEN','SYNTHETIC_OTHER_BUSINESS_TOKEN');\n\$unchanged = \"keep\\nthis\";\n");
        $this->setup=$this->instance();
    }
    public function put(string $source): void { file_put_contents($this->config,$source); chmod($this->config,0600); }
    public function source(): string { return (string)file_get_contents($this->config); }
    public function privatePath(string $suffix): string { return $this->config.'.appleservice39-'.$suffix; }
    public function instance(bool $nativeProvenance = false): AppleService39ConnectionSetup
    {
        return new AppleService39ConnectionSetup($this->root,$this->config,[$this,'identity'],[$this,'directory'],
            fn():int=>$this->now,$nativeProvenance?null:fn(string $sha):bool=>$sha===$this->sha);
    }
    public function identity(string $token): array
    {
        $this->identityCalls++;
        if ($this->identityMode==='throws') throw new RuntimeException('PRIVATE_ERROR_'.$token);
        if ($this->identityMode==='changes_config') $this->put($this->source()."// External configuration change.\n");
        if ($this->identityMode==='expires') $this->now+=AppleService39ConnectionSetup::TTL+1;
        if ($this->identityMode==='changes_source') $this->sha=str_repeat('b',40);
        return ['http'=>$this->identityMode==='unauthorized'?401:200,'transport_error'=>false,
            'data'=>['is_bot'=>$this->identityMode!=='human','username'=>$this->identityMode==='other_bot'?'another_business_bot':AppleService39ConnectionSetup::BOT,
                'user_id'=>'390521129062','description'=>'PRIVATE_RESPONSE_'.$token]];
    }
    public function directory(int $admin, bool $apply): array
    {
        if ($admin===0 && !$apply) return ['project'=>$this->directoryState==='ready'?['id'=>7,'display_name'=>'AppleService39','active'=>true]:null,
            'source'=>$this->directoryState==='ready'?['id'=>9,'source_key'=>AppleService39ConnectionSetup::SOURCE,'channel'=>'max','active'=>true]:null,
            'active_admins'=>[['id'=>17,'display_name'=>'Synthetic owner','apple_project_access'=>$this->directoryState==='ready']]];
        if ($admin!==17 || !$this->activeAdmin) throw new AppleService39SetupFailure('active_admin_required');
        if ($apply) {
            $this->directoryWrites++;
            if ($this->directoryFails) throw new AppleService39SetupFailure('source_provision_failed');
            $this->directoryState='ready';
        }
        return ['fingerprint'=>hash('sha256',$this->directoryState),'project_ready'=>$this->directoryState==='ready','source_ready'=>$this->directoryState==='ready'];
    }
    public function prepare(): array { return $this->setup->prepare($this->sha,17); }
    public function envelope(array $request, ?string $aad = null, string $digest = 'sha256'): string
    {
        $public=$this->dir.'/public.pem'; file_put_contents($public,$request['public_key_pem']); chmod($public,0600);
        $key=random_bytes(32); $nonce=random_bytes(12); $tag='';
        $ciphertext=openssl_encrypt($this->token,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad??$request['aad'],16);
        if (!is_string($ciphertext)) throw new RuntimeException('fixture_encryption_failed');
        [$code,$encrypted] = as39Command(['openssl','pkeyutl','-encrypt','-pubin','-inkey',$public,
            '-pkeyopt','rsa_padding_mode:oaep','-pkeyopt','rsa_oaep_md:'.$digest,'-pkeyopt','rsa_mgf1_md:'.$digest],$key);
        if ($code!==0) throw new RuntimeException('fixture_encryption_failed');
        return json_encode(['version'=>1,'request_id'=>$request['request_id'],'encrypted_key'=>base64_encode($encrypted),
            'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($ciphertext),'tag'=>base64_encode($tag)],JSON_THROW_ON_ERROR);
    }
    public function destroy(): void { as39Remove($this->dir); }
}

$fixtures=[];
function as39Fixture(?string $source = null): AppleSetupFixture { global $fixtures; $fixture=new AppleSetupFixture($source); $fixtures[]=$fixture; return $fixture; }

try {
    $f=as39Fixture(); $before=as39Manifest(dirname($f->config)); $status=$f->setup->status($f->sha);
    as39Check('initial inspection reports admin identities and missing config without writes',
        $status['state']==='inspected' && !$status['config_ready'] && $status['directory']['active_admins'][0]['id']===17
        && $before===as39Manifest(dirname($f->config)) && $f->identityCalls===0 && $f->directoryWrites===0);
    $request=$f->prepare(); $expiry=$request['expires_at']; $f->now+=30; $again=$f->prepare();
    as39Check('prepare reuses one request and never renews its fixed expiry',$again===$request && $expiry===$f->now-30+900);
    $private=(string)file_get_contents($f->privatePath('bootstrap.pem'));
    $public=json_encode($request,JSON_THROW_ON_ERROR);
    as39Check('only public encryption material leaves prepare',str_contains($request['public_key_pem'],'BEGIN PUBLIC KEY')
        && !str_contains($public,'PRIVATE KEY') && !str_contains($public,$private) && !str_contains($public,'SYNTHETIC_OTHER_BUSINESS_TOKEN'));
    as39Check('server private key and request use owner-only permissions',
        (fileperms($f->privatePath('bootstrap.pem'))&0777)===0600 && (fileperms($f->privatePath('bootstrap.json'))&0777)===0600);
    $old=$f->source(); $envelope=$f->envelope($request); $result=$f->setup->apply($f->sha,17,$envelope);
    $registry=AppleService39ConnectionSetup::registry($f->source())['value']; $apple=$registry['appleservice39'];
    as39Check('authenticated encrypted import adds the exact repair project connection',
        $result['state']==='configured' && $apple['project']===['id'=>'appleservice39','brand'=>['name'=>'AppleService39'],
            'business'=>['kind'=>'device_repair'],'routing'=>['source_key'=>'max:appleservice39-main']]
        && $apple['max']['bot_key']==='id390521129062_bot' && $apple['max']['token']===$f->token
        && preg_match('/\A[a-f0-9]{64}\z/',$apple['max']['webhook_secret'])===1);
    as39Check('other configuration bytes remain intact and provider work is identity only',
        str_starts_with($f->source(),$old) && $f->identityCalls===1 && $f->directoryWrites===1 && $result['subscription_writes']===0);
    as39Check('success destroys the one-use private key and returns no token/secret',
        !file_exists($f->privatePath('bootstrap.pem')) && !str_contains(json_encode($result),$f->token)
        && !str_contains(json_encode($result),$apple['max']['webhook_secret']) && (fileperms($f->config)&0777)===0600);
    $installed=$f->source();
    as39Refuses('same envelope cannot repeat an import',fn()=>$f->setup->apply($f->sha,17,$envelope),'request_consumed');
    as39Check('replay leaves provider/database/config unchanged',$f->identityCalls===1 && $f->directoryWrites===1 && $f->source()===$installed);
    $manifest=as39Manifest(dirname($f->config)); $status=$f->setup->status($f->sha);
    as39Check('post-apply inspection is read-only and reports installed result',
        $status['config_ready'] && $status['bootstrap']['import_outcome']==='installed'
        && $manifest===as39Manifest(dirname($f->config)) && $f->identityCalls===1 && $f->directoryWrites===1);

    $other=['another_business'=>['project'=>['id'=>'another_business','brand'=>['name'=>"Other's shop"]],
        'max'=>['bot_key'=>'other_bot','token'=>'SYNTHETIC_OTHER_TOKEN','webhook_secret'=>str_repeat('b',64)]]];
    $source="<?php\n// Prefix preserved.\nif (!defined('MAX_SEARCH_PROJECT_CONNECTIONS')) {\n define('MAX_SEARCH_PROJECT_CONNECTIONS', ".var_export($other,true).");\n}\n// Suffix preserved.\n?>\n";
    $f=as39Fixture($source); $request=$f->prepare(); $beforeParsed=AppleService39ConnectionSetup::registry($source);
    $f->setup->apply($f->sha,17,$f->envelope($request)); $afterParsed=AppleService39ConnectionSetup::registry($f->source());
    as39Check('literal replacement preserves another connection and surrounding PHP guards/comments',
        $afterParsed['value']['another_business']===$other['another_business']
        && substr($source,0,$beforeParsed['start'])===substr($f->source(),0,$afterParsed['start'])
        && substr($source,$beforeParsed['end'])===substr($f->source(),$afterParsed['end']));

    $f=as39Fixture(); chmod($f->config,0640); $metadata=lstat($f->config); $request=$f->prepare();
    $f->setup->apply($f->sha,17,$f->envelope($request)); clearstatcache(true,$f->config); $after=lstat($f->config);
    as39Check('supported group reader permissions and owner/group survive atomic replacement',
        ($after['mode']&0777)===0640 && $after['uid']===$metadata['uid'] && $after['gid']===$metadata['gid']);
    $f=as39Fixture(); chmod($f->config,0644); $before=as39Manifest(dirname($f->config));
    as39Refuses('unsupported config permissions fail before private-key creation',fn()=>$f->prepare(),'unsupported_config_permissions');
    $status=$f->setup->status($f->sha);
    as39Check('read-only inspection explains unsupported write permissions without chmod',
        !$status['config_write_available'] && $before===as39Manifest(dirname($f->config)));
    $f=as39Fixture(); $request=$f->prepare(); chmod($f->config,0640);
    as39Refuses('permission changes invalidate the admitted config preimage',fn()=>$f->setup->apply($f->sha,17,$f->envelope($request)),'configuration_changed');

    $oldApple=['project'=>['id'=>'appleservice39','brand'=>['name'=>'AppleService39'],
        'business'=>['kind'=>'device_repair'],'routing'=>['source_key'=>'max:appleservice39-main']],
        'max'=>['bot_key'=>'id390521129062_bot','token'=>'SYNTHETIC_OLD_APPLE_TOKEN','webhook_secret'=>str_repeat('e',64)]];
    foreach (['legacy','another_connection'] as $collision) {
        $registry=['appleservice39'=>$oldApple];
        if ($collision==='another_connection') $registry['other']=['project'=>['id'=>'other'],
            'max'=>['bot_key'=>'other_bot','token'=>'SYNTHETIC_OTHER_TOKEN','webhook_secret'=>str_repeat('e',64)]];
        $source="<?php\n".($collision==='legacy'?"define('MAX_SEARCH_WEBHOOK_SECRET', '".str_repeat('e',64)."');\n":'')
            ."define('MAX_SEARCH_PROJECT_CONNECTIONS', ".var_export($registry,true).");\n";
        $f=as39Fixture($source); $status=$f->setup->status($f->sha);
        as39Check('actual resolver rejects '.$collision.' secret collision during inspection',!$status['config_ready']);
        $request=$f->prepare();
        as39Refuses('candidate resolver refuses preserved '.$collision.' secret collision',
            fn()=>$f->setup->apply($f->sha,17,$f->envelope($request)),'candidate_effective_mismatch');
        as39Check('resolver refusal is before database/config mutation for '.$collision,$f->directoryWrites===0&&$f->source()===$source);
    }

    foreach (['other_bot','human','unauthorized','throws'] as $mode) {
        $f=as39Fixture(); $request=$f->prepare(); $old=$f->source(); $f->identityMode=$mode;
        as39Refuses('identity refusal '.$mode.' precedes configuration/database mutation',
            fn()=>$f->setup->apply($f->sha,17,$f->envelope($request)),$mode==='throws'?'max_identity_unavailable':'max_identity_mismatch');
        as39Check('identity refusal '.$mode.' preserves existing settings and inactive subscriptions',
            $f->source()===$old && $f->identityCalls===1 && $f->directoryWrites===0 && file_exists($f->privatePath('bootstrap.pem')));
    }

    $f=as39Fixture(); $request=$f->prepare(); $envelope=$f->envelope($request); $old=$f->source();
    $bad=json_decode($envelope,true); $bad['tag']=base64_encode(str_repeat("\0",16));
    as39Refuses('tampered GCM tag is rejected before provider access',fn()=>$f->setup->apply($f->sha,17,json_encode($bad)),'envelope_authentication_failed');
    as39Refuses('different application/project AAD cannot authenticate',fn()=>$f->setup->apply($f->sha,17,$f->envelope($request,'unrelated AAD')),'envelope_authentication_failed');
    as39Refuses('OAEP SHA1 envelope is not silently accepted as SHA256',fn()=>$f->setup->apply($f->sha,17,$f->envelope($request,null,'sha1')),'envelope_authentication_failed');
    $bad=json_decode($envelope,true); $bad['request_id']=str_repeat('f',32);
    as39Refuses('envelope from another request is refused',fn()=>$f->setup->apply($f->sha,17,json_encode($bad)),'envelope_invalid');
    as39Refuses('plaintext input is never a valid token import',fn()=>$f->setup->apply($f->sha,17,$f->token),'envelope_invalid');
    as39Check('authentication failures do not touch provider, directory or configuration',$f->identityCalls===0 && $f->directoryWrites===0 && $f->source()===$old);
    as39Refuses('operator ID is part of the prepared request binding',fn()=>$f->setup->apply($f->sha,18,$envelope),'request_binding_mismatch');
    $f->directoryState='changed';
    as39Refuses('changed directory preimage refuses before provider work',fn()=>$f->setup->apply($f->sha,17,$envelope),'directory_changed');
    $f->directoryState='missing'; $f->put($old."// Concurrent config edit.\n"); $changed=$f->source();
    as39Refuses('changed private config preimage cannot be overwritten',fn()=>$f->setup->apply($f->sha,17,$envelope),'configuration_changed');
    as39Check('concurrent operator config is preserved',$f->source()===$changed && $f->identityCalls===0 && $f->directoryWrites===0);

    $f=as39Fixture(); $request=$f->prepare(); $envelope=$f->envelope($request); $oldSha=$f->sha; $f->sha=str_repeat('b',40);
    as39Refuses('actual installed application must match admitted SHA',fn()=>$f->setup->apply($oldSha,17,$envelope),'application_sha_mismatch');
    as39Check('source drift fails before secret decryption/provider work',$f->identityCalls===0 && $f->directoryWrites===0);

    $f=as39Fixture(); $request=$f->prepare(); $envelope=$f->envelope($request); $old=$f->source(); $f->now=$request['expires_at'];
    $before=as39Manifest(dirname($f->config)); $status=$f->setup->status($f->sha);
    as39Check('expired inspection is purely read-only',$status['bootstrap']['state']==='expired' && $before===as39Manifest(dirname($f->config)));
    as39Refuses('expired key cannot import a token',fn()=>$f->setup->apply($f->sha,17,$envelope),'request_consumed');
    as39Check('write attempt retires expired private material without config/provider writes',
        !file_exists($f->privatePath('bootstrap.pem')) && $f->identityCalls===0 && $f->directoryWrites===0 && $f->source()===$old);

    foreach (['changes_config'=>'configuration_changed','expires'=>'request_expired','changes_source'=>'application_sha_mismatch'] as $mode=>$reason) {
        $f=as39Fixture(); $request=$f->prepare(); $f->identityMode=$mode; $sha=$f->sha;
        as39Refuses('post-identity preflight rejects '.$mode,fn()=>$f->setup->apply($sha,17,$f->envelope($request)),$reason);
        as39Check('post-identity '.$mode.' never provisions directory',$f->identityCalls===1 && $f->directoryWrites===0);
    }

    $f=as39Fixture("<?php\nreturn;\n"); $request=$f->prepare(); $old=$f->source();
    as39Refuses('early-return config cannot falsely report an effective registry',fn()=>$f->setup->apply($f->sha,17,$f->envelope($request)),'candidate_effective_mismatch');
    as39Check('ineffective candidate leaves original file and directory unchanged',$f->source()===$old && $f->directoryWrites===0);
    $f=as39Fixture("<?php\ninvalid php statement\n"); $request=$f->prepare(); $old=$f->source();
    as39Refuses('invalid candidate syntax cannot replace config',fn()=>$f->setup->apply($f->sha,17,$f->envelope($request)),'candidate_lint_failed');
    as39Check('lint refusal is before directory mutation',$f->source()===$old && $f->directoryWrites===0);

    $f=as39Fixture(); $request=$f->prepare(); $envelope=$f->envelope($request); $old=$f->source(); $f->directoryFails=true;
    as39Refuses('directory provisioning failure stops the config installation',fn()=>$f->setup->apply($f->sha,17,$envelope),'source_provision_failed');
    as39Check('failure after write admission consumes key and preserves original config',
        !file_exists($f->privatePath('bootstrap.pem')) && $f->source()===$old && $f->directoryWrites===1);
    as39Refuses('uncertain apply cannot be retried automatically',fn()=>$f->setup->apply($f->sha,17,$envelope),'request_consumed');
    $before=as39Manifest(dirname($f->config)); $status=$f->setup->status($f->sha);
    as39Check('one status read resolves non-installation without importing again',
        $status['bootstrap']['import_outcome']==='not_installed_request_consumed' && $f->identityCalls===1
        && $f->directoryWrites===1 && $before===as39Manifest(dirname($f->config)));

    $f=as39Fixture(); $request=$f->prepare(); $f->setup->apply($f->sha,17,$f->envelope($request));
    $writes=0;
    $register=static function (callable $admit) use (&$writes): array {
        $admit('register','https://app.anytoour.ru/appleservice39_webhook.php'); $writes++;
        return ['ok'=>false,'state'=>'unknown','subscription_writes'=>1];
    };
    $result=$f->setup->activate($f->sha,17,$register);
    as39Check('explicit activation durably admits exactly one provider mutation',$writes===1&&$result['state']==='unknown');
    as39Refuses('unknown provider mutation cannot be repeated',fn()=>$f->setup->activate($f->sha,17,$register),'provider_mutation_already_claimed');
    $f->put($f->source()."// Unrelated private setting change.\n"); $f->sha=str_repeat('c',40);
    as39Refuses('new application/config binding cannot reset an uncertain provider claim',
        fn()=>$f->setup->activate($f->sha,17,$register),'provider_mutation_already_claimed');
    $before=as39Manifest(dirname($f->config));
    $unconfirmedConnection=as39Connection($f);
    $verified=$f->setup->verify($f->sha,static fn($unused,callable $confirmed):array=>
        ['ok'=>true,'state'=>'observed_url','registration_confirmed'=>$confirmed($unconfirmedConnection),'subscription_writes'=>0]);
    as39Check('provider readback is separate and leaves private state intact',
        $verified['state']==='observed_url'&&!$verified['registration_confirmed']&&$before===as39Manifest(dirname($f->config))&&$writes===1);
    as39Refuses('activation cannot admit an arbitrary callback URL',fn()=>$f->setup->activate($f->sha,17,
        static function (callable $admit): array { $admit('register','https://other.invalid/webhook'); return []; }),'unexpected_provider_mutation');
    as39Refuses('revoked administrator cannot authorize a later mutation',fn()=>$f->setup->activate($f->sha,17,
        function (callable $admit) use ($f): array { $f->activeAdmin=false; $admit('remove_legacy','https://tour-max.ru/new/appleservice39/webhook.php'); return []; }),'active_admin_required');
    $f->activeAdmin=true;
    as39Refuses('changed config between provider preflight and mutation invalidates admission',fn()=>$f->setup->activate($f->sha,17,
        function (callable $admit) use ($f): array { $f->put($f->source()."// Concurrent change.\n"); $admit('remove_legacy','https://tour-max.ru/new/appleservice39/webhook.php'); return []; }),'configuration_changed');
    as39Check('refused activation never repeats the uncertain registration',$writes===1);

    $f=as39Fixture(); $request=$f->prepare(); $f->setup->apply($f->sha,17,$f->envelope($request));
    $connection=as39Connection($f); $rotated=as39Connection($f,str_repeat('f',64));
    $inspectProof=static function ($unused,callable $confirmed) use ($connection,$rotated): array {
        return ['ok'=>true,'confirmed'=>$confirmed($connection),'rotated_confirmed'=>$confirmed($rotated)];
    };
    $before=as39Manifest(dirname($f->config)); $proof=$f->setup->verify($f->sha,$inspectProof);
    as39Check('a configured URL/local secret alone is never a registration receipt',
        !$proof['confirmed']&&!$proof['rotated_confirmed']&&$before===as39Manifest(dirname($f->config)));
    as39Refuses('a registration receipt must bind an admitted provider attempt',fn()=>$f->setup->activate($f->sha,17,
        static fn(callable $admit,callable $confirmed):array=>['ok'=>true,'receipt'=>['registration_confirmed'=>true]]),'registration_receipt_unbound');
    $registered=$f->setup->activate($f->sha,17,static function (callable $admit,callable $confirmed): array {
        $admit('register','https://app.anytoour.ru/appleservice39_webhook.php');
        return ['ok'=>false,'state'=>'cleanup_unknown','receipt'=>['registration_confirmed'=>true],'subscription_writes'=>1];
    });
    $before=as39Manifest(dirname($f->config)); $proof=$f->setup->verify($f->sha,$inspectProof);
    as39Check('confirmed registration survives uncertain legacy cleanup and is read-only',
        $registered['state']==='cleanup_unknown'&&$proof['confirmed']&&!$proof['rotated_confirmed']
        &&$before===as39Manifest(dirname($f->config)));
    $result=$f->setup->activate($f->sha,17,static function (callable $admit,callable $confirmed) use ($connection): array {
        return ['ok'=>true,'state'=>'already_active','receipt'=>['registration_confirmed'=>$confirmed($connection)],'subscription_writes'=>0];
    });
    as39Check('trusted existing registration needs no second provider write',
        $result['state']==='already_active'&&$result['subscription_writes']===0&&$before===as39Manifest(dirname($f->config)));

    foreach (["<?php\ndefine('MAX_SEARCH_PROJECT_CONNECTIONS', getenv('UNTRUSTED'));\n"=>'unsupported_config_literal',
        "<?php\ndefine('MAX_SEARCH_PROJECT_CONNECTIONS', []);\ndefine('MAX_SEARCH_PROJECT_CONNECTIONS', []);\n"=>'ambiguous_registry_definition'] as $source=>$reason) {
        $f=as39Fixture($source);
        as39Refuses('ambiguous/dynamic existing registry is never evaluated or rebuilt',fn()=>$f->prepare(),$reason);
        as39Check('unsupported registry cannot create a private key',$f->source()===$source && !file_exists($f->privatePath('bootstrap.pem')));
    }
    $f=as39Fixture(); $target=$f->dir.'/outside.php'; rename($f->config,$target); symlink($target,$f->config);
    as39Refuses('symlinked production config is refused',fn()=>$f->prepare(),'private_file_unavailable');
    $f=as39Fixture(); $lock=fopen($f->privatePath('setup.lock'),'x+b'); chmod($f->privatePath('setup.lock'),0600); flock($lock,LOCK_EX);
    as39Refuses('concurrent importer cannot acquire the server lock',fn()=>$f->prepare(),'setup_locked');
    flock($lock,LOCK_UN); fclose($lock);

    $f=as39Fixture(); file_put_contents($f->root.'/tracked.txt',"fixture\n");
    foreach ([['init','--quiet','--template='],['add','tracked.txt'],['-c','user.name=Synthetic fixture','-c','user.email=fixture@example.invalid','commit','--quiet','-m','Fixture']] as $args) {
        [$code]=as39Command(['git','-C',$f->root,...$args]); if ($code!==0) throw new RuntimeException('fixture_git_failed');
    }
    [$code,$sha]=as39Command(['git','-C',$f->root,'rev-parse','HEAD']); $f->sha=trim($sha); $f->setup=$f->instance(true);
    $request=$f->prepare(); file_put_contents($f->root.'/tracked.txt',"modified source\n");
    as39Refuses('native provenance rejects a dirty installed checkout',fn()=>$f->setup->status($f->sha),'application_sha_mismatch');

    $workflow=(string)file_get_contents(dirname(__DIR__).'/.github/workflows/setup-appleservice39-connection.yml');
    as39Check('manual setup uses original accepted-release resolver and installation concurrency',
        str_contains($workflow,'tools/deployment_release.js resolve-consumer') && str_contains($workflow,'group: production-deploy')
        && str_contains($workflow,'cancel-in-progress: false') && str_contains($workflow,'test "$EXPECTED_APPLICATION_SHA" = "$APPLICATION_SHA"')
        && str_contains($workflow,'name: AppleService39 setup regression'));
    as39Check('workflow passes only ciphertext on stdin and never reruns import after failure',
        substr_count($workflow,'printf \'%s\' "$ENCRYPTED_ENVELOPE" | ssh')===1
        && str_contains($workflow,'ssh "${ssh_options[@]}" "$remote" "$status_command" || true')
        && !str_contains($workflow,'bot_token:') && !str_contains($workflow,'token: ${{ inputs.'));
    echo "AppleService39 setup regression: ".$passed." passed\n";
} finally { foreach ($fixtures as $fixture) $fixture->destroy(); }
