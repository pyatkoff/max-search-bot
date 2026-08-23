<?php
require_once __DIR__ . '/../contracts/LeadDestinationInterface.php';
require_once __DIR__ . '/../services/ManagerSummaryService.php';
require_once __DIR__ . '/../services/ProjectConfig.php';

class BitrixLeadDestination implements LeadDestinationInterface
{
    public function plan(array $tripState, array $userContext = []): array
    {
        return [
            'provider' => 'bitrix',
            'project_id' => ProjectConfig::projectId(),
            'claim_hl' => (int)ProjectConfig::get('leads.claim_hl', 0),
            'iblock_id' => (int)ProjectConfig::get('leads.iblock_id', 0),
            'section_id' => (int)ProjectConfig::get('leads.section_id', 0),
            'status_id' => (int)ProjectConfig::get('leads.status_id', 0),
            'uon_source_id' => (int)ProjectConfig::get('leads.uon_source_id', 0),
            'summary' => ManagerSummaryService::build($tripState, $userContext),
            'trip_state' => $tripState,
            'user_context' => $userContext,
        ];
    }
}
