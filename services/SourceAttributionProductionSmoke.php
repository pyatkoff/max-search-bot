<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/TrafficAttributionService.php';
require_once __DIR__ . '/TelegramStartSourceResolver.php';

final class SourceAttributionProductionSmoke
{
    private const MAX_KEYS=['max_anytour_msk','max_anytour_msk1','max_anytour_spb'];
    private const TG_KEYS=['tg_anytour_msk','tg_anytour_msk2'];

    public static function collect(): array
    {
        $pdo=ConversationDb::connection(); $project=ProjectConfig::projectId(); $checks=[];

        foreach(self::MAX_KEYS as $key){
            $parsed=TrafficAttributionService::parseStartPayload($key);
            $resolved=(string)($parsed['entry_channel']??'');
            $checks['max_parse_'.$key]=$resolved===$key;
            $checks['max_source_'.$key]=self::sourceExists($pdo,$project,$key,'max');
        }

        foreach(self::TG_KEYS as $key){
            $resolved=TelegramStartSourceResolver::resolve(
                ['text'=>'/start '.$key],
                static function(string $candidate) use($pdo,$project): string {
                    return self::sourceExists($pdo,$project,$candidate,'telegram') ? $candidate : '';
                }
            );
            $checks['tg_resolve_'.$key]=$resolved===$key;
            $checks['tg_source_'.$key]=self::sourceExists($pdo,$project,$key,'telegram');
        }

        return ['ok'=>!in_array(false,$checks,true),'checks'=>$checks];
    }

    private static function sourceExists(PDO $pdo,string $project,string $sourceKey,string $channel): bool
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.source_key=? AND s.channel=? AND s.is_active=1');
        $q->execute([$project,$sourceKey,$channel]);
        return (int)$q->fetchColumn()>0;
    }
}
