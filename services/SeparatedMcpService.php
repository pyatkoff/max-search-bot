<?php
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
            $events=$reply?[ManagerPreparationMcpService::EVENT]:[...HotelWorkEventService::EVENTS,...HotelWorkEventService::WORKER_EVENTS];
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
