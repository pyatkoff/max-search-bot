<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';
require_once __DIR__ . '/MaxChannelConversionOutbox.php';
require_once __DIR__ . '/AdvertisingSignalService.php';

/**
 * Conversation-aware owner for higher-funnel Yandex Metrika conversion goals.
 *
 * Business rules live here; transport remains MaxSearchApi::queueMetrikaGoal().
 * All writes are best-effort and must never block manager/customer flows.
 */
class MetrikaConversionGoalService
{
    /** Keep local source evidence even when the legacy advertising queue cannot match it. */
    public static function observeIncoming(array $incoming): void
    {
        AdvertisingSignalService::incoming($incoming);
    }
    private const CUSTOMER_ACTIVITY_GOAL = 'max_bot_activity_start';
    private const BOT_PLATFORMS = ['max','telegram'];
    private const CUSTOMER_ACTIVITY_TYPES = ['message','callback','contact'];

    private const STAGE_GOALS = [
        'working' => 'max_lead_working',
        'offer_sent' => 'max_offer_sent',
        'booking' => 'max_booking',
        'won' => 'max_sale',
    ];

    public static function stageGoal(string $stageKey): ?string
    {
        $stageKey = trim($stageKey);
        return self::STAGE_GOALS[$stageKey] ?? null;
    }

    public static function isCustomerActivity(string $platform, string $type): bool
    {
        return in_array(strtolower(trim($platform)), self::BOT_PLATFORMS, true)
            && in_array(strtolower(trim($type)), self::CUSTOMER_ACTIVITY_TYPES, true);
    }

    public static function customerActivity(string $platform, $chatId, string $type, ?callable $queue = null): bool
    {
        if (!self::isCustomerActivity($platform, $type)) return false;
        $conversationId = 0;
        try {
            $conversationId = self::conversationIdByChat($platform, $chatId);
            if ($conversationId <= 0) return false;
            return self::emitOnce($conversationId, self::CUSTOMER_ACTIVITY_GOAL, $queue);
        } catch (Throwable $e) {
            self::logFailure('customer_activity', $conversationId, $e, [
                'platform'=>strtolower(trim($platform)),
                'type'=>strtolower(trim($type)),
            ]);
            return false;
        }
    }

    public static function managerReply(int $conversationId, ?callable $queue = null): bool
    {
        try {
            if ($conversationId <= 0 || !self::hasEvent($conversationId, 'waiting_manager') || !self::hasEvent($conversationId, 'manager_message')) return false;
            if(self::managedChannelConversation($conversationId))return self::managerRequest($conversationId,$queue);
            return self::emitOnce($conversationId, 'max_manager_reply', $queue);
        } catch (Throwable $e) {
            self::logFailure('manager_reply', $conversationId, $e);
            return false;
        }
    }

    /** The persisted handoff, including direct/night entries, owns this stage. */
    public static function managerRequest(int $conversationId, ?callable $queue = null): bool
    {
        AdvertisingSignalService::request($conversationId);
        try{
            $managed=self::managedChannelConversation($conversationId);
            if(!$managed||!MaxChannelConversionOutbox::sharedGoalsActive($managed['settings']))return false;
            if(!$managed['repository']->event($managed['conversation'],'max_manager_request',$managed['settings']))return false;
            if(class_exists('MaxSearchApi')&&method_exists('MaxSearchApi','metrikaGoalAllowed')&&!MaxSearchApi::metrikaGoalAllowed(self::chatId($conversationId)))return false;
            return self::emitOnce($conversationId,'max_manager_request',$queue);
        }catch(Throwable $e){self::logFailure('manager_request',$conversationId,$e);return false;}
    }

    public static function customerReplyAfterManager(int $conversationId, ?callable $queue = null): bool
    {
        try {
            if ($conversationId <= 0 || !self::hasEvent($conversationId, 'waiting_manager') || !self::hasEvent($conversationId, 'manager_message')) return false;
            // Safely recover the earlier stage before the deeper goal. Managed
            // MAX conversations capture their actual request, not a reply alias.
            self::managerReply($conversationId, $queue);
            return self::emitOnce($conversationId, 'max_customer_reply_after_manager', $queue);
        } catch (Throwable $e) {
            self::logFailure('customer_reply_after_manager', $conversationId, $e);
            return false;
        }
    }

    public static function salesStage(int $conversationId, string $stageKey, ?callable $queue = null): bool
    {
        $goal = self::stageGoal($stageKey);
        if ($goal === null || $conversationId <= 0) return false;
        try {
            return self::emitOnce($conversationId, $goal, $queue);
        } catch (Throwable $e) {
            self::logFailure('sales_stage', $conversationId, $e, ['stage_key'=>$stageKey,'target'=>$goal]);
            return false;
        }
    }

    public static function saleOutcome(int $conversationId, string $outcome, ?callable $queue = null): bool
    {
        if (trim($outcome) !== 'won' || $conversationId <= 0) return false;
        try {
            return self::emitOnce($conversationId, 'max_sale', $queue);
        } catch (Throwable $e) {
            self::logFailure('sale_outcome', $conversationId, $e);
            return false;
        }
    }

    /** Background recovery from recorded MAX messages, scoped by the outbox. */
    public static function reconcileChannelConversation(int $conversationId): void
    {
        $settings=MaxChannelConversionOutbox::settings();
        if(!$settings||$settings['mode']==='off'||!MaxChannelConversionOutbox::sharedGoalsActive($settings))return;
        $chat=self::chatId($conversationId);
        $repository=new MaxChannelConversionOutbox(ConversationDb::connection());
        $conversation=$repository->conversation((string)$conversationId,$chat,$settings);
        if(!$conversation)return;
        if(class_exists('MaxSearchApi')&&method_exists('MaxSearchApi','metrikaGoalAllowed')&&!MaxSearchApi::metrikaGoalAllowed($chat))return;
        foreach(MaxChannelConversionOutbox::TARGETS as $target){
            if(self::hasEvent($conversationId,self::marker($target)))continue;
            if($target!=='max_bot_activity_start'&&!self::hasEvent($conversationId,'waiting_manager'))continue;
            if($target==='max_customer_reply_after_manager'&&!self::hasEvent($conversationId,'manager_message'))continue;
            if(!$repository->event($conversation,$target,$settings))continue;
            if(!self::emitOnce($conversationId,$target,null))throw new RuntimeException('channel_stage_capture_failed');
        }
    }

    private static function managedChannelConversation(int $conversationId): ?array
    {
        if($conversationId<=0)return null;
        $settings=MaxChannelConversionOutbox::settings();
        if(!$settings||$settings['mode']==='off')return null;
        $repository=new MaxChannelConversionOutbox(ConversationDb::connection());
        $conversation=$repository->conversation((string)$conversationId,self::chatId($conversationId),$settings);
        return $conversation?['repository'=>$repository,'conversation'=>$conversation,'settings'=>$settings]:null;
    }

    private static function emitOnce(int $conversationId, string $target, ?callable $queue): bool
    {
        $marker = self::marker($target);
        if (self::hasEvent($conversationId, $marker)) return true;
        $chatId = self::chatId($conversationId);
        if ($chatId === '') return false;

        $queued = $queue ? (bool)$queue($chatId, $target) : self::queue($chatId, $target, $conversationId);
        if (!$queued) return false;

        $payload = json_encode(['target'=>$target], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        ConversationDb::connection()->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json) VALUES (?,?,?,?)')
            ->execute([$conversationId,$marker,'system',$payload ?: null]);
        return true;
    }

    private static function queue(string $chatId, string $target, int $conversationId): bool
    {
        if (!class_exists('MaxSearchApi') || !method_exists('MaxSearchApi', 'queueMetrikaGoal')) return false;
        return (bool)MaxSearchApi::queueMetrikaGoal($chatId, $target, ['conversation_id'=>(string)$conversationId]);
    }

    private static function chatId(int $conversationId): string
    {
        $q = ConversationDb::connection()->prepare('SELECT external_chat_id FROM conversations WHERE id=? LIMIT 1');
        $q->execute([$conversationId]);
        return trim((string)($q->fetchColumn() ?: ''));
    }

    private static function conversationIdByChat(string $platform, $chatId): int
    {
        $q = ConversationDb::connection()->prepare('SELECT id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>? ORDER BY id DESC LIMIT 1');
        $q->execute([
            ProjectConfig::projectId(),
            strtolower(trim($platform)),
            trim((string)$chatId),
            'closed',
        ]);
        return (int)$q->fetchColumn();
    }

    private static function hasEvent(int $conversationId, string $eventType): bool
    {
        $q = ConversationDb::connection()->prepare('SELECT 1 FROM conversation_events WHERE conversation_id=? AND event_type=? LIMIT 1');
        $q->execute([$conversationId,$eventType]);
        return (bool)$q->fetchColumn();
    }

    private static function marker(string $target): string
    {
        return 'metrika_' . $target;
    }

    private static function logFailure(string $stage, int $conversationId, Throwable $e, array $extra = []): void
    {
        if (!class_exists('DiagnosticLogger')) return;
        try {
            DiagnosticLogger::log('metrika_conversion','queue_failed',$extra+['stage'=>$stage,'conversation_id'=>$conversationId,'error'=>$e->getMessage()],null,'warning');
        } catch (Throwable $ignored) {}
    }
}


