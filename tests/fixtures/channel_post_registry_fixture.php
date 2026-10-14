<?php
declare(strict_types=1);

/** Apply the production migration with only MySQL storage syntax translated. */
function createChannelPostRegistryFixture(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS projects (id INTEGER PRIMARY KEY,project_key TEXT UNIQUE,display_name TEXT,is_active INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_sources (id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,display_name TEXT,channel TEXT,is_active INTEGER DEFAULT 1,handling_mode TEXT DEFAULT "manager",FOREIGN KEY(project_id) REFERENCES projects(id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS conversations (id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,status TEXT DEFAULT "ai",manager_id INTEGER,source_id INTEGER,entry_channel TEXT,external_chat_id TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_events (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT,FOREIGN KEY(conversation_id) REFERENCES conversations(id))');
    $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/migrations/033_channel_post_context.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $sql = preg_replace('/\bid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT\b/', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/^\s*PRIMARY KEY \(id\),?\s*$/m', '', $sql);
    $sql = preg_replace('/^\s*KEY [a-zA-Z0-9_]+ \([^\n]+\),?\s*$/m', '', $sql);
    $sql = preg_replace('/UNIQUE KEY [a-zA-Z0-9_]+/', 'UNIQUE', $sql);
    $sql = preg_replace('/ CHARACTER SET [a-zA-Z0-9_]+ COLLATE [a-zA-Z0-9_]+/', '', $sql);
    $sql = str_replace(' UNSIGNED', '', $sql);
    $sql = preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/', ');', $sql);
    $pdo->exec($sql);
}
