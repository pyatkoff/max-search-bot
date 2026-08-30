<?php

declare(strict_types=1);

final class AdminProjectAccessHealth
{
    public static function collect(PDO $pdo,int $sampleLimit=50): array
    {
        $sampleLimit=max(1,min(100,$sampleLimit));

        $activeAdmins=(int)$pdo->query("SELECT COUNT(*) FROM managers WHERE role='admin' AND is_active=1")->fetchColumn();
        $activeProjects=(int)$pdo->query('SELECT COUNT(*) FROM projects WHERE is_active=1')->fetchColumn();
        $expectedLinks=$activeAdmins*$activeProjects;

        $missingSql="SELECT m.id AS manager_id,m.login,p.id AS project_id,p.project_key
            FROM managers m
            CROSS JOIN projects p
            LEFT JOIN manager_projects mp ON mp.manager_id=m.id AND mp.project_id=p.id
            WHERE m.role='admin' AND m.is_active=1 AND p.is_active=1 AND mp.manager_id IS NULL";
        $missingCount=(int)$pdo->query('SELECT COUNT(*) FROM ('.$missingSql.') missing_admin_project_access')->fetchColumn();
        $missing=$pdo->query($missingSql.' ORDER BY m.id,p.id LIMIT '.$sampleLimit)->fetchAll();

        return [
            'ok'=>$missingCount===0,
            'active_admins'=>$activeAdmins,
            'active_projects'=>$activeProjects,
            'expected_links'=>$expectedLinks,
            'missing_count'=>$missingCount,
            'missing'=>array_map(static function(array $row):array{
                return [
                    'manager_id'=>(int)$row['manager_id'],
                    'login'=>(string)$row['login'],
                    'project_id'=>(int)$row['project_id'],
                    'project_key'=>(string)$row['project_key'],
                ];
            },$missing),
        ];
    }
}
