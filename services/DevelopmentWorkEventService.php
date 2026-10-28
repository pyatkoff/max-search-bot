<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/ManagerPreparationOAuth.php';
require_once __DIR__.'/ManagerPreparationWebhook.php';
require_once __DIR__.'/DevelopmentWorkQueue.php';

/** Development metadata events only. Receipt delivery never proves hosted work finished. */
final class DevelopmentWorkEventService
{
    public const EVENTS = ['development_work.pending','development_work.attention'];
    private const PROJECT = 'anytour';
    private PDO $pdo;
    private ManagerPreparationWebhook $webhook;
    private DevelopmentWorkQueue $queue;

    public function __construct(?PDO $pdo=null,?ManagerPreparationWebhook $webhook=null,private $clock=null,
        private $connectionAuthorized=null,?DevelopmentWorkQueue $queue=null)
    {
        $this->pdo=$pdo??ConversationDb::connection();
        $this->webhook=$webhook??new ManagerPreparationWebhook();
        $this->queue=$queue??new DevelopmentWorkQueue($this->pdo,$clock);
    }

    public function eventDefinitions(): array
    {
        $out=[];
        foreach (self::EVENTS as $name) {
            $dispatch=$name==='development_work.pending';
            $out[]=['name'=>$name,'description'=>$dispatch
                ?'One authorized development task is ready. Claim the exact task before starting its existing owner; never repeat unknown work.'
                :'A development task needs reconciliation: inspect its claim, existing exact run and acceptance receipt. This event never authorizes dispatch or retry.',
                'delivery'=>['webhook'],
                'inputSchema'=>['type'=>'object','properties'=>[
                    'project_key'=>['type'=>'string','const'=>self::PROJECT],
                    'queue_id'=>['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$']],
                    'required'=>['project_key','queue_id'],'additionalProperties'=>false],
                'payloadSchema'=>['type'=>'object','properties'=>[
                    'project_key'=>['type'=>'string','const'=>self::PROJECT],
                    'queue_id'=>['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$'],
                    'task_id'=>['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$'],
                    'spec_sha256'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                    'owner_task_id'=>['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,128}$'],
                    'dispatch_allowed'=>['type'=>'boolean','const'=>$dispatch]],
                    'required'=>['project_key','queue_id','task_id','spec_sha256','owner_task_id','dispatch_allowed'],
                    'additionalProperties'=>false]];
        }
        return $out;
    }

    /** Private current-owned subscription metadata; never a HOST commit or dispatch proof. */
    public function subscriptionSetup(int $admin,string $connection,string $queueId): array
    {
        $this->requireSetupAutocommit();
        $this->authorizeSetup($admin,$connection);
        $this->queue->status($admin,$connection,$queueId);
        $this->requireSetupAutocommit();
        $now=$this->now();
        if ($now<1) throw new RuntimeException('development_subscription_status_unavailable');
        $result=self::unavailableSubscriptionSetup();
        try {
            $q=$this->pdo->prepare('SELECT event_name,is_active,expires_at,verified_at,next_attempt_at,last_status FROM development_work_events WHERE project_key=? AND admin_id=? AND oauth_connection_id=? AND queue_id=? AND event_name IN (?,?) LIMIT 21');
            if (!$q||!$q->execute([self::PROJECT,$admin,$connection,$queueId,...self::EVENTS])) throw new RuntimeException('development_subscription_status_unavailable');
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)||!array_is_list($rows)) throw new RuntimeException('development_subscription_status_unavailable');
            if (count($rows)>20) $result=self::unavailableSubscriptionSetup('row_limit');
            else {
                $grouped=array_fill_keys(self::EVENTS,[]);
                foreach ($rows as $row) {
                    $name=$row['event_name']??null;
                    if (!is_string($name)||!array_key_exists($name,$grouped)) throw new RuntimeException('development_subscription_status_unavailable');
                    $grouped[$name][]=$row;
                }
                $events=[];
                foreach ($grouped as $name=>$matches) {
                    $count=count($matches);
                    $events[]=$count===1?self::setupRow($name,$matches[0],$now)
                        :self::setupEntry($name,$count===0?'absent':'ambiguous',$count);
                }
                $result=['available'=>true,'observed_at'=>null,
                    'semantics'=>'server_subscription_metadata_not_native_commit_proof','events'=>$events];
            }
        } catch (RuntimeException $unavailable) {
            $result=self::unavailableSubscriptionSetup();
        }
        $this->requireSetupAutocommit();
        $this->authorizeSetup($admin,$connection);
        $this->queue->status($admin,$connection,$queueId);
        $this->requireSetupAutocommit();
        $result['observed_at']=gmdate('Y-m-d\TH:i:s\Z',$now);
        return $result;
    }

    public static function unavailableSubscriptionSetup(string $reason='unavailable'): array
    {
        $reason=$reason==='row_limit'?'row_limit':'unavailable';
        return ['available'=>false,'observed_at'=>null,
            'semantics'=>'server_subscription_metadata_not_native_commit_proof','reason'=>$reason,
            'events'=>array_map(static fn(string $name):array=>self::setupEntry($name),self::EVENTS)];
    }

    private static function setupEntry(string $name,string $state='unknown',?int $count=null): array
    {
        return ['event_name'=>$name,'state'=>$state,'matching_rows'=>$count,
            'eligible'=>$state==='absent'?false:null,'verified'=>null,'retrying'=>null,
            'last_http_status'=>null,'expires_at'=>null];
    }

    private static function setupRow(string $name,array $row,int $now): array
    {
        $out=self::setupEntry($name,'unknown',1);
        foreach (['is_active','expires_at','verified_at','next_attempt_at','last_status'] as $field)
            if (!array_key_exists($field,$row)) return $out;
        if (!in_array($row['is_active'],[0,1,'0','1'],true)) return $out;
        $expires=self::setupDate($row['expires_at']);$verified=self::setupDate($row['verified_at']);
        $retry=$row['next_attempt_at']===null?null:self::setupDate($row['next_attempt_at']);
        if ($expires===null||$verified===null||$verified>self::date($now)
            ||($row['next_attempt_at']!==null&&$retry===null)) return $out;
        $status=$row['last_status'];
        if ($status!==null&&(!is_int($status)&&!is_string($status)
            ||preg_match('/\A(?:0|[1-5][0-9]{2})\z/D',(string)$status)!==1)) return $out;
        $active=(string)$row['is_active']==='1'&&$expires>self::date($now);
        $out['state']=(string)$row['is_active']==='0'?'inactive':($active?'active_verified':'expired');
        $out['eligible']=$active;$out['verified']=true;
        $out['retrying']=$active&&$retry!==null;
        $out['last_http_status']=$status!==null&&(int)$status>=100?(int)$status:null;
        $out['expires_at']=str_replace(' ','T',$expires).'Z';
        return $out;
    }

    private static function setupDate(mixed $value): ?string
    {
        if (!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value)!==1) return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $date!==false&&$date->getTimestamp()>0&&$date->format('Y-m-d H:i:s')===$value?$value:null;
    }

    private function authorizeSetup(int $admin,string $connection): void
    {
        try { $this->authorize($admin,$connection); }
        catch (DomainException $denied) { throw new DevelopmentSubscriptionStatusAuthorizationError(); }
    }

    private function requireSetupAutocommit(): void
    {
        if ($this->pdo->inTransaction()) throw new LogicException('development_subscription_status_foreign_transaction');
    }

    public function subscribe(array $params,int $admin,?string $connection): array
    {
        $this->authorize($admin,$connection);
        [$id,$single,$queueId,$name,$url,$secret,$arguments]=$this->identity($params,$admin,$connection,true);
        $this->queue->status($admin,$connection,$queueId);
        $ttl=$params['ttlMs']??86400000;
        if (!is_int($ttl)||$ttl<1000) throw new InvalidArgumentException();
        $ttl=min($ttl,86400000);$now=$this->now();$date=self::date($now);
        $q=$this->pdo->prepare('SELECT * FROM development_work_events WHERE subscription_id=?');$q->execute([$id]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        // A replacement secret needs its own proof, even inside the verification cache.
        $verified=$prior['verified_at']??null;
        if (!$prior||(int)$prior['is_active']!==1||!hash_equals((string)$prior['signing_secret'],$secret)
            ||strtotime((string)$verified.' UTC')<$now-300) {
            $result=$this->webhook->verify($url,$secret,$id);
            if (!$result['ok']) throw new DevelopmentWorkCallbackError($result['reason']);
            $verified=$date;
        }
        // No lock is held over DNS/TLS or the remote challenge.
        $this->authorize($admin,$connection);$this->queue->status($admin,$connection,$queueId);
        $expires=self::date($now+max(1,intdiv($ttl,1000)));
        $sqlite=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if ($this->pdo->inTransaction()) throw new LogicException('development_event_foreign_transaction');
        if ($sqlite) $this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE development_work_events SET is_active=0,single_consumer_key=NULL WHERE single_consumer_key=? AND (is_active=0 OR expires_at<=?)')->execute([$single,$date]);
            $q=$this->pdo->prepare('SELECT subscription_id FROM development_work_events WHERE single_consumer_key=?');$q->execute([$single]);
            $other=$q->fetchColumn();
            if ($other!==false&&!hash_equals((string)$other,$id)) throw new InvalidArgumentException('development_event_consumer_exists');
            $q=$this->pdo->prepare('SELECT * FROM development_work_events WHERE subscription_id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM development_work_events WHERE project_key=? AND is_active=1 AND expires_at>? AND subscription_id<>?');
            $q->execute([self::PROJECT,$date,$id]);if ((int)$q->fetchColumn()>=20) throw new InvalidArgumentException();
            if ($row) {
                $rotated=!hash_equals((string)$row['signing_secret'],$secret);
                $this->pdo->prepare('UPDATE development_work_events SET single_consumer_key=?,signing_secret=?,previous_secret=?,previous_secret_until=?,verified_at=?,expires_at=?,is_active=1,updated_at=? WHERE subscription_id=? AND admin_id=? AND oauth_connection_id=?')
                    ->execute([$single,$secret,$rotated?$row['signing_secret']:$row['previous_secret'],
                        $rotated?self::date($now+300):$row['previous_secret_until'],$verified,$expires,$date,$id,$admin,$connection]);
            } else {
                $this->pdo->prepare('INSERT INTO development_work_events (subscription_id,single_consumer_key,admin_id,oauth_connection_id,project_key,queue_id,event_name,arguments_json,callback_url,signing_secret,verified_at,expires_at,is_active,attempts,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,0,?,?)')
                    ->execute([$id,$single,$admin,$connection,self::PROJECT,$queueId,$name,$arguments,$url,$secret,$verified,$expires,$date,$date]);
            }
            if ($sqlite) $this->pdo->exec('COMMIT');else $this->pdo->commit();
        } catch (Throwable $error) {
            try {if ($sqlite) $this->pdo->exec('ROLLBACK');elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();}
            catch (Throwable $ignored) {throw new RuntimeException('development_event_rollback_failed');}
            throw $error;
        }
        return ['id'=>$id,'refreshBefore'=>str_replace(' ','T',$expires).'Z','cursor'=>null,'truncated'=>false];
    }

    public function unsubscribe(array $params,int $admin,?string $connection): object
    {
        $this->authorize($admin,$connection);
        [$id]=$this->identity($params,$admin,$connection,false);
        $this->pdo->prepare('UPDATE development_work_events SET is_active=0,single_consumer_key=NULL,pending_event_id=NULL,pending_body=NULL,updated_at=? WHERE subscription_id=? AND admin_id=? AND oauth_connection_id=?')
            ->execute([self::date($this->now()),$id,$admin,$connection]);
        return (object)[];
    }

    public function hasActiveSubscriptions(): bool
    {
        $q=$this->pdo->prepare('SELECT admin_id,oauth_connection_id,queue_id FROM development_work_events WHERE project_key=? AND is_active=1 AND expires_at>? ORDER BY created_at LIMIT 20');
        $q->execute([self::PROJECT,self::date($this->now())]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {$this->authorize((int)$row['admin_id'],$row['oauth_connection_id']);$this->queue->status((int)$row['admin_id'],$row['oauth_connection_id'],$row['queue_id']);return true;}
            catch (DomainException $ignored) {}
        }
        return false;
    }

    /** One bounded model-free server pass; only metadata leaves the server. */
    public function deliverPending(): array
    {
        $date=self::date($this->now());
        // Backoff belongs to an event, not its subscription. Inspect readiness
        // every minute so a new task never inherits an old event's delay.
        $q=$this->pdo->prepare('SELECT subscription_id FROM development_work_events WHERE project_key=? AND is_active=1 AND expires_at>? ORDER BY updated_at,subscription_id LIMIT 20');
        $q->execute([self::PROJECT,$date]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $counts=['subscriptions'=>count($rows),'accepted'=>0,'retried'=>0,'stopped'=>0,'unchanged'=>0];
        foreach ($rows as $candidate) {
            $id=$candidate['subscription_id'];$token=bin2hex(random_bytes(16));$now=$this->now();$date=self::date($now);
            $q=$this->pdo->prepare('UPDATE development_work_events SET lease_token=?,lease_until=? WHERE subscription_id=? AND is_active=1 AND expires_at>? AND (lease_until IS NULL OR lease_until<=?)');
            $q->execute([$token,self::date($now+30),$id,$date,$date]);if ($q->rowCount()!==1) continue;
            try {
                $q=$this->pdo->prepare('SELECT * FROM development_work_events WHERE subscription_id=? AND lease_token=?');$q->execute([$id,$token]);$row=$q->fetch(PDO::FETCH_ASSOC);if (!$row) continue;
                try {$this->authorize((int)$row['admin_id'],$row['oauth_connection_id']);}
                catch (DomainException $denied) {$this->finish($id,$token,['is_active'=>0,'single_consumer_key'=>null]);++$counts['stopped'];continue;}
                $event=$this->queue->pendingEvent((int)$row['admin_id'],$row['oauth_connection_id'],$row['queue_id']);
                if ($event===null||$event['name']!==$row['event_name']) {
                    $this->finish($id,$token,['pending_event_id'=>null,'pending_body'=>null,'attempts'=>0,'next_attempt_at'=>null]);++$counts['unchanged'];continue;
                }
                self::validEvent($event,$row['queue_id']);
                if ($row['pending_event_id']===$event['event_id']&&$row['pending_body']!==null
                    &&($row['next_attempt_at']??'')>$date) {
                    $this->finish($id,$token,[]);++$counts['unchanged'];continue;
                }
                if ($row['pending_event_id']!==$event['event_id']||$row['pending_body']===null) {
                    $body=json_encode(['eventId'=>$event['event_id'],'name'=>$event['name'],'timestamp'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                        'data'=>['project_key'=>self::PROJECT]+$event['data'],'cursor'=>null],JSON_THROW_ON_ERROR);
                    if (strlen($body)>2048) throw new RuntimeException('development_event_too_large');
                    $this->pdo->prepare('UPDATE development_work_events SET pending_event_id=?,pending_body=?,attempts=0,next_attempt_at=NULL WHERE subscription_id=? AND lease_token=?')->execute([$event['event_id'],$body,$id,$token]);
                    $row['pending_event_id']=$event['event_id'];$row['pending_body']=$body;$row['attempts']=0;
                }
                // Recheck access, subscription lifetime and task immediately before sending.
                $this->authorize((int)$row['admin_id'],$row['oauth_connection_id']);
                $q=$this->pdo->prepare('SELECT 1 FROM development_work_events WHERE subscription_id=? AND lease_token=? AND is_active=1 AND expires_at>? AND lease_until>?');
                $date=self::date($this->now());$q->execute([$id,$token,$date,$date]);
                $current=$this->queue->pendingEvent((int)$row['admin_id'],$row['oauth_connection_id'],$row['queue_id']);
                if (!$q->fetchColumn()||$current===null||$current['event_id']!==$event['event_id']) {
                    $this->finish($id,$token,['pending_event_id'=>null,'pending_body'=>null,'attempts'=>0,'next_attempt_at'=>null]);++$counts['unchanged'];continue;
                }
                $old=($row['previous_secret_until']??'')>$date?$row['previous_secret']:null;
                $response=$this->webhook->request($row['callback_url'],$row['signing_secret'],$event['event_id'],$id,$row['pending_body'],$old);
                $status=$response['status'];$attempts=(int)$row['attempts']+1;
                if ($status>=200&&$status<300) {
                    try {$this->queue->markEventDelivered((int)$row['admin_id'],$row['oauth_connection_id'],$row['queue_id'],$event['event_id']);}
                    catch (DevelopmentWorkQueueConflict $changed) {if ($changed->reason!=='stale_event') throw $changed;}
                    $this->finish($id,$token,['pending_event_id'=>null,'pending_body'=>null,'attempts'=>0,'next_attempt_at'=>null,'last_status'=>$status]);++$counts['accepted'];
                } elseif (in_array($status,[410,413],true)||$attempts>=5) {
                    $this->finish($id,$token,['is_active'=>0,'single_consumer_key'=>null,'attempts'=>$attempts,'last_status'=>$status]);++$counts['stopped'];
                } else {
                    $this->finish($id,$token,['attempts'=>$attempts,'last_status'=>$status,'next_attempt_at'=>self::date($this->now()+min(900,60*(2**($attempts-1))))]);++$counts['retried'];
                }
            } catch (DomainException $denied) {
                $this->finish($id,$token,['is_active'=>0,'single_consumer_key'=>null]);++$counts['stopped'];
            } catch (Throwable $ignored) {
                $this->finish($id,$token,['next_attempt_at'=>self::date($this->now()+60)]);++$counts['retried'];
            }
        }
        return $counts;
    }

    private function identity(array $params,int $admin,string $connection,bool $withSecret): array
    {
        self::keys($params,['name','arguments','delivery','cursor','ttlMs']);$name=$params['name']??null;$args=$params['arguments']??null;
        if (!in_array($name,self::EVENTS,true)||!is_array($args)) throw new InvalidArgumentException();
        self::keys($args,['project_key','queue_id']);$queueId=$args['queue_id']??null;
        if (($args['project_key']??null)!==self::PROJECT||!is_string($queueId)||!preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$queueId)
            ||($params['cursor']??null)!==null) throw new InvalidArgumentException();
        $delivery=$params['delivery']??null;if (!is_array($delivery)) throw new InvalidArgumentException();
        self::keys($delivery,$withSecret?['mode','url','secret']:['mode','url']);$url=$delivery['url']??null;$secret=$delivery['secret']??'';
        if (($delivery['mode']??null)!=='webhook'||!is_string($url)||strlen($url)>2048||!str_starts_with($url,'https://')
            ||($withSecret&&($this->webhook->destination($url)===null||!is_string($secret)||ManagerPreparationWebhook::secretBytes($secret)===null))) throw new InvalidArgumentException();
        $arguments=json_encode(['project_key'=>self::PROJECT,'queue_id'=>$queueId],JSON_THROW_ON_ERROR);
        $id='dev_sub_'.hash('sha256',json_encode([$admin,$connection,$name,$arguments,$url],JSON_THROW_ON_ERROR));
        $single=hash('sha256',json_encode([$admin,$connection,$queueId,$name],JSON_THROW_ON_ERROR));
        return [$id,$single,$queueId,$name,$url,$secret,$arguments];
    }

    private function authorize(int $admin,?string $connection): void
    {
        if ($admin<=0||ProjectConfig::projectId()!==self::PROJECT||!is_string($connection)||!preg_match('/\A[a-f0-9]{32}\z/D',$connection)) throw new DomainException();
        $ok=$this->connectionAuthorized!==null?($this->connectionAuthorized)($connection,$admin,['development_work:write']):ManagerPreparationOAuth::isConnectionAuthorized($connection,$admin,['development_work:write']);
        if ($ok!==true) throw new DomainException();
    }
    private static function validEvent(array $event,string $queueId): void
    {
        if (!in_array($event['name']??null,self::EVENTS,true)||!is_array($event['data']??null)) throw new RuntimeException('invalid_development_event');
        $pattern=$event['name']==='development_work.pending'?'/\Adev_evt_[a-f0-9]{64}\z/D':'/\Adev_attention_[a-f0-9]{64}\z/D';
        if (!is_string($event['event_id']??null)||preg_match($pattern,$event['event_id'])!==1) throw new RuntimeException('invalid_development_event');
        $d=$event['data'];self::keys($d,['queue_id','task_id','spec_sha256','owner_task_id','dispatch_allowed']);
        if (($d['queue_id']??null)!==$queueId||!is_string($d['task_id']??null)||preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$d['task_id'])!==1
            ||!is_string($d['spec_sha256']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$d['spec_sha256'])!==1
            ||!is_string($d['owner_task_id']??null)||preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$d['owner_task_id'])!==1
            ||($d['dispatch_allowed']??null)!==($event['name']==='development_work.pending')) throw new RuntimeException('invalid_development_event');
    }
    private function finish(string $id,string $token,array $fields): void
    {
        $fields['lease_token']=null;$fields['lease_until']=null;$fields['updated_at']=self::date($this->now());
        $sets=[];foreach ($fields as $key=>$value) $sets[]=$key.'=?';
        $this->pdo->prepare('UPDATE development_work_events SET '.implode(',',$sets).' WHERE subscription_id=? AND lease_token=?')->execute([...array_values($fields),$id,$token]);
    }
    private static function keys(array $value,array $allowed): void {if (array_diff(array_keys($value),$allowed)) throw new InvalidArgumentException();}
    private static function date(int $at): string {return gmdate('Y-m-d H:i:s',$at);}
    private function now(): int {return $this->clock!==null?(int)($this->clock)():time();}
}

/** Internal status-only authority failure; carries no caller or storage data. */
final class DevelopmentSubscriptionStatusAuthorizationError extends DomainException {}

final class DevelopmentWorkCallbackError extends RuntimeException
{
    public function __construct(public readonly string $reason) {parent::__construct('Development callback verification failed');}
}
