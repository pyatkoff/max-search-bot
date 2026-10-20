<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/services/ManagerCompanyKnowledge.php';
require_once dirname(__DIR__,2).'/services/ManagerSelectionSnapshot.php';

/** Synthetic dependencies for the actual ai-draft.php HTTP boundary only. */
function managerAiHttpFixtureDirectory(): string
{
    return (string) ($GLOBALS['manager_ai_http_fixture_directory'] ?? '');
}

function managerAiHttpFixtureState(): array
{
    $value = json_decode((string) file_get_contents(managerAiHttpFixtureDirectory().'/state.json'), true);
    if (!is_array($value)) throw new RuntimeException('Invalid synthetic fixture state');
    return $value;
}

function managerAiHttpFixtureEvent(string $event, array $data = []): void
{
    file_put_contents(managerAiHttpFixtureDirectory().'/events.jsonl', json_encode(['event'=>$event]+$data)."\n", FILE_APPEND|LOCK_EX);
}

final class ProjectAccessService
{
    public static function initializeReadOnly(): void
    {
        $GLOBALS['manager_ai_http_fixture_read_only'] = true;
        managerAiHttpFixtureEvent('initialize_read_only');
    }
}

final class ManagerAuthService
{
    public static function byId(int $id): ?array
    {
        managerAiHttpFixtureEvent('auth', ['manager_id'=>$id]);
        if (empty($GLOBALS['manager_ai_http_fixture_read_only'])) {
            throw new RuntimeException('Auth preceded read-only initialization');
        }
        $state = managerAiHttpFixtureState();
        return $id === 7 && $state['active'] ? ['id'=>7,'role'=>'manager'] : null;
    }

    public static function isAdmin(array $manager): bool
    {
        return false;
    }
}

final class ManagerConversationAccessPolicy
{
    public static function canEditVisibleConversation(array $conversation, array $manager): bool
    {
        return false;
    }
}

final class ManagerAiDraftContextService
{
    public static function snapshot(int $conversationId, int $managerId): array
    {
        // Re-read the backing file every time: a cached pre-provider snapshot
        // must not make the fresh revision or access checks pass accidentally.
        $state = managerAiHttpFixtureState();
        managerAiHttpFixtureEvent('snapshot', [
            'conversation_id'=>$conversationId,'manager_id'=>$managerId,
            'revision'=>$state['revision'],'latest_message_id'=>$state['latest_message_id'],
            'session_status'=>session_status(),
        ]);
        if ($conversationId === 404) return ['ok'=>false,'error'=>'not_found'];
        if (!$state['active']) return ['ok'=>false,'error'=>'unauthorized'];
        if (!$state['access']) return ['ok'=>false,'error'=>'forbidden'];
        if ($state['context_error'] !== '') return ['ok'=>false,'error'=>$state['context_error']];
        return [
            'ok'=>true,'revision'=>$state['revision'],'latest_message_id'=>$state['latest_message_id'],
            'company_knowledge'=>ManagerCompanyKnowledge::forProject('anytour', new DateTimeImmutable('2026-10-18T00:00:00Z')),
            'reference_context'=>(object)['synthetic_revision'=>$state['revision']],
            'photo_context'=>new class { public function read(array $context): array { return ['context'=>$context,'images'=>[['type'=>'input_image']]]; } },
            'context'=>['conversation_id'=>$conversationId,'as_of_message_id'=>$state['latest_message_id'],
                'history_incomplete'=>false,'media_unread'=>false,
                'messages'=>[['id'=>$state['latest_message_id'],'sender_type'=>'customer','text'=>'Нужен отдых на море.']]],
        ];
    }
}

final class ManagerPreparedReplyService
{
    public static function prepared(int $cid,int $actor,array $snapshot):array
    {
        managerAiHttpFixtureEvent('prepared_read',['conversation_id'=>$cid,'manager_id'=>$actor,
            'session_closed'=>session_status()!==PHP_SESSION_ACTIVE]);
        return managerAiHttpFixtureState()['prepared_result']??['ok'=>true,'prepared'=>false];
    }
}

final class ManagerAiDraftService
{
    public static function generate(array $context, ?callable $provider = null, ?ManagerCompanyKnowledge $knowledge = null, ?callable $selectionReader = null, ?object $references = null, ?callable $photoReader = null): array
    {
        $state = managerAiHttpFixtureState();
        $selection = $selectionReader ? $selectionReader()->forContext($context) : null;
        $photos = $photoReader ? $photoReader($context) : null;
        managerAiHttpFixtureEvent('provider', ['session_closed'=>session_status() !== PHP_SESSION_ACTIVE,
            'conversation_id'=>$context['conversation_id'],'as_of_message_id'=>$context['as_of_message_id'],
            'knowledge_ids'=>array_column($knowledge?->facts() ?? [], 'id'),
            'reference_revision'=>$references->synthetic_revision ?? null,
            'photo_reader_received'=>$photoReader!==null,'photo_count'=>count($photos['images']??[]),
            'selection_reader_received'=>$selectionReader!==null, 'selection_count'=>count($selection['selection_snapshots']??[])]);
        if ($state['provider_mode'] === 'throw') {
            throw new RuntimeException('SYNTHETIC_PROVIDER_DETAIL_MUST_STAY_PRIVATE');
        }
        if ($state['provider_mode'] === 'wait') {
            file_put_contents(managerAiHttpFixtureDirectory().'/provider-ready', 'ready');
            $deadline = microtime(true)+6;
            while (!is_file(managerAiHttpFixtureDirectory().'/provider-release')) {
                if (microtime(true) > $deadline) throw new RuntimeException('Synthetic provider barrier timed out');
                usleep(10000);
            }
        }
        return $state['proposal'];
    }
}

/** Isolated feedback dependency; the persistence service has its own SQL suite. */
final class ManagerAiDraftFeedbackService
{
    public static function record(int $cid,int $actor,array $snapshot,array $proposal,?string $preparedJobId=null): ?string
    {
        managerAiHttpFixtureEvent('feedback_record',['conversation_id'=>$cid,'manager_id'=>$actor,
            'revision'=>$snapshot['revision'],'latest_message_id'=>$snapshot['latest_message_id']]
            +($preparedJobId===null?[]:['preparation_job_id'=>$preparedJobId]));
        return (managerAiHttpFixtureState()['feedback_available'] ?? true) ? str_repeat('d',32) : null;
    }

    public static function markInsertRequested(int $cid,int $actor,string $id,string $revision,int $latest): ?string
    {
        managerAiHttpFixtureEvent('feedback_insert_request',['conversation_id'=>$cid,'manager_id'=>$actor,
            'draft_id'=>$id,'revision'=>$revision,'latest_message_id'=>$latest]);
        return (managerAiHttpFixtureState()['feedback_available'] ?? true) && $id===str_repeat('d',32) ? $id : null;
    }
}

/** Real loopback HTTP client, also used from an independent child process. */
function managerAiHttpFixtureRequest(string $address, string $path, array $data = [], string $cookie = '', string $method = 'POST'): array
{
    $headers = ['Content-Type: application/json'];
    if ($cookie !== '') $headers[] = 'Cookie: '.$cookie;
    $context = stream_context_create(['http'=>[
        'method'=>$method,'header'=>implode("\r\n", $headers),
        'content'=>$method === 'POST' ? json_encode($data) : '',
        'ignore_errors'=>true,'timeout'=>10,
    ]]);
    $body = (string) file_get_contents('http://'.$address.$path, false, $context);
    $headers = $http_response_header ?? [];
    preg_match('/^HTTP\/[^ ]+ ([0-9]+)/', (string) ($headers[0] ?? ''), $match);
    $responseCookie = '';
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i', $header, $cookieMatch)) $responseCookie = $cookieMatch[1];
    }
    return ['status'=>(int) ($match[1] ?? 0),'json'=>json_decode($body, true),
        'body'=>$body,'headers'=>$headers,'cookie'=>$responseCookie];
}

// These commands are executed only by the isolated test, never routed by the
// fixture web server. Session mutations use the real PHP file session handler.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'http-request') {
    $input = json_decode((string) stream_get_contents(STDIN), true);
    $result = managerAiHttpFixtureRequest($input['address'], '/manager/ai-draft.php', $input['data'], $input['cookie']);
    file_put_contents($input['result_path'], json_encode($result));
    exit;
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'mutate-session') {
    session_save_path($argv[2]);
    session_name('anytour_manager_panel');
    session_id($argv[3]);
    session_start();
    if ($argv[4] === 'logout') $_SESSION = [];
    elseif ($argv[4] === 'rotate_csrf') $_SESSION['csrf'] = 'rotated-synthetic-csrf';
    elseif ($argv[4] === 'change_account') $_SESSION['manager_id'] = 8;
    else throw new RuntimeException('Unknown synthetic session mutation');
    session_write_close();
    echo "session-mutated\n";
    exit;
}
