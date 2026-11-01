<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpDiagnostics.php';
require_once __DIR__.'/hotel_demand_inventory_checks.php';

$passed=0;
function preparationMcpCheck(string $name,bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;echo 'PASS '.$name."\n";
}

final class PreparationMcpPdo extends PDO
{
    public $beforeClaim=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if ($this->beforeClaim!==null && str_contains($query,'SET lease_token=?,lease_until=?')) {
            $hook=$this->beforeClaim;$this->beforeClaim=null;$hook($this);
        }
        return parent::prepare($query,$options);
    }
}

function preparationMcpFixture(): PreparationMcpPdo
{
    $pdo=new PreparationMcpPdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other',1);
INSERT INTO managers VALUES(1,'admin',1),(2,'manager',1),(3,'admin',1),(4,'admin',1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1);
CREATE TABLE manager_preparation_events(subscription_id TEXT PRIMARY KEY,admin_id INTEGER,oauth_connection_id TEXT,
project_key TEXT,event_name TEXT,arguments_json TEXT,callback_url TEXT,signing_secret TEXT,previous_secret TEXT,
previous_secret_until TEXT,verified_at TEXT,expires_at TEXT,is_active INTEGER,observed_jobs_json TEXT,
pending_event_id TEXT,pending_body TEXT,pending_jobs_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,
lease_token TEXT,lease_until TEXT,last_batch_hash TEXT,last_status INTEGER,created_at TEXT,updated_at TEXT);");
    return $pdo;
}

function preparationMcpRpc(string $method,array $params=[]): array { return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]; }
function preparationMcpHeaders(array $headers): array
{
    $out=[];foreach ($headers as $header) { [$key,$value]=explode(': ',$header,2);$out[strtolower($key)]=$value; }return $out;
}

/** Synthetic Search3 envelope: no production request, transcript, supplier or quote. */
function preparationCandidateSource(array $params,int $now): array
{
    $scope=['scopeVersion'=>1]+$params;
    $digest=hash('sha256',json_encode($scope,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $observed=gmdate('Y-m-d\TH:i:s\Z',$now-300);$last=gmdate('Y-m-d\TH:i:s\Z',$now-120);
    $offer=static function(int $number,string $price) use ($params,$digest,$observed,$last,$now): array {
        return ['provider'=>'tourvisor','legacyHotelId'=>900+$number,'price'=>$price,'currency'=>'RUB',
            'observedAt'=>$observed,'lastSeenAt'=>$last,'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600),
            'sourceScopeDigest'=>$digest,'listing'=>['schema_version'=>1,'provider'=>'tourvisor',
                'operator'=>['raw'=>'Synthetic operator','private'=>'PRIVATE SUPPLIER DATA'],
                'identity'=>['offer_ref_digest'=>hash('sha256','synthetic-'.$number),'search_ref_digest'=>str_repeat('e',64)],
                'listingPrice'=>$price,'currency'=>'RUB','listingPriceState'=>'final_ready_estimate',
                'listingPriceReady'=>true,'finalPriceVerified'=>false,'quoteState'=>'unknown',
                'selection_state'=>'refresh_required','booking_enabled'=>false,
                'tour'=>['checkin'=>$params['dateFrom'],'nights'=>$params['nightsFrom'],
                    'party'=>['adults'=>$params['adults'],'children'=>count($params['childs']),'child_ages'=>$params['childs']],
                    'room'=>['raw'=>'Synthetic exact room '.$number],'meal'=>['raw'=>'Synthetic meal'],
                    'placement'=>['raw'=>'Synthetic placement'],'observed_at'=>$observed,
                    'availability'=>['hotel'=>['raw'=>'available']],
                    'flight_details'=>['url'=>'https://private.invalid/flight']]]];
    };
    $hotel=static fn(int $id,array $offers): array=>['anytourHotelId'=>$id,
        'hotel'=>['id'=>$id,'name'=>'Synthetic hotel '.$id,'category'=>4,'country'=>['name'=>'Synthetic country'],
            'region'=>['name'=>'Synthetic region'],'description'=>'PRIVATE UNREAD HOTEL PROPERTY',
            'images'=>['https://private.invalid/photo']],'minPrice'=>'1','offers'=>$offers];
    return ['ok'=>true,'data'=>['source'=>'anytour-db-first-results-v1','offerStoreSchemaVersion'=>2,'scopeVersion'=>1,
        'scopeDigest'=>$digest,'scope'=>$scope,'matchMode'=>'exact','partial'=>false,'sourceScopeDigests'=>[$digest],
        'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),'selectionAuthority'=>false,
        'hotelCount'=>2,'offerCount'=>3,'storedOfferCount'=>3,'withheldOfferCount'=>0,'categoryFilteredOfferCount'=>0,
        'omittedHotelCount'=>0,'omittedOfferCount'=>0,
        'hotels'=>[$hotel(1001,[$offer(1,'100000'),$offer(2,'120000')]),$hotel(1002,[$offer(3,'110000')])]]];
}

function preparationCandidateChecks(): void
{
    $now=1792962000;$requests=[];$mode=null;
    $args=['departure'=>'Synthetic departure','country'=>'Synthetic country','date_from'=>'2027-06-03','date_to'=>'2027-06-05',
        'nights_min'=>6,'nights_max'=>8,'adults'=>2,'child_ages'=>[9,4,4],'stars_min'=>4,'max_price'=>'200000.00'];
    $directory=static fn(string $kind,string $name): array=>['ID'=>$kind==='departure'?'101':'201','NAME'=>$name];
    $transport=static function(string $url,array $envelope) use (&$requests,&$mode,$now): array {
        $requests[]=[$url,$envelope];$source=preparationCandidateSource($envelope['params'],$now);
        if ($mode!==null) $source=$mode($source);
        return ['status'=>200,'type'=>'application/json; charset=utf-8','body'=>json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
    };
    $reader=new ManagerSearch3CandidateReader($transport,$directory,static fn(): int=>$now);
    $read=$reader->read($args);$c=$read['candidates'][0]??[];
    preparationMcpCheck('retained reader uses one fixed Search3 POST envelope and existing catalogue IDs',count($requests)===1
        &&$requests[0][0]==='https://anytoour.ru/_preview/search3-local-candidate/data/search3-local-results-read-v1.php'
        &&array_keys($requests[0][1])===['params']&&$requests[0][1]['params']['departureId']==='101'
        &&$requests[0][1]['params']['countryId']==='201'&&$requests[0][1]['params']['childs']===[4,4,9]
        &&$requests[0][1]['params']['priceTo']==='200000'&&$requests[0][1]['params']['meal']===''
        &&array_key_exists('meal_filter',$read)&&$read['meal_filter']===null);
    preparationMcpCheck('source exact stay, whole-party price and age duplicates survive without fuel arithmetic',($read['ok']??false)===true
        &&$c['checkin']==='2027-06-03'&&$c['nights']===6&&$c['party']===['adults'=>2,'children'=>3,'child_ages'=>[4,4,9]]
        &&$c['room_raw']==='Synthetic exact room 1'&&$c['meal_raw']==='Synthetic meal'
        &&$c['price']['amount']==='100000'&&$c['price']['basis']==='whole_party');
    preparationMcpCheck('distinct hotels precede a second stay and false minPrice is never a stay price',
        array_column(array_column($read['candidates'],'hotel'),'anytour_hotel_id')===[1001,1002,1001]
        &&array_column(array_column($read['candidates'],'price'),'amount')===['100000','110000','120000']);
    preparationMcpCheck('observation time is not renewed and source listing never gains current authority',
        $c['observed_at']===gmdate('Y-m-d\TH:i:s\Z',$now-300)&&$c['last_seen_at']===gmdate('Y-m-d\TH:i:s\Z',$now-120)
        &&$c['price']['current_price_verified']===false&&$c['price']['refresh_required']===true
        &&$c['availability']==='unverified'&&$c['photos_read']===false&&$read['selection_authority']===false&&$read['market_coverage_complete']===false);
    preparationMcpCheck('only projected candidate fields leave the reader',!str_contains(json_encode($read),'PRIVATE')
        &&!str_contains(json_encode($read),'private.invalid')&&!str_contains(json_encode($read),'search_ref_digest'));
    $again=$reader->read($args);
    preparationMcpCheck('a second cache read does not refresh retained observation timestamps',$again['candidates']===$read['candidates']&&count($requests)===2);
    foreach (['scope_country'=>static function($s){$s['data']['scope']['countryId']='202';return $s;},
        'scope_digest'=>static function($s){$s['data']['scopeDigest']=str_repeat('0',64);return $s;},
        'source_identity'=>static function($s){$s['data']['source']='foreign';return $s;},
        'schema'=>static function($s){$s['data']['offerStoreSchemaVersion']=1;return $s;},
        'selection_authority'=>static function($s){$s['data']['selectionAuthority']=true;return $s;},
        'stale_response'=>static function($s) use($now){$s['data']['generatedAt']=gmdate('Y-m-d\TH:i:s\Z',$now-121);return $s;},
        'future_response'=>static function($s) use($now){$s['data']['generatedAt']=gmdate('Y-m-d\TH:i:s\Z',$now+31);return $s;},
        'count_mismatch'=>static function($s){$s['data']['offerCount']=0;return $s;},
        'partial_mismatch'=>static function($s){$s['data']['partial']=true;return $s;}] as $label=>$change) {
        $mode=$change;$result=$reader->read($args);
        preparationMcpCheck('wrong source envelope is unknown not an empty market: '.$label,$result['ok']===false
            &&$result['state']==='unavailable'&&$result['reason']==='invalid_source_response'&&$result['candidates']===[]);
    }
    foreach (['expiry'=>static function($o) use($now){$o['expiresAt']=gmdate('Y-m-d\TH:i:s\Z',$now);return $o;},
        'ttl'=>static function($o) use($now){$o['expiresAt']=gmdate('Y-m-d\TH:i:s\Z',$now+86401);return $o;},
        'party'=>static function($o){$o['listing']['tour']['party']['adults']=3;return $o;},
        'duplicate_ages'=>static function($o){$o['listing']['tour']['party']['child_ages']=[4,9];return $o;},
        'checkin'=>static function($o){$o['listing']['tour']['checkin']='2027-06-08';return $o;},
        'nights'=>static function($o){$o['listing']['tour']['nights']=9;return $o;},
        'float_price'=>static function($o){$o['price']=100000.0;return $o;},
        'zero_price'=>static function($o){$o['price']='0';$o['listing']['listingPrice']='0';return $o;},
        'currency'=>static function($o){$o['currency']='EUR';return $o;},
        'changed_price'=>static function($o){$o['listing']['listingPrice']='1';return $o;},
        'unknown_scope'=>static function($o){$o['sourceScopeDigest']=str_repeat('f',64);return $o;},
        'booking'=>static function($o){$o['listing']['booking_enabled']=true;return $o;},
        'provider'=>static function($o){$o['listing']['provider']='anex';return $o;},
        'verified_contradiction'=>static function($o){$o['listing']['finalPriceVerified']=true;return $o;},
        'observation_mismatch'=>static function($o){$o['listing']['tour']['observed_at']='2026-10-01T00:00:00Z';return $o;}] as $label=>$change) {
        $mode=static function($s) use($change){$s['data']['hotels'][0]['offers'][0]=$change($s['data']['hotels'][0]['offers'][0]);return $s;};
        $result=$reader->read($args);
        preparationMcpCheck('unsafe exact offer withheld without replacing its facts: '.$label,$result['ok']===true
            &&$result['rejected_offer_count']===1&&count($result['candidates'])===2
            &&!in_array(hash('sha256','synthetic-1'),array_column($result['candidates'],'offer_digest'),true));
    }
    $mode=static function($s){$s['data']['hotels'][0]['hotel']['id']=1002;return $s;};
    preparationMcpCheck('canonical local identity mismatch is not joined by name',$reader->read($args)['rejected_offer_count']===2);
    $mode=static function($s){$s['data']['hotels'][0]['hotel']['category']=3;return $s;};
    preparationMcpCheck('minimum category is checked per accepted hotel profile',$reader->read($args)['rejected_offer_count']===2);
    $mode=static function($s){$s['data']['matchMode']='compatible';$s['data']['partial']=true;return $s;};
    preparationMcpCheck('compatible retained coverage stays explicitly partial',$reader->read($args)['source_partial']===true);
    $mode=static function($s){$s['data']['hotels']=[];$s['data']['hotelCount']=0;$s['data']['offerCount']=0;
        $s['data']['storedOfferCount']=0;$s['data']['sourceScopeDigests']=[];$s['data']['matchMode']='none';return $s;};
    $empty=$reader->read($args);
    preparationMcpCheck('zero retained offers explicitly needs existing live search, not a no-tours claim',
        $empty['ok']===true&&$empty['state']==='no_retained_candidates'&&$empty['market_coverage_complete']===false
        &&$empty['next_action']==='existing_live_search_required'&&$empty['candidates']===[]);
    $mode=null;$limited=$reader->read($args+['limit'=>1]);
    preparationMcpCheck('candidate limit reports exact whole-record omissions',count($limited['candidates'])===1&&$limited['omitted_candidate_count']===2&&$limited['truncated']);
    $mode=static function($s){$s['data']['hotels'][0]['offers'][0]['listing']['listingPriceState']='final_verified';
        $s['data']['hotels'][0]['offers'][0]['listing']['finalPriceVerified']=true;return $s;};
    $verified=$reader->read($args)['candidates'][0]['price'];
    preparationMcpCheck('historically verified listing is still not a verified current fare',
        $verified['source_final_price_verified']===true&&$verified['current_price_verified']===false&&$verified['refresh_required']===true);
    $mode=static function($s){$s['data']['hotels'][0]['offers'][1]=$s['data']['hotels'][0]['offers'][0];return $s;};
    preparationMcpCheck('duplicate provider offer identity is not a second choice',$reader->read($args)['rejected_offer_count']===1);
    $mode=null;$cheaper=$reader->read(array_replace($args,['max_price'=>'105000']));
    preparationMcpCheck('literal whole-party budget excludes expensive records without changing prices',
        count($cheaper['candidates'])===1&&$cheaper['candidates'][0]['price']['amount']==='100000'&&$cheaper['rejected_offer_count']===2);
    $mode=static function($s){$d=&$s['data'];$base=$d['hotels'][0];$d['hotels']=[];
        for($i=0;$i<12;++$i){$h=$base;$h['anytourHotelId']=2000+$i;$h['hotel']['id']=2000+$i;$h['offers']=[$base['offers'][0]];
            $h['offers'][0]['listing']['identity']['offer_ref_digest']=hash('sha256','large-'.$i);
            foreach(['room','meal','placement'] as $key)$h['offers'][0]['listing']['tour'][$key]['raw']=str_repeat('x',800);
            $d['hotels'][]=$h;}
        $d['hotelCount']=12;$d['offerCount']=12;$d['storedOfferCount']=12;return $s;};
    $large=$reader->read($args+['limit'=>12]);
    preparationMcpCheck('output cap omits whole candidates without silently clipping stay labels',
        strlen(json_encode($large,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=32768&&$large['truncated']
        &&$large['omitted_candidate_count']===12-count($large['candidates'])
        &&count($large['candidates'])>0&&strlen($large['candidates'][0]['room_raw'])===800);
    $mode=null;
    foreach ([['url'=>'https://private.invalid/'],['job_id'=>'old'],['departure_id'=>101],['currency'=>'EUR'],['child_ages'=>[4,'4',9]],
        ['date_from'=>'2027-02-30'],['date_from'=>"2027-06-03\x00"],['date_to'=>'2027-07-05'],['nights_max'=>28],['meal_id'=>'999'],['adults'=>true],
        ['max_price'=>100000],['max_price'=>'1e6'],['stars_min'=>null],['limit'=>13]] as $bad) {
        $before=count($requests);$rejected=false;try {$reader->read(array_replace($args,$bad));} catch (InvalidArgumentException $e) {$rejected=true;}
        preparationMcpCheck('invalid candidate arguments cause no HTTP request '.json_encode(array_keys($bad)),$rejected&&count($requests)===$before);
    }
    foreach ([401,403,302,503] as $status) {
        $attempts=0;$failed=new ManagerSearch3CandidateReader(static function() use($status,&$attempts){++$attempts;return ['status'=>$status,'body'=>'PRIVATE ERROR'];},$directory,static fn()=>$now);
        $r=$failed->read($args);
        preparationMcpCheck('HTTP failure neither retries nor turns into zero availability '.$status,$attempts===1&&$r['ok']===false
            &&$r['state']==='unavailable'&&!str_contains(json_encode($r),'PRIVATE')
            &&$r['reason']===(in_array($status,[401,403],true)?'source_access_denied':'source_unavailable'));
    }
    foreach ([['status'=>200,'type'=>'text/html','body'=>'PRIVATE HTML'],
        ['status'=>200,'type'=>'application/json','body'=>'{'],
        ['status'=>200,'type'=>'application/json','body'=>str_repeat('x',4194305)]] as $badResponse) {
        $failed=new ManagerSearch3CandidateReader(static fn()=>$badResponse,$directory,static fn()=>$now);
        preparationMcpCheck('malformed or oversized source body fails closed',$failed->read($args)['reason']==='invalid_source_response');
    }
    $failed=new ManagerSearch3CandidateReader(static function(){throw new RuntimeException('PRIVATE TRANSPORT');},$directory,static fn()=>$now);
    preparationMcpCheck('transport exception remains unknown without exception text',$failed->read($args)['reason']==='source_unavailable');
    $noCatalogue=new ManagerSearch3CandidateReader(static function(){throw new RuntimeException('must not fetch');},static fn()=>false,static fn()=>$now);
    preparationMcpCheck('unresolved catalogue names do not guess IDs or fetch',$noCatalogue->read($args)['reason']==='catalogue_name_unresolved');
    $address=new ReflectionMethod(ManagerSearch3CandidateReader::class,'publicAddress');
    foreach (['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','192.0.2.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','::1'] as $ip) {
        preparationMcpCheck('fixed HTTPS reader refuses nonpublic destination '.$ip,$address->invoke(null,$ip)===false);
    }
    preparationMcpCheck('fixed HTTPS reader allows a public IPv4 address',$address->invoke(null,'8.8.8.8')===true);

    $pdo=preparationMcpFixture();$connected=true;$storeCalls=0;
    $service=new ManagerPreparationMcpService($pdo,static function() use(&$storeCalls){++$storeCalls;return [];},null,null,
        static function() use(&$connected): bool {return $connected;},null,$reader);
    $rpc=preparationMcpRpc('tools/call',['name'=>ManagerSearch3CandidateReader::TOOL,'arguments'=>$args]);
    $tool=$service->handle(preparationMcpRpc('tools/list'),1)['result']['tools'][3];
    preparationMcpCheck('retained tool advertises readonly bounded input and existing OAuth metadata',
        $tool['name']===ManagerSearch3CandidateReader::TOOL&&$tool['annotations']['readOnlyHint']===true
        &&$tool['inputSchema']['additionalProperties']===false&&$tool['securitySchemes']===[['type'=>'oauth2','scopes'=>['queue:read','prepared:write']]]);
    $result=$service->handle($rpc,1,str_repeat('a',32));
    preparationMcpCheck('authenticated tool returns source-bound candidates without opening jobs or writing subscriptions',
        ($result['result']['structuredContent']['ok']??false)===true&&$storeCalls===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===0);
    foreach ([0,2,3] as $actor) preparationMcpCheck('candidate read retains active project administrator boundary '.$actor,
        ($service->handle($rpc,$actor,str_repeat('a',32))['error']['code']??0)===-32001);
    $connected=false;$before=count($requests);
    preparationMcpCheck('candidate read requires active requesting connection before HTTP',
        ($service->handle($rpc,1,str_repeat('a',32))['error']['code']??0)===-32001&&count($requests)===$before);
    $connected=true;
    preparationMcpCheck('candidate read cannot use missing connection',($service->handle($rpc,1)['error']['code']??0)===-32001);
    foreach (['admin','project','connection'] as $revoke) {
        $race=preparationMcpFixture();$live=true;
        $racingReader=new ManagerSearch3CandidateReader(static function($url,$envelope) use($race,$revoke,&$live,$now){
            if ($revoke==='admin') $race->exec('UPDATE managers SET is_active=0 WHERE id=1');
            elseif ($revoke==='project') $race->exec('DELETE FROM manager_projects WHERE manager_id=1');
            else $live=false;
            return ['status'=>200,'type'=>'application/json','body'=>json_encode(preparationCandidateSource($envelope['params'],$now))];
        },$directory,static fn()=>$now);
        $raceService=new ManagerPreparationMcpService($race,null,null,null,static function() use(&$live){return $live;},null,$racingReader);
        $r=$raceService->handle($rpc,1,str_repeat('a',32));
        preparationMcpCheck('candidate result blocked after revocation during source read '.$revoke,
            ($r['error']['code']??0)===-32001&&!str_contains(json_encode($r),'Synthetic hotel'));
    }
}

/** The canonical ID and native token intentionally use different namespaces. */
function preparationMealCatalogue(): array
{
    return preparationMealRevision(['source'=>'anytour-search-meal-v1','provider'=>'tourvisor','scopeKey'=>'global',
        'available'=>true,'plans'=>[
            ['id'=>501,'code'=>'AI','nameRu'=>'Всё включено','nativeIds'=>['tv/AI-007']],
            ['id'=>502,'code'=>'BB','nameRu'=>'Завтрак','nativeIds'=>['tv/BB-003']],
            ['id'=>503,'code'=>'NONE','nameRu'=>'Без привязки','nativeIds'=>[]],
            ['id'=>504,'code'=>'MULTI','nameRu'=>'Несколько привязок','nativeIds'=>['tv/AI','tv/UAI']],
        ]]);
}

function preparationMealRevision(array $catalogue): array
{
    $snapshot=['source'=>$catalogue['source'],'provider'=>$catalogue['provider'],'scopeKey'=>$catalogue['scopeKey'],
        'available'=>$catalogue['available'],'plans'=>$catalogue['plans']];
    $catalogue['revision']=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $catalogue;
}

function preparationMealResponse(array $catalogue): array
{
    return ['status'=>200,'type'=>'application/json; charset=utf-8',
        'body'=>json_encode(['ok'=>true,'data'=>$catalogue],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
}

function preparationMealChecks(): void
{
    $now=1792962000;$requests=[];$directoryCalls=0;$catalogue=preparationMealCatalogue();
    $args=['departure'=>'Synthetic departure','country'=>'Synthetic country','date_from'=>'2027-06-03','date_to'=>'2027-06-05',
        'nights_min'=>6,'nights_max'=>8,'adults'=>2,'child_ages'=>[9,4,4]];
    $directory=static function(string $kind,string $name) use(&$directoryCalls): array {
        ++$directoryCalls;return ['ID'=>$kind==='departure'?'101':'201','NAME'=>$name];
    };
    $transport=static function(string $url,array $envelope) use(&$requests,&$catalogue,$now): array {
        $requests[]=[$url,$envelope];
        return array_key_exists('action',$envelope)?preparationMealResponse($catalogue):
            ['status'=>200,'type'=>'application/json','body'=>json_encode(preparationCandidateSource($envelope['params'],$now),JSON_THROW_ON_ERROR)];
    };
    $reader=new ManagerSearch3CandidateReader($transport,$directory,static fn(): int=>$now);
    $public=$reader->readMealCatalog([]);
    preparationMcpCheck('meal catalogue uses only the fixed producer action without directory lookup',count($requests)===1&&$directoryCalls===0
        &&$requests[0]===['https://anytoour.ru/_preview/search3-local-candidate/data/search3-local-results-read-v1.php',
            ['action'=>'meal_catalog','provider'=>'tourvisor','scopeKey'=>'global']]);
    preparationMcpCheck('complete meal discovery projects canonical IDs and binding support without native aliases',
        ($public['ok']??false)===true&&$public['available']===true&&$public['complete']===true&&$public['revision']===$catalogue['revision']
        &&$public['plans']===[
            ['meal_plan_id'=>501,'code'=>'AI','name_ru'=>'Всё включено','filter_supported'=>true,'native_count'=>1],
            ['meal_plan_id'=>502,'code'=>'BB','name_ru'=>'Завтрак','filter_supported'=>true,'native_count'=>1],
            ['meal_plan_id'=>503,'code'=>'NONE','name_ru'=>'Без привязки','filter_supported'=>false,'native_count'=>0],
            ['meal_plan_id'=>504,'code'=>'MULTI','name_ru'=>'Несколько привязок','filter_supported'=>false,'native_count'=>2]]
        &&!str_contains(json_encode($public,JSON_UNESCAPED_SLASHES),'tv/'));
    $requests=[];$filtered=$reader->read($args+['meal_plan_id'=>501]);
    preparationMcpCheck('canonical meal plan resolves before listing and preserves the exact native token',count($requests)===2
        &&$requests[0][1]===['action'=>'meal_catalog','provider'=>'tourvisor','scopeKey'=>'global']
        &&array_keys($requests[1][1])===['params']&&$requests[1][1]['params']['meal']==='tv/AI-007'
        &&$requests[0][0]===$requests[1][0]&&($filtered['ok']??false)===true);
    preparationMcpCheck('filtered result carries its exact catalogue receipt without granting current-price authority',
        $filtered['meal_filter']===['plan_id'=>501,'code'=>'AI','name_ru'=>'Всё включено','native_id'=>'tv/AI-007',
            'catalogue_revision'=>$catalogue['revision'],'provider'=>'tourvisor','scope_key'=>'global']
        &&$filtered['request']['search3_scope']['meal']==='tv/AI-007'&&$filtered['current_price_verified']===false
        &&$filtered['selection_authority']===false&&$filtered['candidates'][0]['price']['current_price_verified']===false
        &&$filtered['candidates'][0]['price']['refresh_required']===true&&$filtered['candidates'][0]['meal_raw']==='Synthetic meal');
    $wrongScope=new ManagerSearch3CandidateReader(static function($url,$envelope) use($catalogue,$now){
        if (isset($envelope['action'])) return preparationMealResponse($catalogue);
        $params=$envelope['params'];$params['meal']='different-native';
        return ['status'=>200,'type'=>'application/json','body'=>json_encode(preparationCandidateSource($params,$now))];
    },$directory,static fn()=>$now);
    $result=$wrongScope->read($args+['meal_plan_id'=>501]);
    preparationMcpCheck('a valid listing for a different native meal scope cannot satisfy the selected plan',
        $result['ok']===false&&$result['candidates']===[]);
    foreach (['3','4','5','7'] as $legacy) {
        $requests=[];$before=$directoryCalls;$result=$reader->read($args+['meal_id'=>$legacy]);
        preparationMcpCheck('legacy meal is explicitly unsupported before any directory or HTTP access '.$legacy,
            $result['ok']===false&&$result['reason']==='legacy_meal_id_unsupported'&&$result['candidates']===[]
            &&$requests===[]&&$directoryCalls===$before);
    }
    foreach ([0,-1,'501',501.5,true,null,[],9007199254740992] as $id) {
        $requests=[];$before=$directoryCalls;$rejected=false;
        try {$reader->read($args+['meal_plan_id'=>$id]);} catch (InvalidArgumentException $ignored) {$rejected=true;}
        preparationMcpCheck('invalid canonical meal ID is rejected before lookup '.json_encode($id),$rejected&&$requests===[]&&$directoryCalls===$before);
    }
    $requests=[];$rejected=false;
    try {$reader->read($args+['meal_id'=>'7','meal_plan_id'=>501]);} catch (InvalidArgumentException $ignored) {$rejected=true;}
    preparationMcpCheck('legacy and canonical meal arguments cannot be combined',$rejected&&$requests===[]);
    foreach ([['provider'=>'tourvisor'],['limit'=>1],['meal_plan_id'=>501]] as $bad) {
        $requests=[];$rejected=false;try {$reader->readMealCatalog($bad);} catch (InvalidArgumentException $ignored) {$rejected=true;}
        preparationMcpCheck('meal discovery rejects caller-controlled arguments '.json_encode(array_keys($bad)),$rejected&&$requests===[]);
    }
    foreach ([999=>'meal_plan_unmapped',503=>'meal_plan_unmapped',504=>'meal_plan_ambiguous'] as $id=>$reason) {
        $requests=[];$result=$reader->read($args+['meal_plan_id'=>$id]);
        preparationMcpCheck('missing or nonscalar meal binding never falls back to an unfiltered listing '.$id,
            $result['ok']===false&&$result['reason']===$reason&&$result['candidates']===[]&&count($requests)===1);
    }
    $catalogue['plans'][0]['nativeIds']=[' tv/AI-007 '];$catalogue=preparationMealRevision($catalogue);
    $requests=[];$result=$reader->read($args+['meal_plan_id'=>501]);
    preparationMcpCheck('whitespace native alias is never trimmed into a different listing filter',
        $result['ok']===false&&count($requests)===1&&$result['candidates']===[]&&$reader->readMealCatalog([])['plans'][0]['filter_supported']===false);
    $catalogue=preparationMealCatalogue();$catalogue['plans'][0]['id']=9007199254740991;$catalogue=preparationMealRevision($catalogue);
    preparationMcpCheck('maximum JSON-safe canonical ID remains an exact integer binding',
        $reader->read($args+['meal_plan_id'=>9007199254740991])['meal_filter']['plan_id']===9007199254740991);

    $changes=[
        'source'=>static function(&$d){$d['source']='foreign';},'provider'=>static function(&$d){$d['provider']='anex';},
        'scope'=>static function(&$d){$d['scopeKey']='local';},'available_type'=>static function(&$d){$d['available']='true';},
        'extra_catalogue_field'=>static function(&$d){$d['extra']='PRIVATE';},
        'nonlist_plans'=>static function(&$d){$d['plans']=[1=>$d['plans'][0]];},
        'duplicate_plan'=>static function(&$d){$d['plans'][1]['id']=$d['plans'][0]['id'];},
        'string_plan_id'=>static function(&$d){$d['plans'][0]['id']='501';},
        'fractional_plan_id'=>static function(&$d){$d['plans'][0]['id']=501.5;},
        'zero_plan_id'=>static function(&$d){$d['plans'][0]['id']=0;},
        'unsafe_plan_id'=>static function(&$d){$d['plans'][0]['id']=9007199254740992;},
        'extra_plan_field'=>static function(&$d){$d['plans'][0]['extra']='PRIVATE';},
        'empty_code'=>static function(&$d){$d['plans'][0]['code']='';},
        'long_code'=>static function(&$d){$d['plans'][0]['code']=str_repeat('c',129);},
        'long_name'=>static function(&$d){$d['plans'][0]['nameRu']=str_repeat('n',513);},
        'control_name'=>static function(&$d){$d['plans'][0]['nameRu']="bad\x00name";},
        'duplicate_native_same_plan'=>static function(&$d){$d['plans'][0]['nativeIds']=['same','same'];},
        'duplicate_native_cross_plan'=>static function(&$d){$d['plans'][1]['nativeIds']=$d['plans'][0]['nativeIds'];},
        'native_integer'=>static function(&$d){$d['plans'][0]['nativeIds']=[7];},
        'native_empty'=>static function(&$d){$d['plans'][0]['nativeIds']=[''];},
        'native_control'=>static function(&$d){$d['plans'][0]['nativeIds']=["tv\nAI"];},
        'native_long'=>static function(&$d){$d['plans'][0]['nativeIds']=[str_repeat('x',129)];},
        'nonlist_aliases'=>static function(&$d){$d['plans'][0]['nativeIds']=['named'=>'tv/AI'];},
        'too_many_aliases'=>static function(&$d){$d['plans'][0]['nativeIds']=array_map(static fn($i)=>'native-'.$i,range(1,10001));},
        'too_many_plans'=>static function(&$d){$d['plans']=array_map(static fn($i)=>['id'=>$i,'code'=>'C'.$i,'nameRu'=>'N'.$i,'nativeIds'=>[]],range(1,1001));},
    ];
    foreach ($changes as $label=>$change) {
        $catalogue=preparationMealCatalogue();$change($catalogue);$catalogue=preparationMealRevision($catalogue);
        $requests=[];$public=$reader->readMealCatalog([]);$result=$reader->read($args+['meal_plan_id'=>501]);
        preparationMcpCheck('invalid meal catalogue is withheld by discovery and binding '.$label,
            $public['ok']===false&&$public['complete']===false&&$public['plans']===[]
            &&$result['ok']===false&&$result['candidates']===[]&&count($requests)===2);
    }
    foreach (['missing','null','mismatch','uppercase'] as $revision) {
        $catalogue=preparationMealCatalogue();
        if ($revision==='missing') unset($catalogue['revision']);
        elseif ($revision==='null') $catalogue['revision']=null;
        elseif ($revision==='mismatch') $catalogue['plans'][0]['nameRu']='Changed after revision';
        else $catalogue['revision']=strtoupper($catalogue['revision']);
        $requests=[];$result=$reader->read($args+['meal_plan_id'=>501]);
        preparationMcpCheck('unverified catalogue revision cannot authorize a meal listing '.$revision,
            $result['ok']===false&&$result['candidates']===[]&&count($requests)===1);
    }
    $catalogue=['source'=>'anytour-search-meal-v1','provider'=>'tourvisor','scopeKey'=>'global','available'=>false,'plans'=>[],'revision'=>null];
    $requests=[];$public=$reader->readMealCatalog([]);$result=$reader->read($args+['meal_plan_id'=>501]);
    preparationMcpCheck('missing catalogue table is unavailable rather than an empty complete catalogue',
        $public['ok']===false&&$public['available']===false&&$public['complete']===false&&$public['plans']===[]
        &&$result['ok']===false&&$result['candidates']===[]&&count($requests)===2);
    $catalogue=preparationMealCatalogue();$catalogue['plans']=array_map(static fn($i)=>
        ['id'=>$i,'code'=>'C'.$i,'nameRu'=>str_repeat('n',512),'nativeIds'=>['native-'.$i]],range(1,100));
    $catalogue=preparationMealRevision($catalogue);$public=$reader->readMealCatalog([]);
    preparationMcpCheck('public meal catalogue overflow is unavailable without publishing a partial plan list',
        $public['ok']===false&&$public['complete']===false&&$public['plans']===[]
        &&strlen(json_encode($public,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=32768);
    foreach ([['status'=>403,'body'=>'PRIVATE ERROR'],['status'=>302,'body'=>'PRIVATE REDIRECT'],
        ['status'=>200,'type'=>'text/html','body'=>'PRIVATE HTML'],['status'=>200,'type'=>'application/json','body'=>'{'],
        ['status'=>200,'type'=>'application/json','body'=>"{\"ok\":true,\"data\":\"\xff\"}"]] as $badResponse) {
        $attempts=0;$failed=new ManagerSearch3CandidateReader(static function() use(&$attempts,$badResponse){++$attempts;return $badResponse;},$directory,static fn()=>$now);
        $public=$failed->readMealCatalog([]);$result=$failed->read($args+['meal_plan_id'=>501]);
        preparationMcpCheck('catalogue HTTP or malformed body failure never proceeds to listing '.$badResponse['status'].'/'.$attempts,
            $attempts===2&&$public['ok']===false&&$public['plans']===[]&&$result['ok']===false&&$result['candidates']===[]
            &&!str_contains(json_encode([$public,$result]),'PRIVATE'));
    }

    $catalogue=preparationMealCatalogue();$catalogueResponse=preparationMealResponse($catalogue);
    $ticks=1000000000;$limits=[];
    $budgetReader=new ManagerSearch3CandidateReader(static function($url,$envelope,$budget) use(&$limits,&$ticks,$catalogueResponse,$now){
        $limits[]=$budget;
        if (isset($envelope['action'])) {$ticks+=1250000000;return $catalogueResponse;}
        return ['status'=>200,'type'=>'application/json','body'=>json_encode(preparationCandidateSource($envelope['params'],$now))];
    },$directory,static fn()=>$now,static function() use(&$ticks){return $ticks;});
    $result=$budgetReader->read($args+['meal_plan_id'=>501]);
    preparationMcpCheck('catalogue and listing share one five-second deadline and remaining response bytes',
        $result['ok']===true&&count($limits)===2&&$limits[0]['timeout_ms']===5000&&$limits[1]['timeout_ms']===3750
        &&$limits[0]['deadline_ns']===$limits[1]['deadline_ns']&&$limits[0]['max_bytes']===4194304
        &&$limits[1]['max_bytes']===4194304-strlen($catalogueResponse['body']));
    foreach (['catalogue','listing'] as $slowStage) {
        $ticks=1000000000;$attempts=0;
        $slow=new ManagerSearch3CandidateReader(static function($url,$envelope,$budget) use(&$ticks,&$attempts,$slowStage,$catalogueResponse,$now){
            ++$attempts;$isCatalogue=isset($envelope['action']);
            if (($slowStage==='catalogue')===$isCatalogue) $ticks=$budget['deadline_ns'];
            return $isCatalogue?$catalogueResponse:['status'=>200,'type'=>'application/json','body'=>json_encode(preparationCandidateSource($envelope['params'],$now))];
        },$directory,static fn()=>$now,static function() use(&$ticks){return $ticks;});
        $result=$slow->read($args+['meal_plan_id'=>501]);
        preparationMcpCheck('shared deadline exhaustion withholds late data without sleeping '.$slowStage,
            $result['ok']===false&&$result['candidates']===[]&&$attempts===($slowStage==='catalogue'?1:2));
    }
    $limits=[];
    $oversized=new ManagerSearch3CandidateReader(static function($url,$envelope,$budget) use(&$limits,$catalogueResponse,$now){
        $limits[]=$budget;
        if (isset($envelope['action'])) return $catalogueResponse;
        $body=json_encode(preparationCandidateSource($envelope['params'],$now));
        return ['status'=>200,'type'=>'application/json','body'=>$body.str_repeat(' ',$budget['max_bytes']-strlen($body)+1)];
    },$directory,static fn()=>$now);
    $result=$oversized->read($args+['meal_plan_id'=>501]);
    preparationMcpCheck('listing body is bounded by bytes remaining after the catalogue transfer',
        $result['ok']===false&&$result['candidates']===[]&&count($limits)===2&&$limits[1]['max_bytes']<4194304);

    $pdo=preparationMcpFixture();$connected=true;$storeCalls=0;$requests=[];$connection=str_repeat('a',32);
    $active=static function(string $family,int $actor) use(&$connected,$connection): bool {return $connected&&$family===$connection&&$actor===1;};
    $service=new ManagerPreparationMcpService($pdo,static function() use(&$storeCalls){++$storeCalls;return [];},null,null,$active,null,$reader);
    $rpc=preparationMcpRpc('tools/call',['name'=>ManagerSearch3CandidateReader::MEAL_TOOL,'arguments'=>[]]);
    $tool=$service->handle(preparationMcpRpc('tools/list'),1)['result']['tools'][4];
    preparationMcpCheck('meal discovery advertises read-only empty arguments and the existing OAuth scopes',
        $tool['name']===ManagerSearch3CandidateReader::MEAL_TOOL&&$tool['annotations']['readOnlyHint']===true
        &&$tool['inputSchema']['additionalProperties']===false&&(array)$tool['inputSchema']['properties']===[]
        &&$tool['securitySchemes']===[['type'=>'oauth2','scopes'=>['queue:read','prepared:write']]]);
    $result=$service->handle($rpc,1,$connection);
    preparationMcpCheck('authenticated meal tool returns matching JSON representations without job or event writes',
        ($result['result']['structuredContent']['ok']??false)===true
        &&json_decode($result['result']['content'][0]['text'],true)===$result['result']['structuredContent']&&$storeCalls===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===0);
    $before=count($requests);
    $invalid=$service->handle(preparationMcpRpc('tools/call',[
        'name'=>ManagerSearch3CandidateReader::MEAL_TOOL,'arguments'=>['provider'=>'tourvisor']]),1,$connection);
    preparationMcpCheck('MCP rejects meal catalogue arguments before source access',
        ($invalid['error']['code']??0)===-32602&&count($requests)===$before);
    foreach ([[0,$connection],[2,$connection],[3,$connection],[4,$connection],[1,null],[1,str_repeat('b',32)]] as [$actor,$family]) {
        $before=count($requests);$result=$service->handle($rpc,$actor,$family);
        preparationMcpCheck('meal tool requires current admin and the requesting OAuth connection '.$actor.'/'.($family??'missing'),
            ($result['error']['code']??0)===-32001&&count($requests)===$before);
    }
    $before=count($requests);$connected=false;$result=$service->handle($rpc,1,$connection);$connected=true;
    preparationMcpCheck('revoked meal connection is checked before HTTP',($result['error']['code']??0)===-32001&&count($requests)===$before);
    foreach (['admin','project','connection','current_project'] as $revoke) {
        $race=preparationMcpFixture();$live=true;
        $raceReader=new ManagerSearch3CandidateReader(static function() use($race,$revoke,&$live,$catalogueResponse){
            if ($revoke==='admin') $race->exec('UPDATE managers SET is_active=0 WHERE id=1');
            elseif ($revoke==='project') $race->exec('DELETE FROM manager_projects WHERE manager_id=1');
            elseif ($revoke==='current_project') ProjectConfig::resetForTests(['id'=>'other']);
            else $live=false;
            return $catalogueResponse;
        },$directory,static fn()=>$now);
        $raceService=new ManagerPreparationMcpService($race,null,null,null,
            static function($family,$actor) use(&$live,$connection){return $live&&$family===$connection&&$actor===1;},null,$raceReader);
        $result=$raceService->handle($rpc,1,$connection);
        preparationMcpCheck('meal result is withheld when authorization changes during source read '.$revoke,
            ($result['error']['code']??0)===-32001&&!isset($result['result'])&&!str_contains(json_encode($result),'Всё включено'));
        ProjectConfig::resetForTests(['id'=>'anytour']);
    }
}

function preparationMcpDiagnosticChecks(): void
{
    $dir=sys_get_temp_dir().'/mcp-diagnostics-'.bin2hex(random_bytes(6));mkdir($dir,0700);
    $file=$dir.'/events.log';DiagnosticLogger::setFile($file);
    try {
        $server=['REQUEST_METHOD'=>'POST','HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('S',43),
            'HTTP_MCP_PROTOCOL_VERSION'=>'2025-11-25','HTTP_COOKIE'=>'PRIVATE COOKIE','HTTP_ORIGIN'=>'PRIVATE ORIGIN'];
        $rpc=preparationMcpRpc('initialize',['protocolVersion'=>'2025-11-25','clientInfo'=>['name'=>'PRIVATE CLIENT']]);
        ManagerPreparationMcpDiagnostics::record($server,$rpc,200,'complete',null);
        $snapshot=ManagerPreparationMcpDiagnostics::snapshot();$last=$snapshot['recent_attempts'][0]??[];
        preparationMcpCheck('HTTP discovery diagnostics retain only useful protocol categories',
            $snapshot['sampled_count']===1&&$last['credential_header']==='bearer'&&$last['rpc_method']==='initialize'
            &&$last['requested_version']==='2025-11-25'&&$last['http_status']===200&&$last['rpc_error']===null);
        $raw=(string)file_get_contents($file);
        preparationMcpCheck('HTTP discovery log contains no credentials or client-supplied strings',
            !str_contains($raw,str_repeat('S',43))&&!str_contains($raw,'PRIVATE')&&!str_contains($raw,'clientInfo'));
        preparationMcpCheck('HTTP discovery records use private file permissions',(fileperms($file)&0777)===0600);
        foreach (['missing'=>[], 'redirect_only'=>['REDIRECT_HTTP_AUTHORIZATION'=>'PRIVATE TOKEN'],
            'noncanonical_bearer'=>['HTTP_AUTHORIZATION'=>'bearer '.str_repeat('S',43)]] as $expected=>$headers) {
            $meta=ManagerPreparationMcpDiagnostics::metadata($headers,null,401,'credentials',-32001);
            preparationMcpCheck('credential delivery category '.$expected,$meta['credential_header']===$expected
                &&$meta['stage']==='credentials'&&$meta['rpc_method']==='absent'&&$meta['rpc_error']===-32001);
        }
        $probeStarted=time();
        $probeServer=['REQUEST_METHOD'=>'POST','HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('P',43),
            'HTTP_USER_AGENT'=>'AnyTour-MCP-Header-Probe/1.0','HTTP_COOKIE'=>'PRIVATE COOKIE'];
        foreach (['legacy','development','replies','hotels'] as $surface) {
            ManagerPreparationMcpDiagnostics::record($probeServer,null,401,'credentials',-32001,$surface);
            $probe=ManagerPreparationMcpDiagnostics::snapshot()['recent_attempts'][0]??[];
            $oneProbe=['recent_attempts'=>[$probe]];
            preparationMcpCheck('installed ingress evidence retains its exact fixed surface '.$surface,
                ($probe['surface']??null)===$surface&&ManagerPreparationMcpDiagnostics::headerProbeDelivered($oneProbe,$probeStarted,$surface));
            $foreign=$surface==='legacy'?'replies':'legacy';
            preparationMcpCheck('another endpoint cannot satisfy ingress evidence '.$surface,
                !ManagerPreparationMcpDiagnostics::headerProbeDelivered($oneProbe,$probeStarted,$foreign));
            $allRejected=true;
            foreach ([['credential_header'=>'missing'],['client_kind'=>'other'],['http_status'=>200],
                ['stage'=>'complete'],['rpc_error'=>null],['at'=>gmdate('c',$probeStarted-1)]] as $change) {
                $allRejected=$allRejected&&!ManagerPreparationMcpDiagnostics::headerProbeDelivered(
                    ['recent_attempts'=>[array_replace($probe,$change)]],$probeStarted,$surface);
            }
            preparationMcpCheck('ingress success requires fresh rejected bearer observation '.$surface,$allRejected);
        }
        $spoofed=ManagerPreparationMcpDiagnostics::metadata($probeServer,
            preparationMcpRpc('initialize',['protocolVersion'=>'2025-11-25','surface'=>'PRIVATE SURFACE',
                '_meta'=>['surface'=>'development'],'arguments'=>['token'=>'PRIVATE TOKEN']]),200,'complete',null,'replies');
        preparationMcpCheck('request metadata cannot replace fixed profile or disclose credentials',
            $spoofed['surface']==='replies'&&!str_contains(json_encode($spoofed),'PRIVATE')
            &&!str_contains(json_encode($spoofed),str_repeat('P',43)));
        $unknownSurface=ManagerPreparationMcpDiagnostics::metadata($probeServer,null,401,'credentials',-32001,'PRIVATE SURFACE');
        preparationMcpCheck('unknown endpoint identity is sanitized and cannot satisfy known ingress',
            $unknownSurface['surface']==='other'&&!str_contains(json_encode($unknownSurface),'PRIVATE')
            &&!ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[
                ['at'=>gmdate('c',$probeStarted)]+$unknownSurface]],$probeStarted,'replies'));
        $hostile=ManagerPreparationMcpDiagnostics::metadata(['REQUEST_METHOD'=>'PRIVATE METHOD'],
            ['method'=>['PRIVATE METHOD'],'params'=>['protocolVersion'=>'PRIVATE VERSION']],999,'PRIVATE STAGE',999);
        preparationMcpCheck('unknown and malformed diagnostic values cannot become published text',
            !str_contains(json_encode($hostile),'PRIVATE')&&$hostile['http_status']===0&&$hostile['rpc_error']===null);
        // A corrupt or old writer cannot smuggle new fields through the public reader.
        $row=['ts'=>gmdate('c'),'component'=>'manager_preparation_mcp','event'=>'http_result',
            'data'=>['http_method'=>'POST','rpc_method'=>'PRIVATE RPC','stage'=>'complete','http_status'=>200,
                'access_token'=>'PRIVATE TOKEN','text'=>'PRIVATE TRANSCRIPT','manager_id'=>7]];
        file_put_contents($file,json_encode($row)."\n",FILE_APPEND);
        $published=json_encode(ManagerPreparationMcpDiagnostics::snapshot());
        preparationMcpCheck('snapshot re-allowlists stored records and excludes injected private fields',
            !str_contains($published,'PRIVATE')&&!str_contains($published,'access_token')&&!str_contains($published,'manager_id'));
        for ($i=0;$i<24;++$i) ManagerPreparationMcpDiagnostics::record($server,preparationMcpRpc('tools/list'),200,'complete',null);
        preparationMcpCheck('discovery history is capped at twenty most recent attempts',ManagerPreparationMcpDiagnostics::snapshot()['sampled_count']===20);
        file_put_contents($file,str_repeat('x',524289)."\n",FILE_APPEND);
        ManagerPreparationMcpDiagnostics::record($server,preparationMcpRpc('tools/list'),200,'complete',null);
        preparationMcpCheck('discovery reader never searches beyond its bounded tail',ManagerPreparationMcpDiagnostics::snapshot()['sampled_count']===1);
        DiagnosticLogger::setFile($dir.'/missing/events.log');
        ManagerPreparationMcpDiagnostics::record($server,$rpc,200,'complete',null);
        preparationMcpCheck('unavailable diagnostic storage is nonfatal and reports no sample',ManagerPreparationMcpDiagnostics::snapshot()['sampled_count']===0);
    } finally {DiagnosticLogger::setFile('');@unlink($file);@rmdir($dir);}
}

function preparationMcpConnectionChecks(): void
{
    $pdo=preparationMcpFixture();$calls=0;
    $first=str_repeat('a',32);$second=str_repeat('b',32);
    $job=['job_id'=>str_repeat('c',64),'project_key'=>'anytour','context_revision'=>str_repeat('d',64),
        'oauth_connection_id'=>'PRIVATE JOB CONNECTION','private_text'=>'PRIVATE TRANSCRIPT'];
    $store=static function(string $operation,array $args) use (&$calls,$job): array {
        ++$calls;
        if ($operation!=='pending') throw new RuntimeException('Unexpected identity-test store operation');
        return ['ok'=>true,'jobs'=>[$job]];
    };
    $active=static function(string $family,int $actor) use ($first,$second): bool {
        return $actor===1 && in_array($family,[$first,$second],true);
    };
    $service=new ManagerPreparationMcpService($pdo,$store,null,null,$active);
    $rpc=preparationMcpRpc('tools/call',['name'=>'list_pending_replies','arguments'=>['limit'=>1]]);
    $before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    foreach ([[$first,$second],[$second,$first]] as [$current,$other]) {
        $response=$service->handle($rpc,1,$current);$result=$response['result']??[];
        $data=$result['structuredContent']??[];
        preparationMcpCheck('queue identifies only the exact requesting OAuth grant '.$current[0],
            ($data['oauth_connection_id']??null)===$current
            &&json_decode($result['content'][0]['text']??'',true)===$data
            &&!str_contains(json_encode($response),$other)
            &&!str_contains(json_encode($response),'PRIVATE'));
    }
    $spoofed=$rpc;$spoofed['params']['_meta']=['oauth_connection_id'=>$second,
        'connection_id'=>$second,'grant_id'=>$second,'access_token'=>'PRIVATE TOKEN'];
    $data=$service->handle($spoofed,1,$first)['result']['structuredContent']??[];
    preparationMcpCheck('request metadata cannot replace authenticated reply grant identity',
        ($data['oauth_connection_id']??null)===$first&&!str_contains(json_encode($data),$second));
    $data=$service->handle($spoofed,1)['result']['structuredContent']??[];
    preparationMcpCheck('unbound internal queue call reports unknown identity even with forged metadata',
        array_key_exists('oauth_connection_id',$data)&&$data['oauth_connection_id']===null);
    $emptyService=new ManagerPreparationMcpService($pdo,
        static fn(string $operation,array $args): array=>['ok'=>true,'jobs'=>[]],null,null,$active);
    $empty=$emptyService->handle($rpc,1,$first)['result']['structuredContent']??[];
    preparationMcpCheck('an empty reply queue retains the exact requesting OAuth grant identity',
        ($empty['jobs']??null)===[]&&($empty['oauth_connection_id']??null)===$first);
    preparationMcpCheck('reply grant identity read changes no database rows or subscription state',
        (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before);

    $beforeCalls=$calls;$badArgs=$rpc;$badArgs['params']['arguments']['oauth_connection_id']=$second;
    preparationMcpCheck('a reply caller cannot select a grant through tool arguments',
        ($service->handle($badArgs,1,$first)['error']['code']??0)===-32602&&$calls===$beforeCalls);
    foreach ([[0,$first],[2,$first],[3,$first],[4,$first],[1,str_repeat('e',32)]] as [$actor,$family]) {
        $response=$service->handle($rpc,$actor,$family);
        preparationMcpCheck('unauthorized caller cannot read reply grant identity '.$actor.'/'.$family[0],
            ($response['error']['code']??0)===-32001&&!isset($response['result'])
            &&!str_contains(json_encode($response),$family)&&$calls===$beforeCalls);
    }

    foreach (['admin','project','connection'] as $revocation) {
        $racePdo=preparationMcpFixture();$connected=true;
        $raceStore=static function() use ($racePdo,$revocation,&$connected): array {
            if ($revocation==='admin') $racePdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
            elseif ($revocation==='project') $racePdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
            else $connected=false;
            return ['ok'=>true,'jobs'=>[]];
        };
        $raceActive=static function(string $family,int $actor) use (&$connected,$first): bool {
            return $connected&&$family===$first&&$actor===1;
        };
        $raceService=new ManagerPreparationMcpService($racePdo,$raceStore,null,null,$raceActive);
        $response=$raceService->handle($rpc,1,$first);
        preparationMcpCheck('reply grant identity withheld after revocation during inventory read '.$revocation,
            ($response['error']['code']??0)===-32001&&!isset($response['result'])
            &&!str_contains(json_encode($response),$first));
    }
}

if(in_array('--hotel-demand-inventory',$argv??[],true)){
    try{hotelDemandInventoryChecks();echo "OK hotel demand inventory checks: $passed\n";exit(0);}
    catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
}

try {
    hotelDemandInventoryChecks();
    preparationMcpConnectionChecks();
    preparationCandidateChecks();
    preparationMealChecks();
    preparationMcpDiagnosticChecks();
    foreach (['admin','project','connection'] as $revocation) {
        $racePdo=preparationMcpFixture();$stillConnected=true;
        $raceStore=static function() use ($racePdo,$revocation,&$stillConnected): array {
            if ($revocation==='admin') $racePdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
            elseif ($revocation==='project') $racePdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
            else $stillConnected=false;
            return ['ok'=>true,'context'=>['private'=>'PRIVATE delayed context']];
        };
        $raceService=new ManagerPreparationMcpService($racePdo,$raceStore,null,null,static function() use (&$stillConnected): bool {return $stillConnected;});
        $raceResult=$raceService->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>str_repeat('1',64)]]),1,str_repeat('a',32));
        preparationMcpCheck($revocation.' revoked during context read blocks private response', ($raceResult['error']['code']??0)===-32001 && !str_contains(json_encode($raceResult),'PRIVATE'));
    }
    $pdo=preparationMcpFixture();$now=1792406400;$connection=str_repeat('a',32);$connectionValid=true;
    $secret='whsec_'.base64_encode(str_repeat('k',32));$nextSecret='whsec_'.base64_encode(str_repeat('n',32));
    $job=['job_id'=>str_repeat('1',64),'project_key'=>'anytour','context_revision'=>str_repeat('2',64),
        'conversation_id'=>10,'manager_id'=>2,'latest_message_id'=>99,'created_at'=>'2026-10-19 08:00:00',
        'private_text'=>'PRIVATE TRANSCRIPT','private_name'=>'PRIVATE CUSTOMER'];
    $jobs=[$job];$calls=[];$requests=[];$statuses=[];$challengeGood=true;$resolveIps=['8.8.8.8'];$pendingAdvance=0;
    $resolver=static function(string $host) use (&$resolveIps): array { return $resolveIps; };
    $clock=static function() use (&$now): int { return $now; };
    $transport=static function(string $url,array $headers,string $body,array $destination) use (&$requests,&$statuses,&$challengeGood): array {
        $decoded=json_decode($body,true);$requests[]=['url'=>$url,'headers'=>preparationMcpHeaders($headers),'body'=>$body,'decoded'=>$decoded,'destination'=>$destination];
        if (($decoded['type']??'')==='verification') return ['status'=>200,'body'=>json_encode(['challenge'=>$challengeGood?$decoded['challenge']:'wrong'])];
        return ['status'=>array_shift($statuses)??204,'body'=>''];
    };
    $webhook=new ManagerPreparationWebhook($transport,$resolver,$clock);
    $imageInputs=[];
    $store=static function(string $op,array $args) use (&$calls,&$jobs,&$pendingAdvance,&$now,&$imageInputs): array {
        $calls[]=[$op,$args];
        if ($op==='pending' && $pendingAdvance>0) { $now+=$pendingAdvance;$pendingAdvance=0; }
        return match ($op) {
            'pending'=>['ok'=>true,'jobs'=>array_slice($jobs,0,$args[0])],
            'context'=>['ok'=>true,'revision'=>str_repeat('2',64),'context'=>['messages'=>[['id'=>99,'text'=>'Synthetic customer']]],'image_inputs'=>$imageInputs],
            'complete'=>['ok'=>true,'job_id'=>$args[0],'idempotent'=>true],
        };
    };
    $active=static function(string $family,int $actor) use (&$connectionValid,$connection): bool { return $connectionValid && $family===$connection && in_array($actor,[1,4],true); };
    $service=new ManagerPreparationMcpService($pdo,$store,$webhook,$clock,$active);
    $subscribe=['name'=>ManagerPreparationMcpService::EVENT,'arguments'=>['project_key'=>'anytour'],
        'delivery'=>['mode'=>'webhook','url'=>'https://callback.example.com/events/abc','secret'=>$secret]];

    preparationMcpCheck('MCP2 discovery advertises tools and events',isset($service->handle(preparationMcpRpc('server/discover'),1)['result']['capabilities']['events']));
    preparationMcpCheck('only protocol 2026-07-28 is advertised',$service->handle(preparationMcpRpc('server/discover'),1)['result']['supportedVersions']===['2026-07-28']);
    foreach (['2025-11-25','2025-06-18','2025-03-26','2024-11-05'] as $version) {
        $params=['protocolVersion'=>$version,'capabilities'=>[],
            'clientInfo'=>['name'=>'synthetic-discovery-client','version'=>'1.0']];
        $initialized=$service->handle(preparationMcpRpc('initialize',$params),1);
        preparationMcpCheck('handshake preserves supported client version '.$version,
            ($initialized['result']['protocolVersion']??null)===$version);
        $wire=json_decode(json_encode($initialized,JSON_THROW_ON_ERROR),false,32,JSON_THROW_ON_ERROR);
        preparationMcpCheck('handshake capabilities encode as objects without Events '.$version,
            $wire->result->capabilities->tools instanceof stdClass && !isset($wire->result->capabilities->events));
        $available=$service->handle(preparationMcpRpc('tools/list'),1);
        preparationMcpCheck('handshake client can discover the preparation tools '.$version,
            array_column($available['result']['tools']??[],'name')===array_merge(['list_pending_replies','get_reply_context','save_prepared_reply',ManagerSearch3CandidateReader::TOOL,ManagerSearch3CandidateReader::MEAL_TOOL],VirtualReplyMcpAdapter::NAMES,[ManagerHotelKnowledgeMcpAdapter::NAME],HotelWorkMcpAdapter::NAMES,ArchiveResearchMcpAdapter::NAMES,DevelopmentWorkMcpAdapter::NAMES,[ModeledConversionStatusMcpAdapter::NAME]));
    }
    foreach (['2099-01-01','2026-07-28'] as $version) {
        $initialized=$service->handle(preparationMcpRpc('initialize',['protocolVersion'=>$version]),1);
        preparationMcpCheck('unknown handshake version offers a supported fallback '.$version,
            ($initialized['result']['protocolVersion']??null)==='2025-11-25');
    }
    foreach ([null,[],42,'','unsupported'] as $version) {
        preparationMcpCheck('malformed handshake version is rejected '.get_debug_type($version),
            ($service->handle(preparationMcpRpc('initialize',['protocolVersion'=>$version]),1)['error']['code']??0)===-32602);
    }
    foreach ([0,2,3] as $actor) preparationMcpCheck('handshake retains active project admin boundary '.$actor,
        ($service->handle(preparationMcpRpc('initialize',['protocolVersion'=>'2025-06-18']),$actor)['error']['code']??0)===-32001);
    preparationMcpCheck('handshake and discovery do not read or write queued jobs',$calls===[] && $requests===[]
        && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===0);
    $discovery=$service->handle(preparationMcpRpc('server/discover'),1)['result'];
    preparationMcpCheck('MCP2 Events discovery remains available after legacy handshakes',
        $discovery['supportedVersions']===['2026-07-28'] && isset($discovery['capabilities']['events']));
    foreach ([0,2,3] as $actor) preparationMcpCheck('current active project admin required '.$actor,($service->handle(preparationMcpRpc('tools/list'),$actor)['error']['code']??0)===-32001);
    preparationMcpCheck('invalid JSON-RPC envelope is rejected',($service->handle(['method'=>'tools/list'],1)['error']['code']??0)===-32600);
    preparationMcpCheck('unknown method is rejected',($service->handle(preparationMcpRpc('customers/send'),1)['error']['code']??0)===-32601);
    $tools=$service->handle(preparationMcpRpc('tools/list'),1)['result']['tools'];
    preparationMcpCheck('existing tools plus hotel step-up metadata are exposed',array_column($tools,'name')===array_merge(['list_pending_replies','get_reply_context','save_prepared_reply',ManagerSearch3CandidateReader::TOOL,ManagerSearch3CandidateReader::MEAL_TOOL],VirtualReplyMcpAdapter::NAMES,[ManagerHotelKnowledgeMcpAdapter::NAME],HotelWorkMcpAdapter::NAMES,ArchiveResearchMcpAdapter::NAMES,DevelopmentWorkMcpAdapter::NAMES,[ModeledConversionStatusMcpAdapter::NAME]));
    preparationMcpCheck('tools advertise matching OAuth scope metadata',$tools[0]['securitySchemes']===[['type'=>'oauth2','scopes'=>['queue:read','prepared:write']]] && $tools[0]['_meta']['securitySchemes']===$tools[0]['securitySchemes']);
    $protocolMeta=['io.modelcontextprotocol/protocolVersion'=>'2026-07-28',
        'io.modelcontextprotocol/clientInfo'=>['name'=>'Synthetic client','version'=>'1'],
        'io.modelcontextprotocol/clientCapabilities'=>[], 'example.com/private'=>'PRIVATE METADATA'];
    foreach ([[],['progressToken'=>'synthetic-progress'],$protocolMeta] as $meta) {
        foreach ([[],['limit'=>1]] as $args) {
            $reply=$service->handle(preparationMcpRpc('tools/call',[
                'name'=>'list_pending_replies','arguments'=>$args,'_meta'=>$meta]),1);
            preparationMcpCheck('standard request metadata permits bounded queue reads '.count($meta).'/'.count($args),
                ($reply['result']['structuredContent']['ok']??false)===true
                && end($calls)===['pending',[$args['limit']??20]]
                && !str_contains(json_encode($reply),'PRIVATE'));
        }
    }
    $before=count($calls);
    foreach ([null,'metadata',true,17,['list-item']] as $meta) {
        preparationMcpCheck('nonobject request metadata is rejected '.get_debug_type($meta),
            ($service->handle(preparationMcpRpc('tools/call',[
                'name'=>'list_pending_replies','_meta'=>$meta]),1)['error']['code']??0)===-32602);
    }
    preparationMcpCheck('invalid metadata does not reach the queue',count($calls)===$before);
    foreach ([0,2,3] as $actor) preparationMcpCheck('metadata cannot replace active project admin authorization '.$actor,
        ($service->handle(preparationMcpRpc('tools/call',[
            'name'=>'list_pending_replies','_meta'=>$protocolMeta+['admin_id'=>1,'project_key'=>'anytour']]),$actor)['error']['code']??0)===-32001);
    foreach ([['name'=>'list_pending_replies','project_key'=>'other','_meta'=>$protocolMeta],
        ['name'=>'list_pending_replies','arguments'=>['limit'=>21],'_meta'=>$protocolMeta],
        ['name'=>'list_pending_replies','arguments'=>['limit'=>'1'],'_meta'=>$protocolMeta],
        ['name'=>'list_pending_replies','arguments'=>['_meta'=>$protocolMeta],'_meta'=>$protocolMeta]] as $params) {
        preparationMcpCheck('request metadata does not relax command validation '.count($params).'/'.count($params['arguments']??[]),
            ($service->handle(preparationMcpRpc('tools/call',$params),1)['error']['code']??0)===-32602);
    }
    preparationMcpCheck('denied metadata calls have no queue side effects',count($calls)===$before);
    $listed=$service->handle(preparationMcpRpc('tools/call',['name'=>'list_pending_replies','arguments'=>['limit'=>20]]),1)['result']['structuredContent'];
    preparationMcpCheck('metadata strips customer identity and transcript',!str_contains(json_encode($listed),'PRIVATE') && !isset($listed['jobs'][0]['conversation_id']));
    preparationMcpCheck('canonical context revision is exposed',$listed['jobs'][0]['revision']===$job['context_revision']);
    preparationMcpCheck('queue inventory exposes heartbeat availability separately from empty connection inventory',
        is_bool($listed['preparation_health']['tick']['available']??null)
        && ($listed['preparation_health']['connections']['active']??null)===0);
    preparationMcpCheck('authorized queue inventory can diagnose the exact draft route source without a pending job',
        is_string($listed['preparation_health']['travel_reference']['reason']??null)
        && isset($listed['preparation_health']['travel_reference']['status'])
        && !str_contains(json_encode($listed['preparation_health']['travel_reference']),dirname(__DIR__)));
    $jobs[]=$job+[];$jobs[1]['project_key']='other';
    preparationMcpCheck('metadata cannot leak another project',count($service->handle(preparationMcpRpc('tools/call',['name'=>'list_pending_replies']),1)['result']['structuredContent']['jobs'])===1);$jobs=[$job];
    preparationMcpCheck('tool batch limit cannot exceed twenty',($service->handle(preparationMcpRpc('tools/call',['name'=>'list_pending_replies','arguments'=>['limit'=>21]]),1)['error']['code']??0)===-32602);
    $context=$service->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>$job['job_id']]]),1);
    preparationMcpCheck('canonical store receives the opaque context job',$context['result']['structuredContent']['ok'] && end($calls)===['context',[$job['job_id']]]);
    $imageInputs=[['type'=>'input_image','image_url'=>'data:image/png;base64,U1lOVEhFVElDX1BIT1RP','detail'=>'high']];
    $photoContext=$service->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>$job['job_id']]]),1)['result'];
    preparationMcpCheck('private preparation returns an actual MCP image block beside the text context',
        $photoContext['content'][1]===['type'=>'image','mimeType'=>'image/png','data'=>'U1lOVEhFVElDX1BIT1RP']);
    preparationMcpCheck('image bytes are absent from structured context and its duplicate text representation',
        !isset($photoContext['structuredContent']['image_inputs'])&&!str_contains($photoContext['content'][0]['text'],'U1lOVEhFVElDX1BIT1RP'));
    $imageInputs=[];
    $proposal=['ok'=>true,'should_draft'=>false,'draft'=>'','reason'=>'Synthetic','checks'=>[]];
    $save=$service->handle(preparationMcpRpc('tools/call',['name'=>'save_prepared_reply','arguments'=>['job_id'=>$job['job_id'],'revision'=>$job['context_revision'],'proposal'=>$proposal]]),1);
    preparationMcpCheck('save uses canonical idempotent store without a provider',$save['result']['structuredContent']['idempotent'] && end($calls)===['complete',[$job['job_id'],$job['context_revision'],$proposal]]);
    foreach ([['get_reply_context',['job_id'=>$job['job_id']],'context',[$job['job_id']]],
        ['save_prepared_reply',['job_id'=>$job['job_id'],'revision'=>$job['context_revision'],'proposal'=>$proposal],
            'complete',[$job['job_id'],$job['context_revision'],$proposal]]] as [$name,$args,$operation,$expected]) {
        $reply=$service->handle(preparationMcpRpc('tools/call',['name'=>$name,'arguments'=>$args,'_meta'=>$protocolMeta]),1);
        preparationMcpCheck('protocol metadata is not forwarded to the canonical store '.$name,
            ($reply['result']['structuredContent']['ok']??false)===true && end($calls)===[$operation,$expected]);
    }
    preparationMcpCheck('unknown tool arguments fail closed',($service->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>$job['job_id'],'project'=>'other']]),1)['error']['code']??0)===-32602);
    preparationMcpCheck('event definition has strict project filter',$service->handle(preparationMcpRpc('events/list'),1)['result']['events'][0]['inputSchema']['properties']['project_key']['const']==='anytour');
    preparationMcpCheck('subscription requires a live OAuth connection',($service->handle(preparationMcpRpc('events/subscribe',$subscribe),1)['error']['code']??0)===-32001);

    $sub=$service->handle(preparationMcpRpc('events/subscribe',$subscribe+['_meta'=>$protocolMeta]),1,$connection);
    preparationMcpCheck('signed callback challenge activates a persistent subscription',isset($sub['result']['id']) && count($requests)===1 && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===1);
    $beforeHealth=$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll();$beforeRequests=count($requests);
    $health=$service->preparationHealth();
    preparationMcpCheck('health distinguishes active subscriptions without writing or delivering',
        ($health['connections']['active']??null)===1 && $beforeHealth===$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll() && count($requests)===$beforeRequests);
    $connectionValid=false;
    preparationMcpCheck('health distinguishes revoked authorization from a stopped cron',($service->preparationHealth()['connections']['unauthorized']??null)===1);
    $connectionValid=true;
    $pdo->prepare('UPDATE manager_preparation_events SET expires_at=?')->execute([gmdate('Y-m-d H:i:s',$now-1)]);
    preparationMcpCheck('health reports subscription expiry independently of OAuth',($service->preparationHealth()['connections']['expired']??null)===1);
    $pdo->prepare('UPDATE manager_preparation_events SET expires_at=?')->execute([$beforeHealth[0]['expires_at']]);
    preparationMcpCheck('health excludes callback secrets and identities',!str_contains(json_encode($health),'callback.example.com') && !str_contains(json_encode($health),'oauth_connection_id'));
    $subId=$sub['result']['id'];$challenge=$requests[0];$headers=$challenge['headers'];
    $expected='v1,'.base64_encode(hash_hmac('sha256',$headers['webhook-id'].'.'.$headers['webhook-timestamp'].'.'.$challenge['body'],str_repeat('k',32),true));
    preparationMcpCheck('challenge signature uses exact Standard Webhooks bytes',hash_equals($expected,$headers['webhook-signature']) && $headers['x-mcp-subscription-id']===$subId);
    preparationMcpCheck('callback uses the validated pinned public destination',$challenge['destination']===['host'=>'callback.example.com','ip'=>'8.8.8.8']);
    $same=$service->handle(preparationMcpRpc('events/subscribe',$subscribe),1,$connection);
    preparationMcpCheck('refresh is idempotent with bounded verification cache',$same['result']['id']===$subId && count($requests)===1);
    $short=$subscribe;$short['ttlMs']=5000;$shortResult=$service->handle(preparationMcpRpc('events/subscribe',$short),1,$connection);
    preparationMcpCheck('granted expiration never exceeds requested TTL',strtotime($shortResult['result']['refreshBefore'])===$now+5);
    $long=$subscribe;$long['ttlMs']=172800000;$longResult=$service->handle(preparationMcpRpc('events/subscribe',$long),1,$connection);
    preparationMcpCheck('subscription TTL is capped at twenty four hours',strtotime($longResult['result']['refreshBefore'])===$now+86400);
    $bad=$subscribe;$bad['arguments']['project_key']='other';
    preparationMcpCheck('other project subscription is rejected',($service->handle(preparationMcpRpc('events/subscribe',$bad),1,$connection)['error']['code']??0)===-32602);
    $bad=$subscribe;$bad['delivery']['secret']='whsec_'.base64_encode('short');
    preparationMcpCheck('short signing secret is rejected',($service->handle(preparationMcpRpc('events/subscribe',$bad),1,$connection)['error']['code']??0)===-32602);
    foreach (['http://callback.example.com/a','https://127.0.0.1/a','https://169.254.169.254/a','https://user:pass@callback.example.com/a','https://callback.example.com:444/a','https://callback.example.com/a#fragment','https://localhost/a'] as $url) preparationMcpCheck('unsafe callback blocked '.parse_url($url,PHP_URL_HOST),$webhook->destination($url)===null);
    $resolveIps=['8.8.8.8','10.0.0.1'];preparationMcpCheck('mixed public and private DNS answer is rejected',$webhook->destination('https://callback.example.com/a')===null);$resolveIps=['8.8.8.8'];
    $challengeGood=false;$bad=$subscribe;$bad['delivery']['url']='https://callback.example.com/different';$failed=$service->handle(preparationMcpRpc('events/subscribe',$bad),1,$connection);
    preparationMcpCheck('wrong verification echo returns categorized callback error',($failed['error']['code']??0)===-32015 && ($failed['error']['data']['reason']??'')==='challenge_failed');$challengeGood=true;
    preparationMcpCheck('failed callback is never persisted',(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===1);

    preparationMcpCheck('active subscription gate checks current principal',$service->hasActiveSubscriptions());
    $before=count($requests);$first=$service->deliverPending();$event=$requests[count($requests)-1];
    preparationMcpCheck('pending batch is delivered exactly once',$first['accepted']===1 && count($requests)===$before+1);
    preparationMcpCheck('event contains only count and opaque batch hash',array_keys($event['decoded']['data'])===['project_key','pending_count','batch_hash'] && !str_contains($event['body'],'PRIVATE') && !str_contains($event['body'],$job['job_id']));
    $again=$service->deliverPending();preparationMcpCheck('acknowledged unchanged queue causes no repeated wakeup',$again['unchanged']===1 && count($requests)===$before+1);
    $jobs=[];$again=$service->deliverPending();preparationMcpCheck('saving a draft and shrinking queue does not create a feedback event',$again['unchanged']===1 && count($requests)===$before+1);
    $jobs=[$job];$jobs[0]['context_revision']=str_repeat('3',64);$statuses=[500];$retry=$service->deliverPending();$failedEvent=end($requests);
    preparationMcpCheck('changed source revision creates new bounded retry work',$retry['retried']===1 && $failedEvent['decoded']['eventId']!==$event['decoded']['eventId']);
    $count=count($requests);$service->deliverPending();preparationMcpCheck('backoff prevents premature retries',count($requests)===$count);
    $now+=60;$service=new ManagerPreparationMcpService($pdo,$store,$webhook,$clock,$active);$retried=$service->deliverPending();$resent=end($requests);
    preparationMcpCheck('retry survives restart with stable event ID and body',$retried['accepted']===1 && $resent['body']===$failedEvent['body'] && $resent['headers']['webhook-id']===$failedEvent['headers']['webhook-id']);
    preparationMcpCheck('retry signs a fresh timestamp',$resent['headers']['webhook-timestamp']!==$failedEvent['headers']['webhook-timestamp'] && $resent['headers']['webhook-signature']!==$failedEvent['headers']['webhook-signature']);
    $rotate=$subscribe;$rotate['delivery']['secret']=$nextSecret;$service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);
    $jobs[0]['context_revision']=str_repeat('4',64);$service->deliverPending();$rotated=end($requests);
    preparationMcpCheck('secret rotation signs both keys for a bounded window',count(explode(' ',$rotated['headers']['webhook-signature']))===2);
    $jobs[0]['context_revision']=str_repeat('7',64);$signature=hash('sha256',$jobs[0]['job_id'].':'.$jobs[0]['context_revision']);$count=count($requests);
    $pdo->beforeClaim=static function(PDO $db) use ($signature): void {
        $db->prepare('UPDATE manager_preparation_events SET observed_jobs_json=?')->execute([json_encode([$signature])]);
    };
    $race=$service->deliverPending();
    preparationMcpCheck('concurrent acknowledgement is reread after acquiring the lease',$race['unchanged']===1 && count($requests)===$count);
    $jobs[0]['context_revision']=str_repeat('8',64);
    $pdo->beforeClaim=static function(PDO $db) use (&$now): void {
        $db->prepare('UPDATE manager_preparation_events SET next_attempt_at=?')->execute([gmdate('Y-m-d H:i:s',$now+60)]);
    };
    $service->deliverPending();
    preparationMcpCheck('concurrent retry backoff cannot be bypassed by a stale inventory',count($requests)===$count);
    $pdo->exec('UPDATE manager_preparation_events SET next_attempt_at=NULL');
    $second=$rotate;$second['delivery']['url']='https://callback.example.com/second';
    $secondResult=$service->handle(preparationMcpRpc('events/subscribe',$second),4,$connection);
    $count=count($requests);$pendingAdvance=31;$slow=$service->deliverPending();
    preparationMcpCheck('expired delivery lease retries without revoking an authorized subscription',$slow['retried']===1 && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events WHERE is_active=1')->fetchColumn()===2);
    preparationMcpCheck('later subscriptions receive leases based on a fresh clock',$slow['accepted']===1 && count($requests)===$count+1);
    $pdo->prepare('DELETE FROM manager_preparation_events WHERE subscription_id=?')->execute([$secondResult['result']['id']]);
    $now+=60;
    $jobs[0]['context_revision']=str_repeat('9',64);
    $pdo->exec("UPDATE manager_preparation_events SET pending_event_id=NULL,pending_body=NULL,pending_jobs_json=NULL,attempts=0,next_attempt_at=NULL");
    $statuses=[500,500,500,500,500];$stopped=null;
    foreach ([0,60,120,240,480] as $advance) { $now+=$advance;$stopped=$service->deliverPending(); }
    preparationMcpCheck('transient delivery attempts are bounded at five',$stopped['stopped']===1 && (int)$pdo->query('SELECT attempts FROM manager_preparation_events')->fetchColumn()===5);
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);
    $statuses=[410];$stopped=$service->deliverPending();
    preparationMcpCheck('HTTP 410 stops retries and collection gate',$stopped['stopped']===1 && !$service->hasActiveSubscriptions());
    $count=count($requests);$now+=1000;$service->deliverPending();preparationMcpCheck('stopped subscription is not retried',count($requests)===$count);
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);$jobs[0]['context_revision']=str_repeat('6',64);
    $connectionValid=false;$count=count($requests);$callCount=count($calls);
    preparationMcpCheck('OAuth revocation closes active collection gate',!$service->hasActiveSubscriptions());$revoked=$service->deliverPending();
    preparationMcpCheck('OAuth revocation stops delivery before context or queue reads',$revoked['stopped']===1 && count($requests)===$count && count($calls)===$callCount);$connectionValid=true;
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);$pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');$count=count($requests);$revoked=$service->deliverPending();
    preparationMcpCheck('admin deactivation stops ongoing subscriptions',$revoked['stopped']===1 && count($requests)===$count);$pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);
    $unsubscribe=$rotate;unset($unsubscribe['delivery']['secret']);$resolveIps=['10.0.0.1'];
    $unsubscribed=$service->handle(preparationMcpRpc('events/unsubscribe',$unsubscribe),1,$connection);
    preparationMcpCheck('unsubscribe works after destination becomes unavailable',isset($unsubscribed['result']) && !$service->hasActiveSubscriptions());
    preparationMcpCheck('unsubscribe is idempotent',isset($service->handle(preparationMcpRpc('events/unsubscribe',$unsubscribe),1,$connection)['result']));$resolveIps=['8.8.8.8'];
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);$count=count($requests);$now+=86401;
    preparationMcpCheck('finite subscription expiry stops collection and delivery',!$service->hasActiveSubscriptions() && $service->deliverPending()['subscriptions']===0 && count($requests)===$count);
    for ($i=0;$i<20;++$i) {
        $bounded=$rotate;$bounded['delivery']['url']='https://callback.example.com/bounded/'.$i;
        $created=$service->handle(preparationMcpRpc('events/subscribe',$bounded),1,$connection);
        if (!isset($created['result']['id'])) throw new RuntimeException('FAIL bounded subscription fixture');
    }
    $bounded['delivery']['url']='https://callback.example.com/bounded/overflow';
    preparationMcpCheck('active subscription cap prevents starvation beyond one bounded delivery pass',($service->handle(preparationMcpRpc('events/subscribe',$bounded),1,$connection)['error']['code']??0)===-32602);
    echo 'Manager preparation MCP regression: '.$passed." checks passed\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
