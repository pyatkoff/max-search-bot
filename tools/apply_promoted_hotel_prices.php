<?php
declare(strict_types=1);

require_once __DIR__.'/../services/PromotedHotelPriceWriter.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const PROMOTED_APP = '/var/www/anytoour/data/www/app.anytoour.ru';
const PROMOTED_OPERATIONS = '/var/www/anytoour/data/channel-post-operations';
const PROMOTED_PLAN_MAX_BYTES = 4_000_000;

$lock = null;
try {
    $mode = $argv[1] ?? '';
    $maxOnly = in_array($mode, ['--inspect-max-publication','--apply-max-publication'], true);
    $maxPublicationId = null;
    if ($maxOnly && isset($argv[2]) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $argv[2]) === 1) {
        $maxPublicationId = (int)$argv[2];
    }
    if (realpath(__DIR__.'/..') !== PROMOTED_APP
        || ($maxOnly && (count($argv) !== 3 || $maxPublicationId === null))
        || (!$maxOnly && (count($argv) !== 2 || !in_array($mode, ['--inspect','--apply'], true)))) {
        throw new RuntimeException('invalid_invocation');
    }
    $apply = $mode === '--apply' || $mode === '--apply-max-publication';
    $path = PROMOTED_OPERATIONS.'/monitor-price-after-collection-v1.jsonl';
    if (!is_file($path) || is_link($path)) throw new RuntimeException('original_price_lock_missing');
    $lock = fopen($path,'rb');
    if (!$lock || !flock($lock, ($apply ? LOCK_EX : LOCK_SH) | LOCK_NB)) throw new RuntimeException('shared_editor_busy');
    $journal = PROMOTED_OPERATIONS.'/promoted-hotel-price-revisions-v1';
    if (is_link($journal)) throw new RuntimeException('invalid_journal_path');
    if ($apply && !is_dir($journal) && !mkdir($journal,0700)) throw new RuntimeException('cannot_create_journal');
    if (is_dir($journal) && (realpath($journal) !== $journal || (fileperms($journal) & 0077) !== 0)) {
        throw new RuntimeException('invalid_journal_permissions');
    }

    $feed = PromotedHotelPriceWriter::publicBytes('https://app.anytoour.ru/feed/hotel-posts-all.xml');
    $process = proc_open(['/usr/bin/python3',__DIR__.'/prepare_promoted_hotel_prices.py','-'],
        [['pipe','r'],['pipe','w'],['file','/dev/null','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('price_planner_unavailable');
    $sent = fwrite($pipes[0],$feed);
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1], PROMOTED_PLAN_MAX_BYTES + 1);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $sent !== strlen($feed) || !is_string($result)
        || strlen($result) > PROMOTED_PLAN_MAX_BYTES) {
        throw new RuntimeException('price_planner_failed');
    }
    $plan = json_decode($result,true,32,JSON_THROW_ON_ERROR);
    $feedHash = hash('sha256',$feed);
    if (!is_array($plan) || ($plan['feed_sha256'] ?? null) !== $feedHash || !is_array($plan['plans'] ?? null)) {
        throw new RuntimeException('price_plan_mismatch');
    }

    ob_start();
    try {
        require __DIR__.'/../config.php';
        require_once __DIR__.'/../services/ChannelPostRuntime.php';
        require_once __DIR__.'/../integrations/ChannelPostApiTransport.php';
    } finally {
        ob_end_clean();
    }
    $pdo = ConversationDb::connection();
    $registry = new ChannelPostRegistryService($pdo);
    $writer = new PromotedHotelPriceWriter($pdo,$registry,$journal);
    $rows = $writer->run($plan,$feedHash,$apply,$maxPublicationId);
    echo 'PROMOTED_HOTEL_PRICE_RESULT='.json_encode(['mode'=>$argv[1],'feed_sha256'=>$plan['feed_sha256'],
        'source_held'=>$plan['held'],'rows'=>$rows],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch (Throwable $error) {
    $reason = $error->getMessage();
    echo 'PROMOTED_HOTEL_PRICE_ERROR='.(preg_match('/^[a-z0-9_]{1,100}$/D',$reason) ? $reason : 'runtime_failure'),PHP_EOL;
    exit(1);
} finally {
    if (is_resource($lock)) fclose($lock);
}
