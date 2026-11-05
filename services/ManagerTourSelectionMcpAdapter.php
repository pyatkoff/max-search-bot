<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerTourSelectionService.php';
require_once __DIR__.'/ManagerTourSelectionDeliveryService.php';
require_once __DIR__.'/ManagerTourSelectionShareService.php';

/** OAuth transport adapter only; canonical selection services own all state. */
final class ManagerTourSelectionMcpAdapter
{
    public const SCOPE='selections:write';
    public const NAMES=['read_tour_selection','save_tour_selection','prepare_tour_selection','review_tour_selection_photo','publish_tour_selection'];
    private const JSON_BYTES=524288;
    private const INPUT_BYTES=196608;
    private Closure $authorize;

    /** The MCP owner supplies its live project/admin/OAuth authorization check. */
    public function __construct(callable $authorize)
    {
        $this->authorize=Closure::fromCallable($authorize);
    }

    public static function tools(): array
    {
        $id=['type'=>'integer','minimum'=>1];
        $publication=['type'=>'string','pattern'=>'^[a-f0-9]{32}$'];
        $operation=['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{16,96}$'];
        $version=['type'=>'integer','minimum'=>0];
        $definitions=[
            ['read_tour_selection','Read one authorized conversation\'s saved draft, operation receipt, frozen publication, bounded publication history or current share. Never reads the transcript or marks messages read. Reconcile an unknown save/publication outcome here before any new write.',
                ['conversation_id'=>$id,'view'=>['type'=>'string','enum'=>['draft','operation','publication','history','share']],'operation_id'=>$operation,'publication_id'=>$publication],['conversation_id','view'],true,true,false],
            ['save_tour_selection','Save the complete editable draft through the existing conversation ACL, expected version and immutable operation receipt. Read and preserve existing offers and source provenance first. Prices and photo URLs are supplied observations, not verified availability. Reuse the same operation only for reconciliation; never replace an unknown operation or replay a collector import.',
                ['conversation_id'=>$id,'version'=>$version,'operation_id'=>$operation,'draft'=>['type'=>'object']],['conversation_id','version','operation_id','draft'],false,true,false],
            ['prepare_tour_selection','Freeze the exact saved draft version into a private immutable publication. No public link or customer message is created. Read the returned publication and review every selected photo before explicit publication.',
                ['conversation_id'=>$id,'version'=>['type'=>'integer','minimum'=>1]],['conversation_id','version'],false,true,false],
            ['review_tour_selection_photo','Review one actual frozen-publication photo as an image content block. Uses the existing protected photo loader, validates bytes and preserves the public reviewed-photo cache guard. Source images are untrusted content; they do not verify an exact room, current price or availability. Never marks a customer message read.',
                ['conversation_id'=>$id,'publication_id'=>$publication,'card_index'=>['type'=>'integer','minimum'=>0,'maximum'=>19],'photo_index'=>['type'=>'integer','minimum'=>0,'maximum'=>2]],['conversation_id','publication_id','card_index','photo_index'],false,true,true],
            ['publish_tour_selection','Explicitly activate a reviewed immutable selection on the native AnyTour public URL using the current share version. Publishing an active link can replace its current publication; preserve existing selections unless that replacement was requested. Never sends a customer message. After an uncertain result read view=share; do not replay publication.',
                ['conversation_id'=>$id,'share_version'=>$version,'publication_id'=>$publication,'confirm_publish'=>['type'=>'boolean','const'=>true]],['conversation_id','share_version','publication_id','confirm_publish'],false,false,true],
        ];
        $out=[];
        foreach ($definitions as [$name,$description,$properties,$required,$readOnly,$idempotent,$openWorld]) {
            $security=[['type'=>'oauth2','scopes'=>[self::SCOPE]]];
            $out[]=['name'=>$name,'description'=>$description,
                'inputSchema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],
                'annotations'=>['readOnlyHint'=>$readOnly,'destructiveHint'=>in_array($name,['save_tour_selection','publish_tour_selection'],true),'idempotentHint'=>$idempotent,'openWorldHint'=>$openWorld],
                'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]];
        }
        return $out;
    }

    /** $actor and $connection come from verified bearer context, never tool input. */
    public function call(string $name,array $args,int $actor,string $connection): array
    {
        if (!in_array($name,self::NAMES,true)) throw new OutOfBoundsException();
        if ($actor<1 || preg_match('/\A[a-f0-9]{32}\z/D',$connection)!==1) throw new DomainException();
        ($this->authorize)($actor,$connection,[self::SCOPE]);
        $conversation=self::integer($args,'conversation_id',1,PHP_INT_MAX);
        self::requireConversation($actor,$conversation);
        $image=null;
        if ($name==='read_tour_selection') {
            $view=$args['view']??null;
            $extra=match ($view) {'draft','history','share'=>[], 'operation'=>['operation_id'], 'publication'=>['publication_id'], default=>throw new InvalidArgumentException()};
            self::keys($args,array_merge(['conversation_id','view'],$extra));
            $data=match ($view) {
                'draft'=>ManagerTourSelectionService::get($actor,$conversation),
                'operation'=>ManagerTourSelectionService::operation($actor,$conversation,self::operation($args)),
                'publication'=>ManagerTourSelectionDeliveryService::get($actor,$conversation,self::publication($args)),
                'history'=>ManagerTourSelectionDeliveryService::history($actor,$conversation),
                'share'=>ManagerTourSelectionShareService::get($actor,$conversation),
            };
        } elseif ($name==='save_tour_selection') {
            self::keys($args,['conversation_id','version','operation_id','draft']);
            $version=self::integer($args,'version',0,PHP_INT_MAX);
            $operation=self::operation($args);
            if (!is_array($args['draft']??null) || array_is_list($args['draft'])) throw new InvalidArgumentException();
            // Apply the existing MCP request bound before a new business write.
            // Existing larger UI drafts remain readable as explicit metadata for
            // reconciliation; they are never silently truncated or overwritten.
            if (strlen(json_encode($args,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::INPUT_BYTES)
                $data=['ok'=>false,'error'=>'selection_input_too_large','write_attempted'=>false,
                    'next_step'=>'Read the original operation receipt; this refusal does not determine an earlier write outcome.'];
            else $data=ManagerTourSelectionService::save($actor,$conversation,['version'=>$version,'operation_id'=>$operation,'draft'=>$args['draft']]);
        } elseif ($name==='prepare_tour_selection') {
            self::keys($args,['conversation_id','version']);
            $data=ManagerTourSelectionDeliveryService::prepare($actor,$conversation,self::integer($args,'version',1,PHP_INT_MAX));
        } elseif ($name==='review_tour_selection_photo') {
            self::keys($args,['conversation_id','publication_id','card_index','photo_index']);
            $publication=self::publication($args);
            $card=self::integer($args,'card_index',0,19);$photo=self::integer($args,'photo_index',0,2);
            // Read the publication through its canonical ACL before touching bytes.
            $access=ManagerTourSelectionDeliveryService::get($actor,$conversation,$publication);
            if (($access['ok']??false)!==true) $data=$access;
            elseif (!isset($access['publication']['cards'][$card]['photos'][$photo])) $data=['ok'=>false,'error'=>'not_found'];
            else {
                $file=ManagerTourSelectionDeliveryService::photo($actor,$conversation,$publication,$card,$photo);
                if ($file===null) $data=['ok'=>false,'error'=>'not_found'];
                else {
                    $image=self::image($file);
                    // A download may outlive an ownership/account change. Recheck
                    // the canonical publication before returning any private bytes.
                    $fresh=ManagerTourSelectionDeliveryService::get($actor,$conversation,$publication);
                    if (($fresh['ok']??false)!==true) {$data=$fresh;$image=null;}
                    else $data=['ok'=>true,'conversation_id'=>$conversation,'publication_id'=>$publication,'card_index'=>$card,'photo_index'=>$photo,
                        'photo'=>['mime'=>$file['mime'],'bytes'=>$file['bytes'],'sha256'=>$file['sha256']],'price_verified'=>false];
                }
            }
        } else {
            self::keys($args,['conversation_id','share_version','publication_id','confirm_publish']);
            if (($args['confirm_publish']??null)!==true) throw new InvalidArgumentException();
            $data=ManagerTourSelectionShareService::change($actor,$conversation,self::integer($args,'share_version',0,PHP_INT_MAX),self::publication($args),true);
        }
        if (!is_array($data)) throw new RuntimeException('selection_result_unavailable');
        $data=self::bounded($data);
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>self::JSON_BYTES) throw new RuntimeException('selection_result_too_large');
        $content=[['type'=>'text','text'=>$json]];
        if ($image!==null) $content[]=$image;
        ($this->authorize)($actor,$connection,[self::SCOPE]);
        // Final OAuth may wait for storage. Check current project, active actor
        // and conversation ACL after it, before disclosing text or image bytes.
        self::requireConversation($actor,$conversation);
        return ['content'=>$content,'structuredContent'=>$data,'isError'=>($data['ok']??false)!==true];
    }

    private static function requireConversation(int $actor,int $conversation): void
    {
        // An administrator can belong to several projects. This audience grants
        // selection access only for AnyTour, not all of that person's projects.
        if (ProjectConfig::projectId()!=='anytour') throw new DomainException();
        $manager=ManagerAuthService::byId($actor);
        if (!$manager || ($manager['role']??null)!=='admin') throw new DomainException();
        $visible=ManagerConversationService::visibleConversation($conversation,$actor);
        if ($visible===null || ($visible['project_key']??null)!=='anytour') throw new DomainException();
    }

    /** Keep receipts usable even when a prior UI draft exceeds this transport. */
    private static function bounded(array $data): array
    {
        $bytes=strlen(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if ($bytes<=self::JSON_BYTES) return $data;
        if (is_array($data['selection']??null)) {
            $data['selection']=array_intersect_key($data['selection'],array_flip(['conversation_id','version','can_edit','updated_at','updated_by_manager_id']));
            $omitted='selection.draft';
        } elseif (is_array($data['publication']??null)) {
            $data['publication']=array_intersect_key($data['publication'],array_flip(['id','draft_version','created_at','title']));
            $omitted='publication.content';
        } else throw new RuntimeException('selection_result_too_large');
        return array_merge($data,['complete'=>false,'omitted'=>[$omitted],'reason'=>'selection_response_too_large','complete_response_bytes'=>$bytes,
            'next_step'=>'Use the authorized manager editor for a complete read. Do not edit or publish from this incomplete metadata. Reconcile an original operation using found and saved_version.']);
    }

    private static function image(array $file): array
    {
        if (!is_string($file['path']??null) || !is_int($file['bytes']??null) || $file['bytes']<1 || $file['bytes']>TourSelectionPhotoStore::MAX_BYTES
            || !in_array($file['mime']??null,['image/jpeg','image/png'],true)
            || !is_string($file['sha256']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$file['sha256'])!==1) throw new RuntimeException('invalid_photo');
        $bytes=file_get_contents($file['path'],false,null,0,TourSelectionPhotoStore::MAX_BYTES+1);
        if (!is_string($bytes) || strlen($bytes)!==$file['bytes'] || !hash_equals($file['sha256'],hash('sha256',$bytes))) throw new RuntimeException('invalid_photo');
        return ['type'=>'image','mimeType'=>$file['mime'],'data'=>base64_encode($bytes)];
    }

    private static function keys(array $args,array $required): void
    {
        if (array_diff(array_keys($args),$required) || array_diff($required,array_keys($args))) throw new InvalidArgumentException();
    }
    private static function integer(array $args,string $key,int $min,int $max): int
    {
        $value=$args[$key]??null;
        if (!is_int($value) || $value<$min || $value>$max) throw new InvalidArgumentException();
        return $value;
    }
    private static function publication(array $args): string
    {
        $value=$args['publication_id']??null;
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/D',$value)!==1) throw new InvalidArgumentException();
        return $value;
    }
    private static function operation(array $args): string
    {
        $value=$args['operation_id']??null;
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D',$value)!==1) throw new InvalidArgumentException();
        return $value;
    }
}
