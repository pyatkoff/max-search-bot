<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerHotelOfferReference.php';

/** Accepted repository data; these fixtures never manufacture an admission. */
function hotelCandidateAccepted(int $id): array
{
    $directory=dirname(__DIR__).'/knowledge/hotels';
    $catalogue=json_decode(file_get_contents($directory.'/catalogue.json'),true,16,JSON_THROW_ON_ERROR);
    foreach ($catalogue['shards'] as $shard) {
        $manifest=json_decode(file_get_contents($directory.'/'.$shard['file']),true,32,JSON_THROW_ON_ERROR);
        foreach ($manifest['bindings'] as $entry) if ($entry['accepted_binding']['hotel_ref']['id']===$id) {
            return [$entry,json_decode(file_get_contents($directory.'/'.$entry['bundle_file']),true,32,JSON_THROW_ON_ERROR)];
        }
    }
    throw new RuntimeException('Missing accepted fixture hotel '.$id);
}

function hotelCandidateArgs(): array
{
    return ['departure'=>'Москва','country'=>'Turkey','date_from'=>'2027-06-03','date_to'=>'2027-06-05',
        'nights_min'=>6,'nights_max'=>8,'adults'=>2,'child_ages'=>[9,4,4],'stars_min'=>4,'max_price'=>'200000.00'];
}

/** Synthetic stays for two real accepted properties, not real supplier quotes. */
function hotelCandidateSource(array $params,int $now): array
{
    $source=preparationCandidateSource($params,$now);
    foreach ([[417,'ALI BEY RESORT SORGUN','Turkey','Side'],[741,'DOUBLETREE BY HILTON ANTALYA KEMER','Турция','Kemer']] as $i=>$identity) {
        [$id,$name,$country,$region]=$identity;
        $hotel=&$source['data']['hotels'][$i];
        $hotel['anytourHotelId']=$id;
        $hotel['hotel']=array_replace($hotel['hotel'],['id'=>$id,'name'=>$name,'category'=>5,
            'country'=>['name'=>$country],'region'=>['name'=>$region]]);
        unset($hotel);
    }
    $source['data']['hotels'][0]['offers'][0]['listing']['tour']['room']['raw']='Operator family "A" / pool view';
    $second=&$source['data']['hotels'][0]['offers'][1]['listing']['tour'];
    $second['checkin']='2027-06-05';$second['nights']=8;$second['room']['raw']=null;
    unset($second);
    $other=&$source['data']['hotels'][1]['offers'][0]['listing']['tour'];
    $other['checkin']='2027-06-04';$other['nights']=7;$other['room']['raw']='Operator twin room';
    unset($other);
    return $source;
}

function hotelCandidateReader(int $now,array &$requests,?callable $change=null,string $country='Турция'): ManagerSearch3CandidateReader
{
    $directory=static fn(string $kind,string $name): array=>[
        'ID'=>$kind==='departure'?'101':'201','NAME'=>$kind==='departure'?'Москва':$country];
    $transport=static function(string $url,array $envelope) use($now,&$requests,$change): array {
        $source=hotelCandidateSource($envelope['params'],$now);
        if ($change!==null) $source=$change($source);
        $requests[]=['url'=>$url,'envelope'=>$envelope,'source'=>$source];
        return ['status'=>200,'type'=>'application/json','body'=>json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
    };
    return new ManagerSearch3CandidateReader($transport,$directory,static fn(): int=>$now);
}

function hotelCandidateWithoutReferences(array $result): array
{
    foreach ($result['candidates'] as &$candidate) unset($candidate['hotel_reference'],$candidate['hotel_reference_admission']);
    unset($candidate);
    return $result;
}

/** The established three-stay projection, independently specified in result order. */
function hotelCandidateExpected(string $scope,int $now): array
{
    $out=[];
    foreach ([[417,'ALI BEY RESORT SORGUN','Turkey','Side',1,'2027-06-03',6,'100000','Operator family "A" / pool view'],
        [741,'DOUBLETREE BY HILTON ANTALYA KEMER','Турция','Kemer',3,'2027-06-04',7,'110000','Operator twin room'],
        [417,'ALI BEY RESORT SORGUN','Turkey','Side',2,'2027-06-05',8,'120000',null]] as $stay) {
        [$id,$name,$country,$region,$offer,$checkin,$nights,$price,$room]=$stay;
        $out[]=['hotel'=>['anytour_hotel_id'=>$id,'name'=>$name,'category'=>5,'country'=>$country,'region'=>$region],
            'provider'=>'tourvisor','operator_raw'=>'Synthetic operator','offer_digest'=>hash('sha256','synthetic-'.$offer),
            'source_scope_digest'=>$scope,'checkin'=>$checkin,'nights'=>$nights,
            'party'=>['adults'=>2,'children'=>3,'child_ages'=>[4,4,9]],'room_raw'=>$room,'meal_raw'=>'Synthetic meal',
            'placement_raw'=>'Synthetic placement','price'=>['amount'=>$price,'currency'=>'RUB','basis'=>'whole_party',
                'source_listing_state'=>'final_ready_estimate','source_final_price_verified'=>false,
                'current_price_verified'=>false,'refresh_required'=>true],
            'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-300),'last_seen_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-120),
            'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600),'availability'=>'unverified','booking_enabled'=>false,'photos_read'=>false];
    }
    return $out;
}

function hotelCandidateReferenceChecks(): void
{
    $now=strtotime('2026-11-16T04:04:30Z');$requests=[];$args=hotelCandidateArgs();
    $reader=hotelCandidateReader($now,$requests);
    $read=$reader->read($args);$baseline=hotelCandidateWithoutReferences($read);
    $expected=hotelCandidateExpected($requests[0]['source']['data']['scopeDigest'],$now);
    preparationMcpCheck('family candidate enrichment preserves every established stay field, price, party and order',
        $read['ok']===true&&$baseline['candidates']===$expected&&$read['rejected_offer_count']===0
        &&$read['omitted_candidate_count']===0&&$read['truncated']===false&&count($requests)===1);
    foreach ([0=>417,1=>741] as $index=>$id) {
        [$entry,$bundle]=hotelCandidateAccepted($id);$reference=$read['candidates'][$index]['hotel_reference']??[];
        preparationMcpCheck('retained property '.$id.' uses its real accepted complete fact/source/limit group',
            ($reference['status']??null)==='available'&&$reference['hotel_ref']===['catalog'=>'anytour','id'=>$id]
            &&$reference['binding_revision']===$entry['binding_revision']&&$reference['facts']===$bundle['facts']
            &&$reference['sources']===$bundle['sources']&&$reference['record_hashes']===$bundle['record_hashes']
            &&$reference['material_limits']===$bundle['material_limits']&&$reference['coverage']===$bundle['coverage']);
        $provenance=$reference['selector_provenance'];
        preparationMcpCheck('retained property '.$id.' provenance binds the exact candidate and canonical directory country',
            $provenance===['kind'=>'retained_search3_candidate','request_scope_digest'=>$read['request']['scope_digest'],
                'source_scope_digest'=>$expected[$index]['source_scope_digest'],'provider'=>$expected[$index]['provider'],
                'offer_digest'=>$expected[$index]['offer_digest'],'candidate_sha256'=>ManagerHotelOfferReference::hash($expected[$index]),
                'source_generated_at'=>$read['source_generated_at'],'request_country'=>'Турция',
                'candidate_country'=>$expected[$index]['hotel']['country']]);
        preparationMcpCheck('retained property '.$id.' does not claim trip agreement, room equivalence or a verified current fare',
            $reference['current_trip_binding']===false&&$reference['customer_agreement_verified']===false
            &&$reference['current_price_verified']===false&&$reference['availability_verified']===false
            &&$reference['room_tariff_equivalence_verified']===false&&$reference['meal_plan_equivalence_verified']===false
            &&$reference['future_operation_verified']===false&&$reference['price_authority']==='none'
            &&$reference['coverage']['complete']===false&&$read['selection_authority']===false);
    }
    $first=$read['candidates'][0]['hotel_reference'];
    $thirdReference=ManagerHotelKnowledge::captureRetainedCandidate($expected[2],
        $read['request']['scope_digest'],$read['source_generated_at'],$read['request']['country'],$now);
    $overflow=$read;unset($overflow['candidates'][2]['hotel_reference_admission']);
    $overflow['candidates'][2]['hotel_reference']=$thirdReference;
    preparationMcpCheck('larger accepted groups preserve the third exact stay while omitting only its whole reference at the response budget',
        !isset($read['candidates'][2]['hotel_reference'])
        &&($read['candidates'][2]['hotel_reference_admission']??null)===['status'=>'omitted','reason'=>'response_budget']
        &&$baseline['candidates'][2]===$expected[2]&&$read['candidates'][2]['room_raw']===null
        &&$read['omitted_candidate_count']===0&&$read['truncated']===false
        &&($thirdReference['status']??null)==='available'&&$thirdReference['facts']===$first['facts']
        &&strlen(json_encode($read,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=32768
        &&strlen(json_encode($overflow,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>32768);

    // Keep both original stays of one hotel to prove distinct provenance and
    // the genuinely unknown room without relying on a third full reference fitting.
    $pairRequests=[];
    $pair=hotelCandidateReader($now,$pairRequests,static function(array $source): array {
        $source['data']['hotels']=[$source['data']['hotels'][0]];
        $source['data']['hotelCount']=1;$source['data']['offerCount']=2;$source['data']['storedOfferCount']=2;
        return $source;
    })->read($args+['limit'=>2]);
    $pairExpected=[$expected[0],$expected[2]];
    $pairFirst=$pair['candidates'][0]['hotel_reference']??[];
    $pairSecond=$pair['candidates'][1]['hotel_reference']??[];
    [$pairEntry,$pairBundle]=hotelCandidateAccepted(417);$pairWhole=true;
    foreach ([$pairFirst,$pairSecond] as $index=>$reference) {
        $pairWhole=$pairWhole&&($reference['status']??null)==='available'
            &&$reference['binding_revision']===$pairEntry['binding_revision']
            &&$reference['selector_provenance']['offer_digest']===$pairExpected[$index]['offer_digest']
            &&$reference['selector_provenance']['candidate_sha256']===ManagerHotelOfferReference::hash($pairExpected[$index]);
        foreach (['facts','sources','record_hashes','material_limits','coverage'] as $field) {
            $pairWhole=$pairWhole&&($reference[$field]??null)===$pairBundle[$field];
        }
    }
    preparationMcpCheck('two exact stays of one accepted hotel retain separate provenance and a genuinely null room',
        $pairWhole&&hotelCandidateWithoutReferences($pair)['candidates']===$pairExpected
        &&count($pairRequests)===1&&$pair['omitted_candidate_count']===0&&$pair['truncated']===false
        &&$pairFirst['selected_room']===$expected[0]['room_raw']
        &&array_key_exists('selected_room',$pairSecond)&&$pairSecond['selected_room']===null
        &&$pairFirst['selector_provenance']['offer_digest']!==$pairSecond['selector_provenance']['offer_digest']
        &&$pairFirst['selector_provenance']['candidate_sha256']!==$pairSecond['selector_provenance']['candidate_sha256']
        &&$pairFirst['knowledge_revision']!==$pairSecond['knowledge_revision']
        &&strlen(json_encode($pair,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=32768);
    $limited=$reader->read($args+['limit'=>1]);
    preparationMcpCheck('hotel knowledge neither widens candidate limit nor rewrites omission counts',
        hotelCandidateWithoutReferences($limited)['candidates']===[$expected[0]]
        &&$limited['omitted_candidate_count']===2&&$limited['truncated']===true);

    $provenance=$first['selector_provenance'];[$entry]=hotelCandidateAccepted(417);
    $native=['project_key'=>'anytour','source_identity'=>$entry['source_identity'],
        'selected_hotel'=>$entry['accepted_source_names'][0],'selected_room'=>'Synthetic operator category',
        'provenance'=>['message_id'=>1,'event_id'=>1,'publication_sha256'=>str_repeat('a',64),'source_sha256'=>str_repeat('b',64)]];
    $nativeReference=ManagerHotelKnowledge::capture($native,$now);
    $wrong=$native;$wrong['provenance']=$provenance;
    preparationMcpCheck('new retained provenance cannot enter the old native or constructor selector contracts',
        ManagerHotelKnowledge::validProvenance($provenance)===false
        &&(ManagerHotelKnowledge::capture($wrong,$now)['reason']??null)==='selector_unavailable'
        &&(ManagerHotelOfferReference::selector([],$provenance)['reason']??null)==='selector_provenance_unavailable'
        &&$nativeReference['status']==='available'&&$nativeReference['facts']===$first['facts']
        &&$nativeReference['selected_room']==='Synthetic operator category'
        &&!array_key_exists('kind',$nativeReference['selector_provenance']));

    foreach (['different name'=>[417,'DOUBLETREE BY HILTON ANTALYA KEMER','Turkey','Турция'],
        'candidate country conflict'=>[417,'ALI BEY RESORT SORGUN','Egypt','Турция'],
        'request country conflict'=>[417,'ALI BEY RESORT SORGUN','Turkey','Египет'],
        'unknown request country'=>[417,'ALI BEY RESORT SORGUN','Turkey','Unresolved country'],
        'unknown candidate country'=>[417,'ALI BEY RESORT SORGUN','Türkiye','Турция'],
        'unaccepted property'=>[2900,'CRYSTAL BOUTIQUE COMFORT COLLECTION ADULTS ONLY 16+','Turkey','Турция'],
        'nonexistent property'=>[99999999,'ALI BEY RESORT SORGUN','Turkey','Турция'],
        'real local TV namespace collision'=>[466,'STELLA MAKADI BEACH RESORT & SPA','Egypt','Египет']] as $label=>$case) {
        [$id,$name,$country,$requestCountry]=$case;$calls=[];
        $change=static function(array $source) use($id,$name,$country): array {
            $hotel=&$source['data']['hotels'][0];$hotel['anytourHotelId']=$id;
            $hotel['hotel']['id']=$id;$hotel['hotel']['name']=$name;$hotel['hotel']['country']['name']=$country;
            return $source;
        };
        $out=hotelCandidateReader($now,$calls,$change,$requestCountry)->read($args+['limit'=>1]);
        $candidate=$out['candidates'][0]??[];$held=$candidate['hotel_reference']??[];
        preparationMcpCheck('candidate reference fails closed without removing the observed stay: '.$label,
            $out['ok']===true&&count($out['candidates'])===1&&$candidate['hotel']['anytour_hotel_id']===$id
            &&$candidate['price']===$expected[0]['price']&&$candidate['party']===$expected[0]['party']
            &&($held['status']??null)==='held'&&$held['facts']===[]&&$held['sources']===[]);
    }
    $calls=[];
    $blue=hotelCandidateReader($now,$calls,static function(array $source): array {
        $hotel=&$source['data']['hotels'][0];$hotel['anytourHotelId']=466;$hotel['hotel']['id']=466;$hotel['hotel']['name']='BLUE FISH';
        return $source;
    })->read($args+['limit'=>1]);
    preparationMcpCheck('typed AnyTour 466 resolves only BLUE FISH rather than local TV 466 Stella Makadi',
        ($blue['candidates'][0]['hotel_reference']['status']??null)==='available'
        &&$blue['candidates'][0]['hotel_reference']['hotel_ref']===['catalog'=>'anytour','id'=>466]);
    $calls=[];
    $egypt=hotelCandidateReader($now,$calls,static function(array $source): array {
        $hotel=&$source['data']['hotels'][0];$hotel['anytourHotelId']=211;$hotel['hotel']['id']=211;
        $hotel['hotel']['name']='STELLA MAKADI BEACH RESORT & SPA';$hotel['hotel']['country']['name']='Египет';
        return $source;
    },'Egypt')->read(array_replace($args,['country'=>'Египет','limit'=>1]));
    preparationMcpCheck('Egypt names resolve the separate accepted AnyTour 211 identity through the canonical directory name',
        ($egypt['candidates'][0]['hotel_reference']['status']??null)==='available'
        &&$egypt['candidates'][0]['hotel_reference']['hotel_ref']===['catalog'=>'anytour','id'=>211]
        &&$egypt['candidates'][0]['hotel_reference']['selector_provenance']['request_country']==='Egypt');

    foreach ([null,'Turkey','Турция'] as $country) {
        $calls=[];$room=str_repeat('я',400);
        $out=hotelCandidateReader($now,$calls,static function(array $source) use($country,$room): array {
            $source['data']['hotels'][0]['hotel']['country']['name']=$country;
            $source['data']['hotels'][0]['offers'][0]['listing']['tour']['room']['raw']=$room;
            return $source;
        },'Turkey')->read($args+['limit'=>1]);
        $reference=$out['candidates'][0]['hotel_reference']??[];
        preparationMcpCheck('missing or explicit supported country preserves the exact 800-byte UTF-8 operator room '.json_encode($country),
            ($reference['status']??null)==='available'&&$reference['selected_room']===$room
            &&$reference['selector_provenance']['candidate_country']===$country
            &&$reference['selector_provenance']['request_country']==='Turkey');
    }
    $differentScope=hash('sha256','compatible-source-scope');$calls=[];
    $compatible=hotelCandidateReader($now,$calls,static function(array $source) use($differentScope): array {
        $source['data']['sourceScopeDigests'][]=$differentScope;
        $source['data']['hotels'][0]['offers'][0]['sourceScopeDigest']=$differentScope;
        $source['data']['matchMode']='compatible';$source['data']['partial']=true;
        return $source;
    })->read($args+['limit'=>1]);
    $p=$compatible['candidates'][0]['hotel_reference']['selector_provenance']??[];
    preparationMcpCheck('compatible retained source binds its own scope separately from the explicit family request',
        ($p['source_scope_digest']??null)===$differentScope&&$p['request_scope_digest']===$read['request']['scope_digest']
        &&$p['source_scope_digest']!==$p['request_scope_digest']&&$compatible['source_partial']===true);
    preparationMcpCheck('candidate enrichment exports no discarded private source fields or fabricated inquiry IDs',
        !str_contains(json_encode($read),'PRIVATE')&&!str_contains(json_encode($read),'private.invalid')
        &&!str_contains(json_encode($read),'message_id')&&!str_contains(json_encode($read),'event_id'));
    hotelCandidateBudgetChecks($now);
}

function hotelCandidateBudgetSource(array $source): array
{
    $base=$source['data']['hotels'][0];$other=$source['data']['hotels'][1];$hotels=[];
    for ($i=0;$i<12;++$i) {
        $hotel=$i===1?$other:$base;
        if ($i>1) {$hotel['anytourHotelId']=990000+$i;$hotel['hotel']['id']=990000+$i;$hotel['hotel']['name']='Unaccepted budget fixture '.$i;}
        $hotel['offers']=[$hotel['offers'][0]];$offer=&$hotel['offers'][0];
        $offer['listing']['identity']['offer_ref_digest']=hash('sha256','hotel-budget-'.$i);
        $offer['price']=$offer['listing']['listingPrice']=(string)(100000+$i);
        foreach (['room','meal','placement'] as $field) $offer['listing']['tour'][$field]['raw']=str_repeat('"/\\',200);
        $hotels[]=$hotel;unset($offer);
    }
    $source['data']['hotels']=$hotels;$source['data']['hotelCount']=12;$source['data']['offerCount']=12;
    $source['data']['storedOfferCount']=12;return $source;
}

function hotelCandidateBudgetChecks(int $now): void
{
    $smallRequests=[];
    $small=hotelCandidateReader($now,$smallRequests,'hotelCandidateBudgetSource')->read(hotelCandidateArgs()+['limit'=>3]);
    $plain=hotelCandidateWithoutReferences($small);$first=$small['candidates'][0];
    $complete=ManagerHotelKnowledge::captureRetainedCandidate($plain['candidates'][0],
        $small['request']['scope_digest'],$small['source_generated_at'],$small['request']['country'],$now);
    preparationMcpCheck('escaped response admits one entire accepted reference and omits the next without losing its stay',
        ($first['hotel_reference']['status']??null)==='available'&&$first['hotel_reference']===$complete
        &&!isset($small['candidates'][1]['hotel_reference'])
        &&$small['candidates'][1]['hotel_reference_admission']===['status'=>'omitted','reason'=>'response_budget']
        &&array_column(array_column($small['candidates'],'hotel'),'anytour_hotel_id')===[417,741,990002]
        &&$small['omitted_candidate_count']===9&&strlen(json_encode($small,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=32768);
    $requests=[];$args=hotelCandidateArgs()+['limit'=>12];
    $read=hotelCandidateReader($now,$requests,'hotelCandidateBudgetSource')->read($args);
    $baseline=hotelCandidateWithoutReferences($read);$present=0;$omitted=0;
    foreach ($read['candidates'] as $index=>$candidate) {
        if (isset($candidate['hotel_reference'])) {
            ++$present;
            $whole=ManagerHotelKnowledge::captureRetainedCandidate($baseline['candidates'][$index],
                $read['request']['scope_digest'],$read['source_generated_at'],$read['request']['country'],$now);
            preparationMcpCheck('budget reference '.$index.' remains one complete canonical group',
                $candidate['hotel_reference']===$whole&&!isset($candidate['hotel_reference_admission']));
        } else {
            ++$omitted;
            preparationMcpCheck('budget reference '.$index.' is explicitly omitted without partial facts',
                ($candidate['hotel_reference_admission']??null)===['status'=>'omitted','reason'=>'response_budget']);
        }
    }
    preparationMcpCheck('escaped-string 32KiB budget retains the preexisting candidate count and order',
        strlen(json_encode($read,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=32768
        &&array_column(array_column($read['candidates'],'hotel'),'anytour_hotel_id')===[417,741,990002,990003,990004,990005]
        &&array_column(array_column($read['candidates'],'price'),'amount')===['100000','100001','100002','100003','100004','100005']
        &&$read['omitted_candidate_count']===6&&$read['truncated']===true&&$present>0&&$omitted>0
        &&array_unique(array_column($read['candidates'],'room_raw'))===[str_repeat('"/\\',200)]);

    $tightRequests=[];
    $tight=hotelCandidateReader($now,$tightRequests,static function(array $source): array {
        $source=hotelCandidateBudgetSource($source);
        for ($i=0;$i<3;++$i) $source['data']['hotels'][$i]['offers'][0]['listing']['operator']['raw']=str_repeat('"',400);
        for ($i=0;$i<2;++$i) $source['data']['hotels'][$i]['hotel']['region']['name']=str_repeat('/',400);
        return $source;
    })->read($args);
    $absent=0;
    foreach ($tight['candidates'] as $i=>$candidate) {
        if (isset($candidate['hotel_reference'])||isset($candidate['hotel_reference_admission'])) continue;
        ++$absent;$probe=$tight;
        $probe['candidates'][$i]['hotel_reference_admission']=['status'=>'omitted','reason'=>'response_budget'];
        preparationMcpCheck('full output keeps candidate '.$i.' when even the optional omission marker cannot fit',
            strlen(json_encode($probe,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>32768
            &&$candidate['room_raw']===str_repeat('"/\\',200)&&$candidate['party']===['adults'=>2,'children'=>3,'child_ages'=>[4,4,9]]);
    }
    preparationMcpCheck('a near-full escaped response preserves all six base candidates and leaves absent evidence unknown',
        $absent>0&&strlen(json_encode($tight,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=32768
        &&array_column(array_column($tight['candidates'],'hotel'),'anytour_hotel_id')===[417,741,990002,990003,990004,990005]
        &&array_column(array_column($tight['candidates'],'price'),'amount')===['100000','100001','100002','100003','100004','100005']
        &&$tight['omitted_candidate_count']===6&&$tight['truncated']===true);
}

/** Actual SQLite OAuth grant lookups and MCP disclosure checks, not a connection stub. */
function hotelCandidateReferenceMcpChecks(): void
{
    $now=strtotime('2026-11-16T04:04:30Z');$args=hotelCandidateArgs()+['limit'=>2];
    $rpc=preparationMcpRpc('tools/call',['name'=>ManagerSearch3CandidateReader::TOOL,'arguments'=>$args]);
    foreach ([null,'admin','project','oauth','current_project'] as $revoke) {
        $pdo=preparationMcpFixture();$connection=str_repeat('c',32);$client=str_repeat('d',48);
        $pdo->exec('CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);');
        $pdo->prepare('INSERT INTO manager_preparation_oauth_clients VALUES(?,?,?,?)')
            ->execute([$client,ManagerPreparationOAuth::REDIRECT_URI,'synthetic-candidate-fixture',$now]);
        $pdo->prepare('INSERT INTO manager_preparation_oauth_grants VALUES(?,?,?,?,?,?,?,?,NULL)')
            ->execute([$connection,$client,1,'anytour',ManagerPreparationOAuth::RESOURCE,implode(' ',ManagerPreparationOAuth::SCOPES),$now,$now+3600]);
        $requests=[];$storeCalls=0;$grantReads=0;
        $pdo->beforeRead=static function(PDO $unused,string $sql) use(&$grantReads): void {if (str_contains($sql,'SELECT g.*')) ++$grantReads;};
        $reader=hotelCandidateReader($now,$requests,static function(array $source) use($pdo,$connection,$now,$revoke): array {
            if ($revoke==='admin') $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
            elseif ($revoke==='project') $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
            elseif ($revoke==='oauth') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$connection]);
            elseif ($revoke==='current_project') ProjectConfig::resetForTests(['id'=>'other']);
            return $source;
        });
        $service=new ManagerPreparationMcpService($pdo,static function() use(&$storeCalls): array {++$storeCalls;return [];},
            null,static fn(): int=>$now,null,null,$reader);
        $before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
        $response=$service->handle($rpc,1,$connection,ManagerPreparationOAuth::SCOPES);
        $pdo->beforeRead=null;
        if ($revoke===null) {
            $data=$response['result']['structuredContent']??[];
            $wireText=json_decode($response['result']['content'][0]['text']??'null',true,48,JSON_THROW_ON_ERROR);
            preparationMcpCheck('actual authorized MCP exposes both accepted family candidate references without business writes',
                ($data['ok']??false)===true&&count($data['candidates'])===2&&$wireText===$data
                &&array_column(array_column($data['candidates'],'hotel_reference'),'status')===['available','available']
                &&array_column(array_column($data['candidates'],'hotel_reference'),'hotel_ref')===[['catalog'=>'anytour','id'=>417],['catalog'=>'anytour','id'=>741]]
                &&count($requests)===1&&$grantReads>1&&$storeCalls===0
                &&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before);
        } else {
            preparationMcpCheck('actual MCP suppresses accepted hotel facts after source-read revocation: '.$revoke,
                ($response['error']['code']??null)===-32001&&!isset($response['result'])
                &&count($requests)===1&&$storeCalls===0&&!str_contains(json_encode($response),'hotel_reference')
                &&!str_contains(json_encode($response),'ALI BEY')&&!str_contains(json_encode($response),'DOUBLETREE'));
        }
        ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
    }
}
