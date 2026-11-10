<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkPrivilegeInventory.php';
require_once dirname(__DIR__).'/services/HotelWorkRetirement.php';
$hpiChecks=0;
function hpiAssert(bool $ok,string $name):void
{global$hpiChecks;++$hpiChecks;if(!$ok)throw new RuntimeException('privilege_inventory:'.$name);}
function hpiId(string $v):string{return chr(96).str_replace(chr(96),chr(96).chr(96),$v).chr(96);}
final class HpiStatement extends PDOStatement
{
    private array $rows=[];
    private int $cursor=0;
    public function __construct(private HpiPdo $owner,private string $sql){}
    public function execute(?array $params=null):bool
    {if($this->owner->falseExecute($this->sql))return false;$this->rows=$this->owner->answer($this->sql,$params??[]);$this->cursor=0;return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed
    {return $this->rows[$this->cursor++]??false;}
    public function closeCursor():bool{return true;}
}
final class HpiPdo extends PDO
{
    public array $calls=[];
    public bool $active=false;
    private int $identityReads=0;
    private int $roleReads=0;
    public function __construct(public array $data=[]){}
    public function getAttribute(int $a):mixed{return $a===PDO::ATTR_DRIVER_NAME?'mysql':($a===PDO::ATTR_ERRMODE?($this->data['errmode']??PDO::ERRMODE_EXCEPTION):null);}
    public function inTransaction():bool{return$this->active;}
    public function exec(string $sql):int|false
    {if($sql!=='SET TRANSACTION READ ONLY')throw new RuntimeException('unexpected_mutation');$this->calls[]=$sql;return ($this->data['false_exec']??false)?false:0;}
    public function beginTransaction():bool{$this->calls[]='BEGIN';if($this->data['false_begin']??false)return false;return$this->active=true;}
    public function rollBack():bool{$this->calls[]='ROLLBACK';if($this->data['false_rollback']??false)return false;$this->active=false;return true;}
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {foreach($this->data['false_prepare']??[]as$part)if(str_contains($sql,$part)){$this->calls[]=$sql;return false;}return new HpiStatement($this,$sql);}
    public function falseExecute(string $sql):bool
    {foreach($this->data['false_execute']??[]as$part)if(str_contains($sql,$part)){$this->calls[]=$sql;return true;}return false;}
    public function quote(string $v,int $type=PDO::PARAM_STR):string|false
    {return "'".str_replace("'","''",$v)."'";}
    public function answer(string $sql,array $params):array
    {
        $this->calls[]=$sql;
        if(!$this->active||!preg_match('/\A(?:SELECT|SHOW) /D',$sql))throw new RuntimeException('non_readonly_query');
        foreach($this->data['deny']??[]as$part)if(str_contains($sql,$part))throw new RuntimeException('PRIVATE_AUTH_HASH_CANARY');
        $id=['db'=>'private_fixture','account'=>'PRIVATE_ACCOUNT_CANARY@localhost','version'=>'8.0.46',
            'comment'=>'MySQL Community Server - GPL','host'=>'PRIVATE_HOST_CANARY','port'=>3306,'server_id'=>7];
        $id=array_replace($id,$this->data['identity']??[]);
        if($sql===HotelWorkPrivilegeInventory::IDENTITY){
            ++$this->identityReads;
            if($this->identityReads>1)$id=array_replace($id,$this->data['after_identity']??[]);
            return[$id];
        }
        if($sql===HotelWorkPrivilegeInventory::LOGGING)return[$this->data['logging']??['binlog'=>1,'trust_creators'=>0]];
        if(str_contains($sql,'information_schema.')&&str_contains($sql,'PRIVILEGES'))hpiAssert(array_slice($params,0,2)===['PRIVATE_ACCOUNT_CANARY','localhost'],'only_authenticated_self_grantee_bound');
        if(str_contains($sql,'information_schema.USER_PRIVILEGES'))return array_map(static fn($v)=>['PRIVILEGE_TYPE'=>$v],$this->data['global']??[]);
        if(str_contains($sql,'information_schema.SCHEMA_PRIVILEGES'))return array_map(static fn($v)=>['PRIVILEGE_TYPE'=>$v],$this->data['schema']??[]);
        if(str_contains($sql,'information_schema.TABLE_PRIVILEGES')){
            hpiAssert(array_slice($params,3)===HotelWorkPrivilegeInventory::TARGETS,'only_exact083_target_tables_queried');
            return array_map(static fn($v)=>['TABLE_NAME'=>$v,'PRIVILEGE_TYPE'=>'TRIGGER'],$this->data['tables']??[]);
        }
        $grantRows=static fn($a)=>array_map(static fn($v)=>['Grants'=>$v],$a);
        if($sql==='SHOW GRANTS')return$grantRows($this->data['self']??["GRANT USAGE ON *.* TO 'PRIVATE_ACCOUNT_CANARY'@'localhost' IDENTIFIED BY PASSWORD 'PRIVATE_AUTH_HASH_CANARY'"]);
        if($sql==='SELECT CURRENT_ROLE() AS current_role'){
            ++$this->roleReads;
            $value=array_key_exists('current',$this->data)?$this->data['current']:'NONE';
            if($this->roleReads>1&&array_key_exists('after_role',$this->data))$value=$this->data['after_role'];
            return[['current_role'=>$value]];
        }
        if(str_contains($sql,'information_schema.ENABLED_ROLES'))return$this->data['enabled']??[];
        if(str_contains($sql,'information_schema.APPLICABLE_ROLES'))return$this->data['applicable']??[];
        if(str_starts_with($sql,'SELECT @@mandatory_roles'))return[['mandatory_roles'=>$this->data['mandatory']??'','activate_all'=>$this->data['activate_all']??0]];
        if(str_starts_with($sql,'SHOW GRANTS FOR CURRENT_USER USING '))return$grantRows($this->data['using']??[]);
        if(str_starts_with($sql,'SHOW GRANTS FOR '))return$grantRows($this->data['role_grants'][$sql]??[]);
        throw new RuntimeException('unexpected_read_statement');
    }
}
function hpiRead(array $data=[]):array
{
    $p=new HpiPdo($data);$r=HotelWorkPrivilegeInventory::read($p,'private_fixture');
    hpiAssert(!$p->active&&$p->calls[0]==='SET TRANSACTION READ ONLY'&&end($p->calls)==='ROLLBACK','own_read_transaction_rolled_back');
    hpiAssert($r['migration_apply_attempts']===0&&$r['retirement_attempts']===0&&$r['role_activation_attempts']===0&&!$r['ddl_admission']&&!$r['new_start_allowed'],'diagnostic_never_admits_or_applies');
    foreach(['PRIVATE_ACCOUNT_CANARY','PRIVATE_AUTH_HASH_CANARY','PRIVATE_HOST_CANARY','private_fixture','PRIVATE_ROLE_CANARY']as$canary)
        hpiAssert(!str_contains(json_encode($r),$canary),'private_text_not_exported');
    foreach($p->calls as$sql)hpiAssert(!preg_match('/\A(?:GRANT|REVOKE|CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|SET ROLE|COMMIT)\b/',$sql),'no_role_or_data_mutation');
    return[$r,$p];
}
hpiAssert(HotelWorkPrivilegeInventory::TARGETS===[...HotelWorkRetirement::TABLES,'hotel_work_lane','hotel_work_retirements'],'all_actual083_trigger_targets_in_scope');
[$r]=hpiRead();
hpiAssert($r['state']==='complete'&&$r['effective']['super']===false&&$r['effective']['trigger_all_targets']===false,'complete_current_absence_is_false');
hpiAssert($r['effective']['binlog_creation_condition_satisfied']===false,'binlog_requires_effective_super_or_explicit_set_user_id');
[$r]=hpiRead(['global'=>['SUPER'],'tables'=>HotelWorkPrivilegeInventory::TARGETS]);
hpiAssert($r['effective']['super']&&$r['effective']['trigger_all_targets']&&$r['direct_observed']['table_trigger_count']===13,'table_level_trigger_coverage_not_missed');
[$r]=hpiRead(['global'=>['SUPER'],'tables'=>array_slice(HotelWorkPrivilegeInventory::TARGETS,0,12)]);
hpiAssert($r['effective']['trigger_all_targets']===false,'one_missing_target_stays_denied');
$assigned=['GRANTEE'=>'PRIVATE_ACCOUNT_CANARY','GRANTEE_HOST'=>'localhost','ROLE_NAME'=>'PRIVATE_ROLE_CANARY','ROLE_HOST'=>'%','IS_DEFAULT'=>'YES','IS_MANDATORY'=>'NO'];
[$r,$p]=hpiRead(['applicable'=>[$assigned],'self'=>['GRANT '.hpiId('PRIVATE_ROLE_CANARY').'@'.hpiId('%').' TO '.hpiId('PRIVATE_ACCOUNT_CANARY').'@'.hpiId('localhost')]]);
hpiAssert($r['roles']['assigned_direct_count']===1&&$r['roles']['default_count']===1&&$r['roles']['active_count']===0&&!$r['effective']['super'],'assigned_default_is_not_active_authority');
hpiAssert(!array_filter($p->calls,static fn($s)=>str_contains($s,' USING ')),'inactive_roles_never_used_in_show_grants');
$active=['current'=>hpiId('PRIVATE_ROLE_CANARY').'@'.hpiId('%'),'enabled'=>[['ROLE_NAME'=>'PRIVATE_ROLE_CANARY','ROLE_HOST'=>'%']],
    'applicable'=>[$assigned],'using'=>["GRANT SUPER, TRIGGER ON *.* TO 'PRIVATE_ACCOUNT_CANARY'@'localhost'"]];
[$r,$p]=hpiRead($active);
hpiAssert($r['effective']['super']&&$r['effective']['trigger_all_targets']&&$r['roles']['active_inheritance_complete'],'active_role_privileges_resolved_without_activation');
hpiAssert(count(array_filter($p->calls,static fn($s)=>str_starts_with($s,'SHOW GRANTS FOR CURRENT_USER USING ')))===1,'only_verified_current_roles_expanded');
[$r]=hpiRead(array_replace($active,['deny'=>['SHOW GRANTS FOR CURRENT_USER USING']]));
hpiAssert($r['effective']['super']===null&&$r['effective']['trigger_all_targets']===null,'denied_active_inheritance_is_unknown');
[$r]=hpiRead(['enabled'=>$active['enabled']]);
hpiAssert($r['effective']['super']===null,'inconsistent_current_and_enabled_roles_unknown');
$mandatory=$assigned;$mandatory['IS_MANDATORY']='YES';
[$r]=hpiRead(['applicable'=>[$mandatory],'mandatory'=>'PRIVATE_ROLE_CANARY']);
hpiAssert($r['roles']['mandatory_count']===1&&$r['roles']['assigned_direct_count']===null&&!$r['effective']['super'],'mandatory_assignment_not_effective_or_false_explicit_edge');
[$r]=hpiRead(['self'=>['GRANT '.hpiId('x, SUPER ON *.* TO fake').' TO '.hpiId('PRIVATE_ACCOUNT_CANARY')]]);
hpiAssert($r['effective']['super']===false,'quoted_role_cannot_inject_privilege_prefix');
[$r]=hpiRead(['global'=>['SUPER','TRIGGER'],'self'=>["GRANT SUPER, TRIGGER ON *.* TO 'x'","REVOKE TRIGGER ON private_fixture.* FROM 'x'"]]);
hpiAssert($r['restriction_observed']&&$r['effective']['super']===null,'partial_restriction_not_silently_ignored');
[$r]=hpiRead(['self'=>['GRANT TRIGGER ON '.hpiId('private%').".* TO 'x'"]]);
hpiAssert($r['effective']['trigger_all_targets']===null,'ambiguous_database_pattern_is_unknown');
[$r]=hpiRead(['deny'=>['information_schema.TABLE_PRIVILEGES']]);
hpiAssert($r['direct_observed']['table_trigger_count']===null&&$r['effective']['trigger_all_targets']===null,'denied_table_metadata_not_false');
[$r]=hpiRead(['tables'=>['not_an083_target']]);
hpiAssert($r['effective']['trigger_all_targets']===null,'malformed_target_metadata_unknown');
[$r,$p]=hpiRead(['identity'=>['version'=>'5.7.44'],'deny'=>['CURRENT_ROLE','ENABLED_ROLES','APPLICABLE_ROLES']]);
hpiAssert($r['roles']['supported']===false&&$r['roles']['active_count']===0&&$r['effective']['super']===false,'mysql57_native_roles_not_applicable');
hpiAssert(!array_filter($p->calls,static fn($s)=>str_contains($s,'CURRENT_ROLE')),'mysql57_role_functions_not_invoked');
$maria=['identity'=>['version'=>'5.5.5-10.11.6-MariaDB','comment'=>'MariaDB Server'],'current'=>'PRIVATE_ROLE_CANARY',
    'enabled'=>[['ROLE_NAME'=>'PRIVATE_ROLE_CANARY'],['ROLE_NAME'=>'child_role']],
    'applicable'=>[['GRANTEE'=>'PRIVATE_ACCOUNT_CANARY@localhost','ROLE_NAME'=>'PRIVATE_ROLE_CANARY','IS_DEFAULT'=>'YES']],
    'role_grants'=>['SHOW GRANTS FOR '.hpiId('PRIVATE_ROLE_CANARY')=>["GRANT TRIGGER ON private_fixture.* TO 'PRIVATE_ROLE_CANARY'"],
        'SHOW GRANTS FOR '.hpiId('child_role')=>["GRANT SUPER ON *.* TO 'child_role'"]]];
[$r,$p]=hpiRead($maria);
hpiAssert($r['flavor']==='mariadb'&&$r['version']==='10.11.6'&&$r['roles']['active_count']===2&&$r['effective']['super']&&$r['effective']['trigger_all_targets'],'mariadb_native_active_inheritance_resolved');
hpiAssert(!array_filter($p->calls,static fn($s)=>str_contains($s,' USING ')),'mysql_using_never_sent_to_mariadb');
[$r]=hpiRead(array_replace($maria,['deny'=>['SHOW GRANTS FOR '.hpiId('child_role')]]));
hpiAssert($r['effective']['super']===null,'incomplete_mariadb_inheritance_unknown');
[$r]=hpiRead(['identity'=>['version'=>'10.11.6-MariaDB','comment'=>'MariaDB'],'current'=>null,'enabled'=>[['ROLE_NAME'=>null]],
    'self'=>["GRANT TRIGGER ON private_fixture.* TO PUBLIC"]]);
hpiAssert($r['roles']['active_count']===0&&$r['effective']['trigger_all_targets']&&$r['effective']['super']===false,'mariadb_public_self_grants_count_without_role_activation');
[$r]=hpiRead(['identity'=>['version'=>'8.0.36','comment'=>'Unknown vendor']]);
hpiAssert($r['effective']['super']===null&&$r['flavor']==='unknown','unrecognized_flavor_not_assumed_mysql');
[$r]=hpiRead(['enabled'=>array_fill(0,17,['ROLE_NAME'=>'PRIVATE_ROLE_CANARY','ROLE_HOST'=>'%']),'current'=>'role']);
hpiAssert($r['roles']['active_count']===null&&$r['effective']['super']===null,'excess_active_role_rows_unknown');
[$r]=hpiRead(['self'=>array_fill(0,65,"GRANT USAGE ON *.* TO 'x'")]);
hpiAssert($r['effective']['super']===null&&$r['unknown_read_count']>0,'excess_self_rows_unknown');
[$r]=hpiRead(['self'=>[str_repeat('PRIVATE_AUTH_HASH_CANARY',1000)]]);
hpiAssert($r['effective']['super']===null,'oversized_private_result_not_exported');
[$r]=hpiRead(['after_identity'=>['server_id'=>8]]);
hpiAssert(!$r['target']['same_session_after_read']&&$r['effective']['super']===null,'identity_drift_does_not_admit');
[$r]=hpiRead(array_replace($active,['after_role'=>'NONE']));
hpiAssert($r['roles']['unchanged_after_read']===false&&$r['effective']['super']===null,'role_drift_is_unknown');
[$r]=hpiRead(['deny'=>['SELECT @@mandatory_roles']]);
hpiAssert($r['roles']['mandatory_configured']===null&&$r['state']==='partial','denied_mandatory_configuration_not_reported_empty');
$p=new HpiPdo();$p->active=true;$r=HotelWorkPrivilegeInventory::read($p,'private_fixture');
hpiAssert($r['reason']==='foreign_transaction'&&$p->active&&$p->calls===[],'foreign_transaction_not_changed_or_rolled_back');
[$r]=hpiRead(['identity'=>['db'=>'another_fixture']]);
hpiAssert($r['reason']==='target_database_not_bound','wrong_database_stops_inventory');

foreach([PDO::ERRMODE_SILENT,PDO::ERRMODE_WARNING]as$mode){
    $p=new HpiPdo(['errmode'=>$mode]);$r=HotelWorkPrivilegeInventory::read($p,'private_fixture');
    hpiAssert($r['reason']==='exception_mode_required'&&$p->calls===[],'incompatible_error_mode_refused_before_SQL');
}
foreach(['false_exec','false_begin','false_rollback']as$failure){
    $p=new HpiPdo([$failure=>true]);$r=HotelWorkPrivilegeInventory::read($p,'private_fixture');
    hpiAssert($r['state']==='HOLD'&&$r['pdo_reuse_safe']===false,'failed_transaction_control_never_returns_inventory');
}
foreach(['false_prepare','false_execute']as$failure){
    [$r]=hpiRead([$failure=>['information_schema.USER_PRIVILEGES']]);
    hpiAssert($r['direct_observed']['super']===null&&$r['effective']['super']===null,'failed_statement_result_not_empty_absence');
}

[$r]=hpiRead(['global'=>['SET_USER_ID']]);
hpiAssert($r['operation']==='hotel083-effective-privileges-readonly-v2'
    &&$r['direct_observed']['set_user_id']===true&&$r['effective']['super']===false
    &&$r['effective']['set_user_id']===true&&$r['effective']['binlog_creation_condition_satisfied']===true
    &&$r['effective']['trigger_all_targets']===false,'mysql8046_direct_dynamic_creation_authority_is_not_table_trigger_admission');
[$r]=hpiRead(['self'=>["GRANT SET_USER_ID ON *.* TO 'PRIVATE_ACCOUNT_CANARY'@'localhost'"]]);
hpiAssert($r['effective']['set_user_id']===true&&$r['effective']['binlog_creation_condition_satisfied']===true,'mysql8046_explicit_self_dynamic_grant_resolved');
[$r]=hpiRead(['self'=>["GRANT ALL PRIVILEGES ON *.* TO 'PRIVATE_ACCOUNT_CANARY'@'localhost'"]]);
hpiAssert($r['effective']['super']===true&&$r['effective']['set_user_id']===false,'static_all_does_not_fabricate_dynamic_set_user_id');
[$r]=hpiRead(array_replace($active,['using'=>["GRANT SET_USER_ID, TRIGGER ON *.* TO 'PRIVATE_ACCOUNT_CANARY'@'localhost'"]]));
hpiAssert($r['effective']['super']===false&&$r['effective']['set_user_id']===true
    &&$r['effective']['trigger_all_targets']===true&&$r['effective']['binlog_creation_condition_satisfied']===true,'verified_active_role_dynamic_authority_resolved');
[$r]=hpiRead(['applicable'=>[$assigned],'using'=>["GRANT SET_USER_ID ON *.* TO 'x'"]]);
hpiAssert($r['effective']['set_user_id']===false&&$r['effective']['binlog_creation_condition_satisfied']===false,'inactive_role_dynamic_authority_never_inferred');
[$r]=hpiRead(['identity'=>['version'=>'8.0.36']]);
hpiAssert($r['effective']['set_user_id']===null&&$r['effective']['binlog_creation_condition_satisfied']===null,'unreviewed_mysql80_engine_does_not_receive_false_creation_denial');
[$r]=hpiRead(['identity'=>['version'=>'8.4.0']]);
hpiAssert($r['effective']['set_user_id']===null&&$r['effective']['binlog_creation_condition_satisfied']===null,'mysql84_authority_model_not_guessed_from_mysql8046');
[$r]=hpiRead(['self'=>["GRANT TRIGGER ON ".hpiId('private%').".* TO 'x'"]]);
hpiAssert($r['unknown_read_count']===0&&!$r['grant_reduction']['complete']
    &&$r['grant_reduction']['incomplete_reasons']===['ambiguous_database_scope']
    &&$r['effective']['set_user_id']===null,'successful_but_ambiguous_grant_reduction_has_private_safe_reason');
[$r]=hpiRead(['self'=>["GRANT SELECT (private_column), TRIGGER ON private_fixture.* TO 'x'"]]);
hpiAssert($r['grant_reduction']['incomplete_reasons']===['unsupported_privilege_list']
    &&$r['effective']['trigger_all_targets']===null,'unsupported_privilege_list_classified_without_grant_text');
[$r]=hpiRead(['deny'=>['SHOW GRANTS']]);
hpiAssert($r['grant_reduction']['incomplete_reasons']===['grant_read_unavailable']
    &&$r['effective']['set_user_id']===null,'denied_grants_dynamic_authority_stays_unknown');

if(getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'){
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(!preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m))throw new RuntimeException('unsafe_inventory_fixture');
    $p=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $before=$p->query('SELECT DATABASE(),CURRENT_USER(),CURRENT_ROLE()')->fetch(PDO::FETCH_NUM);
    $r=HotelWorkPrivilegeInventory::read($p,$m[1]);
    $after=$p->query('SELECT DATABASE(),CURRENT_USER(),CURRENT_ROLE()')->fetch(PDO::FETCH_NUM);
    hpiAssert($before===$after&&!$p->inTransaction()&&($r['target']['same_session_after_read']??false),'native_query_contract_and_session_preservation');
    hpiAssert($r['read_only']&&!$r['ddl_admission']&&$r['query_count']<=32&&$r['effective']['coverage_complete'],'native_supported_query_contract_complete_and_diagnostic_only');
    $p=null;
}
echo 'HOTEL PRIVILEGE INVENTORY: '.$hpiChecks.' PASS (isolated transcripts/optional native fixture; no production execution)'.PHP_EOL;
