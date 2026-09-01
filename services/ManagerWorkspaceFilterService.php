<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerAuthService.php';
require_once __DIR__ . '/ManagerConversationService.php';

class ManagerWorkspaceFilterService
{
    public static function snapshot(int $managerId): array
    {
        $projects=ProjectAccessService::projectsForManager($managerId);
        $projectKeys=array_values(array_filter(array_map(static function($row){return trim((string)($row['project_key']??''));},$projects)));
        $sources=[];
        if($projectKeys){
            $sql='SELECT s.id,s.source_key,s.display_name,s.channel,p.project_key,p.display_name AS project_name '
                .'FROM conversation_sources s JOIN projects p ON p.id=s.project_id '
                .'WHERE s.is_active=1 AND p.project_key IN ('.implode(',',array_fill(0,count($projectKeys),'?')).') '
                .'ORDER BY p.display_name,s.display_name,s.id';
            $q=ConversationDb::connection()->prepare($sql);$q->execute($projectKeys);$sources=$q->fetchAll();
        }
        $manager=ManagerAuthService::byId($managerId);
        $managers=ManagerAuthService::isAdmin($manager)?ManagerConversationService::filterManagers($managerId):[];
        return['projects'=>$projects,'sources'=>$sources,'managers'=>$managers];
    }
}
