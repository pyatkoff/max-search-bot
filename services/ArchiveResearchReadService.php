<?php
declare(strict_types=1);

require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';

/** Private historical research reader. No intake, model, send or read-marker owner. */
final class ArchiveResearchReadService
{
    public const MAX_OUTPUT_BYTES=65536;
    public const MAX_RESPONSE_BYTES=self::MAX_OUTPUT_BYTES;
    public const MAX_PAGE_SOURCE_BYTES=32768;
    public const MAX_RECORD_BYTES=1048576;
    public const CURSOR_TTL=1200;
    private const MAX_ROWS=500000;
    private const MAX_CHATS=30000;
    private const MAX_BYTES=536870912;
    private const MAX_SECONDS=8.0;
    private float $deadline=0;
    private bool $mysql;
    private array $permissionRows=[];
    private array $accountAliases=[];

    public function __construct(private PDO $pdo, private $clock=null, private $keyProvider=null)
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['mysql','sqlite'],true)) self::fail('unavailable');
        $this->mysql=$driver==='mysql';
    }

    public function inventory(int $admin,string $connection,array $args=[]): array
    {
        self::arguments($args,['cursor','limit'],50);
        return $this->read($admin,$connection,function(string $key) use($admin,$connection,$args):array {
            $state=isset($args['cursor'])?$this->decode($args['cursor'],'inventory',$key,$admin,$connection):null;
            $currentFence=$this->fence();$fence=$state['f']??$currentFence;
            if(self::decimalGreater($fence,$currentFence))self::fail('stale_cursor');
            $expiry=$state['e']??($this->now()+self::CURSOR_TTL);
            $summary=$state===null?$this->summary($fence):null;
            [$where,$values]=$this->scope($fence,true);
            $afterAccount=$state['a']??'0';$afterChat=$state['c']??'0';
            $where.=' AND (m.account_id>? OR (m.account_id=? AND m.chat_id>?))';
            array_push($values,$afterAccount,$afterAccount,$afterChat);
            $limit=$args['limit']??25;
            $rows=$this->q('SELECT m.account_id,m.chat_id,COUNT(*) AS records,'
                .'COALESCE(SUM('.$this->jsonBytes().'),0) AS json_bytes,'
                .'COALESCE(SUM(m.is_deleted<>0),0) AS deleted_records '
                .'FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key '
                .'WHERE '.$where.' GROUP BY m.account_id,m.chat_id ORDER BY m.account_id,m.chat_id LIMIT '.($limit+1),$values)->fetchAll(PDO::FETCH_ASSOC);
            $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$chats=[];
            foreach($rows as$row){
                $a=(string)$row['account_id'];$c=(string)$row['chat_id'];
                $chats[]=['chat_ref'=>$this->encode(['v'=>1,'k'=>'chat','a'=>$a,'c'=>$c,'f'=>$fence,'e'=>$expiry],$key,$admin,$connection),
                    'account_alias'=>$this->accountAliases[$a],'message_records'=>(int)$row['records'],
                    'source_json_bytes'=>(int)$row['json_bytes'],'deleted_records'=>(int)$row['deleted_records'],
                    'brand'=>'unknown','trip_count'=>null,'person_identity'=>'unverified'];
            }
            $next=null;
            if($more&&$rows){$last=$rows[count($rows)-1];$next=$this->encode(['v'=>1,'k'=>'inventory','a'=>(string)$last['account_id'],
                'c'=>(string)$last['chat_id'],'f'=>$fence,'e'=>$expiry],$key,$admin,$connection);}
            return $this->base($fence)+['totals'=>$summary['totals']??null,'accounts'=>$summary['accounts']??null,
                'summary_not_recomputed'=>$state!==null,
                'chats'=>$chats,'has_more'=>$more,'next_cursor'=>$next];
        });
    }

    public function history(int $admin,string $connection,array $args): array
    {
        self::arguments($args,['chat_ref','cursor','limit'],100);
        if(!isset($args['chat_ref'])||!is_string($args['chat_ref'])||$args['chat_ref']==='')self::fail('invalid_arguments');
        return $this->read($admin,$connection,function(string$key)use($admin,$connection,$args):array {
            $chat=$this->decode($args['chat_ref'],'chat',$key,$admin,$connection);
            // Current account denial takes precedence over an older cursor's now-smaller fence.
            if(!isset($this->accountAliases[$chat['a']]))self::fail('forbidden');
            if(self::decimalGreater($chat['f'],$this->fence()))self::fail('stale_cursor');
            $cursor=isset($args['cursor'])?$this->decode($args['cursor'],'history',$key,$admin,$connection):null;
            $ref=hash('sha256',$args['chat_ref']);
            if($cursor&&($cursor['a']!==$chat['a']||$cursor['c']!==$chat['c']||$cursor['f']!==$chat['f']
                ||$cursor['r']!==$ref||$cursor['e']!==$chat['e']))self::fail('stale_cursor');
            [$where,$values]=$this->scope($chat['f'],false);
            $where.=' AND m.account_id=? AND m.chat_id=?';array_push($values,$chat['a'],$chat['c']);
            $stat=$this->q('SELECT COUNT(*) AS records,COALESCE(SUM('.$this->jsonBytes().'),0) AS bytes '
                .'FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '.$where,$values)->fetch(PDO::FETCH_ASSOC);
            if((int)$stat['records']===0)self::fail('chat_unavailable');
            if((int)$stat['records']>self::MAX_ROWS||(int)$stat['bytes']>self::MAX_BYTES)self::fail('source_limit');
            [$historicalWhere,$historicalValues]=$this->scope($chat['f'],true);
            $historical=$this->q('SELECT 1 FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '
                .$historicalWhere.' AND m.account_id=? AND m.chat_id=? LIMIT 1',[...$historicalValues,$chat['a'],$chat['c']])->fetchColumn();
            if($historical===false)self::fail('chat_unavailable');
            $after=$cursor['n']??'0';$limit=$args['limit']??50;
            $jsonValid=$this->mysql?'JSON_VALID(m.record_json)':'json_valid(m.record_json)';
            $botType=$this->mysql?"JSON_TYPE(JSON_EXTRACT(m.record_json,'$.bot_key'))":"json_type(m.record_json,'$.bot_key')";
            $botValue=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT(m.record_json,'$.bot_key'))":"CAST(json_extract(m.record_json,'$.bot_key') AS TEXT)";
            $safe='CASE WHEN '.$this->jsonBytes().'<='.self::MAX_RECORD_BYTES.' THEN CASE WHEN '.$jsonValid.'=1 THEN ';
            $metadata=$this->q('SELECT m.id,m.account_id,m.chat_id,m.message_id,m.source_kind,m.version_at,m.is_deleted,'
                .$this->jsonBytes().' AS bytes,'.$safe.'1 ELSE 0 END ELSE 0 END AS valid_json,'
                .$safe.$botType." ELSE NULL END ELSE NULL END AS bot_type,"
                .$safe.'SUBSTR('.$botValue.',1,97) ELSE NULL END ELSE NULL END AS bot_value '
                .'FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE '.$where
                .' AND m.message_id>? ORDER BY m.message_id LIMIT '.($limit+1),[...$values,$after])->fetchAll(PDO::FETCH_ASSOC);
            $hasMore=count($metadata)>$limit;$metadata=array_slice($metadata,0,$limit);
            $selected=[];$fetch=[];$bytes=0;
            foreach($metadata as$row){
                $status=$this->metadataStatus($row);
                $size=(int)$row['bytes'];
                if($status==='available'&&$size>self::MAX_PAGE_SOURCE_BYTES)$status='record_exceeds_page_budget';
                if($status==='available'&&$bytes+$size>self::MAX_PAGE_SOURCE_BYTES){$hasMore=true;break;}
                $row['read_status']=$status;$selected[]=$row;
                if($status==='available'){$fetch[]=(string)$row['id'];$bytes+=$size;}
            }
            $raw=[];
            if($fetch){
                $rows=$this->q('SELECT m.id,m.record_json FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key '
                    .'WHERE '.$where.' AND m.id IN ('.implode(',',array_fill(0,count($fetch),'?')).')',[...$values,...$fetch])->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as$row)$raw[(string)$row['id']]=$row['record_json'];
                foreach($selected as$row)if($row['read_status']==='available'&&(!isset($raw[(string)$row['id']])||strlen($raw[(string)$row['id']])!==(int)$row['bytes']))self::fail('source_changed');
                if(count($raw)!==count($fetch))self::fail('source_changed');
            }
            $messages=[];$omitted=0;$last=$after;
            foreach($selected as$row){
                $item=$this->project($row,$raw[(string)$row['id']]??null);
                $messages[]=$item;$last=(string)$row['message_id'];
                if($item['read_status']!=='available')$omitted++;
            }
            $make=function(array$msgs,string$last,int$skipped,bool$more)use($chat,$ref,$args,$key,$admin,$connection,$stat,$bytes):array{
                $next=$more?$this->encode(['v'=>1,'k'=>'history','a'=>$chat['a'],'c'=>$chat['c'],'f'=>$chat['f'],
                    'n'=>$last,'r'=>$ref,'e'=>$chat['e']],$key,$admin,$connection):null;
                return $this->base($chat['f'])+['chat_ref'=>$args['chat_ref'],'account_alias'=>$this->accountAliases[$chat['a']],
                    'stored_records_within_fence'=>(int)$stat['records'],'messages'=>$msgs,
                    'page_source_json_bytes'=>$bytes,'page_omitted_records'=>$skipped,'has_more'=>$more,'next_cursor'=>$next,
                    'history_complete'=>false,'page_complete'=>!$more&&$skipped===0,
                    'history_limits'=>['external_messenger_completeness'=>'unknown','new_rows_after_fence'=>'excluded',
                        'current_versions_may_change_between_pages'=>true,'missing_media_contents'=>true,
                        'message_availability_at_historical_prediction'=>'unknown']];
            };
            $result=$make($messages,$last,$omitted,$hasMore);
            $firstSelected=$selected[0]??null;
            while(strlen(self::json($result))>self::MAX_RESPONSE_BYTES&&$messages){
                array_pop($messages);$selected=array_slice($selected,0,count($messages));$hasMore=true;
                $last=$selected?(string)$selected[count($selected)-1]['message_id']:$after;
                $omitted=count(array_filter($messages,static fn($m)=>$m['read_status']!=='available'));
                $result=$make($messages,$last,$omitted,true);
            }
            if(!$messages&&$firstSelected){
                $firstSelected['read_status']='response_record_limit';
                $item=$this->project($firstSelected,null);$last=(string)$firstSelected['message_id'];
                $result=$make([$item],$last,1,count($metadata)>1||$hasMore);
            }
            return $result;
        });
    }

    private function read(int$admin,string$connection,callable$operation):array
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        if($admin<1||$connection===''||strlen($connection)>128||!is_callable($this->keyProvider))self::fail('forbidden');
        $this->deadline=microtime(true)+self::MAX_SECONDS;$owned=false;$queryOnly=null;
        try{
            $key=$this->key($admin,$connection);
            if($this->mysql){
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            }else{
                $queryOnly=(int)$this->pdo->query('PRAGMA query_only')->fetchColumn();
                $this->pdo->exec('PRAGMA query_only=ON');$this->pdo->beginTransaction();
            }
            $owned=true;$this->permissionRows=$this->permissions();$original=$this->permissionRows;
            if(!$original)self::fail('forbidden');
            $this->accountAliases=[];foreach($original as$row){$id=(string)$row['account_id'];if(!isset($this->accountAliases[$id]))$this->accountAliases[$id]='A'.(count($this->accountAliases)+1);}
            $result=$operation($key);
            $this->pdo->rollBack();$owned=false;
            if($queryOnly!==null){$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));$queryOnly=null;}
            // Outside the old consistent snapshot: revocations cannot use its old permission rows.
            if(!hash_equals($key,$this->key($admin,$connection))||$original!==$this->permissions())self::fail('permission_changed');
            $this->budget();
            if(strlen(self::json($result))>self::MAX_RESPONSE_BYTES)self::fail('response_limit');
            return$result;
        }catch(ArchiveResearchReadException$e){throw$e;}
        catch(Throwable$e){self::fail('unavailable');}
        finally{
            if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();
            if($queryOnly!==null)$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));
            $this->permissionRows=[];$this->accountAliases=[];
        }
    }

    private function permissions():array
    {
        $rows=$this->q('SELECT a.account_id,a.bot_key,a.connection_id FROM telegram_archive_accounts a '
            .'JOIN telegram_dialogue_connections c ON c.project_key=a.project_key AND c.bot_key=a.bot_key AND c.connection_id=a.connection_id '
            .'JOIN projects p ON p.project_key=a.project_key WHERE a.project_key=? AND p.is_active=1 AND a.approved=1 '
            .'AND a.provider_enabled=1 AND c.is_enabled=1 AND c.account_id=a.account_id ORDER BY a.account_id,a.bot_key,a.connection_id LIMIT 101',['anytour'])->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>100)self::fail('source_limit');
        foreach($rows as$row)if(!self::decimal((string)$row['account_id'],false)||!is_string($row['bot_key'])||$row['bot_key']===''
            ||strlen($row['bot_key'])>96||!is_string($row['connection_id'])||$row['connection_id']==='')self::fail('unavailable');
        return$rows;
    }

    private function scope(string$fence,bool$historical):array
    {
        $ids=array_keys($this->accountAliases);
        if(!$ids)self::fail('forbidden');
        return["m.project_key=? AND p.is_active=1 AND m.account_id IN (".implode(',',array_fill(0,count($ids),'?')).')'
            .' AND m.id<=? AND m.chat_id<>777000 AND NOT '.InternalOwnerDialogueScope::archiveSql('m')
            .($historical?" AND m.source_kind='desktop_import'":" AND m.source_kind IN ('desktop_import','business_message')"),
            ['anytour',...$ids,$fence]];
    }

    private function fence():string
    {
        [$where,$values]=$this->scope('18446744073709551615',false);
        // The archive's BIGINT fence is opaque private metadata, never a provider time.
        return(string)$this->q('SELECT COALESCE(MAX(m.id),0) FROM telegram_dialogue_messages m '
            .'JOIN projects p ON p.project_key=m.project_key WHERE '.$where,$values)->fetchColumn();
    }

    private function summary(string$fence):array
    {
        [$where,$values]=$this->scope($fence,true);
        $rows=$this->q('SELECT m.account_id,COUNT(*) AS records,COUNT(DISTINCT m.chat_id) AS chats,'
            .'COALESCE(SUM('.$this->jsonBytes().'),0) AS bytes FROM telegram_dialogue_messages m '
            .'JOIN projects p ON p.project_key=m.project_key WHERE '.$where.' GROUP BY m.account_id ORDER BY m.account_id',$values)->fetchAll(PDO::FETCH_ASSOC);
        $totals=['message_records'=>0,'account_chat_pairs'=>0,'source_json_bytes'=>0];$accounts=[];
        foreach($rows as$row){
            $totals['message_records']+=(int)$row['records'];$totals['account_chat_pairs']+=(int)$row['chats'];$totals['source_json_bytes']+=(int)$row['bytes'];
            $accounts[]=['account_alias'=>$this->accountAliases[(string)$row['account_id']],'message_records'=>(int)$row['records'],
                'account_chat_pairs'=>(int)$row['chats'],'source_json_bytes'=>(int)$row['bytes'],'brand'=>'unknown'];
        }
        if($totals['message_records']>self::MAX_ROWS||$totals['account_chat_pairs']>self::MAX_CHATS||$totals['source_json_bytes']>self::MAX_BYTES)self::fail('source_limit');
        return['totals'=>$totals,'accounts'=>$accounts];
    }

    private function metadataStatus(array$row):string
    {
        if((int)$row['is_deleted']!==0)return'deleted';
        if((int)$row['bytes']>self::MAX_RECORD_BYTES)return'oversized_record';
        if((int)$row['valid_json']!==1)return'malformed_record';
        $type=strtolower((string)($row['bot_type']??''));if(!$this->mysql&&$type==='text')$type='string';$value=$row['bot_value']??null;
        if($type===''||$type==='null')return'available';
        if($type!=='string'||!is_string($value))return'permission_unverified';
        if($value==='')return'available';
        foreach($this->permissionRows as$p)if((string)$p['account_id']===(string)$row['account_id']&&$p['bot_key']===$value)return'available';
        return'permission_unverified';
    }

    private function project(array$row,?string$raw):array
    {
        $item=['source_ref'=>'archive_message:'.(string)$row['id'],'native_message_id'=>(string)$row['message_id'],
            'source_kind'=>$row['source_kind'],'read_status'=>$row['read_status'],'text'=>null,'role'=>'unknown',
            'direction'=>'unknown','role_verified'=>false,'created_at'=>null,'version_at'=>self::time($row['version_at']),
            'deleted'=>(int)$row['is_deleted']!==0,'forwarded'=>null,'media'=>null,'media_contents_read'=>false,
            'historical_first_available_at'=>null];
        if($row['read_status']!=='available')return$item;
        try{$record=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable$e){$record=null;}
        if(!is_array($record)){$item['read_status']='malformed_record';return$item;}
        foreach(['account_id','chat_id','message_id']as$field)if(!isset($record[$field])||(string)$record[$field]!== (string)$row[$field]){$item['read_status']='malformed_record';return$item;}
        if(($record['source_kind']??null)!==$row['source_kind']||!array_key_exists('is_deleted',$record)
            ||!is_bool($record['is_deleted'])||$record['is_deleted']!==false
            ||(string)($record['version_at']??'')!==(string)($row['version_at']??'')){$item['read_status']='malformed_record';return$item;}
        if((string)($record['sender_id']??'')==='777000'){$item['read_status']='service_message';return$item;}
        if(!is_string($record['text']??null)||preg_match('//u',$record['text'])!==1){$item['read_status']='malformed_record';return$item;}
        $sender=(string)($record['sender_id']??'');$role=$record['sender_type']??'unknown';$direction=$record['direction']??'unknown';
        if(($role==='manager'&&$direction==='outbound'&&$sender===(string)$row['account_id'])
            ||($role==='customer'&&$direction==='inbound'&&$sender===(string)$row['chat_id'])){
            $item['role']=$role;$item['direction']=$direction;$item['role_verified']=true;
        }
        $item['created_at']=self::time($record['created_at']??null);
        $item['forwarded']=is_bool($record['is_forwarded']??null)?$record['is_forwarded']:null;
        $selection=false;$links=false;
        $item['text']=ManagerAiTextRedactor::redact($record['text'],$selection,$links);
        $item['text']=preg_replace('/(?<![\pL\pN_])@[A-Za-z][A-Za-z0-9_]{3,31}\b/u','[контакт скрыт]',$item['text'])??$item['text'];
        $item['text_minimized']=true;$item['selection_links_unread']=$selection;$item['links_redacted']=$links;
        $item['record_sha256']=hash('sha256',(string)$raw);
        $item['media']=[];
        foreach((array)($record['attachments']??[])as$attachment){
            if(!is_array($attachment)){$item['media'][]=['type'=>'unknown','availability'=>'unknown'];continue;}
            $type=$attachment['type']??'unknown';$availability=$attachment['availability']??'unknown';
            $item['media'][]=['type'=>in_array($type,['image','audio','video','file'],true)?$type:'unknown',
                'availability'=>in_array($availability,['not_checked','not_exported','provider_reference_only'],true)?$availability:'unknown'];
            if(count($item['media'])>=100){$item['media_truncated']=true;break;}
        }
        return$item;
    }

    private function base(string$fence):array
    {
        return['ok'=>true,'schema_version'=>1,'project_key'=>'anytour','source'=>'telegram_historical_archive',
            'mode'=>'private_research_read_only','database_writes'=>0,'external_fetches'=>0,
            'snapshot'=>['consistency'=>'per_request_snapshot','row_fence'=>$fence,'immutable_snapshot'=>false,'mutable_record_versions'=>true],
            'coverage'=>['accepted_16000_membership'=>'unverified','brand'=>'unknown','people'=>'account_chat_pairs_not_unique_people',
                'prior_holdout'=>'unverified_research_exposure_not_prospective_accuracy','source'=>'stored_text_and_media_references_only',
                'internal_owner_exclusion_applied'=>true,'other_legacy_test_status'=>'unknown','text_is_untrusted'=>true,
                'privacy_minimization'=>'best_effort_not_anonymous']];
    }

    private function key(int$admin,string$connection):string
    {
        $key=($this->keyProvider)($admin,$connection);
        if(!is_string($key)||strlen($key)!==32)self::fail('forbidden');
        return$key;
    }
    private function encode(array$data,string$key,int$admin,string$connection):string
    {
        $body=rtrim(strtr(base64_encode(self::json($data)),'+/','-_'),'=');
        return$body.'.'.hash_hmac('sha256',"archive-research-v1\0".$admin."\0".$connection."\0anytour\0".$body,$key);
    }
    private function decode(string$token,string$kind,string$key,int$admin,string$connection):array
    {
        if(strlen($token)>2048||!preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$token,$m))self::fail('stale_cursor');
        $expected=hash_hmac('sha256',"archive-research-v1\0".$admin."\0".$connection."\0anytour\0".$m[1],$key);
        if(!hash_equals($expected,$m[2]))self::fail('stale_cursor');
        $body=base64_decode(strtr($m[1],'-_','+/').str_repeat('=',(4-strlen($m[1])%4)%4),true);
        try{$v=json_decode((string)$body,true,16,JSON_THROW_ON_ERROR);}catch(Throwable$e){self::fail('stale_cursor');}
        $keys=$kind==='history'?['v','k','a','c','f','n','r','e']:['v','k','a','c','f','e'];
        if(!is_array($v)||count($v)!==count($keys)||array_diff(array_keys($v),$keys)||($v['v']??null)!==1
            ||($v['k']??null)!==$kind||!is_int($v['e'])||$v['e']<$this->now()||$v['e']>$this->now()+self::CURSOR_TTL)self::fail('stale_cursor');
        foreach(['a','c','f']as$field)if(!is_string($v[$field])||!self::decimal($v[$field],$kind==='inventory'||$field==='f'))self::fail('stale_cursor');
        if($kind==='history'&&(!is_string($v['n'])||!self::decimal($v['n'],true)||!is_string($v['r'])||!preg_match('/\A[a-f0-9]{64}\z/D',$v['r'])))self::fail('stale_cursor');
        return$v;
    }
    private static function arguments(array$args,array$allowed,int$max):void
    {
        if(array_diff(array_keys($args),$allowed))self::fail('invalid_arguments');
        foreach(['cursor','chat_ref']as$field)if(isset($args[$field])&&(!is_string($args[$field])||$args[$field]===''||strlen($args[$field])>2048))self::fail('invalid_arguments');
        foreach(['cursor','chat_ref']as$field)if(array_key_exists($field,$args)&&$args[$field]===null)self::fail('invalid_arguments');
        if(isset($args['limit'])&&(!is_int($args['limit'])||$args['limit']<1||$args['limit']>$max))self::fail('invalid_arguments');
        if(array_key_exists('limit',$args)&&$args['limit']===null)self::fail('invalid_arguments');
    }
    private function q(string$sql,array$args=[]):PDOStatement
    {
        $this->budget();
        if(!str_starts_with($sql,'SELECT '))self::fail('unavailable');
        if($this->mysql)$sql='SELECT /*+ MAX_EXECUTION_TIME('.max(1,min(5000,(int)(($this->deadline-microtime(true))*1000))).') */ '.substr($sql,7);
        $q=$this->pdo->prepare($sql);$q->execute($args);$this->budget();return$q;
    }
    private function jsonBytes():string{return $this->mysql?'LENGTH(m.record_json)':'LENGTH(CAST(m.record_json AS BLOB))';}
    private function budget():void{if(microtime(true)>$this->deadline)self::fail('time_limit');}
    private function now():int{return$this->clock?(int)($this->clock)():time();}
    private static function decimal(string$v,bool$zero):bool{return preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function decimalGreater(string$a,string$b):bool{return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function time(mixed$v):?int{return(is_int($v)||(is_string($v)&&preg_match('/\A[1-9][0-9]{0,11}\z/D',$v)))&&(int)$v>0&&(int)$v<=253402300799?(int)$v:null;}
    private static function json(array$v):string{return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private static function fail(string$reason):never{throw new ArchiveResearchReadException($reason);}
}
final class ArchiveResearchReadException extends RuntimeException
{
    public function __construct(public readonly string$reason){parent::__construct('archive_research_'.$reason);}
}
