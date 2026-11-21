<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueRegistry.php';
require_once __DIR__.'/ModeledConversionPredictionFollowup.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Owner-only retrospective over saved derived blocks. No transcript, processing, label or export writes. */
final class ModeledConversionForecastPaymentReader
{
    public const VERSION='forecast_payment_owner_v1';
    public const AGGREGATE_VERSION='forecast_payment_aggregate_v1';
    public const PAGE_SIZE=8;
    public const SCAN_LIMIT=128;
    public const MAX_SECONDS=15;
    public const MAX_BYTES=4194304;
    private bool $mysql;
    private float $started;
    private int $bytes=0;
    private bool $aggregateMode=false;
    private array $firstCache=[],$accessCache=[];
    public function __construct(private PDO $pdo,private $authorize,private string $cursorKey)
    {
        if(strlen($cursorKey)<32||strlen($cursorKey)>256)throw new InvalidArgumentException('forecast_payment_key');
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['mysql','sqlite'],true))throw new InvalidArgumentException('forecast_payment_driver');
        $this->mysql=$driver==='mysql';
    }
    public function read(array $input,int $now):array
    {
        return $this->readInternal($input,$now,false);
    }
    /** One bounded anonymous snapshot; no cursor or cumulative cross-request state. */
    public function readAggregate(array $input,int $now):array
    {
        return $this->readInternal($input,$now,true);
    }
    private function readInternal(array $input,int $now,bool $aggregate):array
    {
        $this->started=microtime(true);$this->bytes=0;$this->firstCache=[];$this->accessCache=[];$this->aggregateMode=$aggregate;$this->auth();
        try{$f=$this->filters($input,$now,$aggregate);$saved=isset($input['cursor'])?$this->decode((string)$input['cursor'],$f,$now):null;
            if(isset($input['cursor'])&&$saved===null)return $this->done($this->unavailable('invalid_cursor',$f));
        }catch(Throwable $e){$this->auth();return $this->done($this->unavailable('invalid_filters',[]));}
        if($this->pdo->inTransaction())return $this->done($this->unavailable('outer_transaction',$f));
        $own=false;$scanned=0;$omitted=[];$invalidEarlier=[];$aggregateState=self::emptyAggregate();$window=null;$at=$now;
        try{
            if($this->mysql)$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->pdo->beginTransaction();$own=true;$at=$saved['at']??$now;$after=$saved['after']??'0';
            $fence=$saved['fence']??(string)$this->query("SELECT COALESCE(MAX(id),0) AS n FROM modeled_conversion_profile_versions WHERE project_key='anytour'")[0]['n'];
            if(!self::decimal($fence))throw new RuntimeException();
            $window=$this->window($f,$at);$args=[$after,$fence,$window['from_unix'],$window['until_unix'],$at];
            $where="p.id>? AND p.id<=? AND p.observed_at_unix>=? AND p.observed_at_unix<? AND p.observed_at_unix<=?"
                ." AND ".$this->scalar('$.semantic_prediction.version')."='semantic_purchase_heuristic_v1'"
                ." AND ".$this->scalar('$.semantic_prediction.status')."='predicted'";
            if($f['platform']!=='all'){$where.=' AND p.platform=?';$args[]=$f['platform'];}
            $candidates=$this->query($this->select().' '.$this->native().' WHERE '.$where.' ORDER BY p.id LIMIT 129',$args);
            $rows=[];$last=$after;
            foreach($candidates as$meta){
                if($scanned===self::SCAN_LIMIT||(!$aggregate&&count($rows)===self::PAGE_SIZE))break;
                $this->guard();$last=(string)$meta['id'];$scanned++;$why=null;$p=$this->packet($meta,$at,$why);
                if($p===null){$omitted[$why??'invalid_packet']=($omitted[$why??'invalid_packet']??0)+1;continue;}
                $anchor=$p['current_request_anchor'];
                if($p['status']!=='predicted'||$anchor===null||$p['anchor_source_version_no']!==1){$omitted['unusable_anchor']=($omitted['unusable_anchor']??0)+1;continue;}
                $scope=self::scope($meta);$key=implode('|',$scope).'|'.$anchor;
                if(!array_key_exists($key,$this->firstCache))$this->firstCache[$key]=$this->first($scope,$anchor,$fence,$at);
                $first=$this->firstCache[$key];
                if($first===null){$omitted['earliest_unavailable']=($omitted['earliest_unavailable']??0)+1;continue;}
                if((string)$first['meta']['id']!==$last){$omitted['not_global_first']=($omitted['not_global_first']??0)+1;continue;}
                if(!$this->accessible($scope)){$omitted['current_scope_unavailable']=($omitted['current_scope_unavailable']??0)+1;continue;}
                $latest=$this->lookup($scope,$anchor,$fence,$at,false,1);$current=null;$laterWhy='later_snapshot_missing';
                if($latest){$current=$this->packet($latest[0],$at,$laterWhy);}
                $followup=ModeledConversionPredictionFollowup::compare($current??[],$p);
                $end=strtotime($p['horizon_end_at']);
                if($aggregate){
                    self::accumulate($aggregateState,$scope['platform'],$p,$followup,$at>=$end);
                    foreach($first['invalid']as$reason=>$count)$invalidEarlier[$reason]=($invalidEarlier[$reason]??0)+$count;
                    continue;
                }
                $rows[]=['profile_id'=>$last,'platform'=>$scope['platform'],'customer_key'=>$scope['customer_key'],
                    'conversation_id'=>$scope['conversation_id'],'customer_name'=>self::name($meta['customer_name']??null),'request_anchor'=>$anchor,'trip_status'=>'unverified',
                    'forecast'=>['frozen_at'=>$p['frozen_at'],'input_cutoff_at'=>$p['input_cutoff_at'],
                        'profile_observed_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$meta['observed_at_unix']),
                        'prediction_digest'=>$p['prediction_digest'],'estimated_probability'=>$p['estimated_probability'],'stage'=>$p['stage'],
                        'model_sha256'=>$p['model_sha256'],'source_fence_journal_id'=>$p['source_fence_journal_id'],
                        'producer_sha256'=>$meta['_producer_sha'],'invalid_earlier_profiles'=>$first['invalid']],
                    'followup'=>$followup,'later'=>['profile_id'=>$latest?(string)$latest[0]['id']:null,
                        'frozen_at'=>$current['frozen_at']??null,'status'=>$current===null?'unavailable':'admitted','reason'=>$current===null?$laterWhy:null],
                    'observations'=>$current['outcome_observations']??[],
                    'maturity'=>['state'=>$at>=$end?'elapsed_coverage_unknown':'window_open','horizon_end_at'=>$p['horizon_end_at']],
                    'evaluation_eligible'=>false,'verified_payment'=>false];
            }
            $more=count($candidates)>$scanned;
            $page=$aggregate?$this->aggregatePage($f,$at,$window,$aggregateState,$scanned,$omitted,$invalidEarlier,!$more,$more?'scan_limit':null):
                ['version'=>self::VERSION,'available'=>true,'reason'=>null,'filters'=>$f,'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$at),
                'window'=>array_intersect_key($window,array_fill_keys(['from','until','timezone'],true)),
                'cohort_basis'=>'first_admitted_prediction_profile_observed_clock','rows'=>$rows,'has_more'=>$more,
                'next_cursor'=>$more?$this->encode(['at'=>$at,'fence'=>$fence,'after'=>$last],$f):null,
                'coverage'=>['scanned_profiles'=>$scanned,'returned_rows'=>count($rows),'scanned_prefix_complete'=>!$more,
                    'omitted'=>$omitted,'source'=>'saved_semantic_prediction_namespace_not_all_inquiries','history_complete'=>false,
                    'trip_identity'=>'unverified','negative_payment_labels'=>0,'probability_calibrated'=>false,
                    'totals_available'=>false,'max_scan_profiles'=>self::SCAN_LIMIT,'max_rows'=>self::PAGE_SIZE,
                    'max_seconds'=>self::MAX_SECONDS,'max_bytes'=>self::MAX_BYTES]];
        }catch(Throwable $e){
            $page=$aggregate&&$e instanceof LengthException&&is_array($window)
                ?$this->aggregatePage($f,$at,$window,$aggregateState,$scanned,$omitted,$invalidEarlier,false,'read_limit')
                :$this->unavailable($e instanceof LengthException?'read_limit':'storage_unavailable',$f);
        }
        finally{if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();}
        return $this->done($page);
    }
    private function first(array $scope,string $anchor,string $fence,int $at):?array
    {
        $invalid=[];$rows=$this->lookup($scope,$anchor,$fence,$at,true,33);
        foreach($rows as$i=>$meta){
            if($i===32)return null;$why=null;$p=$this->packet($meta,$at,$why);
            if($p!==null&&$p['status']==='predicted'&&$p['anchor_source_version_no']===1)return['meta'=>$meta,'invalid'=>$invalid];
            $reason=$why??'unusable_anchor';$invalid[$reason]=($invalid[$reason]??0)+1;
        }return null;
    }
    private function lookup(array $s,string $anchor,string $fence,int $at,bool $first,int $limit):array
    {
        $where="p.project_key=? AND p.platform=? AND p.customer_key=? AND p.conversation_id=? AND p.id<=? AND p.observed_at_unix<=?"
            ." AND ".$this->scalar('$.semantic_prediction.version')."='semantic_purchase_heuristic_v1'"
            ." AND ".$this->scalar('$.semantic_prediction.current_request_anchor')."=?";
        if($first)$where.=" AND ".$this->scalar('$.semantic_prediction.status')."='predicted'";
        return $this->query($this->select().' '.$this->native().' WHERE '.$where.' ORDER BY p.id '.($first?'ASC':'DESC').' LIMIT '.$limit,
            [...array_values($s),$fence,$at,$anchor]);
    }
    private function accessible(array $scope):bool
    {
        $key=implode('|',$scope);if(array_key_exists($key,$this->accessCache))return $this->accessCache[$key];
        $rows=$this->query('SELECT '.$this->scalar('$.access_status').' AS access_status '.$this->native()
            .' WHERE p.project_key=? AND p.platform=? AND p.customer_key=? AND p.conversation_id=? ORDER BY p.id DESC LIMIT 1',array_values($scope));
        return $this->accessCache[$key]=count($rows)===1&&($rows[0]['access_status']??null)==='allowed';
    }
    private function select():string
    {
        $paths=['$.semantic_prediction','$.subject','$.profile.prediction_at','$.processing','$.early_forecast.version','$.early_forecast.target_version','$.access_status'];
        $name=$this->aggregateMode?'NULL':"SUBSTR(COALESCE(cu.display_name,''),1,200)";
        return "SELECT p.id,p.project_key,p.platform,p.customer_key,p.conversation_id,p.journal_cursor,p.observed_at_unix,p.input_sha256,".$name." AS customer_name,"
            .'JSON_EXTRACT('.$this->safe().','.implode(',',array_map(static fn(string $p):string=>"'".$p."'",$paths)).') AS packet';
    }
    private function native():string
    {
        $cast=$this->mysql?'CHAR':'TEXT';$peer=$this->mysql?"CONCAT('-',cc.external_user_id)":"'-'||cc.external_user_id";
        return 'FROM modeled_conversion_profile_versions p JOIN conversations c ON c.id=p.conversation_id AND c.customer_id=p.customer_key'
            .' AND c.project_key=p.project_key AND c.channel=p.platform AND HEX(CAST(c.id AS '.$cast.'))=HEX(p.conversation_id)'
            .' AND HEX(CAST(c.customer_id AS '.$cast.'))=HEX(p.customer_key) AND HEX(c.project_key)=HEX(p.project_key) AND HEX(c.channel)=HEX(p.platform)'
            .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel'
            ." AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)"
            ." AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')
            ." AND p.project_key='anytour' AND HEX(p.project_key)=HEX('anytour') AND p.platform IN ('max','telegram')"
            ." AND HEX(p.platform) IN (HEX('max'),HEX('telegram'))"
            .' AND HEX(CAST(cc.external_chat_id AS '.$cast.'))=HEX(CAST(c.external_chat_id AS '.$cast.'))'
            .' AND HEX(CAST(c.external_chat_id AS '.$cast.'))=HEX(CAST(CASE WHEN p.platform='."'max'".' THEN '.$peer.' ELSE cc.external_user_id END AS '.$cast.'))'
            .' JOIN customers cu ON cu.id=c.customer_id';
    }
    private function safe():string
    {
        $length=$this->mysql?'OCTET_LENGTH(p.profile_json)':'LENGTH(CAST(p.profile_json AS BLOB))';
        return "CASE WHEN $length<=131072 AND JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
    }
    private function scalar(string $path):string
    {$e="JSON_EXTRACT(".$this->safe().",'".$path."')";return $this->mysql?'JSON_UNQUOTE('.$e.')':$e;}
    private function packet(array &$meta,int $at,?string &$why):?array
    {
        try{
            $raw=$meta['packet']??null;if(!is_string($raw)||strlen($raw)>20480){$why='packet_size';return null;}
            $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($v)||!array_is_list($v)||count($v)!==7){$why='packet_shape';return null;}
            [$prediction,$subject,$profileAt,$processing,$earlyVersion,$earlyTarget,$access]=$v;$s=self::scope($meta);
            if(!is_array($subject)||count($subject)!==4||array_diff_key($subject,$s)||array_diff_key($s,$subject)||array_filter($s,static fn($value,$key):bool=>($subject[$key]??null)!==$value,ARRAY_FILTER_USE_BOTH)||$access!=='allowed'||$earlyVersion!=='live_contextual_ordinal_v1'||$earlyTarget!=='purchase_readiness_30d_ordinal_v1'){
                $why='namespace_or_scope';return null;}
            if(!is_array($processing)||($processing['version']??null)!=='admitted_journal_profiles_v1_2'
                ||!self::sha($processing['source_sha256']??null)||!self::sha($meta['input_sha256']??null)
                ||($processing['journal_cursor']??null)!==(string)$meta['journal_cursor']){$why='producer_stamp';return null;}
            $result=ModeledConversionValueRegistry::predictionFields(['subject'=>$s,'access_status'=>'allowed','profile'=>['prediction_at'=>$profileAt],
                'semantic_prediction'=>$prediction]);
            if(($result['semantic_prediction']['availability']??null)!=='available'){$why='prediction_admission';return null;}
            $p=$result['semantic_prediction']['value'];$freeze=strtotime($p['frozen_at']);
            if(($p['journal_cursor']??null)!==(string)$meta['journal_cursor']
                ||($processing['source_fence_journal_id']??null)!==$p['source_fence_journal_id']
                ||$freeze===false||$freeze>$at||!is_string($profileAt)||strtotime($profileAt)!==(int)$meta['observed_at_unix']||(int)$meta['observed_at_unix']>$freeze){$why='clock_or_fence';return null;}
            $meta['_producer_sha']=$processing['source_sha256'];return$p;
        }catch(Throwable $ignored){$why='packet_invalid';return null;}
    }
    private static function scope(array $r):array
    {
        $s=['project_key'=>(string)($r['project_key']??''),'platform'=>(string)($r['platform']??''),'customer_key'=>(string)($r['customer_key']??''),'conversation_id'=>(string)($r['conversation_id']??'')];
        if($s['project_key']!=='anytour'||!in_array($s['platform'],['max','telegram'],true)||!self::positive($s['customer_key'])||!self::positive($s['conversation_id']))throw new RuntimeException();return$s;
    }
    private function filters(array $v,int $now,bool $aggregate=false):array
    {
        if($now<946684800||array_diff(array_keys($v),$aggregate?['date','period','platform']:['date','period','platform','cursor']))throw new InvalidArgumentException();
        $date=$v['date']??(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        $period=$v['period']??($aggregate?1:30);$platform=$v['platform']??'all';
        if(!is_string($date)||!is_int($period)||!in_array($period,$aggregate?[1,7,30,90]:[7,30,90],true)||!is_string($platform)||!in_array($platform,['all','max','telegram'],true))throw new InvalidArgumentException();
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Europe/Moscow'));
        if(!$d||$d->format('Y-m-d')!==$date||$d->getTimestamp()>$now||isset($v['cursor'])&&!is_string($v['cursor']))throw new InvalidArgumentException();
        return['date'=>$date,'period'=>$period,'platform'=>$platform];
    }
    private function window(array $f,int $at):array
    {
        $day=new DateTimeImmutable($f['date'].' 00:00:00',new DateTimeZone('Europe/Moscow'));$from=$day->modify('-'.($f['period']-1).' days')->getTimestamp();$until=min($at+1,$day->modify('+1 day')->getTimestamp());
        return['from_unix'=>$from,'until_unix'=>$until,'from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'until'=>gmdate('Y-m-d\TH:i:s\Z',$until),'timezone'=>'Europe/Moscow'];
    }
    private function encode(array $s,array $f):string
    {
        // Calendar membership changed; a pre-Moscow cursor must not continue a different cohort.
        $json=json_encode([1,$s['at'],$s['fence'],$s['after'],$f['date'],$f['period'],$f['platform']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $b=rtrim(strtr(base64_encode($json),'+/','-_'),'=');return'fpr1.'.$b.'.'.hash_hmac('sha256',"forecast-payment-owner-v1\nEurope/Moscow\n".$b,$this->cursorKey);
    }
    private function decode(string $v,array $f,int $now):?array
    {
        if(strlen($v)>2048||preg_match('/\Afpr1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$v,$m)!==1||!hash_equals(hash_hmac('sha256',"forecast-payment-owner-v1\nEurope/Moscow\n".$m[1],$this->cursorKey),$m[2]))return null;
        $raw=base64_decode(strtr($m[1],'-_','+/'),true);if(!is_string($raw)||rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$m[1])return null;
        try{$v=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(Throwable $e){return null;}
        if(!is_array($v)||!array_is_list($v)||count($v)!==7||$v[0]!==1||!is_int($v[1])||$v[1]>$now||$now-$v[1]>3600
            ||!self::decimal($v[2])||!self::decimal($v[3])||self::compare($v[3],$v[2])>0||[$v[4],$v[5],$v[6]]!==array_values($f))return null;
        return['at'=>$v[1],'fence'=>$v[2],'after'=>$v[3]];
    }
    private function query(string $sql,array $args=[]):array
    {
        $this->guard();$this->auth();if($this->mysql)$sql=preg_replace('/\ASELECT /','SELECT /*+ MAX_EXECUTION_TIME('.min(4500,max(1,(int)floor((self::MAX_SECONDS-(microtime(true)-$this->started))*1000))).') */ ',$sql,1);
        $q=$this->pdo->prepare($sql);try{$q->execute($args);$rows=[];while(($row=$q->fetch(PDO::FETCH_ASSOC))!==false){$this->bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));$this->guard();$rows[]=$row;}return$rows;}finally{$q->closeCursor();$this->auth();}
    }
    private function guard():void{if(microtime(true)-$this->started>self::MAX_SECONDS||$this->bytes>self::MAX_BYTES)throw new LengthException();}
    private function auth():void{($this->authorize)();}
    private function done(array $p):array{$this->auth();return$p;}
    private function unavailable(string $reason,array $f):array
    {
        if($this->aggregateMode)return['version'=>self::AGGREGATE_VERSION,'available'=>false,'reason'=>$reason,'filters'=>$f,
            'selection_exhausted'=>false,'cohort_membership_complete'=>false,'totals_available'=>false,'totals'=>null,'inspected'=>null,
            'payment_label'=>null,'probability_calibrated'=>false,'verified_payment'=>false,'evaluation_eligible'=>false];
        return['version'=>self::VERSION,'available'=>false,'reason'=>$reason,'filters'=>$f,'rows'=>[],'has_more'=>false,'next_cursor'=>null];
    }
    private static function emptyMetric():array
    {
        return['n'=>0,'probability_sum'=>0.0,'window_open'=>0,'horizon_elapsed'=>0,'later_evidence_observed'=>0,'unknown'=>0,'waiting'=>0];
    }
    private static function emptyAggregate():array
    {
        $stages=['substantive_dialogue','concrete_request','exact_offer_discussion','general_purchase_intent','agency_purchase_intent'];
        $edges=[0.0,0.05,0.10,0.20,0.35,0.50,1.0];$bins=[];
        for($i=0;$i<6;$i++)$bins[]=['lower'=>$edges[$i],'upper'=>$edges[$i+1],'upper_inclusive'=>$i===5]+self::emptyMetric();
        return['total'=>self::emptyMetric(),'evidence_cases'=>['self_reported_payment'=>0,'manager_confirmed_payment'=>0],
            'unknown_by_reason'=>[],'by_platform'=>array_fill_keys(['max','telegram'],self::emptyMetric()),
            'by_stage'=>array_fill_keys($stages,self::emptyMetric()),'probability_bins'=>$bins];
    }
    private static function addMetric(array &$metric,float $probability,string $status,bool $elapsed):void
    {
        $metric['n']++;$metric['probability_sum']+=$probability;$metric[$elapsed?'horizon_elapsed':'window_open']++;
        $metric[match($status){'observed'=>'later_evidence_observed','unknown'=>'unknown','waiting'=>'waiting'}]++;
    }
    private static function accumulate(array &$state,string $platform,array $prediction,array $followup,bool $elapsed):void
    {
        $p=(float)$prediction['estimated_probability'];$stage=$prediction['stage'];$status=$followup['status'];
        if(!is_finite($p)||$p<0||$p>1||!isset($state['by_platform'][$platform],$state['by_stage'][$stage])
            ||!in_array($status,['observed','unknown','waiting'],true))throw new RuntimeException('aggregate_packet');
        self::addMetric($state['total'],$p,$status,$elapsed);
        self::addMetric($state['by_platform'][$platform],$p,$status,$elapsed);
        self::addMetric($state['by_stage'][$stage],$p,$status,$elapsed);
        foreach($state['probability_bins']as&$bin)if($p>=$bin['lower']&&($p<$bin['upper']||$bin['upper_inclusive'])){
            self::addMetric($bin,$p,$status,$elapsed);break;
        }unset($bin);
        if($status==='unknown')$state['unknown_by_reason'][$followup['reason']]=($state['unknown_by_reason'][$followup['reason']]??0)+1;
        if($status==='observed')foreach(array_unique(array_column($followup['evidence'],'label'))as$label){
            if(!array_key_exists($label,$state['evidence_cases']))throw new RuntimeException('aggregate_evidence');
            $state['evidence_cases'][$label]++;
        }
    }
    private static function finishMetric(array $metric):array
    {
        $metric['predicted_probability_mean']=$metric['n']?round($metric['probability_sum']/$metric['n'],6):null;
        unset($metric['probability_sum']);return$metric;
    }
    private static function finishAggregate(array $state):array
    {
        $total=$state['total'];
        return['first_forecasts'=>$total['n'],'window_open'=>$total['window_open'],'horizon_elapsed'=>$total['horizon_elapsed'],
            'later_evidence_observed'=>$total['later_evidence_observed'],'followup_unknown'=>$total['unknown'],'followup_waiting'=>$total['waiting'],
            'evidence_cases'=>$state['evidence_cases'],'unknown_by_reason'=>$state['unknown_by_reason'],
            'by_platform'=>array_map(self::finishMetric(...),$state['by_platform']),
            'by_stage'=>array_map(self::finishMetric(...),$state['by_stage']),
            'probability_bins'=>array_map(self::finishMetric(...),$state['probability_bins'])];
    }
    private function aggregatePage(array $filters,int $at,array $window,array $state,int $scanned,array $omitted,array $invalidEarlier,bool $exhausted,?string $stopped):array
    {
        // These are counts of admitted saved forecasts, never all leads or verified payments.
        $complete=$exhausted&&!array_diff_key($omitted,['not_global_first'=>true])&&!$invalidEarlier;
        $stats=self::finishAggregate($state);
        return['version'=>self::AGGREGATE_VERSION,'available'=>true,'reason'=>null,'filters'=>$filters,'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$at),
            'window'=>array_intersect_key($window,array_fill_keys(['from','until','timezone'],true)),
            'unit'=>'canonical_scope_and_request_anchor','cohort_basis'=>'first_admitted_prediction_profile_observed_clock',
            'selection_exhausted'=>$exhausted,'cohort_membership_complete'=>$complete,'totals_available'=>$complete,
            'totals'=>$complete?$stats:null,'inspected'=>$stats,
            'coverage'=>['scanned_profiles'=>$scanned,'omitted_by_reason'=>$omitted,'invalid_earlier_profiles_by_reason'=>$invalidEarlier,
                'stopped_reason'=>$stopped,'max_scan_profiles'=>self::SCAN_LIMIT,'max_seconds'=>self::MAX_SECONDS,'max_bytes'=>self::MAX_BYTES,
                'source_history_complete'=>false,'trip_identity'=>'unverified'],
            'payment_label'=>null,'probability_calibrated'=>false,'verified_payment'=>false,'evaluation_eligible'=>false];
    }
    private static function name($v):string{return is_string($v)&&strlen($v)<=800&&preg_match('//u',$v)===1&&trim($v)!==''?trim($v):'Турист';}
    private static function sha($v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function positive($v):bool{return is_string($v)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function decimal($v):bool{return is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$v)===1;}
    private static function compare(string $a,string $b):int{return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
}
