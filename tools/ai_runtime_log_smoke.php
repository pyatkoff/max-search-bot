<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/services/AiRuntimeLogger.php';

$written = AiRuntimeLogger::debug('[deployment AI log boundary check]');
$file = AiRuntimeLogger::debugFile();
$dir = $file !== '' ? realpath(dirname($file)) : false;
$project = realpath($root);
$outsideProject = is_string($dir)
    && is_string($project)
    && $dir !== $project
    && !str_starts_with($dir, $project . DIRECTORY_SEPARATOR);
$directoryMode = is_string($dir) && is_dir($dir) ? (fileperms($dir) & 0777) : 0;
$fileMode = $file !== '' && is_file($file) ? (fileperms($file) & 0777) : 0;

$ok = $written
    && $outsideProject
    && $directoryMode === 0700
    && $fileMode === 0600;

echo json_encode([
    'ok' => $ok,
    'outside_document_root' => $outsideProject,
    'directory_mode' => sprintf('%04o', $directoryMode),
    'file_mode' => sprintf('%04o', $fileMode),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
