<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerRequestProfile.php';
require_once __DIR__.'/ManagerConversationService.php';

/** One canonical manager-curated profile history; no wizard, message or send writes. */
final class ManagerRequestProfileService
{
    public static function read(int $actor,int $cid): array
    {
        $pdo=ConversationDb::connection();if($pdo->inTransaction())return self::error('profile_unavailable');
        try{
            $scope=self::scope($pdo,$actor,$cid);if(!$scope)return self::error('forbidden');
            $pdo->beginTransaction();$row=self::latest($pdo,$cid);$context=self::context($pdo,$cid,self::ids($row));
            $view=self::view($row,$context);if($row&&!$view['changed_sources'])$view['status']=hash_equals($row['context_sha256'],self::basis($scope,$context))?'fresh':'needs_review';
            $history=self::history($pdo,$cid);$pdo->commit();
            $after=self::scope($pdo,$actor,$cid);
            if(!$after||self::scopeKey($scope)!==self::scopeKey($after))return self::error('forbidden');
            $sources=[];foreach($context['sources'] as $s){$s['text_truncated']=mb_strlen($s['text'])>6000;$s['text']=mb_substr($s['text'],0,6000);$sources[]=$s;}
            $party=null;$lastCustomer=null;foreach($context['sources'] as $s)if($s['sender_type']==='customer')$lastCustomer=$s;
            if($lastCustomer&&!empty($lastCustomer['customer_statement_eligible'])&&mb_strlen($lastCustomer['text'])<=600){$v=ManagerRequestProfile::explicitParty($lastCustomer['text']);$old=$view['fields']['party']['citation']['message_id']??0;
                if($v!==null&&$lastCustomer['id']>$old)$party=['value'=>$v,'source_id'=>$lastCustomer['id'],'quote'=>$lastCustomer['text']];}
            return ['ok'=>true,'viewer_id'=>$actor,'conversation_id'=>$cid,'can_edit'=>$scope['can_edit'],
                'context_sha256'=>self::basis($scope,$context),'latest_message_id'=>$context['latest'],
                'sources'=>$sources,'coverage_incomplete'=>$context['incomplete'],'profile'=>$view,'history'=>$history,
                'party_suggestion'=>$party,'field_labels'=>ManagerRequestProfile::FIELDS];
        }catch(Throwable $ignored){if($pdo->inTransaction())$pdo->rollBack();return self::error('profile_unavailable');}
    }

    public static function save(int $actor,int $cid,array $input): array
    {
        $pdo=ConversationDb::connection();if($pdo->inTransaction())return self::error('profile_unavailable');
        try{
            if(!is_int($input['version']??null)||$input['version']<0||!self::hex($input['context_sha256']??null,64)
                ||!self::hex($input['operation_id']??null,32)||!is_int($input['boundary_message_id']??null)
                ||!is_bool($input['new_trip']??null)||!ManagerRequestProfile::text($input['label']??null,120,false)
                ||!is_array($input['fields']??null))return self::error('invalid_profile');
            $command=array_intersect_key($input,array_flip(['version','context_sha256','operation_id','boundary_message_id','new_trip','label','fields']));
            $request=hash('sha256',json_encode($command,ManagerRequestProfile::FLAGS));
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id FROM managers WHERE id=? AND is_active=1'.$lock);$q->execute([$actor]);if(!$q->fetchColumn()){ $pdo->rollBack();return self::error('forbidden');}
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$lock);$q->execute([$cid]);if(!$q->fetchColumn()){ $pdo->rollBack();return self::error('forbidden');}
            $scope=self::scope($pdo,$actor,$cid);if(!$scope||!$scope['can_edit']){ $pdo->rollBack();return self::error('forbidden');}
            $q=$pdo->prepare('SELECT conversation_id,version,request_sha256 FROM manager_request_profiles WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$input['operation_id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
            if($receipt){$pdo->rollBack();return (int)$receipt['conversation_id']===$cid&&hash_equals($receipt['request_sha256'],$request)
                ?['ok'=>true,'viewer_id'=>$actor,'conversation_id'=>$cid,'saved_version'=>(int)$receipt['version'],'replayed'=>true]:self::error('operation_mismatch');}
            $row=self::latest($pdo,$cid);if(($row?(int)$row['version']:0)!==$input['version']){$pdo->rollBack();return self::error('conflict');}
            $ids=self::ids($row);foreach($input['fields'] as $f)if(is_array($f)&&is_int($f['source_id']??null)&&$f['source_id']>0)$ids[]=$f['source_id'];$ids[]=$input['boundary_message_id'];
            $ctx=self::context($pdo,$cid,$ids);
            if(!hash_equals(self::basis($scope,$ctx),$input['context_sha256'])){$pdo->rollBack();return self::error('context_changed');}
            $boundary=$input['boundary_message_id'];$start=$ctx['sources'][$boundary]??null;
            if(!$start||!in_array($start['sender_type'],['customer','manager'],true)||!empty($start['unavailable']))throw new InvalidArgumentException('boundary_required');
            if($row&&!$input['new_trip']&&$boundary!==(int)$row['boundary_message_id'])throw new InvalidArgumentException('new_trip_required');
            $fields=ManagerRequestProfile::normalize($input['fields'],$ctx['sources'],$boundary);
            // Validate against the editor's observed sources; persist only sources retained by the new version.
            $savedContext=$ctx;$keep=array_fill_keys([...$ctx['window_ids'],$boundary,...array_column(array_column($fields,'citation'),'message_id')],true);
            $savedContext['sources']=array_intersect_key($ctx['sources'],$keep);
            $profile=['schema_version'=>1,'label'=>trim($input['label']),'fields'=>$fields];$json=json_encode($profile,ManagerRequestProfile::FLAGS);
            if(strlen($json)>ManagerRequestProfile::MAX_BYTES)throw new InvalidArgumentException('profile_too_large');
            $before=$row?json_decode($row['profile_json'],true,64,JSON_THROW_ON_ERROR)['fields']:[];$changed=[];
            foreach(ManagerRequestProfile::FIELDS as $key=>$unused)if(($before[$key]??null)!==($fields[$key]??null))$changed[]=$key;
            $trip=$row&&!$input['new_trip']?$row['trip_id']:bin2hex(random_bytes(16));$version=$input['version']+1;
            $q=$pdo->prepare('INSERT INTO manager_request_profiles (conversation_id,version,trip_id,boundary_message_id,manager_id,operation_id,request_sha256,context_sha256,profile_json,profile_sha256,changed_fields_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$cid,$version,$trip,$boundary,$actor,$input['operation_id'],$request,self::basis($scope,$savedContext),$json,hash('sha256',$json),json_encode($changed,ManagerRequestProfile::FLAGS),gmdate('Y-m-d H:i:s')]);
            $after=self::scope($pdo,$actor,$cid);if(!$after||!$after['can_edit']||self::scopeKey($scope)!==self::scopeKey($after)){$pdo->rollBack();return self::error('forbidden');}
            $pdo->commit();return ['ok'=>true,'viewer_id'=>$actor,'conversation_id'=>$cid,'saved_version'=>$version,'replayed'=>false];
        }catch(InvalidArgumentException $e){if($pdo->inTransaction())$pdo->rollBack();return self::error($e->getMessage());}
        catch(Throwable $ignored){if($pdo->inTransaction())$pdo->rollBack();return self::error('profile_unavailable');}
    }

    public static function operation(int $actor,int $cid,string $operation): array
    {
        if(!self::hex($operation,32))return self::error('invalid_operation');
        try{$pdo=ConversationDb::connection();if(!self::scope($pdo,$actor,$cid))return self::error('forbidden');
            $q=$pdo->prepare('SELECT conversation_id,version FROM manager_request_profiles WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$operation]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if($r&&(int)$r['conversation_id']!==$cid)return self::error('operation_mismatch');
            if(!self::scope($pdo,$actor,$cid))return self::error('forbidden');
            return ['ok'=>true,'viewer_id'=>$actor,'conversation_id'=>$cid,'found'=>$r!==false,'saved_version'=>$r?(int)$r['version']:null];
        }catch(Throwable $ignored){return self::error('profile_unavailable');}
    }

    /** Inside the already-authorized canonical AI read transaction. No writes/auth bootstrap. */
    public static function forContext(PDO $pdo,array $conversation,array $context): ?array
    {
        if(($conversation['project_key']??null)!=='anytour')return null;
        try{$r=self::latest($pdo,(int)$conversation['id']);if(!$r)return null;$ctx=self::context($pdo,(int)$conversation['id'],self::ids($r));
            $view=self::view($r,$ctx);$scope=['conversation'=>$conversation];
            $view['status']=$view['changed_sources']?'source_changed':($ctx['latest']===($context['as_of_message_id']??null)&&$r['context_sha256']===self::basis($scope,$ctx)&&!$view['changed_sources']?'fresh':'needs_review');
            unset($view['saved_by']);
            array_walk_recursive($view,static function(&$v){if(is_string($v)){$a=false;$b=false;$v=ManagerAiTextRedactor::redact($v,$a,$b);}});
            return $view;
        }catch(Throwable $ignored){try{$q=$pdo->prepare('SELECT version FROM manager_request_profiles WHERE conversation_id=? ORDER BY version DESC LIMIT 1');$q->execute([(int)$conversation['id']]);$v=$q->fetchColumn();return $v?['status'=>'unavailable','version'=>(int)$v,'fields'=>[]]:null;}catch(Throwable $missing){return null;}}
    }

    private static function scope(PDO $pdo,int $actor,int $cid): ?array
    {
        if($actor<=0||$cid<=0)return null;ProjectAccessService::initializeReadOnly();$m=ManagerAuthService::byId($actor);if(!$m)return null;
        $c=ManagerConversationService::visibleConversation($cid,$actor);if(!$c||$c['project_key']!=='anytour')return null;
        return ['conversation'=>$c,'can_edit'=>$c['status']!=='closed'&&ManagerConversationAccessPolicy::canEditVisibleConversation($c,$m)];
    }
    private static function scopeKey(array $s): array{$c=$s['conversation'];return [(int)$c['id'],(string)$c['project_key'],(int)($c['source_id']??0),(string)($c['channel']??''),(int)($c['manager_id']??0),(string)$c['status']];}
    private static function basis(array $scope,array $ctx): string{return hash('sha256',json_encode([self::scopeKey($scope),$ctx['latest'],$ctx['window_hash'],array_map(static fn($s)=>[$s['id'],$s['sha256']],array_values($ctx['sources']))],ManagerRequestProfile::FLAGS));}
    private static function latest(PDO $pdo,int $cid): ?array
    {
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $q=$pdo->prepare('SELECT conversation_id,version,trip_id,boundary_message_id,manager_id,context_sha256,SUBSTR(CAST(profile_json AS '.$binary.'),1,'.(ManagerRequestProfile::MAX_BYTES+1).') AS profile_json,profile_sha256,created_at FROM manager_request_profiles WHERE conversation_id=? ORDER BY version DESC LIMIT 1');$q->execute([$cid]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$r)return null;
        if(strlen($r['profile_json'])>ManagerRequestProfile::MAX_BYTES||!self::hex($r['profile_sha256'],64)||!hash_equals($r['profile_sha256'],hash('sha256',$r['profile_json']))
            ||!self::hex($r['trip_id'],32)||!self::hex($r['context_sha256'],64)||(int)$r['version']<=0
            ||!ManagerRequestProfile::validateStored(json_decode($r['profile_json'],true,64,JSON_THROW_ON_ERROR)))throw new RuntimeException('invalid_profile_storage');
        return $r;
    }
    private static function ids(?array $row): array
    {
        if(!$row)return [];$ids=[(int)$row['boundary_message_id']];foreach(json_decode($row['profile_json'],true,64,JSON_THROW_ON_ERROR)['fields'] as $f)if($f['citation'])$ids[]=$f['citation']['message_id'];return array_values(array_unique($ids));
    }
    private static function context(PDO $pdo,int $cid,array $extra): array
    {
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $sql='SELECT id,direction,sender_type,created_at,SUBSTR(CAST(text AS '.$binary.'),1,80001) AS text,SUBSTR(CAST(metadata_json AS '.$binary.'),1,8193) AS metadata FROM messages WHERE conversation_id=?';
        $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 41');$q->execute([$cid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>40;$rows=array_slice($rows,0,40);$window=[];$map=[];$bytes=0;
        foreach(array_reverse($rows) as $r){$s=self::source($r);$map[$s['id']]=$s;$window[]=[$s['id'],$s['sha256']];$bytes+=strlen($s['text']);}
        $latest=$rows?(int)$rows[0]['id']:0;$ids=array_values(array_unique(array_filter($extra,static fn($n)=>is_int($n)&&$n>0&&!isset($map[$n]))));
        if(count($ids)>13)throw new RuntimeException('profile_sources_limit');
        if($ids){$q=$pdo->prepare($sql.' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute([$cid,...$ids]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$s=self::source($r);$map[$s['id']]=$s;$bytes+=strlen($s['text']);}}
        if($bytes>320000)throw new RuntimeException('profile_context_limit');ksort($map);
        return ['latest'=>$latest,'sources'=>$map,'window_ids'=>array_map(static fn($r)=>(int)$r['id'],$rows),'window_hash'=>hash('sha256',json_encode($window,ManagerRequestProfile::FLAGS)),'incomplete'=>$more||count(array_filter($map,static fn($s)=>$s['unavailable']))>0];
    }
    private static function source(array $r): array
    {
        $raw=(string)($r['text']??'');$meta=(string)($r['metadata']??'');$decoded=$meta===''?[]:json_decode($meta,true,32);$bad=!is_array($decoded)||($decoded['type']??'message')!=='message'||strlen($raw)>80000||strlen($meta)>8192||preg_match('//u',$raw)!==1;
        $hash=hash('sha256',json_encode([(int)$r['id'],$r['direction'],$r['sender_type'],$r['created_at'],hash('sha256',$raw),hash('sha256',$meta)],ManagerRequestProfile::FLAGS));
        $a=false;$b=false;$text=$bad?'':ManagerAiTextRedactor::redact($r['direction']==='outbound'?htmlspecialchars_decode($raw,ENT_QUOTES):$raw,$a,$b);
        return ['id'=>(int)$r['id'],'sender_type'=>$r['sender_type'],'text'=>$text,'sha256'=>$hash,'unavailable'=>$bad,'customer_statement_eligible'=>!$bad&&$r['direction']==='inbound'&&$r['sender_type']==='customer'&&empty($decoded['linked_message']),'media_unread'=>!empty($decoded['attachments'])||!empty($decoded['linked_message'])];
    }
    private static function view(?array $row,array $ctx): array
    {
        if(!$row)return ['version'=>0,'trip_id'=>null,'boundary_message_id'=>0,'label'=>'','fields'=>[],'status'=>'empty','changed_sources'=>[]];
        $p=json_decode($row['profile_json'],true,64,JSON_THROW_ON_ERROR);$changed=[];
        foreach($p['fields'] as $key=>$f)if($f['citation']){$c=$f['citation'];$s=$ctx['sources'][$c['message_id']]??null;if(!$s||$s['unavailable']||!hash_equals($c['sha256'],$s['sha256']))$changed[]=$key;}
        return ['version'=>(int)$row['version'],'trip_id'=>$row['trip_id'],'boundary_message_id'=>(int)$row['boundary_message_id'],'label'=>$p['label'],'fields'=>$p['fields'],
            'status'=>$changed?'source_changed':'needs_review','changed_sources'=>$changed,'saved_by'=>(int)$row['manager_id'],'saved_at'=>$row['created_at'],'confirmed_context_sha256'=>$row['context_sha256']];
    }
    private static function history(PDO $pdo,int $cid): array{$q=$pdo->prepare('SELECT version,manager_id,trip_id,changed_fields_json,created_at FROM manager_request_profiles WHERE conversation_id=? ORDER BY version DESC LIMIT 5');$q->execute([$cid]);$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['changed_fields']=json_decode($r['changed_fields_json'],true,8,JSON_THROW_ON_ERROR);unset($r['changed_fields_json']);$out[]=$r;}return $out;}
    private static function hex($v,int $n): bool{return is_string($v)&&preg_match('/\A[a-f0-9]{'.$n.'}\z/D',$v)===1;}
    private static function error(string $reason): array{return ['ok'=>false,'error'=>$reason];}
}
