<?php
declare(strict_types=1);

/** Bounded storage and projection only. SalesPipelineService owns every CRM mutation. */
final class SalesOutcomeTripBinding
{
    private const TABLE='lead_outcome_trip_bindings';
    private const PROFILE_LIMIT=64;
    private const CHOICE_LIMIT=20;
    public const FLAGS=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;

    public static function unavailable(string $reason='storage_unavailable'):array
    {return ['available'=>false,'reason'=>$reason,'revision'=>null,'binding'=>null,'choices'=>[],'choices_truncated'=>false];}

    /** Only an exact missing-table error permits the legacy pre-migration path. */
    public static function available(PDO $pdo):bool
    {
        try{$pdo->query('SELECT id FROM '.self::TABLE.' LIMIT 0')->closeCursor();return true;}
        catch(PDOException $e){
            $message=$e->getMessage();$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if($driver==='sqlite'&&preg_match('/no such table: (?:main\.)?lead_outcome_trip_bindings(?:\s|$)/',$message))return false;
            if($driver==='mysql'&&(int)($e->errorInfo[1]??0)===1146&&str_contains($message,'lead_outcome_trip_bindings'))return false;
            throw $e;
        }
    }

    public static function outcome(array $c):array
    {
        return ['outcome'=>(string)($c['lead_outcome']?:'open'),'close_reason'=>$c['lead_close_reason']===null||$c['lead_close_reason']===''?null:(string)$c['lead_close_reason'],
            'note'=>$c['lead_outcome_note']===null||$c['lead_outcome_note']===''?null:(string)$c['lead_outcome_note'],'sale_amount'=>$c['lead_sale_amount']===null?null:(string)$c['lead_sale_amount'],
            'sale_date'=>$c['lead_sale_date']?:null,'updated_at'=>$c['lead_outcome_updated_at']?:null,
            'manager_id'=>empty($c['lead_outcome_manager_id'])?null:(int)$c['lead_outcome_manager_id']];
    }

    /** Ownership/status belong to the CAS, while the durable link pins customer identity. */
    private static function identity(array $c):array
    {
        return ['conversation_id'=>(int)$c['id'],'project_key'=>(string)($c['project_key']??''),
            'customer_id'=>(int)($c['customer_id']??0),'customer_channel_id'=>(int)($c['customer_channel_id']??0),
            'channel'=>(string)($c['channel']??''),'external_chat_id'=>(string)($c['external_chat_id']??'')];
    }
    private static function guardScope(array $c):array
    {
        return self::identity($c)+['source_id'=>(int)($c['source_id']??0),'manager_id'=>(int)($c['manager_id']??0),
            'status'=>(string)($c['status']??'')];
    }
    public static function hash(array $value):string{return hash('sha256',json_encode($value,self::FLAGS));}
    public static function hex(mixed $value,int $length):bool
    {return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;}

    public static function projection(PDO $pdo,array $c):array
    {
        if(($c['project_key']??null)!=='anytour')return self::unavailable('unsupported_project');
        if(!self::available($pdo))return self::unavailable();
        $head=self::head($pdo,(int)$c['id']);$binding=null;$pinned=null;
        if($head&&$head['outcome_sha256']===self::hash(self::outcome($c))
            &&json_decode($head['scope_json'],true,16,JSON_THROW_ON_ERROR)===self::identity($c)&&$head['trip_id']!==null){
            $pinned=self::profile($pdo,(int)$c['id'],$head['trip_id'],(int)$head['profile_version']);
            if(!$pinned||!hash_equals($head['profile_sha256'],$pinned['profile_sha256']))throw new RuntimeException('outcome_profile_changed');
            $binding=self::choice($pinned);
        }
        $catalog=self::catalog($pdo,(int)$c['id'],$pinned);
        $revision=self::hash([self::outcome($c),(string)($c['lead_stage_key']??''),self::guardScope($c),
            $head?(string)$head['id']:'0',$catalog['latest_version']]);
        return ['available'=>true,'revision'=>$revision,'binding'=>$binding,'choices'=>$catalog['choices'],
            'choices_truncated'=>$catalog['truncated']];
    }

    private static function catalog(PDO $pdo,int $conversation,?array $pinned):array
    {
        $q=$pdo->prepare(self::profileSelect($pdo).' WHERE conversation_id=? ORDER BY version DESC LIMIT '.(self::PROFILE_LIMIT+1));
        $q->execute([$conversation]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $latest=$rows?(int)$rows[0]['version']:0;$truncated=count($rows)>self::PROFILE_LIMIT;$choices=[];$trips=[];
        foreach(array_slice($rows,0,self::PROFILE_LIMIT)as$row){
            $row=self::validProfile($row);
            if(isset($trips[$row['trip_id']]))continue;$trips[$row['trip_id']]=true;
            if(count($choices)>=self::CHOICE_LIMIT){$truncated=true;continue;}
            $choices[]=self::choice($row);
        }
        if($pinned){
            $found=false;foreach($choices as$choice)if($choice['trip_id']===$pinned['trip_id']&&$choice['version']===(int)$pinned['version'])$found=true;
            if(!$found)$choices[]=self::choice($pinned);
        }
        return ['latest_version'=>$latest,'choices'=>$choices,'truncated'=>$truncated];
    }

    /** Exact immutable manager-profile version, never the latest version guessed by the server. */
    public static function profile(PDO $pdo,int $conversation,string $trip,int $version):?array
    {
        if(!self::hex($trip,32)||$version<1)return null;
        $q=$pdo->prepare(self::profileSelect($pdo).' WHERE conversation_id=? AND version=? AND trip_id=? LIMIT 1');
        $q->execute([$conversation,$version,$trip]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        return $row?self::validProfile($row):null;
    }
    private static function profileSelect(PDO $pdo):string
    {
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        return 'SELECT conversation_id,version,trip_id,boundary_message_id,context_sha256,profile_sha256,'
            .'SUBSTR(CAST(profile_json AS '.$binary.'),1,32769) AS profile_json FROM manager_request_profiles';
    }
    private static function validProfile(array $row):array
    {
        require_once __DIR__.'/ManagerRequestProfile.php';
        if((int)$row['version']<1||!self::hex($row['trip_id'],32)||!self::hex($row['context_sha256'],64)
            ||!self::hex($row['profile_sha256'],64)||strlen($row['profile_json'])>ManagerRequestProfile::MAX_BYTES
            ||!hash_equals($row['profile_sha256'],hash('sha256',$row['profile_json']))
            ||!ManagerRequestProfile::validateStored(json_decode($row['profile_json'],true,64,JSON_THROW_ON_ERROR)))
            throw new RuntimeException('outcome_profile_invalid');
        return $row;
    }
    private static function choice(array $row):array
    {
        $profile=json_decode($row['profile_json'],true,64,JSON_THROW_ON_ERROR);$parts=[];
        foreach(['destination','dates','nights','budget']as$key){$value=$profile['fields'][$key]['value']??null;
            if(is_string($value)&&trim($value)!=='')$parts[]=trim($value);}
        return ['trip_id'=>$row['trip_id'],'version'=>(int)$row['version'],'label'=>$profile['label'],
            'summary'=>mb_substr(implode(' · ',$parts),0,240)];
    }

    private static function select(PDO $pdo):string
    {
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        return 'SELECT id,conversation_id,manager_id,operation_id,request_sha256,trip_id,profile_version,profile_sha256,'
            .'SUBSTR(CAST(outcome_json AS '.$binary.'),1,8193) AS outcome_json,outcome_sha256,'
            .'SUBSTR(CAST(scope_json AS '.$binary.'),1,2049) AS scope_json FROM '.self::TABLE;
    }
    public static function head(PDO $pdo,int $conversation):?array
    {
        $q=$pdo->prepare(self::select($pdo).' WHERE conversation_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([$conversation]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?self::validRecord($r):null;
    }
    public static function operation(PDO $pdo,int $actor,string $operation):?array
    {
        $q=$pdo->prepare(self::select($pdo).' WHERE manager_id=? AND operation_id=? LIMIT 1');
        $q->execute([$actor,$operation]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?self::validRecord($r):null;
    }
    private static function validRecord(array $r):array
    {
        if(!self::hex($r['operation_id'],32)||!self::hex($r['request_sha256'],64)||!self::hex($r['outcome_sha256'],64)
            ||strlen($r['outcome_json'])>8192||strlen($r['scope_json'])>2048
            ||!hash_equals($r['outcome_sha256'],hash('sha256',$r['outcome_json']))
            ||($r['trip_id']===null?($r['profile_version']!==null||$r['profile_sha256']!==null):
                (!self::hex($r['trip_id'],32)||(int)$r['profile_version']<1||!self::hex($r['profile_sha256'],64))))
            throw new RuntimeException('outcome_binding_invalid');
        if(!is_array(json_decode($r['outcome_json'],true,16,JSON_THROW_ON_ERROR))
            ||!is_array(json_decode($r['scope_json'],true,16,JSON_THROW_ON_ERROR)))throw new RuntimeException('outcome_binding_invalid');
        return $r;
    }
    public static function operationView(array $record):array
    {return ['operation_id'=>$record['operation_id'],'trip_id'=>$record['trip_id'],
        'profile_version'=>$record['profile_version']===null?null:(int)$record['profile_version']];}

    public static function append(PDO $pdo,array $conversation,int $actor,string $operation,string $request,?array $profile):void
    {
        if(!$pdo->inTransaction())throw new LogicException('Outcome owner transaction required');
        $outcome=json_encode(self::outcome($conversation),self::FLAGS);$scope=json_encode(self::identity($conversation),self::FLAGS);
        if(strlen($outcome)>8192||strlen($scope)>2048)throw new RuntimeException('outcome_binding_limit');
        $q=$pdo->prepare('INSERT INTO '.self::TABLE.' (conversation_id,manager_id,operation_id,request_sha256,trip_id,profile_version,profile_sha256,outcome_json,outcome_sha256,scope_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([(int)$conversation['id'],$actor,$operation,$request,$profile['trip_id']??null,$profile===null?null:(int)$profile['version'],
            $profile['profile_sha256']??null,$outcome,hash('sha256',$outcome),$scope,gmdate('Y-m-d H:i:s')]);
        if($q->rowCount()!==1)throw new RuntimeException('outcome_binding_write_unconfirmed');
    }
}
