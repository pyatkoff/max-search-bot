<?php
declare(strict_types=1);

require_once __DIR__.'/ChannelPostRuntime.php';
require_once __DIR__.'/ChannelPostRegistryService.php';

/** Adds an immutable publication reference after canonical conversation ownership. */
final class ChannelPostEntryService
{
    public static function isPayload(string $payload): bool
    {
        return str_starts_with($payload,'p_');
    }

    public static function telegramPayload(array $incoming): string
    {
        $text=trim((string)($incoming['text']??''));
        if (!preg_match('~\A/start(?:@([A-Za-z0-9_]+))?\s+(p_\S*)\s*\z~u',$text,$m)) return '';
        $identity=ChannelPostRuntime::identity('telegram');
        if (!empty($m[1])&&(!$identity||strcasecmp($m[1],$identity['bot_key'])!==0)) return '';
        return $m[2];
    }

    public static function resolve(string $platform,string $payload,?ChannelPostRegistryService $registry=null): ?array
    {
        $identity=ChannelPostRuntime::identity($platform);
        if (!$identity||!self::isPayload($payload)||(!$registry&&!ConversationDb::isConfigured())) return null;
        try {
            $resolved=($registry??new ChannelPostRegistryService())->resolveToken($identity['project_key'],$platform,$identity['bot_key'],$payload);
            if (!$resolved) return null;
            // A publication cannot turn an AI-only/retired source into a manager route.
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare('SELECT handling_mode FROM conversation_sources WHERE id=? AND is_active=1 AND channel=? LIMIT 1');
            $q->execute([(int)$resolved['source_id'],$platform]);
            if (!in_array((string)$q->fetchColumn(),['manager','ask'],true)) return null;
            return $resolved;
        } catch (Throwable $ignored) {return null;}
    }

    public static function eventKey(string $platform,array $update): string
    {
        if ($platform==='telegram') $parts=[$platform,ProjectConfig::projectId(),$update['update_id']??null,$update['message']['chat']['id']??null,$update['message']['message_id']??null];
        else $parts=[$platform,ProjectConfig::projectId(),$update['timestamp']??null,$update['user']['user_id']??$update['user']['id']??null,$update['payload']??$update['start_payload']??''];
        // A missing update identity must not globally deduplicate unrelated starts.
        if (($platform==='telegram'&&($update['update_id']??null)===null)||($platform==='max'&&($update['timestamp']??null)===null)) return '';
        return hash('sha256',json_encode($parts,JSON_UNESCAPED_SLASHES));
    }

    public static function conversation(string $platform,$chatId,?PDO $pdo=null): ?array
    {
        if (!$pdo&&!ConversationDb::isConfigured()) return null;
        try {
            $q=($pdo??ConversationDb::connection())->prepare("SELECT id,project_key,channel,status,manager_id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1");
            $q->execute([ProjectConfig::projectId(),$platform,(string)$chatId]);
            $row=$q->fetch();
            return is_array($row)?$row:null;
        } catch (Throwable $ignored) {return null;}
    }

    public static function managerOwned(string $platform,$chatId): bool
    {
        $c=self::conversation($platform,$chatId);
        return $c&&((int)($c['manager_id']??0)>0||in_array($c['status'],['manager','waiting_manager'],true));
    }

    /** True means an existing manager dialogue consumed this entry quietly. */
    public static function prepareTelegramEntry(array $incoming,?array $resolved): bool
    {
        $chatId=$incoming['user']['chat_id']??0;
        $conversation=self::conversation('telegram',$chatId);
        if (!$conversation) return false;
        $owned=(int)($conversation['manager_id']??0)>0||in_array($conversation['status'],['manager','waiting_manager'],true);
        if (!$resolved) return $owned;
        try {
            if ($owned) {
                $q=ConversationDb::connection()->prepare("UPDATE conversations SET entry_channel=? WHERE id=? AND project_key=? AND channel='telegram' AND status<>'closed'");
                $q->execute([$resolved['source_key'],(int)$conversation['id'],ProjectConfig::projectId()]);
                return true;
            }
            $q=ConversationDb::connection()->prepare("UPDATE conversations SET source_id=?,entry_channel=? WHERE id=? AND project_key=? AND channel='telegram' AND status='ai' AND (manager_id IS NULL OR manager_id=0)");
            $q->execute([(int)$resolved['source_id'],$resolved['source_key'],(int)$conversation['id'],ProjectConfig::projectId()]);
            // A concurrent manager claim keeps the canonical owner and receives
            // only the post entry, never another greeting or AI reset.
            return self::managerOwned('telegram',$chatId);
        } catch (Throwable $ignored) {return true;}
    }

    public static function record(string $platform,$chatId,?array $resolved,string $eventKey=''): ?array
    {
        $conversation=self::conversation($platform,$chatId);
        if (!$conversation) return null;
        try {
            $registry=new ChannelPostRegistryService();
            return $resolved
                ?$registry->recordEntry((int)$conversation['id'],$resolved,$eventKey)
                :$registry->recordUnavailableEntry((int)$conversation['id'],(string)$conversation['project_key'],$platform,$eventKey);
        } catch (Throwable $ignored) {return null;}
    }
}
