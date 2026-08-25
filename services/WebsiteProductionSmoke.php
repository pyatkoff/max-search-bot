<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/WebsiteSessionService.php';
require_once __DIR__ . '/../integrations/WebsiteIncomingAdapter.php';

class WebsiteProductionSmoke
{
    public static function evaluate(array $facts): array
    {
        $checks = [
            'schema_ok' => !empty($facts['schema_ok']),
            'source_ok' => !empty($facts['source_ok']),
            'adapter_ok' => !empty($facts['adapter_ok']),
            'handoff_evidence_ok' => !empty($facts['handoff_evidence_ok']),
        ];
        return ['ok' => !in_array(false, $checks, true), 'checks' => $checks];
    }

    public static function collect(): array
    {
        $pdo = ConversationDb::connection();
        $schemaOk = false;
        try {
            WebsiteSessionService::ensureSchema();
            $schemaOk = true;
        } catch (Throwable $e) {
            $schemaOk = false;
        }

        $source = $pdo->prepare('SELECT COUNT(*) FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.source_key=? AND s.channel=? AND s.is_active=1');
        $source->execute([ProjectConfig::projectId(), WebsiteSessionService::sourceKey(), 'website']);
        $sourceCount = (int)$source->fetchColumn();

        $sample = WebsiteIncomingAdapter::fromPayload(
            ['action'=>'start','message_id'=>'production-smoke'],
            'website_production_smoke',
            -1700000001
        );
        $adapterOk = is_array($sample)
            && ($sample['platform'] ?? '') === 'website'
            && ($sample['text'] ?? '') === '/start'
            && ($sample['user']['external_user_id'] ?? '') === 'website_production_smoke';

        $handoff = $pdo->prepare("SELECT COUNT(*) FROM conversations c JOIN conversation_sources s ON s.id=c.source_id JOIN projects p ON p.id=s.project_id WHERE c.channel='website' AND c.project_key=? AND p.project_key=? AND s.source_key=? AND (c.manager_id IS NOT NULL OR c.status IN ('waiting_manager','manager'))");
        $project = ProjectConfig::projectId();
        $handoff->execute([$project, $project, WebsiteSessionService::sourceKey()]);
        $handoffCount = (int)$handoff->fetchColumn();

        $facts = [
            'schema_ok' => $schemaOk,
            'source_ok' => $sourceCount > 0,
            'adapter_ok' => $adapterOk,
            'handoff_evidence_ok' => $handoffCount > 0,
            'source_count' => $sourceCount,
            'handoff_count' => $handoffCount,
        ];
        return array_merge($facts, self::evaluate($facts));
    }
}
