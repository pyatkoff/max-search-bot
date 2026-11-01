<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectAccessService.php';
require_once __DIR__.'/ManagerPreparationOAuth.php';
require_once __DIR__.'/DevelopmentWorkMcpAdapter.php';
require_once __DIR__.'/DevelopmentWorkEventService.php';

/** Resource-bound development MCP surface. Never dispatches a worker or publishes source. */
final class DevelopmentWorkMcpService
{
    private const TOOLS=['development_work_create','development_work_append','development_work_status','development_work_claim',
        'development_work_started','development_work_complete','development_work_resolve_existing_run','development_work_retire_unclaimed','development_work_handoff_preflight','development_work_handoff_connection'];
    private const EVENTS=['development_work.pending','development_work.attention'];
    private const VERSIONS=['2025-11-25','2025-06-18','2025-03-26','2024-11-05'];
    private PDO $pdo;
    private DevelopmentWorkEventService $events;

    public function __construct(?PDO $pdo=null,private $store=null,private $clock=null,?DevelopmentWorkEventService $events=null)
    {
        $this->pdo=$pdo??ConversationDb::connection();
        $authorize=fn(string $connection,int $admin,array $required):bool=>$this->oauth()->connectionAuthorized($connection,$admin,$required);
        $this->events=$events??new DevelopmentWorkEventService($this->pdo,null,$clock,$authorize);
    }

    public function handle(array $rpc,int $admin,?string $connection=null,?array $scopes=null): array
    {
        $id=$rpc['id']??null;
        $status=($rpc['method']??null)==='tools/call'&&is_array($rpc['params']??null)
            &&($rpc['params']['name']??null)==='development_work_status';
        if (($rpc['jsonrpc']??null)!=='2.0'||!is_string($rpc['method']??null)
            ||(!is_int($id)&&!is_string($id)&&$id!==null)) return self::error(null,-32600,'Invalid Request');
        try {
            $this->requireContext($admin,$connection,$scopes);
            $params=$rpc['params']??[];
            if (!is_array($params)||($params!==[]&&array_is_list($params))) throw new InvalidArgumentException();
            if (array_key_exists('_meta',$params)) {
                if (!is_array($params['_meta'])||($params['_meta']!==[]&&array_is_list($params['_meta']))) throw new InvalidArgumentException();
                unset($params['_meta']);
            }
            $result=match ($rpc['method']) {
                'server/discover'=>['resultType'=>'complete','supportedVersions'=>['2026-07-28'],
                    'capabilities'=>['tools'=>(object)[],'events'=>(object)[]],
                    'serverInfo'=>['name'=>'anytour-development-work','version'=>'1.0.0']],
                'initialize'=>$this->initialize($params),'ping'=>(object)[],'notifications/initialized'=>null,
                'tools/list'=>['tools'=>$this->tools()],
                'tools/call'=>$this->callTool($params,$admin,$connection),
                'events/list'=>['events'=>$this->eventDefinitions()],
                'events/subscribe'=>$this->subscription($params,$admin,$connection,false),
                'events/unsubscribe'=>$this->subscription($params,$admin,$connection,true),
                default=>throw new OutOfBoundsException(),
            };
            $this->requireContext($admin,$connection,$scopes);
            return array_key_exists('id',$rpc)?['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]:[];
        } catch (DevelopmentWorkCallbackError $error) {
            return self::error($id,-32015,'CallbackEndpointError',['reason'=>$error->reason]);
        } catch (DomainException $error) {
            $reason=$error instanceof DevelopmentWorkMcpAccessError?$error->reason
                :($error instanceof DevelopmentSubscriptionStatusAuthorizationError?'AUTH_CONTEXT_UNAVAILABLE':null);
            if ($status&&in_array($reason,['AUTH_CONTEXT_UNAVAILABLE','AUTH_SCOPE_UNAVAILABLE'],true))
                return self::error($id,-32001,'Access denied ['.$reason.']',['reason'=>$reason]);
            return self::error($id,-32001,'Access denied');
        } catch (InvalidArgumentException $error) {return self::error($id,-32602,'Invalid params');
        } catch (OutOfBoundsException $error) {return self::error($id,-32601,'Method not found');
        } catch (Throwable $error) {return self::error($id,-32603,'Internal error');}
    }

    private function initialize(array $params): array
    {
        $requested=$params['protocolVersion']??null;
        if (!is_string($requested)||preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}\\z/D',$requested)!==1) throw new InvalidArgumentException();
        return ['protocolVersion'=>in_array($requested,self::VERSIONS,true)?$requested:self::VERSIONS[0],
            'capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'anytour-development-work','version'=>'1.0.0']];
    }

    private function tools(): array
    {
        $byName=array_column(DevelopmentWorkMcpAdapter::tools(),null,'name');$out=[];
        foreach (self::TOOLS as $name) {
            if (!isset($byName[$name])) throw new RuntimeException();
            $out[]=$byName[$name];
        }
        return $out;
    }

    private function eventDefinitions(): array
    {
        $byName=array_column($this->events->eventDefinitions(),null,'name');$out=[];
        foreach (self::EVENTS as $name) {
            if (!isset($byName[$name])) throw new RuntimeException();
            $out[]=$byName[$name];
        }
        return $out;
    }

    private function callTool(array $params,int $admin,string $connection): array
    {
        self::keys($params,['name','arguments']);
        $name=$params['name']??null;$args=$params['arguments']??[];
        if (!is_string($name)||!is_array($args)||($args!==[]&&array_is_list($args))) throw new InvalidArgumentException();
        if (!in_array($name,self::TOOLS,true)) throw new OutOfBoundsException();
        try {$data=(new DevelopmentWorkMcpAdapter($this->pdo,$this->store,$this->clock,$this->events))->call($name,$args,$admin,$connection);}
        catch (DomainException $error) {
            if ($name!=='development_work_status'||$error->getMessage()!=='development_work_access_denied') throw $error;
            $this->requireContext($admin,$connection,null);
            $self=$this->oauth()->currentConnectionMetadata($connection,$admin,[ManagerPreparationOAuth::DEVELOPMENT_SCOPE]);
            if ($self===null) throw new DevelopmentWorkMcpAccessError('AUTH_CONTEXT_UNAVAILABLE');
            $data=['ok'=>false,'reason'=>'OWNED_QUEUE_UNAVAILABLE','queue_available'=>false,'caller_self'=>$self];
        }
        return ['content'=>[['type'=>'text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]],
            'structuredContent'=>$data,'isError'=>isset($data['ok'])&&$data['ok']!==true];
    }

    private function subscription(array $params,int $admin,string $connection,bool $remove): mixed
    {
        if (!in_array($params['name']??null,self::EVENTS,true)) throw new OutOfBoundsException();
        return $remove?$this->events->unsubscribe($params,$admin,$connection):$this->events->subscribe($params,$admin,$connection);
    }

    private function requireContext(int $admin,?string $connection,?array $scopes): void
    {
        if ($this->pdo->inTransaction()||$admin<1||$connection===null
            ||!$this->oauth()->connectionAuthorized($connection,$admin,[ManagerPreparationOAuth::DEVELOPMENT_SCOPE]))
            throw new DevelopmentWorkMcpAccessError('AUTH_CONTEXT_UNAVAILABLE');
        if ($scopes!==null&&!ManagerPreparationOAuth::hasScopes($scopes,[ManagerPreparationOAuth::DEVELOPMENT_SCOPE]))
            throw new DevelopmentWorkMcpAccessError('AUTH_SCOPE_UNAVAILABLE');
    }

    private function oauth(): ManagerPreparationOAuth
    {return new ManagerPreparationOAuth($this->pdo,$this->clock,ManagerPreparationOAuth::PROFILE_DEVELOPMENT);}
    private static function keys(array $params,array $allowed): void
    {if (array_diff(array_keys($params),$allowed)) throw new InvalidArgumentException();}
    private static function error(mixed $id,int $code,string $message,?array $data=null): array
    {
        $error=['code'=>$code,'message'=>$message];if ($data!==null) $error['data']=$data;
        return ['jsonrpc'=>'2.0','id'=>$id,'error'=>$error];
    }
}
final class DevelopmentWorkMcpAccessError extends DomainException
{
    public function __construct(public readonly string $reason) {parent::__construct('Access denied');}
}
