# Read-only admission metadata for manager replies

The existing manual and selected prepared AI responses carry a separate admission object. The original eight proposal fields, provider schema, validation/insertion, preparation policy, jobs and feedback remain owned by their existing services. No sender, scheduling, new store or write action is introduced.

Four criteria: last customer question, current context, answer completeness and material conditions. The current implementation always returns decision=manager_review and actual_send_allowed=false. This is an inspectable preparation filter, not a positive semantic admission. The existing “Проверки менеджеру” panel renders the reasons without blocking explicit manual insertion or modifying text/File.

## What is actually checked

The canonical prepared owner reads the existing immutable row/archive and captures the current authorized viewer snapshot. It binds conversation, owner/project, revision (including raw source edits and related context), latest message, instruction revision, the exact displayed proposal hash, input message window and original prepared TTL. A newly observed access/context loss preserves the ordinary API refusal. Secondary admission storage failure instead returns unknown metadata without consuming or rewriting the prepared result.

Manual generation uses the actual captured provider arguments and canonical before/after snapshots. A feedback outage stays nonfatal. Feedback's 24-hour attribution window is not borrowed as an automatic-response TTL; a manual response has no independently established prepared TTL and remains unknown on that dimension.

Canonical selection/photo enrichment can replace the unread-link marker or clear unread flags without editing the raw transcript. Those exact bounded transformations remain unknown when an independent transformation binding is absent; they never imply stale input, semantic truth, source authenticity or model consumption. Other message-content changes still fail. Admission does not rerun any selection/photo reader.

A technical match is labelled only as technical. It does not establish answer correctness. Callbacks/start events do not replace the last actual customer-message reference. Message IDs, model checks and self-reported completeness do not resolve outstanding questions/corrections. A partial answer, a wrong answer about 9+1 instead of 14 nights, and a plausible complete answer all remain manager_review without independent semantic evidence. No keyword/regex classifier or positive reviewer issuer is fabricated.

## Provenance limits

ManagerAiDraftInputEvidence::fromJson validates envelope integrity, not source authenticity. ManagerPreparedInputService::read establishes server_issued_context; external_model_input remains unknown. Neither receipt issuance nor a valid checksum proves that the external model consumed that context.

Company knowledge supports only its admitted brand/search identity facts. Hotel research, material limits and photos do not prove terms of a specific future tour. A stored selection with current_price_verified=false cannot prove current price/availability. Changing nights/room/flight/meal does not transfer a previous amount. No current source-to-material-condition inventory exists here, so condition support stays unknown. No claims are inferred from wording or model self-report.

## Human reply boundary

The existing full-history has_manager_reply fact is projected as snapshot metadata without changing revision or preparation semantics. A human reply requires a future explicit authorized resume for automatic replies. A new customer input or all-own preparation rule is not resume. This package neither implements resume nor introduces a pause ledger; explicit_resume_proven stays false and automatic sending is off for every outcome. No_contact is informational and never authorizes contact.

## Verification

The existing context runner includes support cases and offers --admission-only for bounded local SQL/archive/snapshot checks. The existing AI behavior runner offers the same focused option for new readonly renderer/manual-File/context-fence cases. Its normal CI path still runs all existing cases. The --admission-normalization-only option runs just the two canonical enrichment cases and the existing tampered-envelope negative. No manifest or CI configuration changes.

The HTTP/session fixture is updated only for the additive response/dependency boundary. It retains exact checks for all legacy response fields and separately validates the four-criterion closed metadata. HTTP/browser/native/full MySQL checks belong to the existing coordinator/final-head CI; local source preparation does not claim those results.
