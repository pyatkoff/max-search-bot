<?php
declare(strict_types=1);

/** The existing publisher/Store own every claim; this finite executor adds no queue or credentials. */
final class AppleService39CoveragePublisher
{
    private string $parentDigest;
    public function __construct(
        private AppleService39PilotPublicationStore $store,
        private AppleService39ChannelTransport $transport,
        private AppleService39CoverageRevision $revision,
        private array $hooks
    ) {
        $this->parentDigest = $store->coverageParentDigest();
    }

    private function call(string $name, ...$args) { return ($this->hooks[$name])(...$args); }
    private static function check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    private function request(string $post, string $operation): array { return $this->store->coverageRequest($post, $operation); }

    private function receipt(array $row, string $photo, string $kind): array
    {
        $r = $row['request'];
        $base = ['kind'=>$kind, 'message_id'=>$row['external_message_id'], 'photo_id'=>$photo, 'source_sha256'=>$r['source_sha256']];
        return $row['operation'] === AppleService39CoverageRevision::MENU
            ? $base + ['request_sha256'=>$row['payload_sha256'], 'revision_sha256'=>$r['revision_sha256'],
                'parent_operation_id'=>$r['parent_operation_id'], 'parent_image_receipt_sha256'=>$r['parent_image_receipt_sha256']]
            : $base + ['upload_token_sha256'=>$r['upload_token_sha256'], 'request_sha256'=>$row['payload_sha256'],
                'revision_sha256'=>$r['revision_sha256']];
    }

    /** Exact GET and original POST/token witness, never photo ID alone. */
    private function native(array $row, array $parent): array
    {
        $r = $row['request']; $mid = $row['external_message_id'];
        self::check(is_string($mid) && preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $mid) === 1, 'coverage_operation_held');
        $wire = $this->transport->message($mid);
        self::check(AppleService39ChannelTransport::accepted($wire), 'provider_read_unknown');
        $message = $wire['data'];
        if (isset($message['message']) && is_array($message['message'])) $message = $message['message'];
        $body = $r['body'];
        if ($row['operation'] === AppleService39CoverageRevision::MENU) {
            $body = $parent['items']['menu']['plan']['body'];
            $body['text'] = $r['body']['text']; $body['format'] = 'html';
        }
        $plan = ['body'=>$body, 'native_text'=>$r['native_text'], 'native_markup'=>$r['native_markup']];
        $url = $this->call('verify', $message, $mid, $body, $plan);
        self::check(in_array($row['public_url'], [null,'',$url], true), 'post_url_changed');
        $photo = $this->call('photo', $message);
        self::check(is_string($photo) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $photo) === 1, 'coverage_image_changed');
        $saved = $row['outcome']['image_receipt'] ?? null;
        if ($row['operation'] === AppleService39CoverageRevision::MENU) {
            self::check($photo === $r['preserved_photo_id'] && $mid === $r['mid'] && $url === $r['url'], 'coverage_image_changed');
            $receipt = $this->receipt($row, $photo, 'preserved_image');
        } elseif ($saved !== null) {
            self::check(in_array($saved['kind'] ?? null, ['create_response','exact_upload_token'], true), 'invalid_coverage_image_receipt');
            $receipt = $this->receipt($row, $photo, $saved['kind']);
        } else {
            $token = $this->call('token', $message);
            self::check(is_string($token) && hash_equals($r['upload_token_sha256'], hash('sha256', $token)),
                'coverage_native_image_evidence_required');
            $receipt = $this->receipt($row, $photo, 'exact_upload_token');
        }
        if ($saved !== null) self::check($saved === $receipt, 'invalid_coverage_image_receipt');
        return [$url, $receipt];
    }

    private function snapshot(bool $reconcile = false): array
    {
        self::check($this->store->coverageParentDigest() === $this->parentDigest, 'coverage_parent_changed');
        $menu = $this->store->operation('menu', AppleService39CoverageRevision::MENU);
        $override = null;
        if ($menu !== null && $menu['state'] !== 'prepared') {
            self::check($menu['request'] === $this->request('menu', AppleService39CoverageRevision::MENU), 'coverage_revision_changed');
            $override = $menu['request'];
        }
        $parent = $this->call('parents', $override);
        self::check(count($parent['addresses']) === 30 && $parent['verified'] === 8, 'coverage_parent_incomplete');
        $addresses = $parent['addresses']; $mids = $parent['mids']; $verified = 0;
        foreach ($this->store->rows() as $row) {
            if (!AppleService39CoverageRevision::isOperation($row['post_key'], $row['operation'], $row['request'])) continue;
            self::check(in_array($row['state'], ['prepared','write_started','unknown','verified'], true)
                && (int)$row['write_attempts'] === ($row['state'] === 'prepared' ? 0 : 1)
                && $row['request'] === $this->request($row['post_key'], $row['operation'])
                && $row['payload_sha256'] === self::hash($row['request']), 'coverage_revision_changed');
        }
        foreach ($this->revision->order() as $id) {
            $row = $this->store->operation($id, 'create');
            if ($row === null || $row['state'] === 'prepared') continue;
            try { [$url, $receipt] = $this->native($row, $parent); }
            catch (RuntimeException $error) {
                if ($row['state'] === 'verified') throw $error;
                continue; // An uncertain original write remains held; no candidate search or resend.
            }
            self::check(!isset($mids[$row['external_message_id']]) && !in_array($url, $addresses, true), 'pilot_provider_identity_collision');
            if ($reconcile && in_array($row['state'], ['write_started','unknown'], true)) {
                $this->call('authority');
                $this->store->rememberMessage($row, $row['external_message_id'], $url);
                $this->store->rememberCoverageImageReceipt($row, $receipt);
                $this->store->finish($row, 'verified', ['readback'=>true, 'revision_sha256'=>$this->revision->sha256()]);
                $row = $this->store->operation($id, 'create');
            }
            if ($row['state'] === 'verified') {
                self::check($row['public_url'] === $url && ($row['outcome']['readback'] ?? null) === true
                    && ($row['outcome']['image_receipt'] ?? null) === $receipt, 'coverage_revision_changed');
                $addresses[$id] = $url; $mids[$row['external_message_id']] = true; $verified++;
            }
        }
        if ($menu !== null && $menu['state'] !== 'prepared') {
            [$url, $receipt] = $this->native($menu, $parent);
            if ($reconcile && in_array($menu['state'], ['write_started','unknown'], true)) {
                $this->call('authority');
                $this->store->rememberCoverageImageReceipt($menu, $receipt);
                $this->store->finish($menu, 'verified', ['readback'=>true, 'revision_sha256'=>$this->revision->sha256()]);
                $menu = $this->store->operation('menu', AppleService39CoverageRevision::MENU);
            }
            if ($menu['state'] === 'verified') self::check(($menu['outcome']['image_receipt'] ?? null) === $receipt
                && ($menu['outcome']['readback'] ?? null) === true && $menu['public_url'] === $url, 'coverage_revision_changed');
        }
        self::check($this->call('pin') === $this->hooks['expected_pin'], 'landing_menu_pin_changed');
        self::check($this->store->coverageParentDigest() === $this->parentDigest, 'coverage_parent_changed');
        $held = 0;
        foreach ($this->store->rows() as $row) if (in_array($row['state'], ['write_started','unknown','rejected'], true)) $held++;
        return ['parent'=>$parent, 'addresses'=>$addresses, 'verified'=>$verified, 'menu_verified'=>$menu !== null && $menu['state'] === 'verified', 'held'=>$held];
    }

    private function hold(array $row): void
    {
        try {
            $fresh = $this->store->operation($row['post_key'], $row['operation']);
            if ($fresh && in_array($fresh['state'], ['write_started','unknown'], true)) {
                $this->store->finish($row, 'unknown', ['reason'=>'coverage_outcome_unknown']);
            }
        } catch (Throwable $ignored) {}
    }

    private function execute(string $post, string $operation): void
    {
        $snapshot = $this->snapshot();
        self::check($snapshot['held'] === 0, 'coverage_operation_held');
        $request = $this->request($post, $operation);
        $row = $this->store->prepare($post, $operation, $request);
        if ($row['state'] === 'verified') return;
        self::check($row['state'] === 'prepared', 'coverage_operation_held');
        $this->call('pause');
        $this->call('before', $row, function () use ($post, $operation, $request): void {
            $fresh = $this->snapshot();
            self::check($fresh['held'] === 0 && $this->request($post, $operation) === $request, 'coverage_revision_changed');
        });
        try {
            if ($operation === AppleService39CoverageRevision::UPLOAD) {
                $upload = AppleService39ChannelTransport::uploadReceipt($this->transport->upload($this->revision->artwork()));
                self::check($upload !== null, 'invalid_coverage_upload');
                $this->store->finish($row, 'verified', $upload);
                return;
            }
            if ($operation === 'create') {
                $response = $this->transport->create($request['body']);
                $message = AppleService39ChannelTransport::accepted($response) ? ($response['data']['message'] ?? null) : null;
                self::check(is_array($message) && $this->call('identity', $message), 'coverage_operation_held');
                $mid = $message['body']['mid'] ?? null;
                self::check(is_string($mid) && !isset($snapshot['parent']['mids'][$mid])
                    && !in_array($mid, array_column($this->store->rows(), 'external_message_id'), true), 'pilot_provider_identity_collision');
                $this->store->rememberMessage($row, $mid, '');
                $this->call('verify', $message, $mid, $request['body'], $request);
                $photo = $this->call('photo', $message);
                self::check(is_string($photo) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $photo) === 1, 'coverage_image_changed');
                $row = $this->store->operation($post, $operation);
                $this->store->rememberCoverageImageReceipt($row, $this->receipt($row, $photo, 'create_response'));
            } else {
                $mid = $request['mid'];
                $this->store->rememberMessage($row, $mid, $request['url']);
                $response = $this->transport->edit($mid, $request['body']);
                self::check(AppleService39ChannelTransport::accepted($response) && ($response['data']['success'] ?? null) === true,
                    'coverage_operation_held');
            }
            $row = $this->store->operation($post, $operation);
            [$url, $receipt] = $this->native($row, $snapshot['parent']);
            $this->call('authority');
            self::check($this->call('pin') === $this->hooks['expected_pin'], 'landing_menu_pin_changed');
            $this->store->rememberMessage($row, $mid, $url);
            $this->store->rememberCoverageImageReceipt($row, $receipt);
            $this->store->finish($row, 'verified', ['readback'=>true, 'revision_sha256'=>$this->revision->sha256()]);
        } catch (Throwable $error) {
            $this->hold($row);
            throw new RuntimeException('coverage_operation_held');
        }
    }

    public function run(string $mode): array
    {
        self::check(in_array($mode, ['coverage_inspect','coverage_prepare','coverage_apply','coverage_reconcile'], true), 'invalid_coverage_operation');
        $snapshot = $this->snapshot($mode === 'coverage_reconcile');
        if ($mode === 'coverage_prepare') $this->execute(AppleService39CoverageRevision::COVER, AppleService39CoverageRevision::UPLOAD);
        if ($mode === 'coverage_apply') {
            foreach ($this->revision->order() as $id) $this->execute($id, 'create');
            $this->execute('menu', AppleService39CoverageRevision::MENU);
        }
        $snapshot = $this->snapshot();
        $rows = array_map(static fn(array $row): array => ['post_id'=>$row['post_key'], 'operation'=>$row['operation'],
            'state'=>$row['state'], 'write_attempts'=>(int)$row['write_attempts'],
            'message_id'=>$row['external_message_id'], 'url'=>$row['public_url'] ?: null], $this->store->rows());
        return ['ok'=>$snapshot['held'] === 0, 'state'=>$snapshot['held'] === 0 ? $mode.'_complete' : 'held',
            'revision'=>AppleService39CoverageRevision::KEY, 'revision_sha256'=>$this->revision->sha256(),
            'post_count'=>count($snapshot['addresses']), 'coverage_verified_posts'=>$snapshot['verified'],
            'coverage_expected_new_posts'=>39, 'expected_total_posts'=>69, 'expected_feed_offers'=>67,
            'menu_verified'=>$snapshot['menu_verified'], 'menu_pin_preserved'=>true, 'parent_operations_preserved'=>152,
            'held_operations'=>$snapshot['held'], 'operations'=>$rows,
            'coverage_publication_complete'=>$snapshot['held'] === 0 && $snapshot['verified'] === 39
                && $snapshot['menu_verified'] && count($snapshot['addresses']) === 69];
    }
}
