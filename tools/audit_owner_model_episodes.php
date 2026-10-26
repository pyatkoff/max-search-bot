<?php
declare(strict_types=1);
// Runner-only, once owner-admitted6409686245. Never install on MAIN.
ini_set('display_errors','0');ini_set('log_errors','0');set_time_limit(60);

function ownerScopeCommitment(array $rows): string {
    $keys=[];
    foreach($rows as $r){
        foreach(['conversation_id','customer_id'] as $k)if(!ctype_digit((string)$r[$k])||(int)$r[$k]<=0)throw new RuntimeException('invalid_scope');
        if(!in_array($r['channel'],['max','telegram'],true))throw new RuntimeException('invalid_scope');
        $keys[]='anytour|'.$r['channel'].'|customer:'.$r['customer_id'].'|'.$r['conversation_id'];
    }
    sort($keys,SORT_STRING);
    return hash('sha256',json_encode($keys,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
function ownerReconstructExcluded(array $rows,string $commitment): array {
    if(count($rows)>12||!preg_match('/\A[a-f0-9]{64}\z/D',$commitment))throw new RuntimeException('invalid_exclusion_input');
    $matches=[];
    $walk=function(array $chosen,int $next)use(&$walk,&$matches,$rows,$commitment):void{
        if(count($chosen)===5){if(hash_equals($commitment,ownerScopeCommitment($chosen)))$matches[]=$chosen;return;}
        for($i=$next;$i<=count($rows)-(5-count($chosen));$i++)$walk([...$chosen,$rows[$i]],$i+1);
    };
    $walk([],0);
    if(count($matches)!==1)throw new RuntimeException('exact_exclusion_not_reconstructed');
    return $matches[0];
}
function ownerReadFits(int $rows,int $used,int $episodes): bool {return $rows>0&&$rows<=50&&$used+$rows<=250&&$episodes<5;}
function ownerUtc(string $value,DateTimeZone $zone): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$zone);
    if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('source_time');
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
if(getenv('OWNER_EPISODE_SYNTHETIC_ONLY')==='1')return;

$phase='bootstrap';$pdo=null;ob_start();
try{
    $issued=1792998318;
    if(time()<$issued||time()-$issued>3600)throw new RuntimeException('stale_admission');
    require_once getcwd().'/config.php';
    require_once getcwd().'/services/ProjectConfig.php';
    require_once getcwd().'/services/ManagerConversationService.php';
    require_once getcwd().'/tools/ModeledConversionCandidateSelection.php';
    if(ProjectConfig::projectId()!=='anytour')throw new RuntimeException('scope');
    $pdo=ConversationDb::connection();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    ProjectAccessService::initializeReadOnly();
    $clock=$pdo->query('SELECT UTC_TIMESTAMP() AS captured_at,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS utc_offset,@@session.time_zone AS session_zone,@@system_time_zone AS system_zone')->fetch();
    $name=(string)($clock['session_zone']==='SYSTEM'?$clock['system_zone']:$clock['session_zone']);
    $name=['MSK'=>'Europe/Moscow','UTC'=>'UTC','GMT'=>'UTC'][$name]??$name;
    if($name!=='UTC'&&!str_contains($name,'/')&&!preg_match('/\A[+-][0-9]{2}:[0-9]{2}\z/D',$name))throw new RuntimeException('unknown_zone');
    $zone=new DateTimeZone($name);
    $cutoff=(new DateTimeImmutable((string)$clock['captured_at'],new DateTimeZone('UTC')))->getTimestamp();
    if(abs(time()-$cutoff)>60||$zone->getOffset(new DateTimeImmutable('@'.$cutoff))!==(int)$clock['utc_offset'])throw new RuntimeException('clock_mismatch');
    $local=fn(int $t)=>(new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d H:i:s');
    $captured=str_replace(' ','T',(string)$clock['captured_at']).'Z';
    $phase='prior_metadata';
    // Original fixed fence/ordering; scope metadata only, no old transcript fetch.
    $old=1792960647;
    $type="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(mc.metadata_json) THEN mc.metadata_json ELSE '{}' END,'$.type')),'message')";
    $sql="SELECT c.id AS conversation_id,c.customer_id,c.channel,MAX(mc.id) AS qualifier FROM conversations c JOIN managers ma ON ma.id=c.manager_id AND ma.is_active=1 JOIN messages mc ON mc.conversation_id=c.id WHERE c.project_key='anytour' AND c.channel IN ('max','telegram') AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND mc.direction='inbound' AND mc.sender_type='customer' AND $type NOT IN ('callback','start','bot_started') AND CHAR_LENGTH(TRIM(mc.text))>=8 AND mc.created_at>=? AND mc.created_at<=? GROUP BY c.id,c.customer_id,c.channel ORDER BY qualifier DESC,c.id DESC LIMIT 12";
    $q=$pdo->prepare($sql);$q->execute([$local($old-7*86400),$local($old)]);$prior=$q->fetchAll(PDO::FETCH_ASSOC);
    $excluded=ownerReconstructExcluded($prior,'7885b915ec4a099837e1a433d601f65677edb8ccefaa1be73458ef64cde1e456');
    $excludedPeople=array_map(static fn($r)=>(int)$r['customer_id'],$excluded);
    $phase='actor';
    $q=$pdo->query("SELECT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.role='admin' AND m.is_active=1 AND p.project_key='anytour' AND p.is_active=1 ORDER BY m.id LIMIT 1");
    $actor=(int)$q->fetchColumn();
    if(!ManagerAuthService::isAdmin(ManagerAuthService::byId($actor))||!ProjectAccessService::canAccess($actor,'anytour'))throw new RuntimeException('no_existing_authorized_actor');
    $phase='candidates';
    $query=ModeledConversionCandidateSelection::candidateQuery($local($cutoff-7*86400),$local($cutoff),'max');
    if(!str_ends_with($query['sql'],'LIMIT 100'))throw new RuntimeException('query_contract');
    // 12 prior + 88 current metadata candidates <= owner's cap100.
    $q=$pdo->prepare(substr($query['sql'],0,-9).'LIMIT 88');$q->execute($query['bindings']);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);
    $cases=[];$readEpisodes=0;$used=0;$scanned=0;$skipped=[];$seen=[];
    $skip=function(string $reason)use(&$skipped){$skipped[$reason]=($skipped[$reason]??0)+1;};
    foreach($candidates as $x){
        if($readEpisodes>=5)break;$scanned++;
        $cid=(int)$x['conversation_id'];$person=(int)$x['customer_id'];
        if(in_array($person,$excludedPeople,true)){$skip('prior_person');continue;}
        if(isset($seen[$person])){$skip('person_already_read');continue;}
        $phase='exposure';
        $keys=[hash('sha256','virtual_reply_dialogue_v1|anytour|customer|'.$person),hash('sha256','virtual_reply_dialogue_v1|anytour|conversation|'.$cid)];
        $q=$pdo->prepare("SELECT 1 FROM virtual_reply_sampling_aliases WHERE project_key='anytour' AND identity_key IN (?,?) LIMIT 1");$q->execute($keys);$exposed=(bool)$q->fetchColumn();
        foreach(['virtual_reply_cases','virtual_reply_control_cases'] as $table){
            $q=$pdo->prepare("SELECT 1 FROM $table v JOIN messages m ON v.source_kind='conversation' AND v.source_id=m.id JOIN conversations oc ON oc.id=m.conversation_id WHERE oc.customer_id=? LIMIT 1");$q->execute([$person]);$exposed=$exposed||(bool)$q->fetchColumn();
        }
        if($exposed){$skip('known_review_group');continue;}
        $phase='bounds';
        $q=$pdo->prepare('SELECT COUNT(*) AS n,MAX(created_at) AS last_at FROM messages WHERE conversation_id=?');$q->execute([$cid]);$bound=$q->fetch(PDO::FETCH_ASSOC);
        $n=(int)$bound['n'];
        if(!ownerReadFits($n,$used,$readEpisodes)){$skip('all_row_cap');continue;}
        if((string)$bound['last_at']>$local($cutoff)){$skip('after_fence');continue;}
        $phase='access';$c=ManagerConversationService::visibleConversation($cid,$actor,true);
        if(!$c||$c['project_key']!=='anytour'||$c['channel']!=='max'||(int)$c['customer_id']!==$person){$skip('access_or_identity');continue;}
        $phase='messages';$readEpisodes++;$seen[$person]=true;
        $q=$pdo->prepare('SELECT id,direction,sender_type,text,created_at,metadata_json FROM messages WHERE conversation_id=? ORDER BY id LIMIT 50');$q->execute([$cid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $used+=count($rows);
        if(count($rows)!==$n||$used>250)throw new RuntimeException('source_bound_changed');
        $messages=[];$large=false;
        foreach($rows as $m){
            if(mb_strlen((string)$m['text'],'UTF-8')>4000){$large=true;break;}
            $meta=json_decode((string)$m['metadata_json'],true);
            $kind=is_array($meta)?(string)($meta['type']??'message'):'invalid_metadata';
            if(!preg_match('/\A[a-z_]{1,40}\z/D',$kind))$kind='unsupported';
            $messages[]=['id'=>(string)$m['id'],'direction'=>(string)$m['direction'],'sender_type'=>(string)$m['sender_type'],'text'=>(string)$m['text'],'recorded_at'=>ownerUtc((string)$m['created_at'],$zone),'type'=>$kind,'metadata_revision'=>hash('sha256',(string)$m['metadata_json'])];
        }
        if($large){$skip('oversize_text');continue;}
        $phase='access_recheck';$fresh=ManagerConversationService::visibleConversation($cid,$actor,true);
        if(!$fresh)throw new RuntimeException('access_changed');
        foreach(['customer_id','project_key','source_id','channel','manager_id','status'] as $k)if($fresh[$k]!==$c[$k])throw new RuntimeException('access_changed');
        $cases[]=['subject'=>['project_key'=>'anytour','platform'=>'max','customer_key'=>'customer:'.$person,'conversation_id'=>(string)$cid],
            'started_at'=>ownerUtc((string)$c['started_at'],$zone),'messages_complete'=>false,'events_complete'=>false,'messages'=>$messages,
            'tour_choices'=>['available'=>false],'source_clock'=>'database_persistence_normalized_to_utc_not_provider_occurrence'];
    }
    $phase='serialize';$pdo->rollBack();
    $data=['schema_version'=>1,'captured_at'=>$captured,'database_zone'=>$name,'database_offset_seconds'=>(int)$clock['utc_offset'],
        'scope_cutoff_epoch'=>$cutoff,'lookback_days'=>7,'candidate_count'=>count($candidates),'candidate_cap'=>88,'case_cap'=>5,'message_cap'=>50,
        'skipped'=>$skipped,'database_writes'=>false,'conversion_uploads'=>0,'cases'=>$cases];
    $receipt=['prior_metadata_candidates'=>count($prior),'current_metadata_candidates'=>count($candidates),'metadata_candidates_total'=>count($prior)+count($candidates),
        'current_candidates_scanned'=>$scanned,'prior_people_excluded'=>count($excluded),'prior_exclusion_verified'=>true,'candidate_prefixes_read'=>$readEpisodes,
        'all_message_rows_read'=>$used,'raw_prefixes_saved'=>count($cases),'old_transcript_rows_read'=>0,'person_exposure'=>'unknown_not_untouched',
        'trip_admission'=>'pending_private_semantic_review','source_operations'=>1,'database_writes'=>false];
    $wire=json_encode(['receipt'=>$receipt,'snapshot'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(strlen($wire)>2*1024*1024||ob_get_length()!==0)throw new RuntimeException('payload_bound');
    ob_end_clean();echo $wire;
}catch(Throwable $e){
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(ob_get_level())ob_end_clean();
    $state=$e instanceof PDOException?(string)($e->errorInfo[0]??'none'):'none';$number=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;
    if(!preg_match('/\A[A-Z0-9]{5}\z/D',$state))$state='none';
    fwrite(STDERR,'owner_episode_read_failed:'.$phase.':'.$state.':'.$number.PHP_EOL);exit(1);
}
