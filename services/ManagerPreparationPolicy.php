<?php

declare(strict_types=1);

/** One resolver for account and project preparation rules. It never sends or owns jobs. */
final class ManagerPreparationPolicy
{
    public const MAX_CONVERSATIONS = 200;

    public static function apply(?array $policy, array $input, ?int $now = null): array
    {
        $now ??= time();
        $policy ??= ['next_rule_version'=>1,'all'=>null,'conversations'=>[]];
        self::validate($policy);
        $before=$policy;
        $scope=$input['scope']??null;
        if(!in_array($scope,['all','conversation','clear','stop_all'],true))throw new InvalidArgumentException('invalid_preparation_policy');
        if($scope==='stop_all'&&array_key_exists('enabled',$input)&&$input['enabled']!==false)throw new InvalidArgumentException('invalid_preparation_policy');
        $cid=$input['conversation_id']??null;
        if(in_array($scope,['conversation','clear'],true)&&(!is_int($cid)||$cid<=0))throw new InvalidArgumentException('invalid_conversation_id');
        $version=$policy['next_rule_version']++;
        if($scope==='clear'){
            if(($policy['conversations'][(string)$cid]['inherit']??false)===true)return $before;
            // A tombstone is an inheritance generation, not an active exception.
            // Deleting it would revive A after all-on → dialog-off → clear.
            $policy['conversations'][(string)$cid]=['inherit'=>true,'version'=>$version];
        }else{
            $enabled=$scope==='stop_all'?false:($input['enabled']??null);
            $until=$input['until_utc']??null;
            if(!is_bool($enabled)||($until!==null&&(!is_string($until)||self::timestamp($until)===null||self::timestamp($until)<=$now))
                ||(!$enabled&&$until!==null))throw new InvalidArgumentException('invalid_preparation_policy');
            $old=$scope==='conversation'?($policy['conversations'][(string)$cid]??null):$policy['all'];
            $positive=false;if($scope!=='conversation'&&!$enabled)foreach($policy['conversations'] as $entry)if(($entry['enabled']??false)===true){$positive=true;break;}
            if($old!==null&&($old['enabled']??null)===$enabled&&($old['until_utc']??null)===$until&&!$positive)return $before;
            $rule=['enabled'=>$enabled,'until_utc'=>$until,'version'=>$version];
            if($scope==='conversation')$policy['conversations'][(string)$cid]=$rule;
            else{
                $policy['all']=$rule;
                if(!$enabled)foreach($policy['conversations'] as $id=>$old)if(($old['enabled']??false)===true)
                    $policy['conversations'][$id]=['inherit'=>true,'version'=>$policy['next_rule_version']++];
            }
        }
        if(count($policy['conversations'])>self::MAX_CONVERSATIONS)throw new InvalidArgumentException('preparation_rule_limit');
        return $policy;
    }

    public static function resolve(?array $policy,int $owner,int $cid,string $project,?int $now=null): array
    {
        $now ??= time();
        if($policy===null)return ['allowed'=>true,'binding'=>null,'mode'=>'legacy','scope'=>'legacy','until_utc'=>null];
        self::validate($policy);
        $entry=$policy['conversations'][(string)$cid]??null;
        $own=$entry!==null&&!($entry['inherit']??false);
        $rule=$own?$entry:$policy['all'];
        // A first exception in another dialogue does not change this legacy
        // binding. An explicit inherit tombstone still fences off/clear ABA.
        if($rule===null&&$entry===null)return self::resolve(null,$owner,$cid,$project,$now);
        $scope=$owner===0?'queue':($own?'conversation':($rule===null?'legacy':'all'));
        $expired=$rule!==null&&$rule['until_utc']!==null&&self::timestamp($rule['until_utc'])<=$now;
        $allowed=$rule===null||($rule['enabled']&&!$expired);
        $binding=hash('sha256',json_encode(['preparation_policy_v1',$project,$owner,$cid,$scope,$rule,$entry['version']??null],JSON_THROW_ON_ERROR));
        return ['allowed'=>$allowed,'binding'=>$binding,'mode'=>$rule===null?'legacy':($expired?'expired':($allowed?'enabled':'disabled')),
            'scope'=>$scope,'until_utc'=>$rule['until_utc']??null];
    }

    public static function effective(PDO $pdo,int $owner,int $cid,string $project,?int $now=null): array
    {
        if($owner===0){
            $q=$pdo->prepare('SELECT manager_id,status FROM conversations WHERE id=?');$q->execute([$cid]);$conversation=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            // Owner-only explicit work outside the real waiting queue keeps its
            // legacy contract. A queue rule cannot broaden/reconfigure that lane.
            if(!$conversation||$conversation['manager_id']!==null||$conversation['status']!=='waiting_manager')return self::resolve(null,$owner,$cid,$project,$now);
            $record=self::queueRecord($pdo,$project);$policy=$record['rule'];
        }
        else{
            $q=$pdo->prepare('SELECT state_json FROM manager_personal_workspace_states WHERE manager_id=?');$q->execute([$owner]);
            $json=$q->fetchColumn();$q->closeCursor();
            $state=$json===false?[]:json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($state)||array_key_exists('ai_preparation',$state)&&!is_array($state['ai_preparation']))throw new RuntimeException('invalid_preparation_storage');
            $policy=$state['ai_preparation']??null;
            if($policy!==null&&!is_array($policy))throw new RuntimeException('invalid_preparation_storage');
        }
        return self::resolve($policy,$owner,$cid,$project,$now);
    }

    public static function matchesJob(PDO $pdo,array $row,?int $now=null): bool
    {
        $q=$pdo->prepare('SELECT manager_id,status,project_key FROM conversations WHERE id=?');$q->execute([(int)$row['conversation_id']]);
        $conversation=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$conversation||$conversation['project_key']!==$row['project_key']||(int)($conversation['manager_id']??0)!==(int)$row['manager_id'])return false;
        if(($row['purpose']??'background')==='background'&&$conversation['status']!==((int)$row['manager_id']===0?'waiting_manager':'manager'))return false;
        $decision=self::effective($pdo,(int)$row['manager_id'],(int)$row['conversation_id'],(string)$row['project_key'],$now);
        return self::decisionMatches($decision,$row);
    }

    /** Policy metadata is selected with the bounded inventory, never N+1 reads. */
    public static function matchesInventory(array $row,?int $now=null): bool
    {
        if(($row['policy_current_project']??null)!==$row['project_key']||(int)($row['policy_current_owner']??0)!==(int)$row['manager_id'])return false;
        if(($row['purpose']??'background')==='background'&&($row['policy_current_status']??null)!==((int)$row['manager_id']===0?'waiting_manager':'manager'))return false;
        $json=(int)$row['manager_id']>0?($row['policy_personal_json']??null):
            (($row['policy_current_owner']===null&&$row['policy_current_status']==='waiting_manager')?($row['policy_queue_json']??null):null);
        $policy=$json===null?null:json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);
        if($policy!==null&&!is_array($policy))throw new RuntimeException('invalid_preparation_storage');
        return self::decisionMatches(self::resolve($policy,(int)$row['manager_id'],(int)$row['conversation_id'],(string)$row['project_key'],$now),$row);
    }

    private static function decisionMatches(array $decision,array $row): bool
    {
        $bound=$row['preparation_policy_revision']??null;
        return $decision['allowed']&&($bound===null?$decision['binding']===null:
            is_string($bound)&&is_string($decision['binding'])&&hash_equals($bound,$decision['binding']));
    }

    /** Same stable locks as policy mutations, before the prepared-job lock/CAS. */
    public static function lockJobPolicy(PDO $pdo,array $row): bool
    {
        if(!$pdo->inTransaction())throw new LogicException('policy_lock_requires_transaction');
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        if((int)$row['manager_id']>0){
            // Lock the policy owner for serialization, not as a second viewer
            // authorization gate. Canonical snapshots allow authorized admins
            // to inspect an inactive owner's dialogue without taking it.
            $q=$pdo->prepare('SELECT id FROM managers WHERE id=?'.$suffix);$q->execute([(int)$row['manager_id']]);
        }else{
            $q=$pdo->prepare('SELECT id FROM projects WHERE project_key=?'.$suffix);$q->execute([$row['project_key']]);
        }
        $exists=$q->fetchColumn();$q->closeCursor();
        return $exists!==false&&self::matchesJob($pdo,$row);
    }

    public static function queueAllowed(PDO $pdo,int $actor,string $project,bool $lock=false): bool
    {
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.is_active=1 AND m.role='admin' AND p.project_key=? AND p.is_active=1".$suffix);
        $q->execute([$actor,$project]);$allowed=(bool)$q->fetchColumn();$q->closeCursor();return $allowed;
    }

    public static function queueView(PDO $pdo,int $actor,string $project): array
    {
        if(!self::queueAllowed($pdo,$actor,$project))return ['ok'=>false,'error'=>'forbidden'];
        return ['ok'=>true,'queue_policy'=>self::queueRecord($pdo,$project)];
    }

    public static function mutateQueue(PDO $pdo,int $actor,string $project,array $input): array
    {
        if(!self::queueAllowed($pdo,$actor,$project))return ['ok'=>false,'error'=>'forbidden'];
        $expected=$input['expected_queue_version']??null;
        if(!is_int($expected)||$expected<0)return ['ok'=>false,'error'=>'invalid_version'];
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id FROM projects WHERE project_key=?'.$suffix);$q->execute([$project]);$q->fetchColumn();$q->closeCursor();
        if(!self::queueAllowed($pdo,$actor,$project,true))return ['ok'=>false,'error'=>'forbidden'];
        // Use a current read after the canonical project lock, even if the
        // caller established an older REPEATABLE READ snapshot during auth.
        $record=self::queueRecord($pdo,$project,true);
        if($record['version']!==$expected)return ['ok'=>false,'error'=>'conflict','queue_policy_operation'=>true];
        if(($input['scope']??'')!=='all'&&($input['scope']??'')!=='stop_all')return ['ok'=>false,'error'=>'invalid_preparation_policy'];
        try{$rule=self::apply($record['rule'],$input);}catch(InvalidArgumentException $e){return ['ok'=>false,'error'=>$e->getMessage()];}
        $json=json_encode($rule,JSON_THROW_ON_ERROR);$date=gmdate('Y-m-d H:i:s');
        if($expected===0){
            $q=$pdo->prepare('INSERT INTO manager_preparation_queue_policies (project_key,revision,rule_json,updated_by,updated_at) VALUES (?,1,?,?,?)');
            $q->execute([$project,$json,$actor,$date]);
        }else{
            $q=$pdo->prepare('UPDATE manager_preparation_queue_policies SET revision=revision+1,rule_json=?,updated_by=?,updated_at=? WHERE project_key=? AND revision=?');
            $q->execute([$json,$actor,$date,$project,$expected]);
            if($q->rowCount()!==1)throw new RuntimeException('queue_policy_cas_failure');
        }
        return ['ok'=>true,'queue_policy_operation'=>true];
    }

    private static function queueRecord(PDO $pdo,string $project,bool $lock=false): array
    {
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT revision,rule_json FROM manager_preparation_queue_policies WHERE project_key=?'.$suffix);$q->execute([$project]);
        $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row)return ['project_key'=>$project,'version'=>0,'rule'=>null];
        $rule=json_decode((string)$row['rule_json'],true,32,JSON_THROW_ON_ERROR);self::validate($rule);
        return ['project_key'=>$project,'version'=>(int)$row['revision'],'rule'=>$rule];
    }

    private static function validate(array $policy): void
    {
        if(array_diff(array_keys($policy),['next_rule_version','all','conversations'])||!isset($policy['next_rule_version'],$policy['conversations'])
            ||!array_key_exists('all',$policy)||!is_int($policy['next_rule_version'])||$policy['next_rule_version']<1
            ||!is_array($policy['conversations'])||count($policy['conversations'])>self::MAX_CONVERSATIONS)throw new RuntimeException('invalid_preparation_storage');
        $rules=$policy['conversations'];
        foreach($rules as $id=>$rule)if(!preg_match('/^[1-9][0-9]*$/D',(string)$id))throw new RuntimeException('invalid_preparation_storage');
        if($policy['all']!==null){
            if(!is_array($policy['all'])||array_key_exists('inherit',$policy['all']))throw new RuntimeException('invalid_preparation_storage');
            $rules['all']=$policy['all'];
        }
        foreach($rules as $rule){
            if(!is_array($rule)||!is_int($rule['version']??null)||$rule['version']<1||$rule['version']>=$policy['next_rule_version'])throw new RuntimeException('invalid_preparation_storage');
            if(($rule['inherit']??false)===true){if(array_diff(array_keys($rule),['inherit','version']))throw new RuntimeException('invalid_preparation_storage');continue;}
            if(array_diff(array_keys($rule),['enabled','until_utc','version'])||!is_bool($rule['enabled']??null)||!array_key_exists('until_utc',$rule)
                ||($rule['until_utc']!==null&&(!is_string($rule['until_utc'])||self::timestamp($rule['until_utc'])===null))
                ||(!$rule['enabled']&&$rule['until_utc']!==null))throw new RuntimeException('invalid_preparation_storage');
        }
    }

    private static function timestamp(string $value): ?int
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $date&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():null;
    }
}
