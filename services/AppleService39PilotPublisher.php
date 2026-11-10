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
    private bool $buttonsNormalized = false;
    private ?AppleService39CatalogueRevision $catalogueRevision;

    public function __construct(PDO $pdo, AppleService39PilotManifest $manifest, string $channelId,
        callable $provenance, ?callable $wire = null, ?callable $pause = null,
        ?AppleService39CatalogueRevision $catalogueRevision = null)
    {
        $connection = ProjectMaxConnection::resolve(AppleService39PilotManifest::PROJECT);
        self::check($connection !== null, 'pilot_connection_unavailable');
        $this->pdo = $pdo; $this->manifest = $manifest; $this->channelId = $channelId;
        $this->connection = $connection; $this->store = new AppleService39PilotPublicationStore($pdo);
        $this->transport = new AppleService39ChannelTransport($connection, $channelId, $wire);
        $this->provenance = $provenance;
        $this->pause = $pause ?? static function (): void { usleep(600000); };
        $this->catalogueRevision = $catalogueRevision;
    }

    public function run(string $operation): array
    {
        self::check(in_array($operation, ['inspect','prepare','publish','finalize','pin','reconcile','landing_inspect','landing_prepare','landing_apply','landing_reconcile'], true), 'unsupported_pilot_operation');
        $this->readbackDiagnostics = [];
        $binding = $this->preflight();
        $this->store->assertBinding($binding);
        if (str_starts_with($operation, 'landing_')) return $this->catalogueRevision !== null
            ? $this->catalogue($operation) : $this->landing($operation, $binding);
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
                $receipt = $this->createImageReceipt($row, $post, $message, $body);
                $this->store->rememberImageReceipt($row, $receipt);
                $readback = self::read($this->transport->message($mid));
                $url = $this->verifyMessage($readback, $mid, $body, $post);
                $this->store->rememberMessage($row, $mid, $url);
                $this->store->finish($row, 'verified', ['readback'=>true,'button_object_key_order_normalized'=>$this->buttonsNormalized]);
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
                $this->store->finish($row, 'verified', ['readback'=>true,'button_object_key_order_normalized'=>$this->buttonsNormalized]);
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
                    $candidate = $row['operation'] === 'create' && !isset($row['outcome']['image_receipt'])
                        ? $this->readbackImageReceipt($row, $post, $readback) : null;
                    $url = $this->verifyMessage($readback, $row['external_message_id'], $row['request']['body'], $post, $candidate);
                    if ($candidate !== null) $this->store->rememberImageReceipt($row, $candidate);
                    $this->store->rememberMessage($row, $row['external_message_id'], $url);
                    $this->store->finish($row, 'verified', ['readback'=>true,'button_object_key_order_normalized'=>$this->buttonsNormalized]);
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


    /** Same original MIDs/campaign; one upload per new asset and one edit per post. */
    private function landing(string $operation, array $binding): array
    {
        self::check($this->store->campaign() !== null, 'landing_existing_campaign_required');
        $revision = new AppleService39LandingRevision($this->manifest);
        $targets = $this->landingTargets($revision);
        self::check($this->pinnedMid() === $targets[$this->manifest->menuPostId()]['mid'], 'landing_menu_pin_changed');
        if ($operation === 'landing_prepare') {
            // Validate every original message and pin before the first new upload.
            $this->landingRead($targets, false);
            foreach ($targets as $id=>$target) {
                if ($target['asset'] === null) continue;
                $row = $this->store->prepare($id, 'landing_upload', $target['upload_request']);
                if ($row['state'] === 'verified') continue;
                self::check($row['state'] === 'prepared', 'landing_upload_held');
                $path = $revision->validateArtwork($id);
                $this->beforeWrite($row, function () use ($target): void {
                    $message = self::read($this->transport->message($target['mid']));
                    self::check($this->verifyMessage($message, $target['mid'], $target['prior'], $target['post'])
                        === $target['url'], 'post_url_changed');
                    self::check($this->pinnedMid() === $target['menu_mid'], 'landing_menu_pin_changed');
                });
                try {
                    $receipt = AppleService39ChannelTransport::uploadReceipt($this->transport->upload($path));
                    self::check($receipt !== null, 'landing_upload_held');
                    $this->store->finish($row, 'verified', $receipt);
                } catch (Throwable $error) {
                    $this->hold($row, 'landing_upload_outcome_unknown');
                    throw new RuntimeException('landing_upload_held');
                }
            }
            // Uploads are a separate phase; no message edit is hidden in preparation.
            $targets = $this->landingTargets($revision);
        } elseif ($operation === 'landing_apply') {
            // Only an already reconciled source-reviewed first image unlocks
            // collection of further individual review holds. It approves none.
            $reviewBatch = $this->firstReviewedLandingImage($targets);
            if ($reviewBatch) $this->verifyImageReviewHolds($targets);
            $this->landingRead($targets, $reviewBatch);
            foreach ($targets as $target) self::check($target['plan'] !== null, 'landing_images_not_prepared');
            foreach ($targets as $id=>$target) {
                // Navigation remains original until all15 images are verified.
                if ($target['asset'] === null && $this->hasImageReviewHolds()) break;
                $existing = $this->store->operation($id, 'landing_edit');
                if ($existing !== null && $existing['state'] === 'unknown') {
                    self::check($reviewBatch, 'landing_edit_held');
                    $this->verifyImageReviewHold($target, $existing);
                    continue; // Never prepare, claim, PUT or rewrite an old UNKNOWN.
                }
                $row = $this->store->prepare($id, 'landing_edit', $target['request']);
                if ($row['state'] === 'verified') continue;
                self::check($row['state'] === 'prepared', 'landing_edit_held');
                ($this->pause)();
                $this->beforeWrite($row, function () use ($target,$targets,$reviewBatch): void {
                    if ($reviewBatch) {
                        self::check($this->firstReviewedLandingImage($targets), 'landing_edit_held');
                        $this->verifyImageReviewHolds($targets);
                        $this->landingRead($targets, true);
                    }
                    $message = self::read($this->transport->message($target['mid']));
                    self::check($this->verifyMessage($message, $target['mid'], $target['prior'],
                        $target['post']) === $target['url'], 'post_url_changed');
                    self::check($this->pinnedMid() === $target['menu_mid'], 'landing_menu_pin_changed');
                });
                $editResponse = null;
                try {
                    $this->store->rememberMessage($row, $target['mid'], $target['url']);
                    $editResponse = $this->transport->edit($target['mid'], $target['plan']['body']);
                    $this->verifyLandingTarget($target, true);
                    $this->store->rememberMessage($row, $target['mid'], $target['url']);
                    $this->store->finish($row, 'verified', ['readback'=>true,
                        'revision_sha256'=>$revision->sha256(),'button_object_key_order_normalized'=>$this->buttonsNormalized]);
                } catch (Throwable $error) {
                    if ($reviewBatch && $target['asset'] !== null
                        && $error->getMessage() === 'landing_native_image_evidence_required'
                        && is_array($editResponse) && AppleService39ChannelTransport::accepted($editResponse)
                        && ($editResponse['data']['success'] ?? null) === true) {
                        try {
                            $current = $this->store->operation($id, 'landing_edit');
                            self::check($current !== null && $current['state'] === 'write_started', 'landing_edit_held');
                            $observation = $this->imageReviewObservation($target, $current);
                            $this->store->assertBinding($this->preflight());
                            self::check($this->firstReviewedLandingImage($targets), 'landing_edit_held');
                            $this->verifyImageReviewHolds($targets, (int)$current['id']);
                            $this->rejectKnownImageCollision($id, $observation['native_photo_id']);
                            self::check($this->pinnedMid() === $target['menu_mid'], 'landing_menu_pin_changed');
                            $this->store->finish($current, 'unknown', ['reason'=>'landing_image_review_required',
                                'image_review_observation'=>$observation]);
                            continue; // A disclosed hold, never an image receipt or verified edit.
                        } catch (Throwable $ignored) { /* Every other ambiguity stops this pass. */ }
                    }
                    $this->hold($row, 'landing_edit_outcome_unknown');
                    throw new RuntimeException('landing_edit_held');
                }
            }
        } elseif ($operation === 'landing_reconcile') {
            // Only a complete fresh read of the intended result may close an existing claim.
            foreach ($targets as $id=>$target) {
                $row = $this->store->operation($id, 'landing_edit');
                if ($row === null || !in_array($row['state'], ['write_started','unknown'], true)) continue;
                try {
                    $this->store->assertBinding($this->preflight());
                    $this->verifyLandingTarget($target, true);
                    $outcome = ['readback'=>true,'revision_sha256'=>$revision->sha256(),
                        'button_object_key_order_normalized'=>$this->buttonsNormalized];
                    if (isset($row['outcome']['image_review_observation'])) {
                        $outcome['image_review_observation'] = $row['outcome']['image_review_observation'];
                    }
                    $this->store->finish($row, 'verified', $outcome);
                } catch (Throwable $error) {
                    $safeReasons = ['provider_read_unknown','post_readback_mismatch','post_attachment_mismatch',
                        'post_button_mismatch','post_image_mismatch','post_url_changed','native_post_url_required',
                        'landing_native_image_evidence_required','invalid_landing_image_receipt',
                        'landing_review_evidence_mismatch','landing_review_evidence_changed','landing_review_evidence_unavailable'];
                    $this->readbackDiagnostics[] = ['post_id'=>$id,'operation'=>'landing_edit',
                        'message_id'=>$target['mid'],'reason'=>in_array($error->getMessage(), $safeReasons, true)
                            ? $error->getMessage() : 'landing_readback_held'];
                }
            }
        }
        $observation = $this->landingRead($targets, true);
        self::check($this->pinnedMid() === $targets[$this->manifest->menuPostId()]['mid'], 'landing_menu_pin_changed');
        $result = $this->report($operation.'_complete');
        $result['landing_revision'] = AppleService39LandingRevision::KEY;
        $result['landing_revision_sha256'] = $revision->sha256();
        $result['landing_readback'] = $observation;
        $result['landing_verified_posts'] = count(array_filter($observation, static fn(array $row): bool => $row['state'] === 'verified'));
        $result['landing_expected_navigation_links'] = 86;
        $result['landing_expected_bot_buttons'] = 24;
        $result['landing_expected_new_images'] = 15;
        $result['landing_verified_new_images'] = count(array_filter($observation,
            static fn(array $row): bool => $row['state'] === 'verified'
                && in_array($row['image_receipt_kind'], ['exact_upload_token','reviewed_native_replacement'], true)));
        $result['landing_menu_pin_preserved'] = true;
        $result['landing_image_review_holds'] = count(array_filter($observation,
            static fn(array $row): bool => $row['state'] === 'unknown' && $row['image_review_observation'] !== null));
        return $result;
    }

    /** A successor may start only after every old claim has a complete accepted outcome. */
    private function catalogueTargets(): array
    {
        self::check($this->catalogueRevision !== null && $this->store->campaign() !== null, 'catalogue_parent_incomplete');
        $priorCount = 0;
        foreach ($this->store->rows() as $row) {
            if ($row['operation'] === AppleService39CatalogueRevision::OPERATION) continue;
            self::check(in_array($row['operation'], ['upload','create','links','pin','landing_upload','landing_edit'], true)
                && $row['state'] === 'verified' && (int)$row['write_attempts'] === 1, 'catalogue_parent_incomplete');
            $priorCount++;
        }
        self::check($priorCount === 112, 'catalogue_parent_incomplete');
        $parent = new AppleService39LandingRevision($this->manifest);
        self::check($parent->sha256() === AppleService39CatalogueRevision::PARENT_SHA, 'catalogue_parent_changed');
        $legacy = $this->landingTargets($parent);
        $urls = array_map(static fn(array $t): string => $t['url'], $legacy);
        $posts = array_column($this->catalogueRevision->posts(), null, 'id');
        $targets = []; $photos = [];
        foreach ($legacy as $id=>$old) {
            $parentEdit = $this->store->operation($id, 'landing_edit');
            self::check($parentEdit !== null && $parentEdit['state'] === 'verified'
                && (int)$parentEdit['write_attempts'] === 1 && $parentEdit['request'] === $old['request'], 'catalogue_parent_incomplete');
            $imageRow = $old['asset'] !== null ? $parentEdit : $this->store->operation($id, 'create');
            $imageReceipt = $imageRow['outcome']['image_receipt'] ?? null;
            self::check(is_array($imageReceipt), 'catalogue_parent_incomplete');
            $row = $this->store->operation($id, AppleService39CatalogueRevision::OPERATION);
            if ($row === null) {
                // This is the actual fully validated parent GET, not a manufactured receipt.
                $message = $this->verifyLandingTarget($old);
                $photo = self::nativePhotoId($this->imagePayload($message)['photo_id'] ?? null);
            } else {
                self::check(in_array($row['state'], ['prepared','write_started','unknown','verified'], true)
                    && (int)$row['write_attempts'] === ($row['state'] === 'prepared' ? 0 : 1), 'catalogue_edit_held');
                $photo = $row['request']['preserved_photo_id'] ?? null;
            }
            self::check(is_string($photo) && self::nativePhotoId($photo) === $photo && $photo !== ''
                && !isset($photos[$photo]), 'catalogue_image_changed');
            $photos[$photo] = true;
            $plan = $this->catalogueRevision->plan($posts[$id], $urls);
            self::check(array_keys($plan['body']) === ['text','format'], 'catalogue_post_invalid');
            $request = ['revision'=>AppleService39CatalogueRevision::KEY,
                'revision_sha256'=>$this->catalogueRevision->sha256(),
                'catalogue_sha256'=>$this->catalogueRevision->catalogueSha256(),
                'parent_revision_sha256'=>AppleService39CatalogueRevision::PARENT_SHA,
                'mid'=>$old['mid'],'url'=>$old['url'],
                'parent_edit_operation_id'=>(int)$parentEdit['id'],
                'parent_edit_request_sha256'=>$parentEdit['payload_sha256'],
                'source_image_sha256'=>$old['request']['source_sha256'],
                'source_image_receipt_sha256'=>hash('sha256', json_encode($imageReceipt, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
                'preserved_photo_id'=>$photo,
                'body'=>$plan['body'],'native_text'=>$plan['native_text'],'native_markup'=>$plan['native_markup']];
            if ($row !== null) {
                $json = json_encode($request, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                self::check($row['request_json'] === $json && $row['payload_sha256'] === hash('sha256',$json)
                    && in_array($row['external_message_id'], [null,$old['mid']], true)
                    && in_array($row['public_url'], [null,'',$old['url']], true), 'catalogue_revision_changed');
                if ($row['state'] === 'verified') self::check($row['external_message_id'] === $old['mid']
                    && $row['public_url'] === $old['url'] && ($row['outcome']['text_only'] ?? null) === true
                    && ($row['outcome']['revision_sha256'] ?? null) === $this->catalogueRevision->sha256()
                    && ($row['outcome']['preserved_photo_id'] ?? null) === $photo, 'catalogue_revision_changed');
            }
            $expected = $old['plan']['body']; $expected['text'] = $plan['body']['text'];
            $targets[$id] = ['legacy'=>$old,'plan'=>$plan,'expected_body'=>$expected,'request'=>$request,
                'photo_id'=>$photo,'mid'=>$old['mid'],'url'=>$old['url'],'row'=>$row];
        }
        self::check(count($targets) === 24 && count($photos) === 24
            && $this->pinnedMid() === $targets[$this->manifest->menuPostId()]['mid'], 'landing_menu_pin_changed');
        return $targets;
    }

    /** The new request changes text only; the prior proven image and exact keyboard must survive. */
    private function verifyCatalogueTarget(array $target, bool $newText): array
    {
        if (!$newText) $message = $this->verifyLandingTarget($target['legacy']);
        else {
            $message = self::read($this->transport->message($target['mid']));
            self::check($this->verifyMessageContent($message, $target['mid'], $target['expected_body'], $target['plan'])
                === $target['url'], 'post_url_changed');
        }
        self::check(self::nativePhotoId($this->imagePayload($message)['photo_id'] ?? null) === $target['photo_id'], 'catalogue_image_changed');
        return $message;
    }

    private function catalogueOutcome(array $target): array
    {
        return ['readback'=>true,'text_only'=>true,'revision_sha256'=>$this->catalogueRevision->sha256(),
            'preserved_photo_id'=>$target['photo_id'],
            'parent_edit_request_sha256'=>$target['request']['parent_edit_request_sha256'],
            'button_object_key_order_normalized'=>$this->buttonsNormalized];
    }

    private function catalogueRead(array $targets, bool $allowHeld): array
    {
        $result = [];
        foreach ($targets as $id=>$target) {
            $row = $this->store->operation($id, AppleService39CatalogueRevision::OPERATION);
            $state = $row['state'] ?? 'not_started'; $observed = false;
            try {
                $this->verifyCatalogueTarget($target, $row !== null && $state !== 'prepared');
                $observed = true;
            } catch (Throwable $error) {
                if (!$allowHeld) throw $error;
                $safe = ['provider_read_unknown','post_readback_mismatch','post_attachment_mismatch','post_button_mismatch',
                    'post_url_changed','native_post_url_required','catalogue_image_changed'];
                $this->readbackDiagnostics[] = ['post_id'=>$id,'operation'=>AppleService39CatalogueRevision::OPERATION,
                    'message_id'=>$target['mid'],'reason'=>in_array($error->getMessage(),$safe,true) ? $error->getMessage() : 'catalogue_readback_held'];
            }
            $result[] = ['post_id'=>$id,'message_id'=>$target['mid'],'url'=>$target['url'],'state'=>$state,
                'write_attempts'=>$row === null ? 0 : (int)$row['write_attempts'],
                'native_photo_id'=>$observed ? $target['photo_id'] : null,'native_image_preserved'=>$observed,
                'text_only'=>$row['outcome']['text_only'] ?? false];
        }
        return $result;
    }

    /** Existing fixed modes and the same durable store; no upload, create, pin, or ambiguous replay. */
    private function catalogue(string $operation): array
    {
        if ($operation === 'landing_apply') self::check($this->report('inspected')['held_operations'] === 0, 'catalogue_edit_held');
        $targets = $this->catalogueTargets();
        if ($operation === 'landing_apply') {
            $this->catalogueRead($targets, false);
            foreach ($targets as $id=>$target) {
                $row = $this->store->prepare($id, AppleService39CatalogueRevision::OPERATION, $target['request']);
                if ($row['state'] === 'verified') continue;
                self::check($row['state'] === 'prepared', 'catalogue_edit_held');
                ($this->pause)();
                $this->beforeWrite($row, function () use ($target,$targets): void {
                    self::check($this->report('inspected')['held_operations'] === 0, 'catalogue_edit_held');
                    $this->verifyCatalogueTarget($target, false);
                    self::check($this->pinnedMid() === $targets[$this->manifest->menuPostId()]['mid'], 'landing_menu_pin_changed');
                });
                try {
                    $this->store->rememberMessage($row,$target['mid'],$target['url']);
                    // MAX PUT /messages preserves attachments when the field is omitted.
                    // https://dev.max.ru/docs-api/methods/PUT/messages
                    self::check(array_keys($target['request']['body']) === ['text','format'], 'catalogue_post_invalid');
                    $response = $this->transport->edit($target['mid'],$target['request']['body']);
                    self::check(AppleService39ChannelTransport::accepted($response)
                        && ($response['data']['success'] ?? null) === true, 'catalogue_edit_held');
                    $this->verifyCatalogueTarget($target,true);
                    self::check($this->pinnedMid() === $targets[$this->manifest->menuPostId()]['mid'], 'landing_menu_pin_changed');
                    $this->store->finish($row,'verified',$this->catalogueOutcome($target));
                } catch (Throwable $error) {
                    $this->hold($row,'catalogue_edit_outcome_unknown');
                    throw new RuntimeException('catalogue_edit_held');
                }
            }
        } elseif ($operation === 'landing_reconcile') {
            foreach ($targets as $id=>$target) {
                $row = $this->store->operation($id,AppleService39CatalogueRevision::OPERATION);
                if ($row === null || !in_array($row['state'],['write_started','unknown'],true)) continue;
                try {
                    $this->store->assertBinding($this->preflight());
                    $this->verifyCatalogueTarget($target,true);
                    self::check($this->pinnedMid() === $targets[$this->manifest->menuPostId()]['mid'], 'landing_menu_pin_changed');
                    $this->store->rememberMessage($row,$target['mid'],$target['url']);
                    $this->store->finish($row,'verified',$this->catalogueOutcome($target));
                } catch (Throwable $error) {
                    $this->readbackDiagnostics[] = ['post_id'=>$id,'operation'=>AppleService39CatalogueRevision::OPERATION,
                        'message_id'=>$target['mid'],'reason'=>'catalogue_readback_held'];
                }
            }
        }
        $observation = $this->catalogueRead($targets,true);
        self::check($this->pinnedMid() === $targets[$this->manifest->menuPostId()]['mid'], 'landing_menu_pin_changed');
        $result = $this->report(str_replace('landing_','catalogue_',$operation).'_complete');
        $result['ok'] = $result['ok'] && $this->readbackDiagnostics === [];
        $result['landing_revision'] = AppleService39CatalogueRevision::KEY;
        $result['landing_revision_sha256'] = $this->catalogueRevision->sha256();
        $result['parent_revision_sha256'] = AppleService39CatalogueRevision::PARENT_SHA;
        $result['catalogue_sha256'] = $this->catalogueRevision->catalogueSha256();
        $result['catalogue_model_count'] = $this->catalogueRevision->modelCount();
        $result['catalogue_warranty_months'] = AppleService39PilotCatalog::WARRANTY_MONTHS;
        $result['catalogue_readback'] = $observation;
        $result['catalogue_verified_posts'] = count(array_filter($observation,static fn(array $r):bool=>$r['state']==='verified' && $r['native_image_preserved']));
        $result['catalogue_preserved_images'] = count(array_filter($observation,static fn(array $r):bool=>$r['native_image_preserved']));
        $result['catalogue_new_uploads'] = 0;
        $result['catalogue_new_images'] = 0;
        $result['catalogue_expected_navigation_links'] = 86;
        $result['catalogue_expected_bot_buttons'] = 24;
        $result['catalogue_menu_pin_preserved'] = true;
        return $result;
    }

    /** A real GET-reconciled first review is the sole anchor for the finite pass. */
    private function firstReviewedLandingImage(array $targets): bool
    {
        $first = $this->manifest->posts()[0]['id'];
        $target = $targets[$first]; $row = $this->store->operation($first, 'landing_edit');
        if ($row === null || $row['state'] !== 'verified' || $target['reviewed_image'] === null
            || ($row['outcome']['image_receipt']['kind'] ?? null) !== 'reviewed_native_replacement') return false;
        $this->verifyLandingTarget($target);
        return true;
    }

    private function hasImageReviewHolds(): bool
    {
        foreach ($this->store->rows() as $row) if ($row['state'] === 'unknown'
            && isset($row['outcome']['image_review_observation'])) return true;
        return false;
    }

    /** Unclassified/old UNKNOWN, lost uploads and all non-image holds still stop. */
    private function verifyImageReviewHolds(array $targets, ?int $currentWrite = null): void
    {
        foreach ($this->store->rows() as $row) {
            if (!in_array($row['state'], ['write_started','unknown','rejected'], true)) continue;
            if ((int)$row['id'] === $currentWrite) {
                self::check($row['operation'] === 'landing_edit' && $row['state'] === 'write_started'
                    && (int)$row['write_attempts'] === 1, 'landing_edit_held');
                continue;
            }
            self::check($row['operation'] === 'landing_edit' && isset($targets[$row['post_key']]), 'landing_edit_held');
            $this->verifyImageReviewHold($targets[$row['post_key']], $row);
        }
    }

    /** This record describes a pending image; it never supplies image identity. */
    private function imageReviewRecord(array $target, array $row): array
    {
        $saved = $row['outcome']['image_review_observation'] ?? null;
        $keys = ['kind','source_identity_verified','message_id','public_url','native_photo_id','native_payload_url_sha256',
            'native_text_sha256','revision_sha256','source_sha256','upload_operation_id','upload_request_sha256',
            'upload_token_sha256','edit_operation_id','edit_request_sha256','edit_started_ms'];
        self::check($target['asset'] !== null && is_array($saved) && array_keys($saved) === $keys
            && $saved['kind'] === 'individual_image_review_required' && $saved['source_identity_verified'] === false
            && $saved['message_id'] === $target['mid'] && $saved['public_url'] === $target['url']
            && self::nativePhotoId($saved['native_photo_id']) !== ''
            && is_string($saved['native_payload_url_sha256'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $saved['native_payload_url_sha256']) === 1
            && $saved['native_text_sha256'] === hash('sha256', $target['plan']['native_text'])
            && $saved['revision_sha256'] === $target['request']['revision_sha256']
            && $saved['source_sha256'] === $target['asset']['sha256']
            && $saved['upload_operation_id'] === (int)$target['upload_row']['id']
            && $saved['upload_request_sha256'] === $target['upload_row']['payload_sha256']
            && $saved['upload_token_sha256'] === $target['request']['upload_token_sha256']
            && $saved['edit_operation_id'] === (int)$row['id'] && $saved['edit_request_sha256'] === $row['payload_sha256']
            && $saved['edit_started_ms'] === (int)$row['started_ms'], 'landing_edit_held');
        return $saved;
    }

    private function verifyImageReviewHold(array $target, array $row): void
    {
        self::check($row['state'] === 'unknown' && ($row['outcome']['reason'] ?? null) === 'landing_image_review_required'
            && !isset($row['outcome']['image_receipt']), 'landing_edit_held');
        $saved = $this->imageReviewRecord($target, $row);
        $fresh = $this->imageReviewObservation($target, $row);
        // Signed locators may expire/renew; never replace the original stored
        // observation. The exact native imageID and all other bindings persist.
        unset($saved['native_payload_url_sha256'], $fresh['native_payload_url_sha256']);
        self::check($saved === $fresh, 'landing_edit_held');
    }

    private function imageReviewObservation(array $target, array $row): array
    {
        self::check($target['asset'] !== null && $target['upload_row'] !== null
            && $target['upload_row']['state'] === 'verified' && (int)$target['upload_row']['write_attempts'] === 1
            && in_array($row['state'], ['write_started','unknown'], true) && (int)$row['write_attempts'] === 1
            && $row['request'] === $target['request'] && $row['external_message_id'] === $target['mid']
            && $row['public_url'] === $target['url'] && !isset($row['outcome']['image_receipt']), 'landing_edit_held');
        $native = self::read($this->transport->message($target['mid']));
        self::check($this->verifyMessageContent($native, $target['mid'], $target['plan']['body'], $target['plan'])
            === $target['url'], 'post_url_changed');
        $image = $this->imagePayload($native); $photo = self::nativePhotoId($image['photo_id'] ?? null);
        $token = $image['token'] ?? null; $url = $image['url'] ?? null;
        $locator = is_string($url) ? parse_url($url) : false;
        $prior = $this->store->operation($target['post']['id'], 'create')['outcome']['image_receipt']['photo_id'] ?? null;
        self::check($photo !== '' && $photo !== '0' && is_string($prior) && $photo !== $prior
            && is_string($token) && $token !== '' && strlen($token) <= 4096 && !preg_match('/[\x00-\x20\x7f]/', $token)
            && !hash_equals($target['image_upload']['token'], $token)
            && is_string($url) && strlen($url) <= 8192 && str_starts_with($url, 'https://')
            && is_array($locator) && !empty($locator['host']) && !isset($locator['user']) && !isset($locator['pass'])
            && !preg_match('/[\x00-\x20\x7f]/', $url), 'landing_edit_held');
        return ['kind'=>'individual_image_review_required','source_identity_verified'=>false,
            'message_id'=>$target['mid'],'public_url'=>$target['url'],'native_photo_id'=>$photo,
            'native_payload_url_sha256'=>hash('sha256',$url),'native_text_sha256'=>hash('sha256',$target['plan']['native_text']),
            'revision_sha256'=>$target['request']['revision_sha256'],'source_sha256'=>$target['asset']['sha256'],
            'upload_operation_id'=>(int)$target['upload_row']['id'],'upload_request_sha256'=>$target['upload_row']['payload_sha256'],
            'upload_token_sha256'=>$target['request']['upload_token_sha256'],'edit_operation_id'=>(int)$row['id'],
            'edit_request_sha256'=>$row['payload_sha256'],'edit_started_ms'=>(int)$row['started_ms']];
    }

    /** Known old or other-post IDs are a refusal, never a new-image witness. */
    private function rejectKnownImageCollision(string $postId, string $photo): void
    {
        foreach ($this->store->rows() as $row) {
            $known = $row['outcome']['image_receipt']['photo_id'] ?? null;
            if ($row['operation'] === 'create' || $row['post_key'] !== $postId) {
                self::check($photo !== $known, 'landing_edit_held');
            }
            if ($row['post_key'] !== $postId) self::check($photo !==
                ($row['outcome']['image_review_observation']['native_photo_id'] ?? null), 'landing_edit_held');
        }
    }

    private function landingTargets(AppleService39LandingRevision $revision): array
    {
        $targets = []; $urls = []; $mids = [];
        foreach ($this->manifest->posts() as $post) {
            $create = $this->store->operation($post['id'], 'create');
            $links = $this->store->operation($post['id'], 'links');
            self::check($create !== null && $links !== null && $create['state'] === 'verified' && $links['state'] === 'verified'
                && (int)$create['write_attempts'] === 1 && (int)$links['write_attempts'] === 1
                && is_string($create['external_message_id']) && $create['external_message_id'] !== ''
                && ($links['request']['mid'] ?? null) === $create['external_message_id']
                && $links['external_message_id'] === $create['external_message_id']
                && $links['public_url'] === $create['public_url']
                && self::publicUrl($create['public_url'], $this->channelId), 'landing_original_receipts_required');
            $urls[$post['id']] = $create['public_url']; $mids[$post['id']] = $create['external_message_id'];
            $targets[$post['id']] = ['post'=>$post,'mid'=>$create['external_message_id'],
                'url'=>$create['public_url'],'prior'=>$links['request']['body']];
        }
        self::check(count(array_unique($urls)) === 24 && count(array_unique($mids)) === 24, 'pilot_provider_identity_collision');
        foreach ($revision->posts() as $post) {
            $target = &$targets[$post['id']];
            $upload = $this->upload($target['post']);
            self::check($target['prior'] === $this->manifest->body($target['post'], $upload, $urls)
                && $this->store->operation($post['id'], 'create')['request']['body']
                    === $this->manifest->body($target['post'], $upload), 'landing_original_receipts_required');
            $target['menu_mid'] = $mids[$this->manifest->menuPostId()];
            $target['asset'] = $revision->artwork($post['id']);
            $target['upload_request'] = $target['asset'] === null ? null : [
                'revision'=>AppleService39LandingRevision::KEY,'revision_sha256'=>$revision->sha256(),
                'mid'=>$target['mid'],'url'=>$target['url'],'source_sha256'=>$target['asset']['sha256']];
            $replacement = $this->store->operation($post['id'], 'landing_upload');
            $target['upload_row'] = $replacement;
            $target['reviewed_image'] = $target['asset'] === null ? null : $revision->reviewedNativeImage($post['id']);
            if ($replacement !== null) {
                self::check($target['upload_request'] !== null
                    && $replacement['request_json'] === json_encode($target['upload_request'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)
                    && $replacement['payload_sha256'] === hash('sha256', $replacement['request_json']), 'landing_revision_changed');
            }
            $target['image_upload'] = $target['asset'] === null ? $upload : null;
            if ($target['asset'] !== null && $replacement !== null && $replacement['state'] === 'verified') {
                $value = $replacement['outcome'];
                self::check((int)$replacement['write_attempts'] === 1 && is_string($value['token'] ?? null)
                    && $value['token'] !== '' && is_string($value['photo_id'] ?? null)
                    && preg_match('/\A[0-9]+\z/D', $value['photo_id']) === 1, 'landing_upload_receipt_required');
                $target['image_upload'] = $value;
            }
            $target['plan'] = $target['image_upload'] === null ? null : $revision->plan($post, $target['image_upload'], $urls);
            $target['request'] = $target['plan'] === null ? null : ['revision'=>AppleService39LandingRevision::KEY,
                'revision_sha256'=>$revision->sha256(),'mid'=>$target['mid'],'url'=>$target['url'],
                'prior_body_sha256'=>hash('sha256', json_encode($target['prior'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
                'body'=>$target['plan']['body'],'native_text'=>$target['plan']['native_text'],
                'native_markup'=>$target['plan']['native_markup'],
                'source_sha256'=>$target['asset']['sha256'] ?? $target['post']['image']['sha256'],
                'upload_token_sha256'=>hash('sha256', $target['image_upload']['token'])];
            $row = $this->store->operation($post['id'], 'landing_edit');
            if ($row !== null) {
                self::check($target['request'] !== null, 'landing_revision_changed');
                $json = json_encode($target['request'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                self::check($row['request_json'] === $json && $row['payload_sha256'] === hash('sha256', $json),
                    'landing_revision_changed');
                self::check(($row['external_message_id'] === null || $row['external_message_id'] === $target['mid'])
                    && (in_array($row['public_url'], [null,''], true) || $row['public_url'] === $target['url']),
                    'landing_original_receipts_required');
            }
            unset($target);
        }
        return $targets;
    }

    private function verifyLandingTarget(array $target, bool $remember = false): array
    {
        self::check($target['plan'] !== null, 'landing_images_not_prepared');
        $message = self::read($this->transport->message($target['mid']));
        // Distinguish an image-only review gap only AFTER every content guard.
        self::check($this->verifyMessageContent($message, $target['mid'], $target['plan']['body'], $target['plan'])
            === $target['url'], 'post_url_changed');
        $replacement = null; $newReceipt = null;
        if ($target['asset'] !== null) {
            $row = $this->store->operation($target['post']['id'], 'landing_edit');
            self::check($row !== null && in_array($row['state'], ['write_started','unknown','verified'], true)
                && (int)$row['write_attempts'] === 1 && $row['external_message_id'] === $target['mid']
                && $row['public_url'] === $target['url'] && $row['request'] === $target['request'], 'landing_original_receipts_required');
            if (isset($row['outcome']['image_review_observation'])) {
                $originalObservation = $this->imageReviewRecord($target, $row);
                self::check(self::nativePhotoId($this->imagePayload($message)['photo_id'] ?? null)
                    === $originalObservation['native_photo_id'], 'landing_review_evidence_mismatch');
            }
            $receipt = $row['outcome']['image_receipt'] ?? null;
            if (isset($row['outcome']['image_review_observation'])) {
                // Once collected for individual review, this hold requires its
                // own registered proof even if a later token happens to match.
                if ($receipt === null) $newReceipt = $receipt = $this->reviewedReplacementReceipt($target, $row, $message);
                self::check(($receipt['kind'] ?? null) === 'reviewed_native_replacement', 'invalid_landing_image_receipt');
            }
            if ($receipt === null) {
                $payload = $this->imagePayload($message); $photoId = self::nativePhotoId($payload['photo_id'] ?? null);
                // A PUT returns only success. Neither that ACK, a changed photoID,
                // the photos-map index nor an arbitrary GET proves our new PNG.
                self::check($remember && $photoId !== '', 'landing_native_image_evidence_required');
                if (is_string($payload['token'] ?? null) && hash_equals($target['image_upload']['token'], $payload['token'])) {
                    $newReceipt = $receipt = ['kind'=>'exact_upload_token','message_id'=>$target['mid'],'photo_id'=>$photoId,
                        'source_sha256'=>$target['asset']['sha256'],
                        'upload_token_sha256'=>hash('sha256', $target['image_upload']['token']),
                        'request_sha256'=>$row['payload_sha256'],'revision_sha256'=>$target['request']['revision_sha256']];
                } else $newReceipt = $receipt = $this->reviewedReplacementReceipt($target, $row, $message);
            }
            $keys = ['kind','message_id','photo_id','source_sha256','upload_token_sha256','request_sha256','revision_sha256'];
            if (($receipt['kind'] ?? null) === 'reviewed_native_replacement') {
                $keys[] = 'evidence_sha256'; $keys[] = 'native_preview_sha256';
                self::check($receipt === $this->reviewedReplacementReceipt($target, $row, $message), 'invalid_landing_image_receipt');
            }
            self::check(is_array($receipt) && array_keys($receipt) === $keys
                && in_array($receipt['kind'], ['exact_upload_token','reviewed_native_replacement'], true)
                && $receipt['message_id'] === $target['mid']
                && self::nativePhotoId($receipt['photo_id']) !== '' && $receipt['photo_id'] === (string)$receipt['photo_id']
                && $receipt['source_sha256'] === $target['asset']['sha256']
                && $receipt['upload_token_sha256'] === $target['request']['upload_token_sha256']
                && $receipt['request_sha256'] === $row['payload_sha256']
                && $receipt['revision_sha256'] === $target['request']['revision_sha256'], 'invalid_landing_image_receipt');
            $replacement = ['upload'=>$target['image_upload'],'receipt'=>$receipt];
        }
        self::check($this->verifyMessage($message, $target['mid'], $target['plan']['body'],
            $target['post'], null, $target['plan'], $replacement) === $target['url'], 'post_url_changed');
        if ($newReceipt !== null) $this->store->rememberLandingImageReceipt($row, $newReceipt);
        return $message;
    }

    /** A separately reviewed, source-pinned native file; never an assertion from a GET/ACK alone. */
    private function reviewedReplacementReceipt(array $target, array $row, array $message): array
    {
        $evidence = $target['reviewed_image'];
        self::check(is_array($evidence), 'landing_native_image_evidence_required');
        $p = $evidence['proof']; $upload = $target['upload_row'];
        $keys = ['schema_version','kind','campaign_key','project_key','bot_key','channel_id','post_id',
            'message_id','public_url','revision_sha256','source_sha256','upload_operation_id','upload_request_sha256',
            'upload_token_sha256','edit_operation_id','edit_request_sha256','edit_started_ms','native_photo_id',
            'native_preview_sha256','native_preview_width','native_preview_height','native_payload_url_sha256',
            'native_readback_run_id','native_readback_job_id','visual_review'];
        self::check(is_array($p) && count($p) === count($keys) && array_diff(array_keys($p), $keys) === []
            && $p['schema_version'] === 1 && $p['kind'] === 'reviewed_native_replacement'
            && $p['campaign_key'] === AppleService39PilotManifest::CAMPAIGN
            && $p['project_key'] === AppleService39PilotManifest::PROJECT && $p['bot_key'] === AppleService39PilotManifest::BOT
            && $p['channel_id'] === $this->channelId && $p['post_id'] === $target['post']['id']
            && $p['message_id'] === $target['mid'] && $p['public_url'] === $target['url']
            && $p['revision_sha256'] === $target['request']['revision_sha256']
            && $p['source_sha256'] === $target['asset']['sha256']
            && $upload !== null && $upload['state'] === 'verified' && (int)$upload['write_attempts'] === 1
            && $p['upload_operation_id'] === (int)$upload['id'] && $p['upload_request_sha256'] === $upload['payload_sha256']
            && $p['upload_token_sha256'] === $target['request']['upload_token_sha256']
            && $p['edit_operation_id'] === (int)$row['id'] && $p['edit_request_sha256'] === $row['payload_sha256']
            && is_int($p['edit_started_ms']) && $p['edit_started_ms'] > 0 && $p['edit_started_ms'] === (int)$row['started_ms']
            && is_string($p['native_photo_id']) && self::nativePhotoId($p['native_photo_id']) !== ''
            && $p['native_photo_id'] === self::nativePhotoId($this->imagePayload($message)['photo_id'] ?? null)
            && $p['native_preview_sha256'] === $evidence['native_preview_sha256']
            && is_string($p['native_payload_url_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $p['native_payload_url_sha256']) === 1
            && is_int($p['native_readback_run_id']) && $p['native_readback_run_id'] > 0
            && is_int($p['native_readback_job_id']) && $p['native_readback_job_id'] > 0
            && $p['visual_review'] === ['source_asset_opened'=>true,'native_preview_opened'=>true,
                'exact_layout_model_service_price_qualifiers'=>true], 'landing_review_evidence_mismatch');
        return ['kind'=>'reviewed_native_replacement','message_id'=>$target['mid'],'photo_id'=>$p['native_photo_id'],
            'source_sha256'=>$target['asset']['sha256'],'upload_token_sha256'=>$target['request']['upload_token_sha256'],
            'request_sha256'=>$row['payload_sha256'],'revision_sha256'=>$target['request']['revision_sha256'],
            'evidence_sha256'=>$evidence['evidence_sha256'],'native_preview_sha256'=>$evidence['native_preview_sha256']];
    }

    /** Inspection does not change a claim, receipt, pin or provider message. */
    private function landingRead(array $targets, bool $allowHeld): array
    {
        $observed = [];
        foreach ($targets as $id=>$target) {
            $row = $this->store->operation($id, 'landing_edit');
            $state = $row['state'] ?? 'not_started';
            $held = in_array($state, ['write_started','unknown','rejected'], true);
            self::check($allowHeld || !$held, 'landing_edit_held');
            if ($state === 'verified') $this->verifyLandingTarget($target);
            elseif (!$held) {
                $message = self::read($this->transport->message($target['mid']));
                self::check($this->verifyMessage($message, $target['mid'], $target['prior'],
                    $target['post']) === $target['url'], 'post_url_changed');
            }
            $observation = null;
            if ($held) {
                try {
                    $native = self::read($this->transport->message($target['mid']));
                    $image = $this->imagePayload($native); $upload = $target['upload_row'];
                    $observation = ['observation_only'=>true,'native_identity_matches'=>$this->messageIdentity($native)
                            && ($native['body']['mid'] ?? null) === $target['mid'],
                        'native_url_matches'=>($native['url'] ?? null) === $target['url'],
                        'native_photo_id'=>self::nativePhotoId($image['photo_id'] ?? null),
                        'native_payload_url_sha256'=>is_string($image['url'] ?? null) ? hash('sha256', $image['url']) : null,
                        'source_sha256'=>$target['request']['source_sha256'] ?? null,
                        'revision_sha256'=>$target['request']['revision_sha256'] ?? null,
                        'upload_operation_id'=>$upload === null ? null : (int)$upload['id'],
                        'upload_request_sha256'=>$upload['payload_sha256'] ?? null,
                        'upload_token_sha256'=>$target['request']['upload_token_sha256'] ?? null,
                        'edit_operation_id'=>(int)$row['id'],'edit_request_sha256'=>$row['payload_sha256'],
                        'edit_started_ms'=>(int)$row['started_ms']];
                } catch (Throwable $error) { $observation = ['observation_only'=>true,'native_readback_unavailable'=>true]; }
            }
            $observed[] = ['post_id'=>$id,'message_id'=>$target['mid'],'url'=>$target['url'],
                'state'=>$state,'write_attempts'=>(int)($row['write_attempts'] ?? 0),
                'image_receipt_kind'=>$row['outcome']['image_receipt']['kind'] ?? null,'held_image_observation'=>$observation,
                'image_review_observation'=>$row['outcome']['image_review_observation'] ?? null];
        }
        return $observed;
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

    private function verifyMessage(array $message, string $mid, array $body, array $post, ?array $candidate = null, ?array $native = null, ?array $replacement = null): string
    {
        $url = $this->verifyMessageContent($message, $mid, $body, $native);
        $actual = $message['body']['attachments'] ?? [];
        $expected = $body['attachments'] ?? [];
        if ($replacement === null) {
            $upload = $this->upload($post);
            $created = $this->store->operation($post['id'], 'create');
            $receipt = $candidate ?? ($created['outcome']['image_receipt'] ?? null);
            self::check($created !== null && is_array($receipt) && $this->validImageReceipt($receipt, $created, $post, $upload, $mid),
                'native_image_receipt_required');
        } else {
            // The sole caller validates the source/claim-bound replacement receipt.
            self::check($native !== null && $candidate === null, 'invalid_landing_image_receipt');
            $upload = $replacement['upload']; $receipt = $replacement['receipt'];
        }
        foreach ($expected as $index=>$attachment) if ($attachment['type'] === 'image') {
            $observed = $actual[$index];
            self::check(($receipt['kind'] ?? null) === 'reviewed_native_replacement'
                ? self::nativePhotoId($observed['payload']['photo_id'] ?? null) === $receipt['photo_id']
                : self::imageIdentity((array)($observed['payload'] ?? []), $upload, $receipt), 'post_image_mismatch');
        }
        return $url;
    }

    /** Exact content/keyboard observation; by itself it proves NO image identity. */
    private function verifyMessageContent(array $message, string $mid, array $body, ?array $native = null): string
    {
        $this->buttonsNormalized = false;
        self::check($this->messageIdentity($message) && $message['body']['mid'] === $mid
            && ($message['body']['text'] ?? null) === ($native['native_text'] ?? $body['text'])
            && ($native !== null ? AppleService39LandingRevision::sameMarkup($message['body']['markup'] ?? null, $native['native_markup'])
                : in_array($message['body']['markup'] ?? null, [null,[]], true))
            && ($message['link'] ?? null) === null, 'post_readback_mismatch');
        $actual = $message['body']['attachments'] ?? [];
        $expected = $body['attachments'] ?? [];
        self::check(is_array($actual) && array_is_list($actual) && count($actual) === count($expected), 'post_attachment_mismatch');
        foreach ($expected as $index=>$attachment) {
            $observed = $actual[$index];
            self::check(is_array($observed) && ($observed['type'] ?? null) === $attachment['type'], 'post_attachment_mismatch');
            if ($attachment['type'] !== 'image') {
                $buttons = $observed['payload']['buttons'] ?? null;
                self::check(self::sameButtons($buttons, $attachment['payload']['buttons']), 'post_button_mismatch');
                if ($buttons !== $attachment['payload']['buttons']) $this->buttonsNormalized = true;
            }
        }
        $url = $message['url'] ?? null;
        self::check(is_string($url) && self::publicUrl($url, $this->channelId), 'native_post_url_required');
        return $url;
    }

    /** A provider photo identifier or the exact original unique attachment token. */
    private static function imageIdentity(array $payload, array $upload, array $receipt): bool
    {
        $id = $payload['photo_id'] ?? null;
        if (!is_int($id) && !is_string($id)) return false;
        $id = (string)$id;
        if (preg_match('/\\A-?(?:0|[1-9][0-9]{0,19})\\z/D', $id) !== 1) return false;
        if ($id === $receipt['photo_id']) return true;
        $token = $payload['token'] ?? null;
        return is_string($token) && $token !== '' && hash_equals($upload['token'], $token);
    }

    /** JSON object key order is immaterial; both array orders and every typed field are exact. */
    private static function sameButtons($actual, array $expected): bool
    {
        if (!is_array($actual) || !array_is_list($actual) || !array_is_list($expected) || count($actual) !== count($expected)) return false;
        foreach ($expected as $i=>$row) {
            if (!is_array($row) || !array_is_list($row) || !is_array($actual[$i]) || !array_is_list($actual[$i])
                || count($row) !== count($actual[$i])) return false;
            foreach ($row as $j=>$button) {
                $observed = $actual[$i][$j];
                if (!is_array($button) || array_is_list($button) || !is_array($observed) || array_is_list($observed)
                    || count($button) !== count($observed)) return false;
                foreach ($button as $key=>$value) if (!array_key_exists($key, $observed) || $observed[$key] !== $value) return false;
            }
        }
        return true;
    }

    private static function nativePhotoId($value): string
    {
        $id = is_int($value) || is_string($value) ? (string)$value : '';
        return preg_match('/\\A(?:0|-?[1-9][0-9]{0,18})\\z/D', $id) === 1 ? $id : '';
    }

    private function imagePayload(array $message): ?array
    {
        $attachments = $message['body']['attachments'] ?? null;
        if (!is_array($attachments) || !array_is_list($attachments)) return null;
        $images = array_values(array_filter($attachments, static fn($item): bool => is_array($item) && ($item['type'] ?? null) === 'image'));
        return count($images) === 1 && is_array($images[0]['payload'] ?? null) ? $images[0]['payload'] : null;
    }

    private function imageReceipt(array $row, array $post, string $mid, string $photoId, string $kind, string $evidence = ''): array
    {
        $upload = $this->upload($post);
        return ['kind'=>$kind,'message_id'=>$mid,'photo_id'=>$photoId,'source_sha256'=>$post['image']['sha256'],
            'upload_token_sha256'=>hash('sha256', $upload['token']),'request_sha256'=>$row['payload_sha256'],'evidence_sha256'=>$evidence];
    }

    /** Learn an opaque native photoID only from the original claimed POST response. */
    private function createImageReceipt(array $row, array $post, array $message, array $body): array
    {
        $payload = $this->imagePayload($message);
        $photoId = self::nativePhotoId($payload['photo_id'] ?? null);
        self::check($this->messageIdentity($message) && ($message['body']['text'] ?? null) === $body['text']
            && count((array)($message['body']['attachments'] ?? [])) === count($body['attachments'])
            && $photoId !== '', 'native_image_receipt_required');
        return $this->imageReceipt($row, $post, $message['body']['mid'], $photoId, 'create_response');
    }

    private function validImageReceipt(array $receipt, array $row, array $post, array $upload, string $mid): bool
    {
        if (($row['external_message_id'] ?? null) !== $mid || ($receipt['message_id'] ?? null) !== $mid
            || ($receipt['request_sha256'] ?? null) !== $row['payload_sha256']
            || ($receipt['source_sha256'] ?? null) !== $post['image']['sha256']
            || ($receipt['upload_token_sha256'] ?? null) !== hash('sha256', $upload['token'])
            || self::nativePhotoId($receipt['photo_id'] ?? null) === ''
            || !in_array($receipt['kind'] ?? '', ['create_response','exact_upload_token','reviewed_legacy_native_image'], true)) return false;
        if ($receipt['kind'] !== 'reviewed_legacy_native_image') return ($receipt['evidence_sha256'] ?? null) === '';
        $path = __DIR__.'/../resources/appleservice39/pilot/reconciliation/first-create.json';
        return is_file($path) && !is_link($path) && ($receipt['evidence_sha256'] ?? null) === hash_file('sha256', $path);
    }

    /** Missing canonical evidence is never filled from a merely similar post or a new MID. */
    private function readbackImageReceipt(array $row, array $post, array $message): ?array
    {
        $upload = $this->upload($post); $mid = (string)$row['external_message_id'];
        $payload = $this->imagePayload($message); $photoId = self::nativePhotoId($payload['photo_id'] ?? null);
        if ($photoId === '' || ($row['request']['body'] ?? null) !== $this->manifest->body($post, $upload)) return null;
        if (is_string($payload['token'] ?? null) && hash_equals($upload['token'], $payload['token'])) {
            return $this->imageReceipt($row, $post, $mid, $photoId, 'exact_upload_token');
        }
        if ($post['id'] !== 'repair-iphone-x-battery-replacement' || $mid !== 'mid.ffffb667d20a157101a1bd1d06ec5ef9'
            || $this->channelId !== '-80917954947727') return null;
        $directory = __DIR__.'/../resources/appleservice39/pilot/reconciliation';
        $path = $directory.'/first-create.json'; $preview = $directory.'/first-create-preview.jpg';
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 16384
            && is_file($preview) && !is_link($preview) && filesize($preview) <= 1048576, 'reviewed_image_evidence_unavailable');
        $proof = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        self::check(is_array($proof) && ($proof['schema'] ?? null) === 1
            && ($proof['campaign'] ?? null) === AppleService39PilotManifest::CAMPAIGN
            && ($proof['project_key'] ?? null) === AppleService39PilotManifest::PROJECT
            && ($proof['bot_key'] ?? null) === AppleService39PilotManifest::BOT
            && ($proof['channel_id'] ?? null) === $this->channelId
            && ($proof['manifest_sha256'] ?? null) === $this->manifest->sha256()
            && ($proof['post_id'] ?? null) === $post['id'] && ($proof['message_id'] ?? null) === $mid
            && ($proof['native_photo_id'] ?? null) === $photoId
            && ($proof['public_url'] ?? null) === ($message['url'] ?? null)
            && ($proof['source_image_sha256'] ?? null) === $post['image']['sha256']
            && ($proof['provider_preview_sha256'] ?? null) === hash_file('sha256', $preview),
            'reviewed_image_evidence_mismatch');
        $size = @getimagesize($preview);
        self::check(is_array($size) && $size[0] === 615 && $size[1] === 768 && $size[2] === IMAGETYPE_JPEG,
            'reviewed_image_evidence_mismatch');
        return $this->imageReceipt($row, $post, $mid, $photoId, 'reviewed_legacy_native_image', hash_file('sha256', $path));
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
            if ($current && $current['state'] === 'write_started') $this->store->finish($current, 'unknown', ['reason'=>$reason]);
        } catch (Throwable $ignored) { /* The committed write_started claim still holds. */ }
    }

    /** Bounded predicate evidence only: no provider body, token, text or foreign URL. */
    private function readbackDiagnostic(array $row, ?array $response, ?array $message, Throwable $error): array
    {
        $reasons = ['provider_read_unknown','post_readback_mismatch','post_attachment_mismatch',
            'post_image_mismatch','post_button_mismatch','native_post_url_required','native_image_receipt_required',
            'reviewed_image_evidence_unavailable','reviewed_image_evidence_mismatch'];
        $reason = in_array($error->getMessage(), $reasons, true) ? $error->getMessage() : 'readback_unclassified';
        $url = $message['url'] ?? null; $parts = is_string($url) ? parse_url($url) : false;
        $safeUrl = $parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'max.ru'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['query']) && !isset($parts['fragment'])
            && (preg_match('~\\A/channel_appleservice39ru/[A-Za-z0-9_.-]{1,191}\\z~D', (string)($parts['path'] ?? ''))
                || preg_match('~\\A/c/(-?[1-9][0-9]{0,18})/[A-Za-z0-9_.-]{1,191}\\z~D', (string)($parts['path'] ?? ''), $match)
                    && ltrim($match[1], '-') === ltrim($this->channelId, '-'));
        $upload = $this->upload($this->manifest->post($row['post_key']));
        $images = array_values(array_filter((array)($message['body']['attachments'] ?? []),
            static fn($item): bool => is_array($item) && ($item['type'] ?? null) === 'image'));
        $payload = (array)($images[0]['payload'] ?? []); $photoId = $payload['photo_id'] ?? null;
        $safePhotoId = (is_string($photoId) || is_int($photoId))
            && preg_match('/\\A-?(?:0|[1-9][0-9]{0,19})\\z/D', (string)$photoId) === 1 ? (string)$photoId : null;
        return ['post_id'=>$row['post_key'],'operation'=>$row['operation'],'message_id'=>$row['external_message_id'],
            'reason'=>$reason,'http'=>(int)($response['http'] ?? 0),'transport_error'=>!empty($response['transport_error']),
            'message_envelope'=>is_array($response['data']['message'] ?? null),
            'identity_matches'=>$message !== null && $this->messageIdentity($message),
            'mid_matches'=>($message['body']['mid'] ?? null) === $row['external_message_id'],
            'text_matches'=>($message['body']['text'] ?? null) === ($row['request']['body']['text'] ?? null),
            'url_present'=>is_string($url) && $url !== '',
            'url_matches_policy'=>is_string($url) && self::publicUrl($url, $this->channelId),
            'observed_own_url'=>$safeUrl ? $url : null,
            'image_readback'=>['count'=>count($images),'upload_photo_id'=>$upload['photo_id'],'native_photo_id'=>$safePhotoId,
                'photo_id_matches'=>$safePhotoId !== null && $safePhotoId === $upload['photo_id'],
                'upload_token_matches'=>is_string($payload['token'] ?? null) && hash_equals($upload['token'], $payload['token'])]];
    }

    private function report(string $state): array
    {
        $rows = []; $held = 0;
        foreach ($this->store->rows() as $row) {
            if (in_array($row['state'], ['write_started','unknown','rejected'], true)) $held++;
            $rows[] = ['post_id'=>$row['post_key'],'operation'=>$row['operation'],'state'=>$row['state'],
                'write_attempts'=>(int)$row['write_attempts'],'message_id'=>$row['external_message_id'],
                'url'=>$row['public_url'] !== '' ? $row['public_url'] : null,
                'image_receipt_kind'=>$row['outcome']['image_receipt']['kind'] ?? null,
                'native_photo_id'=>$row['outcome']['image_receipt']['photo_id'] ?? null,
                'button_object_key_order_normalized'=>$row['outcome']['button_object_key_order_normalized'] ?? null];
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
