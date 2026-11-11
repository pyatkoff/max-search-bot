<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/services/ManagerConversationService.php';
require_once dirname(__DIR__,2).'/services/AdminDirectoryService.php';
require_once dirname(__DIR__,2).'/services/RoutingAdminService.php';
require_once dirname(__DIR__,2).'/services/RegionalRoutingScopeService.php';
require_once __DIR__.'/regional_access_schema.php';

function regionalRoutingBind(PDO $pdo): void
{
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) {
        (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
    }
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Regional synthetic fixture']]);
}

/** Only the empty test database passed explicitly by a fixture is touched. */
function regionalRoutingSchema(PDO $pdo, bool $nativeMigration = false): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $auto=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $tables=[
        'projects'=>"id $auto,project_key VARCHAR(64) UNIQUE,display_name VARCHAR(191),is_active INTEGER NOT NULL DEFAULT 1",
        'managers'=>"id $auto,login VARCHAR(191) UNIQUE,password_hash VARCHAR(255),display_name VARCHAR(191),email VARCHAR(191),role VARCHAR(16) NOT NULL DEFAULT 'manager',is_active INTEGER NOT NULL DEFAULT 1,is_working INTEGER NOT NULL DEFAULT 0,priority INTEGER NOT NULL DEFAULT 0,last_login_at DATETIME NULL",
        'manager_projects'=>'manager_id BIGINT UNSIGNED NOT NULL,project_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(manager_id,project_id)',
        'manager_groups'=>"id $auto,project_id BIGINT UNSIGNED NOT NULL,group_key VARCHAR(64),display_name VARCHAR(191),is_active INTEGER NOT NULL DEFAULT 1,UNIQUE(project_id,group_key)",
        'manager_group_members'=>'group_id BIGINT UNSIGNED NOT NULL,manager_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(group_id,manager_id)',
        'conversation_sources'=>"id $auto,project_id BIGINT UNSIGNED,source_key VARCHAR(64),display_name VARCHAR(191),channel VARCHAR(32),handling_mode VARCHAR(32),suppress_channel_offer INTEGER DEFAULT 0,primary_group_id BIGINT UNSIGNED NULL,fallback_mode VARCHAR(16),fallback_group_id BIGINT UNSIGNED NULL,fallback_after_minutes INTEGER,is_active INTEGER DEFAULT 1",
        'customers'=>"id $auto,display_name VARCHAR(191),phone VARCHAR(64),email VARCHAR(191)",
        'manager_customer_names'=>'customer_id BIGINT UNSIGNED PRIMARY KEY,working_name VARCHAR(191),version INTEGER',
        'conversations'=>"id $auto,customer_id BIGINT UNSIGNED,customer_channel_id BIGINT UNSIGNED NULL,project_key VARCHAR(64),source_id BIGINT UNSIGNED NULL,channel VARCHAR(32),entry_channel VARCHAR(64),attribution_region VARCHAR(64),attribution_campaign VARCHAR(64),status VARCHAR(32),lead_stage_key VARCHAR(64),manager_id BIGINT UNSIGNED NULL,started_at DATETIME,last_message_at DATETIME NULL,closed_at DATETIME NULL,external_chat_id VARCHAR(64),metadata_json TEXT,is_test INTEGER NOT NULL DEFAULT 0",
        'messages'=>"id $auto,conversation_id BIGINT UNSIGNED,direction VARCHAR(16),sender_type VARCHAR(16),sender_id VARCHAR(64),channel VARCHAR(32),external_message_id VARCHAR(64),text TEXT,metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'conversation_events'=>"id $auto,conversation_id BIGINT UNSIGNED,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id BIGINT UNSIGNED NULL,payload_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'manager_conversation_reads'=>'manager_id BIGINT UNSIGNED,conversation_id BIGINT UNSIGNED,last_read_message_id BIGINT UNSIGNED,PRIMARY KEY(manager_id,conversation_id)',
        'manager_assignments'=>"id $auto,conversation_id BIGINT UNSIGNED,manager_id BIGINT UNSIGNED,assignment_type VARCHAR(32),released_at DATETIME NULL",
        'admin_audit_log'=>"id $auto,actor_manager_id BIGINT UNSIGNED NULL,action VARCHAR(64),entity_type VARCHAR(64),entity_id VARCHAR(64),project_key VARCHAR(64),before_json TEXT,after_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($tables as $name=>$definition) $pdo->exec('CREATE TABLE '.$name.' ('.$definition.')'.$suffix);
    if ($nativeMigration) {
        require_once dirname(__DIR__,2).'/services/MigrationRunner.php';
        $runner=new MigrationRunner(dirname(__DIR__,2).'/migrations',$pdo);
        $split=new ReflectionMethod(MigrationRunner::class,'splitStatements');
        foreach ($split->invoke($runner,(string)file_get_contents(dirname(__DIR__,2).'/migrations/099_partner_regional_access.sql')) as $sql) $pdo->exec($sql);
    } else regionalAccessFixtureSchema($pdo);
    $pdo->exec("INSERT INTO projects(id,project_key,display_name,is_active) VALUES(1,'anytour','Regional synthetic fixture',1),(2,'other','Other synthetic fixture',1);
        INSERT INTO managers(id,login,display_name,role,is_active,is_working) VALUES
        (7,'regional-native-kras','Synthetic Krasnoyarsk','manager',1,1),
        (8,'regional-native-samara','Synthetic Samara','manager',1,1),
        (9,'regional-native-legacy','Synthetic legacy','manager',1,1),
        (90,'regional-native-admin','Synthetic admin','admin',1,1);
        INSERT INTO manager_projects VALUES(7,1),(8,1),(9,1),(90,1),(90,2);
        INSERT INTO manager_regional_access VALUES(7,1,1),(8,1,1);
        INSERT INTO manager_groups(id,project_id,group_key,display_name,is_active) VALUES
        (10,1,'kras','Synthetic Krasnoyarsk group',1),(11,1,'samara','Synthetic Samara group',1),(12,2,'foreign','Other project group',1);
        INSERT INTO manager_group_members VALUES(10,7),(11,8);
        INSERT INTO conversation_sources(id,project_id,source_key,display_name,channel,handling_mode,primary_group_id,fallback_mode,fallback_after_minutes,is_active) VALUES
        (1,1,'unrestricted','Synthetic unrestricted','max','ai',NULL,'none',0,1),
        (2,1,'kras-only','Synthetic source gate','max','ai',10,'none',0,1),
        (3,1,'samara-only','Synthetic forbidden source','max','ai',11,'none',0,1);
        INSERT INTO customers(id,display_name) VALUES(1,'Synthetic customer');");
}

function regionalRoutingInstallScopes(PDO $pdo): array
{
    $selected=RegionalTerritoryCatalog::selected(['62','11131']);
    $byId=[];foreach($selected as $scope)$byId[$scope['region_id']]=$scope;
    $pdo->beginTransaction();
    try {
        RegionalRoutingScopeService::replaceGroupScopes($pdo,10,[$byId['62']]);
        RegionalRoutingScopeService::replaceGroupScopes($pdo,11,[$byId['11131']]);
        $pdo->commit();
    } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    return $byId;
}

function regionalRoutingReceipt(string $region, string $platform='max'): array
{
    return ['version'=>1,'yandex_region_id'=>$region,'name'=>'Synthetic advertising region','kind'=>'advertising',
        'source'=>$platform==='telegram'?'telegram_start_payload':($platform==='website'?'website_entry':'max_start_payload'),
        'platform'=>$platform,'recorded_at_unix'=>1794300000,'receipt_sha256'=>hash('sha256','synthetic-receipt-'.$platform.'-'.$region)];
}

function regionalRoutingConversation(PDO $pdo,int $id,string $region='62',array $extra=[]): array
{
    $platform=$extra['channel']??'max';$receipt=regionalRoutingReceipt($region,$platform);
    $row=$extra+['id'=>$id,'customer_id'=>1,'project_key'=>'anytour','source_id'=>1,'channel'=>'max',
        'status'=>'waiting_manager','manager_id'=>null,'started_at'=>'2026-11-11 12:00:00',
        'last_message_at'=>'2026-11-11 12:00:00','external_chat_id'=>'synthetic-'.$id,
        'metadata_json'=>json_encode(['first_entry_region'=>$receipt,'entry_region'=>$receipt],JSON_THROW_ON_ERROR)];
    $columns=array_keys($row);$q=$pdo->prepare('INSERT INTO conversations('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
    $q->execute(array_values($row));
    return $row;
}

function regionalRoutingStored(PDO $pdo,int $id): array
{
    $q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
