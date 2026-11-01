<?php
declare(strict_types=1);

/** One existing application PDO; no auth factory, role activation or admission. */
final class HotelWorkPrivilegeInventory
{
    public const TARGETS=[
        'hotel_work_queues','hotel_work_receipts','hotel_work_events',
        'hotel_work_connection_handoffs','hotel_work_resolutions',
        'hotel_work_delivery_recoveries','hotel_work_research_skips',
        'hotel_work_consumer_delivery_repairs','hotel_work_execution_attempts',
        'hotel_work_execution_attempt_entries','hotel_work_execution_attempt_associations',
        'hotel_work_lane','hotel_work_retirements',
    ];
    public const IDENTITY='SELECT DATABASE() AS db,CURRENT_USER() AS account,VERSION() AS version,@@version_comment AS comment,@@hostname AS host,@@port AS port,@@server_id AS server_id';
    public const LOGGING='SELECT @@log_bin AS binlog,@@log_bin_trust_function_creators AS trust_creators';
    private int $queries=0;
    private int $bytes=0;
    private int $unknown=0;
    private function __construct(private PDO $pdo,private string $database){}

    /** null means UNKNOWN; all account/role/grant text remains private to this call. */
    public static function read(PDO $applicationPdo,string $expectedDatabase):array
    {
        $base=['operation'=>'hotel083-effective-privileges-readonly-v2','read_only'=>true,
            'provided_pdo_only'=>true,'role_activation_attempts'=>0,'migration_apply_attempts'=>0,
            'retirement_attempts'=>0,'new_start_allowed'=>false,'ddl_admission'=>false];
        try {
            if($applicationPdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
                ||$expectedDatabase===''||strlen($expectedDatabase)>64||str_contains($expectedDatabase,"\0"))
                return $base+['state'=>'HOLD','reason'=>'unsupported_inventory_context'];
            if($applicationPdo->inTransaction())
                return $base+['state'=>'HOLD','reason'=>'foreign_transaction'];
            if($applicationPdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)
                return $base+['state'=>'HOLD','reason'=>'exception_mode_required'];
            $reader=new self($applicationPdo,$expectedDatabase);
            if($applicationPdo->exec('SET TRANSACTION READ ONLY')===false)throw new RuntimeException('read_transaction_unavailable');
            try{
                if($applicationPdo->beginTransaction()!==true)throw new RuntimeException('read_transaction_unavailable');
                $result=$reader->collect();
            }finally{
                if($applicationPdo->inTransaction()&&$applicationPdo->rollBack()!==true)throw new RuntimeException('read_rollback_unconfirmed');
            }
            return $base+$result;
        }catch(Throwable $ignored){
            return $base+['state'=>'HOLD','reason'=>'inventory_read_unavailable','pdo_reuse_safe'=>false];
        }
    }

    /** Constant reads; the only identifier-based SHOW uses verified enabled roles. */
    private function rows(string $sql,array $params=[],int $limit=64):?array
    {
        if($this->queries>=32){++$this->unknown;return null;}
        ++$this->queries;
        try {
            $s=$this->pdo->prepare($sql);
            if(!($s instanceof PDOStatement))throw new RuntimeException('read_prepare_unconfirmed');
            $rows=[];
            try{
                if($s->execute($params)!==true)throw new RuntimeException('read_execute_unconfirmed');
                while(($r=$s->fetch(PDO::FETCH_ASSOC))!==false){
                    if(count($rows)>=$limit){++$this->unknown;return null;}
                    foreach($r as$v){
                        if(!is_scalar($v)&&$v!==null){++$this->unknown;return null;}
                        $n=strlen((string)$v);
                        if($n>16384||($this->bytes+=$n)>131072){++$this->unknown;return null;}
                    }
                    $rows[]=$r;
                }
            }finally{if($s->closeCursor()!==true)throw new RuntimeException('read_cursor_unconfirmed');}
            return $rows;
        }catch(Throwable $ignored){++$this->unknown;return null;}
    }
    private static function one(?array $rows):?array
    {return is_array($rows)&&count($rows)===1?$rows[0]:null;}
    private static function flag(mixed $v):?bool
    {return in_array((string)$v,['1','ON','YES'],true)?true:(in_array((string)$v,['0','OFF','NO'],true)?false:null);}
    private static function account(string $v):?array
    {
        $at=strrpos($v,'@');
        if($at===false||$at===0||strlen($v)>512||str_contains($v,"\0"))return null;
        return[substr($v,0,$at),substr($v,$at+1)];
    }
    private static function product(array $id):array
    {
        $v=(string)$id['version'];$comment=(string)$id['comment'];$flavor='unknown';
        if(stripos($v.' '.$comment,'MariaDB')!==false)$flavor='mariadb';
        elseif(stripos($v.' '.$comment,'Percona')!==false)$flavor='percona';
        elseif(stripos($comment,'MySQL')!==false
            ||(stripos($v,'ubuntu')!==false&&stripos($comment,'Ubuntu')!==false)
            ||(stripos($v,'deb')!==false&&stripos($comment,'Debian')!==false))$flavor='mysql';
        $version=$flavor==='mariadb'&&preg_match('/(\d+\.\d+\.\d+)-MariaDB/i',$v,$m)?$m[1]:null;
        if($version===null&&preg_match('/\A(\d+\.\d+\.\d+)/',$v,$m))$version=$m[1];
        $supported=$version!==null&&($flavor==='mysql'
            ?(str_starts_with($version,'5.7.')||str_starts_with($version,'8.0.')||str_starts_with($version,'8.4.'))
            :($flavor==='mariadb'&&version_compare($version,'10.0.5','>=')&&version_compare($version,'13.0.0','<')));
        return[$flavor,$version,$supported];
    }
    private static function emptyGrants():array
    {return['super'=>false,'set_user_id'=>false,'trigger'=>array_fill_keys(self::TARGETS,false),
        'complete'=>true,'restriction'=>false,'incomplete_reasons'=>[]];}
    private static function combine(array $a,array $b):array
    {
        $a['super']=$a['super']||$b['super'];$a['set_user_id']=$a['set_user_id']||$b['set_user_id'];
        $a['complete']=$a['complete']&&$b['complete'];
        $a['incomplete_reasons']=array_values(array_unique([...$a['incomplete_reasons'],...$b['incomplete_reasons']]));
        $a['restriction']=$a['restriction']||$b['restriction'];
        foreach(self::TARGETS as$t)$a['trigger'][$t]=$a['trigger'][$t]||$b['trigger'][$t];
        return$a;
    }
    /**
     * Only SUPER/TRIGGER/SET_USER_ID and exact global/schema/083-table scopes are reduced.
     * Restrictions or ambiguous relevant scope => UNKNOWN, never an allow.
     */
    private function grants(?array $rows):array
    {
        $out=self::emptyGrants();if($rows===null){$out['complete']=false;$out['incomplete_reasons'][]='grant_read_unavailable';return$out;}
        foreach($rows as$r){
            if(count($r)!==1||!is_string($line=array_values($r)[0]??null)){$out['complete']=false;$out['incomplete_reasons'][]='malformed_grant_row';continue;}
            if(preg_match('/\AREVOKE\b/i',$line)){$out['restriction']=true;$out['complete']=false;$out['incomplete_reasons'][]='restriction_statement';continue;}
            if(preg_match('/\AGRANT\s+[\x27\x22\x60]/i',$line))continue;
            if(!preg_match('/\AGRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is',$line,$m)){
                // Assignments are not privileges. Active inheritance is resolved
                // by the engine USING or the complete MariaDB ENABLED_ROLES list.
                if(!preg_match('/\AGRANT\s+.+\s+TO\s/is',$line)){$out['complete']=false;$out['incomplete_reasons'][]='unsupported_grant_statement';}
                continue;
            }
            if(!preg_match('/\A[A-Za-z_]+(?:\s+[A-Za-z_]+)*(?:\s*,\s*[A-Za-z_]+(?:\s+[A-Za-z_]+)*)*\z/D',trim($m[1]))){$out['complete']=false;$out['incomplete_reasons'][]='unsupported_privilege_list';continue;}
            $priv=array_map(static fn($v)=>strtoupper(trim($v)),explode(',',$m[1]));
            $all=in_array('ALL PRIVILEGES',$priv,true)||in_array('ALL',$priv,true);
            $super=$all||in_array('SUPER',$priv,true);$trigger=$all||in_array('TRIGGER',$priv,true);
            // ALL does not imply dynamic SET_USER_ID; require its explicit grant.
            $setUserId=in_array('SET_USER_ID',$priv,true);
            if(!$super&&!$trigger&&!$setUserId)continue;
            $scope=trim($m[2]);
            if($scope==='*.*'){
                $out['super']=$out['super']||$super;$out['set_user_id']=$out['set_user_id']||$setUserId;
                if($trigger)$out['trigger']=array_fill_keys(self::TARGETS,true);
                continue;
            }
            if(!preg_match('/\A(\*|[A-Za-z0-9_$]+|\x60(?:[^\x60]|\x60\x60)+\x60)\.(\*|[A-Za-z0-9_$]+|\x60(?:[^\x60]|\x60\x60)+\x60)\z/D',$scope,$s)){$out['complete']=false;$out['incomplete_reasons'][]='unsupported_relevant_scope';continue;}
            $decode=static fn($v)=>str_starts_with($v,chr(96))?str_replace(chr(96).chr(96),chr(96),substr($v,1,-1)):$v;
            $db=$decode($s[1]);$table=$decode($s[2]);
            if($db!==$this->database){
                if(strpbrk($db,"%_\\")!==false){$out['complete']=false;$out['incomplete_reasons'][]='ambiguous_database_scope';}
                continue;
            }
            if($trigger){
                if($table==='*')$out['trigger']=array_fill_keys(self::TARGETS,true);
                elseif(array_key_exists($table,$out['trigger']))$out['trigger'][$table]=true;
            }
        }
        return$out;
    }
    private function privilegeRows(?array $rows,array $types,bool $table=false):?array
    {
        if($rows===null)return null;
        foreach($rows as$r)if(!in_array($r['PRIVILEGE_TYPE']??null,$types,true)
            ||($table&&!in_array($r['TABLE_NAME']??null,self::TARGETS,true))){++$this->unknown;return null;}
        return$rows;
    }

    private function collect():array
    {
        $id=self::one($this->rows(self::IDENTITY,[],1));
        foreach(['db','account','version','comment','host','port','server_id']as$k)
            if(!$id||!array_key_exists($k,$id)||!is_scalar($id[$k]))return['state'=>'HOLD','reason'=>'target_database_not_bound'];
        if($id['db']!==$this->database||!is_string($id['account'])||($account=self::account($id['account']))===null)
            return['state'=>'HOLD','reason'=>'target_database_not_bound'];
        [$flavor,$version,$supported]=self::product($id);
        $fingerprint=hash('sha256',json_encode($id,JSON_THROW_ON_ERROR));
        $logging=self::one($this->rows(self::LOGGING,[],1));
        $binlog=$logging?self::flag($logging['binlog']??null):null;
        $trust=$logging?self::flag($logging['trust_creators']??null):null;
        $grantee="CONCAT(QUOTE(?),'@',QUOTE(?))";
        $global=$this->privilegeRows($this->rows("SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=$grantee AND PRIVILEGE_TYPE IN ('SUPER','TRIGGER','SET_USER_ID') LIMIT 4",$account,3),['SUPER','TRIGGER','SET_USER_ID']);
        $schema=$this->privilegeRows($this->rows("SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_SCHEMA=? AND PRIVILEGE_TYPE='TRIGGER' LIMIT 2",[...$account,$this->database],1),['TRIGGER']);
        $placeholders=implode(',',array_fill(0,count(self::TARGETS),'?'));
        $tables=$this->privilegeRows($this->rows("SELECT TABLE_NAME,PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_SCHEMA=? AND TABLE_NAME IN ($placeholders) AND PRIVILEGE_TYPE='TRIGGER' LIMIT 14",[...$account,$this->database,...self::TARGETS],13),['TRIGGER'],true);
        $contains=static function(?array$rows,string$p):?bool{
            if($rows===null)return null;foreach($rows as$r)if($r['PRIVILEGE_TYPE']===$p)return true;return false;
        };
        $direct=['super'=>$contains($global,'SUPER'),'set_user_id'=>$contains($global,'SET_USER_ID'),'global_trigger'=>$contains($global,'TRIGGER'),
            'schema_trigger'=>$contains($schema,'TRIGGER'),'table_trigger_count'=>$tables===null?null:count($tables)];
        $effective=$this->grants($this->rows('SHOW GRANTS'));
        if($direct['super']===true)$effective['super']=true;
        if($direct['set_user_id']===true)$effective['set_user_id']=true;
        if($direct['global_trigger']===true||$direct['schema_trigger']===true)$effective['trigger']=array_fill_keys(self::TARGETS,true);
        foreach($tables??[]as$r)$effective['trigger'][$r['TABLE_NAME']]=true;
        $roleSupported=$supported&&($flavor==='mariadb'||version_compare($version,'8.0.0','>='));
        $roles=['supported'=>$supported?$roleSupported:null,'current_present'=>$supported&&!$roleSupported?false:null,
            'active_count'=>$supported&&!$roleSupported?0:null,'applicable_count'=>$supported&&!$roleSupported?0:null,
            'assigned_direct_count'=>$supported&&!$roleSupported?0:null,'default_count'=>$supported&&!$roleSupported?0:null,
            'mandatory_count'=>$supported&&!$roleSupported?0:null,'mandatory_roles_applicable'=>$supported?($flavor==='mysql'&&$roleSupported):null,
            'mandatory_configured'=>null,'activate_all_on_login'=>null,'active_inheritance_complete'=>$supported&&!$roleSupported,
            'unchanged_after_read'=>null];
        $beforeRole=null;
        if($roleSupported){
            $cr=self::one($this->rows('SELECT CURRENT_ROLE() AS current_role',[],1));$beforeRole=$cr['current_role']??null;
            $currentKnown=$cr!==null&&array_key_exists('current_role',$cr)&&($flavor==='mysql'
                ?is_string($beforeRole)&&$beforeRole!==''
                :($beforeRole===null||(is_string($beforeRole)&&$beforeRole!=='')));
            $currentPresent=$currentKnown?($beforeRole!==null&&$beforeRole!==''&&($flavor!=='mysql'||$beforeRole!=='NONE')):null;
            $roles['current_present']=$currentPresent;$mysql=$flavor==='mysql';
            $enabled=$this->rows($mysql
                ?'SELECT ROLE_NAME,ROLE_HOST FROM information_schema.ENABLED_ROLES LIMIT 17'
                :'SELECT ROLE_NAME FROM information_schema.ENABLED_ROLES LIMIT 17',[],16);
            $applicable=$this->rows($mysql
                ?'SELECT GRANTEE,GRANTEE_HOST,ROLE_NAME,ROLE_HOST,IS_DEFAULT,IS_MANDATORY FROM information_schema.APPLICABLE_ROLES LIMIT 17'
                :'SELECT GRANTEE,ROLE_NAME,IS_DEFAULT FROM information_schema.APPLICABLE_ROLES LIMIT 17',[],16);
            if($applicable!==null){
                $valid=true;$defaults=0;$mandatory=0;$assigned=0;
                foreach($applicable as$r){
                    if(!is_string($r['ROLE_NAME']??null)||!is_string($r['GRANTEE']??null)||self::flag($r['IS_DEFAULT']??null)===null
                        ||($mysql&&(!is_string($r['ROLE_HOST']??null)||!is_string($r['GRANTEE_HOST']??null)||self::flag($r['IS_MANDATORY']??null)===null))){$valid=false;break;}
                    $defaults+=self::flag($r['IS_DEFAULT'])?1:0;
                    $mandatory+=$mysql&&self::flag($r['IS_MANDATORY'])?1:0;
                    if($mysql?($r['GRANTEE']??null)===$account[0]&&($r['GRANTEE_HOST']??null)===$account[1]:($r['GRANTEE']??null)===$id['account'])++$assigned;
                }
                if($valid){$roles['applicable_count']=count($applicable);$roles['assigned_direct_count']=$mysql&&$mandatory>0?null:$assigned;$roles['default_count']=$defaults;$roles['mandatory_count']=$mysql?$mandatory:null;}
                else ++$this->unknown;
            }
            if($mysql){
                $mr=self::one($this->rows('SELECT @@mandatory_roles AS mandatory_roles,@@activate_all_roles_on_login AS activate_all',[],1));
                if($mr&&is_string($mr['mandatory_roles']??null))$roles['mandatory_configured']=$mr['mandatory_roles']!=='';
                if($mr)$roles['activate_all_on_login']=self::flag($mr['activate_all']??null);
            }
            $active=[];$valid=$enabled!==null&&$currentKnown;
            foreach($enabled??[]as$r){
                if(!$mysql&&array_key_exists('ROLE_NAME',$r)&&$r['ROLE_NAME']===null)continue;
                if(!is_string($r['ROLE_NAME']??null)||$r['ROLE_NAME']===''||strlen($r['ROLE_NAME'])>128||str_contains($r['ROLE_NAME'],"\0")
                    ||($mysql&&(!is_string($r['ROLE_HOST']??null)||strlen($r['ROLE_HOST'])>255||str_contains($r['ROLE_HOST'],"\0")))){$valid=false;break;}
                $active[]=$r;
            }
            if($valid&&(($currentPresent===false&&count($active)!==0)||($currentPresent===true&&count($active)===0)))$valid=false;
            if($valid){
                $roles['active_count']=count($active);$roleGrants=self::emptyGrants();
                if($mysql&&$active!==[]){
                    $names=[];foreach($active as$r)$names[]=$this->pdo->quote($r['ROLE_NAME']).'@'.$this->pdo->quote($r['ROLE_HOST']);
                    $roleGrants=$this->grants($this->rows('SHOW GRANTS FOR CURRENT_USER USING '.implode(',',$names)));
                }elseif(!$mysql){
                    // This native MariaDB table includes all inherited active roles.
                    // Plain SHOW GRANTS also includes PUBLIC grants on >=10.11.
                    foreach($active as$r){
                        $role=chr(96).str_replace(chr(96),chr(96).chr(96),$r['ROLE_NAME']).chr(96);
                        $roleGrants=self::combine($roleGrants,$this->grants($this->rows('SHOW GRANTS FOR '.$role)));
                    }
                }
                $effective=self::combine($effective,$roleGrants);
                $roles['active_inheritance_complete']=$roleGrants['complete'];
            }
        }
        $after=self::one($this->rows(self::IDENTITY,[],1));
        $same=$after!==null&&hash_equals($fingerprint,hash('sha256',json_encode($after,JSON_THROW_ON_ERROR)));
        if($roleSupported){
            $ar=self::one($this->rows('SELECT CURRENT_ROLE() AS current_role',[],1));
            $roles['unchanged_after_read']=$ar!==null&&array_key_exists('current_role',$ar)&&$ar['current_role']===$beforeRole;
        }elseif($supported)$roles['unchanged_after_read']=true;
        $coverage=$supported&&$same&&$global!==null&&$schema!==null&&$tables!==null
            &&$effective['complete']&&!$effective['restriction']&&$roles['active_inheritance_complete']
            &&$roles['unchanged_after_read']===true;
        $perTarget=[];foreach(self::TARGETS as$t)$perTarget[$t]=$coverage?$effective['trigger'][$t]:null;
        $all=$coverage?!in_array(false,$perTarget,true):null;$super=$coverage?$effective['super']:null;
        $setUserId=$coverage?$effective['set_user_id']:null;
        // Exact observed engine source was verified; other MySQL8 versions stay
        // UNKNOWN for a negative SUPER-only binlog-authority decision.
        $mysql80=$flavor==='mysql'&&$version==='8.0.46';
        $mysqlUnmodelled=$flavor==='mysql'&&version_compare($version??'0','8.0.0','>=')&&!$mysql80;
        $binlogCondition=null;
        if($supported&&$binlog!==null&&$trust!==null){
            if(!$binlog||$trust||$super===true)$binlogCondition=true;
            elseif($mysql80&&$super===false&&$setUserId!==null)$binlogCondition=$setUserId;
            elseif(!$mysql80&&!$mysqlUnmodelled)$binlogCondition=$super;
        }
        return['state'=>$coverage&&$this->unknown===0?'complete':'partial','flavor'=>$flavor,'version'=>$version,
            'target'=>['database_matches_expected'=>true,'same_session_after_read'=>$same,'private_binding_sha256'=>$fingerprint],
            'target_table_count'=>count(self::TARGETS),'direct_observed'=>$direct,'roles'=>$roles,
            'grant_reduction'=>['complete'=>$effective['complete'],
                'incomplete_reasons'=>array_values(array_unique($effective['incomplete_reasons']))],
            'effective'=>['coverage_complete'=>$coverage,'super'=>$super,
                'set_user_id'=>$mysql80?$setUserId:null,'trigger_by_target'=>$perTarget,'trigger_all_targets'=>$all,
                'binary_logging_enabled'=>$binlog,'trust_function_creators_enabled'=>$trust,'binlog_creation_condition_satisfied'=>$binlogCondition],
            'restriction_observed'=>$effective['restriction'],'query_count'=>$this->queries,'unknown_read_count'=>$this->unknown];
    }
}
