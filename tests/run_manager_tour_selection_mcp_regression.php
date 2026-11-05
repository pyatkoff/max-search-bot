<?php
declare(strict_types=1);
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

$definitions=ManagerTourSelectionMcpAdapter::tools();$byName=array_column($definitions,null,'name');
deliveryCheck('only the five selection actions are exposed',array_keys($byName)===ManagerTourSelectionMcpAdapter::NAMES);
foreach ($definitions as $tool) {
    deliveryCheck($tool['name'].' declares explicit selection capability and rejects extra arguments',
        $tool['securitySchemes']===[['type'=>'oauth2','scopes'=>['selections:write']]]
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
deliveryCheck('complete explicitly consented selection and dialogue catalog fits actual forward capacity',strlen($all)===125&&strlen($all)<=128&&$allTokens['scope']===$all&&$allContext['scopes']===ManagerPreparationOAuth::SUPPORTED_SCOPES);
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
echo 'Selection MCP adapter and real OAuth integration: '.$checks." checks passed\n";
