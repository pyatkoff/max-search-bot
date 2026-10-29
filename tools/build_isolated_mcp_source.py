#!/usr/bin/env python3
"""One-shot source assembly against pinned base. No network or runtime data."""
from pathlib import Path
import json
import re
import subprocess

BASE = '09a0633ad7a3378ff0d2cdf2b09a7381750ada47'
ROOT = Path(__file__).resolve().parents[1]
changed = []

def load(path):
    return (ROOT / path).read_text()

def save(path, text):
    p = ROOT / path
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(text)
    changed.append(path)

def once(text, before, after):
    if text.count(before) != 1:
        raise RuntimeError('source anchor not unique: ' + before[:100])
    return text.replace(before, after, 1)

def between(text, start, end, replacement):
    if text.count(start) != 1 or text.count(end) != 1:
        raise RuntimeError('source block not unique: ' + start)
    a, b = text.index(start), text.index(end)
    if b <= a:
        raise RuntimeError('invalid source block')
    return text[:a] + replacement + text[b:]

subprocess.run(['git', 'cat-file', '-e', BASE + '^{commit}'], check=True, cwd=ROOT)
s = load('services/ManagerPreparationOAuth.php')
s = once(s, "    public const PROFILE_DEVELOPMENT = 'development';", "    public const PROFILE_DEVELOPMENT = 'development';\n    public const PROFILE_REPLIES = 'replies';\n    public const PROFILE_HOTELS = 'hotels';")
profile = r'''    /** Fixed server-owned resource profiles; never selected from OAuth request data. */
    public static function profileDefinition(string $profile): array
    {
        $definitions = [
            self::PROFILE_LEGACY => ['path'=>'ai', 'prefix'=>'', 'scopes'=>self::SUPPORTED_SCOPES,
                'defaults'=>self::SCOPES, 'session'=>'manager_preparation_oauth_pending', 'label'=>'AnyTour'],
            self::PROFILE_DEVELOPMENT => ['path'=>'development', 'prefix'=>'dev_', 'scopes'=>[self::DEVELOPMENT_SCOPE],
                'defaults'=>[self::DEVELOPMENT_SCOPE], 'session'=>'manager_development_oauth_pending', 'label'=>'Разработка AnyTour'],
            self::PROFILE_REPLIES => ['path'=>'replies', 'prefix'=>'rep_', 'scopes'=>self::SCOPES,
                'defaults'=>self::SCOPES, 'session'=>'manager_replies_oauth_pending', 'label'=>'Ответы AnyTour'],
            self::PROFILE_HOTELS => ['path'=>'hotels', 'prefix'=>'hot_', 'scopes'=>[self::HOTEL_SCOPE],
                'defaults'=>[self::HOTEL_SCOPE], 'session'=>'manager_hotels_oauth_pending', 'label'=>'Отели AnyTour'],
        ];
        if (!isset($definitions[$profile])) throw new InvalidArgumentException('invalid_oauth_profile');
        $out=$definitions[$profile];
        $out['resource']=self::ORIGIN.'/manager/'.$out['path'].'-mcp.php';
        $out['issuer']=$out['endpoint']=self::ORIGIN.'/manager/'.$out['path'].'-oauth.php';
        return $out;
    }

    public static function authorizationMetadata(string $profile = self::PROFILE_LEGACY): array
    {
        $p=self::profileDefinition($profile);
        return ['issuer'=>$p['issuer'],'authorization_response_iss_parameter_supported'=>true,
            'authorization_endpoint'=>$p['endpoint'].'?action=authorize','token_endpoint'=>$p['endpoint'].'?action=token',
            'registration_endpoint'=>$p['endpoint'].'?action=register','revocation_endpoint'=>$p['endpoint'].'?action=revoke',
            'token_endpoint_auth_methods_supported'=>['none'],'revocation_endpoint_auth_methods_supported'=>['none'],
            'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],
            'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>$p['scopes']];
    }

    public static function resourceMetadata(string $profile = self::PROFILE_LEGACY): array
    {
        $p=self::profileDefinition($profile);
        return ['resource'=>$p['resource'],'authorization_servers'=>[$p['issuer']],
            'scopes_supported'=>$p['scopes'],'bearer_methods_supported'=>['header']];
    }

'''
s = between(s, '    public static function authorizationMetadata(', '    /** One fresh canonical grant lookup;', profile)
s = once(s, "($this->profile===self::PROFILE_DEVELOPMENT?self::DEVELOPMENT_SCOPE:implode(' ',self::SCOPES))", "implode(' ',self::profileDefinition($this->profile)['defaults'])")
s = once(s, "$id=$this->profile===self::PROFILE_DEVELOPMENT?'dev_'.bin2hex(random_bytes(22)):bin2hex(random_bytes(24));", "$prefix=self::profileDefinition($this->profile)['prefix'];\n        $id=$prefix.bin2hex(random_bytes(intdiv(48-strlen($prefix),2)));")
s = once(s, "if (!in_array($profile,[self::PROFILE_LEGACY,self::PROFILE_DEVELOPMENT],true)) throw new InvalidArgumentException('invalid_oauth_profile');", "self::profileDefinition($profile);")
helpers = r'''    private function resource(): string { return self::profileDefinition($this->profile)['resource']; }
    private function issuer(): string { return self::profileDefinition($this->profile)['issuer']; }
    private function pendingSessionKey(): string { return self::profileDefinition($this->profile)['session']; }
    private function clientNamespace(string $client): bool
    {
        $prefix=self::profileDefinition($this->profile)['prefix'];
        return preg_match('/\A'.preg_quote($prefix,'/').'[a-f0-9]{'.(48-strlen($prefix)).'}\z/D',$client)===1;
    }
    /** Fixed column allowlist and hex prefix comparison: no wildcard client matching. */
    private function clientProfileSql(string $column): string
    {
        if (!in_array($column,['client_id','g.client_id'],true)) throw new LogicException('invalid_oauth_client_column');
        $prefix=self::profileDefinition($this->profile)['prefix'];
        $remaining=$prefix!==''?'SUBSTR('.$column.','.(strlen($prefix)+1).')':$column;
        foreach (str_split('0123456789abcdef') as $hex) $remaining="REPLACE(".$remaining.",'".$hex."','')";
        return 'LENGTH('.$column.')=48 AND '.($prefix!==''?"HEX(SUBSTR(".$column.",1,".strlen($prefix)."))='".strtoupper(bin2hex($prefix))."' AND ":'').$remaining."=''";
    }
'''
s = between(s, '    private function resource(): string', '    private function grantMatchesProfile(', helpers)
s = once(s, "        if ($this->profile===self::PROFILE_DEVELOPMENT) {\n            if ($value!==self::DEVELOPMENT_SCOPE) $this->fail('invalid_scope');\n            return self::DEVELOPMENT_SCOPE;\n        }", "        if ($this->profile!==self::PROFILE_LEGACY) {\n            $required=self::profileDefinition($this->profile)['scopes'];\n            $given=explode(' ',$value);\n            if (count($given)!==count($required)||array_diff($given,$required)||array_diff($required,$given)) $this->fail('invalid_scope');\n            return implode(' ',$required);\n        }")
known = r'''    private function knownHotelConnectionGrant(string $familyId,int $adminId): ?array
    {
        if (!in_array($this->profile,[self::PROFILE_LEGACY,self::PROFILE_HOTELS],true)) return null;
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$familyId)||$adminId<=0) return null;
        $q=$this->pdo->prepare('SELECT g.scopes,g.expires_at,g.revoked_at,g.resource,g.client_id,g.project_key FROM manager_preparation_oauth_grants g
            JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id
            WHERE g.grant_id=? AND g.admin_id=? AND g.project_key=? AND c.redirect_uri=? LIMIT 1');
        $q->execute([$familyId,$adminId,ProjectConfig::projectId(),self::REDIRECT_URI]);
        $grant=$q->fetch(PDO::FETCH_ASSOC);
        if (!$grant) return null;
        $profile=match ($grant['resource']) {
            self::RESOURCE=>self::PROFILE_LEGACY,
            self::ORIGIN.'/manager/hotels-mcp.php'=>self::PROFILE_HOTELS,
            default=>null,
        };
        if ($profile===null) return null;
        $canonical=new self($this->pdo,$this->clock,$profile);
        if (!$canonical->grantMatchesProfile($grant)||!in_array(self::HOTEL_SCOPE,explode(' ',$grant['scopes']),true)) return null;
        return $grant;
    }

    /** Cron only: accept old or isolated resource for ONE domain, never for HTTP token authentication. */
    public function eventConnectionAuthorizedAcrossProfiles(string $domain,string $familyId,int $adminId): bool
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('event_authorization_transaction');
        [$profiles,$required]=match ($domain) {
            'replies'=>[[self::PROFILE_LEGACY,self::PROFILE_REPLIES],self::SCOPES],
            'hotels'=>[[self::PROFILE_LEGACY,self::PROFILE_HOTELS],[self::HOTEL_SCOPE]],
            default=>throw new InvalidArgumentException('invalid_event_domain'),
        };
        foreach ($profiles as $profile) {
            if ((new self($this->pdo,$this->clock,$profile))->connectionAuthorized($familyId,$adminId,$required)) return true;
        }
        return false;
    }

'''
s = between(s, '    private function knownHotelConnectionGrant(', '    public static function authenticateBearer(', known)
save('services/ManagerPreparationOAuth.php', s)

s=load('services/HotelWorkMcpAdapter.php')
s=once(s, 'private ?HotelWorkEventService $events = null) {}', "private ?HotelWorkEventService $events = null, private string $oauthProfile = ManagerPreparationOAuth::PROFILE_LEGACY)\n    {\n        if (!in_array($oauthProfile,[ManagerPreparationOAuth::PROFILE_LEGACY,ManagerPreparationOAuth::PROFILE_HOTELS],true)) throw new InvalidArgumentException('invalid_hotel_oauth_profile');\n    }")
s=once(s, 'new ManagerPreparationOAuth($this->pdo,$this->clock);', 'new ManagerPreparationOAuth($this->pdo,$this->clock,$this->oauthProfile);')
save('services/HotelWorkMcpAdapter.php',s)
s=load('tools/manager_prepared_replies.php')
s=once(s, '$service=new ManagerPreparationMcpService();', "$service=new ManagerPreparationMcpService($pdo,connectionActive:\n        static fn(string $connection,int $admin):bool=>(new ManagerPreparationOAuth($pdo))->eventConnectionAuthorizedAcrossProfiles('replies',$connection,$admin));")
save('tools/manager_prepared_replies.php',s)
s=load('tools/hotel_work.php')
s=once(s, "$events=new HotelWorkEventService();$stage='subscription';", "$pdo=ConversationDb::connection();\n    $events=new HotelWorkEventService($pdo,connectionAuthorized:\n        static fn(string $connection,int $admin,array $required):bool=>$required===[ManagerPreparationOAuth::HOTEL_SCOPE]\n            &&(new ManagerPreparationOAuth($pdo))->eventConnectionAuthorizedAcrossProfiles('hotels',$connection,$admin));\n    $stage='subscription';")
save('tools/hotel_work.php',s)

save('services/SeparatedMcpService.php',r'''<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPreparationMcpService.php';
require_once __DIR__.'/HotelWorkMcpAdapter.php';
require_once __DIR__.'/HotelWorkEventService.php';

/** Separate audiences AND dispatch allowlists. Legacy endpoint remains unchanged. */
final class SeparatedMcpService
{
    public const REPLY_TOOLS=['list_pending_replies','get_reply_context','save_prepared_reply'];
    private const VERSIONS=['2025-11-25','2025-06-18','2025-03-26','2024-11-05'];
    private PDO $pdo;
    private ManagerPreparationOAuth $oauth;
    private ManagerPreparationMcpService $replies;
    private HotelWorkEventService $hotels;
    private array $required;

    public function __construct(private string $profile,?PDO $pdo=null,private $store=null,private $clock=null)
    {
        if (!in_array($profile,[ManagerPreparationOAuth::PROFILE_REPLIES,ManagerPreparationOAuth::PROFILE_HOTELS],true)) throw new InvalidArgumentException('invalid_separated_profile');
        $this->pdo=$pdo??ConversationDb::connection();
        $this->oauth=new ManagerPreparationOAuth($this->pdo,$clock,$profile);
        $this->required=ManagerPreparationOAuth::profileDefinition($profile)['scopes'];
        $this->replies=new ManagerPreparationMcpService($this->pdo,$store,clock:$clock,
            connectionActive:fn(string $connection,int $admin):bool=>$this->oauth->connectionAuthorized($connection,$admin,ManagerPreparationOAuth::SCOPES));
        $this->hotels=new HotelWorkEventService($this->pdo,clock:$clock,
            connectionAuthorized:fn(string $connection,int $admin,array $required):bool=>$this->oauth->connectionAuthorized($connection,$admin,$required));
    }

    public function handle(array $rpc,int $admin,?string $connection=null,?array $scopes=null): array
    {
        $id=$rpc['id']??null;
        if (($rpc['jsonrpc']??null)!=='2.0'||!is_string($rpc['method']??null)||(!is_int($id)&&!is_string($id)&&$id!==null)) return self::error($id,-32600,'Invalid Request');
        try {
            $this->authorize($admin,$connection,$scopes);
            $params=$rpc['params']??[];
            if (!is_array($params)||($params!==[]&&array_is_list($params))) throw new InvalidArgumentException();
            if (array_key_exists('_meta',$params)) {
                if (!is_array($params['_meta'])||($params['_meta']!==[]&&array_is_list($params['_meta']))) throw new InvalidArgumentException();
                unset($params['_meta']);
            }
            $reply=$this->profile===ManagerPreparationOAuth::PROFILE_REPLIES;
            $tools=$reply?self::REPLY_TOOLS:HotelWorkMcpAdapter::NAMES;
            $events=$reply?[ManagerPreparationMcpService::EVENT]:HotelWorkEventService::EVENTS;
            $method=$rpc['method'];$result=null;
            if ($method==='server/discover') {
                $result=['resultType'=>'complete','supportedVersions'=>['2026-07-28'],
                    'capabilities'=>['tools'=>(object)[],'events'=>(object)[]],
                    'serverInfo'=>['name'=>'anytour-'.$this->profile,'version'=>'1.0.0']];
            } elseif ($method==='initialize') {
                $version=$params['protocolVersion']??null;
                if (!is_string($version)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$version)!==1) throw new InvalidArgumentException();
                $result=['protocolVersion'=>in_array($version,self::VERSIONS,true)?$version:self::VERSIONS[0],
                    'capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'anytour-'.$this->profile,'version'=>'1.0.0']];
            } elseif ($method==='ping') $result=(object)[];
            elseif ($method==='notifications/initialized') $result=null;
            elseif ($method==='tools/list') {
                if ($reply) {
                    $inner=$this->replies->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list','params'=>[]],$admin,$connection,$this->required);
                    if (isset($inner['error'])) throw new RuntimeException();
                    $definitions=$inner['result']['tools']??[];
                } else $definitions=HotelWorkMcpAdapter::tools();
                $byName=array_column($definitions,null,'name');$out=[];
                foreach ($tools as $name) {if (!isset($byName[$name])) throw new RuntimeException();$out[]=$byName[$name];}
                $result=['tools'=>$out];
            } elseif ($method==='tools/call') {
                if (array_diff(array_keys($params),['name','arguments'])) throw new InvalidArgumentException();
                if (!in_array($params['name']??null,$tools,true)) throw new OutOfBoundsException();
                if ($reply) {
                    $response=$this->replies->handle(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params],$admin,$connection,$this->required);
                    $this->authorize($admin,$connection,$scopes);
                    return array_key_exists('id',$rpc)?$response:[];
                }
                $args=$params['arguments']??[];
                if (!is_array($args)||($args!==[]&&array_is_list($args))) throw new InvalidArgumentException();
                try {$data=(new HotelWorkMcpAdapter($this->pdo,$this->store,$this->clock,$this->hotels,$this->profile))->call($params['name'],$args,$admin,$connection);}
                catch (DomainException $error) {
                    if ($params['name']!=='hotel_work_status'||$error->getMessage()!=='hotel_work_access_denied') throw $error;
                    $this->authorize($admin,$connection,$scopes);
                    $self=$this->oauth->currentConnectionMetadata($connection,$admin,$this->required);
                    if ($self===null) throw new DomainException();
                    $data=['ok'=>false,'reason'=>'OWNED_QUEUE_UNAVAILABLE','queue_available'=>false,'caller_self'=>$self];
                }
                $result=['content'=>[['type'=>'text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]],
                    'structuredContent'=>$data,'isError'=>isset($data['ok'])&&$data['ok']!==true];
            } elseif (in_array($method,['events/list','events/subscribe','events/unsubscribe'],true)) {
                if ($method!=='events/list'&&!in_array($params['name']??null,$events,true)) throw new OutOfBoundsException();
                if ($reply) {
                    $response=$this->replies->handle(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params],$admin,$connection,$this->required);
                    $this->authorize($admin,$connection,$scopes);
                    return array_key_exists('id',$rpc)?$response:[];
                }
                $result=match ($method) {
                    'events/list'=>['events'=>$this->hotels->eventDefinitions()],
                    'events/subscribe'=>$this->hotels->subscribe($params,$admin,$connection),
                    'events/unsubscribe'=>$this->hotels->unsubscribe($params,$admin,$connection),
                };
            } else throw new OutOfBoundsException();
            $this->authorize($admin,$connection,$scopes);
            return array_key_exists('id',$rpc)?['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]:[];
        } catch (ManagerPreparationCallbackError|HotelWorkCallbackError $error) {
            return self::error($id,-32015,'CallbackEndpointError',['reason'=>$error->reason]);
        } catch (DomainException $error) {return self::error($id,-32001,'Access denied');}
        catch (InvalidArgumentException $error) {return self::error($id,-32602,'Invalid params');}
        catch (OutOfBoundsException $error) {return self::error($id,-32601,'Method not found');}
        catch (Throwable $error) {return self::error($id,-32603,'Internal error');}
    }
    private function authorize(int $admin,?string $connection,?array $scopes): void
    {
        if ($this->pdo->inTransaction()||$connection===null||$admin<1
            ||($scopes!==null&&$scopes!==$this->required)
            ||!$this->oauth->connectionAuthorized($connection,$admin,$this->required)) throw new DomainException();
    }
    private static function error(mixed $id,int $code,string $message,?array $data=null): array
    { $error=['code'=>$code,'message'=>$message];if ($data!==null)$error['data']=$data;return ['jsonrpc'=>'2.0','id'=>$id,'error'=>$error]; }
}
''')

save('manager/lib/SeparatedMcpHttp.php',r'''<?php
declare(strict_types=1);
// Included only by the two fixed endpoint entrypoints. No request-selectable profile.
if (!isset($surfaceProfile)||!in_array($surfaceProfile,['replies','hotels'],true)) {http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__,2).'/services/SeparatedMcpService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function isolatedMcpResponse(array $body,int $status=200): never
{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    if ($origin!==''&&!in_array($origin,['https://chatgpt.com','https://chat.openai.com'],true)) isolatedMcpResponse(['error'=>['code'=>-32001,'message'=>'Forbidden origin']],403);
    require_once dirname(__DIR__,2).'/config.php';
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
    if ($header==='') $header=(string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if ($header===''&&function_exists('getallheaders')) foreach (getallheaders() as $name=>$value) {
        if (strcasecmp((string)$name,'Authorization')===0) {$header=(string)$value;break;}
    }
    $context=(new ManagerPreparationOAuth(null,null,$surfaceProfile))->contextForBearer($header);
    if ($context===null) {
        $definition=ManagerPreparationOAuth::profileDefinition($surfaceProfile);
        header('WWW-Authenticate: Bearer resource_metadata="'.$definition['endpoint'].'?action=resource", scope="'.implode(' ',$definition['scopes']).'"');
        isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32001,'message'=>'OAuth connection required']],401);
    }
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');isolatedMcpResponse(['error'=>['code'=>-32600,'message'=>'POST required']],405);}
    if (isset($_SERVER['CONTENT_LENGTH'])&&(int)$_SERVER['CONTENT_LENGTH']>196608) isolatedMcpResponse(['error'=>['code'=>-32600,'message'=>'Request too large']],413);
    $raw=file_get_contents('php://input',false,null,0,196609);
    if (!is_string($raw)||strlen($raw)>196608) isolatedMcpResponse(['error'=>['code'=>-32600,'message'=>'Request too large']],413);
    try {$rpc=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch(Throwable $error) {isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error']],400);}
    if (!is_array($rpc)||array_is_list($rpc)||($rpc['jsonrpc']??null)!=='2.0') isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid request']],400);
    if (!array_key_exists('id',$rpc)) {
        if (($rpc['method']??null)==='notifications/initialized') {http_response_code(202);exit;}
        isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Request ID required']],400);
    }
    if ((!is_int($rpc['id'])&&!is_string($rpc['id']))||(is_string($rpc['id'])&&strlen($rpc['id'])>128)) isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid request ID']],400);
    isolatedMcpResponse((new SeparatedMcpService($surfaceProfile))->handle($rpc,$context['admin_id'],$context['connection_id'],$context['scopes']));
} catch(Throwable $error) {isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32603,'message'=>'Separated service unavailable']],503);}
''')

# Share one controller between the two NEW issuers; preserve the old controller byte-for-byte.
s=load('manager/ai-oauth.php')
s=once(s,"declare(strict_types=1);", "declare(strict_types=1);\nif (!isset($surfaceProfile)||!in_array($surfaceProfile,['replies','hotels'],true)) {http_response_code(404);exit;}\n$oauthRoute='/manager/'.$surfaceProfile.'-oauth.php';")
s=s.replace("dirname(__DIR__)","dirname(__DIR__,2)")
s=once(s,"__DIR__.'/lib/ManagerHttp.php'","__DIR__.'/ManagerHttp.php'")
s=s.replace('ManagerPreparationOAuth::resourceMetadata()', 'ManagerPreparationOAuth::resourceMetadata($surfaceProfile)')
s=s.replace('ManagerPreparationOAuth::authorizationMetadata()', 'ManagerPreparationOAuth::authorizationMetadata($surfaceProfile)')
s=once(s,'$oauth=new ManagerPreparationOAuth();','$oauth=new ManagerPreparationOAuth(null,null,$surfaceProfile);')
s=s.replace('/manager/ai-oauth.php',"'.$oauthRoute.'")
s=once(s,"preparationOAuthHtml('Подключения ChatGPT',$html)","preparationOAuthHtml('Подключения ChatGPT — '.ManagerPreparationOAuth::profileDefinition($surfaceProfile)['label'],$html)")
save('manager/lib/SeparatedMcpOAuthHttp.php',s)
for profile in ['replies','hotels']:
    for kind, controller in [('mcp','SeparatedMcpHttp'),('oauth','SeparatedMcpOAuthHttp')]:
        save(f'manager/{profile}-{kind}.php',f"<?php\ndeclare(strict_types=1);\n$surfaceProfile='{profile}';\nrequire __DIR__.'/lib/{controller}.php';\n")
    for folder, method, leaf in [('oauth-authorization-server','authorizationMetadata','oauth'),('oauth-protected-resource','resourceMetadata','mcp')]:
        save(f'.well-known/{folder}/manager/{profile}-{leaf}.php',f"<?php\ndeclare(strict_types=1);\nrequire_once dirname(__DIR__,3).'/services/ManagerPreparationOAuth.php';\nheader('Content-Type: application/json; charset=utf-8');\nheader('Cache-Control: no-store');\necho json_encode(ManagerPreparationOAuth::{method}('{profile}'),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);\n")

save('tests/run_separated_mcp_regression.php',r'''<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/SeparatedMcpService.php';
$checks=0;
function checkSplit(string $name,bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);++$checks;echo 'PASS '.$name.PHP_EOL;}
function rejectSplit(string $name,callable $fn): void {try{$fn();}catch(InvalidArgumentException $e){checkSplit($name,true);return;}throw new RuntimeException('FAIL '.$name);}
function rpcSplit(string $method,array $params=[]): array {return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params];}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);
INSERT INTO managers VALUES(1,'admin',1,0),(2,'manager',1,1);
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1);
INSERT INTO manager_projects VALUES(1,1),(2,1);");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
(new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
$now=time();$clock=static fn():int=>$now;$oauth=[];$tokens=[];$ctx=[];$clients=[];
foreach (['legacy','development','replies','hotels'] as $profile) {
    $oauth[$profile]=new ManagerPreparationOAuth($pdo,$clock,$profile);
    $def=ManagerPreparationOAuth::profileDefinition($profile);
    $registration=$oauth[$profile]->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'test-'.$profile);
    $clients[$profile]=$registration['client_id'];
    checkSplit('namespace '.$profile,strlen($clients[$profile])===48&&str_starts_with($clients[$profile],$def['prefix']));
    checkSplit('default scopes '.$profile,$registration['scope']===implode(' ',$def['defaults']));
    $verifier=str_repeat('v',43);$session=['csrf'=>'synthetic-csrf'];
    $request=['client_id'=>$clients[$profile],'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>$def['resource'],'scope'=>$registration['scope'],'state'=>'synthetic-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth[$profile]->beginAuthorization($session,$request);
    $url=$oauth[$profile]->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$callback);
    checkSplit('issuer '.$profile,$callback['iss']===$def['issuer']);
    $tokens[$profile]=$oauth[$profile]->exchange(['grant_type'=>'authorization_code','client_id'=>$clients[$profile],
        'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'resource'=>$def['resource'],'code'=>$callback['code'],'code_verifier'=>$verifier]);
    $ctx[$profile]=$oauth[$profile]->contextForBearer('Bearer '.$tokens[$profile]['access_token']);
    checkSplit('consent '.$profile,is_array($ctx[$profile])&&$ctx[$profile]['scopes']===$def['defaults']);
}
foreach ($oauth as $profile=>$auth) foreach ($tokens as $other=>$token) {
    checkSplit('audience '.$profile.' vs '.$other,($auth->contextForBearer('Bearer '.$token['access_token'])!==null)===($profile===$other));
}
foreach (['replies','hotels'] as $profile) {
    $def=ManagerPreparationOAuth::profileDefinition($profile);
    checkSplit('metadata '.$profile,ManagerPreparationOAuth::resourceMetadata($profile)['scopes_supported']===$def['scopes']);
    rejectSplit('no broadened registration '.$profile,fn()=>$oauth[$profile]->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>'queue:read prepared:write hotel_work:write'],'bad'));
    rejectSplit('no refresh escalation '.$profile,fn()=>$oauth[$profile]->exchange(['grant_type'=>'refresh_token','client_id'=>$clients[$profile],
        'resource'=>$def['resource'],'refresh_token'=>$tokens[$profile]['refresh_token'],'scope'=>'queue:read prepared:write hotel_work:write']));
}
$calls=[];
$store=static function(string $operation,array $args)use(&$calls):array {$calls[]=$operation;return ['ok'=>true,'synthetic'=>true];};
$services=['replies'=>new SeparatedMcpService('replies',$pdo,$store,$clock),'hotels'=>new SeparatedMcpService('hotels',$pdo,$store,$clock)];
foreach ($services as $profile=>$service) {
    $context=$ctx[$profile];
    $invoke=fn(array $rpc):array=>$service->handle($rpc,1,$context['connection_id'],$context['scopes']);
    $tools=$invoke(rpcSplit('tools/list'));
    $expected=$profile==='replies'?SeparatedMcpService::REPLY_TOOLS:HotelWorkMcpAdapter::NAMES;
    checkSplit('exact tools '.$profile,array_column($tools['result']['tools']??[],'name')===$expected);
    $events=$invoke(rpcSplit('events/list'));
    checkSplit('exact events '.$profile,array_column($events['result']['events']??[],'name')===($profile==='replies'?['manager_replies.pending']:HotelWorkEventService::EVENTS));
    foreach (['read_archive_history','read_tour_candidates','claim_virtual_replies','development_work_create',
        $profile==='replies'?'hotel_work_create':'get_reply_context'] as $forbidden) {
        $before=count($calls);$denied=$invoke(rpcSplit('tools/call',['name'=>$forbidden,'arguments'=>[]]));
        checkSplit('dispatch denied '.$profile.'/'.$forbidden,($denied['error']['code']??0)===-32601&&count($calls)===$before);
    }
    $wrongEvent=$profile==='replies'?'hotel_work.pending':'manager_replies.pending';
    checkSplit('subscription domain '.$profile,($invoke(rpcSplit('events/subscribe',['name'=>$wrongEvent]))['error']['code']??0)===-32601);
    foreach (['legacy','development',$profile==='replies'?'hotels':'replies'] as $foreign) {
        checkSplit('service refuses foreign audience '.$profile.'/'.$foreign,($service->handle(rpcSplit('tools/list'),1,$ctx[$foreign]['connection_id'],$context['scopes'])['error']['code']??0)===-32001);
    }
    checkSplit('service refuses different admin '.$profile,($service->handle(rpcSplit('tools/list'),2,$context['connection_id'],$context['scopes'])['error']['code']??0)===-32001);
}
$r=$ctx['replies'];$h=$ctx['hotels'];
$result=$services['replies']->handle(rpcSplit('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>'SYNTHETIC']]),1,$r['connection_id'],$r['scopes']);
checkSplit('allowed reply command reaches existing store',!isset($result['error'])&&end($calls)==='context');
$result=$services['hotels']->handle(rpcSplit('tools/call',['name'=>'hotel_work_create','arguments'=>['queue_id'=>'SYNTHETIC','baseline_sha256'=>str_repeat('a',64)]]),1,$h['connection_id'],$h['scopes']);
checkSplit('allowed hotel command reaches existing store',!isset($result['error'])&&end($calls)==='create');
$background=new ManagerPreparationOAuth($pdo,$clock);
checkSplit('reply cron accepts separate resource',$background->eventConnectionAuthorizedAcrossProfiles('replies',$r['connection_id'],1));
checkSplit('hotel cron accepts separate resource',$background->eventConnectionAuthorizedAcrossProfiles('hotels',$h['connection_id'],1));
checkSplit('cron denies crossed domain',!$background->eventConnectionAuthorizedAcrossProfiles('replies',$h['connection_id'],1)&&!$background->eventConnectionAuthorizedAcrossProfiles('hotels',$r['connection_id'],1));
checkSplit('active hotel grant not retired',!$oauth['hotels']->connectionRetired($h['connection_id'],1));
$oauth['replies']->disconnect(['csrf'=>'csrf'],1,$r['connection_id'],'csrf');
checkSplit('reply revoke does not revoke hotels',$oauth['hotels']->contextForBearer('Bearer '.$tokens['hotels']['access_token'])!==null&&$oauth['legacy']->contextForBearer('Bearer '.$tokens['legacy']['access_token'])!==null);
checkSplit('revoked reply cannot call service',($services['replies']->handle(rpcSplit('tools/list'),1,$r['connection_id'],$r['scopes'])['error']['code']??0)===-32001);
checkSplit('reply issuer cannot revoke hotel grant',($oauth['replies']->revoke(['token'=>$tokens['hotels']['access_token'],'client_id'=>$clients['hotels']])===null)&&$oauth['hotels']->contextForBearer('Bearer '.$tokens['hotels']['access_token'])!==null);
echo 'SEPARATED MCP: '.$checks.' PASS'.PHP_EOL;
''')
s=load('tests/required_checks_manifest.php')
s=once(s,"        'php tests/run_hotel_work_oauth_regression.php',", "        'php tests/run_hotel_work_oauth_regression.php',\n        'php tests/run_separated_mcp_regression.php',")
save('tests/required_checks_manifest.php',s)
save('docs/SEPARATED_REPLIES_HOTELS_MCP.md', '''# Isolated replies and hotels connections

Two additive resources reuse the existing database, stores and event workers:

| Profile | MCP | OAuth issuer | Scopes | Commands / events |
|---|---|---|---|---|
| Replies | `/manager/replies-mcp.php` | `/manager/replies-oauth.php` | `queue:read prepared:write` | Exactly list_pending_replies, get_reply_context, save_prepared_reply; manager_replies.pending |
| Hotels | `/manager/hotels-mcp.php` | `/manager/hotels-oauth.php` | `hotel_work:write` | Existing hotel_work tools; hotel_work.pending and hotel_work.attention |

Each profile has its own issuer, resource, client namespace, consent session,
refresh-token family and filtered connection-management page. Tokens are not
accepted by another profile, including the legacy or development endpoints.
All calls and subscriptions are restricted before dispatch, not just hidden
from tools/list. Neither new profile can step up into the other domains.

Legacy ai-mcp.php and development-mcp.php remain compatible. Existing grants,
subscriptions, reply tasks, queues and archives are not edited by deployment.
Existing cron lines are retained; their background-only authorization can read
the canonical old or new resource for its own event domain. This does not add
a second scheduled worker. Production callbacks and delivery must still be
verified with actual supported HOST subscriptions after explicit consent.

## Cutover, not automatic migration

Deploy after review and CI. First consent to Replies, verify its exact command
and event lists, then deliberately transfer the existing reply task and its
subscription. Do not run two reply writers. Verify an actual saved draft and
manager readback before retiring the old connection. Connect Hotels separately.
Do not revoke a combined legacy grant until its other consumers are reconciled.

EG26 remains the original held operation: a new hotel grant is not ownership.
The unchanged handoff contract still requires original stage/baseline/run and
retained claim evidence, canonical old-grant retirement for existing_run,
independent packet acceptance and once-only receipt. The new profiles neither
create another queue nor change the old claim/run. No study or import is replayed.

Local tests use synthetic SQLite grants only. A green test or installed endpoint
is not evidence of user consent, HOST event delivery or HOTEL data acceptance.
''')
(ROOT / 'split_changed_paths.json').write_text(json.dumps(changed))
print(json.dumps({'source_files': changed, 'runtime_operations': 0}))
