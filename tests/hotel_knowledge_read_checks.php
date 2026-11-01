<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerHotelKnowledgeReadService.php';

function hotelKnowledgeReadChecks(): void
{
    [$pdo,$publication] = draftHotelFixture();
    $source = $publication['offers'][0]['import_source'];
    $offer = array_replace($source['fields'], ['id'=>str_repeat('4',32),'selected'=>true,
        'source_url'=>$source['url'],'observed_at'=>$source['observed_at'],'import_source'=>$source]);
    $draft = draftWorkingSave($pdo, [$offer]);
    $input = ['kind'=>'working_draft','draft_version'=>1,'offer_id'=>str_repeat('4',32)];
    $read = static fn(array $args = [],int $actor = 1,int $conversation = 1,?callable $fence = null): array =>
        ManagerHotelKnowledgeReadService::read($actor,$conversation,$args ?: $input,$fence);
    $before = $pdo->query('SELECT * FROM manager_tour_selection_drafts')->fetchAll();
    $facts = $read();
    $native = draftHotelReference(draftContextCapture($pdo));
    draftContextCheck('AI native consumer and authorized constructor reader share the exact accepted fact group',
        ($native['status']??'') === 'available'
        && $native['facts'] === $facts['hotel_reference']['facts']
        && $native['sources'] === $facts['hotel_reference']['sources']
        && $native['binding_revision'] === $facts['hotel_reference']['binding_revision']);
    draftContextCheck('constructor exact imported draft receives accepted hotel facts',
        $facts['ok'] && ($facts['hotel_reference']['hotel_ref']['id'] ?? null) === 1554
        && ($facts['hotel_reference']['status'] ?? null) === 'available');
    draftContextCheck('constructor facts do not attest customer choice current trip or offer conditions',
        !$facts['current_trip_link'] && !$facts['customer_choice_verified'] && !$facts['offer_conditions_verified']
        && $facts['requires_manager_review']);
    draftContextCheck('constructor result contains no donor identity source import or transcript',
        !str_contains(json_encode($facts),'PRIVATE') && !str_contains(json_encode($facts),'pro.tourvisor.ru')
        && !isset($facts['import_source'],$facts['messages']));
    $sessionValid=true;$fenceSeen=false;$revokedDuringFinalRead=false;
    $pdo->observeRead=static function(string $sql)use(&$sessionValid,&$fenceSeen,&$revokedDuringFinalRead):void {
        if($fenceSeen&&str_starts_with($sql,'SELECT version,CASE')){$sessionValid=false;$revokedDuringFinalRead=true;}
    };
    $revoked=$read([],1,1,static function()use(&$sessionValid,&$fenceSeen):bool{$fenceSeen=true;return $sessionValid;});
    $pdo->observeRead=null;
    draftContextCheck('session revocation during final database reread withholds the complete fact response',
        $revokedDuringFinalRead&&$revoked===['ok'=>false,'error'=>'unauthorized']);
    $pub = ['kind'=>'publication','publication_id'=>str_repeat('a',32),'card_index'=>0];
    $published = $read($pub,1,2);
    draftContextCheck('frozen publication and draft share the same accepted facts sources and binding',
        $published['ok'] && $published['hotel_reference']['facts'] === $facts['hotel_reference']['facts']
        && $published['hotel_reference']['sources'] === $facts['hotel_reference']['sources']
        && $published['hotel_reference']['binding_revision'] === $facts['hotel_reference']['binding_revision']);
    draftContextCheck('constructor publication read cannot borrow another conversations card',
        $read($pub)['error'] === 'selection_unavailable');
    draftContextCheck('foreign and missing actors cannot read hotel facts',
        $read([],3)['error'] === 'forbidden' && $read([],99)['error'] === 'forbidden');
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
    draftContextCheck('deactivated assigned manager cannot read hotel facts',$read()['error'] === 'forbidden');
    $pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
    foreach ([['kind'=>'working_draft','draft_version'=>2,'offer_id'=>$input['offer_id']],
        ['kind'=>'working_draft','draft_version'=>1,'offer_id'=>str_repeat('5',32)]] as $changed) {
        draftContextCheck('stale or absent exact working offer requires reread', $read($changed)['error'] === 'selection_changed');
    }
    foreach ([array_replace($input,['draft_version'=>'1']),$input+['hotel_id'=>1554],
        array_replace($pub,['card_index'=>20]),array_replace($pub,['publication_id'=>'not-an-id'])] as $bad) {
        draftContextCheck('constructor rejects caller supplied identities malformed pins and unknown fields',
            $read($bad)['error'] === 'invalid_selection');
    }
    $pdo->beginTransaction();
    draftContextCheck('constructor read preserves caller transaction', $read()['error'] === 'knowledge_unavailable' && $pdo->inTransaction());
    $pdo->rollBack();
    draftContextCheck('late HTTP session refusal suppresses already prepared facts',
        $read([],1,1,static fn():bool=>false) === ['ok'=>false,'error'=>'unauthorized']);
    $revoked = $read([],1,1,static function() use($pdo):bool {
        $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1'); return true;
    });
    draftContextCheck('project revocation during session wait suppresses prepared facts',
        $revoked === ['ok'=>false,'error'=>'forbidden']);
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
    $changed = $read([],1,1,static function() use($pdo):bool {
        $pdo->exec('UPDATE manager_tour_selection_drafts SET version=2'); return true;
    });
    draftContextCheck('draft changed during session wait is rejected after the wait',
        $changed === ['ok'=>false,'error'=>'selection_changed']);
    $pdo->exec('UPDATE manager_tour_selection_drafts SET version=1');
    $edited = $draft;
    $edited['offers'][0]['hotel'] = 'Another hotel';
    $pdo->prepare('UPDATE manager_tour_selection_drafts SET draft_json=?')->execute([json_encode($edited)]);
    $held = $read();
    draftContextCheck('renamed working hotel cannot inherit retained original source knowledge',
        $held['ok'] && $held['hotel_reference']['status'] === 'held' && $held['hotel_reference']['facts'] === []);
    $pdo->prepare('UPDATE manager_tour_selection_drafts SET draft_json=?')->execute([$before[0]['draft_json']]);
    $pdo->exec("UPDATE conversations SET project_key='other' WHERE id=1");
    draftContextCheck('other project cannot be read even by AnyTour administrator',$read([],2)['error'] === 'forbidden');
    $pdo->exec("UPDATE conversations SET project_key='anytour' WHERE id=1");
    $pdo->exec("UPDATE manager_tour_selection_drafts SET draft_json='broken'");
    draftContextCheck('corrupt optional storage returns unavailable without partial facts',
        $read() === ['ok'=>false,'error'=>'knowledge_unavailable']);
    $pdo->prepare('UPDATE manager_tour_selection_drafts SET draft_json=?')->execute([str_repeat('x',1048577)]);
    draftContextCheck('oversize saved draft never reaches fact lookup', $read()['error'] === 'knowledge_unavailable');
    $pdo->prepare('UPDATE manager_tour_selection_drafts SET draft_json=?')->execute([$before[0]['draft_json']]);
    draftContextCheck('reader does not write drafts messages read marks or offers',
        $pdo->query('SELECT * FROM manager_tour_selection_drafts')->fetchAll() === $before
        && $pdo->writeAttempts === 0 && (int)$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1);
}
