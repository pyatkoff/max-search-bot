<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/RoutingAccessService.php';

class ConversationAttributionService
{
    public static function syncByChat(string $platform, $chatId): void
    {
        if ($platform !== 'max' || !class_exists('MaxSearchApi') || !ConversationDb::isConfigured()) return;
        try {
            $meta=(array)MaxSearchApi::getTrafficMeta($chatId);
            $entry=trim((string)($meta['entry_channel']??''));
            $region=trim((string)($meta['region_id']??''));
            $campaign=trim((string)($meta['campaign_id']??''));
            if($entry===''&&$region===''&&$campaign==='')return;
            $project=ProjectConfig::projectId();
            $sourceId=$entry!=='' ? RoutingAccessService::sourceId($project,$entry,$platform) : 0;
            $q=ConversationDb::connection()->prepare('UPDATE conversations SET entry_channel=COALESCE(NULLIF(?,\'\'),entry_channel), attribution_region=COALESCE(NULLIF(?,\'\'),attribution_region), attribution_campaign=COALESCE(NULLIF(?,\'\'),attribution_campaign), source_id=CASE WHEN ?>0 THEN ? ELSE source_id END WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>\'closed\' ORDER BY id DESC LIMIT 1');
            $q->execute([$entry,$region,$campaign,$sourceId,$sourceId,$project,$platform,(string)$chatId]);
        } catch (Throwable $ignored) {}
    }
}
