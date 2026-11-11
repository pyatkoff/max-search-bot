<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/fixtures/manager_regional_routing_fixture.php';
require_once dirname(__DIR__).'/services/ManagerEntryRegionViewService.php';

final class ManagerEntryRegionFixturePdo extends PDO
{
    public array $queries=[];
    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('NOW',static fn():string=>'2026-11-11 13:00:00');
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $this->queries[]=$query;
        return parent::prepare($query,$options);
    }
}

$pdo=new ManagerEntryRegionFixturePdo();regionalRoutingSchema($pdo);regionalRoutingBind($pdo);
regionalRoutingInstallScopes($pdo);$checks=0;
function entryViewCheck(string $label,bool $ok):void
{
    global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";
}
function entryViewSave(PDO $pdo,int $id,array $metadata):void
{
    $pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=?')->execute([json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
}
function entryView(PDO $pdo,int $id):array
{
    $visible=ManagerConversationService::visibleConversation($id,9,true);
    if(!$visible)throw new RuntimeException('fixture_conversation_not_visible');
    return ManagerEntryRegionViewService::fromVisibleConversation($visible,$pdo);
}

$first=regionalRoutingReceipt('62');$latest=regionalRoutingReceipt('51');
$metadata=['first_entry_region'=>$first,'entry_region'=>$latest,'private_token'=>'PRIVATE_METADATA_TOKEN',
    'repair_context'=>['private'=>'PRIVATE_REPAIR_CONTEXT'],'trip'=>['city'=>'Москва','no_flight'=>true]];
regionalRoutingConversation($pdo,101,'62',['metadata_json'=>json_encode($metadata,JSON_THROW_ON_ERROR),'attribution_region'=>'213']);
$before=$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll();
$visible=ManagerConversationService::visibleConversation(101,7,true);
entryViewCheck('The manager receives authorized conversation data without raw metadata',$visible!==null&&!array_key_exists('metadata_json',$visible));
$pdo->queries=[];$view=ManagerEntryRegionViewService::fromVisibleConversation($visible,$pdo);
entryViewCheck('The immutable first entry has a readable canonical compact name',
    $view['first_entry_region']===['yandex_region_id'=>'62','name'=>'Красноярск','label'=>'Красноярск · Красноярский край',
        'hierarchy'=>['Сибирь','Красноярский край','Красноярск'],'known'=>true]);
entryViewCheck('Latest explicit entry remains distinct from first entry and unrelated raw attribution',
    $view['latest_entry_region']['yandex_region_id']==='51'&&$view['latest_entry_region']['label']==='Самара · Самарская область');
entryViewCheck('Only one scoped metadata read is needed for both entry projections',
    $pdo->queries===['SELECT CASE WHEN LENGTH(CAST(metadata_json AS BLOB))<=262144 THEN metadata_json ELSE NULL END AS metadata_json FROM conversations WHERE id=? AND project_key=? AND channel=? LIMIT 1']);
$json=json_encode($view,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
entryViewCheck('The projection allows only display fields and leaks no raw receipt or private data',
    !str_contains($json,'PRIVATE_')&&!str_contains($json,$first['receipt_sha256'])&&!str_contains($json,$latest['receipt_sha256'])
    &&!str_contains($json,'receipt_sha256')&&!str_contains($json,'recorded_at_unix')&&!str_contains($json,'max_start_payload')
    &&array_keys($view['first_entry_region'])===['yandex_region_id','name','label','hierarchy','known']);
entryViewCheck('Reading advertising context cannot rewrite attribution trip ownership status or timestamps',
    $pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll()===$before
    &&(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===0
    &&(int)$pdo->query('SELECT COUNT(*) FROM manager_conversation_reads')->fetchColumn()===0);
entryViewCheck('Another regional manager cannot authorize this immutable first region',ManagerConversationService::visibleConversation(101,8,true)===null);

$empty=['first_entry_region'=>null,'latest_entry_region'=>null];
foreach([['project_key'=>'other'],['channel'=>'telegram'],['id'=>999],['channel'=>'email'],['project_key'=>'anytour OR 1=1']]as$change){
    entryViewCheck('A substituted conversation scope cannot borrow metadata '.json_encode($change),
        ManagerEntryRegionViewService::fromVisibleConversation(array_replace($visible,$change),$pdo)===$empty);
}
foreach(['telegram','website']as$platform){
    regionalRoutingConversation($pdo,$platform==='telegram'?102:103,'51',['channel'=>$platform]);
    entryViewCheck('The actual '.$platform.' transport uses its own validated receipt',entryView($pdo,$platform==='telegram'?102:103)['first_entry_region']['yandex_region_id']==='51');
}

entryViewSave($pdo,101,['entry_region'=>$latest]);
entryViewCheck('Latest-only historical metadata never manufactures a first entry',entryView($pdo,101)===['first_entry_region'=>null,'latest_entry_region'=>$view['latest_entry_region']]);
entryViewSave($pdo,101,['first_entry_region'=>$first]);
entryViewCheck('A missing latest receipt never borrows raw attribution or the first receipt',entryView($pdo,101)===['first_entry_region'=>$view['first_entry_region'],'latest_entry_region'=>null]);

$badCases=[];
foreach(['version'=>'1','kind'=>'ip','platform'=>'telegram','source'=>'untrusted','yandex_region_id'=>62,
    'recorded_at_unix'=>'1794300000','receipt_sha256'=>str_repeat('A',64),'name'=>str_repeat('x',401)]as$field=>$value){
    $bad=$first;$bad[$field]=$value;$badCases[$field]=json_encode(['first_entry_region'=>$bad,'entry_region'=>$latest]);
}
$badCases['malformed_json']='{';$badCases['oversized']=json_encode(['first_entry_region'=>$first,'padding'=>str_repeat('x',262145)]);
$badCases['oversized_utf8']=json_encode(['first_entry_region'=>$first,'padding'=>str_repeat('я',131073)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
foreach($badCases as$label=>$json){
    $pdo->prepare('UPDATE conversations SET metadata_json=? WHERE id=101')->execute([$json]);
    $result=entryView($pdo,101);
    entryViewCheck('Malformed first metadata never falls back to a later entry: '.$label,$result['first_entry_region']===null);
}
$badLatest=$latest;$badLatest['platform']='website';
entryViewSave($pdo,101,['first_entry_region'=>$first,'entry_region'=>$badLatest]);
entryViewCheck('Malformed latest metadata preserves the independent valid first entry',entryView($pdo,101)===['first_entry_region'=>$view['first_entry_region'],'latest_entry_region'=>null]);

$unknown=regionalRoutingReceipt('99999999999999999999');$unknown['name']='';
entryViewSave($pdo,101,['first_entry_region'=>$unknown]);$result=entryView($pdo,101)['first_entry_region'];
entryViewCheck('Unknown geography retains its complete decimal ID string without inventing a city',
    $result===['yandex_region_id'=>'99999999999999999999','name'=>'','label'=>'Регион не определён','hierarchy'=>[],'known'=>false]);
$unknown['name']="Invalid\nlabel";entryViewSave($pdo,101,['first_entry_region'=>$unknown]);
entryViewCheck('Control characters in a captured name are not used as a display label',entryView($pdo,101)['first_entry_region']['label']==='Регион не определён');
$unknown['name']='Сохранённое название';entryViewSave($pdo,101,['first_entry_region'=>$unknown]);
entryViewCheck('An unknown ID may retain its safely captured name without pretending catalogue resolution',entryView($pdo,101)['first_entry_region']['label']==='Сохранённое название'&&!entryView($pdo,101)['first_entry_region']['known']);

$sameName=regionalRoutingReceipt('128340');entryViewSave($pdo,101,['first_entry_region'=>$sameName]);$result=entryView($pdo,101)['first_entry_region'];
entryViewCheck('Same-named places retain their own nearest parent and full hierarchy',
    $result['label']==='Самара · Полевское сельское поселение'
    &&$result['hierarchy']===['Дальневосточный федеральный округ','Еврейская автономная область','Полевское сельское поселение','Самара']);

$catalogState=[];$reflection=new ReflectionClass(YandexRegionCatalog::class);
foreach($reflection->getProperties(ReflectionProperty::IS_STATIC)as$property)$catalogState[$property->getName()]=$property->getValue();
try{
    foreach(['initialized'=>true,'manifest'=>null,'legacyRegions'=>null,'bucket'=>null,'bucketRegions'=>[]]as$key=>$value)(new ReflectionProperty(YandexRegionCatalog::class,$key))->setValue(null,$value);
    $first['name']='Красноярск при записи';entryViewSave($pdo,101,['first_entry_region'=>$first]);$result=entryView($pdo,101)['first_entry_region'];
    entryViewCheck('An unavailable catalogue gracefully uses the bounded captured name',
        $result===['yandex_region_id'=>'62','name'=>'Красноярск при записи','label'=>'Красноярск при записи','hierarchy'=>[],'known'=>false]);
}finally{foreach($catalogState as$key=>$value)(new ReflectionProperty(YandexRegionCatalog::class,$key))->setValue(null,$value);}

$broken=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
entryViewCheck('Optional geography storage failure leaves both fields unavailable without failing the lead card',
    ManagerEntryRegionViewService::fromVisibleConversation($visible,$broken)===$empty);
echo 'TOTAL '.$checks.' | PASS '.$checks.' | FAIL 0'."\n";
