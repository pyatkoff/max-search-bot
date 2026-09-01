<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerAuthService.php';
require_once __DIR__ . '/AuditLogService.php';
require_once __DIR__ . '/ManagerPushHealth.php';

class AdminDirectoryService
{
    public static function snapshot(): array
    {
        ProjectAccessService::ensureSchema();
        ManagerAuthService::ensureSchema();
        $pdo=ConversationDb::connection();
        $projects=$pdo->query("SELECT id,project_key,display_name,is_active FROM projects ORDER BY display_name,id")->fetchAll();
        $managers=$pdo->query("SELECT id,login,display_name,role,email,is_active,is_working,last_login_at,priority FROM managers ORDER BY is_active DESC,display_name,login,id")->fetchAll();
        $sources=$pdo->query("SELECT id,project_key,source_key,display_name,channel,is_active FROM conversation_sources ORDER BY project_key,display_name,id")->fetchAll();
        $access=[];
        $q=$pdo->query("SELECT manager_id,project_id FROM manager_projects ORDER BY manager_id,project_id");
        foreach($q->fetchAll() as $row)$access[(int)$row['manager_id']][]=(int)$row['project_id'];
        foreach($managers as &$manager){
            $manager['project_ids']=$access[(int)$manager['id']]??[];
            $manager=self::withOperationalSignals($manager,ManagerPushHealth::statusForManager($pdo,(int)$manager['id']));
        }
        unset($manager);
        return ['projects'=>$projects,'managers'=>$managers,'sources'=>$sources,'audit'=>AuditLogService::recentSummaries(50)];
    }

    public static function withOperationalSignals(array $manager,array $pushStatus): array
    {
        $manager['is_working']=(bool)($manager['is_working']??false);
        $manager['is_reachable']=(bool)($pushStatus['notification_path_usable']??false);
        $manager['reachability_reason']=(string)($pushStatus['notification_path_reason']??'unknown');
        $manager['push_subscription_count']=(int)($pushStatus['subscription_count']??0);
        $manager['healthy_push_subscription_count']=(int)($pushStatus['healthy_subscription_count']??0);
        $manager['last_push_success_at']=$pushStatus['last_success_at']??null;
        $manager['last_push_error_at']=$pushStatus['last_error_at']??null;
        return $manager;
    }

    public static function saveProject(array $data,int $actorManagerId=0): array
    {
        ProjectAccessService::ensureSchema();
        ManagerAuthService::ensureSchema();
        $id=(int)($data['project_id']??0);
        $key=strtolower(trim((string)($data['project_key']??'')));
        $name=trim((string)($data['display_name']??''));
        $active=!empty($data['is_active'])?1:0;
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/',$key))return ['ok'=>false,'error'=>'invalid_project_key'];
        if($name==='')return ['ok'=>false,'error'=>'missing_display_name'];
        $pdo=ConversationDb::connection();
        $before=$id>0?self::projectRow($id):null;
        $pdo->beginTransaction();
        try{
            if($id>0){
                $q=$pdo->prepare('UPDATE projects SET project_key=?,display_name=?,is_active=? WHERE id=?');
                $q->execute([$key,$name,$active,$id]);
                if(!$q->rowCount() && !self::projectExists($id)){
                    $pdo->rollBack();
                    return ['ok'=>false,'error'=>'not_found'];
                }
            }else{
                $q=$pdo->prepare('INSERT INTO projects (project_key,display_name,is_active) VALUES (?,?,?)');
                $q->execute([$key,$name,$active]);
                $id=(int)$pdo->lastInsertId();
            }
            if($active)self::grantProjectToActiveAdmins($pdo,$id);
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            return ['ok'=>false,'error'=>'duplicate_project_key'];
        }
        AuditLogService::record($actorManagerId,$before?'project_updated':'project_created','project',(string)$id,$key,$before,self::projectRow($id));
        return ['ok'=>true,'project_id'=>$id];
    }

    public static function saveManager(array $data,int $actorManagerId): array
    {
        ProjectAccessService::ensureSchema();
        ManagerAuthService::ensureSchema();
        $id=(int)($data['manager_id']??0);
        $login=trim((string)($data['login']??''));
        $name=trim((string)($data['display_name']??''));
        $email=trim((string)($data['email']??''));
        $role=in_array((string)($data['role']??'manager'),['admin','manager'],true)?(string)$data['role']:'manager';
        $active=!empty($data['is_active'])?1:0;
        $priority=max(-100000,min(100000,(int)($data['priority']??0)));
        $password=(string)($data['password']??'');
        $projectIds=array_values(array_unique(array_filter(array_map('intval',(array)($data['project_ids']??[])),static function($v){return $v>0;})));
        if($login===''||!preg_match('/^[A-Za-z0-9._@+-]{3,191}$/',$login))return ['ok'=>false,'error'=>'invalid_login'];
        if($id===0 && strlen($password)<8)return ['ok'=>false,'error'=>'password_too_short'];
        if($password!=='' && strlen($password)<8)return ['ok'=>false,'error'=>'password_too_short'];
        if($id===$actorManagerId && (!$active || $role!=='admin'))return ['ok'=>false,'error'=>'cannot_remove_own_admin_access'];
        if($active && $role==='manager' && !$projectIds)return ['ok'=>false,'error'=>'manager_requires_project'];
        $pdo=ConversationDb::connection();
        if($projectIds){
            $q=$pdo->prepare('SELECT id FROM projects WHERE id IN ('.implode(',',array_fill(0,count($projectIds),'?')).')');
            $q->execute($projectIds);
            $existingProjectIds=array_map('intval',array_column($q->fetchAll(),'id'));
            sort($existingProjectIds);$submittedProjectIds=$projectIds;sort($submittedProjectIds);
            if($existingProjectIds!==$submittedProjectIds)return ['ok'=>false,'error'=>'invalid_project_selection'];
        }
        $before=$id>0?self::managerRow($id):null;$pdo->beginTransaction();
        try{
            if($id>0){
                if(!self::managerExists($id)){ $pdo->rollBack(); return ['ok'=>false,'error'=>'not_found']; }
                $sql='UPDATE managers SET login=?,display_name=?,email=?,role=?,is_active=?,priority=?';$args=[$login,$name!==''?$name:null,$email!==''?$email:null,$role,$active,$priority];
                if($password!==''){$sql.=',password_hash=?';$args[]=password_hash($password,PASSWORD_DEFAULT);}
                $sql.=' WHERE id=?';$args[]=$id;
                $pdo->prepare($sql)->execute($args);
            }else{
                $q=$pdo->prepare('INSERT INTO managers (login,password_hash,display_name,email,role,is_active,priority) VALUES (?,?,?,?,?,?,?)');
                $q->execute([$login,password_hash($password,PASSWORD_DEFAULT),$name!==''?$name:null,$email!==''?$email:null,$role,$active,$priority]);
                $id=(int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM manager_projects WHERE manager_id=?')->execute([$id]);
            $ins=$pdo->prepare('INSERT IGNORE INTO manager_projects (manager_id,project_id) VALUES (?,?)');
            foreach($projectIds as $projectId)$ins->execute([$id,$projectId]);
            if($role==='admin')$pdo->prepare('INSERT IGNORE INTO manager_projects (manager_id,project_id) SELECT ?,id FROM projects WHERE is_active=1')->execute([$id]);
            $pdo->commit();
            AuditLogService::record($actorManagerId,$before?'manager_updated':'manager_created','manager',(string)$id,'',$before,self::managerRow($id));
            return ['ok'=>true,'manager_id'=>$id];
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            return ['ok'=>false,'error'=>self::isDuplicateKeyError($e)?'duplicate_login':'manager_save_failed'];
        }
    }

    private static function isDuplicateKeyError(Throwable $e): bool
    {
        if($e instanceof PDOException){
            $info=$e->errorInfo??null;
            if(is_array($info) && ((string)($info[0]??'')==='23000' || (int)($info[1]??0)===1062))return true;
        }
        return (string)$e->getCode()==='23000';
    }

    private static function grantProjectToActiveAdmins(PDO $pdo,int $projectId): void
    {
        $q=$pdo->prepare("INSERT IGNORE INTO manager_projects (manager_id,project_id) SELECT id,? FROM managers WHERE role='admin' AND is_active=1");
        $q->execute([$projectId]);
    }

    private static function managerRow(int $id): ?array
    {
        $q=ConversationDb::connection()->prepare('SELECT id,login,display_name,role,email,is_active,is_working,priority FROM managers WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();if(!$row)return null;
        $p=ConversationDb::connection()->prepare('SELECT project_id FROM manager_projects WHERE manager_id=? ORDER BY project_id');$p->execute([$id]);$row['project_ids']=array_map('intval',array_column($p->fetchAll(),'project_id'));return$row;
    }
    private static function projectRow(int $id): ?array
    {
        $q=ConversationDb::connection()->prepare('SELECT id,project_key,display_name,is_active FROM projects WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();return$row?:null;
    }
    private static function managerExists(int $id): bool
    {
        $q=ConversationDb::connection()->prepare('SELECT 1 FROM managers WHERE id=? LIMIT 1');$q->execute([$id]);return(bool)$q->fetchColumn();
    }
    private static function projectExists(int $id): bool
    {
        $q=ConversationDb::connection()->prepare('SELECT 1 FROM projects WHERE id=? LIMIT 1');$q->execute([$id]);return(bool)$q->fetchColumn();
    }
}
