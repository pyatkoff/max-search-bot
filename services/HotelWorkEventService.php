<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/ManagerPreparationOAuth.php';
require_once __DIR__.'/ManagerPreparationWebhook.php';
require_once __DIR__.'/HotelWorkQueue.php';

/** Hotel metadata events only. Receipt delivery never proves hosted work finished. */
final class HotelWorkEventService
{
    public const EVENTS = ['hotel_work.pending','hotel_work.attention'];
    private const PROJECT = 'anytour';
    private PDO $pdo;
    private ManagerPreparationWebhook $webhook;
    private HotelWorkQueue $queue;

    public function __construct(?PDO $pdo=null,?ManagerPreparationWebhook $webhook=null,private $clock=null,
        private $connectionAuthorized=null,?HotelWorkQueue $queue=null)
    {
        $this->pdo=$pdo??ConversationDb::connection();
        $this->webhook=$webhook??new ManagerPreparationWebhook();
        $this->queue=$queue??new HotelWorkQueue($this->pdo,$clock);
    }

    public function eventDefinitions(): array
    {
        $out=[];
        foreach (self::EVENTS as $name) {
            $dispatch=$name==='hotel_work.pending';
            $out[]=['name'=>$name,'description'=>$dispatch
                ?'One authorized hotel metadata stage is ready. Claim it before starting the existing owner; never repeat unknown work.'
                :'A hotel stage needs reconciliation: inspect its claim, existing exact run and receipt. This event never authorizes dispatch or retry.',
                'delivery'=>['webhook'],
                'inputSchema'=>['type'=>'object','properties'=>[
                    'project_key'=>['type'=>'string','const'=>self::PROJECT],
                    'queue_id'=>['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$']],
                    'required'=>['project_key','queue_id'],'additionalProperties'=>false],
                'payloadSchema'=>['type'=>'object','properties'=>[
                    'project_key'=>['type'=>'string','const'=>self::PROJECT],
                    'queue_id'=>['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$'],
                    'stage_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                    'stage_kind'=>['type'=>'string','enum'=>['research','intake']],
                    'country'=>['type'=>'string','enum'=>['EG','TH','VN','TR']],
                    'dispatch_allowed'=>['type'=>'boolean','const'=>$dispatch]],
                    'required'=>['project_key','queue_id','stage_id','stage_kind','country','dispatch_allowed'],
                    'additionalProperties'=>false]];
        }
        return $out;
    }

    public function subscribe(array $params,int $admin,?string $connection): array
    {
        $this->authorize($admin,$connection);
        [$id,$single,$queueId,$name,$url,$secret,$arguments]=$this->identity($params,$admin,$connection,true);
        $this->queue->status($admin,$connection,$queueId);
        $ttl=$params['ttlMs']??86400000;
        if (!is_int($ttl)||$ttl<1000) throw new InvalidArgumentException();
        $ttl=min($ttl,86400000);$now=$this->now();$date=self::date($now);
        $q=$this->pdo->prepare('SELECT * FROM hotel_work_events WHERE subscription_id=?');$q->execute([$id]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        // A replacement secret needs its own proof, even inside the verification cache.
        $verified=$prior['verified_at']??null;
        if (!$prior||(int)$prior['is_active']!==1||!hash_equals((string)$prior['signing_secret'],$secret)
            ||strtotime((string)$verified.' UTC')<$now-300) {
            $result=$this->webhook->verify($url,$secret,$id);
            if (!$result['ok']) throw new HotelWorkCallbackError($result['reason']);
            $verified=$date;
        }
        // No lock is held over DNS/TLS or the remote challenge.
        $this->authorize($admin,$connection);$this->queue->status($admin,$connection,$queueId);
        $expires=self::date($now+max(1,intdiv($ttl,1000)));
        $sqlite=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if ($this->pdo->inTransaction()) throw new LogicException('hotel_event_foreign_transaction');
        if ($sqlite) $this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE hotel_work_events SET is_active=0,single_consumer_key=NULL WHERE single_consumer_key=? AND (is_active=0 OR expires_at<=?)')->execute([$single,$date]);
            $q=$this->pdo->prepare('SELECT subscription_id FROM hotel_work_events WHERE single_consumer_key=?');$q->execute([$single]);
            $other=$q->fetchColumn();
            if ($other!==false&&!hash_equals((string)$other,$id)) throw new InvalidArgumentException('hotel_event_consumer_exists');
            $q=$this->pdo->prepare('SELECT * FROM hotel_work_events WHERE subscription_id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM hotel_work_events WHERE project_key=? AND is_active=1 AND expires_at>? AND subscription_id<>?');
            $q->execute([self::PROJECT,$date,$id]);if ((int)$q->fetchColumn()>=20) throw new InvalidArgumentException();
            if ($row) {
                $rotated=!hash_equals((string)$row['signing_secret'],$secret);
                $this->pdo->prepare('UPDATE hotel_work_events SET single_consumer_key=?,signing_secret=?,previous_secret=?,previous_secret_until=?,verified_at=?,expires_at=?,is_active=1,updated_at=? WHERE subscription_id=? AND admin_id=? AND oauth_connection_id=?')
                    ->execute([$single,$secret,$rotated?$row['signing_secret']:$row['previous_secret'],
                        $rotated?self::date($now+300):$row['previous_secret_until'],$verified,$expires,$date,$id,$admin,$connection]);
            } else {
                $this->pdo->prepare('INSERT INTO hotel_work_events (subscription_id,single_consumer_key,admin_id,oauth_connection_id,project_key,queue_id,event_name,arguments_json,callback_url,signing_secret,verified_at,expires_at,is_active,attempts,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,0,?,?)')
                    ->execute([$id,$single,$admin,$connection,self::PROJECT,$queueId,$name,$arguments,$url,$secret,$verified,$expires,$date,$date]);
            }
            if ($sqlite) $this->pdo->exec('COMMIT');else $this->pdo->commit();
        } catch (Throwable $error) {
            try {if ($sqlite) $this->pdo->exec('ROLLBACK');elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();}
            catch (Throwable $ignored) {throw new RuntimeException('hotel_event_rollback_failed');}
            throw $error;
        }
        return ['id'=>$id,'refreshBefore'=>str_replace(' ','T',$expires).'Z','cursor'=>null,'truncated'=>false];
    }

    public function unsubscribe(array $params,int $admin,?string $connection): object
    {
        $this->authorize($admin,$connection);
        [$id]=$this->identity($params,$admin,$connection,false);
        $this->pdo->prepare('UPDATE hotel_work_events SET is_active=0,single_consumer_key=NULL,pending_event_id=NULL,pending_body=NULL,updated_at=? WHERE subscription_id=? AND admin_id=? AND oauth_connection_id=?')
            ->execute([self::date($this->now()),$id,$admin,$connection]);
        return (object)[];
    }

    public function hasActiveSubscriptions(): bool
    {
        $q=$this->pdo->prepare('SELECT admin_id,oauth_connection_id,queue_id FROM hotel_work_events WHERE project_key=? AND is_active=1 AND expires_at>? ORDER BY created_at LIMIT 20');
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
        // every minute so a new stage never inherits an old event's delay.
        $q=$this->pdo->prepare('SELECT subscription_id FROM hotel_work_events WHERE project_key=? AND is_active=1 AND expires_at>? ORDER BY updated_at,subscription_id LIMIT 20');
        $q->execute([self::PROJECT,$date]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $counts=['subscriptions'=>count($rows),'accepted'=>0,'retried'=>0,'stopped'=>0,'unchanged'=>0];
        foreach ($rows as $candidate) {
            $id=$candidate['subscription_id'];$token=bin2hex(random_bytes(16));$now=$this->now();$date=self::date($now);
            $q=$this->pdo->prepare('UPDATE hotel_work_events SET lease_token=?,lease_until=? WHERE subscription_id=? AND is_active=1 AND expires_at>? AND (lease_until IS NULL OR lease_until<=?)');
            $q->execute([$token,self::date($now+30),$id,$date,$date]);if ($q->rowCount()!==1) continue;
            try {
                $q=$this->pdo->prepare('SELECT * FROM hotel_work_events WHERE subscription_id=? AND lease_token=?');$q->execute([$id,$token]);$row=$q->fetch(PDO::FETCH_ASSOC);if (!$row) continue;
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
                    if (strlen($body)>2048) throw new RuntimeException('hotel_event_too_large');
                    $this->pdo->prepare('UPDATE hotel_work_events SET pending_event_id=?,pending_body=?,attempts=0,next_attempt_at=NULL WHERE subscription_id=? AND lease_token=?')->execute([$event['event_id'],$body,$id,$token]);
                    $row['pending_event_id']=$event['event_id'];$row['pending_body']=$body;$row['attempts']=0;
                }
                // Recheck access, subscription lifetime and stage immediately before sending.
                $this->authorize((int)$row['admin_id'],$row['oauth_connection_id']);
                $q=$this->pdo->prepare('SELECT 1 FROM hotel_work_events WHERE subscription_id=? AND lease_token=? AND is_active=1 AND expires_at>? AND lease_until>?');
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
                    catch (HotelWorkQueueConflict $changed) {if ($changed->reason!=='stale_event') throw $changed;}
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
        $id='hotel_sub_'.hash('sha256',json_encode([$admin,$connection,$name,$arguments,$url],JSON_THROW_ON_ERROR));
        $single=hash('sha256',json_encode([$admin,$connection,$queueId,$name],JSON_THROW_ON_ERROR));
        return [$id,$single,$queueId,$name,$url,$secret,$arguments];
    }

    private function authorize(int $admin,?string $connection): void
    {
        if ($admin<=0||ProjectConfig::projectId()!==self::PROJECT||!is_string($connection)||!preg_match('/\A[a-f0-9]{32}\z/D',$connection)) throw new DomainException();
        $ok=$this->connectionAuthorized!==null?($this->connectionAuthorized)($connection,$admin,['hotel_work:write']):ManagerPreparationOAuth::isConnectionAuthorized($connection,$admin,['hotel_work:write']);
        if ($ok!==true) throw new DomainException();
    }
    private static function validEvent(array $event,string $queueId): void
    {
        if (!is_string($event['event_id']??null)||!preg_match('/\Ahotel_(?:evt|attention)_[a-f0-9]{64}\z/D',$event['event_id'])
            ||!in_array($event['name']??null,self::EVENTS,true)||!is_array($event['data']??null)) throw new RuntimeException('invalid_hotel_event');
        $d=$event['data'];self::keys($d,['queue_id','stage_id','stage_kind','country','dispatch_allowed']);
        if (($d['queue_id']??null)!==$queueId||!is_string($d['stage_id']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$d['stage_id'])
            ||!in_array($d['stage_kind']??null,['research','intake'],true)||!in_array($d['country']??null,['EG','TH','VN','TR'],true)
            ||($d['dispatch_allowed']??null)!==($event['name']==='hotel_work.pending')) throw new RuntimeException('invalid_hotel_event');
    }
    private function finish(string $id,string $token,array $fields): void
    {
        $fields['lease_token']=null;$fields['lease_until']=null;$fields['updated_at']=self::date($this->now());
        $sets=[];foreach ($fields as $key=>$value) $sets[]=$key.'=?';
        $this->pdo->prepare('UPDATE hotel_work_events SET '.implode(',',$sets).' WHERE subscription_id=? AND lease_token=?')->execute([...array_values($fields),$id,$token]);
    }
    private static function keys(array $value,array $allowed): void {if (array_diff(array_keys($value),$allowed)) throw new InvalidArgumentException();}
    private static function date(int $at): string {return gmdate('Y-m-d H:i:s',$at);}
    private function now(): int {return $this->clock!==null?(int)($this->clock)():time();}
}

final class HotelWorkCallbackError extends RuntimeException
{
    public function __construct(public readonly string $reason) {parent::__construct('Hotel callback verification failed');}
}
