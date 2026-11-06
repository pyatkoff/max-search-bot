<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ModeledConversionVisitProof.php';

/** Private, read-only projection of recorded value rows; never scores or sends. */
final class ModeledConversionDeliveryRegistry
{
    public const VERSION='modeled_delivery_registry_v2';
    public const EVENTS_PER_CONVERSATION=32;
    public const PAGE_SIZE=10;
    private const BYTES=524288;
    private const STATES=['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'];
    private const UPLOADS=['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'];
    private const ERRORS=['identity_not_found','identity_ambiguous','scope_unavailable','api_rejected','api_row_rejected',
        'upload_outcome_unknown','event_expired','transport_not_sent','rate_limited'];
    private float $started;
    private int $bytes=0;
    private $clock;
    public function __construct(private PDO $pdo,private $authorize,private string $cursorKey,?callable $monotonic=null)
    {
        if(strlen($cursorKey)<32||strlen($cursorKey)>256)throw new InvalidArgumentException('delivery_registry_key');
        $this->clock=$monotonic??static fn():float=>microtime(true);
    }
    public function read(array $input,int $now):array
    {
        $this->started=($this->clock)();$this->bytes=0;$this->auth();
        try{
            if($now<946684800||array_diff(array_keys($input),['date','platform','cursor']))throw new InvalidArgumentException();
            $date=$input['date']??(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
            $platform=$input['platform']??'all';
            if(!is_string($date)||preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\z/D',$date)!==1
                ||!is_string($platform)||!in_array($platform,['all','max','telegram'],true))throw new InvalidArgumentException();
            $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Europe/Moscow'));
            if(!$day||$day->format('Y-m-d')!==$date||$day->getTimestamp()>$now)throw new InvalidArgumentException();
            $from=$day->getTimestamp();$until=$day->modify('+1 day')->getTimestamp();
            $cursor=$input['cursor']??null;$saved=null;
            if($cursor!==null){
                if(!is_string($cursor)||($saved=$this->decodeCursor($cursor,$date,$platform,$now))===null)
                    return $this->done(['available'=>false,'reason'=>'invalid_cursor']);
            }
        }catch(InvalidArgumentException $ignored){return $this->done(['available'=>false,'reason'=>'invalid_request']);}
        $asOf=$saved['as_of']??$now;$snapshot=$saved['snapshot']??null;$before=$saved['before']??null;
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $base=self::sourceSql($mysql);$started=false;
        try{
            if($this->pdo->inTransaction())throw new UnexpectedValueException('outer_transaction');
            if($mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('SET TRANSACTION READ ONLY');}
            $this->pdo->beginTransaction();$started=true;
            if($snapshot===null){
                $r=$this->query('SELECT COALESCE(MAX(o.id),0) AS fence '.$base.' AND o.event_unix<=?',[$asOf]);
                $snapshot=self::decimal($r[0]['fence']??null,true);
            }
            $where=' AND o.id<=? AND o.event_unix>=? AND o.event_unix<? AND o.event_unix<=?';
            $args=[$snapshot,$from,$until,$asOf];
            if($platform!=='all'){$where.=' AND o.platform=?';$args[]=$platform;}
            $history="o.value_origin IN ('owner_day_review_v1','owner_fortnight_review_v1')";
            $receipt="o.upload_id IS NOT NULL AND o.upload_id<>''";
            $conditions=['live'=>'NOT ('.$history.') OR o.value_origin IS NULL','historical_review'=>$history,
                'accepted_receipts'=>$receipt,'processed'=>"o.state='processed'",
                'waiting'=>"o.state IN ('pending','ready','uploading')",'problem'=>"o.state IN ('unknown','failed','expired','unmatched')",
                'unresolved_identity'=>"(o.client_id IS NULL OR o.client_id='')"];
            $pairs=[];
            foreach(ModeledConversionValueContract::STAGES as$rank=>$stage)
                $pairs[]="(HEX(o.target)=HEX('".ModeledConversionValueContract::key($rank)."') AND HEX(o.value_stage)=HEX('".$stage."'))";
            $cast=$mysql?'CHAR':'TEXT';
            $pred=$mysql?"CONCAT('anytour_value_pred_',CAST(o.value_profile_id AS CHAR),'_v1')":"'anytour_value_pred_'||CAST(o.value_profile_id AS TEXT)||'_v1'";
            $stageHex=implode(',',array_map(static fn(string $stage):string=>"HEX('".$stage."')",ModeledConversionValueContract::STAGES));
            $pairs[]="(o.value_profile_id IS NOT NULL AND o.value_profile_id>0 AND HEX(o.target)=HEX(".$pred.") AND HEX(o.value_stage) IN (".$stageHex."))";
            $stateHex=implode(',',array_map(static fn(string $state):string=>"HEX('".$state."')",self::STATES));
            $invalid="o.target IS NULL OR o.value_stage IS NULL OR NOT (".implode(' OR ',$pairs).")"
                ." OR o.state IS NULL OR HEX(o.state) NOT IN (".$stateHex.")"
                ." OR o.platform IS NULL OR HEX(o.platform) NOT IN (HEX('max'),HEX('telegram'))"
                ." OR (o.value_origin IS NOT NULL AND HEX(o.value_origin) NOT IN (HEX('owner_day_review_v1'),HEX('owner_fortnight_review_v1')))"
                ." OR o.value_rub IS NULL OR o.value_rub<=0 OR o.value_rub>".ModeledConversionValueContract::MAX_VALUE_RUB;
            $columns=['COUNT(*) AS n','COUNT(DISTINCT o.conversation_id) AS c','COALESCE(SUM(o.value_rub),0) AS v',
                'COALESCE(SUM(CASE WHEN '.$invalid.' THEN 1 ELSE 0 END),0) AS invalid_n'];
            foreach($conditions as$key=>$condition){
                $columns[]='COALESCE(SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END),0) AS '.$key.'_n';
                $columns[]='COUNT(DISTINCT CASE WHEN '.$condition.' THEN o.conversation_id ELSE NULL END) AS '.$key.'_c';
                $columns[]='COALESCE(SUM(CASE WHEN '.$condition.' THEN o.value_rub ELSE 0 END),0) AS '.$key.'_v';
            }
            $r=$this->query('SELECT '.implode(',',$columns).' '.$base.$where,$args)[0];
            if(self::count($r['invalid_n']??null)!==0)throw new UnexpectedValueException();
            $summary=self::summary($r);
            foreach(['live','historical_review']as$key)$summary[$key]=self::summary($r,$key.'_');
            $summary['delivery']=[];
            foreach(['accepted_receipts','processed','waiting','problem','unresolved_identity']as$key)$summary['delivery'][$key]=self::summary($r,$key.'_');
            if($summary['live']['events']+$summary['historical_review']['events']!==$summary['events']
                ||self::cents($summary['live']['value_rub'])+self::cents($summary['historical_review']['value_rub'])!==self::cents($summary['value_rub']))
                throw new UnexpectedValueException();
            $pageArgs=$args;$pageWhere=$where;
            if($before!==null){$pageWhere.=' AND o.conversation_id<?';$pageArgs[]=$before;}
            $ids=$this->query('SELECT o.conversation_id '.$base.$pageWhere
                .' GROUP BY o.conversation_id ORDER BY o.conversation_id DESC LIMIT '.(self::PAGE_SIZE+1),$pageArgs);
            $hasMore=count($ids)>self::PAGE_SIZE;$selected=[];
            foreach(array_slice($ids,0,self::PAGE_SIZE)as$row)$selected[]=self::decimal($row['conversation_id']??null);
            $items=[];
            if($selected){
                $cast=$mysql?'CHAR':'TEXT';
                $safe="CASE WHEN p.profile_json IS NOT NULL AND ".($mysql?'OCTET_LENGTH':'LENGTH')."(p.profile_json)<=131072 AND JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
                $extract=static fn(string $path):string=>$mysql?"JSON_UNQUOTE(JSON_EXTRACT(".$safe.",'".$path."'))":"JSON_EXTRACT(".$safe.",'".$path."')";
                $frozen="p.id IS NOT NULL AND ".$extract('$.access_status')."='allowed' AND ".$extract('$.subject.project_key')."='anytour'"
                    ." AND HEX(".$extract('$.subject.platform').")=HEX(o.platform)"
                    ." AND HEX(CAST(".$extract('$.subject.customer_key')." AS ".$cast."))=HEX(CAST(c.customer_id AS ".$cast."))"
                    ." AND HEX(CAST(".$extract('$.subject.conversation_id')." AS ".$cast."))=HEX(CAST(o.conversation_id AS ".$cast."))";
                $rankSql='CASE o.value_stage '.implode(' ',array_map(static fn(int $rank,string $stage):string=>"WHEN '".$stage."' THEN ".$rank,array_keys(ModeledConversionValueContract::STAGES),array_values(ModeledConversionValueContract::STAGES))).' ELSE 0 END';
                $daySql='o.event_unix>='.(int)$from.' AND o.event_unix<'.(int)$until;
                $win=' OVER (PARTITION BY o.conversation_id)';
                $scalarColumns=[];
                foreach(['semantic_prediction.estimated_probability'=>'probability','deal_value_prediction.deal_amount_rub.point'=>'deal_rub',
                    'deal_value_prediction.full_value_base_rub'=>'frozen_base_rub','deal_value_prediction.value_rub'=>'frozen_value_rub']as$path=>$alias)
                    $scalarColumns[]='CASE WHEN '.$frozen.' THEN '.$extract('$.'.$path).' ELSE NULL END AS '.$alias;
                $sql='SELECT * FROM (SELECT o.id,o.conversation_id,o.platform,o.target,o.event_unix,o.value_rub,o.value_stage,o.state,o.last_error,o.upload_status,o.client_id,o.upload_id,'
                    .'o.value_origin,o.value_profile_id,o.value_ready_at_unix,o.claimed_at_unix,o.value_api_accepted_at_unix,o.value_processed_at_unix,o.value_receipt_basis,'
                    ."CASE WHEN ".$receipt." THEN 1 ELSE 0 END AS receipt_present,"
                    ."CASE WHEN o.client_id IS NOT NULL AND o.client_id<>'' THEN 1 ELSE 0 END AS identity_present,"
                    ."SUBSTR(COALESCE(cu.display_name,''),1,120) AS customer_name,"
                    .'p.observed_at_unix AS profile_at,CASE WHEN '.$frozen.' THEN 1 ELSE 0 END AS frozen_valid,'
                    .implode(',',$scalarColumns).',COUNT(*)'.$win.' AS event_count,SUM(o.value_rub)'.$win.' AS total_rub,'
                    .'SUM(CASE WHEN '.$daySql.' THEN o.value_rub ELSE 0 END)'.$win.' AS day_rub,'
                    .'MAX(CASE WHEN '.$daySql.' THEN '.$rankSql.' ELSE 0 END)'.$win.' AS day_rank,'
                    .'ROW_NUMBER() OVER (PARTITION BY o.conversation_id ORDER BY o.id DESC) AS event_position '
                    .self::sourceSql($mysql,true)
                    .' AND o.id<=? AND o.event_unix<=? AND o.conversation_id IN ('.implode(',',array_fill(0,count($selected),'?')).')'
                    .') bounded_events WHERE event_position<='.self::EVENTS_PER_CONVERSATION
                    .' ORDER BY conversation_id DESC,id ASC LIMIT '.(self::PAGE_SIZE*self::EVENTS_PER_CONVERSATION+1);
                $events=$this->query($sql,[$snapshot,$asOf,...$selected]);
                if(count($events)>self::PAGE_SIZE*self::EVENTS_PER_CONVERSATION)throw new UnexpectedValueException();
                $proof=ModeledConversionVisitProof::readCache($now);
                $grouped=[];foreach($events as$row)$grouped[(string)$row['conversation_id']][]=$row;
                foreach($selected as$id){
                    $rows=$grouped[$id]??[];if(!$rows||count($rows)>self::EVENTS_PER_CONVERSATION)throw new UnexpectedValueException();
                    $first=$rows[0];$period=self::cents($first['day_rub']);$total=self::cents($first['total_rub']);
                    $count=self::count($first['event_count']);$rank=(int)$first['day_rank'];
                    if($period<=0||$total>100*ModeledConversionValueContract::MAX_VALUE_RUB||$count<count($rows)||!isset(ModeledConversionValueContract::STAGES[$rank]))throw new UnexpectedValueException();
                    $item=['conversation_id'=>$id,'platform'=>null,'customer_name'=>'Турист','period_value_rub'=>self::rub($period),
                        'total_value_rub'=>self::rub($total),'stage'=>ModeledConversionValueContract::STAGES[$rank],
                        'events_total'=>$count,'events_omitted'=>$count-count($rows),'events'=>[]];$seen=[];
                    foreach($rows as$row){
                        $event=$this->event($row,$now);$target=(string)$row['target'];
                        if(isset($seen[$target]))throw new UnexpectedValueException();$seen[$target]=true;
                        if($item['platform']!==null&&$item['platform']!==$row['platform'])throw new UnexpectedValueException();
                        $item['platform']=$row['platform'];$name=trim((string)$row['customer_name']);if($name!=='')$item['customer_name']=$name;
                        $event['in_selected_day']=(int)$row['event_unix']>=$from&&(int)$row['event_unix']<$until;
                        $event['visit_check']=ModeledConversionVisitProof::forEvent($proof,$row);
                        $item['events'][]=$event;
                    }
                    $items[]=$item;
                }
            }
            $next=$hasMore&&$selected?$this->encodeCursor(['v'=>1,'date'=>$date,'platform'=>$platform,
                'snapshot'=>$snapshot,'before'=>$selected[count($selected)-1],'as_of'=>$asOf]):null;
            $this->pdo->commit();$started=false;
            $out=['available'=>true,'version'=>self::VERSION,'date'=>$date,'timezone'=>'Europe/Moscow',
                'basis'=>'saved_value_event_unix','as_of'=>gmdate('Y-m-d\TH:i:s\Z',$asOf),'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                'window'=>['from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'until_exclusive'=>gmdate('Y-m-d\TH:i:s\Z',$until)],
                'platform'=>$platform,'currency'=>'RUB','summary'=>$summary,'rows'=>$items,
                'pagination'=>['page_size'=>self::PAGE_SIZE,'shown'=>count($items),'has_more'=>$hasMore,'next_cursor'=>$next],
                'interpretation'=>['day_is_saved_assignment_not_upload_or_customer_message_day'=>true,
                    'total_is_same_contract_lifetime_reserved_value_not_income'=>true,'receipt_and_processed_overlap'=>true,
                    'processed_is_not_individual_visit_linkage'=>true,'old_receipt_clock_is_unknown'=>true,
                    'upper_id_and_event_cutoff_fixed_statuses_are_current_reads'=>true,'late_commits_or_scope_changes_can_change_membership'=>true,'grounds_are_saved_stage_not_a_new_text_interpretation'=>true,
                    'no_transcripts_or_advertising_identifiers'=>true],
                'limits'=>['page_conversations'=>self::PAGE_SIZE,'events_per_conversation'=>self::EVENTS_PER_CONVERSATION,'read_bytes'=>self::BYTES,'seconds'=>5]];
            return $this->done($out);
        }catch(PDOException $ignored){
            if($started&&$this->pdo->inTransaction())$this->pdo->rollBack();
            return $this->done(['available'=>false,'reason'=>'storage_unavailable']);
        }catch(UnexpectedValueException $ignored){
            if($started&&$this->pdo->inTransaction())$this->pdo->rollBack();
            return $this->done(['available'=>false,'reason'=>'invalid_ledger']);
        }catch(OverflowException $ignored){
            if($started&&$this->pdo->inTransaction())$this->pdo->rollBack();
            ($this->authorize)();return ['available'=>false,'reason'=>'read_limit'];
        }catch(Throwable $error){
            if($started&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $error;
        }
    }
    public static function sourceSql(bool $mysql,bool $profile=false):string
    {
        $cast=$mysql?'CHAR':'TEXT';$peer=$mysql?"CONCAT('-',o.external_user_id)":"'-'||o.external_user_id";
        return 'FROM max_channel_conversion_outbox o JOIN conversations c ON c.id=o.conversation_id AND HEX(c.project_key)=HEX(o.project_key) AND HEX(c.channel)=HEX(o.platform)'
            .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)'
            .' JOIN customers cu ON cu.id=c.customer_id'
            .($profile?' LEFT JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id AND HEX(p.project_key)=HEX(o.project_key) AND HEX(p.platform)=HEX(o.platform) AND p.conversation_id=o.conversation_id AND HEX(p.customer_key)=HEX(CAST(c.customer_id AS '.$cast.'))':'')
            ." WHERE o.project_key='anytour' AND o.counter_id='98615635' AND o.transport_target='anytour_modeled_value_v1' AND o.value_contract='semantic_stage_value_25000_v1'"
            ." AND HEX(o.project_key)=HEX('anytour') AND HEX(o.transport_target)=HEX('anytour_modeled_value_v1') AND HEX(o.value_contract)=HEX('semantic_stage_value_25000_v1')"
            ." AND NOT (COALESCE(o.value_rub,-1)=0 AND COALESCE(HEX(o.state),'')=HEX('failed') AND COALESCE(HEX(o.last_error),'')=HEX('price_loss_reconciled'))"
            ." AND o.platform IN ('max','telegram') AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')
            .' AND HEX(CAST(cc.external_user_id AS '.$cast.'))=HEX(CAST(o.external_user_id AS '.$cast.'))'
            .' AND HEX(CAST(cc.external_chat_id AS '.$cast.'))=HEX(CAST(c.external_chat_id AS '.$cast.'))'
            ." AND HEX(CAST(c.external_chat_id AS ".$cast."))=HEX(CAST(CASE WHEN o.platform='max' THEN ".$peer.' ELSE o.external_user_id END AS '.$cast.'))';
    }
    private function event(array $r,int $now):array
    {
        $id=self::decimal($r['id']??null);$stage=$r['value_stage']??null;$rank=array_search($stage,ModeledConversionValueContract::STAGES,true);
        $pred=is_numeric($r['value_profile_id']??null)&&($r['target']??null)===ModeledConversionValueContract::predictionKey((string)$r['value_profile_id']);
        if($rank===false||(!$pred&&($r['target']??null)!==ModeledConversionValueContract::key($rank))
            ||!in_array($r['state']??null,self::STATES,true)||self::cents($r['value_rub'])<=0
            ||self::cents($r['value_rub'])>100*ModeledConversionValueContract::MAX_VALUE_RUB||!in_array($r['platform']??null,['max','telegram'],true))
            throw new UnexpectedValueException();
        $origin=$r['value_origin']??null;
        if($origin!==null&&!in_array($origin,['owner_day_review_v1','owner_fortnight_review_v1'],true))throw new UnexpectedValueException();
        $valid=(string)($r['frozen_valid']??'0')==='1';
        $profileId=$r['value_profile_id']===null?null:self::decimal($r['value_profile_id']);
        $profileAt=$valid?self::moment($r['profile_at']??null,$now):null;
        if($profileAt===null)$valid=false;
        $basis=$r['value_receipt_basis']??null;if($basis!==null&&!in_array($basis,['post_response','get_reconciliation'],true))throw new UnexpectedValueException();
        $error=$r['last_error']??'';
        return ['id'=>$id,'stage'=>$stage,'value_rub'=>self::rub(self::cents($r['value_rub'])),'state'=>$r['state'],
            'error'=>$error===''?null:(in_array($error,self::ERRORS,true)?$error:'unclassified_error'),
            'upload_status'=>in_array($r['upload_status']??null,self::UPLOADS,true)?$r['upload_status']:null,
            'event_at'=>self::moment($r['event_unix']??null,$now),'ready_at'=>self::moment($r['value_ready_at_unix']??null,$now),
            'upload_claim_at'=>self::moment($r['claimed_at_unix']??null,$now),'api_accepted_at'=>self::moment($r['value_api_accepted_at_unix']??null,$now),
            'processed_at'=>self::moment($r['value_processed_at_unix']??null,$now),'receipt_basis'=>$basis,
            'api_receipt_present'=>(string)$r['receipt_present']==='1','identity_resolved'=>(string)$r['identity_present']==='1',
            'origin'=>$origin===null?'live':$origin,'profile_id'=>$profileId,'profile_saved_at'=>$valid?$profileAt:null,
            'grounds_status'=>$valid?'saved_stage_profile':'profile_unavailable',
            'valuation'=>['basis'=>$pred?'predictive_v1':'stage_v1','probability'=>$valid&&is_numeric($r['probability']??null)?(float)$r['probability']:null,
                'deal_rub'=>$valid&&is_numeric($r['deal_rub']??null)?(int)$r['deal_rub']:null,
                'frozen_base_rub'=>$valid&&is_numeric($r['frozen_base_rub']??null)?(int)$r['frozen_base_rub']:null,
                'frozen_value_rub'=>$valid&&is_numeric($r['frozen_value_rub']??null)?(int)$r['frozen_value_rub']:null,'commission_rate'=>0.07]];
    }
    private function decodeCursor(string $cursor,string $date,string $platform,int $now):?array
    {
        if(strlen($cursor)>1024||preg_match('/\Av1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$cursor,$m)!==1
            ||!hash_equals(hash_hmac('sha256',"delivery-registry-v1\nEurope/Moscow\n".$m[1],$this->cursorKey),$m[2]))return null;
        $raw=base64_decode(strtr($m[1],'-_','+/'),true);if($raw===false)return null;
        try{$v=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){return null;}
        if(!is_array($v)||array_keys($v)!==['v','date','platform','snapshot','before','as_of']||$v['v']!==1
            ||$v['date']!==$date||$v['platform']!==$platform||!is_int($v['as_of'])||$v['as_of']>$now||$v['as_of']<$now-3600)return null;
        try{$v['snapshot']=self::decimal($v['snapshot'],true);$v['before']=self::decimal($v['before']);}catch(UnexpectedValueException $ignored){return null;}
        return $v;
    }
    private function encodeCursor(array $v):string
    {
        // A continuation from the old calendar must not silently select a different cohort.
        $data=rtrim(strtr(base64_encode(json_encode($v,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        return 'v1.'.$data.'.'.hash_hmac('sha256',"delivery-registry-v1\nEurope/Moscow\n".$data,$this->cursorKey);
    }
    private static function decimal(mixed $v,bool $zero=false):string
    {
        if(is_int($v))$v=(string)$v;
        if(!is_string($v)||preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$v)!==1)throw new UnexpectedValueException();
        return $v;
    }
    private static function count(mixed $v):int
    {
        $v=self::decimal($v,true);if(strlen($v)>10||(float)$v>1000000000)throw new UnexpectedValueException();return(int)$v;
    }
    private static function cents(mixed $v):int
    {
        if(is_float($v)){
            if(!is_finite($v)||$v<0||$v>1000000000000)throw new UnexpectedValueException();
            $n=round($v*100);if(abs($v*100-$n)>0.01)throw new UnexpectedValueException();return(int)$n;
        }
        if(is_int($v))$v=(string)$v;
        if(!is_string($v)||preg_match('/\A([0-9]{1,13})(?:\.([0-9]{1,2}))?\z/D',$v,$m)!==1||(float)$v>1000000000000)throw new UnexpectedValueException();
        return(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    private static function rub(int $cents):int|float{return $cents%100===0?intdiv($cents,100):$cents/100;}
    private static function summary(array $r,string $prefix=''):array
    {
        $n=self::count($r[$prefix.'n']??null);$c=self::count($r[$prefix.'c']??null);$v=self::cents($r[$prefix.'v']??null);
        if($c>$n||($n===0&&($c!==0||$v!==0))||($n>0&&($v<$n||$v>$n*100*ModeledConversionValueContract::MAX_VALUE_RUB)))throw new UnexpectedValueException();
        return ['events'=>$n,'conversations'=>$c,'value_rub'=>self::rub($v)];
    }
    private static function moment(mixed $v,int $now):?string
    {
        if($v===null)return null;$v=self::decimal($v);
        if(strlen($v)>11||(float)$v<946684800||(float)$v>$now+60)throw new UnexpectedValueException();
        return gmdate('Y-m-d\TH:i:s\Z',(int)$v);
    }
    private function query(string $sql,array $args):array
    {
        $this->auth();if(preg_match('/\ASELECT\s/i',$sql)!==1)throw new LogicException('delivery_registry_select_only');
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $ms=max(1,(int)floor((5-(($this->clock)()-$this->started))*1000));
            $sql=preg_replace('/\ASELECT\s/i','SELECT /*+ MAX_EXECUTION_TIME('.$ms.') */ ',$sql,1);
        }
        $q=$this->pdo->prepare($sql);foreach($args as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $this->bytes+=strlen(json_encode($rows,JSON_THROW_ON_ERROR));$this->auth();return $rows;
    }
    private function done(array $out):array
    {
        $this->bytes+=strlen(json_encode($out,JSON_THROW_ON_ERROR));$this->auth();return $out;
    }
    private function auth():void
    {
        $elapsed=($this->clock)()-$this->started;
        if($elapsed<0||$elapsed>=5||$this->bytes>self::BYTES)throw new OverflowException('delivery_registry_limit');
        ($this->authorize)();
        $elapsed=($this->clock)()-$this->started;
        if($elapsed<0||$elapsed>=5||$this->bytes>self::BYTES)throw new OverflowException('delivery_registry_limit');
    }
}
