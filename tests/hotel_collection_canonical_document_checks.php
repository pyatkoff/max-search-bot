<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelCollectionCanonicalDocument.php';

$hcDocumentChecks = 0;
function hcDocCheck(bool $ok, string $name): void
{
    global $hcDocumentChecks; ++$hcDocumentChecks;
    if (!$ok) throw new RuntimeException('canonical_document_assertion:'.$name);
}
function hcDocReject(callable $call, string $name, ?string $reason = null): void
{
    try { $call(); }
    catch (InvalidArgumentException | DomainException $e) {
        hcDocCheck($reason === null || $e->getMessage() === 'canonical_document_'.$reason, $name.':'.$e->getMessage());
        return;
    }
    hcDocCheck(false, $name);
}
function hcDocJson(array $value, bool $pretty = false): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0));
}
function hcDocFixture(): array
{
    $hash = str_repeat('a', 64);
    $context = ['queue_id' => 'FIXTURE', 'operation_id' => 'operation-1', 'stage_id' => 'stage-1',
        'country' => 'TH', 'scope_id' => 'scope-1', 'source_packet_id' => 'producer-packet-1',
        'hotel_keys' => ['hotel-1', 'hotel-2'], 'base_revision' => 0, 'baseline_sha256' => $hash];
    $index = ['baseline_sha256' => $hash,
        'facts' => [['id' => 'base-fact', 'hotel_key' => 'hotel-1', 'country' => 'TH']],
        'sources' => [['id' => 'base-source', 'hotel_key' => 'hotel-1', 'country' => 'TH', 'evidence_sha256' => str_repeat('c', 64)]],
        'read_update_ids' => ['base-read'], 'question_ids' => ['base-question']];
    $packet = ['schema_version' => 1];
    foreach ($context as $key => $value) if ($key !== 'hotel_keys') $packet[$key] = $value;
    $packet['sources'] = [['id' => 'source-1', 'hotel_key' => 'hotel-1', 'url' => 'https://example.test/rooms',
        'kind' => 'official', 'publication_date' => null, 'retrieved_at' => '2026-11-01T00:00:00Z',
        'evidence_sha256' => str_repeat('b', 64), 'read_scope' => 'Family room paragraph', 'limitations' => 'Future operation unknown']];
    $packet['facts'] = [['id' => 'fact-1', 'hotel_key' => 'hotel-1', 'criterion_id' => 'room_layout',
        'text' => 'The published category contains two rooms.', 'kind' => 'official_declared',
        'scope' => ['category' => 'Family', 'age' => null, 'season' => null, 'product' => null],
        'source_ids' => ['source-1'], 'material_limits' => ['Party eligibility is unknown'], 'valid_at_travel' => 'unverified']];
    $packet['read_updates'] = [['id' => 'read-1', 'hotel_key' => 'hotel-1', 'source_id' => 'base-source',
        'evidence_sha256' => str_repeat('c', 64), 'read_scope' => 'Previously unread section',
        'read_at' => '2026-11-01T00:00:00Z', 'limitations' => '']];
    $packet['questions'] = [['id' => 'question-1', 'hotel_key' => 'hotel-1', 'question_key' => 'room-party-gap',
        'criterion_id' => 'room_layout', 'status' => 'PARTIAL', 'answer' => 'Room layout known; party eligibility unknown.',
        'fact_ids' => ['fact-1', 'base-fact'], 'remaining_unknowns' => ['Party eligibility']]];
    return [$context, $index, HotelCollectionCanonicalDocument::emptyOverlay($hash), $packet];
}
function hcDocValidate(array $packet, array $context, array $overlay, array $index): array
{
    return HotelCollectionCanonicalDocument::validate(hcDocJson($packet), $context, $overlay, $index);
}

[$context, $index, $overlay, $packet] = hcDocFixture();
$before = [$context, $index, $overlay, $packet];
$valid = hcDocValidate($packet, $context, $overlay, $index);
$applied = HotelCollectionCanonicalDocument::apply(hcDocJson($packet), $context, $overlay, $index);
hcDocCheck([$context, $index, $overlay, $packet] === $before, 'validation_and_apply_are_pure');
hcDocCheck($valid['counts'] === ['facts' => 1, 'sources' => 1, 'read_updates' => 1, 'questions' => 1], 'counts_derived_from_typed_rows');
hcDocCheck($applied['overlay']['facts'][0]['country'] === 'TH' && $applied['overlay']['facts'][0]['operation_id'] === 'operation-1', 'row_ownership_generated_from_context');
hcDocCheck($applied['ledger_entry']['counts'] === $valid['counts'] && $applied['overlay']['ledger'] === [$applied['ledger_entry']], 'ledger_generated_with_commit_data');
hcDocCheck($applied['ledger_entry']['business_accepted'] === false && $applied['ledger_entry']['native_run_verified'] === false, 'no_claimed_semantic_acceptance_or_native_proof');
hcDocCheck($applied['candidate']['questions'][0]['status'] === 'PARTIAL' && $applied['candidate']['facts'][0]['valid_at_travel'] === 'unverified', 'partial_and_future_unknown_preserved');

$format = array_reverse($packet, true);
$format['facts'][0] = array_reverse($format['facts'][0], true);
$format['facts'][0]['scope'] = array_reverse($format['facts'][0]['scope'], true);
$format['questions'][0]['fact_ids'] = array_reverse($format['questions'][0]['fact_ids']);
$formatted = HotelCollectionCanonicalDocument::validate(hcDocJson($format, true), $context, $overlay, $index);
hcDocCheck($formatted['semantic_sha256'] === $valid['semantic_sha256'] && $formatted['raw_sha256'] !== $valid['raw_sha256'], 'formatting_and_set_order_do_not_change_semantic_digest');
hcDocCheck($formatted['identity_sha256'] === $valid['identity_sha256'], 'normalized_identity_digest_ignores_formatting');
$otherContext = $context; $otherContext['operation_id'] = 'operation-other'; $otherContext['stage_id'] = 'stage-other';
$otherContext['base_revision'] = 7; $otherContext['source_packet_id'] = 'packet-other'; $otherContext['scope_id'] = 'scope-other';
$sameContent = $format;
foreach ($otherContext as $key => $value) if ($key !== 'hotel_keys') $sameContent[$key] = $value;
$other = HotelCollectionCanonicalDocument::validate(hcDocJson($sameContent, true), $otherContext, $overlay, $index);
hcDocCheck($other['semantic_sha256'] === $valid['semantic_sha256'] && $other['identity_sha256'] !== $valid['identity_sha256'], 'same_content_under_new_authority_retains_semantic_digest');
$two = $packet; $s = $two['sources'][0]; $s['id'] = 'source-2'; $two['sources'][] = $s;
$twoReordered = $two; $twoReordered['sources'] = array_reverse($two['sources']);
hcDocCheck(hcDocValidate($two, $context, $overlay, $index)['semantic_sha256'] === hcDocValidate($twoReordered, $context, $overlay, $index)['semantic_sha256'], 'row_order_normalized');

foreach (['ledger', 'reserves', 'accepted_batches', 'owner'] as $field) {
    $bad = $packet; $bad[$field] = [];
    hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'forbidden_envelope_'.$field, 'object_keys');
}
foreach (['business_accepted', 'claim_key', '__proto__'] as $field) {
    $bad = $packet; $bad['facts'][0][$field] = 'injected';
    hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'unknown_nested_'.$field, 'object_keys');
}
$bad = $packet; $bad['facts'][0]['scope']['owner'] = 'other';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'unknown_nested_scope', 'object_keys');

$raw = hcDocJson($packet);
$duplicate = str_replace('"schema_version":1', '"schema_version":1,"schema_version":1', $raw);
hcDocReject(fn() => HotelCollectionCanonicalDocument::validate($duplicate, $context, $overlay, $index), 'duplicate_root_key', 'duplicate_json_key');
$escapedDuplicate = str_replace('"id":"fact-1"', '"id":"fact-1","i\\u0064":"fact-1"', $raw);
hcDocReject(fn() => HotelCollectionCanonicalDocument::validate($escapedDuplicate, $context, $overlay, $index), 'escaped_duplicate_nested_key', 'duplicate_json_key');
$bad = $packet; $bad['facts'][0]['text'] = 'Text with "id":1 and escaped \\ characters is not an object key.';
hcDocCheck(hcDocValidate($bad, $context, $overlay, $index)['counts']['facts'] === 1, 'quoted_text_is_not_duplicate_key');
hcDocReject(fn() => HotelCollectionCanonicalDocument::validate(str_repeat(' ', HotelCollectionCanonicalDocument::MAX_PACKET_BYTES + 1), $context, $overlay, $index), 'oversized_packet', 'packet_too_large');
hcDocReject(fn() => HotelCollectionCanonicalDocument::validate('{"a":'.str_repeat('[', 26).'0'.str_repeat(']', 26).'}', $context, $overlay, $index), 'deep_json', 'json_depth');
hcDocReject(fn() => HotelCollectionCanonicalDocument::validate(substr($raw, 0, -1).',}', $context, $overlay, $index), 'trailing_comma', 'invalid_json');
hcDocReject(fn() => HotelCollectionCanonicalDocument::validate($raw.' true', $context, $overlay, $index), 'trailing_json_value', 'invalid_json');
hcDocReject(fn() => HotelCollectionCanonicalDocument::validate(str_replace('"sources":[', '"sources":{', $raw), $context, $overlay, $index), 'invalid_json_delimiter');
$bad = $packet; $bad['facts'][0]['text'] = str_repeat('x', 4001);
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'oversized_fact_text', 'text');
$bad = $packet; $bad['sources'] = array_fill(0, HotelCollectionCanonicalDocument::MAX_ROWS + 1, $packet['sources'][0]);
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'oversized_row_count', 'list_shape_or_limit');
$bad = $packet; $bad['facts'][0]['material_limits'] = (object)[];
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'empty_object_not_list', 'list_shape_or_limit');

foreach (['operation_id', 'stage_id', 'country', 'scope_id', 'source_packet_id', 'baseline_sha256', 'base_revision', 'queue_id'] as $key) {
    $bad = $packet; $bad[$key] = $key === 'base_revision' ? 1 : 'different';
    hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'wrong_context_'.$key, 'context_mismatch_'.$key);
}
$bad = $packet; $bad['facts'][0]['hotel_key'] = 'outside-scope';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'wrong_hotel_scope', 'scope_mismatch');
$bad = $packet; $bad['facts'][0]['source_ids'] = ['missing'];
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'missing_source_fk', 'reference_scope_mismatch');
$bad = $packet; $bad['sources'][0]['hotel_key'] = 'hotel-2';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'cross_hotel_source_fk', 'reference_scope_mismatch');
$wrongIndex = $index; $wrongIndex['sources'][0]['country'] = 'TR';
hcDocReject(fn() => hcDocValidate($packet, $context, $overlay, $wrongIndex), 'cross_country_baseline_source_fk', 'reference_scope_mismatch');
$bad = $packet; $bad['read_updates'][0]['evidence_sha256'] = str_repeat('d', 64);
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'source_read_content_mismatch', 'source_evidence_mismatch');
$bad = $packet; $bad['questions'][0]['fact_ids'] = ['missing'];
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'missing_fact_fk', 'reference_scope_mismatch');
$bad = $packet; $bad['facts'][0]['id'] = 'base-fact';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'baseline_fact_id_cannot_redefine', 'id_already_exists_facts');
$bad = $packet; $bad['sources'][0]['id'] = 'base-source';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'baseline_source_id_cannot_redefine', 'id_already_exists_sources');
$bad = $packet; $bad['read_updates'][0]['id'] = 'base-read';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'baseline_read_id_cannot_redefine', 'id_already_exists_read_updates');
$bad = $packet; $bad['questions'][0]['id'] = 'base-question';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'baseline_question_id_cannot_redefine', 'id_already_exists_questions');
$bad = $packet; $bad['facts'][] = $bad['facts'][0];
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'duplicate_new_row', 'id_already_exists_facts');
hcDocReject(fn() => hcDocValidate($packet, $context, $applied['overlay'], $index), 'append_only_existing_overlay_id', 'id_already_exists_facts');

$secondContext = $context; $secondContext['operation_id'] = 'operation-2'; $secondContext['source_packet_id'] = 'producer-packet-2'; $secondContext['base_revision'] = 1;
$second = $packet;
foreach ($secondContext as $key => $value) if ($key !== 'hotel_keys') $second[$key] = $value;
$second['facts'] = []; $second['sources'] = []; $second['questions'] = [];
$second['read_updates'][0]['id'] = 'read-2'; $second['read_updates'][0]['source_id'] = 'source-1'; $second['read_updates'][0]['evidence_sha256'] = str_repeat('b', 64);
$secondResult = HotelCollectionCanonicalDocument::apply(hcDocJson($second), $secondContext, $applied['overlay'], $index);
hcDocCheck(count($secondResult['overlay']['read_updates']) === 2 && $secondResult['overlay']['facts'] === $applied['overlay']['facts'], 'new_read_appends_without_source_or_fact_rewrite');
hcDocCheck($secondResult['overlay']['ledger'][0] === $applied['ledger_entry'] && count($secondResult['overlay']['ledger']) === 2, 'prior_ledger_preserved');

$bad = $packet; $bad['questions'][0]['status'] = 'RESOLVED';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'resolved_cannot_retain_unknowns', 'resolved_question_inconsistent');
$bad = $packet; $bad['facts'][0]['valid_at_travel'] = 'verified';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'future_service_not_inferred', 'future_validity_claim');
$bad = $packet; $bad['sources'][0]['publication_date'] = '2026-02-30';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'invalid_calendar_date', 'date');
$bad = $packet; $bad['sources'][0]['url'] = 'https://user:password@example.test/';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'url_credentials_rejected', 'source_url');
$bad = $packet; $bad['sources'][0]['evidence_sha256'] = 'supplied-unverified-reference';
hcDocReject(fn() => hcDocValidate($bad, $context, $overlay, $index), 'invalid_evidence_digest', 'sha256');
$badIndex = $index; $badIndex['baseline_sha256'] = str_repeat('f', 64);
hcDocReject(fn() => HotelCollectionCanonicalDocument::validateBaselineIndex($badIndex, $context['baseline_sha256']), 'baseline_index_mismatch', 'baseline_index_mismatch');
$badIndex = $index; $badIndex['facts'][] = $badIndex['facts'][0];
hcDocReject(fn() => HotelCollectionCanonicalDocument::validateBaselineIndex($badIndex, $context['baseline_sha256']), 'baseline_duplicate_id', 'duplicate_baseline_id');
$badOverlay = $applied['overlay']; $badOverlay['ledger'][0]['business_accepted'] = true;
hcDocReject(fn() => hcDocValidate($second, $secondContext, $badOverlay, $index), 'stored_ledger_does_not_claim_business_acceptance', 'overlay_ledger');
$badOverlay = $overlay; $badOverlay['baseline_sha256'] = str_repeat('f', 64);
hcDocReject(fn() => hcDocValidate($packet, $context, $badOverlay, $index), 'wrong_overlay_baseline', 'overlay_baseline_mismatch');
$errorState = serialize([$context, $index, $overlay, $packet]);
$bad = $packet; $bad['facts'][0]['hotel_key'] = 'unreserved';
hcDocReject(fn() => HotelCollectionCanonicalDocument::apply(hcDocJson($bad), $context, $overlay, $index), 'failed_apply', 'scope_mismatch');
hcDocCheck(serialize([$context, $index, $overlay, $packet]) === $errorState, 'failed_apply_leaves_all_inputs_unchanged');

// A successful append must remain readable at the persisted row boundary.
$limit = 20000;
$limitOverlay = $overlay;
$sourceRow = $packet['sources'][0] + ['country' => 'TH', 'operation_id' => 'prior-import'];
for ($n = 0; $n < $limit - 1; ++$n) {
    $sourceRow['id'] = 'limit-source-'.$n;
    $limitOverlay['sources'][] = $sourceRow;
}
$sourceOnly = $packet; $sourceOnly['facts'] = []; $sourceOnly['read_updates'] = []; $sourceOnly['questions'] = [];
$sourceOnly['sources'][0]['id'] = 'limit-source-final';
$atLimit = HotelCollectionCanonicalDocument::apply(hcDocJson($sourceOnly), $context, $limitOverlay, $index);
hcDocCheck(count($atLimit['overlay']['sources']) === $limit, 'append_reaches_exact_source_limit');
$probe = $packet; $probe['facts'] = []; $probe['sources'] = []; $probe['read_updates'] = [];
$probe['questions'][0]['id'] = 'limit-probe'; $probe['questions'][0]['fact_ids'] = []; $probe['questions'][0]['status'] = 'HOLD';
hcDocCheck(hcDocValidate($probe, $context, $atLimit['overlay'], $index)['counts']['questions'] === 1, 'exact_source_limit_remains_readable');
$sourceOnly['sources'][0]['id'] = 'limit-source-overflow';
$limitBefore = hash('sha256', hcDocJson($atLimit['overlay']));
hcDocReject(fn() => HotelCollectionCanonicalDocument::apply(hcDocJson($sourceOnly), $context, $atLimit['overlay'], $index), 'append_over_source_limit_rejected_before_return', 'list_shape_or_limit');
hcDocCheck(hash('sha256', hcDocJson($atLimit['overlay'])) === $limitBefore, 'source_limit_error_preserves_input');
unset($limitOverlay, $sourceRow, $atLimit);

// Synthetic prior ledger rows exercise the document cap; uniqueness is store-owned.
$limitOverlay = $overlay;
$limitOverlay['ledger'] = array_fill(0, $limit - 1, $applied['ledger_entry']);
$atLimit = HotelCollectionCanonicalDocument::apply(hcDocJson($sourceOnly), $context, $limitOverlay, $index);
hcDocCheck(count($atLimit['overlay']['ledger']) === $limit, 'append_reaches_exact_ledger_limit');
hcDocCheck(hcDocValidate($probe, $context, $atLimit['overlay'], $index)['counts']['questions'] === 1, 'exact_ledger_limit_remains_readable');
$limitBefore = hash('sha256', hcDocJson($atLimit['overlay']));
hcDocReject(fn() => HotelCollectionCanonicalDocument::apply(hcDocJson($probe), $context, $atLimit['overlay'], $index), 'append_over_ledger_limit_rejected_before_return', 'list_shape_or_limit');
hcDocCheck(hash('sha256', hcDocJson($atLimit['overlay'])) === $limitBefore, 'ledger_limit_error_preserves_input');
unset($limitOverlay, $atLimit);

echo 'hotel_collection_canonical_document_checks: '.$hcDocumentChecks." passed\n";
