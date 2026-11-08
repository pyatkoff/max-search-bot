<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/repair_max_webhook_fixture.php';
require_once dirname(__DIR__).'/services/SalesPipelineService.php';

// Real goal owner and CRM mutations; provider transport never leaves this fixture.
class MaxSearchApi
{
    public static array $queued = [];
    public static function queueMetrikaGoal($chat, string $goal, ?array $context = null): bool
    {
        self::$queued[] = ['chat'=>(string)$chat,'goal'=>$goal,'conversation_id'=>(int)($context['conversation_id'] ?? 0)];
        return true;
    }
}

$passed = 0;
function conversionBoundaryCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}

$pdo = repairFixture();
$global = ProjectConfig::all();
$pdo->exec("ALTER TABLE conversations ADD COLUMN is_test INTEGER DEFAULT 0;
    ALTER TABLE conversations ADD COLUMN lead_stage_key TEXT DEFAULT 'new';
    ALTER TABLE conversations ADD COLUMN lead_outcome TEXT DEFAULT 'open';
    ALTER TABLE conversations ADD COLUMN lead_close_reason TEXT;
    ALTER TABLE conversations ADD COLUMN lead_outcome_note TEXT;
    ALTER TABLE conversations ADD COLUMN lead_sale_amount TEXT;
    ALTER TABLE conversations ADD COLUMN lead_sale_date TEXT;
    ALTER TABLE conversations ADD COLUMN lead_outcome_updated_at TEXT;
    ALTER TABLE conversations ADD COLUMN lead_outcome_manager_id INTEGER;
    CREATE TABLE lead_stages(stage_key TEXT PRIMARY KEY,is_active INTEGER,is_terminal INTEGER,is_won INTEGER);
    CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,from_stage_key TEXT,to_stage_key TEXT,changed_by_manager_id INTEGER);
    CREATE TABLE lead_close_reasons(reason_key TEXT PRIMARY KEY,is_active INTEGER);
    INSERT INTO lead_stages VALUES('new',1,0,0),('working',1,0,0),('offer_sent',1,0,0),('won',1,1,1);");
$apple = null;
conversionBoundaryCheck('synthetic repair conversation uses explicit core scope', ConversationRecorder::inboundForProject('appleservice39',[
    'platform'=>'max','type'=>'message','source_key'=>'max:appleservice39-main','message_id'=>'analytics_samechat','text'=>'Synthetic repair request',
    'user'=>['chat_id'=>-7001,'external_user_id'=>'7001','first_name'=>'Synthetic']],$apple));
foreach (['telegram'=>110,'website'=>120] as $channel=>$id) {
    $pdo->prepare('INSERT INTO customers(id,display_name) VALUES(?,?)')->execute([$id,'Synthetic legacy channel']);
    $pdo->prepare('INSERT INTO customer_channels(id,customer_id,project_key,channel,external_user_id,external_chat_id) VALUES(?,?,?,?,?,?)')->execute([$id,$id,'anytour',$channel,'7001','-7001']);
    $pdo->prepare('INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,channel,external_chat_id,status,manager_id) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$id,$id,'anytour',$channel,'-7001','manager',77]);
}
$events = $pdo->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json) VALUES(?,?,?,?)');
foreach ([100,110,120,$apple] as $id) {
    $events->execute([$id,'waiting_manager','customer','{}']);
    $events->execute([$id,'manager_message','manager','{}']);
}

conversionBoundaryCheck('same MAX chat is separate by canonical project', repairConversation($pdo,'anytour',7001)['id'] !== $apple && repairConversation($pdo,'appleservice39',7001)['external_chat_id'] === '-7001');
conversionBoundaryCheck('repair manager reply cannot enter legacy goals', !MetrikaConversionGoalService::managerReply($apple));
conversionBoundaryCheck('repair customer reply cannot enter legacy goals', !MetrikaConversionGoalService::customerReplyAfterManager($apple));
conversionBoundaryCheck('repair manager request cannot enter legacy observers', !MetrikaConversionGoalService::managerRequest($apple));
conversionBoundaryCheck('repair sales stage cannot enter legacy goals', !MetrikaConversionGoalService::salesStage($apple,'working'));
conversionBoundaryCheck('repair sale outcome cannot enter legacy goals', !MetrikaConversionGoalService::saleOutcome($apple,'won'));
conversionBoundaryCheck('foreign and missing conversations make no provider calls', !MetrikaConversionGoalService::saleOutcome(999999,'won') && MaxSearchApi::$queued === []);
conversionBoundaryCheck('foreign calls create no goal markers', (int)$pdo->query('SELECT COUNT(*) FROM conversation_events WHERE conversation_id='.(int)$apple." AND event_type LIKE 'metrika_%'")->fetchColumn() === 0);

// An old/forged marker cannot make a foreign conversion report authorized success.
$events->execute([$apple,'metrika_max_offer_sent','system','{"target":"max_offer_sent"}']);
conversionBoundaryCheck('project fence precedes marker idempotency', !MetrikaConversionGoalService::salesStage($apple,'offer_sent') && MaxSearchApi::$queued === []);
$pdo->exec('DELETE FROM conversation_events WHERE conversation_id='.(int)$apple." AND event_type='metrika_max_offer_sent'");

foreach (['max'=>100,'telegram'=>110,'website'=>120] as $channel=>$id) {
    conversionBoundaryCheck('legacy '.$channel.' manager reply goal remains unchanged', MetrikaConversionGoalService::managerReply($id));
    conversionBoundaryCheck('legacy '.$channel.' sales stage goal remains unchanged', MetrikaConversionGoalService::salesStage($id,'working'));
    conversionBoundaryCheck('legacy '.$channel.' sale goal remains unchanged', MetrikaConversionGoalService::saleOutcome($id,'won'));
}
conversionBoundaryCheck('all legacy channels retain exact goals and conversation IDs', array_column(MaxSearchApi::$queued,'conversation_id') === [100,100,100,110,110,110,120,120,120]
    && array_column(MaxSearchApi::$queued,'goal') === ['max_manager_reply','max_lead_working','max_sale','max_manager_reply','max_lead_working','max_sale','max_manager_reply','max_lead_working','max_sale']);
$queued = MaxSearchApi::$queued;
conversionBoundaryCheck('legacy idempotency still prevents repeat queue calls', MetrikaConversionGoalService::saleOutcome(100,'won') && MaxSearchApi::$queued === $queued);

conversionBoundaryCheck('repair CRM stage mutation still succeeds', SalesPipelineService::setStage($apple,'working',77));
conversionBoundaryCheck('repair CRM sale outcome still succeeds', SalesPipelineService::setOutcome($apple,'won',null,'Synthetic repair completion',77,'1000.00','2026-11-08'));
conversionBoundaryCheck('repair CRM completion and stage remain one business result', SalesPipelineService::setOutcomeAndStage($apple,'won',null,'Synthetic repair completion',77,'1200.00','2026-11-08','won','working'));
$row = $pdo->query('SELECT lead_stage_key,lead_outcome,lead_sale_amount FROM conversations WHERE id='.(int)$apple)->fetch();
conversionBoundaryCheck('project fencing does not discard repair business data', $row === ['lead_stage_key'=>'won','lead_outcome'=>'won','lead_sale_amount'=>'1200.00']);
conversionBoundaryCheck('real repair sales mutations cannot emit AnyTour goals or modeled receipts', MaxSearchApi::$queued === $queued
    && (int)$pdo->query('SELECT COUNT(*) FROM conversation_events WHERE conversation_id='.(int)$apple." AND (event_type LIKE 'metrika_%' OR event_type='sales_pipeline_outcome_observed')")->fetchColumn() === 0);

conversionBoundaryCheck('canonical modeled outcome owner rejects other project even for same MAX user', !ModeledConversionOutcomeReceipt::observe($pdo,$apple,'won',null,77,'1200.00','2026-11-08'));
conversionBoundaryCheck('canonical modeled outcome owner keeps AnyTour MAX scope', ModeledConversionOutcomeReceipt::observe($pdo,100,'won',null,77,'1200.00','2026-11-08'));
conversionBoundaryCheck('canonical modeled outcome owner keeps AnyTour Telegram scope', ModeledConversionOutcomeReceipt::observe($pdo,110,'won',null,77,'1200.00','2026-11-08'));
conversionBoundaryCheck('canonical modeled outcome owner keeps unsupported website excluded', !ModeledConversionOutcomeReceipt::observe($pdo,120,'won',null,77,'1200.00','2026-11-08'));
conversionBoundaryCheck('global project settings were not mutated', ProjectConfig::all() === $global);
echo 'TOTAL '.$passed.' passed'.PHP_EOL;
