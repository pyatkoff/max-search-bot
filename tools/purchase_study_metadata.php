<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

/** One bounded aggregate-only audit. Individual metadata never leaves this process. */
final class PurchaseStudyMetadataAudit
{
    public const VERSION = 'purchase_study_metadata_v1';
    public const AFTER = 1793165246; // 2026-10-28T05:27:26Z, exclusive.
    private PDO $pdo;
    private bool $mysql;
    private int $deadline;
    private int $rows = 0;
    public function __construct(PDO $pdo, ?int $began = null)
    {
        $this->pdo=$pdo;
        $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->deadline=($began??hrtime(true))+5000000000;
    }
    private function remaining(): int
    {
        $ms=(int)floor(($this->deadline-hrtime(true))/1000000);
        if($ms<50)throw new RuntimeException('audit_deadline');
        return min(4000,$ms-25);
    }
    private function rows(string $sql,array $args=[]): array
    {
        $ms=$this->remaining();
        if($this->mysql)$this->pdo->exec('SET SESSION MAX_EXECUTION_TIME='.$ms);
        $this->remaining();$q=$this->pdo->prepare($sql);$q->execute($args);
        $r=$q->fetchAll(PDO::FETCH_ASSOC);$this->remaining();
        $this->rows+=count($r);if($this->rows>500)throw new RuntimeException('audit_rows');
        return $r;
    }
    private function safe(string $column): string{return "CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END";}
    private function get(string $safe,string $path): string
    {
        $type=$this->mysql?"JSON_TYPE(JSON_EXTRACT($safe,'$path'))='STRING'":"json_type($safe,'$path')='text'";
        $value=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT($safe,'$path'))":"json_extract($safe,'$path')";
        return "CASE WHEN $type THEN $value ELSE NULL END";
    }
    private function integer(string $safe,string $path): string
    {
        $t=$this->mysql?"JSON_TYPE(JSON_EXTRACT($safe,'$path'))='INTEGER'":"json_type($safe,'$path')='integer'";
        $v=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT($safe,'$path'))":"json_extract($safe,'$path')";
        return "CASE WHEN $t THEN $v ELSE NULL END";
    }
    public function collect(int $after,int $through): array
    {
        if($after<946684800||$through<$after||$through>time()||$this->pdo->inTransaction())throw new RuntimeException('audit_window');
        $this->remaining();
        if($this->mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('SET TRANSACTION READ ONLY');}
        $this->pdo->beginTransaction();
        try{
            $f=$this->rows("SELECT COALESCE(MAX(id),0) AS fence FROM modeled_conversion_profile_versions WHERE project_key='anytour'")[0]['fence'];
            $j=$this->rows("SELECT COALESCE(MAX(id),0) AS fence FROM modeled_conversion_stream_versions WHERE project_key='anytour'")[0]['fence'];
            $state=$this->rows("SELECT enabled FROM modeled_conversion_profiles_state WHERE project_key='anytour'");
            $stream=$this->rows("SELECT enabled FROM modeled_conversion_stream_state WHERE project_key='anytour'");
            $pj=$this->safe('p.profile_json');
            $owner=InternalOwnerDialogueScope::conversationSql('c');
            $cast=$this->mysql?'CHAR':'TEXT';
            $current="c.id IS NOT NULL AND cc.id IS NOT NULL AND c.is_test=0 AND NOT $owner";
            $select="SELECT p.id,p.platform,p.customer_key,p.conversation_id,p.observed_at_unix,CASE WHEN $current THEN 1 ELSE 0 END AS admitted,CASE WHEN c.lead_outcome IN ('won','lost') THEN 1 ELSE 0 END AS current_terminal,";
            $select.="CASE WHEN JSON_VALID(p.profile_json) THEN 1 ELSE 0 END AS valid_json,".$this->get($pj,'$.access_status')." AS access_status,";
            $boolType=$this->mysql?"JSON_TYPE(JSON_EXTRACT($pj,'$.profile.prefix_complete'))='BOOLEAN'":"json_type($pj,'$.profile.prefix_complete') IN ('true','false')";
            $boolValue=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT($pj,'$.profile.prefix_complete'))":"json_extract($pj,'$.profile.prefix_complete')";
            $select.=$this->get($pj,'$.profile.extraction_version')." AS extractor,".$this->get($pj,'$.profile.trip_status')." AS trip_status,";
            $select.="CASE WHEN $boolType THEN $boolValue ELSE NULL END AS prefix_complete ";
            $from="FROM modeled_conversion_profile_versions p LEFT JOIN conversations c ON CAST(c.id AS $cast)=p.conversation_id AND c.project_key=p.project_key AND c.channel=p.platform AND CAST(c.customer_id AS $cast)=p.customer_key LEFT JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel ";
            $profiles=$this->rows($select.$from."WHERE p.project_key='anytour' AND p.platform IN ('max','telegram') AND p.id<=? AND p.observed_at_unix>? AND p.observed_at_unix<=? ORDER BY p.id DESC LIMIT 64",[$f,$after,$through]);
            $out=['schema_version'=>1,'version'=>self::VERSION,'purpose'=>'bounded_new_profile_operational_metadata_not_labels',
                'window_after_exclusive_utc'=>gmdate('Y-m-d\TH:i:s\Z',$after),'window_through_inclusive_utc'=>gmdate('Y-m-d\TH:i:s\Z',$through),
                'profile_page_limit'=>64,'profile_versions_examined'=>count($profiles),'profile_page_cap_reached'=>count($profiles)===64,
                'intake_enabled'=>count($stream)===1&&(string)$stream[0]['enabled']==='1',
                'profiles_enabled'=>count($state)===1&&(string)$state[0]['enabled']==='1',
                'allowed_profile_versions'=>0,'denied_or_unchecked_profile_versions'=>0,'invalid_profile_json'=>0,
                'v1_18_profile_versions'=>0,'unknown_trip_profile_versions'=>0,'incomplete_prefix_versions'=>0,
                'distinct_allowed_people_in_page'=>0,'selected_people_limit'=>4,'selected_scopes'=>0,'current_operational_terminal_scopes'=>0,
                'event_metadata_limit'=>200,'event_metadata_revisions_examined'=>0,'event_metadata_cap_reached'=>false,
                'present_event_revisions'=>0,'declared_outcome_event_revisions'=>0,'declared_manager_role_revisions'=>0,
                'declared_profile_reference_revisions'=>0,'ordinary_sale_marker_revisions'=>0,'subscription_receipt_revisions'=>0,
                'retention_receipt_revisions'=>0,'uninterpreted_event_revisions'=>0,'invalid_event_clock_revisions'=>0,
                'checked_earliest_scope'=>'earliest_in_checked_page_only','independent_control_people'=>0,
                'exposure_and_inquiry_binding'=>'not_established_by_this_audit','purchase_label_readiness'=>false,'event_contracts_independently_verified'=>false,
                'population_coverage'=>'bounded_recent_page_not_global_population','historical_labels_reconstructed'=>0,
                'individual_values_returned'=>0,'native_message_or_event_rows_read'=>0,'database_writes'=>0];
            $people=[];
            foreach($profiles as$p){
                if(!(int)$p['valid_json']){$out['invalid_profile_json']++;continue;}
                if(!(int)$p['admitted']||$p['access_status']!=='allowed'){$out['denied_or_unchecked_profile_versions']++;continue;}
                $out['allowed_profile_versions']++;
                if($p['extractor']==='ru_trip_prefix_rules_v1_18')$out['v1_18_profile_versions']++;
                if($p['trip_status']==='unknown')$out['unknown_trip_profile_versions']++;
                if(in_array($p['prefix_complete'],[0,'0','false'],true))$out['incomplete_prefix_versions']++;
                $key=(string)$p['customer_key'];
                if(!isset($people[$key])||(int)$p['id']<(int)$people[$key]['id'])$people[$key]=$p;
            }
            $out['distinct_allowed_people_in_page']=count($people);
            $scopes=array_values($people);usort($scopes,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);$scopes=array_slice($scopes,0,4);
            $out['selected_scopes']=count($scopes);
            foreach($scopes as$s)$out['current_operational_terminal_scopes']+=(int)$s['current_terminal'];
            if($scopes){
                $sj=$this->safe('v.snapshot_json');$raw=$this->get($sj,'$.payload.payload_json');$payload=$this->safe($raw);
                $scope=[];$args=[$j,$after,$through];
                foreach($scopes as$s){$scope[]='('.$this->get($sj,'$.project_key')."='anytour' AND ".$this->get($sj,'$.platform').'=? AND '.$this->get($sj,'$.customer_key').'=? AND '.$this->get($sj,'$.conversation_id').'=?)';array_push($args,$s['platform'],$s['customer_key'],$s['conversation_id']);}
                $sql="SELECT v.id,v.observed_at_unix,".$this->get($sj,'$.source_status')." AS status,".$this->get($sj,'$.payload_status')." AS payload_status,".$this->get($sj,'$.source_timestamp_basis')." AS clock_basis,".$this->integer($sj,'$.source_created_at_unix')." AS recorded_unix,".$this->get($sj,'$.payload.event_type')." AS event_type,".$this->integer($payload,'$.version')." AS receipt_version,".$this->get($payload,'$.producer')." AS producer,".$this->get($payload,'$.actor_role')." AS actor_role,".$this->get($payload,'$.outcome')." AS outcome,".$this->integer($payload,'$._recorded_clock.unix')." AS clock_unix,".$this->get($payload,'$._recorded_clock.basis')." AS record_basis,".$this->integer($payload,'$._recorded_clock.version')." AS clock_version,".$this->get($payload,'$.profile_id')." AS bound_profile FROM modeled_conversion_stream_versions v WHERE v.project_key='anytour' AND v.source_kind='event' AND v.id<=? AND v.observed_at_unix>? AND v.observed_at_unix<=? AND (".implode(' OR ',$scope).") ORDER BY v.id DESC LIMIT 200";
                $events=$this->rows($sql,$args);$out['event_metadata_revisions_examined']=count($events);$out['event_metadata_cap_reached']=count($events)===200;
                foreach($events as$e){
                    if($e['status']!=='present'||$e['payload_status']!=='copied')continue;
                    $out['present_event_revisions']++;
                    if($e['event_type']==='sales_pipeline_outcome_observed'){
                        if((int)$e['receipt_version']!==1||$e['producer']!=='sales_pipeline_manager_assertion'||!in_array($e['outcome'],['open','won','lost'],true)){$out['uninterpreted_event_revisions']++;continue;}
                        $out['declared_outcome_event_revisions']++;
                        if($e['clock_basis']!=='canonical_recording_unix_v1'||$e['record_basis']!=='server_recording_unix'||(int)$e['clock_version']!==1||(int)$e['clock_unix']!== (int)$e['recorded_unix']||(int)$e['recorded_unix']<946684800||(int)$e['recorded_unix']>(int)$e['observed_at_unix']){$out['invalid_event_clock_revisions']++;continue;}
                        if($e['actor_role']==='manager')$out['declared_manager_role_revisions']++;
                        if($e['bound_profile']!==null&&$e['bound_profile']!=='')$out['declared_profile_reference_revisions']++;
                    }elseif($e['event_type']==='metrika_max_sale')$out['ordinary_sale_marker_revisions']++;
                    elseif($e['event_type']==='channel_subscription_observed')$out['subscription_receipt_revisions']++;
                    elseif($e['event_type']==='channel_retention_30m_observed')$out['retention_receipt_revisions']++;
                    else $out['uninterpreted_event_revisions']++;
                }
            }
            $out['metadata_rows_returned']=$this->rows;$out['elapsed_ms']=(int)round((hrtime(true)-($this->deadline-5000000000))/1000000);
            $this->remaining();$raw=json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(strlen($raw)>65536)throw new RuntimeException('audit_output');
            return $out;
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    }
}

if(!defined('PURCHASE_STUDY_AUDIT_LIBRARY')){
    $began=hrtime(true);$through=time();
    try{
        require_once getcwd().'/config.php';
        require_once getcwd().'/services/ProjectAccessService.php';
        require_once getcwd().'/services/InternalOwnerDialogueScope.php';
        if(ProjectConfig::projectId()!=='anytour')throw new RuntimeException('audit_project');
        ProjectAccessService::initializeReadOnly();
        $out=(new PurchaseStudyMetadataAudit(ConversationDb::connection(),$began))->collect(PurchaseStudyMetadataAudit::AFTER,$through);
        echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
    }catch(Throwable $e){fwrite(STDERR,"purchase_study_metadata_failed\n");exit(1);}
}
