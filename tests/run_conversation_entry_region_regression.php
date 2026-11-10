<?php
declare(strict_types=1);

// Actual parser, recorder, SQL and catalogue; all identities and storage are synthetic.
foreach (['HOST','NAME','USER','PASS'] as $part) define('CONVERSATION_DB_'.$part,'entry-region-fixture');
require_once __DIR__.'/../services/ConversationRecorder.php';
require_once __DIR__.'/../services/ConversationAttributionService.php';
require_once __DIR__.'/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__.'/../integrations/WebsiteIncomingAdapter.php';

final class MaxSearchApi
{
    public static array $traffic = [];
    public static function getTrafficMeta($chatId): array { return self::$traffic[(string)$chatId] ?? []; }
}

// The legacy owner uses MySQL's UPDATE ORDER BY LIMIT. Keep production SQL intact;
// only translate that existing latest-row predicate for actual SQLite execution.
final class EntryRegionFixturePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $suffix=" WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1";
        if (str_starts_with($query,'UPDATE conversations SET entry_channel=COALESCE(') && str_ends_with($query,$suffix)) {
            $query=substr($query,0,-strlen($suffix))." WHERE id=(SELECT id FROM conversations".$suffix.')';
            // MySQL coerces the bound decimal string; SQLite compares storage classes.
            $query=str_replace('source_id=CASE WHEN ?>0','source_id=CASE WHEN CAST(? AS INTEGER)>0',$query);
        }
        return parent::prepare($query,$options);
    }
}

$passed = 0;
function erCheck(string $name, bool $ok): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}
function erRow(PDO $pdo, int $id): array {
    $q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);
}
function erId(PDO $pdo, string $platform, string $chat, string $project='anytour'): int {
    $q=$pdo->prepare('SELECT id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$project,$platform,$chat]);return (int)$q->fetchColumn();
}
function erCount(PDO $pdo): int { return (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='entry_region_observed'")->fetchColumn(); }
function erMax(string $region, string $reference='max-entry-1'): array {
    return ['platform'=>'max','type'=>'bot_started','source_key'=>'max:fixture',
        'start_payload'=>'123456789_region_'.$region.'_campaign_42','raw'=>['timestamp'=>$reference],
        'user'=>['chat_id'=>-9001001,'external_user_id'=>9001001,'first_name'=>'Synthetic']];
}

$pdo = new EntryRegionFixturePdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
    INSERT INTO projects VALUES(1,'anytour','Fixture',1),(2,'other','Other fixture',1);
    CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,display_name TEXT,channel TEXT,is_active INTEGER,fallback_mode TEXT);
    INSERT INTO conversation_sources VALUES(11,1,'max:fixture','MAX fixture','max',1,'immediate'),(12,1,'tg:fixture','TG fixture','telegram',1,'immediate'),(13,1,'web:fixture','Web fixture','website',1,'immediate'),(21,2,'max:other','Other fixture','max',1,'immediate');
    CREATE TABLE customers(id INTEGER PRIMARY KEY AUTOINCREMENT,display_name TEXT);
    CREATE TABLE customer_channels(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT,username TEXT,metadata_json TEXT);
    CREATE TABLE conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT,manager_id INTEGER,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,last_message_at TEXT,metadata_json TEXT);
    CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT);
    CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,sender_id TEXT,channel TEXT,external_message_id TEXT,text TEXT,metadata_json TEXT);");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Fixture'],'routing'=>['source_key'=>'max:fixture'],'messenger'=>['provider'=>'max']]);
ProjectAccessService::initializeReadOnly();

erCheck('MAX captures its first advertising entry before any customer message',ConversationRecorder::captureEntryRegion(erMax('000213')));
$maxId=erId($pdo,'max','-9001001');
$first=ConversationEntryRegionService::forChat(-9001001,'max',null,$pdo);
erCheck('Yandex region is canonicalized without becoming a travel city',$first['yandex_region_id']==='213'&&$first['name']==='Москва'&&$first['kind']==='advertising');
erCheck('first MAX capture does not synthesize a customer message or bot-start event',(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='bot_started'")->fetchColumn()===0);
erCheck('capture never creates or assigns a routing source',(int)$pdo->query('SELECT COUNT(*) FROM conversation_sources')->fetchColumn()===4&&erRow($pdo,$maxId)['source_id']===null);
$metadata=json_decode(erRow($pdo,$maxId)['metadata_json'],true);
$metadata['explicit_city']=['id'=>1,'name'=>'Москва','confirmed'=>true];$metadata['unrelated']=['keep'=>true];
$q=$pdo->prepare("UPDATE conversations SET metadata_json=?,source_id=11,entry_channel='existing-source',manager_id=77,status='manager',attribution_campaign='42' WHERE id=?");
$q->execute([json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$maxId]);
$protected=array_intersect_key(erRow($pdo,$maxId),array_flip(['source_id','entry_channel','manager_id','status','attribution_campaign']));
erCheck('an assigned reentry still records current advertising geography',ConversationRecorder::captureEntryRegion(erMax('2','max-entry-2')));
$after=erRow($pdo,$maxId);$afterMeta=json_decode($after['metadata_json'],true);
erCheck('reentry changes latest only and preserves first provenance',$afterMeta['entry_region']['yandex_region_id']==='2'&&$afterMeta['first_entry_region']===$first);
erCheck('region capture preserves manager, routing and campaign',array_intersect_key($after,array_flip(array_keys($protected)))===$protected);
erCheck('confirmed city and unrelated metadata survive geography capture',$afterMeta['explicit_city']===$metadata['explicit_city']&&$afterMeta['unrelated']===$metadata['unrelated']&&$afterMeta['_recorded_clock']===$metadata['_recorded_clock']);
erCheck('the advertising projection receives the canonical ID',$after['attribution_region']==='2');
$beforeReplay=$after['metadata_json'];$count=erCount($pdo);
ConversationEntryRegionService::record($maxId,'anytour',erMax('000213'),$pdo,1800000000);
ConversationEntryRegionService::record($maxId,'anytour',erMax('2','max-entry-2'),$pdo,1800000001);
erCheck('both old and latest native retries are idempotent',erCount($pdo)===$count&&erRow($pdo,$maxId)['metadata_json']===$beforeReplay);
foreach (['','0','-213','moscow','{region_id}',str_repeat('9',21)] as $region) {
    erCheck('invalid or missing advertising region is not defaulted: '.($region===''?'empty':$region),ConversationEntryRegionService::candidate(erMax($region))===null);
    ConversationEntryRegionService::record($maxId,'anytour',erMax($region),$pdo);
}
erCheck('invalid entries cannot erase a previously observed region',erRow($pdo,$maxId)['metadata_json']===$beforeReplay&&erCount($pdo)===$count);
erCheck('unknown positive region remains available for future catalogue enrichment',ConversationEntryRegionService::record($maxId,'anytour',erMax('99999999999999999999','max-entry-unknown'),$pdo,1800000002));
$unknown=ConversationEntryRegionService::forChat(-9001001,'max',null,$pdo);
erCheck('unknown IDs have no invented region name',$unknown['yandex_region_id']==='99999999999999999999'&&$unknown['name']==='');
erCheck('receipt is bounded and does not retain raw advertising IDs',!array_key_exists('raw',$unknown)&&!array_key_exists('yclid',$unknown)&&strlen(json_encode($unknown))<1024);
$beforeScope=erRow($pdo,$maxId)['metadata_json'];
erCheck('another project cannot update this conversation',!ConversationEntryRegionService::record($maxId,'other',erMax('54','wrong-project'),$pdo));
$wrongPlatform=erMax('54','wrong-transport');$wrongPlatform['platform']='telegram';$wrongPlatform['entry_attribution']=['region_id'=>'54'];
erCheck('another transport cannot update this conversation',!ConversationEntryRegionService::record($maxId,'anytour',$wrongPlatform,$pdo));
erCheck('wrong-scope reads do not leak geography',ConversationEntryRegionService::forChat(-9001001,'telegram',null,$pdo)===null&&ConversationEntryRegionService::forChat(-9001001,'max','other',$pdo)===null);
erCheck('failed scope checks leave the existing region byte-identical',erRow($pdo,$maxId)['metadata_json']===$beforeScope);

$telegram=TelegramIncomingAdapter::fromUpdate(['update_id'=>7001,'message'=>['message_id'=>91,'from'=>['id'=>9001002,'first_name'=>'Synthetic'],'text'=>'/start@Fixture_bot 123456789_region_0002_campaign_42']]);
$telegram['source_key']='tg:fixture';
erCheck('Telegram first input uses the same canonical recorder',ConversationRecorder::inbound($telegram));
$tgId=erId($pdo,'telegram','9001002');
$tg=ConversationEntryRegionService::forChat(9001002,'telegram',null,$pdo);
erCheck('Telegram stores region and provenance despite the project MAX provider',$tg['yandex_region_id']==='2'&&$tg['source']==='telegram_start_payload'&&erRow($pdo,$tgId)['attribution_region']==='2');
$changedRetry=$telegram;$changedRetry['text']='/start 123456789_region_54_campaign_42';
ConversationRecorder::inbound($changedRetry);
erCheck('duplicate native Telegram messages cannot change entry geography',ConversationEntryRegionService::forChat(9001002,'telegram',null,$pdo)===$tg);
$ordinary=$telegram;$ordinary['text']='Мы живём в регионе 213';$ordinary['message_id']='92';
erCheck('ordinary text is not treated as an advertising entry',ConversationEntryRegionService::candidate($ordinary)===null);

$web=WebsiteIncomingAdapter::fromPayload(['action'=>'start','message_id'=>'web-entry-1','attribution'=>['region_id'=>'00054']],'synthetic-web-session',9001003);
$web['source_key']='web:fixture';ConversationRecorder::inbound($web);
erCheck('website starts use the shared geography contract',ConversationEntryRegionService::forChat(9001003,'website',null,$pdo)['yandex_region_id']==='54');
$destinationOnly=WebsiteIncomingAdapter::fromPayload(['action'=>'start','message_id'=>'web-destination-only','region'=>'213'],'synthetic-other-session',9001005);
erCheck('a website destination region is never promoted to advertising origin',ConversationEntryRegionService::candidate($destinationOnly)===null);
$scoped=['platform'=>'max','type'=>'bot_started','source_key'=>'max:other','delivery_key'=>'generic-entry-1',
    'entry_attribution'=>['region_id'=>'39'],'user'=>['chat_id'=>-9001004,'external_user_id'=>9001004]];
$scopedId=null;$duplicate=null;
erCheck('explicit project entry captures geography in its authorized project',ConversationRecorder::inboundForProject('other',$scoped,$scopedId,$duplicate));
erCheck('generic recorder entry is isolated from the global project',ConversationEntryRegionService::forChat(-9001004,'max','other',$pdo)['yandex_region_id']==='39'&&ConversationEntryRegionService::forChat(-9001004,'max',null,$pdo)===null);

MaxSearchApi::$traffic['-9001001']=['region_id'=>'00022'];
ConversationAttributionService::syncByChat('max',-9001001);
erCheck('later legacy traffic synchronization also canonicalizes its region',erRow($pdo,$maxId)['attribution_region']==='22');
foreach (['invalid','0','-22','',str_repeat('9',21)] as $invalid) {
    MaxSearchApi::$traffic['-9001001']=['region_id'=>$invalid];
    ConversationAttributionService::syncByChat('max',-9001001);
}
erCheck('invalid legacy region cannot erase the canonical projection',erRow($pdo,$maxId)['attribution_region']==='22');
MaxSearchApi::$traffic['-9001001']=['region_id'=>'99999999999999999999'];
ConversationAttributionService::syncByChat('max',-9001001);
erCheck('legacy synchronization preserves a valid unknown region',erRow($pdo,$maxId)['attribution_region']==='99999999999999999999');
erCheck('legacy region synchronization leaves assigned manager and source unchanged',array_intersect_key(erRow($pdo,$maxId),array_flip(array_keys($protected)))===$protected);

$pdo->exec("CREATE TRIGGER reject_entry_region BEFORE INSERT ON conversation_events WHEN NEW.event_type='entry_region_observed' BEGIN SELECT RAISE(ABORT,'fixture-rejected'); END");
$beforeFailure=erRow($pdo,$maxId);$eventsBefore=erCount($pdo);
erCheck('failed event persistence reports failure',!ConversationEntryRegionService::record($maxId,'anytour',erMax('54','failed-entry'),$pdo,1800000003));
erCheck('region projection and provenance roll back together',erRow($pdo,$maxId)===$beforeFailure&&erCount($pdo)===$eventsBefore&&!$pdo->inTransaction());
$pdo->beginTransaction();
erCheck('capture failure is isolated inside an existing transaction',!ConversationEntryRegionService::record($maxId,'anytour',erMax('54','failed-outer-entry'),$pdo,1800000004)&&$pdo->inTransaction());
erCheck('savepoint protects caller-owned changes and original geography',erRow($pdo,$maxId)===$beforeFailure);
$pdo->rollBack();$pdo->exec('DROP TRIGGER reject_entry_region');
$pdo->exec("UPDATE conversations SET status='closed' WHERE id=".$maxId);
erCheck('closed conversations cannot accept entry reattribution',!ConversationEntryRegionService::record($maxId,'anytour',erMax('54','closed-entry'),$pdo));
erCheck('closed conversation geography is unavailable to new suggestions',ConversationEntryRegionService::forChat(-9001001,'max',null,$pdo)===null);

echo $passed." entry-region checks passed\n";
