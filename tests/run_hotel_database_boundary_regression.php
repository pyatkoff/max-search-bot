<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/HotelDatabase.php';

function failHotelDb(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

if (HotelDatabase::configured()) {
    failHotelDb('hotel DB must not silently reuse bot DB configuration');
}

try {
    HotelDatabase::connection();
    failHotelDb('missing hotel DB configuration must fail closed');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'Hotel database is not configured') {
        failHotelDb('unexpected missing-config error');
    }
}

$source = (string) file_get_contents(__DIR__ . '/../services/HotelDatabase.php');
foreach (['HOTEL_DB_HOST', 'HOTEL_DB_NAME', 'HOTEL_DB_USER', 'HOTEL_DB_PASS'] as $constant) {
    if (strpos($source, $constant) === false) failHotelDb("missing {$constant} boundary");
}
if (strpos($source, 'CONVERSATION_DB_') !== false) {
    failHotelDb('hotel DB boundary must not depend on conversation DB credentials');
}

echo "OK: separate hotel database boundary regression passed\n";
