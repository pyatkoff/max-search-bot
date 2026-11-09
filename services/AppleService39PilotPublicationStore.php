<?php
declare(strict_types=1);

/** Durable one-attempt state; no provider call and no runtime schema creation. */
final class AppleService39PilotPublicationStore
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function campaign(): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM appleservice39_pilot_campaigns WHERE campaign_key=?');
        $query->execute(['appleservice39-pilot-v1']);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function bind(array $binding): void
    {
        $fields = ['campaign_key','project_key','bot_key','channel_id','source_id','admin_manager_id','manifest_sha256','connection_sha256'];
        $current = $this->campaign();
        if ($current === null) {
            try {
                $query = $this->pdo->prepare('INSERT INTO appleservice39_pilot_campaigns ('.implode(',', $fields).',created_at) VALUES (?,?,?,?,?,?,?,?,?)');
                $values = array_map(static fn(string $field) => $binding[$field], $fields); $values[] = gmdate('Y-m-d H:i:s');
                $query->execute($values);
            } catch (PDOException $error) {
                if (!in_array((string)$error->getCode(), ['23000','23505'], true)) throw $error;
            }
            $current = $this->campaign();
        }
        if (!is_array($current)) throw new RuntimeException('campaign_binding_unavailable');
        foreach ($fields as $field) if ((string)$current[$field] !== (string)$binding[$field]) throw new RuntimeException('campaign_binding_changed');
    }

    public function assertBinding(array $binding): void
    {
        $current = $this->campaign();
        if ($current === null) return;
        foreach ($binding as $field=>$value) if (!array_key_exists($field, $current) || (string)$current[$field] !== (string)$value) throw new RuntimeException('campaign_binding_changed');
    }

    public function operation(string $post, string $operation): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM appleservice39_pilot_operations WHERE campaign_key=? AND post_key=? AND operation=?');
        $query->execute(['appleservice39-pilot-v1',$post,$operation]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    public function prepare(string $post, string $operation, array $request): array
    {
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,55}\z/D', $post) || !in_array($operation, ['upload','create','links','pin','landing_edit'], true)) throw new RuntimeException('invalid_pilot_operation');
        $json = self::json($request); $hash = hash('sha256', $json);
        $row = $this->operation($post, $operation);
        if ($row === null) {
            try {
                $now = gmdate('Y-m-d H:i:s');
                $query = $this->pdo->prepare("INSERT INTO appleservice39_pilot_operations (campaign_key,post_key,operation,payload_sha256,request_json,state,write_attempts,outcome_json,created_at,updated_at) VALUES (?,?,?,?,?,'prepared',0,'{}',?,?)");
                $query->execute(['appleservice39-pilot-v1',$post,$operation,$hash,$json,$now,$now]);
            } catch (PDOException $error) {
                if (!in_array((string)$error->getCode(), ['23000','23505'], true)) throw $error;
            }
            $row = $this->operation($post, $operation);
        }
        if (!$row || !hash_equals((string)$row['payload_sha256'], $hash) || $row['request_json'] !== $json) throw new RuntimeException('pilot_operation_changed');
        return $row;
    }

    /** No lease expiry or reset exists. A crash after this CAS keeps the hold. */
    public function claim(array $row): bool
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('pilot_requires_autocommit');
        $query = $this->pdo->prepare("UPDATE appleservice39_pilot_operations SET state='write_started',write_attempts=1,started_ms=?,updated_at=? WHERE id=? AND payload_sha256=? AND state='prepared' AND write_attempts=0");
        $query->execute([(int)floor(microtime(true)*1000),gmdate('Y-m-d H:i:s'),(int)$row['id'],$row['payload_sha256']]);
        return $query->rowCount() === 1;
    }

    /** Save a returned real MID before the independent readback, never fabricate one. */
    public function rememberMessage(array $row, string $mid, string $url): void
    {
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $mid)) throw new RuntimeException('invalid_provider_mid');
        $query = $this->pdo->prepare("UPDATE appleservice39_pilot_operations SET external_message_id=?,public_url=?,updated_at=? WHERE id=? AND payload_sha256=? AND state IN ('write_started','unknown') AND write_attempts=1 AND (external_message_id IS NULL OR external_message_id=?)");
        $query->execute([$mid,$url,gmdate('Y-m-d H:i:s'),(int)$row['id'],$row['payload_sha256'],$mid]);
        $saved = $this->operation((string)$row['post_key'], (string)$row['operation']);
        if (!$saved || $saved['external_message_id'] !== $mid || $saved['public_url'] !== $url) throw new RuntimeException('provider_receipt_storage_unknown');
    }

    /** A canonical image binding never resets the original create claim. */
    public function rememberImageReceipt(array $row, array $receipt): void
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('pilot_requires_autocommit');
        $keys = ['kind','message_id','photo_id','source_sha256','upload_token_sha256','request_sha256','evidence_sha256'];
        $current = $this->operation((string)$row['post_key'], 'create');
        if (($row['operation'] ?? '') !== 'create' || !$current || (int)$current['id'] !== (int)$row['id']
            || $current['payload_sha256'] !== $row['payload_sha256']
            || !in_array($current['state'], ['write_started','unknown'], true) || (int)$current['write_attempts'] !== 1
            || array_keys($receipt) !== $keys
            || !in_array($receipt['kind'], ['create_response','exact_upload_token','reviewed_legacy_native_image'], true)
            || $receipt['message_id'] !== $current['external_message_id']
            || !is_string($receipt['photo_id']) || preg_match('/\\A(?:0|-?[1-9][0-9]{0,18})\\z/D', $receipt['photo_id']) !== 1
            || $receipt['request_sha256'] !== $current['payload_sha256']) throw new RuntimeException('invalid_image_receipt');
        foreach (['source_sha256','upload_token_sha256','request_sha256'] as $key) {
            if (!is_string($receipt[$key]) || preg_match('/\\A[a-f0-9]{64}\\z/D', $receipt[$key]) !== 1) throw new RuntimeException('invalid_image_receipt');
        }
        if (!is_string($receipt['evidence_sha256'])
            || ($receipt['kind'] === 'reviewed_legacy_native_image'
                ? preg_match('/\\A[a-f0-9]{64}\\z/D', $receipt['evidence_sha256']) !== 1 : $receipt['evidence_sha256'] !== '')) {
            throw new RuntimeException('invalid_image_receipt');
        }
        $existing = $current['outcome']['image_receipt'] ?? null;
        if ($existing !== null) {
            if ($existing !== $receipt) throw new RuntimeException('image_receipt_changed');
            return;
        }
        $outcome = $current['outcome']; $outcome['image_receipt'] = $receipt;
        $query = $this->pdo->prepare("UPDATE appleservice39_pilot_operations SET outcome_json=?,updated_at=? WHERE id=? AND payload_sha256=? AND state IN ('write_started','unknown') AND write_attempts=1 AND external_message_id=? AND outcome_json=?");
        $query->execute([self::json($outcome),gmdate('Y-m-d H:i:s'),(int)$row['id'],$row['payload_sha256'],$receipt['message_id'],$current['outcome_json']]);
        $saved = $this->operation((string)$row['post_key'], 'create');
        if (!$saved || ($saved['outcome']['image_receipt'] ?? null) !== $receipt) throw new RuntimeException('image_receipt_storage_unknown');
    }

    public function finish(array $row, string $state, array $outcome = []): void
    {
        if (!in_array($state, ['verified','unknown','rejected'], true)) throw new RuntimeException('invalid_pilot_outcome');
        $current = $this->operation((string)$row['post_key'], (string)$row['operation']);
        if (!$current || (int)$current['id'] !== (int)$row['id'] || $current['payload_sha256'] !== $row['payload_sha256']) throw new RuntimeException('pilot_outcome_storage_unknown');
        $receipt = $current['outcome']['image_receipt'] ?? null;
        if (array_key_exists('image_receipt', $outcome) && $outcome['image_receipt'] !== $receipt) throw new RuntimeException('image_receipt_changed');
        if ($receipt !== null) $outcome['image_receipt'] = $receipt;
        $json = self::json($outcome);
        $query = $this->pdo->prepare("UPDATE appleservice39_pilot_operations SET state=?,outcome_json=?,updated_at=? WHERE id=? AND payload_sha256=? AND state IN ('write_started','unknown') AND write_attempts=1 AND outcome_json=?");
        $query->execute([$state,$json,gmdate('Y-m-d H:i:s'),(int)$row['id'],$row['payload_sha256'],$current['outcome_json']]);
        $saved = $this->operation((string)$row['post_key'], (string)$row['operation']);
        if (!$saved || $saved['state'] !== $state || $saved['outcome_json'] !== $json) throw new RuntimeException('pilot_outcome_storage_unknown');
    }

    /** A native GET may establish that our exact verified menu is already pinned. */
    public function confirmExistingPin(array $row): void
    {
        if (($row['operation'] ?? '') !== 'pin') throw new RuntimeException('pin_observation_only');
        $query = $this->pdo->prepare("UPDATE appleservice39_pilot_operations SET state='verified',outcome_json=?,updated_at=? WHERE id=? AND payload_sha256=? AND operation='pin' AND state='prepared' AND write_attempts=0");
        $query->execute([self::json(['readback'=>true,'already_present'=>true]),gmdate('Y-m-d H:i:s'),(int)$row['id'],$row['payload_sha256']]);
        $saved = $this->operation((string)$row['post_key'], 'pin');
        if (!$saved || $saved['state'] !== 'verified') throw new RuntimeException('pilot_pin_observation_unknown');
    }

    public function rows(): array
    {
        $query = $this->pdo->prepare('SELECT * FROM appleservice39_pilot_operations WHERE campaign_key=? ORDER BY id');
        $query->execute(['appleservice39-pilot-v1']);
        return array_map([$this,'decode'], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    private function decode(array $row): array
    {
        $row['request'] = json_decode((string)$row['request_json'], true, 32, JSON_THROW_ON_ERROR);
        $row['outcome'] = json_decode((string)$row['outcome_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($row['request']) || !is_array($row['outcome'])) throw new RuntimeException('pilot_state_invalid');
        return $row;
    }
    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) throw new RuntimeException('pilot_state_too_large');
        return $json;
    }
}
