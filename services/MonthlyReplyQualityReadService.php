<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/DialogueReviewReadService.php';
require_once __DIR__.'/ArchiveResearchReadService.php';
require_once __DIR__.'/VirtualReplySourceReader.php';
require_once __DIR__.'/VirtualReplySampling.php';

/** Private retained-history projection. Never a worker, collector or state owner. */
final class MonthlyReplyQualityReadService
{
    private const TTL=1200;
    private const MAX_INPUT=1048576;
    private const FRAGMENT_BYTES=16384;
    private float $deadline=0;
    private bool $mysql;

    public function __construct(private PDO $pdo,private $clock,private $keyProvider,private $archiveKeyProvider=null)
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['mysql','sqlite'],true))self::fail('unavailable');
        $this->mysql=$driver==='mysql';
    }

    public static function descriptor(): array
    {
        $lanes=[];
        foreach(['native','archive','manual','prepared','virtual'] as $lane)
            $lanes[$lane]=['start_cursor'=>'qh.'.$lane.'.start','required_scopes'=>$lane==='archive'?['quality:read','archive:read']:['quality:read']];
        return ['requires_explicit_consent'=>true,'scope'=>'quality:read','lanes'=>$lanes,
            'period'=>'previous calendar month day at00:00 Moscow through the first lane read','max_inventory_limit'=>25,
            'retention'=>'retained rows only; deleted or overwritten versions may be absent',
            'no_generation'=>true,'no_business_writes'=>true];
    }

    public function inventory(int $admin,string $connection,array $args): array
    {
        return $this->run($admin,$connection,function(string $key)use($admin,$connection,$args):array{
            $cursor=$args['cursor']??'';$limit=$args['limit']??25;
            if(!is_string($cursor)||!is_int($limit)||$limit<1||$limit>50)self::fail('invalid_arguments');
            if(preg_match('/\Aqh\.(native|archive|manual|prepared|virtual)\.start\z/D',$cursor,$m)){
                $until=$this->now();$date=(new DateTimeImmutable('@'.$until))->setTimezone(new DateTimeZone('Europe/Moscow'));
                $previous=$date->modify('first day of last month')->setTime(0,0);
                $from=$previous->setDate((int)$previous->format('Y'),(int)$previous->format('m'),min((int)$date->format('d'),(int)$previous->format('t')))->getTimestamp();
                $s=['k'=>'inventory','l'=>$m[1],'f'=>$from,'t'=>$until];
            }else{$s=$this->decode($cursor,$key,$admin,$connection);if($s['k']!=='inventory')self::fail('stale_cursor');}
            $lane=$s['l'];$limit=min($limit,25);
            if(in_array($lane,['native','archive'],true)){
                $delegate=$this->delegate($lane,$admin,$connection);
                $a=['limit'=>$limit];if(isset($s['x']))$a['cursor']=$s['x'];
                $r=$delegate->inventory($admin,$connection,$a);
                $field=$lane==='native'?'dialogues':'chats';$ref=$lane==='native'?'dialogue_ref':'chat_ref';$records=[];
                foreach($r[$field]??[] as $row){
                    $row['record_ref']=$this->encode(['k'=>'record','l'=>$lane,'f'=>$s['f'],'t'=>$s['t'],'x'=>$row[$ref]],$key,$admin,$connection);
                    unset($row[$ref]);$row['kind']=$lane;$records[]=$row;
                }
                unset($r[$field],$r['feature_scan'],$r['semantic_scan'],$r['historical_prefix_study']);
                $r['records']=$records;$r['quality_period']=$this->period($s);
                if(is_string($r['next_cursor']??null))$r['next_cursor']=$this->encode(['k'=>'inventory','l'=>$lane,'f'=>$s['f'],'t'=>$s['t'],'x'=>$r['next_cursor']],$key,$admin,$connection);
                return$r;
            }
            $rows=$this->readOnly(fn():array=>$this->metadataRows($lane,$s,null,$limit+1));
            $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$records=[];$skipped=0;$proofs=[];
            foreach($rows as $row){
                $proof=$this->sourceProof($lane,$row);
                if($proof===null){$skipped++;continue;}
                $revision=$this->revision($lane,$row);
                $ref=$this->encode(['k'=>'record','l'=>$lane,'f'=>$s['f'],'t'=>$s['t'],'id'=>(string)$row['id'],'rev'=>$revision,'proof'=>$proof,'section'=>'answer'],$key,$admin,$connection);
                $records[]=['kind'=>$lane,'record_ref'=>$ref,'record_id'=>(string)$row['id'],'conversation_id'=>isset($row['conversation_id'])?(string)$row['conversation_id']:null,
                    'saved_at'=>$row['at'],'clock_basis'=>'explicit_utc_producer_contract_not_native_transcript_datetime',
                    'status'=>$row['status']??null,'bound_message_id'=>isset($row['latest_message_id'])?(string)$row['latest_message_id']:null,
                    'instruction_revision'=>$row['instruction_revision']??null,'prepared_parent'=>$row['preparation_job_id']??null,
                    'source_kind'=>$row['source_kind']??'conversation','proposal_bytes'=>(int)$row['payload_bytes']];
                $proofs[]=[$row,$proof];
            }
            foreach($proofs as [$row,$proof])if($this->sourceProof($lane,$row)!==$proof)self::fail('source_changed');
            $next=null;
            if($more&&$rows){$last=$rows[count($rows)-1];$next=$this->encode(['k'=>'inventory','l'=>$lane,'f'=>$s['f'],'t'=>$s['t'],'at'=>$last['at'],'after'=>(string)$last['id']],$key,$admin,$connection);}
            return $this->base('inventory',$s)+['records'=>$records,'returned'=>count($records),'examined_records'=>count($rows),
                'skipped_source_scope_records'=>$skipped,'has_more'=>$more,'next_cursor'=>$next];
        });
    }

    public function context(int $admin,string $connection,array $args): array
    {
        return $this->run($admin,$connection,function(string $key)use($admin,$connection,$args):array{
            if(!is_string($args['dialogue_ref']??null))self::fail('invalid_arguments');
            $ref=$args['dialogue_ref'];$s=$this->decode($ref,$key,$admin,$connection);
            if($s['k']!=='record')self::fail('stale_cursor');
            $c=isset($args['cursor'])?$this->decode($args['cursor'],$key,$admin,$connection):null;
            if($c!==null&&($c['k']!=='context'||($c['reference']??null)!==hash('sha256',$ref)||$c['l']!==$s['l']))self::fail('stale_cursor');
            $lane=$s['l'];
            if(in_array($lane,['native','archive'],true)){
                $a=[$lane==='native'?'dialogue_ref':'chat_ref'=>$s['x'],'limit'=>$args['limit']??100];if($c!==null)$a['cursor']=$c['x'];
                $delegate=$this->delegate($lane,$admin,$connection);$method=$lane==='native'?'context':'history';
                $r=$delegate->$method($admin,$connection,$a);$r['dialogue_ref']=$ref;$r['quality_period']=$this->period($s);
                if(is_string($r['next_cursor']??null))$r['next_cursor']=$this->encode(['k'=>'context','l'=>$lane,'f'=>$s['f'],'t'=>$s['t'],'reference'=>hash('sha256',$ref),'x'=>$r['next_cursor']],$key,$admin,$connection);
                return$r;
            }
            $rows=$this->readOnly(fn():array=>$this->metadataRows($lane,$s,$s['id']??null,1));$row=$rows[0]??null;
            if($row===null)self::fail('dialogue_unavailable');
            if(!hash_equals($s['rev']??'',$this->revision($lane,$row)))self::fail('source_changed');
            $proof=$this->sourceProof($lane,$row);if($proof===null)self::fail('dialogue_unavailable');
            if(!is_string($s['proof']??null)||!hash_equals($s['proof'],$proof))self::fail('source_changed');
            $section=$s['section']??'answer';
            $data=$this->readOnly(fn():array=>$this->loadJson($lane,$row,$section));
            $after=$this->readOnly(fn():array=>$this->metadataRows($lane,$s,(string)$row['id'],1));
            if(!$after||$this->revision($lane,$after[0])!==$this->revision($lane,$row)||$this->sourceProof($lane,$row)!==$proof)self::fail('source_changed');
            $result=$this->base('context',$s)+['record_id'=>(string)$row['id'],'kind'=>$lane,'section'=>$section,
                'dialogue_ref'=>$ref,'bound_message_id'=>isset($row['latest_message_id'])?(string)$row['latest_message_id']:null,
                'input_meaning'=>'retained normalized evidence; not proof of the complete external model input',
                'availability'=>$data['availability'],'has_more'=>false,'next_cursor'=>null];
            if($section==='answer')$result['input_ref']=$this->encode(array_merge($s,['section'=>'input']),$key,$admin,$connection);
            if($data['availability']!=='available')return$result;
            $json=self::json($this->minimize($data['value']));$projection=hash('sha256',$json);$offset=$c['off']??0;
            if(!is_int($offset)||$offset<0||$offset>strlen($json)||($c!==null&&($c['projection']??null)!==$projection))self::fail('source_changed');
            $fragment=substr($json,$offset,self::FRAGMENT_BYTES);
            while($fragment!==''&&preg_match('//u',$fragment)!==1)$fragment=substr($fragment,0,-1);
            if($fragment===''&&$offset<strlen($json))self::fail('unavailable');
            $next=$offset+strlen($fragment);$total=strlen($json);
            $result['content']=['encoding'=>'json_utf8_fragment','fragment'=>$fragment,'offset_bytes'=>$offset,
                'total_bytes'=>$total,'projection_sha256'=>$projection,'text_minimized'=>true];
            $result['has_more']=$next<$total;
            if($result['has_more'])$result['next_cursor']=$this->encode(['k'=>'context','l'=>$lane,'f'=>$s['f'],'t'=>$s['t'],'reference'=>hash('sha256',$ref),'off'=>$next,'projection'=>$projection],$key,$admin,$connection);
            return$result;
        });
    }

    private function metadataRows(string $lane,array $s,?string $id,int $limit): array
    {
        if(!in_array($lane,['manual','prepared','virtual'],true))self::fail('invalid_arguments');
        if($lane==='virtual'){
            $sampling=new VirtualReplySampling($this->pdo,fn():int=>$this->now());
            if(!$sampling->available())self::fail('unavailable');
            $select="SELECT c.case_id AS id,c.source_kind,c.source_id,c.source_revision,c.state AS status,c.reference_time,j.completed_at AS at,j.instruction_revision,j.result_sha256 AS stored_hash,"
                .$this->length('j.result_json')." AS payload_bytes FROM virtual_reply_cases c JOIN virtual_reply_jobs j ON j.case_id=c.case_id AND j.role='author'";
            $where="c.project_key=? AND j.result_json IS NOT NULL AND j.completed_at>=? AND j.completed_at<=? AND ".$sampling->reviewPredicate('c');
            $idColumn='c.case_id';$at='j.completed_at';
        }else{
            $table=$lane==='manual'?'manager_ai_drafts':'manager_prepared_replies';
            $idColumn=$lane==='manual'?'r.draft_id':'r.job_id';$at=$lane==='manual'?'r.created_at':'r.prepared_at';
            $parent=$lane==='manual'?'r.preparation_job_id':'NULL';
            $storedHash=$lane==='manual'?'NULL':'r.proposal_sha256';$status=$lane==='manual'?"'stored'":'r.status';
            $select='SELECT '.$idColumn.' AS id,r.conversation_id,r.manager_id,r.project_key,r.context_revision,r.instruction_revision,r.latest_message_id,'
                .$at.' AS at,'.$status.' AS status,'.$parent.' AS preparation_job_id,'.$storedHash.' AS stored_hash,'.$this->length('r.proposal_json').' AS payload_bytes'
                .' FROM '.$table.' r JOIN conversations c ON c.id=r.conversation_id AND c.project_key=r.project_key'
                .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel'
                .' JOIN projects p ON p.project_key=c.project_key';
            $where="r.project_key=? AND p.is_active=1 AND c.channel IN ('max','telegram') AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')
                .' AND r.proposal_json IS NOT NULL AND '.$at.'>=? AND '.$at.'<=?';
        }
        $bindings=['anytour',gmdate('Y-m-d H:i:s',$s['f']),gmdate('Y-m-d H:i:s',$s['t'])];
        if($id!==null){
            if(preg_match($lane==='manual'?'/\A[a-f0-9]{32}\z/D':'/\A[a-f0-9]{64}\z/D',$id)!==1)self::fail('stale_cursor');
            $where.=' AND '.$idColumn.'=?';$bindings[]=$id;
        }elseif(isset($s['after'])){
            if(!is_string($s['at']??null)||!is_string($s['after']))self::fail('stale_cursor');
            $where.=' AND ('.$at.'>? OR ('.$at.'=? AND '.$idColumn.'>?))';array_push($bindings,$s['at'],$s['at'],$s['after']);
        }
        return $this->q($select.' WHERE '.$where.' ORDER BY '.$at.','.$idColumn.' LIMIT '.$limit,$bindings)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function sourceProof(string $lane,array $row): ?string
    {
        if($lane!=='virtual'){
            $proof=$this->readOnly(fn()=>$this->nativeProof((string)$row['conversation_id']));
            return $proof===null?null:hash('sha256',self::json($proof));
        }
        $kind=$row['source_kind']??'';$id=(string)($row['source_id']??'');
        if(preg_match('/\A[1-9][0-9]{0,18}\z/D',$id)!==1)return null;
        if($kind==='conversation'){
            $subject=$this->readOnly(function()use($id){
                return $this->q("SELECT m.conversation_id FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.id=? AND c.project_key=? AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c'),[$id,'anytour'])->fetchColumn();
            });
            if($subject===false)return null;$proof=$this->readOnly(fn()=>$this->nativeProof((string)$subject));
            if($proof===null)return null;
        }elseif($kind==='telegram_archive'){
            $allowed=$this->readOnly(fn()=>$this->q('SELECT 1 FROM telegram_dialogue_messages m JOIN projects p ON p.project_key=m.project_key WHERE m.id=? AND m.project_key=? AND p.is_active=1 AND NOT '.InternalOwnerDialogueScope::archiveSql('m'),[$id,'anytour'])->fetchColumn());
            if($allowed===false)return null;
        }else return null;
        $identity=(new VirtualReplySourceReader($this->pdo))->exposureIdentity($kind,(int)$id);
        return $identity===null?null:hash('sha256',self::json([$kind,$id,$identity]));
    }

    private function nativeProof(string $id): ?array
    {
        $row=$this->q("SELECT c.id,c.customer_id,c.customer_channel_id,c.project_key,c.channel,cc.external_user_id,cc.external_chat_id"
            ." FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel"
            ." JOIN projects p ON p.project_key=c.project_key WHERE c.id=? AND c.project_key=? AND p.is_active=1 AND c.is_test=0 AND c.channel IN ('max','telegram') AND NOT ".InternalOwnerDialogueScope::conversationSql('c'),[$id,'anytour'])->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    private function loadJson(string $lane,array $row,string $section): array
    {
        if(!in_array($section,['answer','input'],true))self::fail('stale_cursor');
        $id=(string)$row['id'];$sql='';$bindings=[$id];$hash=null;
        if($section==='answer'){
            if((int)$row['payload_bytes']>32768)return['availability'=>'oversized_retained_answer'];
            $hash=$row['stored_hash']??null;
            $sql=$lane==='manual'?'SELECT proposal_json AS json FROM manager_ai_drafts WHERE draft_id=?'
                :($lane==='prepared'?'SELECT proposal_json AS json FROM manager_prepared_replies WHERE job_id=?'
                :"SELECT result_json AS json FROM virtual_reply_jobs WHERE case_id=? AND role='author'");
        }elseif($lane==='manual'){
            $sql='SELECT input_json AS json,input_sha256 AS stored_hash FROM manager_ai_draft_inputs WHERE draft_id=?';
        }elseif($lane==='prepared'){
            $sql='SELECT i.packet_json AS json,i.packet_sha256 AS stored_hash FROM manager_prepared_reply_input_links l JOIN manager_prepared_reply_inputs i ON i.receipt_id=l.receipt_id AND i.job_id=l.job_id WHERE l.job_id=?';
        }else{
            $sql='SELECT payload_json AS json FROM virtual_reply_cases WHERE case_id=?';
        }
        // Inspect byte length first. Never materialize an unbounded historical value.
        $from=strpos($sql,' FROM ');$jsonExpression=substr($sql,7,strpos($sql,' AS json')-7);
        $sizes=$this->q('SELECT '.$this->length($jsonExpression).' AS bytes'.substr($sql,$from),$bindings)->fetch(PDO::FETCH_ASSOC);
        if($sizes===false)return['availability'=>'not_captured_or_no_longer_retained'];
        if((int)$sizes['bytes']>($section==='answer'?32768:self::MAX_INPUT))return['availability'=>'oversized_retained_context'];
        $record=$this->q($sql,$bindings)->fetch(PDO::FETCH_ASSOC);
        if(!$record||!is_string($record['json']??null))return['availability'=>'not_captured_or_no_longer_retained'];
        $raw=$record['json'];$hash=$record['stored_hash']??$hash;
        if(strlen($raw)!==(int)$sizes['bytes'])self::fail('source_changed');
        if($hash!==null&&(!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1||!hash_equals($hash,hash('sha256',$raw))))return['availability'=>'retained_hash_mismatch'];
        try{$value=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable $e){return['availability'=>'invalid_retained_json'];}
        if(!is_array($value))return['availability'=>'invalid_retained_json'];
        if($section==='input'&&$lane==='virtual'){
            $columns=$this->q("SELECT ".$this->length('instructions')." AS instruction_bytes,".$this->length('profile_json')." AS profile_bytes,".$this->length('schema_json')." AS schema_bytes FROM virtual_reply_jobs WHERE case_id=? AND role='author'",[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$columns||array_sum(array_map('intval',$columns))+strlen($raw)>self::MAX_INPUT)return['availability'=>'oversized_retained_context'];
            $contract=$this->q("SELECT instructions,profile_json,schema_json FROM virtual_reply_jobs WHERE case_id=? AND role='author'",[$id])->fetch(PDO::FETCH_ASSOC);
            $value=['payload'=>$value,'retained_author_contract'=>$contract,'meaning'=>'stored_case_and_contract_not_verified_external_wire'];
        }
        return ['availability'=>'available','value'=>$value];
    }

    private function revision(string $lane,array $row): string
    {
        unset($row['status']);
        return hash('sha256',self::json([$lane,$row]));
    }

    private function delegate(string $lane,int $admin,string $connection): object
    {
        $provider=fn(int $a,string $c):string=>$this->authorizedKey($a,$c);
        if($lane==='native')return new DialogueReviewReadService($this->pdo,$this->clock,$provider,null,true);
        if($this->archiveKeyProvider===null)self::fail('forbidden');
        $archiveKey=($this->archiveKeyProvider)($admin,$connection);
        $provider=function(int $a,string $c)use($archiveKey):string{
            $quality=$this->authorizedKey($a,$c);$fresh=($this->archiveKeyProvider)($a,$c);
            if(!is_string($fresh)||strlen($fresh)!==32||!hash_equals($archiveKey,$fresh))self::fail('permission_changed');
            return hash_hmac('sha256','anytour.quality.archive.v1'.$quality,$fresh,true);
        };
        return new ArchiveResearchReadService($this->pdo,$this->clock,$provider,true);
    }

    private function run(int $admin,string $connection,callable $operation): array
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        $this->deadline=microtime(true)+8.0;
        try{
            $key=$this->authorizedKey($admin,$connection);$result=$operation($key);
            if(!hash_equals($key,$this->authorizedKey($admin,$connection)))self::fail('stale_cursor');
            if(strlen(self::json($result))>65536)self::fail('response_limit');
            return$result;
        }catch(DialogueReviewReadException $e){throw$e;}
        catch(ArchiveResearchReadException $e){self::fail(in_array($e->reason,['forbidden','permission_changed','stale_cursor','time_limit','source_changed'],true)?$e->reason:'unavailable');}
        catch(DomainException $e){self::fail('forbidden');}
        catch(PDOException $e){self::fail(in_array((int)($e->errorInfo[1]??0),[3024,1969],true)?'time_limit':'unavailable');}
        catch(Throwable $e){self::fail('unavailable');}
    }

    private function authorizedKey(int $admin,string $connection): string
    {
        if($admin<1||preg_match('/\A[a-f0-9]{32}\z/D',$connection)!==1||!is_callable($this->keyProvider))self::fail('forbidden');
        $key=($this->keyProvider)($admin,$connection);
        if(!is_string($key)||strlen($key)!==32)self::fail('forbidden');
        $allowed=$this->q("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.role='admin' AND m.is_active=1 AND p.project_key=? AND p.is_active=1 LIMIT 1",[$admin,'anytour'])->fetchColumn();
        if($allowed===false)self::fail('forbidden');return$key;
    }

    private function readOnly(callable $operation): mixed
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        $queryOnly=null;$owned=false;
        try{
            if($this->mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');}
            else{$queryOnly=(int)$this->pdo->query('PRAGMA query_only')->fetchColumn();$this->pdo->exec('PRAGMA query_only=ON');$this->pdo->beginTransaction();}
            $owned=true;return$operation();
        }finally{
            if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();
            if($queryOnly!==null)$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));
        }
    }

    private function encode(array $s,string $key,int $admin,string $connection): string
    {
        $s['v']=1;$s['e']=$this->now()+self::TTL;
        $body=rtrim(strtr(base64_encode(self::json($s)),'+/','-_'),'=');
        return 'qh.'.$body.'.'.hash_hmac('sha256',"anytour.quality.v1\0".$admin."\0".$connection."\0".$body,$key);
    }

    private function decode(string $token,string $key,int $admin,string $connection): array
    {
        if(strlen($token)>2048||preg_match('/\Aqh\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$token,$m)!==1
            ||!hash_equals(hash_hmac('sha256',"anytour.quality.v1\0".$admin."\0".$connection."\0".$m[1],$key),$m[2]))self::fail('stale_cursor');
        try{$s=json_decode((string)base64_decode(strtr($m[1],'-_','+/').str_repeat('=',(4-strlen($m[1])%4)%4),true),true,16,JSON_THROW_ON_ERROR);}
        catch(Throwable $e){self::fail('stale_cursor');}
        if(!is_array($s)||($s['v']??null)!==1||!in_array($s['k']??null,['inventory','record','context'],true)
            ||!in_array($s['l']??null,['native','archive','manual','prepared','virtual'],true)
            ||!is_int($s['f']??null)||!is_int($s['t']??null)||$s['f']<946684800||$s['f']>=$s['t']||$s['t']>$this->now()
            ||$s['t']-$s['f']>32*86400||!is_int($s['e']??null)||$s['e']<$this->now()||$s['e']>$this->now()+self::TTL)self::fail('stale_cursor');
        return$s;
    }

    private function base(string $operation,array $s): array
    {
        return ['ok'=>true,'schema_version'=>1,'project_key'=>'anytour','operation'=>'quality_history_'.$operation,'quality_period'=>$this->period($s),
            'database_writes'=>0,'read_markers_changed'=>0,'customer_sends'=>0,'model_calls'=>0,'external_fetches'=>0,'untrusted_source_text'=>true,
            'retention_completeness'=>'unverified; deleted or overwritten versions may be absent','semantic_review_complete'=>false];
    }
    private function period(array $s): array
    {
        $zone=new DateTimeZone('Europe/Moscow');
        return ['from'=>(new DateTimeImmutable('@'.$s['f']))->setTimezone($zone)->format(DATE_ATOM),
            'until'=>(new DateTimeImmutable('@'.$s['t']))->setTimezone($zone)->format(DATE_ATOM),'timezone'=>'Europe/Moscow',
            'native_legacy_clock_uncertainty_preserved'=>true,'mutable_rows_may_change_between_pages'=>true];
    }
    private function minimize(mixed $value): mixed
    {
        if(is_string($value)){if(str_starts_with($value,'data:'))return'[media bytes not read]';$selection=false;$links=false;return ManagerAiTextRedactor::redact($value,$selection,$links);}
        if(!is_array($value))return$value;
        $out=[];foreach($value as $key=>$item){if(is_string($key)&&preg_match('/\A(?:access_token|refresh_token|authorization|client_secret|signing_secret)\z/i',$key))$out[$key]='[credential omitted]';else$out[$key]=$this->minimize($item);}return$out;
    }
    private function q(string $sql,array $bindings=[]): PDOStatement
    {
        if(microtime(true)>$this->deadline)self::fail('time_limit');
        if(!str_starts_with($sql,'SELECT '))self::fail('unavailable');
        if($this->mysql)$sql='SELECT /*+ MAX_EXECUTION_TIME('.max(1,min(5000,(int)(($this->deadline-microtime(true))*1000))).') */ '.substr($sql,7);
        $q=$this->pdo->prepare($sql);$q->execute($bindings);if(microtime(true)>$this->deadline)self::fail('time_limit');return$q;
    }
    private function length(string $column): string
    {return $this->mysql?'OCTET_LENGTH(COALESCE('.$column.",''))":'LENGTH(CAST(COALESCE('.$column.",'') AS BLOB))";}
    private function now(): int
    {$now=$this->clock!==null?($this->clock)():time();if(!is_int($now)||$now<946684800||$now>4102444800)self::fail('unavailable');return$now;}
    private static function json(mixed $value): string
    {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function fail(string $reason): never {throw new DialogueReviewReadException($reason);}
}
