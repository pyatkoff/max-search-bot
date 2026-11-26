<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);

require_once dirname(__DIR__).'/services/ManagerHotelKnowledge.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/hotel_catalogue_compatibility.php APPLICATION_ROOT CANDIDATE_READER\n");
    exit(2);
}
[, $app, $candidate] = $argv;
$size = !is_link($candidate) && is_file($candidate) ? @filesize($candidate) : false;
$sha = $size !== false && $size > 0 && $size <= 131072 ? @hash_file('sha256', $candidate) : false;
$status = is_string($sha) ? ManagerHotelKnowledge::coreCatalogueCompatibility($app, $sha) : 'invalid_input';
$ok = $status === 'bundled' || $status === 'compatible';
echo json_encode(['ok'=>$ok, 'status'=>$status], JSON_THROW_ON_ERROR)."\n";
exit($ok ? 0 : 1);
