<?php
declare(strict_types=1);
/* One fixed 28-receipt metadata read; application snapshot precedes catalogue.
 * No DDL, record/history bodies, provider/tick/cron call or server file write. */
const HOTEL_EXPECTED_INSTALLED_SHA='08f821759be6f63c324d5419c3104551313c1e79';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_STATUS_KIND='hotel-server-private-incident-v1';
const HOTEL_OPERATION='86e350b2136ddd050a254da79b1c0b57ebc57a1990609ec1b308ccbb93d5a21c';
const HOTEL_SOURCE_PINS_JSON='{"migrations/hotel/084_hotel_server_projection.sql":"6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec","services/ConversationDb.php":"8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1","services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"}';
const HOTEL_OBSERVATION_IDENTITY_JSON='{"approved_source_head":"853b8c56ca819fc96d8ac4804facb92885cfbb9a","database_writes":0,"expected_application_database_identity_sha256":"ea32f9490874a6b4429a7bec1e3c562717b97f009e84df2c7f8ee8f14d0b3653","expected_catalog_database_identity_sha256":"a0eeb9b531321e9854c472e91a4ff4b7191533b8037c707fadc5251d964b1491","expected_ops_parent":"4be9516b0871bee9aa337a1120178f3f20533acb","expected_receipts_sha256":"90750a96d50cc567874b55f8cc5868b42fb711bbe057efdb1f9df562eb1b28fd","installed_sha":"08f821759be6f63c324d5419c3104551313c1e79","maximum_fixed_receipts":28,"maximum_metadata_queries":6,"maximum_read_only_snapshots":2,"observation_kind":"accepted_catalog_delivery","original_installed_deploy_run_id":"41851487224","original_prerequisite_operation_id":"a0c4c35e2ea61442583cb7b4049a53e0718d6461a077d9b31bb50db0e51bb0cc","original_prerequisite_plaintext_sha256":"8423600d62dbadfcfd3636cac2e6332cb39ecc55a0b23a5cca84f196983e3a65","original_prerequisite_run_id":"41847768588","original_schema_run_id":"41850853997","provider_calls":0,"schema":"hotel-server-private-incident-v1","server_file_writes":0,"source_pins":{"migrations/hotel/084_hotel_server_projection.sql":"6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec","services/ConversationDb.php":"8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1","services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"},"source_ref":"https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6607905718"}';
const HOTEL_EXPECTED_RECEIPTS_JSON='[{"campaign_id":"HOTEL-SERVER-20261102","payload_bytes":1221,"payload_sha256":"699b732beff1ad3d95e4d207aff365f7ac4f15da7beb36f02dd378d85eaed632","receipt_sha256":"b6a79520674b36e66847971fed1cef14906b156de4369c91e4f02072838181d4","stage_index":0},{"campaign_id":"HOTEL-SERVER-20261102","payload_bytes":1260,"payload_sha256":"d0b84646c1d6bac6cd2f8ac503391e36b0385bd597099e51122520f1aaeb67ac","receipt_sha256":"917b637d03d2e43b135c909594dc68d5883b18827f535d7dae33419734e134fc","stage_index":1},{"campaign_id":"HOTEL-SERVER-20261102","payload_bytes":1154,"payload_sha256":"e04f2bb3b02d1cfa8ea0f02cbdb1ccdc09804c7e062008fb90bfdfa9bf9529ef","receipt_sha256":"42eb91f1889de744bc6e9c192232da927f2b846712c666d4292350bc9baf6ec3","stage_index":2},{"campaign_id":"HOTEL-SERVER-20261102","payload_bytes":1164,"payload_sha256":"dc44b5184351adb7469136821b84f0334c99ca6acabfa0e4e1360625fc861270","receipt_sha256":"4d8c4341cadbb899bb8695d816ccf252de5afd1f07188e8d70a198518976fc1c","stage_index":3},{"campaign_id":"HOTEL-SERVER-20261102-B02","payload_bytes":3562,"payload_sha256":"b3d743e7e310b29b4da07f352d412f76bfcfe0d444065253938ed7cc3b9806a6","receipt_sha256":"733f5e400cd77a5bcfc7ee1bf0cf729c16e912de2c45a05504b6c763189ce778","stage_index":0},{"campaign_id":"HOTEL-SERVER-20261102-B02","payload_bytes":2949,"payload_sha256":"b52c486eebcae7ab17205f3ea46e38acd49f0e0b80c50a4db94becfb8ef7bf06","receipt_sha256":"2544430affcc931ed02fbb04d8ba90d193b14ed43268b9cc63699d1b67a9ab7c","stage_index":1},{"campaign_id":"HOTEL-SERVER-20261102-B02","payload_bytes":3364,"payload_sha256":"0eea25bab608b98b6def309a625c63501b5e90bec3e5d9e4fbdf828fa91dad7c","receipt_sha256":"e527ab6b2ba83210b34a216b46d6ba2b6d1c8ee04961a151f5b0266777c75957","stage_index":2},{"campaign_id":"HOTEL-SERVER-20261102-B02","payload_bytes":3198,"payload_sha256":"3dae393f803ad39f5e576d0774ef24dd13f097185adc37811f009e8cd9ce1b3d","receipt_sha256":"b05febe2dae126381c0e8b9cbca64be67cedb86db65bffbea9dcbd841310fb41","stage_index":3},{"campaign_id":"HOTEL-SERVER-20261103-B03","payload_bytes":3270,"payload_sha256":"60b130ec7d4e02db2b481ca612471efa2f7cb8cf56d8bf03a490da5488984b99","receipt_sha256":"cbcbcdb7459533e4637e9020424443769453af292c73e3b5c696ab0317743a9f","stage_index":0},{"campaign_id":"HOTEL-SERVER-20261103-B03","payload_bytes":3270,"payload_sha256":"0521f9f9b760b0f7bc8f0096c6f20a42a5b3d31a4526bb2eec9b7d9d573c0ce8","receipt_sha256":"074abd83decd4c4a7db1f112ad951c2c89c3c4d814c0c80b7fa5359846da7b8f","stage_index":1},{"campaign_id":"HOTEL-SERVER-20261103-B03","payload_bytes":3366,"payload_sha256":"a5d6b3b2d4dc5509ecef5dab5f46fecd33bf79d6b5ac23226c047ccdb282f3a8","receipt_sha256":"001bb0940e674c93261436ad162f76755a9fcfbc91109d71d956b30716417115","stage_index":2},{"campaign_id":"HOTEL-SERVER-20261103-B03","payload_bytes":3125,"payload_sha256":"6d1009618049d442e252da32adcc313cef2fa72c187333a35d0be2bd622e56bb","receipt_sha256":"1583eae3d59b61ce54f2ea92ba484fd9abb421d569097981d71bca7c4d482a04","stage_index":3},{"campaign_id":"HOTEL-SERVER-20261103-B04","payload_bytes":2943,"payload_sha256":"2e4ac25c082530b97dd7f3809616131f59e7789ffc2ae9c6c663390542dcd826","receipt_sha256":"e9db9e67850683e754c7499c10aabc5446994723aa679dd9f799a4e06ea0a3cf","stage_index":0},{"campaign_id":"HOTEL-SERVER-20261103-B04","payload_bytes":2646,"payload_sha256":"093964758098e310e7eb340d6a5eea77db02ebfbdb06bacab12fa9e219b1eb26","receipt_sha256":"fe81b6c46210d9014109b7174213957fada0d88284ccfc3423b8209e0355dee6","stage_index":1},{"campaign_id":"HOTEL-SERVER-20261103-B04","payload_bytes":2543,"payload_sha256":"0b9f730bc936af5fa91d57149d8e1bd2a7da7273f04d171fe0cb07093b0a8d58","receipt_sha256":"63b4b350791cba9341551e114003af41122290c1516e55dcbf10ba1a24395c2d","stage_index":2},{"campaign_id":"HOTEL-SERVER-20261103-B04","payload_bytes":2414,"payload_sha256":"5aafb68f34e0d0e0bc4aa319d538938a3fabf1b004c8433c26ad52a60a9bd065","receipt_sha256":"522478fba34945678d72a2967bd7f2c4fe91fd3ee8e32a99eeacfb371a1dc3c8","stage_index":3},{"campaign_id":"HOTEL-SERVER-20261103-B05","payload_bytes":2780,"payload_sha256":"fc587671a4441a6159127af31420035451f917337232be63d8993f0137aa0e7b","receipt_sha256":"e0f196e8f68575f1b3d085890a3f8c2b45161cbbb3f8c52f0a4438e60bee4223","stage_index":0},{"campaign_id":"HOTEL-SERVER-20261103-B05","payload_bytes":2729,"payload_sha256":"d9b8eb8163a4a2ba2980b75c44d6e407efc508adb7d2b89578d7fea832857ca4","receipt_sha256":"bf51fa0b2839dfbde1e6ceb2bd6fd0680f360dedb67a049d5d03bac6c93a34fe","stage_index":1},{"campaign_id":"HOTEL-SERVER-20261103-B05","payload_bytes":2718,"payload_sha256":"d628e3a8407872270e4151ff4e5fdf2c6789067a28510551f395220ce82f6c88","receipt_sha256":"ad75399f2d6611fd1fdd1c17b8ad2dffa44eb6427363472232eb2c2e98814a4f","stage_index":2},{"campaign_id":"HOTEL-SERVER-20261103-B05","payload_bytes":2868,"payload_sha256":"27a1eec642009a593ed2b5e90fcdc73723a9663902ba8546cfe785fe4858b6af","receipt_sha256":"f10739653b095eae8a76a9420671bb6f57eb5d28125d68ff19190cf0d03b6900","stage_index":3},{"campaign_id":"HOTEL-SERVER-20261103-B06","payload_bytes":2760,"payload_sha256":"e6fb75351e1859aec18e3889cc579d94349f2739bf781aa0f0604b1eb617e87a","receipt_sha256":"ac2e79e0a35758ca7231d8b4a8e4a94f4df8cb46b3c7ff128ed681cf0e562d54","stage_index":0},{"campaign_id":"HOTEL-SERVER-20261103-B06","payload_bytes":3062,"payload_sha256":"e75e815887a94b715fe20fddb4637f3e9d577a557c126fdae553143d274b375c","receipt_sha256":"f907b5a156444ed22e22883f0c7d87807d1dfaf807fa21dfeab2b3a546a149a8","stage_index":1},{"campaign_id":"HOTEL-SERVER-20261103-B06","payload_bytes":2715,"payload_sha256":"295b40683915b65a50d5b6127b61cc0e280fee00e37aee21ce8598b786a18228","receipt_sha256":"a896803168052c5d3f889837d3ebe8cb6e7f24ca88229a259f6e28eb5cc20107","stage_index":2},{"campaign_id":"HOTEL-SERVER-20261103-B06","payload_bytes":2528,"payload_sha256":"b16b09dd471f8b18d15ac11eff3229eed95912de9e8aca038b5c168bb90431d7","receipt_sha256":"75a20552efbbf94f3d112eaf78e722b347f53ad007756c550e5d2b85bf68595d","stage_index":3},{"campaign_id":"HOTEL-SERVER-20261103-B07","payload_bytes":2749,"payload_sha256":"afbec54201fc1c608bb8454f3fa86dfa95bd3e294b9187e16e62263fdabcebb5","receipt_sha256":"42214e4f7037a0b2e789a09c4e2ee887bfe554a5981c22b2a425114121b478d5","stage_index":0},{"campaign_id":"HOTEL-SERVER-20261103-B07","payload_bytes":2673,"payload_sha256":"041e7e1ca7098bd4a71ea118ef93f6371e8626a66a1cc607d86d211ce1e22a70","receipt_sha256":"bf7a381d10701a943fe989cb2d9eb1404be5422f80fa0bb9b52ef08775d1f492","stage_index":1},{"campaign_id":"HOTEL-SERVER-20261103-B07","payload_bytes":2826,"payload_sha256":"e87059f740ffc5df8803d870a22b4c8d3df3587b40ccaa13245cb88c4c52ac6d","receipt_sha256":"d1cda2669283d2850bb878f73e9de97fa5fc478d28cbe4df433c62b48d4139ac","stage_index":2},{"campaign_id":"HOTEL-SERVER-20261103-B07","payload_bytes":2725,"payload_sha256":"82f4b2ee36dfe4240a2308ed0dc0e4debb352b091e85f0a9dd661c9d7068bd0b","receipt_sha256":"0599f9f0fbd8dc6fa59d2e54864e9ac9818c874bb8824df39423d45724da6f2e","stage_index":3}]';
const HOTEL_EXPECTED_RECEIPTS_SHA256='90750a96d50cc567874b55f8cc5868b42fb711bbe057efdb1f9df562eb1b28fd';
const HOTEL_EXPECTED_APPLICATION_DB='ea32f9490874a6b4429a7bec1e3c562717b97f009e84df2c7f8ee8f14d0b3653';
const HOTEL_EXPECTED_CATALOG_DB='a0eeb9b531321e9854c472e91a4ff4b7191533b8037c707fadc5251d964b1491';
const HOTEL_B07_CAMPAIGN='HOTEL-SERVER-20261103-B07';
const HOTEL_PLAIN_LIMIT=524288;
const HOTEL_WIRE_LIMIT=786432;

function hotelFail(string $reason): never { throw new DomainException($reason); }

function hotelJson(string $raw): array {
    $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($v))hotelFail('private_shape_unconfirmed');return $v;
}

function hotelHash(mixed $value): string {
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))hotelFail('saved_hash_unconfirmed');
    return $value;
}

function hotelInt(mixed $value,int $maximum=PHP_INT_MAX): int {
    if(is_int($value))$text=(string)$value;elseif(is_string($value))$text=$value;else hotelFail('saved_integer_unconfirmed');
    if(!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text)||strlen($text)>strlen((string)$maximum)
        ||(strlen($text)===strlen((string)$maximum)&&strcmp($text,(string)$maximum)>0))hotelFail('saved_integer_unconfirmed');
    return (int)$text;
}

function hotelInstalled(string $app): string {
    $out=[];$code=1;exec('git -C '.escapeshellarg($app).' rev-parse HEAD 2>/dev/null',$out,$code);
    $sha=trim(implode('',$out));
    if($code!==0||!preg_match('/\A[a-f0-9]{40}\z/D',$sha)||!hash_equals(HOTEL_EXPECTED_INSTALLED_SHA,$sha))
        hotelFail('installed_release_unconfirmed');
    return $sha;
}

function hotelSeal(array $payload): array {
    $recipient=<<<'PUBLIC_RECEIPT_RECIPIENT'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAuN8W3YQpfXZak/D2Whmc
58DfcWVrLJRumXPFLQjaHhcueBq9zWnFf6jp8/x9IIZ/CZDz/vxvdh7PmSAW/Faa
ciVa2HDRJmxO3ZteBVcnVWatO9j1GrbvAZc/OCYxflqBwueetSikhlqFdeqKDok4
gKE81+uPhl28Um8leV4+ouLmM9Pic4gDSqL87uMyKSe1O5lDmUbvJm3Qyfs+ZfOn
PBuEsUuvMUkVZ7c6FrhY74pEzyT6Mx6XdnnVdyZ+ksGcmCAroAQoPtia7ebv17Xi
pJjgwEokntbAAlvqwrbH8X7X+MpeI84EXAh6kJOPxCj8l/Oxb1xdZR/wC0DorQkj
R7yipD6y0nyjWWHdwucd4Lty0H332crtnxktGYIfymRXprZb/creHaGOw39gIvGV
T8YNFRu1JmL3tbjxnO4ekvJfvOzwErVxLahHT0RJCcZ43a7zga+9oMaFLII9RqGd
LPAX0T14+63yqvazaMLpk7UOTjSedaIGCxEfuviVR4pzAgMBAAE=
-----END PUBLIC KEY-----
PUBLIC_RECEIPT_RECIPIENT;
    $recipient.="\n";
    if(!hash_equals(HOTEL_RECEIPT_RECIPIENT_SHA,hash('sha256',$recipient)))hotelFail('receipt_recipient_unconfirmed');
    $aad=HOTEL_STATUS_KIND.'|'.HOTEL_EXPECTED_INSTALLED_SHA.'|'.HOTEL_OPERATION;
    $plain=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($plain)>HOTEL_PLAIN_LIMIT)hotelFail('receipt_bounds_unconfirmed');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||strlen($tag)!==16||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING))hotelFail('sealing_unavailable');
    unset($key,$plain);
    $envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'sealed','operator_provider_calls'=>0,
        'algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>HOTEL_RECEIPT_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
    if(strlen(json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))+1>HOTEL_WIRE_LIMIT)hotelFail('wire_receipt_bounds_unconfirmed');
    return $envelope;
}

function hotelCanonical(mixed $value): string {
    $sort=static function(mixed $v)use(&$sort):mixed{
        if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $k=>$item)$v[$k]=$sort($item);return $v;
    };
    return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function deliverySources(string $app): void {
    hotelInstalled($app);
    foreach(hotelJson(HOTEL_SOURCE_PINS_JSON) as $path=>$sha){
        $file=$app.'/'.$path;clearstatcache(true,$file);
        if(is_link($file)||realpath($file)!==$file||!is_file($file)||filesize($file)>131072)hotelFail('canonical_source_unconfirmed');
        $raw=file_get_contents($file,false,null,0,131073);
        if(!is_string($raw)||strlen($raw)>131072||hash('sha256',$raw)!==$sha)hotelFail('canonical_source_unconfirmed');
    }
}

function deliveryRows(PDO $pdo,string $sql,array $args,int $cap): array {
    global $report;
    if(!str_starts_with($sql,'SELECT ')||str_contains($sql,'FOR UPDATE')||$cap<1||$cap>29)hotelFail('catalog_delivery_read_scope');
    if(++$report['query_count']>6)hotelFail('catalog_delivery_query_bound');
    $s=$pdo->prepare($sql);$s->execute($args);$rows=[];
    try{while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){
        if(count($rows)>=$cap||strlen(json_encode($row,JSON_THROW_ON_ERROR))>4096)hotelFail('catalog_delivery_read_bound');
        $rows[]=$row;
    }}finally{$s->closeCursor();}return $rows;
}

function deliveryBegin(PDO $pdo,string $name): void {
    global $connections,$report;
    if(isset($connections[$name])||count($connections)>=2||$pdo->inTransaction()
        ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
        ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))hotelFail('catalog_database_context_unconfirmed');
    $connections[$name]=$pdo;
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $report['snapshots'][$name]=['started'=>true,'rolled_back'=>false,'started_at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
    $report['snapshot_count']++;
}

function deliveryEnd(PDO $pdo,string $name): void {
    global $report;
    if(!$pdo->inTransaction())hotelFail('catalog_read_only_snapshot_lost');
    $pdo->rollBack();$report['snapshots'][$name]['rolled_back']=true;
}

function deliveryDatabaseIdentity(PDO $pdo,string $expected): string {
    $rows=deliveryRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ DATABASE() AS db,@@hostname AS host,@@port AS port',[],1);
    if(count($rows)!==1||!is_string($rows[0]['db'])||$rows[0]['db']===''
        ||!is_string($rows[0]['host'])||$rows[0]['host']==='')hotelFail('catalog_database_identity_unconfirmed');
    $digest=hash('sha256',hotelCanonical(['database'=>$rows[0]['db'],'host'=>$rows[0]['host'],'port'=>hotelInt($rows[0]['port'],65535)]));
    if(!hash_equals($expected,$digest))hotelFail('catalog_database_identity_changed');
    return $digest;
}

function deliveryExpected(): array {
    $rows=hotelJson(HOTEL_EXPECTED_RECEIPTS_JSON);
    if(!array_is_list($rows)||count($rows)!==28||hash('sha256',hotelCanonical($rows))!==HOTEL_EXPECTED_RECEIPTS_SHA256)
        hotelFail('fixed_receipt_set_unconfirmed');
    $expected=[];
    foreach($rows as $row){
        if(!is_array($row)||count($row)!==5||!is_string($row['campaign_id']??null)
            ||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$row['campaign_id']))hotelFail('fixed_receipt_set_unconfirmed');
        $receipt=hotelHash($row['receipt_sha256']??null);hotelHash($row['payload_sha256']??null);
        hotelInt($row['stage_index']??null,9);hotelInt($row['payload_bytes']??null,1048576);
        if(isset($expected[$receipt]))hotelFail('fixed_receipt_set_unconfirmed');
        $expected[$receipt]=$row;
    }
    return $expected;
}

function deliveryApplication(PDO $pdo,array $expected): array {
    $identity=deliveryDatabaseIdentity($pdo,HOTEL_EXPECTED_APPLICATION_DB);
    $receipts=array_keys($expected);
    $rows=deliveryRows($pdo,"SELECT /*+ MAX_EXECUTION_TIME(2500) */ o.campaign_id,o.receipt_sha256,o.state,
        OCTET_LENGTH(o.payload_bytes) AS payload_bytes,
        CASE WHEN OCTET_LENGTH(o.payload_bytes)<=1048576 THEN SHA2(o.payload_bytes,256) ELSE NULL END AS payload_sha256,
        r.stage_index,CASE WHEN OCTET_LENGTH(r.receipt_bytes)<=65536 THEN SHA2(r.receipt_bytes,256) ELSE NULL END AS receipt_actual_sha256
        FROM hotel_server_catalog_outbox o
        LEFT JOIN hotel_server_receipts r ON r.campaign_id=o.campaign_id AND r.receipt_sha256=o.receipt_sha256
        WHERE o.receipt_sha256 IN (".implode(',',array_fill(0,28,'?')).") ORDER BY o.campaign_id,r.stage_index,o.receipt_sha256 LIMIT 29",$receipts,29);
    $seen=[];$pending=0;$done=0;$other=0;$matches=count($rows)===28;
    foreach($rows as &$row){
        $receipt=hotelHash($row['receipt_sha256']);
        if(!isset($expected[$receipt])||isset($seen[$receipt]))hotelFail('fixed_receipt_read_unconfirmed');
        $seen[$receipt]=true;$pin=$expected[$receipt];$row['payload_bytes']=hotelInt($row['payload_bytes']);
        if($row['stage_index']!==null)$row['stage_index']=hotelInt($row['stage_index'],9);
        $row['matches_expected']=$row['campaign_id']===$pin['campaign_id']&&$row['stage_index']===$pin['stage_index']
            &&$row['payload_bytes']===$pin['payload_bytes']&&$row['payload_sha256']===$pin['payload_sha256']
            &&$row['receipt_actual_sha256']===$receipt&&in_array($row['state'],['pending','done'],true);
        $matches=$matches&&$row['matches_expected'];
        if($row['state']==='pending')$pending++;elseif($row['state']==='done')$done++;else $other++;
    }unset($row);
    $campaign=deliveryRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ state,enabled,reserved_calls FROM hotel_server_campaigns WHERE campaign_id=? LIMIT 2',[HOTEL_B07_CAMPAIGN],2);
    if(count($campaign)!==1)hotelFail('b07_campaign_metadata_unconfirmed');
    $b07=$campaign[0];$b07['enabled']=hotelInt($b07['enabled'],1);$b07['reserved_calls']=hotelInt($b07['reserved_calls']);
    $counts=deliveryRows($pdo,"SELECT /*+ MAX_EXECUTION_TIME(2500) */ COUNT(*) AS attempts,
        COALESCE(SUM(CASE WHEN state='succeeded' THEN 1 ELSE 0 END),0) AS succeeded,
        COALESCE(SUM(CASE WHEN state='unknown' THEN 1 ELSE 0 END),0) AS unknown,
        COALESCE(SUM(CASE WHEN state NOT IN ('succeeded','unknown') THEN 1 ELSE 0 END),0) AS other
        FROM hotel_server_attempts WHERE campaign_id=?",[HOTEL_B07_CAMPAIGN],1);
    if(count($counts)!==1)hotelFail('b07_attempt_counts_unconfirmed');
    foreach($counts[0] as $key=>$value)$b07[$key]=hotelInt($value);
    $b07['matches_consumed_research']=$b07['state']==='complete'&&$b07['reserved_calls']===8
        &&$b07['attempts']===8&&$b07['succeeded']===8&&$b07['unknown']===0&&$b07['other']===0;
    return ['database_identity_sha256'=>$identity,'requested_receipt_count'=>28,'observed_receipt_count'=>count($rows),
        'all_fixed_receipts_match'=>$matches,'pending'=>$pending,'done'=>$done,'other'=>$other,'receipts'=>$rows,
        'metadata_sha256'=>hash('sha256',hotelCanonical($rows)),'b07'=>$b07,'raw_records_returned'=>false,'provider_attempts_aggregate_only'=>true];
}

function deliveryCatalog(PDO $pdo,array $expected): array {
    $identity=deliveryDatabaseIdentity($pdo,HOTEL_EXPECTED_CATALOG_DB);
    if($identity===HOTEL_EXPECTED_APPLICATION_DB)hotelFail('catalog_database_not_separate');
    $docs=deliveryRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ receipt_sha256,payload_sha256,OCTET_LENGTH(document_bytes) AS document_bytes,
        CASE WHEN OCTET_LENGTH(document_bytes)<=1048576 THEN SHA2(document_bytes,256) ELSE NULL END AS document_actual_sha256
        FROM hotel_server_catalog_documents WHERE receipt_sha256 IN ('.implode(',',array_fill(0,28,'?')).') ORDER BY receipt_sha256 LIMIT 29',array_keys($expected),29);
    $seen=[];$matching=0;
    foreach($docs as &$doc){
        $receipt=hotelHash($doc['receipt_sha256']);
        if(!isset($expected[$receipt])||isset($seen[$receipt]))hotelFail('fixed_catalog_read_unconfirmed');
        $seen[$receipt]=true;$pin=$expected[$receipt];$doc['document_bytes']=hotelInt($doc['document_bytes']);
        $doc['matches_expected']=$doc['payload_sha256']===$pin['payload_sha256']&&$doc['document_actual_sha256']===$pin['payload_sha256']
            &&$doc['document_bytes']===$pin['payload_bytes'];
        if($doc['matches_expected'])$matching++;
    }unset($doc);
    return ['database_identity_sha256'=>$identity,'separate_pdo'=>true,'requested_receipt_count'=>28,
        'observed_document_count'=>count($docs),'matching_documents'=>$matching,'conflicting_documents'=>count($docs)-$matching,
        'documents'=>$docs,'metadata_sha256'=>hash('sha256',hotelCanonical($docs)),'raw_documents_returned'=>false];
}

function deliverySummary(array $expected,array $application,array $catalog): array {
    $app=array_column($application['receipts'],null,'receipt_sha256');$docs=array_column($catalog['documents'],null,'receipt_sha256');
    $verified=0;$pendingWithDocument=0;$pendingWithoutDocument=0;$invalid=0;$rows=[];$campaigns=[];
    foreach($expected as $receipt=>$pin){
        $a=$app[$receipt]??null;$d=$docs[$receipt]??null;
        if(($a['matches_expected']??false)!==true||($d!==null&&($d['matches_expected']??false)!==true)){
            $state='unconfirmed';$invalid++;
        }elseif($a['state']==='done'){
            if($d===null){$state='ack_without_matching_catalog_document';$invalid++;}
            else{$state='acknowledged_and_catalog_verified';$verified++;}
        }elseif($d!==null){$state='catalog_seen_after_pending_application_snapshot';$pendingWithDocument++;}
        else{$state='pending_without_catalog_document';$pendingWithoutDocument++;}
        $rows[]=['campaign_id'=>$pin['campaign_id'],'stage_index'=>$pin['stage_index'],'receipt_sha256'=>$receipt,'state'=>$state];
        $campaigns[$pin['campaign_id']]??=['expected'=>0,'acknowledged_and_verified'=>0,'pending_with_document'=>0,'pending_without_document'=>0,'unconfirmed'=>0];
        $c=&$campaigns[$pin['campaign_id']];$c['expected']++;
        if($state==='acknowledged_and_catalog_verified')$c['acknowledged_and_verified']++;
        elseif($state==='catalog_seen_after_pending_application_snapshot')$c['pending_with_document']++;
        elseif($state==='pending_without_catalog_document')$c['pending_without_document']++;
        else $c['unconfirmed']++;unset($c);
    }
    $valid=$application['all_fixed_receipts_match']&&$application['b07']['matches_consumed_research']
        &&$catalog['conflicting_documents']===0&&$invalid===0;
    $state=!$valid?'unconfirmed':($verified===28?'complete':(($verified+$pendingWithDocument)>0?'partial':'none'));
    return ['state'=>$state,'fixed_receipts'=>28,'acknowledged_and_catalog_verified'=>$verified,
        'pending_at_application_snapshot_with_catalog_document_later'=>$pendingWithDocument,
        'pending_without_catalog_document'=>$pendingWithoutDocument,'unconfirmed_receipts'=>$invalid,
        'by_campaign'=>$campaigns,'receipts'=>$rows,'snapshots_atomic_across_databases'=>false,
        'application_snapshot_precedes_catalog_snapshot'=>true,'pending_with_document_does_not_prove_lost_ack'=>true,
        'b07_research_unchanged'=>$application['b07']['matches_consumed_research'],'ai_or_cards_consumption_verified'=>false];
}

ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$connections=[];$target=false;$outputDone=false;$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'state'=>'HOLD','phase'=>'target',
    'operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0,'runtime_config_changed'=>false,'cron_changed'=>false,
    'new_campaign_started'=>false,'automatic_retry_allowed'=>false,'snapshot_count'=>0,'query_count'=>0,'snapshots'=>[],
    'snapshots_atomic_across_databases'=>false,'section_errors'=>[]];

function observationReason(Throwable $e): string {
    $message=$e->getMessage();
    return $e instanceof DomainException&&preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'readback_unconfirmed';
}

function observationFinish(): int {
    global $connections,$target,$outputDone,$level,$report;
    if($outputDone)return 1;$outputDone=true;
    foreach($connections as $name=>$pdo)if($pdo->inTransaction()){
        try{$pdo->rollBack();$report['snapshots'][$name]['rolled_back']=true;}
        catch(Throwable $e){$report['section_errors'][$name.'_rollback']=observationReason($e);$report['state']='HOLD';}
    }
    $report['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $clean=$report['state']==='observed'&&$report['snapshot_count']===2&&$report['query_count']===6&&$report['section_errors']===[];
    foreach($report['snapshots'] as $snapshot)$clean=$clean&&($snapshot['rolled_back']??false)===true;
    try{$envelope=$target?hotelSeal($report):['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0];}
    catch(Throwable $ignored){$clean=false;$envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'sealed_readback_result_unconfirmed','operator_provider_calls'=>0];}
    while(ob_get_level()>$level)ob_end_clean();
    echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    return $clean&&$envelope['state']==='sealed'?0:1;
}

register_shutdown_function(static function():void{
    global $outputDone,$emergencyMemory,$report;
    if($outputDone)return;$emergencyMemory='';$e=error_get_last();$report['state']='HOLD';
    if($e!==null){$report['reason']='fatal_readback_unconfirmed';$report['fatal']=['type'=>$e['type'],'line'=>$e['line'],
        'message_sha256'=>hash('sha256',(string)$e['message'])];}
    else $report['reason']='interrupted_readback_unconfirmed';
    observationFinish();
});

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        hotelFail('target_unconfirmed');
    $target=true;
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $identity=hotelJson(HOTEL_OBSERVATION_IDENTITY_JSON);
    if(hash('sha256',hotelCanonical($identity))!==HOTEL_OPERATION)hotelFail('observation_identity_unconfirmed');
    $expected=deliveryExpected();$report['identity']=$identity;$report['github_run_id']=$run;$report['started_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $report['phase']='installed_source';deliverySources($app);$report['installed_sha']=HOTEL_EXPECTED_INSTALLED_SHA;
    hotelSeal(['state'=>'crypto_preflight','operator_provider_calls'=>0]);
    require_once $app.'/config.php';require_once $app.'/services/ConversationDb.php';require_once $app.'/services/HotelDatabase.php';
    $appPdo=ConversationDb::connection();
    $report['phase']='application_metadata';deliveryBegin($appPdo,'application');
    try{$report['application']=deliveryApplication($appPdo,$expected);}finally{deliveryEnd($appPdo,'application');}
    if(!HotelDatabase::configured())hotelFail('catalog_not_configured');
    $report['phase']='catalog_metadata';$hotelPdo=HotelDatabase::connection();
    if($hotelPdo===$appPdo)hotelFail('catalog_PDO_not_separate');
    deliveryBegin($hotelPdo,'catalog');
    try{$report['catalog']=deliveryCatalog($hotelPdo,$expected);}finally{deliveryEnd($hotelPdo,'catalog');}
    $report['phase']='installed_source_final';deliverySources($app);
    $report['delivery']=deliverySummary($expected,$report['application'],$report['catalog']);
    if($report['delivery']['state']==='unconfirmed')hotelFail('catalog_delivery_unconfirmed');
    $report['state']='observed';$report['phase']='finished';
}catch(Throwable $e){$report['state']='HOLD';$report['reason']=observationReason($e);}
exit(observationFinish());
