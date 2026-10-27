<?php
declare(strict_types=1);

// Same existing factory; optional room/latest-time parameters bind frozen controls. No production
// connection, model/provider call, customer send or research export is used.
if (getenv('PREPARED_REPLY_MYSQL_DSN') !== false && getenv('PREPARED_REPLY_MYSQL_DSN') !== '') {
    throw new RuntimeException('This control is SQLite-only.');
}
$repo = realpath(__DIR__.'/repo');
if ($repo === false) throw new RuntimeException('Current checkout missing.');
define('MANAGER_PREPARED_FIXTURE_ONLY', true);
require $repo.'/tests/run_manager_prepared_reply_regression.php';
require_once $repo.'/services/ManagerTourSelectionPublicationDraft.php';
require_once $repo.'/services/ManagerTourSelectionImportDraft.php';

$controlChecks = [];
function controlCheck(string $name, bool $ok): void {
    global $controlChecks;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $controlChecks[] = $name;
}
function controlJson(array $v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function controlHash(array $v): string { return hash('sha256', controlJson($v)); }

/** Canonical existing fixture services; only the accepted native entry varies. */
function controlFixture(array $entry, string $question, ?string $sourceName = null, ?string $selectedRoom = null, ?string $latestAt = null): array {
    $pdo = preparedReplyFixture();
    controlCheck('fixture is SQLite in memory', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && !PreparedReplyPdo::$mysqlFixture);
    $native = $entry['source_identity']['hotel'];
    $country = $entry['source_identity']['country'];
    $name = $sourceName ?? $entry['accepted_source_names'][0];
    $room = $selectedRoom ?? ($entry['accepted_binding']['hotel_ref']['id'] === 1554 ? 'Family Suite' : 'Apartment');
    $destination = $entry['accepted_binding']['hotel_ref']['id'] === 1554 ? 'Египет, Шарм-эль-Шейх' : 'Египет, Макади-Бей';
    $observation = [
        'adapter'=>'tourvisor-snapshot-v1',
        'url'=>'https://pro.tourvisor.ru/search?ts_dosearch=1&s_form_mode=0&s_nights_from=11&s_nights_to=11&s_directflight=0&s_regular=1&x_hotel_codes='.$native.'&s_j_date_from=29.10.2026&s_j_date_to=29.10.2026&s_adults=2&s_meal=7&s_flyfrom=1&s_country='.$country,
        'observed_at'=>'2026-10-18T11:00:00Z', 'quote_id'=>'tv-snapshot-1234567890',
        'keys'=>['hotel'=>$native,'meal'=>'7','departure'=>'1','country'=>$country,'operator'=>'13'],
        'offer'=>['hotel'=>$name,'destination'=>$destination,'departure'=>'Москва','date_from'=>'2026-10-29','nights'=>11,'adults'=>2,'child_ages'=>[],'room'=>$room,'meal'=>'AI - Все Включено','amount'=>'160000','currency'=>'RUB','stars'=>5],
        'raw'=>['hotel'=>$name.' 5*','destination'=>$destination,'date'=>'29 октября','nights'=>'11 ночей','room'=>$room,'meal'=>'AI - Все Включено','party'=>'2 взрослых','price'=>'160 000 РУБ','price_basis'=>'за номер','base_price'=>'144 000 РУБ','fuel_price'=>'16 000 РУБ','tariff'=>'Synthetic control tariff','flight_alternatives'=>'Synthetic unselected flights','party_price_confirmation'=>'one_room_two_adults'],
        'photos'=>array_map(static fn(int $i): string=>'https://static.tourvisor.ru/hotel_pics/verybig/1/'.$native.'-'.$i.'.jpg',[1,2,3]),
    ];
    $normalized = ManagerTourSelectionImportDraft::normalize($observation);
    controlCheck('native observation normalizes', $normalized !== null);
    $offer = $normalized['offer']; $offer['import_source'] = ManagerTourSelectionImportDraft::source($normalized);
    $publication = ManagerTourSelectionPublicationDraft::build(['title'=>'Isolated controlled hotel publication','intro'=>'','offers'=>[$offer]]);
    controlCheck('immutable publication builds', !empty($publication['ok']));
    unset($publication['ok']);
    $s = $publication['offers'][0]['snapshot']; $d = $s['quoted_details'];
    $public = ['index'=>0,'hotel'=>$s['hotel'],'departure'=>$s['departure'],'date_from'=>$s['date_from'],'nights'=>$s['nights'],'adults'=>$s['adults'],'children'=>count($s['child_ages']),'room'=>$s['room'],'meal'=>$s['meal'],'amount'=>$s['amount'],'currency'=>$s['currency'],'rationale'=>$s['rationale'],'compromise'=>$s['compromise'],'destination'=>$d['destination'],'flights'=>$d['flights'],'inclusions'=>$d['inclusions'],'constraints'=>$d['constraints'],'observed_at'=>$d['observed_at'],'price_verified'=>false];
    $publicationId = str_repeat('d',32);
    $pdo->guard = false;
    try {
        $pdo->exec('CREATE TABLE manager_tour_selection_publications(publication_id CHAR(32) PRIMARY KEY,conversation_id INTEGER,draft_version INTEGER,draft_hash CHAR(64),created_by_manager_id INTEGER,binding_json TEXT,selection_json TEXT,created_at TEXT)');
        $pdo->exec("INSERT INTO conversations SELECT 102,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,'PRIVATE donor chat',is_test FROM conversations WHERE id=101");
        $pdo->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?,?,?)')->execute([$publicationId,102,1,str_repeat('e',64),1,controlJson(['project_key'=>'anytour','channel'=>'telegram','external_chat_id'=>'PRIVATE donor chat','external_user_id'=>'PRIVATE donor user']),controlJson($publication),'2026-10-19 09:59:00']);
        $payload = ['schema'=>1,'entry_key'=>str_repeat('f',64),'publication_id'=>$publicationId,'link_version'=>1,'variant'=>1,'public_offer'=>$public];
        $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?,?)')->execute([999,101,'tour_web_inquiry','customer',controlJson($payload),'2026-10-19 10:01:00']);
        $pdo->prepare('UPDATE messages SET text=?,metadata_json=?,created_at=? WHERE id=2')->execute(['Обращение из веб-подборки · вариант 1: '.$public['hotel']."\n".$question,controlJson(['type'=>'tour_web_inquiry','native_event_type'=>'bot_started','publication_id'=>$publicationId,'variant'=>1,'link_version'=>1]),$latestAt ?? gmdate('Y-m-d H:i:s',time()-60)]);
    } finally { $pdo->guard = true; }
    $snapshot = preparedReplySnapshot();
    controlCheck('current native inquiry snapshot captures', !empty($snapshot['ok']));
    $job = preparedReplyQueue($snapshot);
    return [$pdo,$snapshot,$job,$publication];
}

