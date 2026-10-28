<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPreparationOAuth.php';

/** Exact metadata authority for an explicitly requested same-admin recovery.
 * Never reads credentials, issues grants, selects a different caller or repairs access.
 */
final class DevelopmentWorkConnectionAuthority
{
    private string $driver;
    public function __construct(private PDO $pdo,private $clock=null)
    {
        $this->driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver,['sqlite','mysql'],true)||($clock!==null&&!is_callable($clock)))
            throw new InvalidArgumentException('invalid_development_work_runtime');
    }

    /** Called only inside the queue's lane/queue transaction, again before return. */
    public function assertPair(int $admin,string $old,string $current): bool
    {
        if ($admin<1||$old===$current||preg_match('/\A[a-f0-9]{32}\z/D',$old)!==1
            ||preg_match('/\A[a-f0-9]{32}\z/D',$current)!==1||ProjectConfig::projectId()!=='anytour')
            throw new DomainException('development_work_access_denied');
        // Deterministic order prevents opposite grant-pair lock acquisition.
        $ids=[$old,$current];sort($ids,SORT_STRING);$rows=[];
        foreach ($ids as $id) $rows[$id]=$this->grant($id,$admin);
        $this->activeAdmin($admin);
        $profile=$this->profile($rows[$current]);
        $now=$this->now();
        if ($rows[$current]['revoked_at']!==null||(int)$rows[$current]['expires_at']<=$now)
            throw new DomainException('development_work_access_denied');
        $oauth=new ManagerPreparationOAuth($this->pdo,$this->clock,$profile);
        if (!$oauth->connectionAuthorized($current,$admin,[ManagerPreparationOAuth::DEVELOPMENT_SCOPE])) {
            // A proven malformed/revoked current authority is a denial; an
            // unexplained canonical read failure must not become a login loop.
            $fresh=$this->grant($current,$admin);$this->activeAdmin($admin);
            if ($fresh['revoked_at']!==null||(int)$fresh['expires_at']<=$this->now())
                throw new DomainException('development_work_access_denied');
            throw new RuntimeException('development_work_authority_unavailable');
        }
        return true;
    }

    /** Existing current owner may read its immutable completed handoff audit. */
    public function assertCurrent(int $admin,string $current): bool
    {
        if ($admin<1||preg_match('/\A[a-f0-9]{32}\z/D',$current)!==1||ProjectConfig::projectId()!=='anytour')
            throw new DomainException('development_work_access_denied');
        $grant=$this->grant($current,$admin);$this->activeAdmin($admin);
        if ($grant['revoked_at']!==null||(int)$grant['expires_at']<=$this->now())
            throw new DomainException('development_work_access_denied');
        if (!(new ManagerPreparationOAuth($this->pdo,$this->clock,$this->profile($grant)))
            ->connectionAuthorized($current,$admin,[ManagerPreparationOAuth::DEVELOPMENT_SCOPE]))
            throw new RuntimeException('development_work_authority_unavailable');
        return true;
    }

    private function grant(string $connection,int $admin): array
    {
        $q=$this->pdo->prepare('SELECT g.grant_id,g.client_id,g.admin_id,g.project_key,g.resource,g.scopes,g.expires_at,g.revoked_at,c.redirect_uri
            FROM manager_preparation_oauth_grants g JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id
            WHERE g.grant_id=? AND g.admin_id=? LIMIT 1'.$this->lock());
        if (!$q||!$q->execute([$connection,$admin])) throw new RuntimeException('development_work_authority_unavailable');
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)||($row['grant_id']??null)!==$connection||(int)($row['admin_id']??0)!==$admin
            ||($row['project_key']??null)!=='anytour'||($row['redirect_uri']??null)!==ManagerPreparationOAuth::REDIRECT_URI)
            throw new DomainException('development_work_access_denied');
        $profile=$this->profile($row);
        $client=$row['client_id']??null;
        if (!is_string($client)||preg_match($profile===ManagerPreparationOAuth::PROFILE_DEVELOPMENT
                ?'/\Adev_[a-f0-9]{44}\z/D':'/\A[a-f0-9]{48}\z/D',$client)!==1)
            throw new DomainException('development_work_access_denied');
        $scope=$row['scopes']??null;
        if (!is_string($scope)||strlen($scope)>100) throw new DomainException('development_work_access_denied');
        $parts=explode(' ',$scope);$allowed=ManagerPreparationOAuth::resourceMetadata($profile)['scopes_supported'];
        if (count(array_unique($parts))!==count($parts)||array_diff($parts,$allowed)
            ||implode(' ',array_values(array_intersect($allowed,$parts)))!==$scope
            ||!in_array(ManagerPreparationOAuth::DEVELOPMENT_SCOPE,$parts,true))
            throw new DomainException('development_work_access_denied');
        $reply=ManagerPreparationOAuth::SCOPES;
        if (array_intersect($parts,$reply)&&!ManagerPreparationOAuth::hasScopes($parts,$reply))
            throw new DomainException('development_work_access_denied');
        $time=static fn($v):bool=>(is_int($v)&&$v>=0)||(is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$v)===1);
        if (!$time($row['expires_at']??null)||!array_key_exists('revoked_at',$row)
            ||($row['revoked_at']!==null&&!$time($row['revoked_at'])))
            throw new DomainException('development_work_access_denied');
        return $row;
    }

    private function profile(array $row): string
    {
        return match ($row['resource']??null) {
            ManagerPreparationOAuth::RESOURCE=>ManagerPreparationOAuth::PROFILE_LEGACY,
            ManagerPreparationOAuth::DEVELOPMENT_RESOURCE=>ManagerPreparationOAuth::PROFILE_DEVELOPMENT,
            default=>throw new DomainException('development_work_access_denied'),
        };
    }

    private function activeAdmin(int $admin): void
    {
        $name=(string)ProjectConfig::get('brand.name','anytour');
        $q=$this->pdo->prepare("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id
            JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.is_active=1 AND m.role='admin'
            AND p.project_key=? AND p.is_active=1 AND p.display_name=? LIMIT 1".$this->lock());
        if (!$q||!$q->execute([$admin,'anytour',$name!==''?$name:'anytour']))
            throw new RuntimeException('development_work_authority_unavailable');
        if ($q->fetchColumn()===false) throw new DomainException('development_work_access_denied');
    }

    private function lock(): string {return $this->driver==='mysql'?' FOR UPDATE':'';}
    private function now(): int
    {
        $at=$this->clock===null?time():($this->clock)();
        if (!is_int($at)||$at<1) throw new RuntimeException('development_work_authority_unavailable');
        return $at;
    }
}
