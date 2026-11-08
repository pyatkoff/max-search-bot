<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/manager-request-profile-http.php';

/** Real private API/auth/profile owners; only the external goal queue is local. */
function salesOutcomeTripHttpFixture(string $directory): array
{
    $fixture=managerRequestProfileHttpFixture($directory);
    $p=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec("PRAGMA foreign_keys=ON;
UPDATE conversations SET id=10;
UPDATE messages SET conversation_id=10;
UPDATE manager_conversation_reads SET conversation_id=10;
ALTER TABLE conversations ADD COLUMN customer_channel_id INTEGER DEFAULT 10;
ALTER TABLE conversations ADD COLUMN is_test INTEGER DEFAULT 0;
ALTER TABLE conversations ADD COLUMN lead_outcome TEXT DEFAULT 'open';
ALTER TABLE conversations ADD COLUMN lead_close_reason TEXT;
ALTER TABLE conversations ADD COLUMN lead_outcome_note TEXT;
ALTER TABLE conversations ADD COLUMN lead_sale_amount TEXT;
ALTER TABLE conversations ADD COLUMN lead_sale_date TEXT;
ALTER TABLE conversations ADD COLUMN lead_outcome_updated_at TEXT;
ALTER TABLE conversations ADD COLUMN lead_outcome_manager_id INTEGER;
ALTER TABLE conversation_events ADD COLUMN actor_id TEXT;
CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT);
INSERT INTO customer_channels VALUES(10,10,'anytour','telegram');
CREATE TABLE lead_stages(stage_key TEXT PRIMARY KEY,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER,is_terminal INTEGER,is_won INTEGER);
INSERT INTO lead_stages VALUES('new','Новая','#888',1,1,0,0),('custom_won','Продажа','#080',2,1,1,1),('custom_lost','Отказ','#800',3,1,1,0);
CREATE TABLE lead_close_reasons(reason_key TEXT PRIMARY KEY,display_name TEXT,sort_order INTEGER,is_active INTEGER);
INSERT INTO lead_close_reasons VALUES('price','Цена',1,1);
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,from_stage_key TEXT,to_stage_key TEXT,changed_by_manager_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE lead_tags(id INTEGER PRIMARY KEY,tag_key TEXT,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER);
CREATE TABLE conversation_lead_tags(conversation_id INTEGER,tag_id INTEGER,added_by_manager_id INTEGER);
CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY,conversation_id INTEGER,title TEXT,due_at_utc TEXT,status TEXT,is_pinned INTEGER,assigned_manager_id INTEGER,created_by_manager_id INTEGER,completed_at_utc TEXT,reminder_attempted_at_utc TEXT,reminder_notified_at_utc TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE fixture_goal_calls(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id TEXT,target TEXT);
CREATE TABLE lead_outcome_trip_bindings(
 id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,
 operation_id TEXT NOT NULL,request_sha256 TEXT NOT NULL,trip_id TEXT,profile_version INTEGER,profile_sha256 TEXT,
 outcome_json BLOB NOT NULL,outcome_sha256 TEXT NOT NULL,scope_json BLOB NOT NULL,created_at TEXT NOT NULL,
 UNIQUE(manager_id,operation_id),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
 FOREIGN KEY(conversation_id,profile_version) REFERENCES manager_request_profiles(conversation_id,version) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE INDEX outcome_http_conversation ON lead_outcome_trip_bindings(conversation_id,id);");
    if(!copy($fixture['root'].'/manager/pipeline-api.php',$fixture['webroot'].'/manager/pipeline-api.php'))throw new RuntimeException('outcome_api_copy_failed');
    file_put_contents($fixture['webroot'].'/maxsearchclass.php',<<<'PHP'
<?php
class MaxSearchApi {
    public static function getAiSearchContext($chat):array{return [];}
    public static function queueMetrikaGoal($chat,$target,$scope):bool {
        ConversationDb::connection()->prepare('INSERT INTO fixture_goal_calls(chat_id,target) VALUES(?,?)')->execute([$chat,$target]);
        return true;
    }
}
PHP);
    file_put_contents($fixture['webroot'].'/config.php',"\n\$fixturePdo=ConversationDb::connection();\n\$fixturePdo->exec('PRAGMA foreign_keys=ON');\n\$fixturePdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));\n\$fixturePdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>gmdate('Y-m-d H:i:s'));\nProjectAccessService::initializeReadOnly();\n",FILE_APPEND);
    return $fixture;
}
