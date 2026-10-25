<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitContract.php';

/** Immutable analytics beside canonical conversations; never owns or sends a goal. */
final class AdvertisingSignalRepository
{
    private PDO $pdo;
    private array $scope;
    public function __construct(PDO $pdo, array $scope)
    {
        $this->pdo = $pdo;
        $this->scope = AdvertisingVisitContract::scope($scope);
        if ($this->scope['platform'] !== 'telegram') throw new InvalidArgumentException('advertising_signal_platform');
    }

    public function incoming(string $chatId, string $nativeId, string $kind, int $now): int
    {
        if (!preg_match('/\A[1-9][0-9]{0,31}\z/D', $chatId) || $nativeId === '' || strlen($nativeId) > 128
            || !in_array($kind, ['message','callback','contact'], true)) return 0;
        $q = $this->pdo->prepare('SELECT c.id AS conversation_id,c.external_chat_id,c.status,cc.external_user_id,cc.external_chat_id AS user_chat_id,m.id AS source_id,m.created_at,'.$this->unix('m.created_at').' AS occurred_at_unix,'.$this->messageKind('m.metadata_json').' AS kind FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel JOIN messages m ON m.conversation_id=c.id AND m.channel=c.channel WHERE c.project_key=? AND c.channel=? AND c.is_test=0 AND c.status<>\'closed\' AND c.external_chat_id=? AND m.external_message_id=? AND m.direction=\'inbound\' AND m.sender_type=\'customer\' ORDER BY m.id DESC LIMIT 1');
        $q->execute([$this->scope['project_key'],$this->scope['platform'],$chatId,$nativeId]);
        $source = $q->fetch(PDO::FETCH_ASSOC);
        if (!$this->privateUser($source) || $source['kind'] !== $kind || !$this->sourceTime($source,$now)) return 0;
        $known = $this->knownSignals((string)$source['conversation_id']);
        $inserted = isset($known['customer_activity']) ? 0 : $this->insert($source,'customer_activity','message',$now);
        if ($kind === 'callback' || $source['status'] !== 'manager' || isset($known['customer_reply'])) return $inserted;
        $waiting = $this->handoff((string)$source['conversation_id'], (string)$source['created_at']);
        if (!$waiting) return $inserted;
        $q = $this->pdo->prepare('SELECT id FROM conversation_events WHERE conversation_id=? AND event_type=\'manager_message\' AND id>? AND created_at>=? AND created_at<=? ORDER BY id DESC LIMIT 1');
        $q->execute([$source['conversation_id'],$waiting['id'],$waiting['created_at'],$source['created_at']]);
        $managerEvent = $q->fetchColumn();
        if ($managerEvent === false) return $inserted;
        $q = $this->pdo->prepare('SELECT id FROM messages WHERE conversation_id=? AND channel=? AND direction=\'outbound\' AND sender_type=\'manager\' AND id<? AND created_at>=? AND created_at<=? ORDER BY id DESC LIMIT 1');
        $q->execute([$source['conversation_id'],$this->scope['platform'],$source['source_id'],$waiting['created_at'],$source['created_at']]);
        $managerMessage = $q->fetchColumn();
        if ($managerMessage === false) return $inserted;
        $source += ['handoff_event_id'=>(string)$waiting['id'],'manager_event_id'=>(string)$managerEvent,'manager_message_id'=>(string)$managerMessage];
        return $inserted + $this->insert($source,'customer_reply','message',$now);
    }

    public function request(int $conversationId, int $now): int
    {
        if ($conversationId <= 0) return 0;
        $q = $this->pdo->prepare('SELECT c.id AS conversation_id,c.external_chat_id,c.status,cc.external_user_id,cc.external_chat_id AS user_chat_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=? AND c.project_key=? AND c.channel=? AND c.is_test=0 AND c.status IN (\'waiting_manager\',\'manager\') LIMIT 1');
        $q->execute([$conversationId,$this->scope['project_key'],$this->scope['platform']]);
        $source = $q->fetch(PDO::FETCH_ASSOC);
        if (!$this->privateUser($source)) return 0;
        if (isset($this->knownSignals((string)$conversationId)['manager_request'])) return 0;
        $waiting = $this->handoff((string)$conversationId);
        if (!$waiting) return 0;
        $source += ['source_id'=>(string)$waiting['id'],'occurred_at_unix'=>$waiting['occurred_at_unix']];
        if (!$this->sourceTime($source,$now)) return 0;
        return $this->insert($source,'manager_request','conversation_event',$now);
    }

    /** Read-time assessment, not a frozen match or an export decision. */
    public function preview(string $afterId, int $limit, int $now): array
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$afterId) || $limit < 1 || $limit > 100) throw new InvalidArgumentException('advertising_signal_page');
        $q = $this->pdo->prepare('SELECT * FROM advertising_signals WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND id>? AND captured_at_unix<=? AND occurred_at_unix<=? ORDER BY id LIMIT '.$limit);
        $q->execute([...array_values($this->scope),$afterId,$now,$now]);
        $signals = $q->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($signals as $signal) {
            $q = $this->pdo->prepare('SELECT client_id FROM advertising_visit_observations WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND external_user_id=? AND identity_status=\'verified\' AND client_id<>\'\' AND visit_at_unix<=? AND observed_at_unix<=? AND received_at_unix<=? GROUP BY client_id LIMIT 2');
            $q->execute([...array_values($this->scope),$signal['external_user_id'],$signal['occurred_at_unix'],$now,$now]);
            $clients = $q->fetchAll(PDO::FETCH_COLUMN);
            $result[] = ['id'=>(string)$signal['id'],'signal_key'=>$signal['signal_key'],
                'occurred_at_unix'=>(int)$signal['occurred_at_unix'], 'captured_at_unix'=>(int)$signal['captured_at_unix'],
                'identity_status'=>count($clients)===0?'missing':(count($clients)===1?'unique':'ambiguous'),
                'client_id'=>count($clients)===1?(string)$clients[0]:''];
        }
        return $result;
    }

    public function counts(): array
    {
        $q = $this->pdo->prepare('SELECT signal_key,COUNT(*) AS total FROM advertising_signals WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? GROUP BY signal_key');
        $q->execute(array_values($this->scope));
        $counts = ['customer_activity'=>0,'manager_request'=>0,'customer_reply'=>0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) if (array_key_exists($row['signal_key'],$counts)) $counts[$row['signal_key']] = (int)$row['total'];
        return $counts;
    }

    private function insert(array $source, string $signal, string $sourceType, int $now): int
    {
        $values = $this->scope + ['conversation_id'=>(string)$source['conversation_id'],'external_user_id'=>(string)$source['external_user_id'],
            'signal_key'=>$signal,'source_type'=>$sourceType,'source_id'=>(string)$source['source_id'],
            'handoff_event_id'=>$source['handoff_event_id']??'','manager_event_id'=>$source['manager_event_id']??'','manager_message_id'=>$source['manager_message_id']??'',
            'occurred_at_unix'=>(int)$source['occurred_at_unix'],'captured_at_unix'=>$now];
        $columns = array_keys($values);
        $sql = 'INSERT INTO advertising_signals ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
        $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' ON DUPLICATE KEY UPDATE id=id' : ' ON CONFLICT(project_key,source_key,platform,counter_id,conversation_id,signal_key) DO NOTHING';
        $q = $this->pdo->prepare($sql);$q->execute(array_values($values));
        return $q->rowCount()===1 ? 1 : 0;
    }

    private function handoff(string $conversationId, ?string $before = null): ?array
    {
        $q = $this->pdo->prepare('SELECT id,created_at,'.$this->unix('created_at').' AS occurred_at_unix FROM conversation_events WHERE conversation_id=? AND event_type=\'waiting_manager\' AND actor_type=\'customer\''.($before!==null?' AND created_at<=?':'').' ORDER BY id DESC LIMIT 1');
        $q->execute($before!==null?[$conversationId,$before]:[$conversationId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    private function knownSignals(string $conversationId): array
    {
        $q=$this->pdo->prepare('SELECT signal_key FROM advertising_signals WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND conversation_id=?');
        $q->execute([...array_values($this->scope),$conversationId]);
        return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
    }
    private function privateUser($source): bool
    {
        return is_array($source) && is_string($source['external_user_id']) && preg_match('/\A[1-9][0-9]{0,31}\z/D',$source['external_user_id'])
            && $source['external_user_id']===(string)$source['external_chat_id'] && $source['external_user_id']===(string)$source['user_chat_id'];
    }
    private function sourceTime(array $source, int $now): bool { $time=(int)$source['occurred_at_unix'];return $time>=946684800 && $time<=$now; }
    private function unix(string $column): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'UNIX_TIMESTAMP('.$column.')':"CAST(strftime('%s',".$column.") AS INTEGER)"; }
    private function messageKind(string $column): string
    {
        $value=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(".$column.",'$.type'))":"json_extract(".$column.",'$.type')";
        return 'CASE WHEN JSON_VALID('.$column.') THEN '.$value." ELSE '' END";
    }
}
