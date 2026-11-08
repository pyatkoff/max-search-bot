<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectMaxConnection.php';
require_once __DIR__.'/AppleService39PilotManifest.php';
require_once __DIR__.'/AppleService39PilotPublicationStore.php';
require_once __DIR__.'/../integrations/AppleService39ChannelTransport.php';

/** Fixed 24-post pilot. This owner does not invoke generic p_ button automation. */
final class AppleService39PilotPublisher
{
    private PDO $pdo;
    private AppleService39PilotManifest $manifest;
    private AppleService39PilotPublicationStore $store;
    private ProjectMaxConnection $connection;
    private AppleService39ChannelTransport $transport;
    private string $channelId;
    private string $botUserId = '';
    private $provenance;
    private $pause;
    private array $readbackDiagnostics = [];

    public function __construct(PDO $pdo, AppleService39PilotManifest $manifest, string $channelId,
        callable $provenance, ?callable $wire = null, ?callable $pause = null)
    {
        $connection = ProjectMaxConnection::resolve(AppleService39PilotManifest::PROJECT);
        self::check($connection !== null, 'pilot_connection_unavailable');
        $this->pdo = $pdo; $this->manifest = $manifest; $this->channelId = $channelId;
        $this->connection = $connection; $this->store = new AppleService39PilotPublicationStore($pdo);
        $this->transport = new AppleService39ChannelTransport($connection, $channelId, $wire);
        $this->provenance = $provenance;
        $this->pause = $pause ?? static function (): void { usleep(600000); };
    }

    public function run(string $operation): array
    {
        self::check(in_array($operation, ['inspect','prepare','publish','finalize','pin','reconcile'], true), 'unsupported_pilot_operation');
        $this->readbackDiagnostics = [];
        $binding = $this->preflight();
        $this->store->assertBinding($binding);
        if ($operation === 'inspect') return $this->report('inspected');
        if ($operation === 'reconcile') {
            if ($this->store->campaign() !== null) $this->reconcile();
            return $this->report('reconciled');
        }
        $this->store->bind($binding);
        if ($operation === 'prepare') $this->prepareImages();
        elseif ($operation === 'publish') $this->publish();
        elseif ($operation === 'finalize') $this->finalize();
        else $this->pinMenu();
        return $this->report($operation.'_complete');
    }

    /** Fresh actor, project/source, protected connection and real provider identity. */
    private function preflight(): array
    {
        self::check(($this->provenance)() === true, 'installed_checkout_changed');
        $current = ProjectMaxConnection::resolve(AppleService39PilotManifest::PROJECT);
        self::check($current !== null && self::connectionHash($current) === self::connectionHash($this->connection), 'pilot_connection_changed');
        self::check($current->projectKey() === AppleService39PilotManifest::PROJECT && $current->botKey() === AppleService39PilotManifest::BOT
            && $current->settings()->get('routing.source_key') === AppleService39PilotManifest::SOURCE, 'pilot_connection_binding_mismatch');
        $source = $this->source();
        $me = self::read($this->transport->me());
        self::check(($me['is_bot'] ?? null) === true && ($me['username'] ?? null) === AppleService39PilotManifest::BOT
            && self::positiveId($me['user_id'] ?? null) !== '', 'canonical_bot_identity_mismatch');
        $botId = self::positiveId($me['user_id']);
        self::check($this->botUserId === '' || $this->botUserId === $botId, 'provider_bot_identity_changed');
        $this->botUserId = $botId;
        $chat = self::read($this->transport->channel());
        self::check((string)($chat['chat_id'] ?? '') === $this->channelId && ($chat['type'] ?? null) === 'channel'
            && ($chat['status'] ?? null) === 'active' && ($chat['is_public'] ?? null) === true
            && is_string($chat['link'] ?? null) && rtrim($chat['link'], '/') === AppleService39PilotManifest::CHANNEL, 'public_channel_identity_mismatch');
        $member = self::read($this->transport->membership());
        self::check(($member['is_bot'] ?? null) === true && ($member['username'] ?? null) === AppleService39PilotManifest::BOT
            && (string)($member['user_id'] ?? '') === $botId && ($member['is_admin'] ?? null) === true, 'channel_admin_required');
        $permissions = $member['permissions'] ?? null;
        self::check(is_array($permissions) && in_array('read_all_messages', $permissions, true)
            && count(array_intersect(['write','post_edit_delete_message'], $permissions)) > 0
            && count(array_intersect(['edit','edit_message'], $permissions)) > 0, 'channel_publish_edit_read_permissions_required');
        // Provider reads do not extend an expired operator grant.
        self::check($this->source() === $source && ($this->provenance)() === true, 'publication_authority_changed');
        return ['campaign_key'=>AppleService39PilotManifest::CAMPAIGN,'project_key'=>AppleService39PilotManifest::PROJECT,
            'bot_key'=>AppleService39PilotManifest::BOT,'channel_id'=>$this->channelId,'source_id'=>$source,
            'admin_manager_id'=>2,'manifest_sha256'=>$this->manifest->sha256(),'connection_sha256'=>self::connectionHash($current)];
    }

    private function source(): int
    {
        $query = $this->pdo->prepare("SELECT s.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id JOIN conversation_sources s ON s.project_id=p.id WHERE m.id=2 AND m.role='admin' AND m.is_active=1 AND p.project_key=? AND p.is_active=1 AND s.source_key=? AND s.channel='max' AND s.is_active=1 AND s.handling_mode IN ('manager','ask')");
        $query->execute([AppleService39PilotManifest::PROJECT,AppleService39PilotManifest::SOURCE]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        self::check(count($ids) === 1 && (int)$ids[0] > 0, 'active_project_admin_and_source_required');
        return (int)$ids[0];
    }

    private function beforeWrite(array $row, ?callable $beforeClaim = null): void
    {
        $this->store->assertBinding($this->preflight());
        if ($beforeClaim !== null) $beforeClaim();
        $this->source();
        self::check(($this->provenance)() === true, 'installed_checkout_changed');
        self::check($this->store->claim($row), 'pilot_operation_held');
    }

    private function prepareImages(): void
    {
        foreach ($this->manifest->posts() as $post) {
            if (!isset($post['image'])) continue;
            $path = $this->manifest->validateImage($post);
            $row = $this->store->prepare($post['id'], 'upload', ['asset_sha256'=>$post['image']['sha256']]);
            if ($row['state'] === 'verified') { $this->upload($post); continue; }
            self::check($row['state'] === 'prepared', 'pilot_upload_held');
            $this->beforeWrite($row);
            try {
                $response = $this->transport->upload($path);
                $receipt = AppleService39ChannelTransport::uploadReceipt($response);
                if ($receipt === null) {
                    $this->store->finish($row, 'unknown', ['reason'=>'upload_outcome_unknown']);
                    throw new RuntimeException('pilot_upload_held');
                }
                $this->store->finish($row, 'verified', $receipt);
            } catch (Throwable $error) {
                $this->hold($row, 'upload_outcome_unknown');
                throw $error;
            }
        }
    }

    private function upload(array $post): ?array
    {
        if (!isset($post['image'])) return null;
        $this->manifest->validateImage($post);
        $row = $this->store->operation($post['id'], 'upload');
        self::check($row !== null && $row['state'] === 'verified'
            && ($row['request']['asset_sha256'] ?? null) === $post['image']['sha256']
            && is_string($row['outcome']['token'] ?? null) && $row['outcome']['token'] !== ''
            && preg_match('/\A[0-9]+\z/D', (string)($row['outcome']['photo_id'] ?? '')) === 1, 'prepare_images_first');
        return $row['outcome'];
    }

    private function publish(): void
    {
        // Validate the entire batch before its first POST. Uploads are a separate
        // explicit phase, giving MAX time to finish processing approved images.
        foreach ($this->manifest->posts() as $post) $this->manifest->body($post, $this->upload($post));
        foreach ($this->manifest->posts() as $post) {
            $body = $this->manifest->body($post, $this->upload($post));
            $row = $this->store->prepare($post['id'], 'create', ['body'=>$body]);
            if ($row['state'] === 'verified') continue;
            self::check($row['state'] === 'prepared', 'pilot_create_held');
            ($this->pause)();
            $this->beforeWrite($row);
            try {
                $response = $this->transport->create($body);
                $message = AppleService39ChannelTransport::accepted($response) ? ($response['data']['message'] ?? null) : null;
                self::check(is_array($message) && $this->messageIdentity($message), 'create_receipt_unknown');
                $mid = (string)$message['body']['mid'];
                $this->store->rememberMessage($row, $mid, '');
                $readback = self::read($this->transport->message($mid));
                $url = $this->verifyMessage($readback, $mid, $body, $post);
                $this->store->rememberMessage($row, $mid, $url);
                $this->store->finish($row, 'verified', ['readback'=>true]);
            } catch (Throwable $error) {
                $this->hold($row, 'create_outcome_unknown');
                throw new RuntimeException('pilot_create_held');
            }
        }
    }

    /** Every target must be an exact own created post with a fresh native URL. */
    private function verifiedPosts(bool $requireFinal): array
    {
        $urls = []; $rows = [];
        foreach ($this->manifest->posts() as $post) {
            $row = $this->store->operation($post['id'], 'create');
            self::check($row !== null && $row['state'] === 'verified' && (int)$row['write_attempts'] === 1
                && is_string($row['external_message_id']) && $row['external_message_id'] !== '', 'all_pilot_posts_required');
            $links = $this->store->operation($post['id'], 'links');
            if ($requireFinal) self::check($links !== null && $links['state'] === 'verified', 'all_pilot_links_required');
            $expected = $links !== null && $links['state'] === 'verified' ? $links['request']['body'] : $row['request']['body'];
            // An unresolved edit cannot be assumed to still have the initial body.
            self::check($links === null || in_array($links['state'], ['prepared','verified'], true), 'pilot_edit_held');
            $readback = self::read($this->transport->message($row['external_message_id']));
            $url = $this->verifyMessage($readback, $row['external_message_id'], $expected, $post);
            self::check($row['public_url'] === $url, 'post_url_changed');
            $urls[$post['id']] = $url; $rows[$post['id']] = $row;
        }
        self::check(count(array_unique(array_column($rows, 'external_message_id'))) === 24
            && count(array_unique($urls)) === 24, 'pilot_provider_identity_collision');
        return [$urls,$rows];
    }

    private function finalize(): void
    {
        [$urls,$created] = $this->verifiedPosts(false);
        $bodies = [];
        foreach ($this->manifest->posts() as $post) $bodies[$post['id']] = $this->manifest->body($post, $this->upload($post), $urls);
        foreach ($this->manifest->posts() as $post) {
            $mid = $created[$post['id']]['external_message_id']; $body = $bodies[$post['id']];
            $row = $this->store->prepare($post['id'], 'links', ['mid'=>$mid,'body'=>$body]);
            if ($row['state'] === 'verified') continue;
            self::check($row['state'] === 'prepared', 'pilot_edit_held');
            // Do not overwrite any intervening manual edit, even on an own MID.
            ($this->pause)();
            $this->beforeWrite($row, function () use ($mid,$created,$post): void {
                $before = self::read($this->transport->message($mid));
                $this->verifyMessage($before, $mid, $created[$post['id']]['request']['body'], $post);
            });
            try {
                $this->store->rememberMessage($row, $mid, $urls[$post['id']]);
                $this->transport->edit($mid, $body);
                // Exactly one GET follows even when the PUT response was lost.
                $readback = self::read($this->transport->message($mid));
                self::check($this->verifyMessage($readback, $mid, $body, $post) === $urls[$post['id']], 'post_url_changed');
                $this->store->finish($row, 'verified', ['readback'=>true]);
            } catch (Throwable $error) {
                $this->hold($row, 'edit_outcome_unknown');
                throw new RuntimeException('pilot_edit_held');
            }
        }
        $this->verifiedPosts(true);
    }

    private function pinMenu(): void
    {
        [, $created] = $this->verifiedPosts(true);
        $post = $this->manifest->menuPostId(); $mid = $created[$post]['external_message_id'];
        $member = self::read($this->transport->membership());
        self::check(in_array('pin_message', (array)($member['permissions'] ?? []), true), 'channel_pin_permission_required');
        $row = $this->store->prepare($post, 'pin', ['mid'=>$mid]);
        $pinned = $this->pinnedMid();
        if ($pinned === $mid) {
            if ($row['state'] === 'prepared') $this->store->confirmExistingPin($row);
            elseif (in_array($row['state'], ['write_started','unknown'], true)) $this->store->finish($row, 'verified', ['readback'=>true]);
            self::check(in_array($this->store->operation($post, 'pin')['state'], ['verified'], true), 'pilot_pin_held');
            return;
        }
        self::check($row['state'] === 'prepared', 'pilot_pin_held');
        ($this->pause)();
        $this->beforeWrite($row);
        try {
            $this->transport->setPin($mid);
            self::check($this->pinnedMid() === $mid, 'pin_readback_unknown');
            $this->store->finish($row, 'verified', ['readback'=>true]);
        } catch (Throwable $error) {
            $this->hold($row, 'pin_outcome_unknown');
            throw new RuntimeException('pilot_pin_held');
        }
    }

    private function pinnedMid(): ?string
    {
        $response = $this->transport->pin();
        if (empty($response['transport_error']) && (int)($response['http'] ?? 0) === 404) return null;
        $data = self::read($response);
        $message = $data['message'] ?? null;
        if ($message === null) return null;
        self::check(is_array($message) && $this->messageIdentity($message), 'pin_identity_mismatch');
        return (string)$message['body']['mid'];
    }

    private function reconcile(): void
    {
        foreach ($this->store->rows() as $row) {
            if (!in_array($row['state'], ['write_started','unknown'], true)) continue;
            if (in_array($row['operation'], ['create','links'], true) && is_string($row['external_message_id']) && $row['external_message_id'] !== '') {
                $post = $this->manifest->post($row['post_key']);
                $response = null; $readback = null;
                try {
                    $response = $this->transport->message($row['external_message_id']);
                    $readback = self::read($response);
                    $url = $this->verifyMessage($readback, $row['external_message_id'], $row['request']['body'], $post);
                    $this->store->rememberMessage($row, $row['external_message_id'], $url);
                    $this->store->finish($row, 'verified', ['readback'=>true]);
                } catch (Throwable $error) {
                    // Diagnose only the exact recorded MID; never retry or adopt a post.
                    $this->readbackDiagnostics[] = $this->readbackDiagnostic($row, $response, $readback, $error);
                }
            } elseif ($row['operation'] === 'pin') {
                try {
                    $this->verifiedPosts(true);
                    if ($this->pinnedMid() === ($row['request']['mid'] ?? '')) $this->store->finish($row, 'verified', ['readback'=>true]);
                } catch (Throwable $ignored) { /* No repeated pin mutation. */ }
            }
            // A lost POST without MID and an uncertain upload remain held. A
            // similar public post is not proof that this operation created it.
        }
    }

    private function messageIdentity(array $message): bool
    {
        $sender = $message['sender'] ?? null;
        return (string)($message['recipient']['chat_id'] ?? '') === $this->channelId
            && ($message['recipient']['chat_type'] ?? null) === 'channel'
            && is_string($message['body']['mid'] ?? null) && preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D', $message['body']['mid']) === 1
            && ($sender === null || is_array($sender) && (string)($sender['user_id'] ?? '') === $this->botUserId
                && (!isset($sender['is_bot']) || $sender['is_bot'] === true));
    }

    private function verifyMessage(array $message, string $mid, array $body, array $post): string
    {
        self::check($this->messageIdentity($message) && $message['body']['mid'] === $mid
            && ($message['body']['text'] ?? null) === $body['text']
            && in_array($message['body']['markup'] ?? null, [null,[]], true)
            && ($message['link'] ?? null) === null, 'post_readback_mismatch');
        $actual = $message['body']['attachments'] ?? [];
        $expected = $body['attachments'] ?? [];
        self::check(is_array($actual) && array_is_list($actual) && count($actual) === count($expected), 'post_attachment_mismatch');
        $upload = $this->upload($post);
        foreach ($expected as $index=>$attachment) {
            $observed = $actual[$index];
            self::check(is_array($observed) && ($observed['type'] ?? null) === $attachment['type'], 'post_attachment_mismatch');
            if ($attachment['type'] === 'image') self::check((string)($observed['payload']['photo_id'] ?? '') === $upload['photo_id'], 'post_image_mismatch');
            else self::check(($observed['payload']['buttons'] ?? null) === $attachment['payload']['buttons'], 'post_button_mismatch');
        }
        $url = $message['url'] ?? null;
        self::check(is_string($url) && self::publicUrl($url, $this->channelId), 'native_post_url_required');
        return $url;
    }

    public static function publicUrl(string $url, string $channelId): bool
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'max.ru'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) return false;
        if (preg_match('~\A/channel_appleservice39ru/[A-Za-z0-9_-]{1,191}\z~D', (string)($parts['path'] ?? ''))) return true;
        return preg_match('~\A/c/(-?[1-9][0-9]{0,18})/[A-Za-z0-9_-]{1,191}\z~D', (string)($parts['path'] ?? ''), $match) === 1
            && ltrim($match[1], '-') === ltrim($channelId, '-');
    }

    private function hold(array $row, string $reason): void
    {
        try {
            $current = $this->store->operation($row['post_key'], $row['operation']);
            if ($current && in_array($current['state'], ['write_started','unknown'], true)) $this->store->finish($current, 'unknown', ['reason'=>$reason]);
        } catch (Throwable $ignored) { /* The committed write_started claim still holds. */ }
    }

    /** Bounded predicate evidence only: no provider body, token, text or foreign URL. */
    private function readbackDiagnostic(array $row, ?array $response, ?array $message, Throwable $error): array
    {
        $reasons = ['provider_read_unknown','post_readback_mismatch','post_attachment_mismatch',
            'post_image_mismatch','post_button_mismatch','native_post_url_required'];
        $reason = in_array($error->getMessage(), $reasons, true) ? $error->getMessage() : 'readback_unclassified';
        $url = $message['url'] ?? null; $parts = is_string($url) ? parse_url($url) : false;
        $safeUrl = $parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'max.ru'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['query']) && !isset($parts['fragment'])
            && (preg_match('~\\A/channel_appleservice39ru/[A-Za-z0-9_.-]{1,191}\\z~D', (string)($parts['path'] ?? ''))
                || preg_match('~\\A/c/(-?[1-9][0-9]{0,18})/[A-Za-z0-9_.-]{1,191}\\z~D', (string)($parts['path'] ?? ''), $match)
                    && ltrim($match[1], '-') === ltrim($this->channelId, '-'));
        return ['post_id'=>$row['post_key'],'operation'=>$row['operation'],'message_id'=>$row['external_message_id'],
            'reason'=>$reason,'http'=>(int)($response['http'] ?? 0),'transport_error'=>!empty($response['transport_error']),
            'message_envelope'=>is_array($response['data']['message'] ?? null),
            'identity_matches'=>$message !== null && $this->messageIdentity($message),
            'mid_matches'=>($message['body']['mid'] ?? null) === $row['external_message_id'],
            'text_matches'=>($message['body']['text'] ?? null) === ($row['request']['body']['text'] ?? null),
            'url_present'=>is_string($url) && $url !== '',
            'url_matches_policy'=>is_string($url) && self::publicUrl($url, $this->channelId),
            'observed_own_url'=>$safeUrl ? $url : null];
    }

    private function report(string $state): array
    {
        $rows = []; $held = 0;
        foreach ($this->store->rows() as $row) {
            if (in_array($row['state'], ['write_started','unknown','rejected'], true)) $held++;
            $rows[] = ['post_id'=>$row['post_key'],'operation'=>$row['operation'],'state'=>$row['state'],
                'write_attempts'=>(int)$row['write_attempts'],'message_id'=>$row['external_message_id'],
                'url'=>$row['public_url'] !== '' ? $row['public_url'] : null];
        }
        return ['ok'=>$held === 0,'state'=>$held === 0 ? $state : 'held','campaign'=>AppleService39PilotManifest::CAMPAIGN,
            'project_key'=>AppleService39PilotManifest::PROJECT,'bot_key'=>AppleService39PilotManifest::BOT,
            'channel_id'=>$this->channelId,'channel_url'=>AppleService39PilotManifest::CHANNEL,
            'manifest_sha256'=>$this->manifest->sha256(),'post_count'=>24,'held_operations'=>$held,'operations'=>$rows,
            'readback_diagnostics'=>$this->readbackDiagnostics];
    }

    private static function connectionHash(ProjectMaxConnection $connection): string
    {
        return hash('sha256', implode("\0", [$connection->projectKey(),$connection->botKey(),$connection->baseUrl(),$connection->token()]));
    }
    private static function positiveId($value): string { $id = is_int($value) || is_string($value) ? (string)$value : ''; return preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) ? $id : ''; }
    private static function read(array $response): array { self::check(AppleService39ChannelTransport::accepted($response), 'provider_read_unknown'); return $response['data']; }
    private static function check(bool $condition, string $reason): void { if (!$condition) throw new RuntimeException($reason); }
}
