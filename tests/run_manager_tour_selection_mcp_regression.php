<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-tour-selection.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionMcpAdapter.php';

$pdo=deliveryDb();
$pdo->exec('CREATE TABLE manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));');
$connection=str_repeat('c',32);$allowed=true;$lateDeny=false;$authorizations=0;$afterAuthorizationHook=null;
$adapter=new ManagerTourSelectionMcpAdapter(static function(int $actor,string $actual,array $required)use(&$allowed,&$lateDeny,&$authorizations,&$afterAuthorizationHook,$connection):void {
    ++$authorizations;
    // This callback models the transport boundary only. Real grant and consent
    // coverage belongs to the pending MCP/OAuth integration, not this fixture.
    if (!$allowed || $actor!==90 || $actual!==$connection || $required!==[ManagerTourSelectionMcpAdapter::SCOPE]
        || ($lateDeny && $authorizations===2)) throw new DomainException('synthetic_access_denied');
    if ($authorizations===2 && $afterAuthorizationHook!==null) $afterAuthorizationHook();
});
function selectionMcp(string $name,array $args,int $actor=90,?string $actualConnection=null): array
{
    global $adapter,$connection,$authorizations;
    $authorizations=0;
    return $adapter->call($name,$args,$actor,$actualConnection??$connection);
}
function selectionMcpRejected(string $name,callable $run,string $class=InvalidArgumentException::class): void
{
    try {$run();}catch(Throwable $error){deliveryCheck($name,$error instanceof $class);return;}
    throw new RuntimeException('FAIL '.$name.' accepted');
}
function selectionMcpBusinessRows(): array
{
    global $pdo;$out=[];
    foreach (['conversations','managers','messages','manager_conversation_reads'] as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
    return $out;
}

// This focused CLI mode exercises only the new synthetic booking cases on
// platforms without the unchanged POSIX-only protected photo-store fixture.
// The ordinary required CI invocation still executes the complete file.
if (in_array('--booking-only',$argv??[],true)) {
    require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic workspace']]);
    $pdo->exec('CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);');
    $oauth=new ManagerPreparationOAuth($pdo);
    $client=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'booking-only-fixture')['client_id'];
    [, $legacyContext]=selectionRealGrant($oauth,$client,implode(' ',ManagerPreparationOAuth::SCOPES));
    [, $selectionContext]=selectionRealGrant($oauth,$client,ManagerPreparationOAuth::SELECTION_SCOPE);
    selectionBookingChecks($pdo,$oauth,$client,$legacyContext,$selectionContext,new ManagerPreparationMcpService($pdo));
    echo 'Focused booking reader: '.$checks." checks passed\n";
    exit;
}

$definitions=ManagerTourSelectionMcpAdapter::tools();$byName=array_column($definitions,null,'name');
deliveryCheck('only the declared selection and readonly booking actions are exposed',array_keys($byName)===ManagerTourSelectionMcpAdapter::NAMES);
foreach ($definitions as $tool) {
    deliveryCheck($tool['name'].' declares explicit selection capability and rejects extra arguments',
        $tool['securitySchemes']===[['type'=>'oauth2','scopes'=>[ManagerTourSelectionMcpAdapter::scopeFor($tool['name'])]]]
        &&$tool['_meta']['securitySchemes']===$tool['securitySchemes']&&$tool['inputSchema']['additionalProperties']===false);
}
deliveryCheck('only metadata reads are read-only; photo review discloses externally loaded image',
    $byName['read_tour_selection']['annotations']['readOnlyHint']===true
    &&$byName['review_tour_selection_photo']['annotations']['readOnlyHint']===false
    &&$byName['review_tour_selection_photo']['annotations']['openWorldHint']===true);
deliveryCheck('publishing is explicitly destructive and is not advertised as blindly replayable',
    $byName['publish_tour_selection']['annotations']['destructiveHint']===true
    &&$byName['publish_tour_selection']['annotations']['idempotentHint']===false);

$before=selectionMcpBusinessRows();
$read=selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft']);
deliveryCheck('canonical empty draft is read with two authorization checks',!$read['isError']&&$read['structuredContent']['selection']['version']===0&&$authorizations===2);
deliveryCheck('read does not touch messages, read markers, assignments or shifts',selectionMcpBusinessRows()===$before);
$allowed=false;
selectionMcpRejected('missing/revoked capability blocks access before a canonical read',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft']),DomainException::class);
$allowed=true;
selectionMcpRejected('the authenticated actor cannot be replaced',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft'],7),DomainException::class);
selectionMcpRejected('a different OAuth connection is rejected',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft'],90,str_repeat('d',32)),DomainException::class);
selectionMcpRejected('noncanonical conversation IDs are rejected',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>'1','view'=>'draft']));
selectionMcpRejected('actor argument is not accepted',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft','actor'=>7]));
selectionMcpRejected('wrong-view arguments are rejected',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft','operation_id'=>str_repeat('e',32)]));
selectionMcpRejected('missing publication ID is rejected',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'publication']));
foreach (['send','collector_import','collector_connect','revoke','get_reply_context'] as $unknown) {
    selectionMcpRejected($unknown.' cannot be dispatched',static fn()=>selectionMcp($unknown,['conversation_id'=>1]),OutOfBoundsException::class);
}
$lateDeny=true;
selectionMcpRejected('late revocation suppresses the complete read result',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft']),DomainException::class);
$lateDeny=false;

$pdo->exec("INSERT INTO projects VALUES(2,'other','Synthetic second project',1);INSERT INTO manager_projects VALUES(90,2);UPDATE conversations SET project_key='other' WHERE id=2");
selectionMcpRejected('AnyTour capability cannot read a second project of the same administrator',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>2,'view'=>'draft']),DomainException::class);
selectionMcpRejected('AnyTour capability cannot read another project operation',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>2,'view'=>'operation','operation_id'=>str_repeat('0',32)]),DomainException::class);
selectionMcpRejected('AnyTour capability cannot read another project share',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>2,'view'=>'share']),DomainException::class);
selectionMcpRejected('AnyTour capability cannot write a second project of the same administrator',static fn()=>selectionMcp('save_tour_selection',['conversation_id'=>2,'version'=>0,'operation_id'=>str_repeat('0',32),'draft'=>deliveryDraft()]),DomainException::class);
deliveryCheck('cross-project denial creates no draft or operation',(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_drafts')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===0);
$pdo->exec("UPDATE conversations SET project_key='anytour' WHERE id=2");
ProjectConfig::resetForTests(['id'=>'other']);
selectionMcpRejected('selection adapter cannot run in another configured project',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft']),DomainException::class);
ProjectConfig::resetForTests(['id'=>'anytour']);
$afterAuthorizationHook=static fn()=>$pdo->exec("UPDATE conversations SET project_key='other' WHERE id=1");
selectionMcpRejected('a target-project change on the final authorization boundary suppresses old text',static fn()=>selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'draft']),DomainException::class);
$afterAuthorizationHook=null;$pdo->exec("UPDATE conversations SET project_key='anytour' WHERE id=1");

$draft=deliveryDraft('Synthetic MCP hotel');$operation=str_repeat('a',32);
$save=['conversation_id'=>1,'version'=>0,'operation_id'=>$operation,'draft'=>$draft];
$saved=selectionMcp('save_tour_selection',$save)['structuredContent'];
deliveryCheck('save uses the canonical draft version and operation receipt',$saved['ok']&&$saved['saved_version']===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===1);
$again=selectionMcp('save_tour_selection',$save)['structuredContent'];
deliveryCheck('the original save receipt does not create a duplicate version',$again['ok']&&$again['saved_version']===1&&(int)$pdo->query('SELECT version FROM manager_tour_selection_drafts WHERE conversation_id=1')->fetchColumn()===1);
$changed=$save;$changed['draft']['offers'][0]['amount']='260000';
deliveryCheck('an operation ID cannot be reused for a different price',selectionMcp('save_tour_selection',$changed)['structuredContent']['error']==='operation_mismatch');
$changed['operation_id']=str_repeat('b',32);
deliveryCheck('stale draft CAS cannot overwrite the saved terms',selectionMcp('save_tour_selection',$changed)['structuredContent']['error']==='conflict');
$receipt=selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'operation','operation_id'=>$operation])['structuredContent'];
deliveryCheck('unknown-save reconciliation returns the original receipt and exact conditions',$receipt['found']&&$receipt['saved_version']===1&&$receipt['selection']['draft']['offers'][0]['amount']==='240000'&&count($receipt['selection']['draft']['offers'])===1);
deliveryCheck('another conversation cannot claim that operation',selectionMcp('read_tour_selection',['conversation_id'=>2,'view'=>'operation','operation_id'=>$operation])['structuredContent']['error']==='operation_mismatch');
$pdo->exec("UPDATE conversations SET status='closed' WHERE id=1");
$closed=$save;$closed['version']=1;$closed['operation_id']=str_repeat('f',32);
deliveryCheck('canonical closed-conversation edit denial remains authoritative',selectionMcp('save_tour_selection',$closed)['structuredContent']['error']==='forbidden');
$pdo->exec("UPDATE conversations SET status='manager' WHERE id=1");

deliveryCheck('preparation rejects a stale saved version',selectionMcp('prepare_tour_selection',['conversation_id'=>1,'version'=>2])['structuredContent']['error']==='draft_changed');
$prepared=selectionMcp('prepare_tour_selection',['conversation_id'=>1,'version'=>1])['structuredContent'];$publication=$prepared['publication']['id'];
$frozen=$pdo->query('SELECT selection_json FROM manager_tour_selection_publications')->fetchColumn();
deliveryCheck('private preparation freezes the exact canonical party price and all conditions',
    $prepared['ok']&&$prepared['publication']['cards'][0]['snapshot']['amount']==='240000'
    &&$prepared['publication']['cards'][0]['snapshot']['child_ages']===[4,4]
    &&$prepared['publication']['cards'][0]['snapshot']['room']==='Семейный номер');
deliveryCheck('identical preparation reuses the immutable publication',selectionMcp('prepare_tour_selection',['conversation_id'=>1,'version'=>1])['structuredContent']['publication']['id']===$publication&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_publications')->fetchColumn()===1);
$history=selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'history'])['structuredContent'];
deliveryCheck('publication history supports preparation reconciliation',count($history['publications'])===1&&$history['publications'][0]['publication_id']===$publication);
$publicationRead=selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'publication','publication_id'=>$publication]);
deliveryCheck('readback contains the same frozen publication',$publicationRead['structuredContent']['publication']['id']===$publication);
selectionMcpRejected('publication requires an explicit affirmative intent',static fn()=>selectionMcp('publish_tour_selection',['conversation_id'=>1,'share_version'=>0,'publication_id'=>$publication,'confirm_publish'=>false]));
selectionMcpRejected('publication refuses a forged token or actor argument',static fn()=>selectionMcp('publish_tour_selection',['conversation_id'=>1,'share_version'=>0,'publication_id'=>$publication,'confirm_publish'=>true,'token'=>str_repeat('0',64)]));

$private=sys_get_temp_dir().'/selection-mcp-'.bin2hex(random_bytes(12));mkdir($private,0700);DiagnosticLogger::setFile($private.'/events.log');
try {
    $publish=['conversation_id'=>1,'share_version'=>0,'publication_id'=>$publication,'confirm_publish'=>true];
    deliveryCheck('publish cannot bypass the canonical photo-preview cache guard',selectionMcp('publish_tour_selection',$publish)['structuredContent']['error']==='preparation_failed');
    $imageBytes=file_get_contents(dirname(__DIR__).'/assets/owner-tour-cards/compact-v1/cover.jpg');
    $q=$pdo->prepare('SELECT offer_id FROM manager_tour_selection_cards WHERE publication_id=?');$q->execute([$publication]);$offerId=(int)$q->fetchColumn();
    foreach ($draft['offers'][0]['photos'] as $index=>$photo) TourSelectionPhotoStore::image(TourSelectionPhotoStore::offerKey($offerId),0,$index,$photo['url'],static fn():string=>$imageBytes);
    foreach ([0,1,2] as $index) {
        $photo=selectionMcp('review_tour_selection_photo',['conversation_id'=>1,'publication_id'=>$publication,'card_index'=>0,'photo_index'=>$index]);
        deliveryCheck('photo'.$index.' review returns real validated bytes and no private path',
            !$photo['isError']&&count($photo['content'])===2&&$photo['content'][1]['type']==='image'
            &&base64_decode($photo['content'][1]['data'],true)===$imageBytes
            &&$photo['structuredContent']['photo']['sha256']===hash('sha256',$imageBytes)
            &&!str_contains(json_encode($photo,JSON_THROW_ON_ERROR),$private));
    }
    $afterAuthorizationHook=static fn()=>$pdo->exec('DELETE FROM manager_projects WHERE manager_id=90 AND project_id=1');
    selectionMcpRejected('late conversation ACL loss suppresses already loaded image bytes',static fn()=>selectionMcp('review_tour_selection_photo',['conversation_id'=>1,'publication_id'=>$publication,'card_index'=>0,'photo_index'=>0]),DomainException::class);
    $afterAuthorizationHook=null;$pdo->exec('INSERT INTO manager_projects VALUES(90,1)');
    deliveryCheck('photo review rejects another publication context',selectionMcp('review_tour_selection_photo',['conversation_id'=>2,'publication_id'=>$publication,'card_index'=>0,'photo_index'=>0])['structuredContent']['error']==='not_found');
    selectionMcpRejected('photo index is bounded',static fn()=>selectionMcp('review_tour_selection_photo',['conversation_id'=>1,'publication_id'=>$publication,'card_index'=>0,'photo_index'=>3]));
    $published=selectionMcp('publish_tour_selection',$publish)['structuredContent'];
    deliveryCheck('publication creates one native canonical link and share receipt',$published['ok']&&$published['share']['active']&&$published['share']['version']===1&&$published['share']['publication_id']===$publication&&str_starts_with($published['share']['path'],'/selection/?t='));
    $share=selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'share'])['structuredContent'];
    deliveryCheck('share readback reconciles the actual published version',$share['share']['path']===$published['share']['path']&&$share['share']['publication_id']===$publication);
    deliveryCheck('stale publish CAS cannot create another link/history event',selectionMcp('publish_tour_selection',$publish)['structuredContent']['error']==='share_changed'&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_link_history')->fetchColumn()===1);
    $token=substr($published['share']['path'],strlen('/selection/?t='));$public=ManagerTourSelectionShareService::read($token);
    deliveryCheck('public reader keeps the exact terms with unverified observed price',$public['offers'][0]['amount']==='240000'&&$public['offers'][0]['price_verified']===false&&$public['offers'][0]['room']==='Семейный номер');
    deliveryCheck('public reader does not expose private conversation identities',!str_contains(json_encode($public,JSON_THROW_ON_ERROR),'external_chat_id')&&!str_contains(json_encode($public,JSON_THROW_ON_ERROR),'Synthetic client'));

    $late=$save;$late['version']=1;$late['operation_id']=str_repeat('1',32);$late['draft']['offers'][0]['amount']='270000';$lateDeny=true;
    selectionMcpRejected('late authorization loss does not falsely report a committed save as rollback',static fn()=>selectionMcp('save_tour_selection',$late),DomainException::class);
    $lateDeny=false;
    $reconciled=selectionMcp('read_tour_selection',['conversation_id'=>1,'view'=>'operation','operation_id'=>$late['operation_id']])['structuredContent'];
    deliveryCheck('the original operation receipt reconciles a late-denied committed save',$reconciled['found']&&$reconciled['saved_version']===2&&$reconciled['selection']['draft']['offers'][0]['amount']==='270000');
    deliveryCheck('later draft changes preserve the prior immutable publication and shared price',$pdo->query('SELECT selection_json FROM manager_tour_selection_publications')->fetchColumn()===$frozen&&ManagerTourSelectionShareService::read($token)['offers'][0]['amount']==='240000');

    // A valid existing canonical/UI draft can exceed the MCP text budget. Both
    // its complete read and original operation receipt must remain reconcilable.
    $large=deliveryDraft('Large synthetic fixture');$offer=$large['offers'][0];
    foreach (['hotel'=>191,'destination'=>160,'departure'=>160,'room'=>300,'meal'=>160,'source_label'=>160,'rationale'=>600,'compromise'=>600,'flights'=>1200,'inclusions'=>600,'constraints'=>1200] as $key=>$length)
        $offer[$key]=str_repeat("\u{1F3E8}",$length);
    $url='https://photos.example.org/'.str_repeat('a',2048-strlen('https://photos.example.org/')-4).'.jpg';
    $offer['source_url']=$url;foreach($offer['photos'] as &$photo)$photo['url']=$url;unset($photo);
    $large['offers']=[];for($i=1;$i<=20;$i++)$large['offers'][]=array_replace($offer,['id'=>str_pad(dechex($i),32,'0',STR_PAD_LEFT)]);
    $largeOperation=str_repeat('e',32);$largeSave=['conversation_id'=>2,'version'=>0,'operation_id'=>$largeOperation,'draft'=>$large];
    $refused=selectionMcp('save_tour_selection',$largeSave)['structuredContent'];
    deliveryCheck('over-budget new input is rejected before a canonical save',$refused['error']==='selection_input_too_large'&&$refused['write_attempted']===false&&!isset($refused['committed'])&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_drafts WHERE conversation_id=2')->fetchColumn()===0);
    $largeSaved=ManagerTourSelectionService::save(90,2,['version'=>0,'operation_id'=>$largeOperation,'draft'=>$large]);
    deliveryCheck('large multibyte fixture is genuinely valid canonical data beyond512KiB',$largeSaved['ok']&&strlen(json_encode($largeSaved,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>524288);
    $largeRead=selectionMcp('read_tour_selection',['conversation_id'=>2,'view'=>'draft'])['structuredContent'];
    deliveryCheck('large existing draft is explicitly incomplete metadata, never truncated terms',$largeRead['ok']&&$largeRead['complete']===false&&$largeRead['omitted']===['selection.draft']&&!isset($largeRead['selection']['draft'])&&$largeRead['selection']['version']===1);
    $largeReceipt=selectionMcp('read_tour_selection',['conversation_id'=>2,'view'=>'operation','operation_id'=>$largeOperation])['structuredContent'];
    deliveryCheck('large existing operation still returns its bounded original receipt',$largeReceipt['ok']&&$largeReceipt['found']&&$largeReceipt['saved_version']===1&&$largeReceipt['selection']['version']===1&&$largeReceipt['complete']===false&&!isset($largeReceipt['selection']['draft']));
} finally {
    // Only this fixture's random private directory; no production/cache cleanup.
    foreach (glob($private.'/tour-selection-photos/*')?:[] as $file) if(is_file($file))unlink($file);
    if(is_dir($private.'/tour-selection-photos'))rmdir($private.'/tour-selection-photos');
    if(is_file($private.'/events.log'))unlink($private.'/events.log');
    rmdir($private);DiagnosticLogger::setFile('');
}
deliveryCheck('all tools leave native sends, read markers, shifts and assignments untouched',selectionMcpBusinessRows()===$before);
deliveryCheck('no delivery has left pending and no outbound customer message exists',
    (int)$pdo->query("SELECT COUNT(*) FROM manager_tour_selection_deliveries WHERE state<>'pending'")->fetchColumn()===0
    &&(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0);
// Integration uses actual OAuth grants and the real general MCP dispatcher.
// No mock authorization callback substitutes for these boundary assertions.
require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/SeparatedMcpService.php';
ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic workspace']]);
$pdo->exec('CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);');
$oauth=new ManagerPreparationOAuth($pdo);
$client=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'selection-mcp-fixture')['client_id'];
function selectionRealGrant(ManagerPreparationOAuth $oauth,string $client,string $scope,string $profile=ManagerPreparationOAuth::PROFILE_LEGACY): array
{
    $resource=ManagerPreparationOAuth::profileDefinition($profile)['resource'];$verifier=str_repeat('v',43);$session=['csrf'=>'selection-fixture-csrf'];
    $pending=$oauth->beginAuthorization($session,['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'resource'=>$resource,
        'response_type'=>'code','scope'=>$scope,'state'=>'selection-fixture-state','code_challenge_method'=>'S256',
        'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')]);
    $redirect=$oauth->completeAuthorization($session,$pending,90,$session['csrf'],true);parse_str((string)parse_url($redirect,PHP_URL_QUERY),$query);
    $tokens=$oauth->exchange(['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>$resource,'code'=>$query['code'],'code_verifier'=>$verifier]);
    $context=$oauth->contextForBearer('Bearer '.$tokens['access_token']);
    if(!is_array($context))throw new RuntimeException('Real selection grant unavailable');
    return [$tokens,$context];
}
function selectionRealRpc(string $name,array $args): array
{
    return ['jsonrpc'=>'2.0','id'=>41,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]];
}
[$legacyTokens,$legacyContext]=selectionRealGrant($oauth,$client,implode(' ',ManagerPreparationOAuth::SCOPES));
[$selectionTokens,$selectionContext]=selectionRealGrant($oauth,$client,ManagerPreparationOAuth::SELECTION_SCOPE);
$all=implode(' ',ManagerPreparationOAuth::SUPPORTED_SCOPES);
[$allTokens,$allContext]=selectionRealGrant($oauth,$client,$all);
deliveryCheck('complete explicitly consented catalog including booking fits unchanged forward capacity',strlen($all)===152&&strlen($all)<=160&&$allTokens['scope']===$all&&$allContext['scopes']===ManagerPreparationOAuth::SUPPORTED_SCOPES);
deliveryCheck('old grants and reply defaults do not acquire selection access',$legacyTokens['scope']==='queue:read prepared:write'&&!$oauth->connectionAuthorized($legacyContext['connection_id'],90,[ManagerPreparationOAuth::SELECTION_SCOPE])&&ManagerPreparationOAuth::SCOPES===['queue:read','prepared:write']);
try {
    $oauth->exchange(['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,
        'refresh_token'=>$legacyTokens['refresh_token'],'scope'=>'queue:read prepared:write selections:write']);
    throw new RuntimeException('FAIL refresh silently expanded selection rights');
} catch(InvalidArgumentException $error) {
    deliveryCheck('refresh cannot silently add selection publication rights',$error->getMessage()==='invalid_scope'&&!$oauth->connectionAuthorized($legacyContext['connection_id'],90,[ManagerPreparationOAuth::SELECTION_SCOPE]));
}
$mcp=new ManagerPreparationMcpService($pdo);
$readRpc=selectionRealRpc('read_tour_selection',['conversation_id'=>1,'view'=>'draft']);
$denied=$mcp->handle($readRpc,90,$legacyContext['connection_id'],$legacyContext['scopes']);
deliveryCheck('real old grant receives explicit additive selection OAuth challenge',($denied['result']['isError']??false)===true&&str_contains($denied['result']['_meta']['mcp/www_authenticate'][0]??'','scope="queue:read prepared:write selections:write"'));
$read=$mcp->handle($readRpc,90,$selectionContext['connection_id'],$selectionContext['scopes']);
deliveryCheck('real selection-only grant reaches canonical draft without reply or dialogue rights',($read['result']['structuredContent']['ok']??false)===true&&$read['result']['structuredContent']['selection']['version']===2&&!in_array(ManagerPreparationOAuth::DIALOGUE_SCOPE,$selectionContext['scopes'],true));
$newSave=selectionRealRpc('save_tour_selection',['conversation_id'=>1,'version'=>2,'operation_id'=>str_repeat('9',32),'draft'=>$reconciled['selection']['draft']]);
$saved=$mcp->handle($newSave,90,$selectionContext['connection_id'],$selectionContext['scopes']);
deliveryCheck('real MCP dispatcher binds OAuth actor and canonical save CAS',($saved['result']['structuredContent']['saved_version']??null)===3);
$receiptRpc=selectionRealRpc('read_tour_selection',['conversation_id'=>1,'view'=>'operation','operation_id'=>str_repeat('9',32)]);
$receipt=$mcp->handle($receiptRpc,90,$selectionContext['connection_id'],$selectionContext['scopes']);
deliveryCheck('real MCP receipt read reconciles the original save',($receipt['result']['structuredContent']['found']??false)===true&&$receipt['result']['structuredContent']['saved_version']===3);
deliveryCheck('a different actor cannot reuse the real administrator connection',($mcp->handle($readRpc,7,$selectionContext['connection_id'],$selectionContext['scopes'])['error']['code']??null)===-32001);
$transcriptDenied=$mcp->handle(selectionRealRpc('list_current_dialogues',[]),90,$selectionContext['connection_id'],$selectionContext['scopes']);
deliveryCheck('selection-only grant requires separate consent before current transcript reads',($transcriptDenied['result']['isError']??false)===true&&str_contains($transcriptDenied['result']['_meta']['mcp/www_authenticate'][0]??'','dialogues:read')&&!isset($transcriptDenied['result']['structuredContent']['dialogues']));
$pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([time(),$selectionContext['connection_id']]);
deliveryCheck('revoked real grant cannot disclose an original operation receipt',($mcp->handle($receiptRpc,90,$selectionContext['connection_id'],$selectionContext['scopes'])['error']['code']??null)===-32001);
$pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$selectionContext['connection_id']]);
$pdo->exec('UPDATE projects SET is_active=0 WHERE id=1');
deliveryCheck('real grant loses selection access with its project',($mcp->handle($readRpc,90,$selectionContext['connection_id'],$selectionContext['scopes'])['error']['code']??null)===-32001);
$pdo->exec('UPDATE projects SET is_active=1 WHERE id=1');
foreach([ManagerPreparationOAuth::PROFILE_REPLIES,ManagerPreparationOAuth::PROFILE_HOTELS,ManagerPreparationOAuth::PROFILE_DEVELOPMENT] as $profile){
    $profileOAuth=new ManagerPreparationOAuth($pdo,null,$profile);$profileClient=$profileOAuth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'selection-mcp-'.$profile)['client_id'];
    selectionMcpRejected($profile.' audience cannot acquire selection capability',static fn()=>selectionRealGrant($profileOAuth,$profileClient,ManagerPreparationOAuth::SELECTION_SCOPE,$profile));
}
deliveryCheck('integrated OAuth and selection calls still never send a customer message',(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0);

// New capability: real grants, canonical saved choices, and no-write disclosure.
function selectionBookingChecks(PDO $pdo,ManagerPreparationOAuth $oauth,string $client,array $legacyContext,array $selectionContext,ManagerPreparationMcpService $mcp): void
{
require_once __DIR__.'/support/search3-tour-handoff.php';
require_once dirname(__DIR__).'/integrations/TelegramIncomingAdapter.php';
[$bookingTokens,$bookingContext]=selectionRealGrant($oauth,$client,ManagerPreparationOAuth::BOOKING_SCOPE);
$bookingRpc=static fn(array $args):array=>selectionRealRpc('read_tour_booking_context',$args);
$choicesRpc=selectionRealRpc('read_tour_choices',['conversation_id'=>1]);
foreach ([$legacyContext,$selectionContext] as $oldContext) {
    $beforeChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $denied=$mcp->handle($choicesRpc,90,$oldContext['connection_id'],$oldContext['scopes']);
    deliveryCheck('existing grants require new booking consent without any writes',($denied['result']['isError']??false)===true
        &&str_contains($denied['result']['_meta']['mcp/www_authenticate'][0]??'','bookings:read')
        &&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$beforeChanges
        &&!isset($denied['result']['structuredContent']['choices']));
}
deliveryCheck('booking-only grant does not gain selection write or transcript rights',
    !$oauth->connectionAuthorized($bookingContext['connection_id'],90,[ManagerPreparationOAuth::SELECTION_SCOPE])
    &&!$oauth->connectionAuthorized($bookingContext['connection_id'],90,[ManagerPreparationOAuth::DIALOGUE_SCOPE]));
$pdo->exec("UPDATE conversations SET customer_id=1,customer_channel_id=1,project_key='anytour',channel='telegram',external_chat_id='101',status='manager' WHERE id=1");
$snapshot=['hotel'=>'Synthetic booking choice','departure'=>'Москва','date_from'=>'2027-06-12','date_to'=>'2027-06-19','nights'=>7,'adults'=>2,'child_ages'=>[],
    'room'=>'Standard','meal'=>'AI','flights'=>[],'amount'=>'180000.50','currency'=>'RUB','price_basis'=>'party','is_demo'=>false];
$native=TourChoiceService::prepare($pdo,1,$snapshot,7);
deliveryCheck('synthetic native choice preparation succeeds',$native['ok']);
deliveryCheck('synthetic delivered receipt is canonical',TourChoiceService::recordDelivered($pdo,$native['offer_id'],'1777','101')['ok']);
$incoming=TelegramIncomingAdapter::fromUpdate(['callback_query'=>['id'=>'synthetic-booking-like','data'=>$native['callback_data'],'from'=>['id'=>101],
    'message'=>['message_id'=>1777,'chat'=>['id'=>101,'type'=>'private']]]]);$incoming['project_key']='anytour';
deliveryCheck('synthetic customer native like is genuine',TourChoiceService::like($pdo,$incoming)['ok']);
$unliked=TourChoiceService::prepare($pdo,1,$snapshot,7);
TourChoiceService::recordDelivered($pdo,$unliked['offer_id'],'1778','101');
$pdo->beginTransaction();
$web=TourChoiceService::webInquiryInTransaction($pdo,1,'101',str_repeat('a',64),['public_offer'=>['hotel'=>'Synthetic web choice','amount'=>'120000','currency'=>'RUB','date_from'=>'2027-06-12'],
    'publication_id'=>str_repeat('a',32),'version'=>7,'card'=>0]);
$intent=search3FixtureIntent();
$search=TourChoiceService::searchInquiryInTransaction($pdo,1,'101',str_repeat('b',64),
    ['origin'=>'search3','handoff_id'=>str_repeat('b',32),'snapshot'=>['binding'=>$intent['binding'],'observation'=>$intent['observation']]]);
$pdo->commit();
deliveryCheck('synthetic web and search inquiries are canonical',$web['ok']&&$search['ok']);
$changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$eventsBefore=$pdo->query('SELECT * FROM conversation_events ORDER BY id')->fetchAll();
$markersBefore=$pdo->query('SELECT * FROM manager_conversation_reads ORDER BY 1')->fetchAll();
$readChoices=$mcp->handle($choicesRpc,90,$bookingContext['connection_id'],$bookingContext['scopes']);
$choices=$readChoices['result']['structuredContent']['choices']??[];
$find=static function(string $kind,?int $id=null)use(&$choices):array{foreach($choices as $choice)if($choice['source_kind']===$kind&&($id===null||$choice['source_id']===$id))return $choice;throw new RuntimeException('Expected synthetic choice absent');};
$chosen=$find('native',$native['offer_id']);$notChosen=$find('native',$unliked['offer_id']);
deliveryCheck('current native like has exact immutable revision and delivery is not a choice',
    $chosen['signal']==='native_like'&&$chosen['booking_eligible']&&strlen($chosen['choice_revision'])===64
    &&!$notChosen['booking_eligible']&&$notChosen['signal']==='delivered_unliked');
foreach (['web','search'] as $kind) deliveryCheck($kind.' genuine inquiry is returned as current source',$find($kind)['booking_eligible']===true);
$args=['conversation_id'=>1,'source_kind'=>'native','source_id'=>$native['offer_id'],'choice_revision'=>$chosen['choice_revision']];
$context=$mcp->handle($bookingRpc($args),90,$bookingContext['connection_id'],$bookingContext['scopes']);
$data=$context['result']['structuredContent']??[];
deliveryCheck('new booking grant loads exact context without inventing a visible form',
    ($data['ok']??false)&&$data['source']['choice']===$chosen['choice']&&$data['record']===null
    &&$data['context_loaded']===true&&$data['visual_form_opened']===false&&$data['price_refreshed']===false);
foreach (['web','search'] as $kind) {
    $source=$find($kind);$r=$mcp->handle($bookingRpc(['conversation_id'=>1,'source_kind'=>$kind,'source_id'=>$source['source_id'],'choice_revision'=>$source['choice_revision']]),90,$bookingContext['connection_id'],$bookingContext['scopes']);
    deliveryCheck($kind.' source reaches canonical readonly booking context',($r['result']['structuredContent']['source']['choice']??null)===$source['choice']);
}
deliveryCheck('choice and context reads make no event, marker or other DB writes',
    (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes
    &&$pdo->query('SELECT * FROM conversation_events ORDER BY id')->fetchAll()===$eventsBefore
    &&$pdo->query('SELECT * FROM manager_conversation_reads ORDER BY 1')->fetchAll()===$markersBefore);
$bad=$args;$bad['source_id']=$unliked['offer_id'];$bad['choice_revision']=$notChosen['choice_revision'];
deliveryCheck('delivered unliked source cannot load a booking',($mcp->handle($bookingRpc($bad),90,$bookingContext['connection_id'],$bookingContext['scopes'])['error']['code']??null)===-32001);
foreach (['kind'=>static function(&$a){$a['source_kind']='web';},'id'=>static function(&$a){$a['source_id']=PHP_INT_MAX;},'revision'=>static function(&$a){$a['choice_revision']=str_repeat('0',64);},'conversation'=>static function(&$a){$a['conversation_id']=2;}] as $label=>$mutate) {
    $bad=$args;$mutate($bad);$r=$mcp->handle($bookingRpc($bad),90,$bookingContext['connection_id'],$bookingContext['scopes']);
    deliveryCheck('mismatched '.$label.' denies without partial context',isset($r['error'])&&!isset($r['result']));
}
$fields=TourBookingService::normalize(['state'=>'checking','amount'=>'','currency'=>'','checked_at'=>'','verification_source'=>'Synthetic verification',
    'terms'=>'Private synthetic terms','agreement_evidence'=>'Private synthetic agreement','operator'=>'Synthetic operator','booking_reference'=>'TEST-PRIVATE-ONLY',
    'confirmed_at'=>'','cancellation_reason'=>'','available'=>false,'customer_agreed'=>false]);
$saved=TourBookingService::save($pdo,90,1,['source_kind'=>'native','source_id'=>$native['offer_id'],'revision'=>0,'operation_id'=>str_repeat('e',32),'fields'=>$fields],static fn():bool=>true);
deliveryCheck('synthetic stored worksheet is prepared before read-only checks',$saved['ok']);
$changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$r=$mcp->handle($bookingRpc($args),90,$bookingContext['connection_id'],$bookingContext['scopes']);$record=$r['result']['structuredContent']['record']??[];
deliveryCheck('explicitly consented record retains declared private categories without raw account data',
    ($record['fields']??null)===$fields&&array_keys($record)===['revision','updated_at','fields']
    &&!str_contains(json_encode($r),'actor_id')&&!str_contains(json_encode($r),'binding')
    &&!str_contains(json_encode($r),$native['callback_data'])&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes);
$likeRow=$pdo->query("SELECT id,payload_json FROM conversation_events WHERE event_type='tour_offer_liked' ORDER BY id DESC LIMIT 1")->fetch();
$alter=$pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=?');
$alter->execute([json_encode(['offer_id'=>PHP_INT_MAX]),$likeRow['id']]);
deliveryCheck('canonical old record alone can still exist after current like is absent',TourBookingService::read($pdo,1,'native',$native['offer_id'])['ok']);
deliveryCheck('old record cannot substitute for the missing current choice',($mcp->handle($bookingRpc($args),90,$bookingContext['connection_id'],$bookingContext['scopes'])['error']['code']??null)===-32001);
$alter->execute([$likeRow['payload_json'],$likeRow['id']]);
$preparedPayload=$pdo->query('SELECT payload_json FROM conversation_events WHERE id='.(int)$native['offer_id'])->fetchColumn();
$changedPayload=json_decode($preparedPayload,true);$changedPayload['snapshot']['amount']='190000';
$alter->execute([json_encode($changedPayload),$native['offer_id']]);
deliveryCheck('same source ID with changed frozen source rejects old revision',($mcp->handle($bookingRpc($args),90,$bookingContext['connection_id'],$bookingContext['scopes'])['error']['code']??null)===-32001);
$new=$mcp->handle($choicesRpc,90,$bookingContext['connection_id'],$bookingContext['scopes']);$newChoices=$new['result']['structuredContent']['choices'];
$newRevision=null;foreach($newChoices as $choice)if($choice['source_kind']==='native'&&$choice['source_id']===$native['offer_id'])$newRevision=$choice['choice_revision'];
$changedArgs=$args;$changedArgs['choice_revision']=$newRevision;
deliveryCheck('current revision cannot disclose a stored record with the old source',($mcp->handle($bookingRpc($changedArgs),90,$bookingContext['connection_id'],$bookingContext['scopes'])['error']['code']??null)===-32001);
$alter->execute([$preparedPayload,$native['offer_id']]);
$hook=null;$calls=0;
$guarded=new ManagerTourSelectionMcpAdapter(static function(int $actor,string $family,array $required)use($oauth,$pdo,&$hook,&$calls,$bookingContext):void{
    ++$calls;if($calls===2&&$hook!==null)$hook();
    if($actor!==90||$family!==$bookingContext['connection_id']||$required!==[ManagerPreparationOAuth::BOOKING_SCOPE]
        ||!$oauth->connectionAuthorized($family,$actor,$required))throw new DomainException();
});
foreach ([
    'customer'=>[static fn()=>$pdo->exec('UPDATE conversations SET customer_id=2 WHERE id=1'),static fn()=>$pdo->exec('UPDATE conversations SET customer_id=1 WHERE id=1')],
    'native subject'=>[static fn()=>$pdo->exec("UPDATE customer_channels SET external_user_id='999' WHERE id=1"),static fn()=>$pdo->exec("UPDATE customer_channels SET external_user_id='101' WHERE id=1")],
    'channel chat'=>[static fn()=>$pdo->exec("UPDATE customer_channels SET external_chat_id='999' WHERE id=1"),static fn()=>$pdo->exec("UPDATE customer_channels SET external_chat_id='101' WHERE id=1")],
    'conversation ACL'=>[static fn()=>$pdo->exec("UPDATE conversations SET project_key='other' WHERE id=1"),static fn()=>$pdo->exec("UPDATE conversations SET project_key='anytour' WHERE id=1")],
    'admin'=>[static fn()=>$pdo->exec('UPDATE managers SET is_active=0 WHERE id=90'),static fn()=>$pdo->exec('UPDATE managers SET is_active=1 WHERE id=90')],
    'grant'=>[static fn()=>$pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([time(),$bookingContext['connection_id']]),static fn()=>$pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$bookingContext['connection_id']])],
    'current like'=>[static fn()=>$alter->execute([json_encode(['offer_id'=>PHP_INT_MAX]),$likeRow['id']]),static fn()=>$alter->execute([$likeRow['payload_json'],$likeRow['id']])]
] as $label=>[$change,$restore]) {
    $calls=0;$hook=$change;
    try {selectionMcpRejected('final '.$label.' change suppresses all booking data',static fn()=>$guarded->call('read_tour_booking_context',$args,90,$bookingContext['connection_id']),DomainException::class);}
    finally {$hook=null;$restore();}
}
deliveryCheck('new readonly APIs still never send customer messages',(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0);
}
selectionBookingChecks($pdo,$oauth,$client,$legacyContext,$selectionContext,$mcp);

echo 'Selection MCP adapter and real OAuth integration: '.$checks." checks passed\n";
