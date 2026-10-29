from pathlib import Path
import json

paths = json.loads(Path('split_review_paths.json').read_text())
def replace(path, old, new):
    p = Path(path)
    text = p.read_text()
    if text.count(old) != 1:
        raise SystemExit(f'Expected exact marker: {path}, count={text.count(old)}')
    p.write_text(text.replace(old, new))
    if path not in paths:
        paths.append(path)

# Match the existing discovery HTTP contract instead of weakening its new test.
for profile in ['replies', 'hotels']:
    for kind, suffix in [('oauth-authorization-server', 'oauth'), ('oauth-protected-resource', 'mcp')]:
        path = f'.well-known/{kind}/manager/{profile}-{suffix}.php'
        replace(path, "header('Cache-Control: no-store');", """header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {
    header('Allow: GET');http_response_code(405);
    echo json_encode(['error'=>'invalid_request'],JSON_THROW_ON_ERROR);exit;
}""")

# The original health fixtures stubbed all services and predate the new authority
# callback. Model that dependency explicitly and keep every existing assertion.
manager = 'tests/run_manager_preparation_health_regression.php'
replace(manager,
    "class ManagerPreparationMcpService {public function hasActiveSubscriptions(){return !is_file(__DIR__.'/waiting');}",
    """class ManagerPreparationOAuth {
    public function __construct($pdo=null){}
    public function eventConnectionAuthorizedAcrossProfiles(string $domain,string $connection,int $admin):bool {
        if($domain!=='replies'||$connection!==str_repeat('a',32)||$admin!==1)throw new RuntimeException('PRIVATE WRONG AUTHORITY');
        if(is_file(__DIR__.'/authfail'))throw new RuntimeException('PRIVATE AUTHORITY UNAVAILABLE');
        return true;
    }
}
class ManagerPreparationMcpService {
    public function __construct(private $connectionActive=null){if(!is_callable($connectionActive))throw new RuntimeException('PRIVATE MISSING AUTHORITY');}
    public function hasActiveSubscriptions(){return ($this->connectionActive)(str_repeat('a',32),1)&&!is_file(__DIR__.'/waiting');}""")
replace(manager, "['ok'=>0,'waiting'=>0,'collectionfail'=>1,'enqueuefail'=>1,'fail'=>1]", "['ok'=>0,'waiting'=>0,'authfail'=>1,'collectionfail'=>1,'enqueuefail'=>1,'fail'=>1]")
replace(manager, "['ok'=>'ok','waiting'=>'awaiting_connection','collectionfail'=>'failed','enqueuefail'=>'failed','fail'=>'failed']", "['ok'=>'ok','waiting'=>'awaiting_connection','authfail'=>'failed','collectionfail'=>'failed','enqueuefail'=>'failed','fail'=>'failed']")
replace(manager, "        if ($mode==='fail') healthCheck(", "        if ($mode==='authfail') healthCheck('unknown authority is not a healthy disconnected reply worker',($row['stage']??null)==='subscription');\n        if ($mode==='fail') healthCheck(")

hotel = 'tests/run_hotel_work_health_regression.php'
replace(hotel, "class HotelWorkEventService {\n    public function hasActiveSubscriptions(){return !is_file(__DIR__.'/waiting');}", """class ConversationDb {public static function connection(){return null;}}
class ManagerPreparationOAuth {
    public const HOTEL_SCOPE='hotel_work:write';
    public function __construct($pdo=null){}
    public function eventConnectionAuthorizedAcrossProfiles(string $domain,string $connection,int $admin):bool {
        if($domain!=='hotels'||$connection!==str_repeat('a',32)||$admin!==1)throw new RuntimeException('PRIVATE WRONG AUTHORITY');
        if(is_file(__DIR__.'/authfail'))throw new RuntimeException('PRIVATE AUTHORITY UNAVAILABLE');
        return true;
    }
}
class HotelWorkEventService {
    public function __construct($pdo=null,private $connectionAuthorized=null){if(!is_callable($connectionAuthorized))throw new RuntimeException('PRIVATE MISSING AUTHORITY');}
    public function hasActiveSubscriptions(){return ($this->connectionAuthorized)(str_repeat('a',32),1,['hotel_work:write'])&&!is_file(__DIR__.'/waiting');}""")
replace(hotel, "['ok'=>0,'waiting'=>0,'deliveryfail'=>1,'stopped'=>1,'fail'=>1]", "['ok'=>0,'waiting'=>0,'authfail'=>1,'deliveryfail'=>1,'stopped'=>1,'fail'=>1]")
replace(hotel, "['ok'=>'ok','waiting'=>'awaiting_connection','deliveryfail'=>'failed','stopped'=>'failed','fail'=>'failed']", "['ok'=>'ok','waiting'=>'awaiting_connection','authfail'=>'failed','deliveryfail'=>'failed','stopped'=>'failed','fail'=>'failed']")
replace(hotel, "        if ($mode==='fail') hotelHealthCheck(", "        if ($mode==='authfail') hotelHealthCheck('unknown authority is not a healthy disconnected hotel worker',($row['stage']??null)==='subscription');\n        if ($mode==='fail') hotelHealthCheck(")

Path('split_review_paths.json').write_text(json.dumps(paths))
print(json.dumps({'review_repair_files':paths,'runtime_operations':0}))
