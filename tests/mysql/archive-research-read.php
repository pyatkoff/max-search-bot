<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$archiveResearchNativePdo = null;
try {
    $archiveResearchDsn = (string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if (preg_match('/\\Amysql:.*(?:\\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\\z)/D', $archiveResearchDsn, $archiveResearchMatch) !== 1) {
        throw new RuntimeException('unsafe_fixture_database');
    }
    $archiveResearchNativePdo = new PDO($archiveResearchDsn, getenv('PREPARED_REPLY_MYSQL_USER') ?: null, getenv('PREPARED_REPLY_MYSQL_PASS') ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($archiveResearchNativePdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || $archiveResearchNativePdo->query('SELECT DATABASE()')->fetchColumn() !== $archiveResearchMatch[1]) {
        throw new RuntimeException('unsafe_fixture_database');
    }
    require_once dirname(__DIR__).'/run_archive_research_read_regression.php';
    archiveResearchReadRegression($archiveResearchNativePdo);
    echo "PASS native MySQL archive research read-only admission, paging and unchanged data\n";
} catch (Throwable $archiveResearchError) {
    if ($archiveResearchNativePdo instanceof PDO && $archiveResearchNativePdo->inTransaction()) {
        $archiveResearchNativePdo->rollBack();
    }
    // No DSN, source text, identities, SQL or exception details in CI.
    fwrite(STDERR, "FAIL native MySQL archive research reader\n");
    exit(1);
}
