<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitContract.php';

/** No runtime DDL, network, conversion exports or dialogue mutations. */
final class AdvertisingVisitRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Read-only resume token anchored to the last committed observation. */
    public function checkpoint(array $scope, bool $allowUnbound = false): array
    {
        $scope = AdvertisingVisitContract::scope($scope, $allowUnbound);
        $q = $this->pdo->prepare('SELECT c.last_cursor,o.payload_sha256 FROM advertising_visit_cursors c LEFT JOIN advertising_visit_observations o ON o.project_key=c.project_key AND o.source_key=c.source_key AND o.platform=c.platform AND o.counter_id=c.counter_id AND o.source_cursor=c.last_cursor WHERE c.project_key=? AND c.source_key=? AND c.platform=? AND c.counter_id=? LIMIT 1');
        $q->execute(array_values($scope));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['last_cursor'] === '0') return ['cursor'=>'0','fingerprint'=>null];
        if (!is_string($row['payload_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/D', $row['payload_sha256'])) {
            throw new RuntimeException('advertising_visit_checkpoint_missing_observation');
        }
        return ['cursor'=>(string)$row['last_cursor'],'fingerprint'=>$row['payload_sha256']];
    }

    /** A feed checkpoint and its complete observation page commit together. */
    public function importPage(array $page, int $receivedAt, bool $nativeLaunch = false): array
    {
        if ($this->pdo->inTransaction()) throw new LogicException('advertising_visit_outer_transaction');
        if($nativeLaunch&&(($page['schema_version']??null)!==2||count($page['observations'])!==1))throw new LogicException('invalid_native_page');
        $scope = array_intersect_key($page, array_flip(['project_key','source_key','platform','counter_id']));
        $params = array_values($scope);
        $where = 'project_key=? AND source_key=? AND platform=? AND counter_id=?';
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO advertising_visit_cursors (project_key,source_key,platform,counter_id,last_cursor) VALUES (?,?,?,?,\'0\')';
            $sql .= $mysql ? ' ON DUPLICATE KEY UPDATE last_cursor=last_cursor' : ' ON CONFLICT(project_key,source_key,platform,counter_id) DO NOTHING';
            $this->pdo->prepare($sql)->execute($params);
            $q = $this->pdo->prepare('SELECT last_cursor FROM advertising_visit_cursors WHERE '.$where.($mysql ? ' FOR UPDATE' : ''));
            $q->execute($params);
            $cursor = (string)$q->fetchColumn();
            if($nativeLaunch){
                // Recheck after the shared scope lock. A concurrent committed
                // launch must not append a second observation with the next cursor.
                $existing=$this->nativeExisting($scope,$page['observations'][0]);
                if($existing!==null){$this->pdo->commit();return ['inserted'=>0,'replayed'=>1,'cursor'=>(string)$existing['source_cursor']];}
            }
            $replay = $cursor !== $page['after_cursor'];
            if ($replay && AdvertisingVisitContract::compare($cursor, $page['next_cursor']) < 0) throw new RuntimeException('advertising_visit_cursor_conflict');
            $inserted = $replayed = 0;
            foreach ($page['observations'] as $row) {
                $fingerprint = AdvertisingVisitContract::fingerprint($row);
                $q = $this->pdo->prepare('SELECT payload_sha256 FROM advertising_visit_observations WHERE '.$where.' AND source_cursor=?');
                $q->execute([...$params, $row['source_cursor']]);
                $existing = $q->fetchColumn();
                if ($existing !== false) {
                    if (!hash_equals((string)$existing, $fingerprint)) throw new RuntimeException('advertising_visit_observation_conflict');
                    $replayed++;
                    continue;
                }
                if ($replay) throw new RuntimeException('advertising_visit_incomplete_replay');
                $q = $this->pdo->prepare('SELECT visit_at_unix FROM advertising_visit_observations WHERE '.$where.' AND visit_id=? LIMIT 1');
                $q->execute([...$params, $row['visit_id']]);
                $originalTime = $q->fetchColumn();
                if ($originalTime !== false && (string)$originalTime !== (string)$row['visit_at_unix']) throw new RuntimeException('advertising_visit_entry_conflict');
                if ($row['identity_status'] === 'verified') {
                    $q = $this->pdo->prepare('SELECT external_user_id FROM advertising_visit_observations WHERE '.$where.' AND visit_id=? AND identity_status=\'verified\' AND external_user_id<>? LIMIT 1');
                    $q->execute([...$params, $row['visit_id'], $row['external_user_id']]);
                    if ($q->fetchColumn() !== false) throw new RuntimeException('advertising_visit_identity_conflict');
                }
                $values = $scope + $row + ['payload_sha256'=>$fingerprint,'received_at_unix'=>$receivedAt];
                $columns = ['project_key','source_key','platform','counter_id','source_cursor','visit_id','visit_at_unix','observed_at_unix','external_user_id','identity_status','client_id','yclid','campaign_id','region_id','payload_sha256','received_at_unix'];
                if (($page['schema_version'] ?? 1) === 2) $columns=[...$columns,'post_ref','offer_ref','context_json'];
                $q = $this->pdo->prepare('INSERT INTO advertising_visit_observations ('.implode(',', $columns).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
                $q->execute(array_map(static fn($column) => $values[$column], $columns));
                $inserted++;
            }
            if (!$replay) {
                $q = $this->pdo->prepare('UPDATE advertising_visit_cursors SET last_cursor=? WHERE '.$where);
                $q->execute([$page['next_cursor'], ...$params]);
                $cursor = $page['next_cursor'];
            }
            $this->pdo->commit();
            return ['inserted'=>$inserted,'replayed'=>$replayed,'cursor'=>$cursor];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Native signed launch through the same append-only observation owner and cursor CAS. */
    public function recordLaunch(array $scope,array $observation,int $now): array
    {
        $scope=AdvertisingVisitContract::scope($scope,true);
        if($scope['platform']!=='max'||($observation['identity_status']??null)!=='verified')throw new InvalidArgumentException('native_launch_identity_required');
        AdvertisingVisitContract::page(['schema_version'=>2]+$scope+['after_cursor'=>'0','next_cursor'=>'1','observations'=>[['source_cursor'=>'1']+$observation]],$scope,$now);
        $where='project_key=? AND source_key=? AND platform=? AND counter_id=?';$params=array_values($scope);
        for($attempt=0;$attempt<3;$attempt++){
            $old=$this->nativeExisting($scope,$observation);
            if($old!==null)return ['cursor'=>(string)$old['source_cursor'],'visit_id'=>$old['visit_id'],'reused'=>true];
            $checkpoint=$this->checkpoint($scope,true);$cursor=$checkpoint['cursor'];$next=$cursor;$carry=1;
            for($i=strlen($next)-1;$i>=0&&$carry;$i--){$digit=(int)$next[$i]+1;$next[$i]=(string)($digit%10);$carry=$digit>9?1:0;}
            if($carry)$next='1'.$next;if(strlen($next)>20)throw new RuntimeException('advertising_cursor_capacity');
            $row=['source_cursor'=>$next]+$observation;
            $page=AdvertisingVisitContract::page(['schema_version'=>2]+$scope+['after_cursor'=>$cursor,'next_cursor'=>$next,'observations'=>[$row]],$scope,$now);
            try{
                $saved=$this->importPage($page,$now,true);
                return ['cursor'=>$saved['cursor'],'visit_id'=>$observation['visit_id'],'reused'=>$saved['inserted']===0];
            }catch(RuntimeException $e){
                if(!in_array($e->getMessage(),['advertising_visit_cursor_conflict','advertising_visit_observation_conflict','advertising_visit_incomplete_replay'],true))throw $e;
                // importPage rolled back its entire local transaction. No external write is retried.
            }
        }
        throw new RuntimeException('advertising_capture_busy');
    }

    private function nativeExisting(array $scope,array $observation): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM advertising_visit_observations WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND visit_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([...array_values($scope),$observation['visit_id']]);$old=$q->fetch(PDO::FETCH_ASSOC);if(!$old)return null;
        foreach(['external_user_id','visit_at_unix','yclid','campaign_id','region_id','post_ref','offer_ref','context_json']as$key)
            if((string)$old[$key] !== (string)$observation[$key])throw new RuntimeException('advertising_launch_conflict');
        if($old['client_id']!==''&&$observation['client_id']!==''&&$old['client_id']!==$observation['client_id'])throw new RuntimeException('advertising_launch_conflict');
        return $old['client_id']!==''||$observation['client_id']===''?$old:null;
    }

    /** Candidate history only; callers must explicitly own attribution selection. */
    public function verifiedHistory(array $scope, string $externalUserId, int $limit = 20): array
    {
        $scope = AdvertisingVisitContract::scope($scope);
        if (!preg_match('/\A[1-9][0-9]{0,31}\z/D', $externalUserId) || $limit < 1 || $limit > 100) throw new InvalidArgumentException('advertising_visit_history_input');
        $q = $this->pdo->prepare('SELECT source_key,source_cursor,visit_id,visit_at_unix,observed_at_unix,client_id,yclid,campaign_id,region_id FROM advertising_visit_observations WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND external_user_id=? AND identity_status=\'verified\' AND client_id<>\'\' ORDER BY observed_at_unix DESC,id DESC LIMIT '.$limit);
        $q->execute([...array_values($scope), $externalUserId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Complete bounded candidates for an existing read-only report, not attribution.
     * Its query callback owns the shared transaction, authorization and row/time/byte
     * budget. Limit is a sentinel: reaching it returns no partial candidates.
     */
    public function entryContextCandidates(array $scope,array $externalUserIds,int $limit,callable $read):array
    {
        $scope=AdvertisingVisitContract::scope($scope);
        if(!$externalUserIds||count($externalUserIds)>100||$limit<1||$limit>50001)
            throw new InvalidArgumentException('advertising_visit_entry_context_input');
        foreach($externalUserIds as$id)if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,31}\z/D',$id)!==1)
            throw new InvalidArgumentException('advertising_visit_entry_context_identity');
        $where=[];$args=[];
        foreach($scope as$key=>$value){$where[]='v.'.$key.'=? AND HEX(v.'.$key.')=HEX(?)';array_push($args,$value,$value);}
        $marks=implode(',',array_fill(0,count($externalUserIds),'?'));
        // The persisted feed owner authenticates identity. Raw client IDs or click
        // hints in a profile, start payload or unverified capture are not substitutes.
        $where[]="v.external_user_id IN ($marks) AND v.identity_status='verified' AND HEX(v.identity_status)=HEX('verified')";
        array_push($args,...$externalUserIds);
        // Count a bounded identity-index prefix before the costlier conflict
        // projection. A history that cannot fit must not spend its SQL budget
        // evaluating one correlated visit-owner check for every rejected row.
        $count=$read('SELECT COUNT(*) AS candidate_count FROM (SELECT 1 FROM advertising_visit_observations v WHERE '
            .implode(' AND ',$where).' LIMIT '.$limit.') bounded_candidates',$args);
        if((int)$count[0]['candidate_count']>=$limit)return ['complete'=>false,'rows'=>[]];
        $same=[];foreach(array_keys($scope)as$key)$same[]='x.'.$key.'=v.'.$key.' AND HEX(x.'.$key.')=HEX(v.'.$key.')';
        $conflict='EXISTS(SELECT 1 FROM advertising_visit_observations x WHERE '.implode(' AND ',$same)
            ." AND x.visit_id=v.visit_id AND HEX(x.visit_id)=HEX(v.visit_id) AND x.identity_status='verified'"
            ." AND HEX(x.identity_status)=HEX('verified') AND HEX(x.external_user_id)<>HEX(v.external_user_id))";
        $rows=$read('SELECT v.external_user_id,v.client_id,v.visit_at_unix,v.observed_at_unix,v.received_at_unix,'
            .$conflict.' AS identity_conflict FROM advertising_visit_observations v WHERE '.implode(' AND ',$where)
            .' LIMIT '.$limit,$args);
        return ['complete'=>true,'rows'=>$rows];
    }
}
