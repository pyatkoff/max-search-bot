<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/MetrikaConversionGoalService.php';
require_once __DIR__ . '/ModeledConversionOutcomeReceipt.php';
require_once __DIR__ . '/SalesOutcomeTripBinding.php';
class SalesPipelineService
{
    public static function stages(bool $activeOnly=true): array{$sql='SELECT stage_key,display_name,color,sort_order,is_active,is_terminal,is_won FROM lead_stages'.($activeOnly?' WHERE is_active=1':'').' ORDER BY sort_order,display_name,stage_key';return ConversationDb::connection()->query($sql)->fetchAll();}
    public static function tags(bool $activeOnly=true): array{$sql='SELECT id,tag_key,display_name,color,sort_order,is_active FROM lead_tags'.($activeOnly?' WHERE is_active=1':'').' ORDER BY sort_order,display_name,id';return ConversationDb::connection()->query($sql)->fetchAll();}
    public static function closeReasons(bool $activeOnly=true): array{$sql='SELECT reason_key,display_name,sort_order,is_active FROM lead_close_reasons'.($activeOnly?' WHERE is_active=1':'').' ORDER BY sort_order,display_name,reason_key';return ConversationDb::connection()->query($sql)->fetchAll();}
    public static function outcomeOptions(): array{return ['open'=>'В работе','won'=>'Продажа','lost'=>'Отказ'];}
    public static function closeReasonOptions(bool $activeOnly=true): array{$out=[];foreach(self::closeReasons($activeOnly) as $reason)$out[(string)$reason['reason_key']]=(string)$reason['display_name'];return$out;}
    public static function stageForConversation(int $id): ?array{$q=ConversationDb::connection()->prepare('SELECT s.stage_key,s.display_name,s.color,s.sort_order,s.is_terminal,s.is_won FROM conversations c LEFT JOIN lead_stages s ON s.stage_key=c.lead_stage_key WHERE c.id=? LIMIT 1');$q->execute([$id]);return$q->fetch()?:null;}
    public static function stageHistoryForConversation(int $id,int $limit=20): array{$limit=max(1,min(100,$limit));$q=ConversationDb::connection()->prepare("SELECT h.id,h.from_stage_key,h.to_stage_key,h.changed_by_manager_id,h.created_at,fs.display_name AS from_stage_name,ts.display_name AS to_stage_name,m.display_name AS changed_by_manager_name FROM lead_stage_history h LEFT JOIN lead_stages fs ON fs.stage_key=h.from_stage_key LEFT JOIN lead_stages ts ON ts.stage_key=h.to_stage_key LEFT JOIN managers m ON m.id=h.changed_by_manager_id WHERE h.conversation_id=? ORDER BY h.id DESC LIMIT {$limit}");$q->execute([$id]);return$q->fetchAll();}
    public static function tagsForConversation(int $id): array{$q=ConversationDb::connection()->prepare('SELECT t.id,t.tag_key,t.display_name,t.color,t.sort_order,t.is_active FROM conversation_lead_tags ct JOIN lead_tags t ON t.id=ct.tag_id WHERE ct.conversation_id=? ORDER BY t.is_active DESC,t.sort_order,t.display_name,t.id');$q->execute([$id]);return$q->fetchAll();}
    public static function outcomeForConversation(int $id): array{$q=ConversationDb::connection()->prepare('SELECT c.lead_outcome,c.lead_close_reason,c.lead_outcome_note,c.lead_sale_amount,c.lead_sale_date,c.lead_outcome_updated_at,c.lead_outcome_manager_id,r.display_name AS close_reason_label,r.is_active AS close_reason_active FROM conversations c LEFT JOIN lead_close_reasons r ON r.reason_key=c.lead_close_reason WHERE c.id=? LIMIT 1');$q->execute([$id]);$r=$q->fetch()?:[];return ['outcome'=>(string)($r['lead_outcome']?:'open'),'close_reason'=>$r['lead_close_reason']?:null,'close_reason_label'=>$r['close_reason_label']?:null,'close_reason_active'=>isset($r['close_reason_active'])?(int)$r['close_reason_active']:null,'note'=>$r['lead_outcome_note']===null||$r['lead_outcome_note']===''?null:(string)$r['lead_outcome_note'],'sale_amount'=>$r['lead_sale_amount']!==null?(string)$r['lead_sale_amount']:null,'sale_date'=>$r['lead_sale_date']?:null,'updated_at'=>$r['lead_outcome_updated_at']?:null,'manager_id'=>$r['lead_outcome_manager_id']?:(null)];}
    public static function decorateConversationRows(array $rows): array{if(!$rows)return[];$sm=[];foreach(self::stages(false) as $s)$sm[(string)$s['stage_key']]=$s;$ids=array_values(array_unique(array_filter(array_map(fn($r)=>(int)($r['id']??0),$rows))));$tm=[];$stageSince=[];if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$q=ConversationDb::connection()->prepare("SELECT ct.conversation_id,t.id,t.tag_key,t.display_name,t.color,t.sort_order,t.is_active FROM conversation_lead_tags ct JOIN lead_tags t ON t.id=ct.tag_id WHERE ct.conversation_id IN ({$in}) ORDER BY t.is_active DESC,t.sort_order,t.display_name,t.id");$q->execute($ids);foreach($q->fetchAll() as $t)$tm[(int)$t['conversation_id']][]=$t;$q=ConversationDb::connection()->prepare("SELECT h.conversation_id,h.created_at FROM lead_stage_history h JOIN (SELECT conversation_id,MAX(id) AS max_id FROM lead_stage_history WHERE conversation_id IN ({$in}) GROUP BY conversation_id) latest ON latest.max_id=h.id");$q->execute($ids);foreach($q->fetchAll() as $h)$stageSince[(int)$h['conversation_id']]=$h['created_at']??null;}foreach($rows as &$r){$id=(int)($r['id']??0);$r['lead_stage']=$sm[(string)($r['lead_stage_key']??'new')]??null;$r['lead_tags']=$tm[$id]??[];$r['lead_stage_since_at']=$stageSince[$id]??null;}unset($r);return$rows;}
    public static function setStage(int $id,string $key,int $actor=0): bool{$key=trim($key);if($id<=0||$key==='')return false;$pdo=ConversationDb::connection();$q=$pdo->prepare('SELECT 1 FROM lead_stages WHERE stage_key=? AND is_active=1 LIMIT 1');$q->execute([$key]);if(!$q->fetchColumn())return false;$q=$pdo->prepare('SELECT lead_stage_key FROM conversations WHERE id=? LIMIT 1');$q->execute([$id]);$current=$q->fetchColumn();if($current===false)return false;$current=(string)$current;if($current===$key)return true;$pdo->beginTransaction();try{$q=$pdo->prepare('UPDATE conversations SET lead_stage_key=? WHERE id=? AND lead_stage_key=?');$q->execute([$key,$id,$current]);if($q->rowCount()!==1){$pdo->rollBack();return self::currentStageKey($id)===$key;}$h=$pdo->prepare('INSERT INTO lead_stage_history (conversation_id,from_stage_key,to_stage_key,changed_by_manager_id) VALUES (?,?,?,?)');$h->execute([$id,$current!==''?$current:null,$key,$actor>0?$actor:null]);$pdo->commit();MetrikaConversionGoalService::salesStage($id,$key);return true;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}}
    public static function setTags(int $id,array $tagIds,int $actor=0): bool{if($id<=0)return false;$tagIds=array_values(array_unique(array_filter(array_map('intval',$tagIds),fn($v)=>$v>0)));$pdo=ConversationDb::connection();$pdo->beginTransaction();try{$valid=[];if($tagIds){$in=implode(',',array_fill(0,count($tagIds),'?'));$q=$pdo->prepare("SELECT id FROM lead_tags WHERE is_active=1 AND id IN ({$in})");$q->execute($tagIds);$valid=array_map('intval',array_column($q->fetchAll(),'id'));}if(count($valid)!==count($tagIds)){$pdo->rollBack();return false;}$pdo->prepare('DELETE FROM conversation_lead_tags WHERE conversation_id=? AND tag_id IN (SELECT id FROM lead_tags WHERE is_active=1)')->execute([$id]);if($valid){$ins=$pdo->prepare('INSERT INTO conversation_lead_tags (conversation_id,tag_id,added_by_manager_id) VALUES (?,?,?)');foreach($valid as $tid)$ins->execute([$id,$tid,$actor>0?$actor:null]);}$pdo->commit();return true;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}}
    public static function setOutcome(int $id,string $outcome,?string $reason,?string $note,int $actor,?string $saleAmount=null,?string $saleDate=null): bool
    {
        if(!SalesOutcomeTripBinding::available(ConversationDb::connection()))return self::setOutcomeWithoutTripStorage($id,$outcome,$reason,$note,$actor,$saleAmount,$saleDate);
        $r=self::writeOutcome($id,$actor,['outcome'=>$outcome,'close_reason'=>$reason,'note'=>$note,'sale_amount'=>$saleAmount,'sale_date'=>$saleDate],null);
        return !empty($r['ok']);
    }
    public static function setOutcomeAndStage(int $id,string $outcome,?string $reason,?string $note,int $actor,?string $saleAmount,?string $saleDate,string $stageKey,string $expectedStage): bool
    {
        if(!SalesOutcomeTripBinding::available(ConversationDb::connection()))return self::setOutcomeAndStageWithoutTripStorage($id,$outcome,$reason,$note,$actor,$saleAmount,$saleDate,$stageKey,$expectedStage);
        $r=self::writeOutcome($id,$actor,['outcome'=>$outcome,'close_reason'=>$reason,'note'=>$note,'sale_amount'=>$saleAmount,'sale_date'=>$saleDate,'stage_key'=>$stageKey,'expected_stage_key'=>$expectedStage],null);
        return !empty($r['ok']);
    }

    /** The HTTP caller supplies fresh visibility/edit authorization, repeated under account/conversation locks. */
    public static function saveOutcomeCommand(int $id,int $actor,array $data,callable $authorize):array
    {return self::writeOutcome($id,$actor,$data,$authorize);}

    /** Authorized caller only. Optional trip storage must not hide the existing CRM card. */
    public static function outcomeTripForConversation(int $id):array
    {
        try{$pdo=ConversationDb::connection();$q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            return $c?SalesOutcomeTripBinding::projection($pdo,$c):SalesOutcomeTripBinding::unavailable();
        }catch(Throwable $ignored){return SalesOutcomeTripBinding::unavailable();}
    }

    /** Read-only resolution of the same actor's uncertain operation; never retries a mutation or observer. */
    public static function outcomeOperation(int $id,int $actor,string $operation,callable $authorize):array
    {
        if($id<=0||$actor<=0||!SalesOutcomeTripBinding::hex($operation,32))return self::outcomeError('invalid_binding');
        try{
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare('SELECT * FROM managers WHERE id=? AND is_active=1');$q->execute([$actor]);$m=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            $q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$m||!$c||!$authorize($pdo,$c,$m))return self::outcomeError('forbidden');
            if(!SalesOutcomeTripBinding::available($pdo))return self::outcomeError('storage_unavailable');
            $saved=SalesOutcomeTripBinding::operation($pdo,$actor,$operation);
            if($saved&&(int)$saved['conversation_id']!==$id)return self::outcomeError('operation_mismatch');
            $pipeline=self::conversationSnapshot($id);
            if(!$authorize($pdo,$c,$m))return self::outcomeError('forbidden');
            return ['ok'=>true,'viewer_id'=>$actor,'conversation_id'=>$id,'found'=>$saved!==null,
                'operation'=>$saved?SalesOutcomeTripBinding::operationView($saved):null,'pipeline'=>$pipeline];
        }catch(Throwable $ignored){return self::outcomeError('storage_unavailable');}
    }

    private static function writeOutcome(int $id,int $actor,array $data,?callable $authorize):array
    {
        $bound=array_key_exists('outcome_trip',$data);$trip=$data['outcome_trip']??null;$withStage=array_key_exists('stage_key',$data);
        if($id<=0||($authorize!==null&&$actor<=0)||!is_string($data['outcome']??null))return self::outcomeError('invalid_outcome');
        foreach(['close_reason','note','sale_amount','sale_date']as$key)
            if(isset($data[$key])&&!is_string($data[$key]))return self::outcomeError('invalid_outcome');
        if($withStage&&(!is_string($data['stage_key'])||!is_string($data['expected_stage_key']??null)))return self::outcomeError('invalid_outcome');
        if($bound&&(!is_array($trip)||count($trip)!==4||!SalesOutcomeTripBinding::hex($trip['operation_id']??null,32)
            ||!SalesOutcomeTripBinding::hex($trip['expected_revision']??null,64)||!array_key_exists('trip_id',$trip)||!array_key_exists('profile_version',$trip)
            ||($trip['trip_id']===null?$trip['profile_version']!==null:
                (!SalesOutcomeTripBinding::hex($trip['trip_id'],32)||!is_int($trip['profile_version'])||$trip['profile_version']<1))))return self::outcomeError('invalid_binding');
        if($bound)$trip=['operation_id'=>$trip['operation_id'],'expected_revision'=>$trip['expected_revision'],
            'trip_id'=>$trip['trip_id'],'profile_version'=>$trip['profile_version']];
        $command=[];foreach(['outcome','close_reason','note','sale_amount','sale_date']as$key)$command[$key]=$data[$key]??null;
        $command['stage']=$withStage?[$data['stage_key'],$data['expected_stage_key']]:null;
        $command['outcome_trip']=$bound?$trip:null;
        $request=SalesOutcomeTripBinding::hash($command);$operation=$bound?$trip['operation_id']:bin2hex(random_bytes(16));
        $pdo=ConversationDb::connection();$own=false;
        try{
            if(!SalesOutcomeTripBinding::available($pdo)){
                if($bound)return self::outcomeError('storage_unavailable');
                if($authorize!==null){
                    $q=$pdo->prepare('SELECT * FROM managers WHERE id=? AND is_active=1');$q->execute([$actor]);$legacyManager=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                    $q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$legacyConversation=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                    if(!$legacyManager||!$legacyConversation||!$authorize($pdo,$legacyConversation,$legacyManager))return self::outcomeError('forbidden');
                }
                $ok=$withStage?self::setOutcomeAndStageWithoutTripStorage($id,$data['outcome'],$data['close_reason']??null,$data['note']??null,$actor,$data['sale_amount']??null,$data['sale_date']??null,$data['stage_key'],$data['expected_stage_key']):
                    self::setOutcomeWithoutTripStorage($id,$data['outcome'],$data['close_reason']??null,$data['note']??null,$actor,$data['sale_amount']??null,$data['sale_date']??null);
                return $ok?['ok'=>true,'replayed'=>false,'viewer_id'=>$actor,'conversation_id'=>$id,'pipeline'=>self::conversationSnapshot($id)]:self::outcomeError($withStage?'conflict':'invalid_outcome');
            }
            if($pdo->inTransaction())return self::outcomeError('storage_unavailable');
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();$own=true;$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $m=null;if($actor>0){$q=$pdo->prepare('SELECT * FROM managers WHERE id=? AND is_active=1'.$lock);$q->execute([$actor]);$m=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();}
            $q=$pdo->prepare('SELECT * FROM conversations WHERE id=?'.$lock);$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$c||($authorize!==null&&(!$m||!$authorize($pdo,$c,$m)))){$pdo->rollBack();return self::outcomeError('forbidden');}
            if($bound&&($c['project_key']??null)!=='anytour'){$pdo->rollBack();return self::outcomeError('forbidden');}
            // A completed operation remains completed even if the current outcome or profile has since changed.
            if($bound&&($saved=SalesOutcomeTripBinding::operation($pdo,$actor,$operation))!==null){
                $matches=(int)$saved['conversation_id']===$id&&hash_equals($saved['request_sha256'],$request);
                $pdo->rollBack();$own=false;
                return $matches?['ok'=>true,'replayed'=>true,'viewer_id'=>$actor,'conversation_id'=>$id,'pipeline'=>self::conversationSnapshot($id)]:self::outcomeError('operation_mismatch');
            }
            $profile=null;
            if($bound){
                $view=SalesOutcomeTripBinding::projection($pdo,$c);
                if(!$view['available']){$pdo->rollBack();return self::outcomeError('storage_unavailable');}
                if(!hash_equals($view['revision'],$trip['expected_revision'])){$pdo->rollBack();return self::outcomeError('conflict');}
                if($trip['trip_id']!==null){
                    $profile=SalesOutcomeTripBinding::profile($pdo,$id,$trip['trip_id'],$trip['profile_version']);
                    if(!$profile){$pdo->rollBack();return self::outcomeError('invalid_binding');}
                }
            }
            $values=self::outcomeValues($id,$data['outcome'],$data['close_reason']??null,$data['note']??null,$data['sale_amount']??null,$data['sale_date']??null);
            if($values===null){$pdo->rollBack();return self::outcomeError('invalid_outcome');}
            [$outcome,$reason,$note,$amount,$date]=$values;$stageKey=(string)($c['lead_stage_key']??'');$previousStage=$stageKey;
            if($withStage){
                if($previousStage!==$data['expected_stage_key']){$pdo->rollBack();return self::outcomeError('conflict');}
                $stageKey=trim($data['stage_key']);$q=$pdo->prepare('SELECT is_terminal,is_won FROM lead_stages WHERE stage_key=? AND is_active=1'.$lock);
                $q->execute([$stageKey]);$stage=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                if(!$stage||($outcome==='open'?(int)$stage['is_terminal']!==0:
                    ((int)$stage['is_terminal']!==1||((int)$stage['is_won']===1)!==($outcome==='won')))){$pdo->rollBack();return self::outcomeError('invalid_outcome');}
            }
            $desired=['outcome'=>$outcome,'close_reason'=>$reason!==''?$reason:null,'note'=>$note!==''?$note:null,'sale_amount'=>$amount,'sale_date'=>$date];
            $before=SalesOutcomeTripBinding::outcome($c);$businessChanged=array_intersect_key($before,$desired)!==$desired;
            $stageChanged=$stageKey!==$previousStage;
            // Binding-only commands do not manufacture a new sale or an operational outcome observation.
            if($businessChanged||$stageChanged){
                $q=$pdo->prepare('UPDATE conversations SET lead_stage_key=?,lead_outcome=?,lead_close_reason=?,lead_outcome_note=?,lead_sale_amount=?,lead_sale_date=?,lead_outcome_updated_at=NOW(),lead_outcome_manager_id=? WHERE id=?');
                $q->execute([$stageKey,$outcome,$desired['close_reason'],$desired['note'],$amount,$date,$actor>0?$actor:null,$id]);
                if($q->rowCount()!==1)throw new RuntimeException('outcome_write_unconfirmed');
                if($stageChanged){$q=$pdo->prepare('INSERT INTO lead_stage_history (conversation_id,from_stage_key,to_stage_key,changed_by_manager_id) VALUES (?,?,?,?)');$q->execute([$id,$previousStage!==''?$previousStage:null,$stageKey,$actor>0?$actor:null]);}
            }
            $q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$after=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$after||array_intersect_key(SalesOutcomeTripBinding::outcome($after),$desired)!==$desired||(string)($after['lead_stage_key']??'')!==$stageKey)throw new RuntimeException('outcome_write_unconfirmed');
            if($authorize!==null&&!$authorize($pdo,$after,$m)){$pdo->rollBack();return self::outcomeError('forbidden');}
            SalesOutcomeTripBinding::append($pdo,$after,$actor,$operation,$request,$profile);
            $pdo->commit();$own=false;
            // Existing observers run only for this newly committed business mutation, never on receipt replay.
            if($stageChanged)MetrikaConversionGoalService::salesStage($id,$stageKey);
            if(($businessChanged||$stageChanged)&&$outcome==='won')MetrikaConversionGoalService::saleOutcome($id,$outcome);
            if($businessChanged||$stageChanged)ModeledConversionOutcomeReceipt::observe($pdo,$id,$outcome,$desired['close_reason'],$actor,$amount,$date);
            return ['ok'=>true,'replayed'=>false,'viewer_id'=>$actor,'conversation_id'=>$id,'pipeline'=>self::conversationSnapshot($id)];
        }catch(Throwable $ignored){if($own&&$pdo->inTransaction())$pdo->rollBack();return self::outcomeError('storage_unavailable');}
    }
    private static function outcomeError(string $code):array{return ['ok'=>false,'error'=>$code];}

    /** Compatibility only when the binding migration is positively absent. */
    private static function setOutcomeWithoutTripStorage(int $id,string $outcome,?string $reason,?string $note,int $actor,?string $saleAmount=null,?string $saleDate=null): bool{
        $values=self::outcomeValues($id,$outcome,$reason,$note,$saleAmount,$saleDate);if($values===null)return false;
        [$outcome,$reason,$note,$amount,$date]=$values;
        $pdo=ConversationDb::connection();$q=$pdo->prepare('UPDATE conversations SET lead_outcome=?,lead_close_reason=?,lead_outcome_note=?,lead_sale_amount=?,lead_sale_date=?,lead_outcome_updated_at=NOW(),lead_outcome_manager_id=? WHERE id=?');$q->execute([$outcome,$reason!==''?$reason:null,$note!==''?$note:null,$amount,$date,$actor>0?$actor:null,$id]);$ok=$q->rowCount()>0;if($ok&&$outcome==='won')MetrikaConversionGoalService::saleOutcome($id,$outcome);if($ok)ModeledConversionOutcomeReceipt::observe($pdo,$id,$outcome,$reason!==''?$reason:null,$actor,$amount,$date);return$ok;
    }

    /** One explicit completion/reopening changes both sales fields or neither. */
    private static function setOutcomeAndStageWithoutTripStorage(int $id,string $outcome,?string $reason,?string $note,int $actor,?string $saleAmount,?string $saleDate,string $stageKey,string $expectedStage): bool
    {
        $values=self::outcomeValues($id,$outcome,$reason,$note,$saleAmount,$saleDate);
        if($values===null||$actor<=0||trim($stageKey)==='')return false;
        [$outcome,$reason,$note,$amount,$date]=$values;$stageKey=trim($stageKey);
        $pdo=ConversationDb::connection();if($pdo->inTransaction())return false;
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT lead_stage_key FROM conversations WHERE id=?'.$suffix);$q->execute([$id]);$previous=$q->fetchColumn();$q->closeCursor();
            if($previous===false||(string)$previous!==$expectedStage){$pdo->rollBack();return false;}
            $q=$pdo->prepare('SELECT is_terminal,is_won FROM lead_stages WHERE stage_key=? AND is_active=1'.$suffix);$q->execute([$stageKey]);$stage=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            $compatible=$stage&&($outcome==='open'?(int)$stage['is_terminal']===0:
                (int)$stage['is_terminal']===1&&((int)$stage['is_won']===1)===($outcome==='won'));
            if(!$compatible){$pdo->rollBack();return false;}
            $q=$pdo->prepare('UPDATE conversations SET lead_stage_key=?,lead_outcome=?,lead_close_reason=?,lead_outcome_note=?,lead_sale_amount=?,lead_sale_date=?,lead_outcome_updated_at=NOW(),lead_outcome_manager_id=? WHERE id=? AND lead_stage_key=?');
            $q->execute([$stageKey,$outcome,$reason!==''?$reason:null,$note!==''?$note:null,$amount,$date,$actor,$id,$expectedStage]);
            $changed=$q->rowCount()>0;$stageChanged=(string)$previous!==$stageKey;
            if(!$changed){
                $q=$pdo->prepare('SELECT lead_stage_key,lead_outcome,lead_close_reason,lead_outcome_note,lead_sale_amount,lead_sale_date,lead_outcome_manager_id FROM conversations WHERE id=?');
                $q->execute([$id]);$stored=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                if(!$stored||$stored['lead_stage_key']!==$stageKey||$stored['lead_outcome']!==$outcome
                    ||$stored['lead_close_reason']!==($reason!==''?$reason:null)||$stored['lead_outcome_note']!==($note!==''?$note:null)
                    ||$stored['lead_sale_amount']!==$amount||$stored['lead_sale_date']!==$date||(int)$stored['lead_outcome_manager_id']!==$actor){
                    $pdo->rollBack();return false;
                }
            }
            if($stageChanged){
                $q=$pdo->prepare('INSERT INTO lead_stage_history (conversation_id,from_stage_key,to_stage_key,changed_by_manager_id) VALUES (?,?,?,?)');
                $q->execute([$id,(string)$previous!==''?$previous:null,$stageKey,$actor]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        // Existing analytics owners observe only the committed business result.
        if($stageChanged)MetrikaConversionGoalService::salesStage($id,$stageKey);
        if($changed&&$outcome==='won')MetrikaConversionGoalService::saleOutcome($id,$outcome);
        if($changed)ModeledConversionOutcomeReceipt::observe($pdo,$id,$outcome,$reason!==''?$reason:null,$actor,$amount,$date);
        return true;
    }

    private static function outcomeValues(int $id,string $outcome,?string $reason,?string $note,?string $saleAmount,?string $saleDate): ?array
    {
        $outcome=trim($outcome);if($id<=0||!array_key_exists($outcome,self::outcomeOptions()))return null;$reason=trim((string)$reason);$note=trim((string)$note);
        if($outcome==='lost'&&!self::closeReasonAllowedForConversation($id,$reason))return null;if($outcome!=='lost')$reason='';if(mb_strlen($note)>500)$note=mb_substr($note,0,500);
        $amount=null;$date=null;
        if($outcome==='won'){
            $rawAmount=trim((string)$saleAmount);if($rawAmount!==''){$rawAmount=str_replace(',','.',$rawAmount);if(!is_numeric($rawAmount))return null;$numeric=(float)$rawAmount;if($numeric<0||$numeric>9999999999.99)return null;$amount=number_format($numeric,2,'.','');}
            $rawDate=trim((string)$saleDate);if($rawDate!==''){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$rawDate))return null;$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$rawDate);if(!$dt||$dt->format('Y-m-d')!==$rawDate)return null;$date=$rawDate;}
        }
        return [$outcome,$reason,$note,$amount,$date];
    }
    public static function conversationSnapshot(int $id): array{return ['stage'=>self::stageForConversation($id),'stage_history'=>self::stageHistoryForConversation($id),'tags'=>self::tagsForConversation($id),'outcome'=>self::outcomeForConversation($id),'outcome_trip'=>self::outcomeTripForConversation($id)];}
    private static function closeReasonAllowedForConversation(int $id,string $reason): bool{if($id<=0||$reason==='')return false;$q=ConversationDb::connection()->prepare('SELECT c.lead_close_reason AS current_reason,r.is_active AS requested_active FROM conversations c LEFT JOIN lead_close_reasons r ON r.reason_key=? WHERE c.id=? LIMIT 1');$q->execute([$reason,$id]);$row=$q->fetch();if(!$row)return false;return(int)($row['requested_active']??0)===1||(string)($row['current_reason']??'')===$reason;}
    private static function currentStageKey(int $id): string{$q=ConversationDb::connection()->prepare('SELECT lead_stage_key FROM conversations WHERE id=? LIMIT 1');$q->execute([$id]);return(string)($q->fetchColumn()?:'');}
}
