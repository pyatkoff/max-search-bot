<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scanDirs = ['handlers','actions','services','ai','contracts'];
$forbidden = [
    'MaxSearchApi::MaxSend(',
    'MaxSearchApi::MaxSendWithButtons(',
    'MaxSearchApi::MaxRequest(',
    'MAX_SEARCH_TOKEN',
    'platform-api2.max.ru',
];

$allowedFiles = [
    'services/MaxTransport.php',
];

$violations = [];
$scanned = 0;

foreach ($scanDirs as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) continue;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (in_array($relative, $allowedFiles, true)) continue;
        $scanned++;

        $content = (string)file_get_contents($file->getPathname());
        foreach ($forbidden as $needle) {
            if (strpos($content, $needle) !== false) {
                $violations[] = $relative . ' -> ' . $needle;
            }
        }
    }
}

if ($scanned === 0) {
    fwrite(STDERR, "FAIL  platform boundary audit scanned 0 files\n");
    exit(1);
}

if ($violations) {
    echo "FAIL  platform boundary violations detected\n";
    foreach ($violations as $violation) echo "      {$violation}\n";
    echo "\nDirect MAX transport/API details are allowed only in the MAX integration/transport layer.\n";
    exit(1);
}

echo "PASS  platform boundary: {$scanned} core PHP files are free of direct MAX transport/API dependencies\n";
exit(0);
