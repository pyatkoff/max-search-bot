<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ModeledConversionAssignedValue.php';

function modeledAssignedValueAssertions(callable $check):void
{
    $now=1793004000;
    $member=['record_id'=>'10','external_user_id'=>'700','source_id'=>'20','state'=>'subscribed',
        'subscribed_at_unix'=>$now-3600,'retention_checked_at_unix'=>null,'retention_record_id'=>null];
    $v=ModeledConversionAssignedValue::member($member,$now);
    $check('subscriber without any messages receives 50', $v['known_subtotal_rub']===50);
    $check('elapsed 30 minutes does not manufacture retention', $v['stages']['retention_30m']['status']==='unknown');
    $member['retention_checked_at_unix']=$now-1800;$member['retention_record_id']='11';
    $v=ModeledConversionAssignedValue::member($member,$now);
    $check('actual period-bound observed check yields 75', $v['known_subtotal_rub']===75);
    $repeat=ModeledConversionAssignedValue::member($member,$now);
    $check('retry is the same current value, not another accrual', $repeat===$v);
    $rejoin=$member;$rejoin['record_id']='12';
    $check('new subscription row does not mint another value scope',ModeledConversionAssignedValue::member($rejoin,$now)['value_scope']===$v['value_scope']);
    $bad=$member;$bad['retention_checked_at_unix']=$now+1;
    $check('future observed check cannot award retention',ModeledConversionAssignedValue::member($bad,$now)['known_subtotal_rub']===50);
    $bad['retention_checked_at_unix']=$member['subscribed_at_unix']+1799;
    $check('1799-second check cannot award retention',ModeledConversionAssignedValue::member($bad,$now)['known_subtotal_rub']===50);
    $greeting=modeledEarlyFixture([['text'=>'Здравствуйте']]);
    foreach($greeting[0]['rows']as&$r){$r['snapshot']['payload']['direction']='inbound';$r['snapshot']['payload']['sender_type']='customer';}unset($r);
    $greetingPacket=$greeting[1]+ModeledConversionEarlyForecast::freeze(...$greeting);
    $check('a real text greeting is dialogue without a concrete request',ModeledConversionAssignedValue::profile($greetingPacket,$greeting[0])['known_subtotal_rub']===50);
    $command=modeledEarlyFixture([['text'=>'/start']]);
    foreach($command[0]['rows']as&$r){$r['snapshot']['payload']['direction']='inbound';$r['snapshot']['payload']['sender_type']='customer';}unset($r);
    $commandPacket=$command[1]+ModeledConversionEarlyForecast::freeze(...$command);
    $check('a start command is not dialogue value',ModeledConversionAssignedValue::profile($commandPacket,$command[0])['known_subtotal_rub']===0);
    $parts=modeledOfferFixture('Fixture Beach A дорого, найдите дешевле с таким же пляжем');
    $packet=ModeledConversionEarlyForecast::freeze(...$parts);$wrapper=$parts[1]+$packet;
    $profile=ModeledConversionAssignedValue::profile($wrapper);
    $check('actual canonical request and discussion project 200 without membership', $profile['known_subtotal_rub']===200);
    $full=ModeledConversionAssignedValue::merge($v,$profile);
    $check('five configured stages sum to 275', $full['known_subtotal_rub']===275);
    $check('multiple current profile revisions are not summed',ModeledConversionAssignedValue::merge($full,$profile)['known_subtotal_rub']===275);
    $check('extra discussion weight remains disabled',$full['discussion_extra']['enabled']===false);
    $check('business weights do not enable advertising export',!$full['export_allowed']&&$full['conversion_uploads']===0);
    $check('reaction remains a price-change request, not a payment', $full['reaction_type']==='price_alternative_request');
    $check('existing frozen probability/value remain null',$packet['early_forecast']['expected_value_rub']===null&&$packet['early_forecast']['purchase_with_us']['probability']===null);
    $parts=modeledOfferFixture('Спасибо');$w=$parts[1]+ModeledConversionEarlyForecast::freeze(...$parts);
    $check('unlinked thanks cannot receive offer-reaction credit', ModeledConversionAssignedValue::profile($w)['stages']['offer_reply']['status']==='unknown');
    $wrapper['access_status']='scope_excluded';
    $check('excluded source awards nothing and stays partial',ModeledConversionAssignedValue::profile($wrapper)['known_subtotal_rub']===0&&ModeledConversionAssignedValue::profile($wrapper)['completeness']==='partial');
    $wrapper['access_status']='allowed';$wrapper['early_forecast']['early_quality']['specific_option_discussions'][0]['available_at']='2099-01-01T00:00:00Z';
    $check('late reaction is not backdated',ModeledConversionAssignedValue::profile($wrapper)['stages']['offer_reply']['status']==='unknown');
    require_once dirname(__DIR__).'/services/ModeledConversionValueRegistry.php';
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE conversations(id INTEGER,project_key TEXT,channel TEXT,is_test INTEGER,customer_id TEXT,customer_channel_id INTEGER,external_chat_id TEXT)');
    $pdo->exec('CREATE TABLE customer_channels(id INTEGER,customer_id TEXT,project_key TEXT,channel TEXT,external_user_id TEXT)');
    $pdo->exec('CREATE TABLE modeled_conversion_profile_versions(id INTEGER,project_key TEXT,platform TEXT,customer_key TEXT,conversation_id TEXT,observed_at_unix INTEGER,profile_json TEXT)');
    $registry=new ModeledConversionValueRegistry($pdo);
    $member['attribution']=['status'=>'unknown'];
    $page=['members'=>[$member],'observed_at_unix'=>$now,'has_more'=>false,'next_after'=>'10','coverage'=>'fixture'];
    $rows=$registry->members($page);
    $check('registry includes subscriber with no conversation at all',count($rows['rows'])===1&&$rows['rows'][0]['known_subtotal_rub']===75&&$rows['rows'][0]['profile_binding']==='no_dialogue');
    $pdo->exec("INSERT INTO customer_channels VALUES(7,'7','anytour','max','700')");
    $pdo->exec("INSERT INTO conversations VALUES(9,'anytour','max',0,'7',7,'-700')");
    $q=$pdo->prepare('INSERT INTO modeled_conversion_profile_versions VALUES(?,?,?,?,?,?,?)');
    $q->execute([1,'anytour','telegram','7','9',$now-1000,json_encode($parts[1]+ModeledConversionEarlyForecast::freeze(...$parts))]);
    $check('registry never merges a Telegram profile into a MAX member',$registry->members($page)['rows'][0]['known_subtotal_rub']===75);
    $check('dialogue page is a usable current value projection',count($registry->profiles('0',$now)['rows'])===1);
    $check('registry reads do not add award or profile rows',(int)$pdo->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()===1);
}
