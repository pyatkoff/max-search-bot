<?php
declare(strict_types=1);
/** Disposable fixtures only. Production schema is migration 082. */
function preparedInputFixtureSchema(PDO $pdo): void
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/082_manager_prepared_reply_inputs.sql'));return;
    }
    $pdo->exec('CREATE TABLE manager_prepared_reply_inputs(receipt_id TEXT PRIMARY KEY,job_id TEXT NOT NULL,admin_id INTEGER NOT NULL,connection_id TEXT NOT NULL,packet_json TEXT NOT NULL,packet_sha256 TEXT NOT NULL,issued_at TEXT NOT NULL,FOREIGN KEY(job_id) REFERENCES manager_prepared_replies(job_id) ON DELETE CASCADE);
CREATE TABLE manager_prepared_reply_input_links(job_id TEXT PRIMARY KEY,receipt_id TEXT NOT NULL UNIQUE,FOREIGN KEY(job_id) REFERENCES manager_prepared_replies(job_id) ON DELETE CASCADE,FOREIGN KEY(receipt_id) REFERENCES manager_prepared_reply_inputs(receipt_id) ON DELETE CASCADE)');
}
