<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once(__DIR__ . '/config.php');

header('Content-Type: text/plain; charset=utf-8');

$queueFile = __DIR__ . '/metrika_offline_queue.csv';
$processingFile = __DIR__ . '/metrika_offline_processing.csv';
$logFile = __DIR__ . '/metrika_upload.log';

function metrikaLog($message)
{
    global $logFile;
    @file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function metrikaFail($message, $restoreProcessing = false)
{
    global $processingFile, $queueFile;

    metrikaLog('ERROR ' . $message);

    if ($restoreProcessing && is_file($processingFile) && filesize($processingFile) > 0) {
        $data = file_get_contents($processingFile);
        if ($data !== false && $data !== '') {
            @file_put_contents($queueFile, $data, FILE_APPEND | LOCK_EX);
        }
        @unlink($processingFile);
    }

    http_response_code(500);
    echo 'ERROR: ' . $message . PHP_EOL;
    exit(1);
}

if (!defined('METRIKA_COUNTER_ID') || trim((string)METRIKA_COUNTER_ID) === '') {
    metrikaFail('METRIKA_COUNTER_ID is not configured');
}

if (!defined('METRIKA_OAUTH_TOKEN') || trim((string)METRIKA_OAUTH_TOKEN) === '') {
    metrikaFail('METRIKA_OAUTH_TOKEN is not configured');
}

$counterId = trim((string)METRIKA_COUNTER_ID);
$oauthToken = trim((string)METRIKA_OAUTH_TOKEN);

if (!preg_match('/^\d+$/', $counterId)) {
    metrikaFail('METRIKA_COUNTER_ID must be numeric');
}

if (!is_file($processingFile) || filesize($processingFile) === 0) {
    if (!is_file($queueFile) || filesize($queueFile) === 0) {
        echo "OK queue empty\n";
        exit;
    }

    $fh = fopen($queueFile, 'c+');
    if (!$fh) {
        metrikaFail('cannot open queue file');
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        metrikaFail('cannot lock queue file');
    }

    rewind($fh);
    $data = stream_get_contents($fh);

    if ($data === false || trim($data) === '') {
        flock($fh, LOCK_UN);
        fclose($fh);
        echo "OK queue empty\n";
        exit;
    }

    if (file_put_contents($processingFile, $data, LOCK_EX) === false) {
        flock($fh, LOCK_UN);
        fclose($fh);
        metrikaFail('cannot create processing batch');
    }

    ftruncate($fh, 0);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

$in = fopen($processingFile, 'r');
if (!$in) {
    metrikaFail('cannot read processing batch', true);
}

$uploadFile = tempnam(sys_get_temp_dir(), 'metrika_offline_');
if ($uploadFile === false) {
    fclose($in);
    metrikaFail('cannot create temporary upload file', true);
}

$out = fopen($uploadFile, 'w');
if (!$out) {
    fclose($in);
    @unlink($uploadFile);
    metrikaFail('cannot open temporary upload file', true);
}

fputcsv($out, ['Yclid', 'Target', 'DateTime']);

$count = 0;
$lineNo = 0;
$now = time();

while (($row = fgetcsv($in)) !== false) {
    $lineNo++;

    if ($row === [null] || count($row) === 0) {
        continue;
    }

    $yclid = trim((string)($row[0] ?? ''));
    $target = trim((string)($row[1] ?? ''));
    $dateRaw = trim((string)($row[2] ?? ''));

    if ($yclid === '' && $target === '' && $dateRaw === '') {
        continue;
    }

    if (
        strcasecmp($yclid, 'Yclid') === 0 &&
        strcasecmp($target, 'Target') === 0 &&
        strcasecmp($dateRaw, 'DateTime') === 0
    ) {
        continue;
    }

    if (!preg_match('/^\d+$/', $yclid)) {
        fclose($in);
        fclose($out);
        @unlink($uploadFile);
        metrikaFail('invalid Yclid at queue line ' . $lineNo, true);
    }

    if ($target === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $target)) {
        fclose($in);
        fclose($out);
        @unlink($uploadFile);
        metrikaFail('invalid Target at queue line ' . $lineNo, true);
    }

    if (preg_match('/^\d{9,12}$/', $dateRaw)) {
        $timestamp = (int)$dateRaw;
    } else {
        $timestamp = strtotime($dateRaw);
    }

    if ($timestamp === false || $timestamp <= 0) {
        fclose($in);
        fclose($out);
        @unlink($uploadFile);
        metrikaFail('invalid DateTime at queue line ' . $lineNo, true);
    }

    if ($timestamp > $now) {
        fclose($in);
        fclose($out);
        @unlink($uploadFile);
        metrikaFail('future DateTime at queue line ' . $lineNo, true);
    }

    fputcsv($out, [$yclid, $target, $timestamp]);
    $count++;
}

fclose($in);
fclose($out);

if ($count === 0) {
    @unlink($uploadFile);
    @unlink($processingFile);
    echo "OK queue empty\n";
    exit;
}

$url = 'https://api-metrika.yandex.net/management/v1/counter/' . rawurlencode($counterId)
    . '/offline_conversions/upload?type=BASIC&comment=' . rawurlencode('MAX bot offline goals');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($uploadFile, 'text/csv', 'offline_conversions.csv'),
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => [
        'Authorization: OAuth ' . $oauthToken,
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($uploadFile);

if ($response === false) {
    metrikaFail('curl failed: ' . $curlError, true);
}

$json = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    $safeBody = is_string($response) ? substr($response, 0, 1000) : '';
    metrikaFail('HTTP ' . $httpCode . ' response=' . $safeBody, true);
}

$uploading = is_array($json) ? ($json['uploading'] ?? null) : null;
$uploadId = is_array($uploading) ? (int)($uploading['id'] ?? 0) : 0;
$status = is_array($uploading) ? (string)($uploading['status'] ?? '') : '';
$sourceQuantity = is_array($uploading) ? (int)($uploading['source_quantity'] ?? 0) : 0;
$lineQuantity = is_array($uploading) ? (int)($uploading['line_quantity'] ?? 0) : 0;

if ($uploadId <= 0) {
    metrikaFail('upload accepted but upload id is missing: ' . substr((string)$response, 0, 1000), true);
}

@unlink($processingFile);

metrikaLog(
    'OK upload_id=' . $uploadId
    . ' count=' . $count
    . ' source_quantity=' . $sourceQuantity
    . ' line_quantity=' . $lineQuantity
    . ' status=' . $status
);

echo 'OK upload_id=' . $uploadId
    . ' count=' . $count
    . ' source_quantity=' . $sourceQuantity
    . ' line_quantity=' . $lineQuantity
    . ' status=' . $status
    . PHP_EOL;
