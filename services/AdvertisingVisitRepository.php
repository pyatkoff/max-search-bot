<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitContract.php';

/** No runtime DDL, network, conversion exports or dialogue mutations. */
final class AdvertisingVisitRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Read-only resume token anchored to the last committed observation. */
    public function checkpoint(array $scope): array
    {
        $scope = AdvertisingVisitContract::scope($scope);
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
    public function importPage(array $page, int $receivedAt): array
    {
        if ($this->pdo->inTransaction()) throw new LogicException('advertising_visit_outer_transaction');
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

    /** Candidate history only; callers must explicitly own attribution selection. */
    public function verifiedHistory(array $scope, string $externalUserId, int $limit = 20): array
    {
        $scope = AdvertisingVisitContract::scope($scope);
        if (!preg_match('/\A[1-9][0-9]{0,31}\z/D', $externalUserId) || $limit < 1 || $limit > 100) throw new InvalidArgumentException('advertising_visit_history_input');
        $q = $this->pdo->prepare('SELECT source_key,source_cursor,visit_id,visit_at_unix,observed_at_unix,client_id,yclid,campaign_id,region_id FROM advertising_visit_observations WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND external_user_id=? AND identity_status=\'verified\' AND client_id<>\'\' ORDER BY observed_at_unix DESC,id DESC LIMIT '.$limit);
        $q->execute([...array_values($scope), $externalUserId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
