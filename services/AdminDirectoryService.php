<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerAuthService.php';

class AdminDirectoryService
{
    public static function snapshot(): array
    {
        ProjectAccessService::ensureSchema();
        ManagerAuthService::ensureSchema();
        $pdo=ConversationDb::connection();
        $projects=$pdo->query("SELECT id,project_key,display_name,is_active FROM projects ORDER BY display_name,id")->fetchAll();
        $managers=$pdo->query("SELECT id,login,display_name,role,email,is_active,last_login_at FROM managers ORDER BY is_active DESC,display_name,login,id")->fetchAll();
        $access=[];
        $q=$pdo->query("SELECT manager_id,project_id FROM manager_projects ORDER BY manager_id,project_id");
        foreach($q->fetchAll() as $row)$access[(int)$row['manager_id']][]=(int)$row['project_id'];
        foreach($managers as &$manager)$manager['project_ids']=$access[(int)$manager['id']]??[];
        unset($manager);
        return ['projects'=>$projects,'managers'=>$managers];
    }

    public static function saveProject(array $data): array
    {
        ProjectAccessService::ensureSchema();
        $id=(int)($data['project_id']??0);
        $key=strtolower(trim((string)($data['project_key']??'')));
        $name=trim((string)($data['display_name']??''));
        $active=!empty($data['is_active'])?1:0;
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/',$key))return ['ok'=>false,'error'=>'invalid_project_key'];
        if($name==='')return ['ok'=>false,'error'=>'missing_display_name'];
        $pdo=ConversationDb::connection();
        if($id>0){
            $q=$pdo->prepare('UPDATE projects SET project_key=?,display_name=?,is_active=? WHERE id=?');
            try{$q->execute([$key,$name,$active,$id]);}catch(Throwable $e){return ['ok'=>false,'error'=>'duplicate_project_key'];}
            if(!$q->rowCount() && !self::projectExists($id))return ['ok'=>false,'error'=>'not_found'];
        }else{
            $q=$pdo->prepare('INSERT INTO projects (project_key,display_name,is_active) VALUES (?,?,?)');
            try{$q->execute([$key,$name,$active]);}catch(Throwable $e){return ['ok'=>false,'error'=>'duplicate_project_key'];}
            $id=(int)$pdo->lastInsertId();
        }
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
        $password=(string)($data['password']??'');
        $projectIds=array_values(array_unique(array_filter(array_map('intval',(array)($data['project_ids']??[])),static function($v){return $v>0;})));
        if($login===''||!preg_match('/^[A-Za-z0-9._@+-]{3,191}$/',$login))return ['ok'=>false,'error'=>'invalid_login'];
        if($id===0 && strlen($password)<8)return ['ok'=>false,'error'=>'password_too_short'];
        if($password!=='' && strlen($password)<8)return ['ok'=>false,'error'=>'password_too_short'];
        if($id===$actorManagerId && (!$active || $role!=='admin'))return ['ok'=>false,'error'=>'cannot_remove_own_admin_access'];
        $pdo=ConversationDb::connection();$pdo->beginTransaction();
        try{
            if($id>0){
                if(!self::managerExists($id)){ $pdo->rollBack(); return ['ok'=>false,'error'=>'not_found']; }
                $sql='UPDATE managers SET login=?,display_name=?,email=?,role=?,is_active=?';$args=[$login,$name!==''?$name:null,$email!==''?$email:null,$role,$active];
                if($password!==''){$sql.=',password_hash=?';$args[]=password_hash($password,PASSWORD_DEFAULT);}
                $sql.=' WHERE id=?';$args[]=$id;
                $pdo->prepare($sql)->execute($args);
            }else{
                $q=$pdo->prepare('INSERT INTO managers (login,password_hash,display_name,email,role,is_active) VALUES (?,?,?,?,?,?)');
                $q->execute([$login,password_hash($password,PASSWORD_DEFAULT),$name!==''?$name:null,$email!==''?$email:null,$role,$active]);
                $id=(int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM manager_projects WHERE manager_id=?')->execute([$id]);
            $ins=$pdo->prepare('INSERT IGNORE INTO manager_projects (manager_id,project_id) VALUES (?,?)');
            foreach($projectIds as $projectId){if(self::projectExists($projectId))$ins->execute([$id,$projectId]);}
            if($role==='admin'){
                $pdo->prepare('INSERT IGNORE INTO manager_projects (manager_id,project_id) SELECT ?,id FROM projects WHERE is_active=1')->execute([$id]);
            }
            $pdo->commit();
            return ['ok'=>true,'manager_id'=>$id];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return ['ok'=>false,'error'=>'duplicate_login'];}
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
