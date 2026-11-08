<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';

/** A tourist's explicit hotel choice, separate from attribution and trip needs. */
final class SourceHotelIntentService
{
    private const KEY = 'hotel_request_intent';

    public static function begin(int $conversationId, array $post): array
    {
        try {
            $row = self::row($conversationId);
            if (!self::writable($row) || ($post['platform'] ?? '') !== $row['channel']
                || (int)($post['source_id'] ?? 0) !== (int)$row['source_id']
                || (int)($post['publication_id'] ?? 0) < 1 || (int)($post['version_id'] ?? 0) < 1
                || !is_string($post['hotel_name'] ?? null) || trim($post['hotel_name']) === '') return [];
            $previous = self::state($row);
            // A later advert must never overwrite a choice already made for this trip.
            if ($previous && ($previous['choice'] !== 'pending'
                || ($previous['publication_id'] === (int)$post['publication_id']
                    && $previous['version_id'] === (int)$post['version_id']))) return $previous;
            $state = ['schema'=>1, 'conversation_id'=>$conversationId, 'platform'=>$row['channel'],
                'source_id'=>(int)$row['source_id'], 'publication_id'=>(int)$post['publication_id'],
                'version_id'=>(int)$post['version_id'], 'hotel_name'=>trim($post['hotel_name']),
                'choice'=>'pending', 'token'=>bin2hex(random_bytes(16)), 'prompted'=>false, 'needs_progressed'=>false];
            return self::save($row, $state) ? $state : [];
        } catch (Throwable $ignored) { return []; }
    }

    public static function current(int $conversationId): array
    {
        try { return self::state(self::row($conversationId)); }
        catch (Throwable $ignored) { return []; }
    }

    public static function prompted(int $conversationId, string $token): void
    {
        try {
            $row = self::row($conversationId); $state = self::state($row);
            if (!$state || $state['choice'] !== 'pending' || !hash_equals($state['token'], $token)
                || $state['prompted']) return;
            $state['prompted'] = true;
            self::save($row, $state);
        } catch (Throwable $ignored) {}
    }

    public static function callback(string $payload): ?array
    {
        if (preg_match('/\Ahotel_intent_(same|other):([a-f0-9]{32})\z/D', $payload, $m) !== 1) return null;
        return ['choice'=>$m[1], 'token'=>$m[2]];
    }

    public static function buttons(array $state): array
    {
        if (($state['choice'] ?? '') !== 'pending'
            || preg_match('/\A[a-f0-9]{32}\z/D', (string)($state['token'] ?? '')) !== 1) return [];
        return [[['text'=>'В этот отель', 'callback_data'=>'hotel_intent_same:'.$state['token']]],
            [['text'=>'Подобрать другой', 'callback_data'=>'hotel_intent_other:'.$state['token']]]];
    }

    /** One issued question can be answered once; replayed or foreign buttons are inert. */
    public static function choose(int $conversationId, array $callback, string $inputId = ''): array
    {
        try {
            $row = self::row($conversationId); $state = self::state($row);
            if (!$state || $state['choice'] !== 'pending' || !self::writable($row)
                || (int)$row['source_id'] !== $state['source_id']
                || !in_array($callback['choice'] ?? '', ['same','other'], true)
                || !is_string($callback['token'] ?? null) || !hash_equals($state['token'], $callback['token'])) return [];
            return self::apply($row, $state, $callback['choice'], $inputId);
        } catch (Throwable $ignored) { return []; }
    }

    /** Explicit words are accepted without a click. Other text stays with the need collector. */
    public static function fromText(int $conversationId, string $text, string $inputId = ''): array
    {
        try {
            $row = self::row($conversationId); $state = self::state($row);
            if (!$state || !self::writable($row)) return [];
            $choice = self::interpret($text, $state['hotel_name'], $state['choice'] === 'pending');
            if ($choice === null || $choice === $state['choice'] || $inputId === '') return [];
            // Recorder deduplication must not let an older text delivery undo a newer choice.
            $q = ConversationDb::connection()->prepare("SELECT id,text,metadata_json FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' AND external_message_id=? ORDER BY id DESC LIMIT 2");
            $q->execute([$conversationId, $inputId]); $rows = $q->fetchAll();
            if (count($rows) !== 1 || $rows[0]['text'] !== $text) return [];
            $input = $rows[0]; $meta = json_decode((string)$input['metadata_json'], true);
            if (!is_array($meta) || ($meta['type'] ?? '') !== 'message' || ($meta['linked_message']['type'] ?? '') === 'forward'
                || (int)$input['id'] <= (int)($state['input_message_id'] ?? 0)) return [];
            $q = ConversationDb::connection()->prepare("SELECT MAX(id) FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer'");
            $q->execute([$conversationId]);
            if ((int)$q->fetchColumn() !== (int)$input['id']) return [];
            $state['input_message_id'] = (int)$input['id'];
            return self::apply($row, $state, $choice, $inputId);
        } catch (Throwable $ignored) { return []; }
    }

    public static function interpret(string $text, string $hotelName, bool $pending): ?string
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 12000) return null;
        if (preg_match('/(*UCP)\b(?:друг(?:ой|ие)\s+(?:отел[ьи]|вариант[ы]?)\s+(?:(?:мне|нам)\s+)?не\s+(?:нуж|интерес|рассматрива|хоч)|не\s+(?:нужен|нужны|хочу|хотим|интересует|интересуют|рассматриваю|рассматриваем)\s+друг)/iu', $text) === 1) return null;
        if ($pending && preg_match('/\Aнет(?:\s*[,!.;]|\s*$)/iu', $text) === 1) return 'other';
        if (preg_match('/(*UCP)\b(?:не\s+(?:в\s+)?этот\s+отель|друг(?:ой|ие)\s+(?:отел[ьи]|вариант[ы]?)|по\s+(?:моим|своим|нашим)\s+пожеланиям|под\s+(?:мой|свой|наш)\s+запрос)\b/iu', $text) === 1) {
            return 'other';
        }
        if (preg_match('/(*UCP)\bэтот\s+отель\s+(?:мне\s+|нам\s+)?не\s+(?:подходит|подойд[её]т|нужен|интересует)/iu', $text) === 1) return 'other';
        if (preg_match('/(*UCP)\bне\s+|\?|\b(?:если|возможно|может)\b/iu', $text) === 1) return null;
        if (preg_match('/(*UCP)\A(?:да\s*[,!.]?\s*)?(?:в\s+)?этот\s+отель(?:\s*[,!.;]|\s+(?:на|для|из|с)\s+|\s*$)/iu', $text) === 1) return 'same';
        if (preg_match('/(*UCP)\A(?:да\s*[,!.]?\s*)?(?:хочу|хотим|нужен|интересует|рассчитайте|подберите)\s+(?:тур\s+)?(?:в\s+)?этот\s+отель\b/iu', $text) === 1) return 'same';
        if ($pending && preg_match('/\A(?:да|ага|конечно)(?:\s*[,!.;]|\s*$)/iu', $text) === 1) return 'same';
        if ($hotelName !== '' && preg_match('/(*UCP)\b(?:хочу|хотим|нужен|интересует|рассчитайте|подберите)\s+(?:тур\s+)?(?:в\s+)?'.preg_quote($hotelName, '/').'\b/iu', $text) === 1) return 'same';
        if (preg_match('/\A(?:хочу|хотим|нужен|интересует|рассчитайте|подберите)\s+(?:тур\s+)?(?:в\s+)?[A-Za-z][A-Za-z0-9 &\x27.-]{2,}/iu', $text) === 1) return 'other';
        return null;
    }

    public static function choiceOnly(string $text): bool
    {
        return preg_match('/\A\s*(?:да|нет|ага|конечно|(?:да\s*[,!]?[ ]*)?(?:(?:хочу|хотим)\s+)?(?:в\s+)?этот\s+отель|(?:подберите|подобрать|нужен)\s+другой(?:\s+отель)?|(?:под\s+)?(?:мой|свой|наш)\s+запрос)\s*[.!]?\s*\z/iu', $text) === 1;
    }

    /** A successful canonical need application belongs to this entry, not an older complete trip. */
    public static function needsProgressed(int $conversationId): void
    {
        try {
            $row = self::row($conversationId); $state = self::state($row);
            if (!$state || !empty($state['needs_progressed'])) return;
            $state['needs_progressed'] = true;
            self::save($row, $state);
        } catch (Throwable $ignored) {}
    }

    /** Safe read model for an already-authorized manager/AI context; no callback token. */
    public static function projection(int $conversationId): array
    {
        $state = self::current($conversationId);
        if (!$state) return [];
        return ['status'=>['same'=>'post_hotel','other'=>'custom_request','pending'=>'unconfirmed'][$state['choice']],
            'source_hotel_name'=>$state['hotel_name'], 'publication_id'=>$state['publication_id'],
            'version_id'=>$state['version_id'], 'label'=>self::label($state)];
    }

    private static function label(array $state): string
    {
        if ($state['choice'] === 'same') return 'Хочет тур в '.$state['hotel_name'];
        if ($state['choice'] === 'other') return 'Нужен подбор по пожеланиям';
        return 'Выбор отеля пока не подтверждён';
    }

    public static function clearForStart(string $platform, $chatId): void
    {
        if (ProjectConfig::projectId() !== 'anytour' || !in_array($platform, ['max','telegram'], true)) return;
        try {
            $q = ConversationDb::connection()->prepare("SELECT id FROM conversations WHERE project_key='anytour' AND channel=? AND external_chat_id=? AND status='ai' ORDER BY id DESC LIMIT 1");
            $q->execute([$platform, (string)$chatId]); $id = (int)$q->fetchColumn();
            if ($id > 0) { $row = self::row($id); if (self::state($row)) self::save($row, null); }
        } catch (Throwable $ignored) {}
    }

    private static function apply(array $row, array $state, string $choice, string $inputId): array
    {
        $state['choice'] = $choice;
        $state['token'] = bin2hex(random_bytes(16)); // invalidate the old question's buttons
        $state['input_id'] = substr($inputId, 0, 180);
        return self::save($row, $state) ? $state : [];
    }

    private static function row(int $id): array
    {
        if ($id < 1 || !ConversationDb::isConfigured()) return [];
        $q = ConversationDb::connection()->prepare('SELECT id,project_key,channel,status,manager_id,source_id,metadata_json FROM conversations WHERE id=? LIMIT 1');
        $q->execute([$id]); $row = $q->fetch();
        return is_array($row) && $row['project_key'] === 'anytour'
            && in_array($row['channel'], ['max','telegram'], true) ? $row : [];
    }

    private static function state(array $row): array
    {
        if (!$row) return [];
        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        $s = is_array($metadata) ? ($metadata[self::KEY] ?? null) : null;
        if (!is_array($s) || ($s['schema'] ?? null) !== 1 || ($s['conversation_id'] ?? null) !== (int)$row['id']
            || ($s['platform'] ?? null) !== $row['channel'] || !is_string($s['hotel_name'] ?? null)
            || trim($s['hotel_name']) === '' || strlen($s['hotel_name']) > 720
            || !in_array($s['choice'] ?? null, ['pending','same','other'], true)
            || !is_string($s['token'] ?? null) || preg_match('/\A[a-f0-9]{32}\z/D', $s['token']) !== 1
            || !is_bool($s['prompted'] ?? null)) return [];
        foreach (['source_id','publication_id','version_id'] as $key) if (!is_int($s[$key] ?? null) || $s[$key] < 1) return [];
        return $s;
    }

    private static function writable(array $row): bool
    {
        return ProjectConfig::projectId() === 'anytour' && $row && ($row['project_key'] ?? '') === 'anytour' && ($row['status'] ?? '') === 'ai'
            && (int)($row['manager_id'] ?? 0) === 0;
    }

    /** Preserve unrelated metadata and refuse a concurrent claim, source change or write. */
    private static function save(array $row, ?array $state): bool
    {
        if (!self::writable($row)) return false;
        $raw = (string)($row['metadata_json'] ?? '');
        $metadata = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) return false;
        if ($state === null) unset($metadata[self::KEY]); else $metadata[self::KEY] = $state;
        $json = json_encode((object)$metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $q = ConversationDb::connection()->prepare("UPDATE conversations SET metadata_json=? WHERE id=? AND project_key='anytour' AND channel=? AND source_id=? AND status='ai' AND (manager_id IS NULL OR manager_id=0) AND COALESCE(metadata_json,'')=?");
        $q->execute([$json, (int)$row['id'], $row['channel'], (int)$row['source_id'], $raw]);
        return $q->rowCount() === 1;
    }
}
