<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerPromiseTaskService.php';
require_once __DIR__.'/ManagerTourSelectionService.php';
require_once __DIR__.'/TourOfferCardRenderer.php';
require_once __DIR__.'/LeadTaskService.php';
require_once __DIR__.'/ManagerReplyQueueService.php';

/** Explicit publication intent and proof. Only LeadTaskService changes a task. */
final class ManagerPromiseSelectionService
{
    private const FLAGS=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    private const MAX_BYTES=2097152;
    private const STATES=['armed','completed','cancelled','blocked'];

    public static function catalog(int $actor,int $cid): array
    {
        return self::transaction($actor,$cid,static function(PDO $pdo,array $scope)use($cid):array{
            $profile=ManagerRequestProfileService::forTaskBinding($pdo,$scope['conversation'],0)['profile'];
            $transport=self::transport($pdo,$scope['conversation']);
            $q=$pdo->prepare("SELECT * FROM manager_task_promises WHERE conversation_id=? AND trip_id=? AND kind='send_selection' ORDER BY created_at DESC,promise_id DESC LIMIT 20");
            $q->execute([$cid,$profile['trip_id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$promises=[];
            foreach($rows as $row){
                self::validatePromise($row,$cid);$task=self::task($pdo,$cid,(int)$row['task_id']);
                if($task!==null)$promises[]=['promise_id'=>$row['promise_id'],'kind'=>$row['kind'],'task'=>$task,'source_message_id'=>(int)$row['source_message_id']];
            }
            // Publications have no historical trip pin. They are candidates for
            // the explicit current-trip association, never inferred membership.
            $q=$pdo->prepare('SELECT publication_id FROM manager_tour_selection_publications WHERE conversation_id=? ORDER BY created_at DESC,publication_id DESC LIMIT 20');
            $q->execute([$cid]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();$publications=[];
            foreach($ids as $id){$p=self::publication($pdo,$cid,$id);if($p['binding']!==$transport)continue;
                $publications[]=['publication_id'=>$id,'title'=>$p['selection']['title'],'card_count'=>count($p['cards']),
                    'created_at'=>$p['created_at'],'states'=>array_column($p['cards'],'state')];}
            // An earlier trip may still hold a pre-send publication. Keep that
            // armed intent visible for explicit cancellation, without mixing
            // terminal history from other trips into the current-trip catalog.
            $q=$pdo->prepare("SELECT * FROM manager_promise_selection_intents WHERE conversation_id=? AND (trip_id=? OR state='armed') ORDER BY CASE WHEN state='armed' THEN 0 ELSE 1 END,created_at DESC,intent_id DESC LIMIT 20");
            $q->execute([$cid,$profile['trip_id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$intents=[];
            foreach($rows as $row)$intents[]=self::intentView($pdo,self::validateIntent($row,$cid));
            return ['ok'=>true,'can_edit'=>$scope['can_edit'],'profile'=>$profile,'promises'=>$promises,'publications'=>$publications,'intents'=>$intents];
        });
    }

    public static function preview(int $actor,int $cid,string $promiseId,string $publicationId): array
    {
        if(!self::hex($promiseId,64)||!self::hex($publicationId,32))return self::error('invalid_input');
        return self::transaction($actor,$cid,static fn(PDO $pdo,array $scope):array=>self::previewData($pdo,$scope,$promiseId,$publicationId)['view']);
    }

    public static function attach(int $actor,int $cid,array $input): array
    {
        if(!self::keys($input,['operation_id','promise_id','publication_id','preview_sha256'])
            ||!self::hex($input['operation_id']??null,32)||!self::hex($input['promise_id']??null,64)
            ||!self::hex($input['publication_id']??null,32)||!self::hex($input['preview_sha256']??null,64))return self::error('invalid_input');
        $request=self::hash(['attach',$cid,$input['operation_id'],$input['promise_id'],$input['publication_id'],$input['preview_sha256']]);
        return self::transaction($actor,$cid,static function(PDO $pdo,array $scope)use($actor,$cid,$input,$request):array{
            $receipt=self::receipt($pdo,$actor,$input['operation_id']);
            if($receipt)return self::replay($pdo,$cid,$receipt,$request,'attach');
            if(!$scope['can_edit'])return self::error('forbidden');
            $preview=self::previewData($pdo,$scope,$input['promise_id'],$input['publication_id']);$view=$preview['view'];
            if(!hash_equals($view['preview_sha256'],$input['preview_sha256']))return self::error('preview_changed');
            if(!$view['can_attach'])return self::error($view['reason']);
            $p=$preview['capture'];$id=bin2hex(random_bytes(16));$now=gmdate('Y-m-d H:i:s');
            $q=$pdo->prepare('INSERT INTO manager_promise_selection_intents (intent_id,conversation_id,promise_id,task_id,publication_id,publication_sha256,manifest_sha256,manifest_json,trip_id,profile_version,profile_sha256,profile_context_sha256,source_message_id,source_message_revision,source_author_manager_id,task_revision,scope_json,created_by_manager_id,state,reason,active_promise_id,active_publication_id,evidence_json,created_at,updated_at,completed_at,cancelled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$id,$cid,$input['promise_id'],$view['task']['id'],$input['publication_id'],$p['publication']['draft_hash'],
                self::hash($p['manifest']),self::json($p['manifest']),$view['profile']['trip_id'],$view['profile']['version'],
                $view['profile']['sha256'],$view['profile']['context_sha256'],$view['source']['id'],$view['source']['revision'],
                $view['source']['author_manager_id'],$view['task']['business_revision'],self::json($p['scope']),$actor,'armed',null,
                $input['promise_id'],$input['publication_id'],null,$now,$now,null,null]);
            if($q->rowCount()!==1)throw new RuntimeException('intent_write_unconfirmed');
            self::writeReceipt($pdo,$actor,$cid,$input['operation_id'],'attach',$id,$request);
            return ['ok'=>true,'replayed'=>false,'intent'=>self::intentView($pdo,self::intent($pdo,$cid,$id))];
        });
    }

    public static function cancel(int $actor,int $cid,array $input): array
    {
        if(!self::keys($input,['operation_id','intent_id'])||!self::hex($input['operation_id']??null,32)
            ||!self::hex($input['intent_id']??null,32))return self::error('invalid_input');
        $request=self::hash(['cancel',$cid,$input['operation_id'],$input['intent_id']]);
        return self::transaction($actor,$cid,static function(PDO $pdo,array $scope)use($actor,$cid,$input,$request):array{
            $receipt=self::receipt($pdo,$actor,$input['operation_id']);
            if($receipt)return self::replay($pdo,$cid,$receipt,$request,'cancel');
            if(!$scope['can_edit'])return self::error('forbidden');
            $row=self::intent($pdo,$cid,$input['intent_id']);
            if($row['state']==='armed')self::terminal($pdo,$row,'cancelled',null,null);
            self::writeReceipt($pdo,$actor,$cid,$input['operation_id'],'cancel',$row['intent_id'],$request);
            return ['ok'=>true,'replayed'=>false,'intent'=>self::intentView($pdo,self::intent($pdo,$cid,$row['intent_id']))];
        });
    }

    public static function operation(int $actor,int $cid,string $operationId): array
    {
        if(!self::hex($operationId,32))return self::error('invalid_input');
        return self::transaction($actor,$cid,static function(PDO $pdo,array $scope)use($actor,$cid,$operationId):array{
            $receipt=self::receipt($pdo,$actor,$operationId);
            if(!$receipt)return ['ok'=>true,'found'=>false,'action'=>null,'request_sha256'=>null,'intent'=>null];
            if((int)$receipt['conversation_id']!==$cid)return self::error('operation_mismatch');
            return ['ok'=>true,'found'=>true,'action'=>$receipt['action'],'request_sha256'=>$receipt['request_sha256'],
                'intent'=>self::intentView($pdo,self::receiptIntent($pdo,$cid,$receipt['intent_id']))];
        });
    }

    public static function status(int $actor,int $cid,string $intentId): array
    {
        if(!self::hex($intentId,32))return self::error('invalid_input');
        return self::transaction($actor,$cid,static fn(PDO $pdo,array $scope):array=>[
            'ok'=>true,'intent'=>self::intentView($pdo,self::intent($pdo,$cid,$intentId))]);
    }

    /**
     * Called only by the native delivery owner, after its verified local mirror.
     * That owner already holds original sender -> conversation -> delivery locks.
     * A logical refusal commits the delivery. Storage failure propagates, leaving
     * the durable known native receipt available for zero-creation recovery.
     */
    public static function afterDeliveredInTransaction(PDO $pdo,int $cid,string $publicationId,int $actor,?array $manager): void
    {
        if(!$pdo->inTransaction()||$pdo!==ConversationDb::connection())throw new LogicException('delivery_transaction_required');
        try{
            $q=$pdo->prepare('SELECT * FROM manager_promise_selection_intents WHERE conversation_id=? AND active_publication_id=?'.self::lock($pdo));
            $q->execute([$cid,$publicationId]);$raw=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        }catch(PDOException $e){
            // Only the initial absence of the optional table is legacy-safe.
            // An existing intent, another missing dependency or any write failure
            // must never be hidden from the owning delivery transaction.
            if(self::missingIntentTable($e))return;throw $e;
        }
        if(!$raw)return;$intent=self::validateIntent($raw,$cid);
        if($intent['state']!=='armed'||$intent['publication_id']!==$publicationId)throw new RuntimeException('invalid_active_intent');
        $conversation=$manager?ManagerConversationService::visibleConversation($cid,$actor,false,true):null;
        if(!$conversation||$conversation['status']!=='manager'||(int)$conversation['manager_id']!==$actor
            ||!ManagerConversationAccessPolicy::canEditVisibleConversation($conversation,$manager)){
            self::terminal($pdo,$intent,'blocked','access_changed',null);return;
        }
        try{$transport=self::transport($pdo,$conversation);}
        catch(DomainException $e){self::terminal($pdo,$intent,'blocked',$e->getMessage(),null);return;}
        $expectedScope=$intent['_scope'];$expectedBoundary=$expectedScope['reply_boundary'];unset($expectedScope['reply_boundary']);
        if(self::scopeIdentity($conversation,$transport)!==$expectedScope){
            self::terminal($pdo,$intent,'blocked','scope_changed',null);return;
        }
        // Own outgoing cards advance transcript context but not the canonical
        // customer/request frontier. New customer input needs a fresh decision
        // even while the manually saved profile still has its previous pins.
        if(ManagerReplyQueueService::captureSendBoundary($cid)!==$expectedBoundary){
            self::terminal($pdo,$intent,'blocked','context_changed',null);return;
        }
        $publication=self::publication($pdo,$cid,$publicationId);$manifest=self::manifest($publication);
        if(!hash_equals($intent['publication_sha256'],$publication['draft_hash'])||$publication['binding']!==$transport
            ||self::json($manifest)!==$intent['manifest_json']){
            self::terminal($pdo,$intent,'blocked','publication_changed',null);return;
        }
        try{$pins=self::pins($pdo,$conversation,(int)$intent['source_message_id']);}
        catch(DomainException $e){self::terminal($pdo,$intent,'blocked',$e->getMessage(),null);return;}
        if($pins['profile']['trip_id']!==$intent['trip_id']||$pins['profile']['version']!==(int)$intent['profile_version']
            ||!hash_equals($pins['profile']['sha256'],$intent['profile_sha256'])){
            self::terminal($pdo,$intent,'blocked','profile_changed',null);return;
        }
        if(!hash_equals($pins['source']['revision'],$intent['source_message_revision'])
            ||$pins['source']['author_manager_id']!==(int)$intent['source_author_manager_id']){
            self::terminal($pdo,$intent,'blocked','source_changed',null);return;
        }
        // Outbound cards themselves advance profile context. Only canonical
        // trip/version/content and source evidence remain fulfillment pins.
        foreach($publication['cards'] as $card)if($card['state']!=='delivered')return;
        $evidence=self::evidence($pdo,$publication,$manifest);
        if($evidence===null){self::terminal($pdo,$intent,'blocked','delivery_evidence_changed',null);return;}
        $completion=LeadTaskService::completeForPromise($pdo,$cid,(int)$intent['task_id'],(int)$intent['task_revision'],$actor);
        $proof=['version'=>1,'publication_id'=>$publicationId,'publication_sha256'=>$intent['publication_sha256'],
            'manifest_sha256'=>$intent['manifest_sha256'],'cards'=>$evidence,'actor'=>$actor,
            'task_outcome'=>$completion['outcome'],'task_revision'=>$completion['business_revision']];
        if($completion['outcome']==='applied'){self::terminal($pdo,$intent,'completed',null,$proof);return;}
        $reason=['already_done'=>'task_done','changed'=>'task_changed','missing'=>'task_missing'][$completion['outcome']]??null;
        if($reason===null)throw new RuntimeException('invalid_task_completion_result');
        self::terminal($pdo,$intent,'blocked',$reason,$proof);
    }

    private static function transaction(int $actor,int $cid,callable $work): array
    {
        if($actor<=0||$cid<=0)return self::error('forbidden');$pdo=null;
        try{
            $pdo=ConversationDb::connection();if($pdo->inTransaction())return self::error('storage_unavailable');
            ProjectAccessService::initializeReadOnly();
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();$manager=ManagerAuthService::byId($actor,true);
            if(!$manager){$pdo->rollBack();return self::error('forbidden');}
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.self::lock($pdo));$q->execute([$cid]);$exists=$q->fetchColumn();$q->closeCursor();
            $conversation=$exists?ManagerConversationService::visibleConversation($cid,$actor,false,true):null;
            if(!$conversation||$conversation['project_key']!=='anytour'){$pdo->rollBack();return self::error('forbidden');}
            $scope=['actor'=>$actor,'conversation'=>$conversation,
                'can_edit'=>$conversation['status']!=='closed'&&ManagerConversationAccessPolicy::canEditVisibleConversation($conversation,$manager)];
            $result=$work($pdo,$scope);
            if(empty($result['ok']))$pdo->rollBack();else{$pdo->commit();$result+=['viewer_id'=>$actor,'conversation_id'=>$cid];}
            return $result;
        }catch(DomainException $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();return self::error($e->getMessage());}
        catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();return self::error('storage_unavailable');}
    }

    private static function previewData(PDO $pdo,array $scope,string $promiseId,string $publicationId): array
    {
        $c=$scope['conversation'];$cid=(int)$c['id'];
        $scope['transport']=self::transport($pdo,$c);
        $q=$pdo->prepare('SELECT * FROM manager_task_promises WHERE conversation_id=? AND promise_id=?'.self::lock($pdo));
        $q->execute([$cid,$promiseId]);$promise=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$promise)throw new DomainException('not_found');self::validatePromise($promise,$cid);
        $publication=self::publication($pdo,$cid,$publicationId);$manifest=self::manifest($publication);
        $pins=self::pins($pdo,$c,(int)$promise['source_message_id']);$profile=$pins['profile'];$source=$pins['source'];
        $task=self::task($pdo,$cid,(int)$promise['task_id']);if(!$task)throw new DomainException('task_missing');
        $q=$pdo->prepare('SELECT * FROM manager_promise_selection_intents WHERE active_promise_id=? OR active_publication_id=? ORDER BY intent_id LIMIT 2'.self::lock($pdo));
        $q->execute([$promiseId,$publicationId]);$active=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($active as &$row)$row=self::validateIntent($row,$cid);unset($row);
        $reason=null;
        if(!$scope['can_edit'])$reason='forbidden';
        elseif($promise['kind']!=='send_selection')$reason='unsupported_promise';
        elseif($task['status']!=='open')$reason='task_closed';
        elseif(json_decode($promise['scope_json'],true,16,JSON_THROW_ON_ERROR)!==self::promiseIdentity($c)||$publication['binding']!==$scope['transport'])$reason='scope_changed';
        elseif($profile['trip_id']!==$promise['trip_id']||$profile['version']!==(int)$promise['profile_version']
            ||!hash_equals($profile['sha256'],$promise['profile_sha256']))$reason='profile_changed';
        elseif(!hash_equals($source['revision'],$promise['source_message_revision'])||$source['author_manager_id']!==(int)$promise['source_author_manager_id'])$reason='source_changed';
        elseif($active)$reason=count($active)===1&&$active[0]['promise_id']===$promiseId&&$active[0]['publication_id']===$publicationId?'already_attached':'intent_conflict';
        elseif($publication['duplicate_cards'])$reason='duplicate_cards';
        else foreach($publication['cards'] as $row)if(!in_array($row['state'],['pending','rejected'],true)){$reason='delivery_started';break;}
        $identity=self::scopeIdentity($c,$scope['transport']);
        $identity['reply_boundary']=ManagerReplyQueueService::captureSendBoundary($cid);
        $hash=self::hash(['version'=>1,'actor'=>$scope['actor'],'scope'=>$identity,'promise'=>$promise,
            'task'=>$task,'profile'=>[$profile['trip_id'],$profile['version'],$profile['sha256'],$profile['context_sha256']],
            'source'=>[$source['id'],$source['revision'],$source['author_manager_id']],
            'publication'=>[$publicationId,$publication['draft_hash'],self::hash($manifest),array_column($publication['cards'],'state')],
            'active'=>array_column($active,'intent_id')]);
        $cards=[];foreach($manifest as $i=>$card)$cards[]=['index'=>$i,'offer_id'=>$card['offer_id'],'state'=>$publication['cards'][$i]['state'],
            'text'=>TourOfferCardRenderer::render($card['snapshot'],$card['presentation'],$card['callback_data'])['plain_text'],'snapshot'=>$card['snapshot']];
        return ['view'=>['ok'=>true,'preview_sha256'=>$hash,'can_attach'=>$reason===null,'reason'=>$reason,
            'promise'=>['promise_id'=>$promiseId,'kind'=>$promise['kind'],'deadline'=>json_decode($promise['deadline_json'],true,8,JSON_THROW_ON_ERROR)],
            'task'=>$task,'source'=>$source,'profile'=>$profile,'publication'=>['publication_id'=>$publicationId,
                'title'=>$publication['selection']['title'],'card_count'=>count($cards),'cards'=>$cards],
            'intent'=>$active?self::intentView($pdo,$active[0]):null],
            'capture'=>['publication'=>$publication,'manifest'=>$manifest,'scope'=>$identity]];
    }

    private static function pins(PDO $pdo,array $conversation,int $sourceId): array
    {
        $pins=ManagerRequestProfileService::forTaskBinding($pdo,$conversation,$sourceId);$source=$pins['source'];
        if(!$source||$source['unavailable']||$sourceId<$pins['profile']['boundary_message_id'])throw new DomainException('source_unavailable');
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $q=$pdo->prepare('SELECT direction,sender_type,sender_id,channel,external_message_id,SUBSTR(CAST(metadata_json AS '.$binary.'),1,8193) AS metadata FROM messages WHERE conversation_id=? AND id=?'.self::lock($pdo));
        $q->execute([(int)$conversation['id'],$sourceId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $evidence=ManagerPromiseTaskService::sourceEvidence($row?:[],$conversation,$source,true);
        if(!$evidence)throw new DomainException('source_unavailable');
        $q=$pdo->prepare('SELECT id FROM managers WHERE id=?');$q->execute([$evidence['author_manager_id']]);$author=$q->fetchColumn();$q->closeCursor();
        if(!$author)throw new DomainException('source_unavailable');$pins['source']=$evidence;return $pins;
    }

    private static function transport(PDO $pdo,array $c): array
    {
        if(!in_array($c['channel'],['max','telegram'],true)||$c['project_key']!=='anytour')throw new DomainException('unsupported_channel');
        $q=$pdo->prepare('SELECT cc.external_user_id,cc.external_chat_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=?');
        $q->execute([(int)$c['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row||(string)$row['external_chat_id']!==(string)$c['external_chat_id']||trim((string)$row['external_user_id'])==='')throw new DomainException('scope_changed');
        return ['project_key'=>(string)$c['project_key'],'channel'=>(string)$c['channel'],
            'external_chat_id'=>(string)$c['external_chat_id'],'external_user_id'=>(string)$row['external_user_id']];
    }

    private static function task(PDO $pdo,int $cid,int $id): ?array
    {
        $q=$pdo->prepare('SELECT id,conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id,business_revision FROM lead_tasks WHERE conversation_id=? AND id=?'.self::lock($pdo));
        $q->execute([$cid,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$row)return null;
        if(!in_array($row['status'],['open','done'],true)||filter_var($row['business_revision'],FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX-1]])===false)throw new RuntimeException('invalid_task_storage');
        foreach(['id','conversation_id','business_revision'] as $key)$row[$key]=(int)$row[$key];
        $row['is_pinned']=(bool)$row['is_pinned'];$row['assigned_manager_id']=$row['assigned_manager_id']===null?null:(int)$row['assigned_manager_id'];return $row;
    }

    private static function scopeIdentity(array $c,array $transport): array
    {
        return ['conversation_id'=>(int)$c['id'],'project_key'=>(string)$c['project_key'],'customer_id'=>(int)$c['customer_id'],
            'source_id'=>(int)($c['source_id']??0),
            'manager_id'=>(int)($c['manager_id']??0),'status'=>(string)$c['status'],'transport'=>$transport];
    }
    private static function promiseIdentity(array $c): array
    {return ['conversation_id'=>(int)$c['id'],'project_key'=>(string)$c['project_key'],'customer_id'=>(int)$c['customer_id'],
        'channel'=>(string)$c['channel'],'external_chat_id'=>(string)$c['external_chat_id']];}
    private static function validatePromise(array $row,int $cid): void
    {
        if((int)$row['conversation_id']!==$cid||!self::hex($row['promise_id'],64)||!self::hex($row['trip_id'],32)
            ||!in_array($row['kind'],['send_selection','send_calculation'],true)||!self::hex($row['source_message_revision'],64)
            ||!self::hex($row['profile_sha256'],64)||!self::hex($row['profile_context_sha256'],64)
            ||(int)$row['profile_version']<1||(int)$row['source_message_id']<1||(int)$row['source_author_manager_id']<1
            ||(int)$row['task_id']<1||strlen($row['scope_json'])>2048||strlen($row['deadline_json'])>512
            ||$row['promise_id']!==self::hash(['anytour',$cid,$row['trip_id'],$row['kind'],(int)$row['source_message_id']]))throw new RuntimeException('invalid_promise_storage');
    }

    /** Read and validate the immutable owner record, never prepare a new one. */
    private static function publication(PDO $pdo,int $cid,string $id): array
    {
        $q=$pdo->prepare('SELECT * FROM manager_tour_selection_publications WHERE conversation_id=? AND publication_id=?'.self::lock($pdo));
        $q->execute([$cid,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row)throw new DomainException('not_found');
        if(!self::hex($row['publication_id'],32)||!self::hex($row['draft_hash'],64)||(int)$row['draft_version']<1
            ||strlen($row['binding_json'])>4096||strlen($row['selection_json'])>self::MAX_BYTES)throw new RuntimeException('invalid_publication_storage');
        $binding=json_decode($row['binding_json'],true,16,JSON_THROW_ON_ERROR);$selection=json_decode($row['selection_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($binding)||!self::keys($binding,['project_key','channel','external_chat_id','external_user_id'])
            ||!is_array($selection)||!is_string($selection['title']??null)||!is_array($selection['offers']??null)
            ||!array_is_list($selection['offers'])||count($selection['offers'])<1||count($selection['offers'])>20
            ||!hash_equals($row['draft_hash'],hash('sha256',self::json($binding).self::json($selection))))throw new RuntimeException('invalid_publication_storage');
        foreach($binding as $value)if(!is_string($value)||$value===''||strlen($value)>255)throw new RuntimeException('invalid_publication_binding');
        $q=$pdo->prepare('SELECT c.card_index,c.offer_id,c.callback_data,d.conversation_id,d.offer_hash,d.callback_data AS delivery_callback,d.state,d.receipt_json FROM manager_tour_selection_cards c JOIN manager_tour_selection_deliveries d ON d.offer_id=c.offer_id WHERE c.publication_id=? ORDER BY c.card_index LIMIT 21'.self::lock($pdo));
        $q->execute([$id]);$cards=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($cards)!==count($selection['offers']))throw new RuntimeException('incomplete_publication');$seen=[];$duplicate=false;
        foreach($cards as $i=>&$card){
            $offer=$selection['offers'][$i];
            if((int)$card['card_index']!==$i||(int)$card['offer_id']<1
                ||(int)$card['conversation_id']!==$cid||$card['callback_data']!==$card['delivery_callback']
                ||!in_array($card['state'],['pending','reserved','known','delivered','unknown','rejected'],true)
                ||!is_array($offer)||!is_array($offer['snapshot']??null)||!is_array($offer['presentation']??null)
                ||!self::hex($card['offer_hash'],64)||!hash_equals($card['offer_hash'],self::hash([
                    'binding'=>$binding,'snapshot'=>$offer['snapshot'],'presentation'=>$offer['presentation']])))throw new RuntimeException('invalid_publication_card');
            TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],$card['callback_data']);
            if(isset($seen[(int)$card['offer_id']]))$duplicate=true;
            $seen[(int)$card['offer_id']]=true;$card['card_index']=$i;$card['offer_id']=(int)$card['offer_id'];
        }unset($card);
        $row['binding']=$binding;$row['selection']=$selection;$row['cards']=$cards;$row['duplicate_cards']=$duplicate;return $row;
    }

    private static function manifest(array $publication): array
    {
        $manifest=[];foreach($publication['cards'] as $i=>$card){$offer=$publication['selection']['offers'][$i];
            $text=TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],$card['callback_data'])['plain_text'];
            $manifest[]=['index'=>$i,'offer_id'=>$card['offer_id'],'offer_hash'=>$card['offer_hash'],'callback_data'=>$card['callback_data'],
                'snapshot'=>$offer['snapshot'],'presentation'=>$offer['presentation'],'text_sha256'=>hash('sha256',$text)];}
        if(strlen(self::json($manifest))>self::MAX_BYTES)throw new RuntimeException('manifest_too_large');return $manifest;
    }

    /** Exact local proof of the already verified native creation for every card. */
    private static function evidence(PDO $pdo,array $publication,array $manifest): ?array
    {
        $cid=(int)$publication['conversation_id'];$channel=$publication['binding']['channel'];$proof=[];
        foreach($manifest as $i=>$expected){
            $raw=$publication['cards'][$i]['receipt_json'];if(strlen($raw)>self::MAX_BYTES)return null;
            $receipt=json_decode($raw,true,64);$mid=$receipt['native']['mid']??null;$chat=$receipt['identity']['chat']??null;
            $sender=$receipt['actor']??null;
            if(!is_array($receipt)||!is_int($sender)||$sender<=0||!is_string($mid)||$mid===''||strlen($mid)>255
                ||!is_string($chat)||$chat===''||strlen($chat)>255
                ||!is_array($receipt['image_hashes']??null)||!array_is_list($receipt['image_hashes'])
                ||count($receipt['image_hashes'])!==count($expected['presentation']['photos'])
                ||($receipt['identity']['user']??null)!==$publication['binding']['external_user_id']
                ||($channel==='telegram'&&$chat!==$publication['binding']['external_user_id']))return null;
            foreach($receipt['image_hashes'] as $hash)if(!self::hex($hash,64))return null;
            $q=$pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE id=? AND conversation_id=? AND event_type='tour_offer_prepared'");
            $q->execute([$expected['offer_id'],$cid]);$prepared=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            $payload=$prepared?json_decode($prepared['payload_json'],true,64):null;
            $token=substr($expected['callback_data'],strrpos($expected['callback_data'],'_')+1);
            if(!is_array($payload)||($payload['schema']??null)!==1||($payload['snapshot']??null)!==$expected['snapshot']
                ||($payload['binding']??null)!==$publication['binding']||($payload['token_hash']??null)!==hash('sha256',$token))return null;
            $q=$pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type='tour_offer_delivered' AND CAST(JSON_EXTRACT(payload_json,'$.offer_id') AS CHAR)=? ORDER BY id LIMIT 2");
            $q->execute([$cid,(string)$expected['offer_id']]);$events=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            if(count($events)!==1||json_decode($events[0]['payload_json'],true,16)!==[
                'offer_id'=>$expected['offer_id'],'native_mid'=>$mid,'native_chat_id'=>$chat])return null;
            $q=$pdo->prepare('SELECT id,direction,sender_type,sender_id,text,metadata_json FROM messages WHERE conversation_id=? AND channel=? AND external_message_id=? ORDER BY id LIMIT 2');
            $q->execute([$cid,$channel,$mid]);$messages=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            if(count($messages)!==1)return null;$m=$messages[0];$metadata=json_decode($m['metadata_json'],true,32);
            $text=TourOfferCardRenderer::render($expected['snapshot'],$expected['presentation'],$expected['callback_data'])['plain_text'];
            if($m['direction']!=='outbound'||$m['sender_type']!=='manager'||(string)$m['sender_id']!==(string)$sender
                ||$m['text']!==htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')||!is_array($metadata)
                ||($metadata['project_key']??null)!==$publication['binding']['project_key']
                ||($metadata['tour_selection']??null)!==$publication['publication_id']
                ||($metadata['tour_offer_id']??null)!==$expected['offer_id'])return null;
            $proof[]=['index'=>$i,'offer_id'=>$expected['offer_id'],'native_mid'=>$mid,'native_chat_id'=>$chat,
                'delivery_event_id'=>(int)$events[0]['id'],'transcript_message_id'=>(int)$m['id'],
                'transcript_sha256'=>hash('sha256',$m['text'])];
        }
        return $proof;
    }

    private static function intent(PDO $pdo,int $cid,string $id): array
    {
        $q=$pdo->prepare('SELECT * FROM manager_promise_selection_intents WHERE conversation_id=? AND intent_id=?'.self::lock($pdo));
        $q->execute([$cid,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row)throw new DomainException('not_found');return self::validateIntent($row,$cid);
    }

    private static function validateIntent(array $row,int $cid): array
    {
        foreach(['intent_id'=>32,'promise_id'=>64,'publication_id'=>32,'publication_sha256'=>64,'manifest_sha256'=>64,
            'trip_id'=>32,'profile_sha256'=>64,'profile_context_sha256'=>64,'source_message_revision'=>64] as $key=>$length)
            if(!self::hex($row[$key]??null,$length))throw new RuntimeException('invalid_intent_storage');
        if((int)$row['conversation_id']!==$cid||$cid<=0||!in_array($row['state'],self::STATES,true)
            ||(int)$row['task_id']<=0||(int)$row['profile_version']<1||filter_var($row['task_revision'],FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX-1]])===false
            ||(int)$row['source_message_id']<1||(int)$row['source_author_manager_id']<1||(int)$row['created_by_manager_id']<1
            ||strlen($row['manifest_json'])>self::MAX_BYTES||strlen($row['scope_json'])>4096
            ||!hash_equals($row['manifest_sha256'],hash('sha256',$row['manifest_json'])))throw new RuntimeException('invalid_intent_storage');
        $manifest=json_decode($row['manifest_json'],true,64,JSON_THROW_ON_ERROR);$scope=json_decode($row['scope_json'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($manifest)||!array_is_list($manifest)||count($manifest)<1||count($manifest)>20||!is_array($scope)
            ||($scope['conversation_id']??null)!==$cid||($scope['project_key']??null)!=='anytour'
            ||!is_array($scope['transport']??null)||!is_array($scope['reply_boundary']??null)
            ||!self::keys($scope['reply_boundary'],['version','customer_message_id','request_event_id'])
            ||$scope['reply_boundary']['version']!==1||!is_int($scope['reply_boundary']['customer_message_id'])
            ||$scope['reply_boundary']['customer_message_id']<0||!is_int($scope['reply_boundary']['request_event_id'])
            ||$scope['reply_boundary']['request_event_id']<0)throw new RuntimeException('invalid_intent_storage');
        $seen=[];foreach($manifest as $i=>$card){
            if(!is_array($card)||!self::keys($card,['index','offer_id','offer_hash','callback_data','snapshot','presentation','text_sha256'])
                ||$card['index']!==$i||!is_int($card['offer_id'])||$card['offer_id']<=0||isset($seen[$card['offer_id']])
                ||!self::hex($card['offer_hash'],64)||!self::hex($card['text_sha256'],64)
                ||!is_string($card['callback_data'])||!is_array($card['snapshot'])||!is_array($card['presentation']))throw new RuntimeException('invalid_intent_manifest');
            $seen[$card['offer_id']]=true;
        }
        if($row['state']==='armed'){
            if($row['active_promise_id']!==$row['promise_id']||$row['active_publication_id']!==$row['publication_id']
                ||$row['reason']!==null||$row['completed_at']!==null||$row['cancelled_at']!==null||$row['evidence_json']!==null)throw new RuntimeException('invalid_active_intent');
        }elseif($row['active_promise_id']!==null||$row['active_publication_id']!==null
            ||($row['state']==='completed'&&($row['completed_at']===null||$row['reason']!==null||$row['evidence_json']===null))
            ||($row['state']==='cancelled'&&($row['cancelled_at']===null||$row['reason']!==null))
            ||($row['state']==='blocked'&&(!is_string($row['reason'])||$row['reason']===''||strlen($row['reason'])>40)))throw new RuntimeException('invalid_terminal_intent');
        if($row['evidence_json']!==null){
            if(strlen($row['evidence_json'])>65536)throw new RuntimeException('invalid_intent_evidence');
            $e=json_decode($row['evidence_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($e)||($e['version']??null)!==1||($e['publication_id']??null)!==$row['publication_id']
                ||($e['manifest_sha256']??null)!==$row['manifest_sha256']||($e['publication_sha256']??null)!==$row['publication_sha256']
                ||!is_array($e['cards']??null)||count($e['cards'])!==count($manifest))throw new RuntimeException('invalid_intent_evidence');
        }
        $row['_manifest']=$manifest;$row['_scope']=$scope;return $row;
    }

    private static function intentView(PDO $pdo,array $row): array
    {
        $ids=array_column($row['_manifest'],'offer_id');$q=$pdo->prepare("SELECT COUNT(*) FROM manager_tour_selection_deliveries WHERE conversation_id=? AND state='delivered' AND offer_id IN (".implode(',',array_fill(0,count($ids),'?')).')');
        $q->execute([(int)$row['conversation_id'],...$ids]);$delivered=(int)$q->fetchColumn();$q->closeCursor();
        return ['intent_id'=>$row['intent_id'],'promise_id'=>$row['promise_id'],'publication_id'=>$row['publication_id'],
            'task_id'=>(int)$row['task_id'],'state'=>$row['state'],'reason'=>$row['reason'],'created_at'=>$row['created_at'],
            'completed_at'=>$row['completed_at'],'cancelled_at'=>$row['cancelled_at'],'card_count'=>count($ids),'delivered_count'=>$delivered];
    }

    private static function terminal(PDO $pdo,array $intent,string $state,?string $reason,?array $evidence): void
    {
        if(!in_array($state,['completed','cancelled','blocked'],true)||($reason!==null&&strlen($reason)>40))throw new LogicException('invalid_intent_transition');
        $now=gmdate('Y-m-d H:i:s');$encoded=$evidence===null?null:self::json($evidence);
        if($encoded!==null&&strlen($encoded)>65536)throw new RuntimeException('completion_evidence_too_large');
        $q=$pdo->prepare("UPDATE manager_promise_selection_intents SET state=?,reason=?,active_promise_id=NULL,active_publication_id=NULL,evidence_json=?,updated_at=?,completed_at=?,cancelled_at=? WHERE intent_id=? AND conversation_id=? AND state='armed'");
        $q->execute([$state,$reason,$encoded,$now,$state==='completed'?$now:null,$state==='cancelled'?$now:null,
            $intent['intent_id'],(int)$intent['conversation_id']]);
        if($q->rowCount()!==1)throw new RuntimeException('intent_transition_unconfirmed');
    }

    private static function receipt(PDO $pdo,int $actor,string $operation): ?array
    {
        $q=$pdo->prepare('SELECT conversation_id,action,intent_id,request_sha256 FROM manager_promise_selection_operations WHERE manager_id=? AND operation_id=?'.self::lock($pdo));
        $q->execute([$actor,$operation]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$row)return null;
        if((int)$row['conversation_id']<=0||!in_array($row['action'],['attach','cancel'],true)
            ||!self::hex($row['intent_id'],32)||!self::hex($row['request_sha256'],64))throw new RuntimeException('invalid_selection_operation');return $row;
    }
    private static function writeReceipt(PDO $pdo,int $actor,int $cid,string $operation,string $action,string $intent,string $request): void
    {
        $q=$pdo->prepare('INSERT INTO manager_promise_selection_operations (manager_id,operation_id,conversation_id,action,intent_id,request_sha256,created_at) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$actor,$operation,$cid,$action,$intent,$request,gmdate('Y-m-d H:i:s')]);
        if($q->rowCount()!==1)throw new RuntimeException('operation_write_unconfirmed');
    }
    private static function replay(PDO $pdo,int $cid,array $receipt,string $request,string $action): array
    {
        if((int)$receipt['conversation_id']!==$cid||$receipt['action']!==$action||!hash_equals($receipt['request_sha256'],$request))return self::error('operation_mismatch');
        return ['ok'=>true,'replayed'=>true,'intent'=>self::intentView($pdo,self::receiptIntent($pdo,$cid,$receipt['intent_id']))];
    }
    private static function receiptIntent(PDO $pdo,int $cid,string $id): array
    {
        try{return self::intent($pdo,$cid,$id);}
        catch(DomainException $e){throw new RuntimeException('operation_intent_missing',0,$e);}
    }
    private static function missingIntentTable(PDOException $e): bool
    {
        $code=(string)($e->errorInfo[0]??$e->getCode());$message=$e->getMessage();
        return ($code==='42S02'&&str_contains($message,'manager_promise_selection_intents'))
            ||($code==='HY000'&&str_contains($message,'no such table: manager_promise_selection_intents'));
    }
    private static function json(array $value): string{return json_encode($value,self::FLAGS);}
    private static function hash(array $value): string{return hash('sha256',self::json($value));}
    private static function keys(array $value,array $keys): bool{return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];}
    private static function hex(mixed $value,int $length): bool{return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;}
    private static function lock(PDO $pdo): string{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private static function error(string $code): array{return ['ok'=>false,'error'=>$code];}
}
