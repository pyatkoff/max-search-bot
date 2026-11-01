<?php
declare(strict_types=1);
require_once __DIR__.'/HotelWorkQueue.php';
require_once __DIR__.'/ManagerPreparationOAuth.php';

/** Internal recovery identity only. No public dispatch/install/recovery API. */
final class HotelNativeRecoveryContext
{
    public function __construct(public readonly PDO $pdo,public readonly int $admin,public readonly string $connection,private $clock=null)
    {if($admin<1||preg_match('/\A[a-f0-9]{32}\z/D',$connection)!==1)throw new InvalidArgumentException('native_recovery_identity');}
    public function now():int{return $this->clock===null?time():(int)($this->clock)();}
    public function authorize():void
    {
        if(ProjectConfig::projectId()!=='anytour')throw new DomainException('native_recovery_access_denied');
        if($this->pdo->inTransaction()){
            // owned() has already locked lane -> queue -> grant. The cron-only
            // cross-profile helper deliberately forbids caller transactions.
            $g=$this->one('SELECT resource FROM manager_preparation_oauth_grants WHERE grant_id=? AND admin_id=?',[$this->connection,$this->admin]);
            $profile=match($g['resource']??null){ManagerPreparationOAuth::RESOURCE=>ManagerPreparationOAuth::PROFILE_LEGACY,
                ManagerPreparationOAuth::ORIGIN.'/manager/hotels-mcp.php'=>ManagerPreparationOAuth::PROFILE_HOTELS,default=>null};
            $ok=$profile!==null&&(new ManagerPreparationOAuth($this->pdo,$this->clock,$profile))->connectionAuthorized($this->connection,$this->admin,[ManagerPreparationOAuth::HOTEL_SCOPE]);
        }else$ok=(new ManagerPreparationOAuth($this->pdo,$this->clock))->eventConnectionAuthorizedAcrossProfiles('hotels',$this->connection,$this->admin);
        if(!$ok)throw new DomainException('native_recovery_access_denied');
    }
    public function autocommit():void{if($this->pdo->inTransaction())throw new LogicException('native_recovery_foreign_transaction');}
    public function owned(string $queue,bool $lock=false):array
    {
        if(preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$queue)!==1)throw new InvalidArgumentException('native_recovery_queue');
        $suffix=$lock&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $lane=$this->one('SELECT queue_id FROM hotel_work_lane WHERE lane_key=?'.$suffix,['HOTEL']);
        $q=$this->one('SELECT * FROM hotel_work_queues WHERE queue_id=?'.$suffix,[$queue]);
        if(!$q||!$lane||$lane['queue_id']!==$queue||$q['project_key']!=='anytour'||(int)$q['admin_id']!==$this->admin||$q['oauth_connection_id']!==$this->connection)throw new DomainException('native_recovery_access_denied');
        if($lock)$this->one('SELECT grant_id FROM manager_preparation_oauth_grants WHERE grant_id=?'.$suffix,[$this->connection]);
        $this->authorize();return$q;
    }
    public function one(string $sql,array $args):?array{$s=$this->pdo->prepare($sql);$s->execute($args);return$s->fetch(PDO::FETCH_ASSOC)?:null;}
    public function exec(string $sql,array $args):void{$s=$this->pdo->prepare($sql);$s->execute($args);}
    public static function json(mixed $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function sha(string $v):void{if(preg_match('/\A[a-f0-9]{64}\z/D',$v)!==1)throw new InvalidArgumentException('native_recovery_sha');}
}
