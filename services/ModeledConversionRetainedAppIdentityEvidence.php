<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitContract.php';
require_once __DIR__.'/ModeledConversionValueVisitEvidence.php';
require_once __DIR__.'/ModeledConversionValueIdentityEvidence.php';

/** One saved-case local candidate only. No identity promotion, provider request or delivery permission. */
final class ModeledConversionRetainedAppIdentityEvidence
{
    public const VERSION='retained_app_identity_v1';
    public const RECEIPT_KEY='finite_retained_app_identity';
    public const MAX_BYTES=768;
    private const REASONS=['observed_no_candidate','candidate_observed','ambiguous','unsupported_source','scope_limit','row_limit',
        'packet_budget','invalid_source','identity_conflict','storage_unavailable','time_headroom','unsupported_execution_limit'];
    public static function intent(mixed $original,array $row,string $namespace,int $now):?array
    {
        $b=self::binding($original,$row,$namespace,$now);if($b===null||$now<$b['checked'])return null;
        $hash=hash('sha256',json_encode([self::VERSION,$namespace,$b['digest'],$b['ref'],'anytour','max','98615635',
            (string)$row['external_user_id'],(int)$row['event_unix'],32,8,64,8192,250,500,$now],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return ['v'=>self::VERSION,'n'=>$namespace,'d'=>$b['digest'],'e'=>$b['ref'],'h'=>$hash,'t'=>$now,'s'=>1,'o'=>null];
    }
    public static function binding(mixed $original,array $row,string $namespace,int $now):?array
    {
        if(($row['project_key']??null)!=='anytour'||($row['platform']??null)!=='max'||(string)($row['counter_id']??'')!=='98615635'
            ||!MaxTrackerLookupProtocol::id($row['external_user_id']??null))return null;
        $frame=ModeledConversionValueVisitEvidence::decode($original,$namespace,$now);
        $ref=ModeledConversionValueIdentityEvidence::reference($row,$namespace);if($frame===null||$ref===null)return null;
        $missing=[];foreach($frame['r']as$r)if($r[2]===2&&$r[5]===3)$missing[]=$r;
        if(count($missing)!==1||$missing[0][0]!==$ref||(string)$missing[0][1]!== (string)$row['id']
            ||$missing[0][15]!== (int)$row['event_unix']||!is_int($missing[0][4]))return null;
        return ['ref'=>$ref,'index'=>array_search($ref,array_column($frame['r'],0),true),'checked'=>$missing[0][4],'digest'=>hash('sha256',json_encode([$frame['v'],$frame['n'],$frame['b'],$frame['r']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    }
    /** The caller has already committed the distinct absent-key intent. Source SELECTs only; result contains no raw identity. */
    public static function observe(PDO $pdo,array $intent,array $row,int $now):array
    {
        $started=hrtime(true);
        $unavailable=static fn(int $code):array=>[$code,$now,null,null,null,0,null,null,null,null,null,null];
        try{
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return $unavailable(11);
            $version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if(stripos($version,'mariadb')!==false||preg_match('/\A8\.[0-9]+\.[0-9]+(?:[-.][A-Za-z0-9_.-]+)?\z/D',$version)!==1)return $unavailable(11);
        }catch(Throwable $ignored){return $unavailable(11);}
        $result=self::observeMysql($pdo,$intent,$row,$now,$started);
        // The inner finally has rolled back before any candidate may be published.
        return hrtime(true)-$started>=500000000?$unavailable(10):$result;
    }
    private static function observeMysql(PDO $pdo,array $intent,array $row,int $now,int $started):array
    {
        $result=static fn(int $code,?int $scopes=null,?int $rows=null,?int $late=null):array=>[$code,$now,$scopes,$rows,$late,0,null,null,null,null,null,null];
        $bytes=0;
        if($pdo->inTransaction())return $result(9);
        try{
            $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            if($mysql)$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
            $cast=static fn(string $v):string=>'CAST('.$v.' AS '.($mysql?'CHAR':'TEXT').')';
            $scopeSql="SELECT project_key,source_key,platform,counter_id,last_cursor FROM advertising_visit_cursors "
                .($mysql?'FORCE INDEX (PRIMARY)':'')." WHERE project_key='anytour' LIMIT 33";
            $scopePacket='JSON_ARRAY(project_key,source_key,platform,counter_id,last_cursor)';
            $scopes=self::packets($pdo,$scopeSql,$scopePacket,[],$mysql,$started,$bytes,'retained_app_scopes');
            if(count($scopes)>32)return $result(4);
            $sources=[];
            foreach($scopes as$s){
                if(!is_array($s)||count($s)!==5||!array_is_list($s)||$s[0]!=='anytour')return $result(7);
                $scope=AdvertisingVisitContract::scope(array_combine(['project_key','source_key','platform','counter_id'],array_slice($s,0,4)),true);
                if(!is_string($s[4])||preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$s[4])!==1)return $result(7);
                // Historical committed cursor only; no current-config or Metrika-visit attestation.
                if($scope['platform']==='max'&&$scope['counter_id']==='98615635'&&$s[4]!=='0')$sources[$scope['source_key']]=true;
            }
            if(!$sources)return $result(3,0);
            if(count($sources)>8)return $result(4);
            $keys=array_keys($sources);sort($keys,SORT_STRING);$marks=implode(',',array_fill(0,count($keys),'?'));
            $fields=['id','project_key','source_key','platform','counter_id','external_user_id','identity_status','source_cursor',
                'visit_id','client_id','yclid','visit_at_unix','observed_at_unix','received_at_unix','payload_sha256'];
            $point='SELECT '.implode(',',$fields).' FROM advertising_visit_observations '
                .($mysql?'FORCE INDEX (idx_advertising_identity)':'INDEXED BY idx_advertising_identity')
                ." WHERE project_key='anytour' AND source_key IN (".$marks.") AND platform='max' AND counter_id='98615635'"
                ." AND external_user_id=? AND identity_status='verified' LIMIT 65";
            $same=[];foreach(['project_key','source_key','platform','counter_id','visit_id']as$key)
                $same[]='x.'.$key.'=v.'.$key.' AND HEX(x.'.$key.')=HEX(v.'.$key.')';
            $conflict='EXISTS(SELECT 1 FROM advertising_visit_observations x '
                .($mysql?'FORCE INDEX (idx_advertising_visit)':'INDEXED BY idx_advertising_visit')
                .' WHERE '.implode(' AND ',$same)." AND x.identity_status='verified' AND HEX(x.identity_status)=HEX('verified')"
                .' AND HEX(x.external_user_id)<>HEX(v.external_user_id))';
            $parts=array_map(static fn(string $key):string=>$key==='id'?$cast('v.id'):'v.'.$key,$fields);$parts[]=$conflict;
            $points=self::packets($pdo,$point,'JSON_ARRAY('.implode(',',$parts).')',
                [...$keys,$row['external_user_id']],$mysql,$started,$bytes,'retained_app_identity');
            if(count($points)>64)return $result(5,count($keys));
            $pool=[];$late=0;$cids=[];$yclids=[];
            foreach($points as$p){
                if(!is_array($p)||!array_is_list($p)||count($p)!==16)return $result(7);
                $p=array_combine([...$fields,'conflict'],$p);
                if($p['project_key']!=='anytour'||$p['platform']!=='max'||$p['counter_id']!=='98615635'
                    ||$p['external_user_id']!==$row['external_user_id']||$p['identity_status']!=='verified'
                    ||!is_string($p['source_key'])||!isset($sources[$p['source_key']])
                    ||!MaxTrackerLookupProtocol::id($p['id'])||!MaxTrackerLookupProtocol::id($p['source_cursor'])
                    ||!is_string($p['visit_id'])||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$p['visit_id'])!==1
                    ||!is_string($p['payload_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$p['payload_sha256'])!==1)return $result(7);
                foreach(['client_id','yclid']as$key)if(!is_string($p[$key])||($p[$key]!==''&&!MaxTrackerLookupProtocol::id($p[$key],40)))return $result(7);
                foreach(['visit_at_unix','observed_at_unix','received_at_unix']as$key){
                    $p[$key]=self::clock($p[$key],$now+300);
                    if($p[$key]===null)return $result(7);
                }
                if($p['observed_at_unix']<$p['visit_at_unix']||!in_array($p['conflict'],[0,1],true))return $result(7);
                if($p['conflict']===1)return $result(8);
                if(max($p['visit_at_unix'],$p['observed_at_unix'],$p['received_at_unix'])>(int)$row['event_unix']){$late++;continue;}
                if($p['client_id']!=='')$cids[$p['client_id']]=true;if($p['yclid']!=='')$yclids[$p['yclid']]=true;
                if($p['client_id']!==''||$p['yclid']!=='')$pool[]=$p;
            }
            if(count($cids)>1||count($yclids)>1)return $result(2,count($keys),count($points),$late);
            if(!$pool)return $result(0,count($keys),count($points),$late);
            $kind=$yclids?2:1;$field=$kind===2?'yclid':'client_id';
            $witnesses=array_values(array_filter($pool,static fn(array $p):bool=>$p[$field]!==''));
            if($cids&&$yclids&&!array_filter($pool,static fn(array $p):bool=>$p['client_id']!==''&&$p['yclid']!==''))return $result(2,count($keys),count($points),$late);
            usort($witnesses,static fn(array $a,array $b):int=>AdvertisingVisitContract::compare($a['id'],$b['id']));$p=$witnesses[0];
            $hash=hash('sha256',json_encode([self::VERSION,$intent['n'],$intent['d'],$intent['e'],$kind,$p[$field],$p['id'],
                $p['payload_sha256'],$p['project_key'],$p['source_key'],$p['platform'],$p['counter_id'],$p['external_user_id'],
                $p['source_cursor'],$p['visit_id'],$p['visit_at_unix'],$p['observed_at_unix'],$p['received_at_unix']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            return [1,$now,count($keys),count($points),$late,$kind,$hash,$p['id'],$p['payload_sha256'],
                $p['visit_at_unix'],$p['observed_at_unix'],$p['received_at_unix']];
        }catch(PDOException $ignored){return $result(9);}
        catch(InvalidArgumentException|UnexpectedValueException|JsonException $error){
            $code=['packet_budget'=>6,'time_headroom'=>10][$error->getMessage()]??7;return $result($code);
        }finally{if($pdo->inTransaction())$pdo->rollBack();}
    }
    private static function packets(PDO $pdo,string $source,string $packet,array $args,bool $mysql,int $started,int &$bytes,string $alias):array
    {
        if(hrtime(true)-$started>=500000000)throw new UnexpectedValueException('time_headroom');
        if(8192-$bytes<2+24*($alias==='retained_app_scopes'?33:65))throw new UnexpectedValueException('packet_budget');
        $inner='SELECT '.$packet.' AS packet FROM ('.$source.') v';
        $size=$mysql?'OCTET_LENGTH(JSON_QUOTE(CAST(packet AS CHAR)))':'LENGTH(CAST(JSON_QUOTE(CAST(packet AS TEXT)) AS BLOB))';
        $sql='SELECT '.($mysql?'/*+ MAX_EXECUTION_TIME(250) */ ':'').'CASE WHEN SUM('.$size.'+24) OVER ()+2<=? THEN packet ELSE NULL END AS packet FROM ('.$inner.') '.$alias;
        $q=$pdo->prepare($sql);try{
            foreach([8192-$bytes,...$args]as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
            $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        }finally{$q->closeCursor();}
        if(hrtime(true)-$started>=500000000)throw new UnexpectedValueException('time_headroom');
        $bytes+=strlen(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if($bytes>8192)throw new UnexpectedValueException('packet_budget');
        if(count($rows)>=($alias==='retained_app_scopes'?33:65))return array_fill(0,count($rows),null);
        $out=[];foreach($rows as$r){
            if(!is_string($r['packet']??null))throw new UnexpectedValueException('packet_budget');
            $p=json_decode($r['packet'],true,16,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!is_array($p))throw new UnexpectedValueException('invalid_source');$out[]=$p;
        }
        if(hrtime(true)-$started>=500000000)throw new UnexpectedValueException('time_headroom');
        return $out;
    }
    public static function complete(array $intent,mixed $original,array $row,string $namespace,array $result,int $now):?array
    {
        if(self::decode($intent,$original,$row,$namespace,$now)===null||$intent['s']!==1)return null;
        $intent['s']=2;$intent['o']=$result;return self::decode($intent,$original,$row,$namespace,$now);
    }
    public static function projection(mixed $saved,mixed $original,array $row,string $namespace,int $now):array
    {
        $fail=static fn(string $reason):array=>['available'=>false,'reason'=>$reason,'financial_permission'=>false,'provider_requests_in_reader'=>0];
        if($saved===false)return $fail('retained_tombstone_no_automatic_retry');
        if($saved===null)return $fail('not_recorded');
        $v=self::decode($saved,$original,$row,$namespace,$now);if($v===null)return $fail('invalid_saved_journal');
        $binding=self::binding($original,$row,$namespace,$now);
        $out=['available'=>true,'case_index'=>$binding['index'],'status'=>'consumed_unknown','financial_permission'=>false];
        if($v['s']===1)return $out;$r=$v['o'];
        $out['status']=self::REASONS[$r[0]];
        if($r[0]===1){
            $out['status']='candidate';$out['kind']=$r[5]===2?'yclid':'client_id';
            $out['candidate_ref']=$r[6];$out['clocks']=array_slice($r,9,3);
        }return $out;
    }

    private static function decode(mixed $saved,mixed $original,array $row,string $namespace,int $now):?array
    {
        if(!is_array($saved)||count($saved)!==8||array_diff(array_keys($saved),['v','n','d','e','h','t','s','o'])||($saved['v']??null)!==self::VERSION||($saved['n']??null)!==$namespace
            ||!is_int($saved['t']??null)||$saved['t']>$now||!in_array($saved['s']??null,[1,2],true)||!array_key_exists('o',$saved))return null;
        $expected=self::intent($original,$row,$namespace,$saved['t']);
        if($expected===null||$saved['d']!==$expected['d']||$saved['e']!==$expected['e']||$saved['h']!==$expected['h']
            ||strlen(json_encode($saved,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)return null;
        if($saved['s']===1)return $saved['o']===null?$saved:null;
        $r=$saved['o'];if(!is_array($r)||!array_is_list($r)||count($r)!==12||!is_int($r[0])||!isset(self::REASONS[$r[0]])
            ||self::clock($r[1],$now)===null||$r[1]<$saved['t'])return null;
        foreach([2=>8,3=>64,4=>64]as$i=>$max)if($r[$i]!==null&&(!is_int($r[$i])||$r[$i]<0||$r[$i]>$max))return null;
        if($r[0]<=2&&($r[2]===null||$r[3]===null||$r[4]===null||$r[4]>$r[3]))return null;
        if($r[0]!==1)return $r[5]===0&&array_slice($r,6)===[null,null,null,null,null,null]?$saved:null;
        if(!in_array($r[5],[1,2],true)||!MaxTrackerLookupProtocol::id($r[7])||!is_string($r[6])||preg_match('/\A[a-f0-9]{64}\z/D',$r[6])!==1
            ||!is_string($r[8])||preg_match('/\A[a-f0-9]{64}\z/D',$r[8])!==1)return null;
        foreach(array_slice($r,9)as$clock)if(self::clock($clock,(int)$row['event_unix'])===null)return null;
        return $r[10]>=$r[9]?$saved:null;
    }
    private static function clock(mixed $v,int $now):?int
    {
        if(is_string($v)&&preg_match('/\A[1-9][0-9]{8,9}\z/D',$v)===1)$v=(int)$v;
        return is_int($v)&&$v>=946684800&&$v<=$now?$v:null;
    }
}
