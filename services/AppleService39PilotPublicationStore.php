<?php
declare(strict_types=1);

/** Durable one-attempt state; no provider call and no runtime schema creation. */
final class AppleService39PilotPublicationStore
{
    private PDO $pdo;
    private ?AppleService39SearchIntentRevision $searchRevision = null;
    private ?string $searchBaseSha256 = null;
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

    /** Read-only public permalink; no private request/outcome payload is selected. */
    public function verifiedPostUrl(string $post, int $sourceId): ?string
    {
        if ($sourceId <= 0 || preg_match('/\A[a-z][a-z0-9_-]{0,55}\z/D', $post) !== 1) return null;
        $query = $this->pdo->prepare("SELECT o.external_message_id,o.public_url FROM appleservice39_pilot_operations o
            JOIN appleservice39_pilot_campaigns c ON c.campaign_key=o.campaign_key
            WHERE c.campaign_key=? AND c.project_key=? AND c.bot_key=? AND c.channel_id=? AND c.source_id=?
            AND o.post_key=? AND o.operation='create' AND o.state='verified' AND o.write_attempts=1 LIMIT 1");
        $query->execute(['appleservice39-pilot-v1','appleservice39','id390521129062_bot','-80917954947727',$sourceId,$post]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['external_message_id'])
            || preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $row['external_message_id']) !== 1
            || !is_string($row['public_url'])
            || preg_match('~\Ahttps://max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]{1,191}\z~D', $row['public_url']) !== 1) return null;
        return $row['public_url'];
    }

    public function prepare(string $post, string $operation, array $request): array
    {
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,55}\z/D', $post) || !in_array($operation, ['upload','create','links','pin','landing_upload','landing_edit','catalogue_v1','search_v1_upload','search_v1_edit'], true)) throw new RuntimeException('invalid_pilot_operation');
        if (self::isSearchOperation($post, $operation, $request)) $this->validateSearchRequest($post, $operation, $request);
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
        if (self::isSearchOperation($row['post_key'], $row['operation'], $row['request'])) {
            $this->validateSearchRequest($row['post_key'], $row['operation'], $row['request']);
            $current = $this->operation($row['post_key'], $row['operation']);
            if (!$current || (int)$current['id'] !== (int)$row['id'] || $current['request'] !== $row['request']
                || $current['payload_sha256'] !== $row['payload_sha256']) throw new RuntimeException('pilot_operation_changed');
        }
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
        if ($current && self::isSearchOperation($current['post_key'], $current['operation'], $current['request'])) throw new RuntimeException('invalid_image_receipt');
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

    /** A source-bound token witness or admitted native-file review binds a replacement image. */
    public function rememberLandingImageReceipt(array $row, array $receipt): void
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('pilot_requires_autocommit');
        $current = $this->operation((string)$row['post_key'], 'landing_edit');
        $keys = ['kind','message_id','photo_id','source_sha256','upload_token_sha256','request_sha256','revision_sha256'];
        if (($receipt['kind'] ?? null) === 'reviewed_native_replacement') {
            $keys[] = 'evidence_sha256'; $keys[] = 'native_preview_sha256';
        }
        if (($row['operation'] ?? '') !== 'landing_edit' || !$current || (int)$current['id'] !== (int)$row['id']
            || $current['payload_sha256'] !== $row['payload_sha256']
            || !in_array($current['state'], ['write_started','unknown'], true) || (int)$current['write_attempts'] !== 1
            || array_keys($receipt) !== $keys
            || !in_array($receipt['kind'], ['exact_upload_token','reviewed_native_replacement'], true)
            || $receipt['message_id'] !== $current['external_message_id']
            || $receipt['message_id'] !== ($current['request']['mid'] ?? null)
            || !is_string($receipt['photo_id']) || preg_match('/\A(?:0|-?[1-9][0-9]{0,18})\z/D', $receipt['photo_id']) !== 1
            || $receipt['source_sha256'] !== ($current['request']['source_sha256'] ?? null)
            || $receipt['upload_token_sha256'] !== ($current['request']['upload_token_sha256'] ?? null)
            || $receipt['revision_sha256'] !== ($current['request']['revision_sha256'] ?? null)
            || $receipt['request_sha256'] !== $current['payload_sha256']) throw new RuntimeException('invalid_landing_image_receipt');
        foreach (['source_sha256','upload_token_sha256','request_sha256','revision_sha256'] as $key) {
            if (!is_string($receipt[$key]) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt[$key]) !== 1) throw new RuntimeException('invalid_landing_image_receipt');
        }
        if ($receipt['kind'] === 'reviewed_native_replacement') foreach (['evidence_sha256','native_preview_sha256'] as $key) {
            if (!is_string($receipt[$key]) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt[$key]) !== 1) throw new RuntimeException('invalid_landing_image_receipt');
        }
        $old = $current['outcome']['image_receipt'] ?? null;
        if ($old !== null) {
            if ($old !== $receipt) throw new RuntimeException('image_receipt_changed');
            return;
        }
        $outcome = $current['outcome']; $outcome['image_receipt'] = $receipt;
        $query = $this->pdo->prepare("UPDATE appleservice39_pilot_operations SET outcome_json=?,updated_at=? WHERE id=? AND payload_sha256=? AND state IN ('write_started','unknown') AND write_attempts=1 AND external_message_id=? AND outcome_json=?");
        $query->execute([self::json($outcome),gmdate('Y-m-d H:i:s'),(int)$row['id'],$row['payload_sha256'],$receipt['message_id'],$current['outcome_json']]);
        $saved = $this->operation((string)$row['post_key'], 'landing_edit');
        if (!$saved || ($saved['outcome']['image_receipt'] ?? null) !== $receipt) throw new RuntimeException('image_receipt_storage_unknown');
    }

    public function finish(array $row, string $state, array $outcome = []): void
    {
        if (!in_array($state, ['verified','unknown','rejected'], true)) throw new RuntimeException('invalid_pilot_outcome');
        $current = $this->operation((string)$row['post_key'], (string)$row['operation']);
        if (!$current || (int)$current['id'] !== (int)$row['id'] || $current['payload_sha256'] !== $row['payload_sha256']) throw new RuntimeException('pilot_outcome_storage_unknown');
        $receipt = $current['outcome']['image_receipt'] ?? null;
        if ($state === 'verified' && self::isSearchOperation($current['post_key'], $current['operation'], $current['request'])) {
            $this->validateSearchRequest($current['post_key'], $current['operation'], $current['request']);
            if ($current['operation'] === 'search_v1_upload') {
                if (array_keys($outcome) !== ['photo_id','token'] || !is_string($outcome['photo_id'])
                    || preg_match('/\A[0-9]{1,19}\z/D', $outcome['photo_id']) !== 1
                    || !is_string($outcome['token']) || $outcome['token'] === '' || strlen($outcome['token']) > 4096
                    || preg_match('/[\x00-\x20\x7f]/', $outcome['token'])) throw new RuntimeException('invalid_search_upload_receipt');
                foreach ($this->rows() as $other) if ((int)$other['id'] !== (int)$current['id']
                    && in_array($other['operation'], ['upload','landing_upload','search_v1_upload'], true)
                    && ($other['outcome']['token'] ?? null) === $outcome['token']) throw new RuntimeException('search_upload_token_collision');
            } elseif (!is_array($receipt) || ($outcome['readback'] ?? null) !== true
                || $receipt['message_id'] !== $current['external_message_id']
                || !is_string($current['public_url'])
                || preg_match('~\Ahttps://max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]{1,191}\z~D', $current['public_url']) !== 1) {
                throw new RuntimeException('search_image_receipt_required');
            }
        }
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

    /** Six new creates retain the existing create/permalink convention. */
    public static function isSearchOperation(string $post, string $operation, array $request): bool
    {
        return str_starts_with($operation, 'search_v1_') || ($request['revision'] ?? null) === 'search-intent-v1'
            || in_array($post, ['repair-iphone-16-display','service-rear-glass-replacement','symptom-not-charging',
                'service-rear-camera-replacement','service-earpiece-replacement','model-iphone-17'], true);
    }

    /** Finite source binding, not a generic successor/override input. */
    private function validateSearchRequest(string $post, string $operation, array $request): void
    {
        require_once __DIR__.'/AppleService39PilotManifest.php';
        if ($this->searchRevision === null) {
            $manifest = AppleService39PilotManifest::installed();
            $this->searchBaseSha256 = $manifest->sha256();
            $this->searchRevision = new AppleService39SearchIntentRevision($manifest);
        }
        $revision = $this->searchRevision;
        $posts = array_column($revision->posts(), null, 'id');
        $binding = $revision->historicalBinding($post);
        $keys = ['revision','revision_sha256','post_id','source_sha256','mid','url'];
        if ($operation !== 'search_v1_upload') $keys = array_merge($keys,
            ['upload_operation_id','upload_request_sha256','upload_token_sha256','parent_request_sha256','body','native_text','native_markup']);
        if (!isset($posts[$post]) || array_keys($request) !== $keys
            || !in_array($operation, ['search_v1_upload', $binding === null ? 'create' : 'search_v1_edit'], true)
            || $request['revision'] !== AppleService39SearchIntentRevision::KEY
            || $request['revision_sha256'] !== $revision->sha256() || $request['post_id'] !== $post
            || $request['source_sha256'] !== $posts[$post]['image']['sha256']
            || $request['mid'] !== ($binding['message_id'] ?? null) || $request['url'] !== ($binding['url'] ?? null)) {
            throw new RuntimeException('invalid_search_operation');
        }
        $revision->validateArtwork($post);
        $campaign = $this->campaign();
        if (!$campaign || $campaign['project_key'] !== AppleService39PilotManifest::PROJECT || $campaign['bot_key'] !== AppleService39PilotManifest::BOT
            || $campaign['channel_id'] !== '-80917954947727'
            || $campaign['manifest_sha256'] !== $this->searchBaseSha256) {
            throw new RuntimeException('search_parent_incomplete');
        }
        if ($operation === 'search_v1_upload') return;
        $upload = $this->operation($post, 'search_v1_upload');
        if (!$upload || $upload['state'] !== 'verified' || (int)$upload['write_attempts'] !== 1
            || $request['upload_operation_id'] !== (int)$upload['id']
            || $request['upload_request_sha256'] !== $upload['payload_sha256']
            || $request['upload_token_sha256'] !== hash('sha256', $upload['outcome']['token'] ?? '')
            || $upload['request'] !== array_intersect_key($request, array_flip(array_slice($keys, 0, 6)))
            || !is_array($request['body']) || !is_string($request['native_text']) || !is_array($request['native_markup'])) {
            throw new RuntimeException('invalid_search_operation');
        }
        $parent = $binding === null ? null : $this->operation($post, 'catalogue_v1');
        if ($request['parent_request_sha256'] !== ($parent['payload_sha256'] ?? null)
            || ($binding !== null && (!$parent || $parent['state'] !== 'verified' || (int)$parent['write_attempts'] !== 1))) {
            throw new RuntimeException('search_parent_incomplete');
        }
    }

    /** Only the claimed response or an exact own upload-token witness can bind a new image. */
    public function rememberSearchImageReceipt(array $row, array $receipt): void
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('pilot_requires_autocommit');
        $current = $this->operation($row['post_key'], $row['operation']);
        $keys = ['kind','message_id','photo_id','source_sha256','upload_token_sha256','request_sha256','revision_sha256'];
        if (($receipt['kind'] ?? null) === 'reviewed_native_replacement') $keys = array_merge($keys,['evidence_sha256','native_preview_sha256']);
        if (!$current || !in_array($current['operation'], ['create','search_v1_edit'], true)
            || !self::isSearchOperation($current['post_key'], $current['operation'], $current['request'])
            || (int)$current['id'] !== (int)$row['id'] || $current['payload_sha256'] !== $row['payload_sha256']
            || !in_array($current['state'], ['write_started','unknown'], true) || (int)$current['write_attempts'] !== 1
            || array_keys($receipt) !== $keys || !in_array($receipt['kind'] ?? null,
                $current['operation'] === 'create' ? ['create_response','exact_upload_token'] : ['exact_upload_token','reviewed_native_replacement'], true)
            || $receipt['message_id'] !== $current['external_message_id']
            || !is_string($receipt['photo_id']) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $receipt['photo_id']) !== 1
            || $receipt['source_sha256'] !== $current['request']['source_sha256']
            || $receipt['upload_token_sha256'] !== $current['request']['upload_token_sha256']
            || $receipt['request_sha256'] !== $current['payload_sha256']
            || $receipt['revision_sha256'] !== $current['request']['revision_sha256']) throw new RuntimeException('invalid_search_image_receipt');
        $this->validateSearchRequest($current['post_key'], $current['operation'], $current['request']);
        if ($receipt['kind'] === 'reviewed_native_replacement') {
            $review = $this->searchRevision->reviewedNativeImage($current['post_key']);
            if (!$review || $receipt['evidence_sha256'] !== $review['evidence_sha256']
                || $receipt['native_preview_sha256'] !== $review['native_preview_sha256']) throw new RuntimeException('invalid_search_image_receipt');
            $expected = ['campaign_key'=>'appleservice39-pilot-v1','project_key'=>'appleservice39',
                'bot_key'=>'id390521129062_bot','channel_id'=>'-80917954947727','post_id'=>$current['post_key'],
                'message_id'=>$current['external_message_id'],'public_url'=>$current['public_url'],
                'revision_sha256'=>$receipt['revision_sha256'],'source_sha256'=>$receipt['source_sha256'],
                'upload_operation_id'=>$current['request']['upload_operation_id'],
                'upload_request_sha256'=>$current['request']['upload_request_sha256'],
                'upload_token_sha256'=>$receipt['upload_token_sha256'],'edit_operation_id'=>(int)$current['id'],
                'edit_request_sha256'=>$current['payload_sha256'],'edit_started_ms'=>(int)$current['started_ms'],
                'native_photo_id'=>$receipt['photo_id']];
            foreach ($expected as $key=>$value) if (($review['proof'][$key] ?? null) !== $value) throw new RuntimeException('invalid_search_image_receipt');
        }
        $old = $current['outcome']['image_receipt'] ?? null;
        if ($old !== null) {
            if ($old !== $receipt) throw new RuntimeException('image_receipt_changed');
            return;
        }
        $outcome = $current['outcome']; $outcome['image_receipt'] = $receipt;
        $query = $this->pdo->prepare("UPDATE appleservice39_pilot_operations SET outcome_json=?,updated_at=? WHERE id=? AND payload_sha256=? AND state IN ('write_started','unknown') AND write_attempts=1 AND external_message_id=? AND outcome_json=?");
        $query->execute([self::json($outcome),gmdate('Y-m-d H:i:s'),(int)$row['id'],$row['payload_sha256'],$receipt['message_id'],$current['outcome_json']]);
        $saved = $this->operation($row['post_key'], $row['operation']);
        if (!$saved || ($saved['outcome']['image_receipt'] ?? null) !== $receipt) throw new RuntimeException('image_receipt_storage_unknown');
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
