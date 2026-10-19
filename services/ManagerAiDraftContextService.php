<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ManagerAuthService.php';
require_once __DIR__.'/ManagerDeliveryStateService.php';
require_once __DIR__.'/ProjectAccessService.php';
require_once __DIR__.'/CallbackGeneration.php';
require_once __DIR__.'/ManagerCompanyKnowledge.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';
require_once __DIR__.'/ManagerSelectionLinks.php';
require_once __DIR__.'/ManagerAiDraftReferenceContext.php';

/** A bounded, read-only input snapshot. It neither generates nor sends a reply. */
final class ManagerAiDraftContextService
{
    public const MAX_MESSAGES = 120;
    public const MAX_TEXT_CHARACTERS = 40000;

    /**
     * Every call owns a new short snapshot. In particular, a post-generation
     * check must not reuse a caller's older REPEATABLE READ transaction.
     */
    public static function snapshot(int $conversationId, int $actor): array
    {
        if ($conversationId <= 0) return self::failure('invalid_conversation_id');
        if ($actor <= 0) return self::failure('unauthorized');
        $pdo = null;
        $started = false;
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return self::failure('context_unavailable');
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('SET TRANSACTION READ ONLY');
            } elseif ($driver !== 'sqlite') {
                return self::failure('context_unavailable');
            }
            $pdo->beginTransaction();
            $started = true;
            // This must precede auth: the legacy ensureSchema path UPSERTs the
            // current project. An unready project is refused, never repaired.
            ProjectAccessService::initializeReadOnly();
            $manager = ManagerAuthService::byId($actor);
            if (!$manager) return self::failure('unauthorized');
            $conversation = ManagerConversationService::visibleConversation($conversationId, $actor);
            if (!$conversation) return self::failure('not_found');
            // Admin edit permission is deliberately not reply ownership.
            if (($conversation['status'] ?? '') !== 'manager'
                || (int)($conversation['manager_id'] ?? 0) !== $actor) {
                return self::failure('reply_not_allowed');
            }
            if (($conversation['channel'] ?? '') === 'max'
                && ManagerDeliveryStateService::activeFailure($conversationId) !== null) {
                return self::failure('suspended');
            }
            $detail = ManagerConversationService::detail($conversationId, $actor, 0, self::MAX_MESSAGES, false);
            if (!$detail) return self::failure('not_found');
            $rows = $detail['messages'];
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            $metadata = [];
            if ($ids) {
                $q = $pdo->prepare('SELECT id,metadata_json FROM messages WHERE conversation_id=? AND id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY id');
                $q->execute(array_merge([$conversationId], $ids));
                foreach ($q->fetchAll() as $row) $metadata[(int)$row['id']] = (string)($row['metadata_json'] ?? '');
                if (count($metadata) !== count($ids)) return self::failure('context_unavailable');
            }
            $latest = $ids ? $ids[count($ids)-1] : 0;
            $projected = [];
            $source = [];
            $selectionSources = [];
            $metadataIncomplete = false;
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $raw = $metadata[$id];
                $meta = $raw !== '' ? json_decode($raw, true, 32) : [];
                $validMetadata = is_array($meta);
                if (!$validMetadata) { $meta = []; $metadataIncomplete = true; }
                $text = (string)($row['text'] ?? '');
                if (preg_match('//u', $text) !== 1) return self::failure('context_unavailable');
                $type = $validMetadata ? (string)($meta['type'] ?? 'message') : 'unknown';
                if (!in_array($type, ['message','callback','start','bot_started'], true)) $type = 'unknown';
                $sender = (string)($row['sender_type'] ?? '');
                if (!in_array($sender, ['customer','manager','ai','system'], true)) $sender = 'system';
                if ($type === 'message') $selectionSources[$id] = ManagerSelectionLinks::inMessage($text, $id, $sender, (string)($row['created_at'] ?? ''));
                $linkUnread = false;
                $linksRedacted = false;
                if ($type === 'callback') $text = self::callbackText($text);
                else $text = self::safeText($text, $linkUnread, $linksRedacted);
                $attachments = is_array($meta['attachments'] ?? null) ? $meta['attachments'] : [];
                // Reply/forward content is not silently substituted for the
                // speaker's own text; absent media/quotes remain unread evidence.
                $mediaUnread = $attachments !== [] || !empty($meta['linked_message']);
                $projected[] = ['id'=>$id, 'sender_type'=>$sender, 'text'=>$text,
                    'event_type'=>$type, 'created_at'=>(string)($row['created_at'] ?? ''),
                    'media_unread'=>$mediaUnread, 'links_redacted'=>$linksRedacted,
                    'selections_link_unread'=>$linkUnread];
                // Hash source edits even when redaction makes two projections
                // identical. Raw metadata/identities are never returned or logged.
                $source[] = [$id, (string)$row['direction'], (string)$row['sender_type'],
                    (string)$row['text'], (string)$row['created_at'], hash('sha256', $raw)];
            }
            $incomplete = !empty($detail['history']['has_more']) || $metadataIncomplete;
            $messages = [];
            $remaining = self::MAX_TEXT_CHARACTERS;
            for ($i=count($projected)-1; $i>=0; --$i) {
                $message = $projected[$i];
                $length = self::length($message['text']);
                if ($length > $remaining) {
                    $incomplete = true;
                    if ($messages === []) {
                        $message['text'] = self::suffix($message['text'], $remaining);
                        $message['text_truncated'] = true;
                        $messages[] = $message;
                    }
                    break;
                }
                $messages[] = $message;
                $remaining -= $length;
            }
            $messages = array_reverse($messages);
            $selectionLinks = [];
            foreach ($messages as $message) if (empty($message['text_truncated'])) {
                array_push($selectionLinks, ...($selectionSources[$message['id']] ?? []));
            }
            $context = ['conversation_id'=>$conversationId, 'as_of_message_id'=>$latest,
                'messages'=>$messages, 'history_incomplete'=>$incomplete,
                'media_unread'=>in_array(true, array_column($messages, 'media_unread'), true),
                'selections_link_unread'=>in_array(true, array_column($messages, 'selections_link_unread'), true)];
            $knowledge = ManagerCompanyKnowledge::forProject((string)$conversation['project_key']);
            $references = ManagerAiDraftReferenceContext::capture($pdo,$conversation,$context);
            $revision = hash('sha256', json_encode([
                'schema'=>'manager_ai_draft_context_v2', 'actor'=>$actor,
                'role'=>(string)($manager['role'] ?? ''),
                'conversation'=>array_intersect_key($conversation, array_flip(['id','project_key','source_id','channel','status','manager_id'])),
                'source'=>$source, 'context'=>$context, 'company_knowledge'=>$knowledge->revision(), 'selection_context_schema'=>1,
                'reference_context'=>$references->revision(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $pdo->commit();
            $started = false;
            return ['ok'=>true, 'context'=>$context, 'revision'=>$revision, 'company_knowledge'=>$knowledge,
                'reference_context'=>$references,
                'selection_sources'=>array_slice($selectionLinks, -41),
                'latest_message_id'=>$latest, 'history_incomplete'=>$incomplete];
        } catch (Throwable $ignored) {
            // No SQL, source text, identity, metadata or provider payload in logs.
            return self::failure('context_unavailable');
        } finally {
            if ($started && $pdo instanceof PDO && $pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable $ignored) {}
            }
        }
    }

    private static function failure(string $error): array
    {
        return ['ok'=>false, 'error'=>$error];
    }

    private static function callbackText(string $text): string
    {
        $base = CallbackGeneration::base($text);
        $known = in_array($base, ['ai_start','search_options','start_search','manager_request',
            'manager_after_tours','phone_manual','show_tours','tours_checked','tours_found',
            'edit_params','restart','back_phone','back_check','pick_city_other','pick_country_other',
            'nights_other','back_pick_city','back_pick_country','finish'], true)
            || preg_match('/\A(?:pick_(?:city|country)_[1-9][0-9]{0,8}|adults_[1-6]|child_[0-3]|star_[1-5]|meal_(?:3|4|5|7|999)|nights_[0-9]{1,2}(?:_[0-9]{1,2})?|(?:edit|back)_(?:city|country|tourists|stars|meal|nights|date)|pick_date_[0-9]{2}\.[0-9]{2}\.[0-9]{4}|month_change_[0-9]{2}\.[0-9]{4})\z/D', $base) === 1;
        return $known ? 'Нажата кнопка: '.$base : 'Нажата кнопка; действие не распознано';
    }

    private static function safeText(string $text, bool &$selectionUnread, bool &$linksRedacted): string
    {
        return ManagerAiTextRedactor::redact($text, $selectionUnread, $linksRedacted);
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int)preg_match_all('/./us', $text);
    }

    private static function suffix(string $text, int $length): string
    {
        if ($length <= 0) return '';
        if (function_exists('mb_substr')) return mb_substr($text, -$length, null, 'UTF-8');
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_slice($characters ?: [], -$length));
    }
}
