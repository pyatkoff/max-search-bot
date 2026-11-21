<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

/** These helpers create only a disposable in-memory SQLite fixture. */
function v2BindingTestPackage(string $name): string
{
    $base = dirname(__DIR__, 2);
    $path = $base.'/'.$name;
    if (!is_dir($path)) $path = $base.'/max2-common-v2-'.$name.'-20261121';
    if (!is_dir($path)) throw new RuntimeException('SYNTHETIC_PACKAGE_MISSING');
    return $path;
}
require_once v2BindingTestPackage('policy').'/services/Max2ValueBindingPolicyV2.php';
require_once dirname(__DIR__).'/services/Max2CommonFrameV2.php';
require_once dirname(__DIR__).'/services/Max2CommonEntryEvidenceV2.php';
require_once dirname(__DIR__).'/services/Max2CommonEntryCaptureV2.php';
require_once dirname(__DIR__).'/services/Max2CommonMessageCaptureV2.php';

final class V2BindingMemoryPdo extends PDO
{
    public int $commits = 0;
    public ?int $loseCommit = null;
    public function commit(): bool
    {
        $result = parent::commit();
        $this->commits++;
        if ($this->loseCommit === $this->commits) {
            $this->loseCommit = null;
            throw new PDOException('synthetic lost commit acknowledgement');
        }
        return $result;
    }
}

function v2BindingTestDb(): V2BindingMemoryPdo
{
    $db = new V2BindingMemoryPdo('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->sqliteCreateFunction('OCTET_LENGTH', static fn($value): int => strlen((string)$value), 1);
    $db->exec('PRAGMA foreign_keys=ON');

    // Translate the actual reviewed frame table's dialect only. The native
    // MySQL service separately owns verification of MySQL DDL and concurrency.
    $sql = file_get_contents(v2BindingTestPackage('worker').'/migrations/113_max2_common_runtime_v2.sql');
    if (!is_string($sql) || preg_match('/CREATE TABLE IF NOT EXISTS max2_common_entry_frames_v2 \([\s\S]+?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/', $sql, $match) !== 1)
        throw new RuntimeException('ACTUAL_FRAME_DDL_MISSING');
    $sql = str_replace(' CHARACTER SET ascii COLLATE ascii_bin', '', $match[0]);
    $sql = preg_replace('/UNIQUE KEY ([a-z0-9_]+) \(/', 'CONSTRAINT $1 UNIQUE (', $sql);
    $sql = preg_replace('/^\s*KEY [a-z0-9_]+ \([^\n]+\),\n/m', '', $sql);
    $sql = str_replace(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;', ');', $sql);
    $db->exec($sql);

    // Minimal native-column fixtures from pinned 001/033 and current capture
    // SELECTs. Native message IDs deliberately use a case-insensitive collation
    // so exact returned-ID validation cannot hide behind SQLite's default.
    $db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, project_key TEXT NOT NULL)');
    $db->exec('CREATE TABLE post_channels (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, platform TEXT NOT NULL, bot_key TEXT NOT NULL, canonical_bot_username TEXT NOT NULL, channel_id TEXT NOT NULL)');
    $db->exec('CREATE TABLE post_publications (id INTEGER PRIMARY KEY, post_channel_id INTEGER NOT NULL)');
    $db->exec('CREATE TABLE post_versions (id INTEGER PRIMARY KEY, publication_id INTEGER NOT NULL, snapshot_json TEXT NOT NULL)');
    $db->exec('CREATE TABLE customer_channels (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, project_key TEXT NOT NULL, channel TEXT NOT NULL, external_user_id TEXT NOT NULL, external_chat_id TEXT NOT NULL)');
    $db->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY, project_key TEXT NOT NULL, channel TEXT NOT NULL, customer_id INTEGER NOT NULL, customer_channel_id INTEGER NOT NULL, external_chat_id TEXT NOT NULL, is_test INTEGER NOT NULL)');
    $db->exec("CREATE TABLE post_entries (id INTEGER PRIMARY KEY, conversation_id INTEGER NOT NULL, publication_id INTEGER NULL, version_id INTEGER NULL, entry_key TEXT NOT NULL, availability_reason TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, UNIQUE(conversation_id,entry_key))");
    $db->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, conversation_id INTEGER NOT NULL, channel TEXT NOT NULL, direction TEXT NOT NULL, sender_type TEXT NOT NULL, sender_id TEXT NULL, external_message_id TEXT COLLATE NOCASE NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL)');
    return $db;
}

function v2BindingFixture(PDO $db, bool $seedEntry = true): array
{
    $mapFile = v2BindingTestPackage('map').'/canonical-map-v1055.json';
    $raw = file_get_contents($mapFile);
    if (!is_string($raw) || hash('sha256', $raw) !== Max2CommonEntryEvidenceV2::MAP_SHA256)
        throw new RuntimeException('ACTUAL_FROZEN_MAP_REQUIRED');
    $map = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $pair = array_values($map['bindings_by_post_id'])[0];
    $eventKey = hash('sha256', 'synthetic authenticated MAX bot start');
    $subject = ['project_key'=>'anytour', 'platform'=>'max', 'customer_key'=>'17', 'conversation_id'=>'29'];
    $activation = ['mode'=>'collect', 'counter_id'=>98615635, 'goal_id'=>687941321,
        'target'=>'anytour_modeled_value_v2_max2', 'activated_at_unix'=>1795263000,
        'activation_sha256'=>hash('sha256', 'synthetic activation; not live permission')];
    $fixture = ['map_file'=>$mapFile, 'map'=>$map, 'pair'=>$pair, 'activation'=>$activation, 'now'=>1795263660,
        'actor'=>'91', 'subject'=>$subject, 'conversation'=>['id'=>'29', 'project_key'=>'anytour', 'channel'=>'max'],
        'resolved'=>['project_key'=>'anytour', 'platform'=>'max', 'publication_id'=>$pair['publication_id'], 'version_id'=>'59'],
        'record'=>['duplicate'=>false, 'entry_id'=>'41', 'version_id'=>'59'], 'event_key'=>$eventKey,
        'entry_key'=>hash('sha256', 'max|'.$map['post_bot_key'].'|'.$eventKey), 'entry_id'=>'41', 'version_id'=>'59',
        'message_id'=>'97', 'external_message_id'=>'mid.Abc_97', 'entry_observed_at'=>1795263500,
        'inquiry_recorded_at'=>1795263600, 'message_observed_at'=>1795263601];

    $db->exec("INSERT INTO projects VALUES(1538,'anytour')");
    $q = $db->prepare('INSERT INTO post_channels VALUES(31,1538,?,?,?,?)');
    $q->execute(['max', $map['post_bot_key'], $map['post_bot_key'], $map['channel_id']]);
    $q = $db->prepare('INSERT INTO post_publications VALUES(?,31)'); $q->execute([$pair['publication_id']]);
    $q = $db->prepare('INSERT INTO post_versions VALUES(59,?,?)');
    $q->execute([$pair['publication_id'], json_encode(['post_url'=>$pair['post_url'], 'title'=>'Synthetic hotel'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    $db->exec("INSERT INTO customer_channels VALUES(25,17,'anytour','max','91','-91')");
    $db->exec("INSERT INTO conversations VALUES(29,'anytour','max',17,25,'-91',0)");
    if ($seedEntry) {
        $q = $db->prepare('INSERT INTO post_entries VALUES(41,29,?,59,?,\'\',?)');
        $q->execute([$pair['publication_id'], $fixture['entry_key'], gmdate('Y-m-d H:i:s', $fixture['entry_observed_at'])]);
    }
    $q = $db->prepare('INSERT INTO messages VALUES(97,29,\'max\',\'inbound\',\'customer\',\'91\',?,?,?)');
    $q->execute([$fixture['external_message_id'], json_encode(['_recorded_clock'=>['version'=>1, 'basis'=>'server_recording_unix', 'unix'=>$fixture['inquiry_recorded_at']]], JSON_THROW_ON_ERROR),
        gmdate('Y-m-d H:i:s', $fixture['inquiry_recorded_at'])]);
    return $fixture;
}

/** Fixture-only private seam: this DOES NOT test successful real HTTP auth. */
function v2BindingCaptureEntry(PDO $db, array $fixture, ?int $now = null): array
{
    $capture = new Max2CommonEntryCaptureV2($db, $fixture['activation'], $fixture['map_file']);
    $method = new ReflectionMethod($capture, 'captureAuthenticated');
    $method->setAccessible(true);
    return $method->invoke($capture, $fixture['record'], $fixture['conversation'], $fixture['resolved'],
        $fixture['event_key'], $fixture['actor'], $now ?? $fixture['entry_observed_at']);
}

/** Fixture-only private seam: this DOES NOT test successful real HTTP auth. */
function v2BindingCaptureMessage(PDO $db, array $fixture, ?int $now = null): array
{
    $capture = new Max2CommonMessageCaptureV2($db, $fixture['activation']);
    $method = new ReflectionMethod($capture, 'captureAuthenticated');
    $method->setAccessible(true);
    return $method->invoke($capture, $fixture['actor'], $fixture['external_message_id'], $now ?? $fixture['message_observed_at']);
}

function v2BindingAddEntry(PDO $db, array $fixture, string $entryId = '43', ?string $createdAt = null): array
{
    $f = $fixture;
    $f['entry_id'] = $entryId; $f['record']['entry_id'] = $entryId;
    $f['event_key'] = hash('sha256', 'synthetic distinct entry '.$entryId);
    $f['entry_key'] = hash('sha256', 'max|'.$f['map']['post_bot_key'].'|'.$f['event_key']);
    $q = $db->prepare('INSERT INTO post_entries VALUES(?,?,?,?,?,\'\',?)');
    $q->execute([$entryId, $f['subject']['conversation_id'], $f['pair']['publication_id'], $f['version_id'], $f['entry_key'],
        $createdAt ?? gmdate('Y-m-d H:i:s', $f['entry_observed_at'] + 10)]);
    return $f;
}

function v2BindingFrameRow(PDO $db, ?string $entryId): ?array
{
    $sql = $entryId === null ? 'SELECT * FROM max2_common_entry_frames_v2 WHERE entry_id IS NULL ORDER BY frame_key' : 'SELECT * FROM max2_common_entry_frames_v2 WHERE entry_id=?';
    $q = $db->prepare($sql); $q->execute($entryId === null ? [] : [$entryId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
    if (count($rows) > 1) throw new RuntimeException('SYNTHETIC_FRAME_SELECTION_AMBIGUOUS');
    return $rows[0] ?? null;
}
