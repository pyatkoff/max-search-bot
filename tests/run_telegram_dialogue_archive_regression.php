<?php
declare(strict_types=1);

define('TELEGRAM_WEBHOOK_SECRET', 'synthetic-archive-secret');
require_once __DIR__.'/../services/TelegramBusinessArchiveRuntime.php';
require_once __DIR__.'/../services/TelegramDialogueFiles.php';
require_once __DIR__.'/../handlers/TelegramWebhookHandler.php';

$passed = 0; $failed = 0;
function archiveCheck(string $name, bool $ok): void {
    global $passed, $failed;
    echo ($ok ? 'PASS ' : 'FAIL ').$name."\n";
    $ok ? $passed++ : $failed++;
}
function archiveThrows(callable $call): bool { try { $call(); return false; } catch (Throwable $error) { return true; } }

$dsn = getenv('TELEGRAM_ARCHIVE_TEST_DSN');
if ($dsn !== false && $dsn !== '') {
    if (!preg_match('/\Amysql:host=127\.0\.0\.1;port=3306;dbname=telegram_archive_test(?:;charset=utf8mb4)?\z/', $dsn)) throw new RuntimeException('isolated_test_database_required');
    $pdo = new PDO($dsn, 'root', 'archive-test-only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec((string)file_get_contents(__DIR__.'/../migrations/041_telegram_dialogue_archive.sql'));
    $pdo->exec((string)file_get_contents(__DIR__.'/../migrations/041_telegram_dialogue_archive.sql'));
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE telegram_dialogue_connections (project_key TEXT,bot_key TEXT,connection_id TEXT,account_id TEXT,is_enabled INTEGER,last_update_id INTEGER,PRIMARY KEY(project_key,bot_key,connection_id))');
    $pdo->exec('CREATE TABLE telegram_dialogue_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,account_id TEXT,chat_id TEXT,message_id TEXT,version_at INTEGER,source_kind TEXT,is_deleted INTEGER,record_json TEXT,UNIQUE(project_key,account_id,chat_id,message_id))');
}
$archive = new TelegramDialogueArchive($pdo);
$message = static fn(int $id, string $from, $text, int $date=1792230000): array => ['id'=>$id,'type'=>'message','date_unixtime'=>(string)$date,'from_id'=>$from,'text'=>$text];
$chat = ['id'=>200,'type'=>'personal_chat','messages'=>[
    $message(1, 'user200', 'Турция, двое, до 200 тысяч за всех'),
    $message(2, 'user100', ['Подборка ', ['type'=>'text_link','text'=>'здесь','href'=>'https://qui-quo.ru/synthetic-selection']]),
    $message(3, 'user200', 'Второй отель нравится'),
    ['id'=>4,'type'=>'service','action'=>'history_clear'],
    $message(5, 'missing-author', 'Автор не установлен'),
    $message(6, 'user100', 'Фото') + ['photo'=>'photos/photo.jpg','reply_to_message_id'=>1],
]];
$input = ['chats'=>['list'=>[$chat,['id'=>999,'type'=>'personal_chat','messages'=>[$message(1,'user999','PRIVATE-EXCLUDED')]]]]];
$normalized = TelegramDialogueNormalizer::desktop($input, '100', ['200']);
archiveCheck('combined export selects only explicit work chats', $normalized['counts'] === ['selected_chats'=>1,'messages'=>5,'service_messages'=>1,'excluded_chats'=>1]);
archiveCheck('text entities retain exact visible text and linked selection URL', $normalized['messages'][1]['text'] === 'Подборка здесь' && $normalized['messages'][1]['links'] === ['https://qui-quo.ru/synthetic-selection']);
archiveCheck('manager and customer authors are determined by original IDs', $normalized['messages'][0]['sender_type'] === 'customer' && $normalized['messages'][1]['sender_type'] === 'manager');
archiveCheck('missing author is never invented as customer', $normalized['messages'][3]['sender_type'] === 'unknown');
archiveCheck('original date and reply identity survive import', $normalized['messages'][4]['created_at'] === 1792230000 && $normalized['messages'][4]['reply_to_message_id'] === '1');
archiveCheck('media reference does not claim file download or content analysis', $normalized['messages'][4]['attachments'] === [['type'=>'image','reference'=>'photos/photo.jpg','availability'=>'not_checked']]);
archiveCheck('individual chat export is supported', count(TelegramDialogueNormalizer::desktop($chat, '100', ['200'])['messages']) === 5);
archiveCheck('missing selected chat fails instead of claiming complete history', archiveThrows(fn()=>TelegramDialogueNormalizer::desktop($input, '100', ['201'])));
archiveCheck('empty scope is rejected', archiveThrows(fn()=>TelegramDialogueNormalizer::desktop($input, '100', [])));
$group = $chat; $group['type'] = 'private_group';
archiveCheck('group/channel exports cannot enter selected private history', archiveThrows(fn()=>TelegramDialogueNormalizer::desktop($group, '100', ['200'])));
$duplicate = $chat; $duplicate['messages'][] = $chat['messages'][0];
archiveCheck('duplicate source IDs fail before partial import', archiveThrows(fn()=>TelegramDialogueNormalizer::desktop($duplicate, '100', ['200'])));
$badDate = $chat; unset($badDate['messages'][0]['date_unixtime']);
archiveCheck('timezone-ambiguous dates do not become fabricated timestamps', archiveThrows(fn()=>TelegramDialogueNormalizer::desktop($badDate, '100', ['200'])));
$oversized = $chat; $oversized['messages'][0]['text'] = str_repeat('x', 1048577);
archiveCheck('oversized text is rejected without truncation', archiveThrows(fn()=>TelegramDialogueNormalizer::desktop($oversized, '100', ['200'])));
$unsafeFile = $chat; $unsafeFile['messages'][5]['photo'] = '../../outside';
archiveCheck('attachment traversal never becomes a usable path', TelegramDialogueNormalizer::desktop($unsafeFile, '100', ['200'])['messages'][4]['attachments'][0]['reference'] === null);
archiveCheck('noncanonical identities fail closed', archiveThrows(fn()=>TelegramDialogueNormalizer::id('0200')) && archiveThrows(fn()=>TelegramDialogueNormalizer::id(2.5)));

archiveCheck('first import inserts the full selected message set', $archive->import('anytour', $normalized['messages'], '7') === ['inserted'=>5,'updated'=>0,'unchanged'=>0]);
archiveCheck('repeat import does not duplicate or rewrite messages', $archive->import('anytour', $normalized['messages'], '7') === ['inserted'=>0,'updated'=>0,'unchanged'=>5]);
$otherAccount = TelegramDialogueNormalizer::desktop($chat, '101', ['200']);
archiveCheck('same chat and message IDs on another account remain separate', $archive->import('anytour', $otherAccount['messages'])['inserted'] === 5);
archiveCheck('another project is a separate archive scope', $archive->import('other-project', $normalized['messages'])['inserted'] === 5);
archiveCheck('project/account export cannot leak adjacent archive scopes', count(iterator_to_array($archive->export('anytour', '100', ['200']))) === 5);
$before = (int)$pdo->query('SELECT COUNT(*) FROM telegram_dialogue_messages')->fetchColumn();
$invalid = $normalized['messages'][0]; $invalid['message_id'] = 'bad';
$new = $normalized['messages'][0]; $new['message_id'] = '90';
archiveCheck('one failed record rolls back the entire import', archiveThrows(fn()=> $archive->import('anytour', [$new, $invalid])) && (int)$pdo->query('SELECT COUNT(*) FROM telegram_dialogue_messages')->fetchColumn() === $before);

ProjectConfig::resetForTests(['id'=>'anytour']);
$config = ['enabled'=>true,'bot_key'=>'AnyTourArchiveBot','accounts'=>['100'=>['manager_id'=>'7','chat_ids'=>['200']]]];
$connection = ['update_id'=>10,'business_connection'=>['id'=>'connection-1','user'=>['id'=>100],'is_enabled'=>true]];
archiveCheck('disabled intake requires no database and no configuration', TelegramBusinessArchiveRuntime::receive($connection, [])['state'] === 'disabled');
archiveCheck('configured capture requires authenticated webhook even on direct dispatch', archiveThrows(fn()=>TelegramBusinessArchiveRuntime::receive($connection, $config, $archive)));
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = TELEGRAM_WEBHOOK_SECRET;
archiveCheck('allowlisted account can register authenticated connection', TelegramBusinessArchiveRuntime::receive($connection, $config, $archive)['state'] === 'connected');
$denied = $connection; $denied['business_connection']['user']['id'] = 999;
archiveCheck('unapproved account is not registered', TelegramBusinessArchiveRuntime::receive($denied, $config, $archive)['state'] === 'account_not_allowed');
$live = ['update_id'=>11,'business_message'=>['business_connection_id'=>'connection-1','message_id'=>7,'date'=>1792230001,'chat'=>['id'=>200,'type'=>'private'],'from'=>['id'=>100],'text'=>'Новая подборка']];
archiveCheck('live message is archived without ordinary bot processing', TelegramBusinessArchiveRuntime::receive($live, $config, $archive) === ['state'=>'archived','stored'=>1]);
archiveCheck('provider retry is idempotent', TelegramBusinessArchiveRuntime::receive($live, $config, $archive)['stored'] === 0);
$edit = ['update_id'=>13,'edited_business_message'=>$live['business_message']+['edit_date'=>1792230010]];
$edit['edited_business_message']['text'] = 'Уточнённая подборка';
archiveCheck('edit changes stored text', TelegramBusinessArchiveRuntime::receive($edit, $config, $archive)['stored'] === 1);
$oldEdit = $edit; $oldEdit['update_id'] = 12; $oldEdit['edited_business_message']['text'] = 'Устаревшая правка';
archiveCheck('out-of-order same-second edit cannot replace latest text', TelegramBusinessArchiveRuntime::receive($oldEdit, $config, $archive)['stored'] === 0);
$opaque = $connection; $opaque['update_id'] = 0; $opaque['business_connection']['id'] = 'opaque+connection/id==';
archiveCheck('opaque provider connection IDs and update ID zero are supported', TelegramBusinessArchiveRuntime::receive($opaque, $config, $archive)['state'] === 'connected');
$reconnectedEdit = $edit; $reconnectedEdit['update_id'] = 21; $reconnectedEdit['edited_business_message']['business_connection_id'] = 'opaque+connection/id==';
$reconnectedEdit['edited_business_message']['text'] = 'Правка после переподключения';
archiveCheck('new connection preserves account/chat/message identity', TelegramBusinessArchiveRuntime::receive($reconnectedEdit, $config, $archive)['stored'] === 1);
$crossConnectionOld = $edit; $crossConnectionOld['update_id'] = 19;
archiveCheck('same-bot sequence fences delayed edits across connection changes', TelegramBusinessArchiveRuntime::receive($crossConnectionOld, $config, $archive)['stored'] === 0);
$olderImport = TelegramDialogueNormalizer::desktop(['id'=>200,'type'=>'personal_chat','messages'=>[$message(7,'user100','Old imported text',1792230001)]], '100', ['200']);
archiveCheck('older import cannot overwrite newer provider edit', $archive->import('anytour', $olderImport['messages'])['unchanged'] === 1);
$deniedChat = $live; $deniedChat['business_message']['chat']['id'] = 201;
archiveCheck('pilot chat allowlist is enforced', TelegramBusinessArchiveRuntime::receive($deniedChat, $config, $archive)['state'] === 'chat_not_allowed');
$unknown = $live; $unknown['business_message']['business_connection_id'] = 'missing';
archiveCheck('unknown connection fails for retry instead of silently losing message', archiveThrows(fn()=>TelegramBusinessArchiveRuntime::receive($unknown, $config, $archive)));
$deletion = ['update_id'=>14,'deleted_business_messages'=>['business_connection_id'=>'connection-1','chat'=>['id'=>200,'type'=>'private'],'message_ids'=>[7,8]]];
archiveCheck('known and previously unseen deletions create durable tombstones', TelegramBusinessArchiveRuntime::receive($deletion, $config, $archive)['stored'] === 2);
archiveCheck('deleted message cannot be resurrected by older Desktop export', $archive->import('anytour', $olderImport['messages'])['unchanged'] === 1);
archiveCheck('deleted message cannot be resurrected by delayed live edit', TelegramBusinessArchiveRuntime::receive($edit, $config, $archive)['stored'] === 0);
$rows = iterator_to_array($archive->export('anytour', '100', ['200']));
$deleted = array_values(array_filter($rows, static fn(array $row): bool=>$row['message_id'] === '7'))[0];
archiveCheck('deletion removes text/media/links but retains original chronology', $deleted['text'] === null && $deleted['attachments'] === [] && $deleted['links'] === [] && $deleted['created_at'] === 1792230001);
$revoked = $connection; $revoked['update_id'] = 15; $revoked['business_connection']['is_enabled'] = false;
TelegramBusinessArchiveRuntime::receive($revoked, $config, $archive);
archiveCheck('revoked connection stops further ingestion', TelegramBusinessArchiveRuntime::receive($live, $config, $archive)['state'] === 'disconnected');
TelegramBusinessArchiveRuntime::receive($connection, $config, $archive);
archiveCheck('stale enabled connection cannot undo revocation', TelegramBusinessArchiveRuntime::receive($live, $config, $archive)['state'] === 'disconnected');
$bot = TelegramDialogueNormalizer::businessMessage($live['business_message']+['sender_business_bot'=>['id'=>300]], '100', 'connection-1', '20');
archiveCheck('business bot author is not misrepresented as human manager', $bot['sender_type'] === 'bot');
$pagedMessages = [];
for ($i=1; $i<=401; $i++) $pagedMessages[] = $message($i, 'user300', 'Синтетическая история '.$i);
$paged = TelegramDialogueNormalizer::desktop(['id'=>300,'type'=>'personal_chat','messages'=>$pagedMessages], '100', ['300']);
$archive->import('anytour', $paged['messages']);
$pagedRows = iterator_to_array($archive->export('anytour', '100', ['300']));
archiveCheck('keyset export crosses three pages without losing first or last messages', count($pagedRows) === 401 && $pagedRows[0]['message_id'] === '1' && $pagedRows[400]['message_id'] === '401');
if ($dsn !== false && $dsn !== '') {
    $snapshot = $archive->export('anytour', '100', ['300']);
    $snapshot->rewind();
    $writer = new TelegramDialogueArchive(new PDO($dsn, 'root', 'archive-test-only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]));
    $late = $paged['messages'][0]; $late['message_id'] = '402';
    $writer->import('anytour', [$late]);
    archiveCheck('native MySQL snapshot excludes concurrent later insert', count(iterator_to_array($snapshot)) === 401 && count(iterator_to_array($archive->export('anytour', '100', ['300']))) === 402);
}

// Exercise the actual dispatch boundary with a spy application: zero calls/sends.
$calls = 0;
$app = new DialogueApplication(static function() use (&$calls): void { $calls++; });
$dispatcher = new IncomingUpdateDispatcher($app);
$messenger = new TelegramMessengerAdapter(static function() use (&$calls): bool { $calls++; return true; });
putenv('MAX_SEARCH_TELEGRAM_DIALOGUE_ARCHIVE');
archiveCheck('actual webhook consumes business events before normal application', TelegramWebhookHandler::dispatchUpdate($live, $dispatcher, $messenger) && $calls === 0);
archiveCheck('ordinary Telegram messages remain on the existing dispatcher', TelegramWebhookHandler::dispatchUpdate(['update_id'=>100,'message'=>['message_id'=>1,'from'=>['id'=>200],'chat'=>['id'=>200],'text'=>'Обычный бот']], $dispatcher, $messenger) && $calls === 1);

$private = sys_get_temp_dir().'/telegram-dialogue-test-'.bin2hex(random_bytes(8));
mkdir($private, 0700); chmod($private, 0700);
try {
    $file = $private.'/export.json'; file_put_contents($file, json_encode($input, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); chmod($file, 0600);
    archiveCheck('private source file can be read without printing content', strlen(TelegramDialogueFiles::read($file)) > 0);
    symlink($file, $private.'/link.json');
    archiveCheck('input symlinks are rejected', archiveThrows(fn()=>TelegramDialogueFiles::read($private.'/link.json')));
    archiveCheck('checkout output is rejected', archiveThrows(fn()=>TelegramDialogueFiles::write(dirname(__DIR__).'/archive-private.json', static fn($emit)=>$emit('PRIVATE'))));
    $target = $private.'/archive.jsonl'; TelegramDialogueFiles::write($target, static fn($emit)=>$emit("synthetic\n"));
    clearstatcache();
    archiveCheck('private output is complete and mode 0600', file_get_contents($target) === "synthetic\n" && (fileperms($target) & 0777) === 0600);
    archiveCheck('completed output is never overwritten', archiveThrows(fn()=>TelegramDialogueFiles::write($target, static fn($emit)=>$emit('replacement'))) && file_get_contents($target) === "synthetic\n");
    archiveCheck('failed output leaves no partial artifact', archiveThrows(fn()=>TelegramDialogueFiles::write($private.'/partial', static function($emit): void { $emit('partial'); throw new RuntimeException('synthetic'); })) && !file_exists($private.'/partial'));
    chmod($private, 0755); clearstatcache();
    archiveCheck('readable output directory is rejected rather than silently altered', archiveThrows(fn()=>TelegramDialogueFiles::write($private.'/public', static fn($emit)=>$emit('private'))));
    chmod($private, 0700); clearstatcache();
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../tools/telegram_dialogue_archive.php').' --mode=import --input='.escapeshellarg($file).' --account-id=100 --chat-ids=200';
    exec($command.' 2>&1', $output, $exit);
    $summary = json_decode(implode("\n", $output), true);
    archiveCheck('CLI dry run validates without loading runtime config or DB', $exit === 0 && ($summary['mode'] ?? '') === 'dry_run' && $summary['counts']['messages'] === 5);
    archiveCheck('CLI output contains no message text or account identity', !str_contains(implode("\n", $output), 'Турция') && !str_contains(implode("\n", $output), 'account_id'));
} finally {
    foreach (glob($private.'/*') as $file) unlink($file);
    foreach (glob($private.'/.telegram-archive-*') as $file) unlink($file);
    rmdir($private);
}
require_once __DIR__.'/telegram_archive_setup_checks.php';
runTelegramArchiveSetupChecks($pdo, $dsn);
ProjectConfig::resetForTests(null);
echo "TOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
