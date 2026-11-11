<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/fixtures/manager_regional_routing_fixture.php';

final class RegionalRoutingFixturePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('NOW',static fn():string=>'2026-11-11 13:00:00');
        $this->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value);
        $this->sqliteCreateFunction('GREATEST',static fn(...$values)=>max($values),-1);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        return parent::prepare(str_replace('INSERT IGNORE INTO','INSERT OR IGNORE INTO',$query),$options);
    }
}

$pdo=new RegionalRoutingFixturePdo();regionalRoutingSchema($pdo);regionalRoutingBind($pdo);
$scopes=regionalRoutingInstallScopes($pdo);$checks=0;
function regionalCheck(string $label,bool $ok):void
{
    global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";
}
function regionalVisible(PDO $pdo,int $manager,int $conversation):bool
{
    return RoutingAccessService::canSeeConversation($manager,regionalRoutingStored($pdo,$conversation));
}
function regionalManagerData(int $id,array $changes=[]):array
{
    return $changes+['manager_id'=>$id,'login'=>'regional-native-kras','display_name'=>'Synthetic Krasnoyarsk',
        'role'=>'manager','is_active'=>1,'priority'=>0,'email'=>'','project_ids'=>[1],'password'=>''];
}

regionalCheck('Krasnoyarsk source city and federal spelling share one reviewed territory',
    in_array('62',$scopes['62']['region_ids'],true)&&in_array('11303',$scopes['62']['region_ids'],true));
regionalCheck('Samara territory contains its capital and federal spelling variant',
    in_array('51',$scopes['11131']['region_ids'],true)&&in_array('11133',$scopes['11131']['region_ids'],true));
regionalCheck('Same-named Samara in other provinces never enters the Samara territory',
    !array_intersect(['128340','128955','153277'],$scopes['11131']['region_ids']));
foreach([['225'],['62','62'],['062'],['62 OR 1=1'],['300005']]as$invalid){
    $rejected=false;try{RegionalTerritoryCatalog::selected($invalid);}catch(InvalidArgumentException $error){$rejected=true;}
    regionalCheck('Territory selection rejects country agency duplicate or noncanonical IDs '.json_encode($invalid),$rejected);
}

regionalRoutingConversation($pdo,101,'62');regionalRoutingConversation($pdo,102,'51');
regionalRoutingConversation($pdo,103,'11303');regionalRoutingConversation($pdo,104,'11133');
regionalRoutingConversation($pdo,105,'128340');
regionalRoutingConversation($pdo,106,'62',['project_key'=>'other']);
regionalRoutingConversation($pdo,107,'62',['source_id'=>3]);
regionalRoutingConversation($pdo,108,'62',['source_id'=>2]);
regionalRoutingConversation($pdo,109,'51',['status'=>'manager','manager_id'=>7]);
regionalRoutingConversation($pdo,110,'51',['status'=>'manager','manager_id'=>8]);
regionalRoutingConversation($pdo,111,'62',['metadata_json'=>'{}']);
regionalRoutingConversation($pdo,112,'99999999999999999999');
regionalRoutingConversation($pdo,113,'62',['channel'=>'telegram']);
regionalRoutingConversation($pdo,114,'62',['channel'=>'website']);
regionalRoutingConversation($pdo,115,'62',['status'=>'manager','manager_id'=>7,'source_id'=>3]);
regionalRoutingConversation($pdo,117,'62',['status'=>'manager','manager_id'=>8]);
regionalRoutingConversation($pdo,118,'51',['status'=>'manager','manager_id'=>8]);
regionalRoutingConversation($pdo,119,'62',['manager_id'=>0]);
$before=$pdo->query('SELECT id,status,manager_id FROM conversations ORDER BY id')->fetchAll();
foreach([101=>true,102=>false,103=>true,104=>false,105=>false,106=>false,107=>false,108=>true,
    109=>true,110=>false,111=>false,112=>false,113=>true,114=>true,115=>false,117=>false,118=>false,119=>true]as$id=>$expected){
    regionalCheck('Krasnoyarsk manager singleton intersects project source first region and ownership '.$id,regionalVisible($pdo,7,$id)===$expected);
}
regionalCheck('Samara manager sees only its own regional queue',regionalVisible($pdo,8,102)&&regionalVisible($pdo,8,104)&&!regionalVisible($pdo,8,101)&&!regionalVisible($pdo,8,105));
regionalCheck('Matching recipient eligibility does not expose another current owner dialogue',
    RoutingAccessService::canReceiveConversation(7,regionalRoutingStored($pdo,117))&&!regionalVisible($pdo,7,117)
    &&!RoutingAccessService::canReceiveConversation(7,regionalRoutingStored($pdo,118)));
regionalCheck('Legacy manager without opt-in retains prior project access',regionalVisible($pdo,9,101)&&regionalVisible($pdo,9,102)&&regionalVisible($pdo,9,111));
regionalCheck('Administrator keeps normal project-scoped administration',regionalVisible($pdo,90,102)&&regionalVisible($pdo,90,111));
$rows=$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll();
$expected=[101,103,108,109,113,114,119];$batch=RoutingAccessService::visibleConversations(7,$rows);
regionalCheck('Batch and singleton regional ACL agree exactly and preserve row order',array_map('intval',array_column($batch,'id'))===$expected);
$zeroArgs=[];$zeroPredicate=RegionalRoutingScopeService::candidatePredicate(7,'c',$zeroArgs);
$q=$pdo->prepare('SELECT id FROM conversations c WHERE c.id=119 AND '.$zeroPredicate);$q->execute($zeroArgs);
regionalCheck('The regional SQL predicate treats a historical zero owner like singleton ACL without changing queue policy',(int)$q->fetchColumn()===119);
regionalCheck('Regional access decisions cannot assign or change the dialogue status',$pdo->query('SELECT id,status,manager_id FROM conversations ORDER BY id')->fetchAll()===$before);
$forged=regionalRoutingStored($pdo,102);$forged['metadata_json']=regionalRoutingStored($pdo,101)['metadata_json'];
regionalCheck('A caller cannot lend another conversation its in-memory advertising metadata',RegionalRoutingScopeService::visibleRows(7,[$forged])===[]);
$forged=regionalRoutingStored($pdo,101);$forged['project_key']='other';
regionalCheck('A caller cannot substitute a project on a valid regional row',RegionalRoutingScopeService::visibleRows(7,[$forged])===[]);

$pdo->exec('UPDATE managers SET is_working=0 WHERE id=7');
regionalCheck('Regional access does not bypass the existing off-shift waiting gate',!regionalVisible($pdo,7,101)&&regionalVisible($pdo,7,109));
$pdo->exec('UPDATE managers SET is_working=1 WHERE id=7');
regionalCheck('Returning on shift uses fresh availability',regionalVisible($pdo,7,101));

$first=ConversationEntryRegionService::firstReceipt(regionalRoutingStored($pdo,101)['metadata_json'],'max');
$entry=['platform'=>'max','type'=>'bot_started','delivery_key'=>'synthetic-later-samara','start_payload'=>'123456_region_51_campaign_99'];
regionalCheck('A repeat entry records the new advertising region',ConversationEntryRegionService::record(101,'anytour',$entry,$pdo,1794390000));
$stored=regionalRoutingStored($pdo,101);$metadata=json_decode($stored['metadata_json'],true,32,JSON_THROW_ON_ERROR);
regionalCheck('The initial regional receipt stays immutable while latest attribution advances',
    $metadata['first_entry_region']===$first&&$metadata['entry_region']['yandex_region_id']==='51'&&$stored['attribution_region']==='51');
regionalCheck('A new Samara click cannot transfer an existing Krasnoyarsk regional dialogue',regionalVisible($pdo,7,101)&&!regionalVisible($pdo,8,101));
$metadata['trip']=['city'=>'Москва','no_flight'=>true];$pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=101')->execute([json_encode($metadata,JSON_THROW_ON_ERROR)]);
regionalCheck('Travel departure and no-flight values cannot replace the first click region',regionalVisible($pdo,7,101)&&!regionalVisible($pdo,8,101));
$onlyLatest=['entry_region'=>regionalRoutingReceipt('62')];regionalRoutingConversation($pdo,116,'62',['metadata_json'=>json_encode($onlyLatest)]);
regionalCheck('A latest receipt without a first receipt cannot grant regional access',!regionalVisible($pdo,7,116));

$invalidReceipts=[];$valid=regionalRoutingReceipt('62');
foreach(['version'=>'1','kind'=>'ip','platform'=>'telegram','source'=>'untrusted','yandex_region_id'=>62,
    'recorded_at_unix'=>'1794300000','receipt_sha256'=>str_repeat('A',64),'name'=>str_repeat('x',401)]as$field=>$value){$bad=$valid;$bad[$field]=$value;$invalidReceipts[$field]=json_encode(['first_entry_region'=>$bad]);}
$invalidReceipts['invalid_json']='{';$invalidReceipts['oversized']=json_encode(['first_entry_region'=>$valid,'padding'=>str_repeat('x',262145)]);
foreach($invalidReceipts as$label=>$json){
    regionalRoutingConversation($pdo,200+count($invalidReceipts),'62',['metadata_json'=>$json]);
    $id=200+count($invalidReceipts);regionalCheck('Malformed first receipt fails closed: '.$label,!regionalVisible($pdo,7,$id));
    $pdo->prepare('DELETE FROM conversations WHERE id=?')->execute([$id]);
}

// Revoking membership or scopes never clears the durable opt-in mode.
$pdo->exec('DELETE FROM manager_group_members WHERE group_id=10 AND manager_id=7');
regionalCheck('Group-membership revocation immediately removes regional queue access',!regionalVisible($pdo,7,101)&&regionalVisible($pdo,7,109));
$pdo->exec('INSERT INTO manager_group_members VALUES(10,7)');
$saved=RoutingAdminService::saveGroupResult(90,'anytour',10,'kras','Synthetic Krasnoyarsk group',[7],null);
regionalCheck('A legacy group save with omitted territory input preserves its scopes',!empty($saved['ok'])&&regionalVisible($pdo,7,101));
$saved=RoutingAdminService::saveGroupResult(90,'anytour',10,'kras','Synthetic Krasnoyarsk group',[7],[]);
regionalCheck('An explicit empty territory list revokes access instead of making it unrestricted',!empty($saved['ok'])&&!regionalVisible($pdo,7,101)&&RegionalRoutingScopeService::regionalProjectIds(7)===[1]);
regionalCheck('Non-admin and cross-project group edits cannot widen a regional grant',
    (RoutingAdminService::saveGroupResult(7,'anytour',10,'kras','Forbidden',[7],['62'])['error']??'')==='admin_required'
    &&(RoutingAdminService::saveGroupResult(90,'anytour',12,'foreign','Forbidden',[7],['62'])['error']??'')==='group_project_mismatch');
regionalRoutingInstallScopes($pdo);
$saved=AdminDirectoryService::saveManager(regionalManagerData(7),90);
regionalCheck('A manager edit omitting regional-project input preserves the restriction',!empty($saved['ok'])&&RegionalRoutingScopeService::regionalProjectIds(7)===[1]);
$saved=AdminDirectoryService::saveManager(regionalManagerData(7,['project_ids'=>[],'regional_project_ids'=>[],'is_active'=>0]),90);
regionalCheck('Revoking a project grant preserves its durable regional opt-in',!empty($saved['ok'])&&RegionalRoutingScopeService::regionalProjectIds(7)===[1]&&!regionalVisible($pdo,7,101));
$snapshot=AdminDirectoryService::snapshot();$removed=array_values(array_filter($snapshot['managers'],static fn(array$row):bool=>(int)$row['id']===7))[0];
regionalCheck('The admin snapshot retains an ungranted durable regional restriction for form regrant',
    $removed['project_ids']===[]&&$removed['regional_project_ids']===[1]);
$saved=AdminDirectoryService::saveManager(regionalManagerData(7,['regional_project_ids'=>$removed['regional_project_ids']]),90);
regionalCheck('Restoring the same project grant does not silently restore unrestricted visibility',!empty($saved['ok'])&&regionalVisible($pdo,7,101)&&!regionalVisible($pdo,7,102));
$saved=AdminDirectoryService::saveManager(regionalManagerData(7,['regional_project_ids'=>[2]]),90);
regionalCheck('Regional restriction must be a subset of the submitted project grants',empty($saved['ok'])&&RegionalRoutingScopeService::regionalProjectIds(7)===[1]);
$newData=['manager_id'=>0,'login'=>'regional-native-created','display_name'=>'Synthetic regional newcomer',
    'role'=>'manager','is_active'=>1,'priority'=>0,'email'=>'','password'=>'Synthetic fixture password only',
    'project_ids'=>[1],'regional_project_ids'=>[1]];
$beforeManagers=(int)$pdo->query('SELECT COUNT(*) FROM managers')->fetchColumn();
foreach([
    [$newData+[],7],
    [array_replace($newData,['role'=>'admin']),90],
    [array_replace($newData,['project_ids'=>[1,2]]),90],
    [array_replace($newData,['regional_project_ids'=>[]]),90],
]as[$input,$actor]){
    $result=AdminDirectoryService::saveManagerForProject($input,$actor,'anytour');
    regionalCheck('The fixed-project manager wrapper rejects non-admin elevation or wider grants',empty($result['ok'])
        &&(int)$pdo->query('SELECT COUNT(*) FROM managers')->fetchColumn()===$beforeManagers);
}
$created=AdminDirectoryService::saveManagerForProject($newData,90,'anytour');$createdId=(int)($created['manager_id']??0);
$q=$pdo->prepare('SELECT role,is_working FROM managers WHERE id=?');$q->execute([$createdId]);$createdRow=$q->fetch();
$q=$pdo->prepare('SELECT project_id FROM manager_projects WHERE manager_id=? ORDER BY project_id');$q->execute([$createdId]);
regionalCheck('Canonical fixed-project creation atomically installs only the regional project grant',!empty($created['ok'])
    &&array_map('intval',array_column($q->fetchAll(),'project_id'))===[1]
    &&RegionalRoutingScopeService::regionalProjectIds($createdId)===[1]&&$createdRow['role']==='manager');
regionalCheck('Account creation neither starts an operator shift nor grants a groupless regional queue',
    (int)$createdRow['is_working']===0&&!regionalVisible($pdo,$createdId,101));
$audit=json_encode($pdo->query('SELECT before_json,after_json FROM admin_audit_log')->fetchAll(),JSON_THROW_ON_ERROR);
regionalCheck('Canonical account audit cannot contain a password or its database hash field',
    !str_contains($audit,$newData['password'])&&!str_contains($audit,'password_hash'));
$pdo->exec("INSERT INTO managers(id,login,display_name,role,is_active,is_working) VALUES(13,'regional-existing-inactive','Synthetic inactive prior member','manager',0,0),(14,'regional-invalid-inactive','Synthetic inactive new target','manager',0,0);
    INSERT INTO manager_projects VALUES(13,1),(14,1);
    INSERT INTO manager_group_members VALUES(10,13)");
$territoriesBefore=$pdo->query('SELECT * FROM manager_group_territories WHERE group_id=10 ORDER BY territory_region_id')->fetchAll();
$descendantsBefore=$pdo->query('SELECT * FROM manager_group_territory_regions WHERE group_id=10 ORDER BY territory_region_id,region_id')->fetchAll();
$appended=RoutingAdminService::appendMembersResult(90,'anytour',10,[$createdId]);
$again=RoutingAdminService::appendMembersResult(90,'anytour',10,[$createdId,9]);
$members=array_map('intval',array_column($pdo->query('SELECT manager_id FROM manager_group_members WHERE group_id=10 ORDER BY manager_id')->fetchAll(),'manager_id'));
regionalCheck('Append-only membership preserves existing inactive colleagues and deduplicates repeat appends',
    !empty($appended['ok'])&&!empty($again['ok'])&&$members===[7,9,13,$createdId]);
regionalCheck('Appending colleagues cannot replace territory headers or their compiled descendants',
    $pdo->query('SELECT * FROM manager_group_territories WHERE group_id=10 ORDER BY territory_region_id')->fetchAll()===$territoriesBefore
    &&$pdo->query('SELECT * FROM manager_group_territory_regions WHERE group_id=10 ORDER BY territory_region_id,region_id')->fetchAll()===$descendantsBefore);
$badAppend=RoutingAdminService::appendMembersResult(90,'anytour',10,[14]);
regionalCheck('An inactive new member is rejected without dropping prior inactive memberships',empty($badAppend['ok'])
    &&array_map('intval',array_column($pdo->query('SELECT manager_id FROM manager_group_members WHERE group_id=10 ORDER BY manager_id')->fetchAll(),'manager_id'))===$members);
regionalCheck('Append-only membership still requires the project admin and exact group project',
    empty(RoutingAdminService::appendMembersResult(7,'anytour',10,[$createdId])['ok'])
    &&empty(RoutingAdminService::appendMembersResult(90,'anytour',12,[$createdId])['ok']));

// More than a full candidate page of foreign or malformed receipts must not
// starve the valid in-region conversation below them in the existing ordering.
$pdo->exec('DELETE FROM conversations;DELETE FROM conversation_events');
for($id=1;$id<=220;$id++)regionalRoutingConversation($pdo,1000+$id,'51',['started_at'=>'2000-01-01 00:00:00','last_message_at'=>'2000-01-01 00:00:00']);
for($id=1;$id<=220;$id++){
    $bad=regionalRoutingReceipt('62');$bad['version']='1';
    regionalRoutingConversation($pdo,2000+$id,'62',['started_at'=>'2000-01-02 00:00:00','last_message_at'=>'2000-01-02 00:00:00','metadata_json'=>json_encode(['first_entry_region'=>$bad])]);
}
regionalRoutingConversation($pdo,9000,'62');
$page=ManagerConversationService::notificationWaitingRows(7,'anytour');
regionalCheck('Regional and canonical-receipt filters run before the 200-row Inbox candidate cap',array_map('intval',array_column($page,'id'))===[9000]);
regionalCheck('The same cap retains the legacy unrestricted queue population',count(ManagerConversationService::notificationWaitingRows(9,'anytour'))===200);

$header=$pdo->query('SELECT * FROM manager_group_territories WHERE group_id=10')->fetch();
$pdo->exec("UPDATE manager_group_territories SET catalog_sha256='".str_repeat('f',64)."' WHERE group_id=10");
regionalCheck('Stale territory compilation cannot authorize current dialogues',!regionalVisible($pdo,7,9000));
$pdo->prepare('UPDATE manager_group_territories SET catalog_sha256=? WHERE group_id=10')->execute([$header['catalog_sha256']]);
regionalCheck('A valid restored header revalidates against its actual source anchor',regionalVisible($pdo,7,9000));
$pdo->exec('DROP TABLE manager_regional_access');
regionalCheck('Missing regional ACL schema never restores unrestricted non-admin access',!regionalVisible($pdo,7,9000));
echo 'Manager regional routing regression passed ('.$checks." checks)\n";
