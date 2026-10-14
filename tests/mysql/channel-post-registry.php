<?php
declare(strict_types=1);

// Optional locally; mandatory with the explicit MySQL service configuration in CI.
// Refuse every application database and any pre-existing schema before doing DDL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = getenv('CHANNEL_POST_TEST_MYSQL_DSN');
if (!is_string($dsn) || $dsn === '') { echo "SKIP native channel post MySQL fixture is not configured\n"; exit(0); }
if (!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=([0-9]{1,5});)?dbname=channel_post_registry_test;charset=utf8mb4\z/D', $dsn)) {
    fwrite(STDERR, "Refusing non-ephemeral channel post MySQL configuration\n"); exit(1);
}
require_once dirname(__DIR__, 2) . '/services/ChannelPostRegistryService.php';
require_once dirname(__DIR__, 2) . '/services/MigrationRunner.php';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$user = (string)(getenv('CHANNEL_POST_TEST_MYSQL_USER') ?: 'root');
$password = (string)(getenv('CHANNEL_POST_TEST_MYSQL_PASSWORD') ?: '');
try { $pdo = new PDO($dsn, $user, $password, $options); }
catch (Throwable $error) { fwrite(STDERR, "Native fixture connection failed\n"); exit(1); }
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'channel_post_registry_test' || (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
    fwrite(STDERR, "Refusing a non-empty or unexpected native fixture schema\n"); exit(1);
}
$pdo->exec("CREATE TABLE projects (id BIGINT UNSIGNED NOT NULL PRIMARY KEY,project_key VARCHAR(64) NOT NULL UNIQUE,display_name VARCHAR(191) NOT NULL,is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE conversation_sources (id BIGINT UNSIGNED NOT NULL PRIMARY KEY,project_id BIGINT UNSIGNED NOT NULL,source_key VARCHAR(96) NOT NULL,display_name VARCHAR(191) NOT NULL,channel VARCHAR(32) NOT NULL,is_active TINYINT NOT NULL DEFAULT 1,handling_mode VARCHAR(16) NOT NULL DEFAULT 'manager',FOREIGN KEY (project_id) REFERENCES projects(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE conversations (id BIGINT UNSIGNED NOT NULL PRIMARY KEY,project_key VARCHAR(64) NOT NULL,channel VARCHAR(32) NOT NULL,status VARCHAR(32) NOT NULL DEFAULT 'ai',manager_id BIGINT UNSIGNED NULL,source_id BIGINT UNSIGNED NULL,entry_channel VARCHAR(64) NULL,external_chat_id VARCHAR(191) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE conversation_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,actor_type VARCHAR(32),payload_json LONGTEXT,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY (conversation_id) REFERENCES conversations(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$migration = (string)file_get_contents(dirname(__DIR__, 2) . '/migrations/033_channel_post_context.sql');
$runner = new MigrationRunner(dirname(__DIR__, 2) . '/migrations', $pdo);
$splitter = new ReflectionMethod(MigrationRunner::class, 'splitStatements');
foreach ($splitter->invoke($runner, $migration) as $statement) $pdo->exec($statement);

$passed = 0;
function nativePostCheck(string $name, bool $condition): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    $passed++; echo 'PASS ' . $name . "\n";
}
function nativePostReject(callable $operation, string $reason): bool
{
    try { $operation(); } catch (InvalidArgumentException $error) { return $error->getMessage() === $reason; }
    return false;
}
function nativePostConstraint(callable $operation): bool
{
    try { $operation(); } catch (PDOException $error) { return (string)$error->getCode() === '23000'; }
    return false;
}
nativePostCheck('actual forward migration creates all private channel tables on native MySQL', (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('post_channels','post_publications','post_versions','post_observations','post_entries')")->fetchColumn() === 5);
nativePostCheck('all migrated tables use InnoDB with real foreign keys', (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'post_%' AND engine='InnoDB'")->fetchColumn() === 5 && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND table_name LIKE 'post_%'")->fetchColumn() === 10);
$pdo->exec("INSERT INTO projects VALUES (1,'anytour','AnyTour',1),(2,'foreign','Foreign',1)");
$pdo->exec("INSERT INTO conversation_sources VALUES (11,1,'max_anytour_msk1','Москва','max',1,'manager'),(12,1,'tg_anytour_msk','ТГ Москва','telegram',1,'ask'),(13,1,'max_other','Другой','max',1,'manager'),(14,1,'max_ai','ИИ','max',1,'ai'),(21,2,'max_anytour_msk1','Чужой','max',1,'manager')");
$pdo->exec("INSERT INTO conversations (id,project_key,channel,status,manager_id,source_id,external_chat_id) VALUES (100,'anytour','max','manager',77,11,'501'),(101,'anytour','telegram','waiting_manager',NULL,12,'502'),(102,'anytour','max','waiting_manager',NULL,11,'503'),(200,'foreign','max','ai',NULL,21,'504')");
$registry = new ChannelPostRegistryService($pdo);
$configuration = ['project_key'=>'anytour','platform'=>'max','bot_key'=>'id9704048781_1_bot','channel_id'=>'80095447388179','source_key'=>'max_anytour_msk1','display_name'=>'Тест канала','bot_url'=>'https://max.ru/id9704048781_1_bot'];
$configured = $registry->configureChannel($configuration);
nativePostCheck('native configuration is disabled by default and canonicalizes channel identity', (int)$configured['is_enabled'] === 0 && $configured['channel_id'] === '-80095447388179');
$snapshot = ['text'=>"  Турция\n150 000 ₽ за всех  ",'format'=>'html','attachments'=>[['type'=>'image','provider'=>'max','photo_id'=>'123','url'=>'https://cdn.example.com/original.png','token'=>'PRIVATE-token']],'post_url'=>'https://max.ru/c/-80095447388179/AaE53iyDMmw','metadata'=>['source_event_id'=>'initial-channel-event','source_revision_ms'=>'1791972000001']];
nativePostCheck('disabled channel cannot register a publication', $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','AaE53iyDMmw',$snapshot) === null);
$configured = $registry->configureChannel($configuration + ['is_enabled'=>true,'expected_version'=>1]);
nativePostCheck('native locked config CAS increments version and rejects old editor', (int)$configured['config_version'] === 2 && nativePostReject(fn()=>$registry->configureChannel($configuration + ['is_enabled'=>true,'expected_version'=>1]),'channel_configuration_conflict'));
nativePostCheck('AI-only source cannot publish a manager button or mutate source policy', nativePostReject(fn()=>$registry->configureChannel(array_replace($configuration,['channel_id'=>'-999','source_key'=>'max_ai'])),'manager_source_required') && $pdo->query('SELECT handling_mode FROM conversation_sources WHERE id=14')->fetchColumn() === 'ai');
$first = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','AaE53iyDMmw',$snapshot);
nativePostCheck('native publication receives an opaque short token and exact private snapshot', $first !== null && preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D',$first['start_payload']) === 1 && $first['snapshot']['text'] === $snapshot['text']);
$duplicate = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','AaE53iyDMmw',$snapshot);
nativePostCheck('native duplicate observation appends neither a publication nor a version', $duplicate['publication_id'] === $first['publication_id'] && !empty($duplicate['duplicate_event']) && (int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn() === 1 && (int)$pdo->query('SELECT COUNT(*) FROM post_observations')->fetchColumn() === 1);
nativePostCheck('native token resolution uses exact canonical source and scope', $registry->resolveToken('anytour','max','id9704048781_1_bot',$first['start_payload'])['source_key'] === 'max_anytour_msk1' && $registry->resolveToken('foreign','max','id9704048781_1_bot',$first['start_payload']) === null && $registry->resolveToken('anytour','telegram','Any_tour_bot',$first['start_payload']) === null && $registry->resolveToken('anytour','max','id9704048781_2_bot',$first['start_payload']) === null);
$insertIdentity = $pdo->prepare('INSERT INTO post_publications (post_channel_id,source_id,external_message_id,start_payload) VALUES (?,?,?,?)');
nativePostCheck('native publication token uniqueness rejects an exact duplicate', nativePostConstraint(fn()=>$insertIdentity->execute([(int)$configured['id'],11,'DuplicateToken',$first['start_payload']])));
// Explicit fixture tokens verify the actual binary collation independently of chance.
$upperToken = 'p_' . str_repeat('A',24); $lowerToken = 'p_' . str_repeat('a',24);
$insertIdentity->execute([(int)$configured['id'],11,'UppercaseToken',$upperToken]);
$insertIdentity->execute([(int)$configured['id'],11,'LowercaseToken',$lowerToken]);
nativePostCheck('native token index preserves case instead of unicode case folding', (int)$pdo->query("SELECT COUNT(*) FROM post_publications WHERE start_payload='".$upperToken."'")->fetchColumn() === 1 && (int)$pdo->query("SELECT COUNT(*) FROM post_publications WHERE start_payload='".$lowerToken."'")->fetchColumn() === 1);
nativePostCheck('native channel ownership FK rejects orphan publication writes', nativePostConstraint(fn()=>$insertIdentity->execute([999999,11,'OrphanPublication','p_'.str_repeat('B',24)])));
nativePostCheck('native platform/channel unique identity prevents another project claim', nativePostReject(fn()=>$registry->configureChannel(array_replace($configuration,['project_key'=>'foreign','expected_version'=>2])),'channel_identity_conflict'));
$before = $pdo->query('SELECT status,manager_id,source_id FROM conversations WHERE id=100')->fetch();
$entry = $registry->recordEntry(100,$first,'authenticated-start-1');
$entryDuplicate = $registry->recordEntry(100,$first,'authenticated-start-1');
nativePostCheck('native authenticated entry and canonical event are idempotent', $entry['entry_id'] === $entryDuplicate['entry_id'] && $entryDuplicate['duplicate'] && (int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn() === 1);
nativePostCheck('native context recording preserves manager/routing ownership', $pdo->query('SELECT status,manager_id,source_id FROM conversations WHERE id=100')->fetch() === $before);
nativePostCheck('native entries reject foreign projects and wrong conversation platform', $registry->recordEntry(200,$first,'wrong-project') === null && $registry->recordEntry(101,$first,'wrong-platform') === null);
$ownEdit = $snapshot; $ownEdit['metadata']['source_event_id']='own-button-edit';
$ownObserved = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','AaE53iyDMmw',$ownEdit);
nativePostCheck('native same-content self edits receive their own durable observation receipt', $ownObserved['version_id'] === $first['version_id'] && (int)$pdo->query('SELECT COUNT(*) FROM post_observations')->fetchColumn() === 2);
$changed = $snapshot; $changed['text']='Новая цена 140 000 ₽'; $changed['attachments'][0]['photo_id']='124'; $changed['metadata']=['source_event_id'=>'smm-edit','source_revision_ms'=>'1791972000002'];
$second = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','AaE53iyDMmw',$changed);
nativePostCheck('native content edits keep token identity and append immutable versions', $second['version_number'] === 2 && $second['start_payload'] === $first['start_payload'] && $second['version_id'] !== $first['version_id']);
nativePostCheck('native earlier image getter remains exact after a newer SMM photo is observed', $registry->versionForPublication($first['publication_id'],$first['version_id'])['snapshot']['attachments'][0]['photo_id'] === '123' && $registry->versionForPublication($first['publication_id'],$first['version_id'])['snapshot']['text'] === $snapshot['text']);
nativePostCheck('native manager entry continues to show its immutable original price', $registry->managerContext(100,'anytour')['current']['text'] === $snapshot['text']);
$oldReplay = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','AaE53iyDMmw',$ownEdit);
nativePostCheck('native replay of earlier same-body bot edit cannot restore older SMM content', !empty($oldReplay['duplicate_event']) && $oldReplay['version_id'] === $second['version_id']);
$oldUpdate = $snapshot; $oldUpdate['metadata']['source_event_id']='late-distinct-event';
$oldObservation = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','AaE53iyDMmw',$oldUpdate);
nativePostCheck('native revision ordering retains millisecond precision', !empty($oldObservation['stale_observation']) && $oldObservation['version_id'] === $second['version_id']);
nativePostCheck('native already-published channel cannot change the source behind tokens', nativePostReject(fn()=>$registry->configureChannel(array_replace($configuration,['source_key'=>'max_other','is_enabled'=>true,'expected_version'=>2])),'published_channel_source_immutable'));
$claim = $registry->claimPublication($second['publication_id'],$second['version_id']);
$secondPdo = new PDO($dsn,$user,$password,$options); $otherRegistry = new ChannelPostRegistryService($secondPdo);
nativePostCheck('native second connection cannot claim an already-reserved version', $claim !== null && $otherRegistry->claimPublication($second['publication_id'],$second['version_id']) === null);
nativePostCheck('native external write CAS succeeds once only', $registry->markExternalWriteStarted($second['publication_id'],$second['version_id'],$claim['claim_token']) && !$otherRegistry->markExternalWriteStarted($second['publication_id'],$second['version_id'],$claim['claim_token']));
nativePostCheck('native attempted API write cannot re-enter writable pending state', !$registry->finishPublication($second['publication_id'],$second['version_id'],$claim['claim_token'],'pending'));
$expire = $pdo->prepare("UPDATE post_versions SET claimed_at='2000-01-01 00:00:00' WHERE id=?"); $expire->execute([$second['version_id']]);
$unknown = $registry->pendingPublications('anytour','max','id9704048781_1_bot')[0];
nativePostCheck('native expiry update moves attempted writes to UNKNOWN without clearing proof', $unknown['state'] === 'unknown' && $unknown['write_attempts'] === 1 && $registry->claimPublication($second['publication_id'],$second['version_id']) === null);
$reconcile = $registry->claimPublication($second['publication_id'],$second['version_id'],true);
nativePostCheck('native UNKNOWN reconciliation never permits a second external write', $reconcile['reconcile_only'] && !$registry->markExternalWriteStarted($second['publication_id'],$second['version_id'],$reconcile['claim_token']));
nativePostCheck('native reconciliation commits a bounded safe receipt', $registry->finishPublication($second['publication_id'],$second['version_id'],$reconcile['claim_token'],'sent','',['verified'=>true,'http_results'=>[['operation'=>'get_message','http'=>200,'transport_error'=>0,'body'=>'PRIVATE-response']]]) && !str_contains(json_encode($registry->publication($second['publication_id'])['receipt']),'PRIVATE-response'));
$anotherSnapshot = $changed; $anotherSnapshot['text']='Другой пост'; $anotherSnapshot['metadata']['source_event_id']='other-publication';
$another = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','OtherPost',$anotherSnapshot);
nativePostCheck('native separate publications never share tokens or immutable version getter pairs', $another['start_payload'] !== $second['start_payload'] && $registry->versionForPublication($second['publication_id'],$another['version_id']) === null);
$a = $registry->recordEntry(102,$second); $b = $registry->recordEntry(102,$another); $aAgain = $registry->recordEntry(102,$second);
nativePostCheck('native starts lacking provider keys preserve A-to-B-to-A entry order', $a['entry_id'] < $b['entry_id'] && $b['entry_id'] < $aAgain['entry_id'] && $registry->managerContext(102,'anytour')['current']['entry_id'] === $aAgain['entry_id']);
$u = $registry->recordUnavailableEntry(102,'anytour','max'); $registry->recordEntry(102,$second); $uAgain = $registry->recordUnavailableEntry(102,'anytour','max');
nativePostCheck('native unavailable starts lacking keys get fresh current markers', $uAgain['entry_id'] > $u['entry_id'] && $registry->managerContext(102,'anytour')['current']['unavailable']);
$entryCount = (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn();
$pdo->exec("CREATE TRIGGER channel_post_reject_event BEFORE INSERT ON conversation_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic event failure'");
$rolledBack = false; try { $registry->recordEntry(100,$second,'rollback-event'); } catch (PDOException $error) { $rolledBack = true; }
nativePostCheck('native event failure rolls back entry and receipt in the same transaction', $rolledBack && (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn() === $entryCount);
$pdo->exec('DROP TRIGGER channel_post_reject_event');
nativePostCheck('native same-key retry after rollback records exactly one entry', $registry->recordEntry(100,$second,'rollback-event') !== null && (int)$pdo->query('SELECT COUNT(*) FROM post_entries')->fetchColumn() === $entryCount+1);
$pdo->exec("UPDATE conversation_sources SET is_active=0 WHERE id=11");
nativePostCheck('native retired source disables token entry and external work without removing history', $registry->resolveToken('anytour','max','id9704048781_1_bot',$first['start_payload']) === null && $registry->pendingPublications('anytour','max','id9704048781_1_bot') === [] && $registry->versionForPublication($first['publication_id'],$first['version_id'])['snapshot']['text'] === $snapshot['text']);
$pdo->exec("UPDATE conversation_sources SET is_active=1,handling_mode='ai' WHERE id=11");
nativePostCheck('native AI policy changes fail closed without modifying source configuration', $registry->publication($first['publication_id']) === null && $registry->pendingPublications('anytour','max','id9704048781_1_bot') === [] && $pdo->query('SELECT handling_mode FROM conversation_sources WHERE id=11')->fetchColumn() === 'ai');
$pdo->exec("UPDATE conversation_sources SET handling_mode='manager' WHERE id=11");
$tg = ['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'Any_tour_bot','channel_id'=>'-10012345','source_key'=>'tg_anytour_msk','display_name'=>'ТГ тест','bot_url'=>'https://t.me/Any_tour_bot','is_enabled'=>true];
$registry->configureChannel($tg);
$tgPost = $registry->registerPublication('anytour','telegram','Any_tour_bot','-10012345','5',['text'=>'ТГ: предложение','attachments'=>[['type'=>'image','provider'=>'telegram','telegram_file_id'=>'saved-original-file-id']]]);
nativePostCheck('native Telegram uses same token protocol with its own exact bot/source scope', $tgPost !== null && $tgPost['source_key'] === 'tg_anytour_msk' && $registry->resolveToken('anytour','max','id9704048781_1_bot',$tgPost['start_payload']) === null && $registry->recordEntry(101,$tgPost,'telegram-start') !== null);
$maxClockSnapshot = ['text'=>'Latest MAX clock','metadata'=>['source_event_id'=>'clock-4','source_revision_ms'=>'1791974000004']];
$maxClockFirst = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','ClockPost',$maxClockSnapshot);
$maxClockSnapshot['metadata']=['source_event_id'=>'clock-6','source_revision_ms'=>'1791974000006'];
$maxClockSame = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','ClockPost',$maxClockSnapshot);
$maxClockSnapshot['text']='Older MAX clock'; $maxClockSnapshot['metadata']=['source_event_id'=>'clock-5','source_revision_ms'=>'1791974000005'];
$maxClockOld = $registry->registerPublication('anytour','max','id9704048781_1_bot','-80095447388179','ClockPost',$maxClockSnapshot);
$tgClockSnapshot = ['text'=>'Latest TG clock','metadata'=>['source_event_id'=>'tg-clock-1004','source_revision_ms'=>'1791974000000','source_sequence'=>'1004','source_sequence_scope'=>'telegram_update_id']];
$tgClockFirst = $registry->registerPublication('anytour','telegram','Any_tour_bot','-10012345','6',$tgClockSnapshot);
$tgClockSnapshot['metadata']['source_event_id']='tg-clock-1006'; $tgClockSnapshot['metadata']['source_sequence']='1006';
$tgClockSame = $registry->registerPublication('anytour','telegram','Any_tour_bot','-10012345','6',$tgClockSnapshot);
$tgClockSnapshot['text']='Older TG clock'; $tgClockSnapshot['metadata']['source_event_id']='tg-clock-1005'; $tgClockSnapshot['metadata']['source_sequence']='1005';
$tgClockOld = $registry->registerPublication('anytour','telegram','Any_tour_bot','-10012345','6',$tgClockSnapshot);
$tgClockSnapshot['text']='Later TG date'; $tgClockSnapshot['metadata']=['source_event_id'=>'tg-clock-reset','source_revision_ms'=>'1791974001000','source_sequence'=>'1','source_sequence_scope'=>'telegram_update_id'];
$tgClockLater = $registry->registerPublication('anytour','telegram','Any_tour_bot','-10012345','6',$tgClockSnapshot);
nativePostCheck('native accepted observation clock protects unchanged MAX/TG bodies and keeps date primary', $maxClockSame['version_id'] === $maxClockFirst['version_id'] && !empty($maxClockOld['stale_observation']) && $maxClockOld['snapshot']['text'] === 'Latest MAX clock' && $tgClockSame['version_id'] === $tgClockFirst['version_id'] && !empty($tgClockOld['stale_observation']) && $tgClockOld['snapshot']['text'] === 'Latest TG clock' && $tgClockLater['version_id'] !== $tgClockFirst['version_id'] && $tgClockLater['snapshot']['text'] === 'Later TG date');
$safe = $registry->managerContext(100,'anytour');
nativePostCheck('native manager projection contains no signed URLs/provider tokens/raw snapshot', !str_contains(json_encode($safe),'PRIVATE-token') && !str_contains(json_encode($safe),'cdn.example.com') && $registry->managerContext(100,'foreign')['current'] === null);
nativePostCheck('native admin configuration lists only its own bounded scope', count($registry->listChannels('anytour')) === 2 && $registry->listChannels('foreign') === []);
echo 'NATIVE CHANNEL POST MYSQL OK (' . $passed . " checks)\n";
