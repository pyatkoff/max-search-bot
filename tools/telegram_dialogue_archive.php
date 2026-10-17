<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__.'/../services/TelegramDialogueArchive.php';
require_once __DIR__.'/../services/TelegramDialogueFiles.php';

// Parse before loading configuration or connecting to any database.
$options = getopt('', ['mode:', 'input:', 'output:', 'account-id:', 'chat-ids:', 'manager-id:', 'project:', 'apply']);
try {
    $mode = $options['mode'] ?? '';
    if (!in_array($mode, ['import', 'export'], true)) throw new InvalidArgumentException('mode_required');
    $account = TelegramDialogueNormalizer::id($options['account-id'] ?? null);
    if (!is_string($options['chat-ids'] ?? null)) throw new InvalidArgumentException('explicit_chat_scope_required');
    $chats = array_values(array_unique(array_map([TelegramDialogueNormalizer::class, 'id'], explode(',', $options['chat-ids']))));
    $project = TelegramDialogueArchive::key((string)($options['project'] ?? 'anytour'));
    $manager = isset($options['manager-id']) ? TelegramDialogueNormalizer::id($options['manager-id']) : null;
    if ($mode === 'import') {
        $bytes = TelegramDialogueFiles::read((string)($options['input'] ?? ''));
        $input = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if (!is_array($input)) throw new InvalidArgumentException('invalid_export');
        $normalized = TelegramDialogueNormalizer::desktop($input, $account, $chats);
        $summary = ['ok'=>true, 'mode'=>isset($options['apply']) ? 'import' : 'dry_run', 'counts'=>$normalized['counts'], 'input_sha256'=>hash('sha256', $bytes)];
        if (isset($options['apply'])) {
            require_once __DIR__.'/../config.php';
            $summary['storage'] = (new TelegramDialogueArchive())->import($project, $normalized['messages'], $manager);
        }
    } else {
        if (isset($options['apply'])) throw new InvalidArgumentException('export_is_read_only');
        require_once __DIR__.'/../config.php';
        $count = 0; $archive = new TelegramDialogueArchive();
        TelegramDialogueFiles::write((string)($options['output'] ?? ''), static function (callable $emit) use ($archive, $project, $account, $chats, &$count): void {
            $emit(TelegramDialogueArchive::encode(['type'=>'header', 'schema'=>'telegram_dialogue_archive_v1', 'project_key'=>$project,
                'account_id'=>$account, 'chat_ids'=>$chats, 'generated_at'=>gmdate('c'),
                'coverage'=>'stored_selected_chats_only', 'media_coverage'=>'references_only'])."\n");
            $digest = hash_init('sha256');
            foreach ($archive->export($project, $account, $chats) as $record) {
                $line = TelegramDialogueArchive::encode(['type'=>'message', 'channel'=>'telegram', 'record'=>$record])."\n";
                hash_update($digest, $line); $emit($line); $count++;
            }
            $emit(TelegramDialogueArchive::encode(['type'=>'footer', 'complete'=>true, 'message_records'=>$count, 'records_sha256'=>hash_final($digest)])."\n");
        });
        $summary = ['ok'=>true, 'mode'=>'export', 'message_records'=>$count];
    }
    echo json_encode($summary, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // No source text, identities, paths, PDO exception or credentials in terminal logs.
    fwrite(STDERR, "Telegram archive operation failed; private content was not printed.\n");
    exit(1);
}
