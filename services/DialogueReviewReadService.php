<?php
declare(strict_types=1);

require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ConversationRecordClock.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';
require_once __DIR__.'/ManagerSelectionLinks.php';
require_once __DIR__.'/ManagerCallbackPresentationService.php';

/** Private, bounded projections of existing live stores. No collector or business-state owner. */
final class DialogueReviewReadService
{
    public const MAX_OUTPUT_BYTES=65536;
    public const CURSOR_TTL=1200;
    public const MAX_MESSAGE_BYTES=32768;
    public const MAX_PAGE_SOURCE_BYTES=32768;
    public const MAX_PROFILE_BYTES=131072;
    public const MAX_SELECTION_REFERENCES_PER_MESSAGE=2;
    public const MAX_SELECTION_REFERENCES_PER_PAGE=20;
    private const MAX_SECONDS=8.0;
    private bool $mysql;
    private float $deadline=0.0;
    private array $boundScopes=[];
    private int $sourceBytes=0;
    private int $selectionReferenceCount=0;

    public function __construct(private PDO $pdo,private $clock=null,private $keyProvider=null,private $selectionSourceAuthorize=null,private bool $historyAudit=false,private $conversionTraceAuthorize=null)
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['mysql','sqlite'],true))throw new InvalidArgumentException('dialogue_review_driver');
        $this->mysql=$driver==='mysql';
    }

    public function inventory(int $admin,string $connection,array $args=[]):array
    {
        self::arguments($args,['limit','cursor'],50);
        return $this->read($admin,$connection,function(string $key)use($admin,$connection,$args):array{
            $now=$this->now();$limit=$args['limit']??25;
            $state=isset($args['cursor'])?$this->decode($args['cursor'],'inventory',$key,$admin,$connection):null;
            $kind=$state['k']??'inventory';$newest=$kind==='inventory_newest';
            [$from,$where]=$this->nativeScope();
            $summary=$this->q('SELECT COUNT(*) AS total,COALESCE(MAX(c.id),0) AS upper_id FROM '.$from.' WHERE '.$where,['anytour'])->fetch(PDO::FETCH_ASSOC);
            $fence=$state['f']??(string)$summary['upper_id'];$after=$state['a']??'0';$started=$state['t']??$now;
            // A cursor is a continuation hint, never a license to invent a future cutoff.
            if(self::greater($after,$fence))self::fail('stale_cursor');
            if($state!==null){
                $maximum=(string)$this->q('SELECT COALESCE(MAX(id),0) FROM conversations WHERE project_key=?',['anytour'])->fetchColumn();
                if(self::greater($fence,$maximum))self::fail('stale_cursor');
            }
            if($newest){
                $rows=$this->nativeRows(' AND c.id<=?'.($after!=='0'?' AND c.id<?':''),
                    $after!=='0'?[$fence,$after]:[$fence],$limit+1,true);
            }else $rows=$this->nativeRows(' AND c.id>? AND c.id<=?',[$after,$fence],$limit+1);
            $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
            $markers=$this->activityMarkers($rows);$dialogues=[];
            foreach($rows as$row){
                $id=(string)$row['id'];$identity=$this->identity($row);$this->boundScopes[$id]=$identity;
                $mark=$markers[$id]??['latest_message_id'=>'0','latest_event_id'=>'0','recorded_at_unix'=>null];
                $dialogues[]=[
                    'dialogue_id'=>$id,'dialogue_ref'=>$this->dialogueRef($row,$key,$admin,$connection),
                    'platform'=>$row['channel'],'status'=>$row['status'],'assigned'=>$row['manager_id']!==null,
                    'latest_message_id'=>$mark['latest_message_id'],'latest_event_id'=>$mark['latest_event_id'],
                    'last_message_clock'=>$this->recordedClock($mark['recorded_at_unix'],$row['last_message_at']),
                    'change_hint'=>hash('sha256',self::json([$identity,$row['status'],$row['manager_id'],$row['source_id'],
                        $row['updated_at'],$mark['latest_message_id'],$mark['latest_event_id'],$mark['recorded_at_unix']])),
                ];
                $after=$id;
            }
            $result=$this->base('inventory',$now)+[
                'snapshot'=>['inventory_started_at'=>gmdate('Y-m-d\TH:i:s\Z',$started),'conversation_id_fence'=>$fence,
                    'consistency'=>'per_request_current_state_not_frozen_cohort'],
                'dialogues'=>$dialogues,'returned'=>count($dialogues),'total_open_at_this_read'=>(int)$summary['total'],
                'has_more'=>$more,'next_cursor'=>$more?$this->encode(['v'=>1,'k'=>$kind,'f'=>$fence,'a'=>$after,'t'=>$started,'e'=>$now+self::CURSOR_TTL],$key,$admin,$connection):null,
                'coverage'=>['population'=>$this->historyAudit?'retained_authorized_anytour_max_telegram_including_closed':'current_open_authorized_anytour_max_telegram_including_ai',
                    'inventory_traversal_exhausted_within_id_fence'=>!$more,'new_or_reopened_rows_may_change_between_pages'=>true,
                    'change_hint_is_complete_edit_revision'=>false,'deduplicate_using_returned_message_revisions'=>true,
                    'transcripts_read'=>false,'semantic_review_complete'=>false],
            ];
            if(!$this->historyAudit){
                // A fresh start is distinct from next_cursor, which continues this pass.
                $result['newest_open']=[
                    'start_cursor'=>$this->encode(['v'=>1,'k'=>'inventory_newest','f'=>(string)$summary['upper_id'],
                        'a'=>'0','t'=>$now,'e'=>$now+self::CURSOR_TTL],$key,$admin,$connection),
                    'order'=>'conversation_id_desc','latest_activity_inventory'=>false,'all_today_coverage'=>false,
                ];
            }
            if($newest)$result['coverage']+=['order'=>'conversation_id_desc',
                'latest_activity_inventory'=>false,'all_today_coverage'=>false];
            return$result;
        });
    }

    public function context(int $admin,string $connection,array $args):array
    {
        self::arguments($args,['dialogue_ref','limit','cursor'],100);
        if(!isset($args['dialogue_ref']))self::fail('invalid_arguments');
        // This is a server-supplied, live dual-grant verifier, never a tool input.
        $selectionSources=is_callable($this->selectionSourceAuthorize);
        $conversionTrace=!$this->historyAudit&&is_callable($this->conversionTraceAuthorize);
        return $this->read($admin,$connection,function(string $key)use($admin,$connection,$args,$selectionSources):array{
            $now=$this->now();$limit=$args['limit']??50;
            $ref=$this->decode($args['dialogue_ref'],'dialogue',$key,$admin,$connection);
            $rows=$this->nativeRows(' AND c.id=?',[$ref['d']],1);
            if(!$rows)self::fail('dialogue_unavailable');
            $row=$rows[0];$identity=$this->identity($row);
            if(!hash_equals($identity,$ref['r']))self::fail('source_changed');
            $this->boundScopes[(string)$row['id']]=$identity;
            $state=isset($args['cursor'])?$this->decode($args['cursor'],'context',$key,$admin,$connection):null;
            if($state!==null&&($state['d']!==$ref['d']||!hash_equals($state['r'],$identity)))self::fail('stale_cursor');
            $started=$state['t']??$now;
            $maximum=(string)$this->q('SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id=? AND channel=?',[$row['id'],$row['channel']])->fetchColumn();
            $fence=$state['m']??$maximum;
            if(self::greater($fence,$maximum))self::fail('stale_cursor');
            $before=$state['b']??'0';
            if($before!=='0'&&self::greater($before,$fence))self::fail('stale_cursor');
            $profile=$this->profile($row,$state===null?false:$state['p'],$started);
            $profileId=$profile['profile_id']??null;
            if(($profile['availability']??null)==='not_saved')$profileId='0';
            $textBytes=$this->length('m.text');$metaBytes=$this->length('m.metadata_json');
            $where='m.conversation_id=? AND m.channel=? AND m.id<=?'.($before!=='0'?' AND m.id<?':'');
            $bind=[$row['id'],$row['channel'],$fence];if($before!=='0')$bind[]=$before;
            // The sentinel is metadata only. Never materialize a MEDIUMTEXT lookahead just to find another page.
            $metadata=$this->q('SELECT m.id,m.direction,m.sender_type,m.created_at,'.$textBytes.' AS text_bytes,'.$metaBytes.' AS metadata_bytes'
                .' FROM messages m WHERE '.$where.' ORDER BY m.id DESC LIMIT '.($limit+1),$bind)->fetchAll(PDO::FETCH_ASSOC);
            $more=count($metadata)>$limit;$metadata=array_slice($metadata,0,$limit);$selected=[];$payloadBytes=0;
            foreach($metadata as$meta){
                $bytes=(int)$meta['text_bytes']+(int)$meta['metadata_bytes'];
                $status=$bytes>self::MAX_MESSAGE_BYTES?'record_exceeds_page_budget':'available';
                if($status==='available'&&$payloadBytes+$bytes>self::MAX_PAGE_SOURCE_BYTES){$more=true;break;}
                $meta['read_status']=$status;$selected[]=$meta;if($status==='available')$payloadBytes+=$bytes;
            }
            if(count($selected)<count($metadata))$more=true;
            $payload=[];$ids=[];
            foreach($selected as$meta)if($meta['read_status']==='available')$ids[]=(string)$meta['id'];
            if($ids){
                $guard='('.$textBytes.'+'.$metaBytes.')<='.self::MAX_MESSAGE_BYTES;
                $data=$this->q('SELECT m.id,CASE WHEN '.$guard.' THEN m.text ELSE NULL END AS text,'
                    .'CASE WHEN '.$guard.' THEN m.metadata_json ELSE NULL END AS metadata_json FROM messages m WHERE m.conversation_id=? AND m.channel=?'
                    .' AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$row['id'],$row['channel'],...$ids])->fetchAll(PDO::FETCH_ASSOC);
                foreach($data as$item)$payload[(string)$item['id']]=$item;
            }
            $messages=[];
            foreach($selected as$meta){
                $raw=$payload[(string)$meta['id']]??null;
                if($meta['read_status']==='available'&&($raw===null
                    ||strlen((string)$raw['text'])!==(int)$meta['text_bytes']||strlen((string)$raw['metadata_json'])!==(int)$meta['metadata_bytes']))self::fail('source_changed');
                $messages[]=$this->projectMessage($meta,$raw,$selectionSources);
            }
            $this->sourceBytes+=$payloadBytes;
            $readCount=count($messages);$returned=$messages;
            $make=function(array $page,bool $hasMore,array $saved)use($now,$row,$ref,$identity,$fence,$before,$started,$profileId,$key,$admin,$connection,$readCount):array{
                $last=$page?(string)$page[count($page)-1]['message_id']:$before;
                $next=$hasMore&&$page?$this->encode(['v'=>1,'k'=>'context','d'=>$ref['d'],'r'=>$identity,'m'=>$fence,'b'=>$last,
                    'p'=>$profileId,'t'=>$started,'e'=>$now+self::CURSOR_TTL],$key,$admin,$connection):null;
                $ordered=array_reverse($page);$revisions=array_map(static fn(array$m):array=>[$m['message_id'],$m['revision'],$m['read_status']],$ordered);
                return $this->base('context',$now)+[
                    'dialogue_id'=>(string)$row['id'],'dialogue_ref'=>$this->dialogueRef($row,$key,$admin,$connection),
                    'platform'=>$row['channel'],'status'=>$row['status'],'assigned'=>$row['manager_id']!==null,
                    'snapshot'=>['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$started),'message_id_fence'=>$fence,
                        'mutable_rows_may_change_between_pages'=>true,'clock_basis'=>'validated_recording_receipt_or_unknown'],
                    'messages'=>$ordered,'saved_profile'=>$saved,
                    'context_revision'=>hash('sha256',self::json([$identity,$row['status'],$row['manager_id'],$row['source_id'],$fence,$revisions,$saved])),
                    'revision_scope'=>'returned_message_page_and_selected_saved_profile_not_whole_dialogue',
                    'returned'=>count($page),'read_but_not_returned_records'=>$readCount-count($page),'page_source_bytes'=>$this->sourceBytes,
                    'has_more'=>$hasMore,'next_cursor'=>$next,
                    'coverage'=>['older_messages_remaining'=>$hasMore,'history_traversal_exhausted_within_id_fence'=>!$hasMore,
                        'omitted_records'=>count(array_filter($page,static fn(array$m):bool=>$m['read_status']!=='available')),
                        'message_scope'=>'same_native_conversation_and_channel',
                        'media_contents_read'=>false,'source_post_or_selection_pages_fetched'=>false,
                        'trip_boundary'=>'not_inferred','semantic_review_complete'=>false,
                        'saved_profile_refs'=>'immutable_journal_refs_not_asserted_quotes_of_current_message_rows'],
                ];
            };
            $result=$make($returned,$more,$profile);
            while(strlen(self::json($result))>self::MAX_OUTPUT_BYTES&&count($returned)>1){array_pop($returned);$more=true;$result=$make($returned,$more,$profile);}
            if(strlen(self::json($result))>self::MAX_OUTPUT_BYTES){
                $profile=['availability'=>'unavailable','reason'=>'response_limit','profile_id'=>$profileId];
                $result=$make($returned,$more,$profile);
            }
            if(strlen(self::json($result))>self::MAX_OUTPUT_BYTES&&$returned){
                $returned[0]['text']=null;$returned[0]['read_status']='response_record_limit';
                if($selectionSources)$returned[0]['selection_sources']=self::unavailableSelectionSources('response_record_limit');
                $result=$make($returned,$more,$profile);
            }
            return$result;
        },$selectionSources,$conversionTrace);
    }

    private function read(int $admin,string $connection,callable $operation,bool $selectionSources=false,bool $conversionTrace=false):array
    {
        if($this->pdo->inTransaction())self::fail('transaction_active');
        if($admin<1||preg_match('/\A[a-f0-9]{32}\z/D',$connection)!==1||!is_callable($this->keyProvider))self::fail('forbidden');
        $this->deadline=microtime(true)+self::MAX_SECONDS;$this->boundScopes=[];$this->sourceBytes=0;$this->selectionReferenceCount=0;
        $owned=false;$queryOnly=null;$post=false;
        try{
            if($selectionSources)($this->selectionSourceAuthorize)($admin,$connection);
            if($conversionTrace)($this->conversionTraceAuthorize)($admin,$connection);
            $key=$this->key($admin,$connection);
            if($this->mysql){
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            }else{
                $queryOnly=(int)$this->pdo->query('PRAGMA query_only')->fetchColumn();
                $this->pdo->exec('PRAGMA query_only=ON');$this->pdo->beginTransaction();
            }
            $owned=true;$permission=$this->permission($admin);if($permission===null)self::fail('forbidden');
            $result=$operation($key);
            $this->pdo->rollBack();$owned=false;
            if($queryOnly!==null){$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));$queryOnly=null;}
            // All release checks run outside the old source snapshot, including native subject ownership.
            $post=true;
            if($selectionSources)($this->selectionSourceAuthorize)($admin,$connection);
            if($conversionTrace)($this->conversionTraceAuthorize)($admin,$connection);
            $freshKey=$this->key($admin,$connection);
            if(!hash_equals($key,$freshKey))self::fail('stale_cursor');
            if($permission!==$this->permission($admin))self::fail('permission_changed');
            if($this->boundScopes){
                $ids=array_keys($this->boundScopes);$fresh=$this->nativeRows(' AND c.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids,count($ids));
                $identities=[];foreach($fresh as$row)$identities[(string)$row['id']]=$this->identity($row);
                ksort($identities,SORT_STRING);ksort($this->boundScopes,SORT_STRING);
                if($identities!==$this->boundScopes)self::fail('source_changed');
            }
            $this->budget();if(strlen(self::json($result))>self::MAX_OUTPUT_BYTES)self::fail('response_limit');
            return$result;
        }catch(DialogueReviewReadException $e){throw$e;}
        catch(DomainException $e){self::fail($post?'permission_changed':'forbidden');}
        catch(PDOException $e){throw self::databaseError($e);}
        catch(Throwable $e){self::fail('unavailable');}
        finally{
            try{
                if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();
                if($queryOnly!==null)$this->pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));
            }catch(Throwable $e){self::fail('unavailable');}
            finally{$this->boundScopes=[];$this->sourceBytes=0;$this->selectionReferenceCount=0;}
        }
    }

    private function permission(int $admin):?array
    {
        $row=$this->q("SELECT m.id AS admin_id,p.id AS project_id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id"
            ." JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.is_active=1 AND m.role='admin' AND p.project_key=? AND p.is_active=1 LIMIT 1",[$admin,'anytour'])->fetch(PDO::FETCH_ASSOC);
        return$row===false?null:array_map('strval',$row);
    }

    private function nativeScope():array
    {
        return['conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id'
            .' AND cc.project_key=c.project_key AND cc.channel=c.channel',
            "c.project_key=? AND c.channel IN ('max','telegram')".($this->historyAudit?'':" AND c.status<>'closed'")." AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')];
    }

    private function nativeRows(string $extra,array $bindings,int $limit,bool $descending=false):array
    {
        [$from,$where]=$this->nativeScope();
        $rows=$this->q('SELECT c.id,c.project_key,c.channel,c.customer_id,c.customer_channel_id,c.external_chat_id,c.status,c.manager_id,c.source_id,'
            .'c.started_at,c.last_message_at,c.updated_at,cc.external_user_id,cc.external_chat_id AS channel_chat_id FROM '.$from.' WHERE '.$where.$extra
            .' ORDER BY c.id'.($descending?' DESC':'').' LIMIT '.$limit,['anytour',...$bindings])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as$row)foreach(['id','customer_id','customer_channel_id']as$field)if(!self::decimal((string)$row[$field],false))self::fail('unavailable');
        return$rows;
    }

    /** Only scalar activity markers and validated clock fields; no transcript or arbitrary metadata copies. */
    private function activityMarkers(array $rows):array
    {
        if(!$rows)return[];$ids=array_map(static fn(array$r):string=>(string)$r['id'],$rows);$slots=implode(',',array_fill(0,count($ids),'?'));$out=[];
        foreach($rows as$row)$out[(string)$row['id']]=['latest_message_id'=>'0','latest_event_id'=>'0','recorded_at_unix'=>null];
        $latest=$this->q('SELECT m.conversation_id,MAX(m.id) AS id FROM messages m JOIN conversations c ON c.id=m.conversation_id'
            .' AND c.channel=m.channel WHERE m.conversation_id IN ('.$slots.') GROUP BY m.conversation_id',$ids)->fetchAll(PDO::FETCH_ASSOC);
        $messageIds=[];foreach($latest as$item){$id=(string)$item['conversation_id'];$out[$id]['latest_message_id']=(string)$item['id'];$messageIds[]=(string)$item['id'];}
        if($messageIds){
            $clock=ConversationRecordClock::sql('s.metadata_json',$this->mysql);
            $clocks=$this->q('SELECT s.conversation_id,'.$clock.' AS recorded_at_unix FROM messages s WHERE s.id IN ('
                .implode(',',array_fill(0,count($messageIds),'?')).')',$messageIds)->fetchAll(PDO::FETCH_ASSOC);
            foreach($clocks as$item)$out[(string)$item['conversation_id']]['recorded_at_unix']=$item['recorded_at_unix'];
        }
        foreach($this->q('SELECT conversation_id,MAX(id) AS id FROM conversation_events WHERE conversation_id IN ('.$slots.') GROUP BY conversation_id',$ids)->fetchAll(PDO::FETCH_ASSOC)as$item)
            $out[(string)$item['conversation_id']]['latest_event_id']=(string)$item['id'];
        return$out;
    }

    private function identity(array $row):string
    {
        return hash('sha256',self::json(array_map(static fn(string$k):string=>(string)($row[$k]??''),
            ['id','project_key','channel','customer_id','customer_channel_id','external_chat_id','external_user_id','channel_chat_id'])));
    }

    /** Read the latest exact scope only. An unusable latest record never falls back to an older positive. */
    private function profile(array $row,mixed $selected,int $cutoff):array
    {
        if($this->historyAudit)return['availability'=>'not_requested','reason'=>'quality_history_not_a_forecast_review'];
        if($selected===null)return['availability'=>'unavailable','reason'=>'previous_profile_unavailable','profile_id'=>null];
        if($selected==='0')return['availability'=>'not_saved','profile_id'=>null];
        try{
            $length=$this->length('profile_json');
            $sql='SELECT id,observed_at_unix,'.$length.' AS bytes,CASE WHEN '.$length.'<='.self::MAX_PROFILE_BYTES.' THEN profile_json ELSE NULL END AS profile_json'
                .' FROM modeled_conversion_profile_versions WHERE project_key=? AND platform=? AND customer_key=? AND conversation_id=?';
            $bindings=['anytour',$row['channel'],(string)$row['customer_id'],(string)$row['id']];
            if($selected!==false){$sql.=' AND id=?';$bindings[]=$selected;}
            $saved=$this->q($sql.' ORDER BY id DESC LIMIT 1',$bindings)->fetch(PDO::FETCH_ASSOC);
            if(!$saved)return['availability'=>$selected===false?'not_saved':'unavailable','reason'=>$selected===false?'no_saved_profile':'saved_profile_unavailable','profile_id'=>null];
            $id=(string)$saved['id'];$unavailable=['availability'=>'unavailable','reason'=>'saved_profile_unusable','profile_id'=>$id];
            if(!is_string($saved['profile_json'])||(int)$saved['bytes']>self::MAX_PROFILE_BYTES)return$unavailable;
            $this->sourceBytes+=strlen($saved['profile_json']);
            if(!self::decimal((string)$saved['observed_at_unix'],false)||(int)$saved['observed_at_unix']>$cutoff)return$unavailable;
            $wrapper=json_decode($saved['profile_json'],true,64,JSON_THROW_ON_ERROR);
            $subject=['project_key'=>'anytour','platform'=>$row['channel'],'customer_key'=>(string)$row['customer_id'],'conversation_id'=>(string)$row['id']];
            if(!is_array($wrapper)||($wrapper['access_status']??null)!=='allowed'||!is_array($wrapper['subject']??null))return$unavailable;
            foreach($subject as$field=>$value)if(($wrapper['subject'][$field]??null)!==$value)return$unavailable;
            require_once __DIR__.'/ModeledConversionValueRegistry.php';
            $projection=ModeledConversionValueRegistry::predictionFields($wrapper);
            if(($projection['semantic_prediction']['availability']??null)!=='available')return$unavailable;
            $result=['availability'=>'available','profile_id'=>$id,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$saved['observed_at_unix']),
                'projection'=>$projection,'meaning'=>'saved_untrained_prediction_not_current_trip_truth_or_reply_quality'];
            if(is_callable($this->conversionTraceAuthorize)){
                require_once __DIR__.'/ModeledConversionDialogueTraceReader.php';
                $result['conversion_trace']=(new ModeledConversionDialogueTraceReader(fn(string $sql,array $bind):PDOStatement=>$this->q($sql,$bind)))
                    ->read($wrapper,$subject,$id,$this->now());
            }
            return$result;
        }catch(PDOException $e){
            if(in_array((int)($e->errorInfo[1]??0),[3024,1969],true))throw self::databaseError($e);
            return['availability'=>'unavailable','reason'=>'profile_storage_unavailable','profile_id'=>null];
        }catch(DialogueReviewReadException $e){throw$e;}
        catch(Throwable $e){return['availability'=>'unavailable','reason'=>'saved_profile_unusable','profile_id'=>isset($id)?$id:null];}
    }

    private function projectMessage(array $meta,?array $raw,bool $selectionSources=false):array
    {
        $direction=in_array($meta['direction'],['inbound','outbound'],true)?$meta['direction']:'unknown';
        $sender=in_array($meta['sender_type'],['customer','manager','ai','bot','system'],true)?$meta['sender_type']:'unknown';
        $role=match(true){$direction==='inbound'&&$sender==='customer'=>'customer',
            $direction==='outbound'&&$sender==='manager'=>'manager',
            $direction==='outbound'&&in_array($sender,['ai','bot'],true)=>'bot',$sender==='system'=>'system',default=>'unknown'};
        $item=['message_id'=>(string)$meta['id'],'source_ref'=>'message:'.$meta['id'],'direction'=>$direction,'sender_type'=>$sender,'role'=>$role,
            'read_status'=>$meta['read_status'],'text'=>null,'event_type'=>'unknown','clock'=>$this->recordedClock(null,$meta['created_at']),
            'media_unread'=>null,'linked_context_unread'=>null,'text_minimized'=>false,'links_redacted'=>false,'selection_links_unread'=>false,'revision'=>null];
        if($selectionSources)$item['selection_sources']=self::unavailableSelectionSources('source_unavailable');
        if($meta['read_status']!=='available')return$item;
        $text=(string)$raw['text'];$json=(string)$raw['metadata_json'];
        // Hash byte strings independently: malformed text/JSON remains an explicit omission, not a JSON-encoding exception.
        $item['revision']=hash('sha256',self::json([(string)$meta['id'],$meta['direction'],$meta['sender_type'],$meta['created_at'],hash('sha256',$text),hash('sha256',$json)]));
        if(preg_match('//u',$text)!==1){$item['read_status']='invalid_text';return$item;}
        $metadata=$json===''?[]:json_decode($json,true,32);
        $valid=is_array($metadata)&&!array_is_list($metadata)||$metadata===[];
        if(!$valid)$metadata=[];
        $type=$valid&&is_string($metadata['type']??'message')?($metadata['type']??'message'):'unknown';
        if(!in_array($type,['message','callback','start','bot_started','contact'],true))$type='unknown';
        $item['event_type']=$type;$item['metadata_complete']=$valid;
        $item['clock']=$this->recordedClock(ConversationRecordClock::read($json),$meta['created_at']);
        if($sender==='manager')$text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
        if($type==='callback'){
            $action=ManagerCallbackPresentationService::fromMessage(['direction'=>$direction,'sender_type'=>$sender,'text'=>$text],['type'=>'callback']);
            $text=$action['label']??'[действие клиента]';
        }
        $selection=false;$links=false;$item['text']=ManagerAiTextRedactor::redact($text,$selection,$links);
        $item['text_minimized']=true;$item['links_redacted']=$links;$item['selection_links_unread']=$selection;
        if($selectionSources){
            $item['selection_sources']=$valid&&$type==='message'&&in_array($role,['customer','manager'],true)
                ?$this->selectionSources($text,$item)
                :self::unavailableSelectionSources('unsupported_message');
        }
        $item['media_unread']=$valid?(!empty($metadata['attachments'])):null;
        $item['linked_context_unread']=$valid?(!empty($metadata['linked_message'])):null;
        return$item;
    }

    private static function unavailableSelectionSources(string $reason):array
    {
        return ['references'=>[],'complete'=>false,'reason'=>$reason,'source_page_fetched'=>false];
    }

    /** Purpose-scoped references only; ordinary text/provider/log projections stay redacted. */
    private function selectionSources(string $text,array $message):array
    {
        $id=filter_var($message['message_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false||(string)$id!==$message['message_id'])return self::unavailableSelectionSources('unsupported_identity');
        $found=ManagerSelectionLinks::inMessage($text,$id,$message['sender_type'],'');
        $valid=[];$unsupported=0;
        foreach($found as$source){
            $url=$source['url']??null;
            if(!is_string($url)){++$unsupported;continue;}
            // Keep the latest distinct references, using the existing strict classifier.
            unset($valid[$url]);$valid[$url]=$url;
        }
        $remaining=max(0,self::MAX_SELECTION_REFERENCES_PER_PAGE-$this->selectionReferenceCount);
        $take=min(self::MAX_SELECTION_REFERENCES_PER_MESSAGE,$remaining);
        $selected=$take>0?array_slice(array_values($valid),-$take):[];
        $omitted=count($valid)-count($selected);$reached=count($found)>=41;
        $references=[];
        foreach($selected as$url){
            $references[]=['message_id'=>$message['message_id'],'source_ref'=>$message['source_ref'],
                'message_revision'=>$message['revision'],'provider'=>'qui_quo_public_selection','url'=>$url,
                'untrusted_source'=>true,'source_page_fetched'=>false];
        }
        $this->selectionReferenceCount+=count($references);
        return ['references'=>$references,'complete'=>$unsupported===0&&$omitted===0&&!$reached,
            'reason'=>$unsupported>0?'unsupported_links':($omitted>0||$reached?'reference_limit':null),
            'unsupported_links_within_limit'=>$unsupported,'references_omitted_within_limit'=>$omitted,
            'classifier_limit_reached'=>$reached,'source_page_fetched'=>false];
    }

    private function recordedClock(mixed $unix,mixed $raw):array
    {
        $valid=(is_int($unix)||(is_string($unix)&&preg_match('/\A[0-9]{1,12}\z/D',$unix)===1))
            &&(int)$unix>=946684800&&(int)$unix<=4102444800;
        return ['at'=>$valid?gmdate('Y-m-d\TH:i:s\Z',(int)$unix):null,
            'at_moscow'=>$valid?(new DateTimeImmutable('@'.(int)$unix))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i:sP'):null,
            'basis'=>$valid?'server_recording_unix':'unknown_bare_database_datetime',
            'recorded_local_value'=>is_string($raw)?$raw:null];
    }

    private function base(string $operation,int $now):array
    {
        return['ok'=>true,'schema_version'=>1,'project_key'=>'anytour','operation'=>$operation,'timezone'=>'Europe/Moscow',
            'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$now),'as_of_moscow'=>(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i:sP'),
            'database_writes'=>0,'read_markers_changed'=>0,'model_calls'=>0,'external_fetches'=>0,'customer_sends'=>0,
            'untrusted_source_text'=>true];
    }

    private function dialogueRef(array $row,string $key,int $admin,string $connection):string
    {return$this->encode(['v'=>1,'k'=>'dialogue','d'=>(string)$row['id'],'r'=>$this->identity($row),'e'=>$this->now()+self::CURSOR_TTL],$key,$admin,$connection);}
    private function key(int $admin,string $connection):string
    {$key=($this->keyProvider)($admin,$connection);if(!is_string($key)||strlen($key)!==32)self::fail('forbidden');return$key;}
    private function encode(array $value,string $key,int $admin,string $connection):string
    {
        $body=rtrim(strtr(base64_encode(self::json($value)),'+/','-_'),'=');
        return$body.'.'.hash_hmac('sha256',"anytour.dialogue-review.v1\0".$admin."\0".$connection."\0anytour\0".$body,$key);
    }
    private function decode(string $token,string $kind,string $key,int $admin,string $connection):array
    {
        if(strlen($token)>2048||preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$token,$m)!==1)self::fail('stale_cursor');
        if(!hash_equals(hash_hmac('sha256',"anytour.dialogue-review.v1\0".$admin."\0".$connection."\0anytour\0".$m[1],$key),$m[2]))self::fail('stale_cursor');
        $body=base64_decode(strtr($m[1],'-_','+/').str_repeat('=',(4-strlen($m[1])%4)%4),true);
        try{$v=json_decode((string)$body,true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){self::fail('stale_cursor');}
        $keys=match($kind){'inventory'=>['v','k','f','a','t','e'],'dialogue'=>['v','k','d','r','e'],'context'=>['v','k','d','r','m','b','p','t','e'],default=>[]};
        $kinds=$kind==='inventory'&&!$this->historyAudit?['inventory','inventory_newest']:[$kind];
        $now=$this->now();
        if(!is_array($v)||count($v)!==count($keys)||array_diff(array_keys($v),$keys)||($v['v']??null)!==1||!in_array($v['k']??null,$kinds,true)
            ||!is_int($v['e'])||$v['e']<$now||$v['e']>$now+self::CURSOR_TTL)self::fail('stale_cursor');
        foreach($kind==='inventory'?['f','a']:($kind==='context'?['d','m','b']:['d'])as$field)
            if(!is_string($v[$field])||!self::decimal($v[$field],$field!=='d'))self::fail('stale_cursor');
        if($kind!=='inventory'&&(!is_string($v['r'])||preg_match('/\A[a-f0-9]{64}\z/D',$v['r'])!==1))self::fail('stale_cursor');
        if($kind!=='dialogue'&&(!is_int($v['t'])||$v['t']<946684800||$v['t']>$now))self::fail('stale_cursor');
        if($kind==='context'&&($v['p']!==null&&(!is_string($v['p'])||!self::decimal($v['p'],true))))self::fail('stale_cursor');
        return$v;
    }
    private static function arguments(array $args,array $allowed,int $maximum):void
    {
        if(($args!==[]&&array_is_list($args))||array_diff(array_keys($args),$allowed))self::fail('invalid_arguments');
        foreach(['cursor','dialogue_ref']as$field)if(array_key_exists($field,$args)&&(!is_string($args[$field])||$args[$field]===''||strlen($args[$field])>2048))self::fail('invalid_arguments');
        if(array_key_exists('limit',$args)&&(!is_int($args['limit'])||$args['limit']<1||$args['limit']>$maximum))self::fail('invalid_arguments');
    }
    private function q(string $sql,array $bindings):PDOStatement
    {
        $this->budget();if(!str_starts_with($sql,'SELECT '))self::fail('unavailable');
        if($this->mysql)$sql='SELECT /*+ MAX_EXECUTION_TIME('.max(1,min(5000,(int)(($this->deadline-microtime(true))*1000))).') */ '.substr($sql,7);
        $q=$this->pdo->prepare($sql);$q->execute($bindings);$this->budget();return$q;
    }
    private function length(string $column):string
    {return$this->mysql?'OCTET_LENGTH(COALESCE('.$column.",''))":'LENGTH(CAST(COALESCE('.$column.",'') AS BLOB))";}
    private function budget():void{if(microtime(true)>$this->deadline)self::fail('time_limit');}
    private function now():int
    {$now=$this->clock!==null?($this->clock)():time();if(!is_int($now)||$now<946684800||$now>4102444800)self::fail('unavailable');return$now;}
    private static function decimal(string $value,bool $zero):bool{return preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$value)===1;}
    private static function greater(string $a,string $b):bool{return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    // Match the adapter's encoding: escaped slashes count toward the actual private data envelope.
    private static function json(array $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private static function databaseError(PDOException $e):DialogueReviewReadException
    {return new DialogueReviewReadException(in_array((int)($e->errorInfo[1]??0),[3024,1969],true)?'time_limit':'unavailable');}
    private static function fail(string $reason):never{throw new DialogueReviewReadException($reason);}
}

final class DialogueReviewReadException extends RuntimeException
{
    public function __construct(public readonly string $reason){parent::__construct('dialogue_review_'.$reason);}
}
