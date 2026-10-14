<?php
declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ChannelPostRegistryService.php';
require_once __DIR__ . '/ChannelPostButtonTransport.php';

/** One-time, project-scoped channel setup. No source/routing/secret mutations. */
final class ChannelPostAdminService
{
    private PDO $pdo;
    private ChannelPostRegistryService $registry;
    private array $identities;
    private $verify;

    public function __construct(?PDO $pdo = null, ?callable $verify = null, array $identities = [])
    {
        $this->pdo = $pdo ?? ConversationDb::connection();
        $this->registry = new ChannelPostRegistryService($this->pdo);
        $this->identities = $identities;
        $this->verify = $verify ?? [new ChannelPostButtonTransport(), 'verifyChannel'];
    }

    public function snapshot(int $actor): array
    {
        if ($error = $this->authorize($actor)) return $error;
        $q = $this->pdo->prepare('SELECT p.id,p.project_key,p.display_name FROM projects p JOIN manager_projects mp ON mp.project_id=p.id WHERE mp.manager_id=? AND p.is_active=1 ORDER BY p.display_name,p.id LIMIT 101');
        $q->execute([$actor]);
        $projects = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $project) {
            $bots = [];
            foreach ($this->identities as $platform => $identity) {
                if ($this->validIdentity($platform, $identity) && $identity['project_key'] === $project['project_key']) $bots[$platform] = $identity;
            }
            if ($bots) $projects[] = ['id'=>(int)$project['id'],'project_key'=>(string)$project['project_key'],'display_name'=>(string)$project['display_name'],'bots'=>$bots];
        }
        $sources = []; $channels = [];
        foreach (array_slice($projects, 0, 100) as $project) {
            $q = $this->pdo->prepare("SELECT id,source_key,display_name,channel,handling_mode FROM conversation_sources WHERE project_id=? AND is_active=1 AND handling_mode IN ('manager','ask') ORDER BY display_name,id LIMIT 501");
            $q->execute([$project['id']]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 500) return ['ok'=>false,'error'=>'catalog_limit'];
            foreach ($rows as $source) {
                if (!isset($project['bots'][$source['channel']])) continue;
                $sources[] = ['id'=>(int)$source['id'],'project_key'=>$project['project_key'],'source_key'=>(string)$source['source_key'],'display_name'=>(string)$source['display_name'],'platform'=>(string)$source['channel'],'handling_mode'=>(string)$source['handling_mode']];
            }
            foreach ($this->registry->listChannels($project['project_key']) as $row) {
                $identity = $project['bots'][$row['platform']] ?? null;
                if (!$identity || $row['bot_key'] !== $identity['bot_key']) continue;
                $channels[] = $this->view($row);
            }
        }
        if (count($projects) > 100) return ['ok'=>false,'error'=>'catalog_limit'];
        return ['ok'=>true,'projects'=>$projects,'sources'=>$sources,'channels'=>$channels];
    }

    public function save(int $actor, array $input): array
    {
        if ($error = $this->authorize($actor)) return $error;
        foreach (['project_key','platform','channel_id','source_key','display_name','button_text'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key])) return ['ok'=>false,'error'=>'invalid_'.$key];
        }
        if (!isset($input['is_enabled']) || !is_bool($input['is_enabled'])) return ['ok'=>false,'error'=>'invalid_enabled'];
        if (!isset($input['expected_version']) || !is_int($input['expected_version']) || $input['expected_version'] < 0) return ['ok'=>false,'error'=>'invalid_version'];
        $project = $input['project_key']; $platform = $input['platform'];
        $identity = $this->identities[$platform] ?? null;
        if (!$this->validIdentity($platform, $identity) || $identity['project_key'] !== $project || !$this->canAccess($actor, $project)) return ['ok'=>false,'error'=>'forbidden'];
        try {
            $channelId = ChannelPostRegistryService::canonicalChannelId($platform, $input['channel_id']);
        } catch (InvalidArgumentException $e) { return ['ok'=>false,'error'=>'invalid_channel_id']; }
        foreach (['display_name'=>191,'button_text'=>80] as $field=>$max) {
            $input[$field] = trim($input[$field]);
            $length = function_exists('mb_strlen') ? mb_strlen($input[$field], 'UTF-8') : strlen($input[$field]);
            if ($input[$field] === '' || $length > $max || preg_match('/[\x00-\x1f\x7f]/u', $input[$field])) return ['ok'=>false,'error'=>'invalid_'.$field];
        }
        // Exact active source identity, platform and project. Never interpret a
        // source prefix or silently switch its manager/AI handling policy.
        $q = $this->pdo->prepare("SELECT s.id,s.source_key,s.channel FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND p.is_active=1 AND s.is_active=1 AND s.handling_mode IN ('manager','ask') AND s.source_key=? AND s.channel=? LIMIT 1");
        $q->execute([$project,$input['source_key'],$platform]); $source = $q->fetch(PDO::FETCH_ASSOC);
        if (!$source || $source['source_key'] !== $input['source_key'] || $source['channel'] !== $platform) return ['ok'=>false,'error'=>'active_platform_source_required'];
        $current = $this->registry->channel($project, $platform, $identity['bot_key'], $channelId, false);
        if (($current === null && $input['expected_version'] !== 0) || ($current !== null && (int)$current['config_version'] !== $input['expected_version'])) return ['ok'=>false,'error'=>'channel_configuration_conflict'];
        $configuration = [
            'project_key'=>$project,'platform'=>$platform,'channel_id'=>$channelId,
            'source_key'=>$input['source_key'],'display_name'=>$input['display_name'],
            'button_text'=>$input['button_text'],'is_enabled'=>$input['is_enabled'],
            'expected_version'=>$input['expected_version'],'bot_key'=>$identity['bot_key'],
            'canonical_bot_username'=>$identity['bot_key'],'bot_url'=>$identity['bot_url'],
        ];
        if ($input['is_enabled']) {
            try { $verification = ($this->verify)($configuration); }
            catch (Throwable $e) { $verification = ['state'=>'unknown','reason'=>'provider_read_failed']; }
            if (($verification['state'] ?? '') !== 'ready') {
                // No configuration write was attempted. Draft remains browser-owned.
                $reason = (string)($verification['reason'] ?? 'channel_verification_failed');
                if (!preg_match('/\A[a-zA-Z0-9_-]{1,96}\z/', $reason)) $reason = 'channel_verification_failed';
                return ['ok'=>false,'error'=>'channel_verification_failed','verification'=>[
                    'state'=>($verification['state'] ?? '') === 'unknown' ? 'unknown' : 'requires_attention','reason'=>$reason,
                ]];
            }
        }
        // A rights check may take time: check the actor and project once again
        // before the registry's transactional config-version compare-and-swap.
        if ($error = $this->authorize($actor)) return $error;
        if (!$this->canAccess($actor, $project)) return ['ok'=>false,'error'=>'forbidden'];
        try { $row = $this->registry->configureChannel($configuration); }
        catch (InvalidArgumentException $e) {
            $allowed = ['channel_configuration_conflict','channel_identity_conflict','published_channel_source_immutable','active_platform_source_required','manager_source_required'];
            return ['ok'=>false,'error'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'invalid_configuration'];
        }
        return ['ok'=>true,'channel'=>$this->view($row)];
    }

    private function authorize(int $actor): ?array
    {
        if ($actor <= 0) return ['ok'=>false,'error'=>'unauthorized'];
        $q = $this->pdo->prepare('SELECT role,is_active FROM managers WHERE id=? LIMIT 1'); $q->execute([$actor]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_active'] !== 1) return ['ok'=>false,'error'=>'unauthorized'];
        return $row['role'] === 'admin' ? null : ['ok'=>false,'error'=>'forbidden'];
    }

    private function canAccess(int $actor, string $project): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM manager_projects mp JOIN projects p ON p.id=mp.project_id WHERE mp.manager_id=? AND p.project_key=? AND p.is_active=1 LIMIT 1'); $q->execute([$actor,$project]);
        return (bool)$q->fetchColumn();
    }

    private function validIdentity(string $platform, $identity): bool
    {
        if (!in_array($platform,['max','telegram'],true) || !is_array($identity)) return false;
        $bot = $identity['bot_key'] ?? '';
        return is_string($bot) && preg_match('/\A[A-Za-z0-9_]{1,191}\z/', $bot) === 1
            && is_string($identity['project_key'] ?? null)
            && ($identity['platform'] ?? '') === $platform
            && ($identity['bot_url'] ?? '') === ($platform === 'max' ? 'https://max.ru/' : 'https://t.me/').$bot;
    }

    private function view(array $row): array
    {
        $counts = $row['state_counts'] ?? $row['publication_states'] ?? null;
        if (!is_array($counts)) {
            $q = $this->pdo->prepare('SELECT v.state,COUNT(*) AS total FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id WHERE pp.post_channel_id=? GROUP BY v.state');
            $q->execute([(int)$row['id']]); $counts = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $count) $counts[(string)$count['state']] = (int)$count['total'];
        }
        $publicationCount = (int)($row['publication_count'] ?? array_sum($counts));
        $attention = (int)($counts['requires_attention'] ?? 0) + (int)($counts['unknown'] ?? 0);
        if ((int)($row['source_active'] ?? 1) !== 1 || (int)($row['project_active'] ?? 1) !== 1) $attention++;
        if (isset($row['source_handling_mode']) && !in_array($row['source_handling_mode'],['manager','ask'],true)) $attention++;
        $pending = (int)($counts['pending'] ?? 0) + (int)($counts['claimed'] ?? 0) + (int)($counts['write_started'] ?? 0);
        $status = !(bool)$row['is_enabled'] ? 'disabled' : ($attention > 0 ? 'attention' : ($publicationCount > 0 && $pending === 0 ? 'ready' : 'waiting'));
        return ['id'=>(int)$row['id'],'project_key'=>(string)$row['project_key'],'platform'=>(string)$row['platform'],
            'channel_id'=>(string)$row['channel_id'],'source_id'=>(int)$row['source_id'],'source_key'=>(string)$row['source_key'],
            'display_name'=>(string)$row['display_name'],'button_text'=>(string)$row['button_text'],'is_enabled'=>(bool)$row['is_enabled'],
            'bot_url'=>(string)$row['bot_url'],'config_version'=>(int)$row['config_version'],'status'=>$status,
            'publication_count'=>$publicationCount,'attention_count'=>$attention];
    }
}
