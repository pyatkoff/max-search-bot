<?php
declare(strict_types=1);
/** Disposable SQLite/MySQL fixtures; no production connection is selected here. */
function preparationPolicyFixtureSchema(PDO $pdo): void
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/085_manager_preparation_controls.sql'));
    }else{
        $columns=$pdo->query('PRAGMA table_info(manager_prepared_replies)')->fetchAll(PDO::FETCH_ASSOC);
        if($columns&&!in_array('preparation_policy_revision',array_column($columns,'name'),true))$pdo->exec('ALTER TABLE manager_prepared_replies ADD COLUMN preparation_policy_revision CHAR(64) NULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS manager_preparation_queue_policies(project_key VARCHAR(64) PRIMARY KEY,revision BIGINT NOT NULL,rule_json TEXT NOT NULL,updated_by BIGINT NOT NULL,updated_at DATETIME NOT NULL)');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_personal_workspace_states(manager_id BIGINT PRIMARY KEY,revision BIGINT NOT NULL,state_json TEXT NOT NULL,updated_at DATETIME NOT NULL);
CREATE TABLE IF NOT EXISTS manager_personal_workspace_operations(manager_id BIGINT NOT NULL,operation_id VARCHAR(96) NOT NULL,request_hash CHAR(64) NOT NULL,result_json TEXT,created_at DATETIME NOT NULL,PRIMARY KEY(manager_id,operation_id))');
}
