<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/HotelDatabase.php';

function failHotelDb(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

if (HotelDatabase::configured()) {
    failHotelDb('AnyTour data DB must not silently reuse bot DB configuration');
}

try {
    HotelDatabase::connection();
    failHotelDb('missing AnyTour data DB configuration must fail closed');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'AnyTour data database is not configured') {
        failHotelDb('unexpected missing-config error');
    }
}

$source = (string) file_get_contents(__DIR__ . '/../services/HotelDatabase.php');
foreach (['ANYTOUR_DATA_DB_HOST', 'ANYTOUR_DATA_DB_NAME', 'ANYTOUR_DATA_DB_USER', 'ANYTOUR_DATA_DB_PASSWORD'] as $constant) {
    if (strpos($source, $constant) === false) failHotelDb("missing {$constant} boundary");
}
if (strpos($source, 'CONVERSATION_DB_') !== false) {
    failHotelDb('AnyTour data DB boundary must not depend on conversation DB credentials');
}
if (strpos($source, 'HOTEL_DB_') !== false) {
    failHotelDb('obsolete HOTEL_DB_* configuration must not be introduced');
}

echo "OK: separate AnyTour data database boundary regression passed\n";
