# Telegram Business → tgmini: finite private overlap report

This is the identity-only continuation of issue #1140. It answers which retained
inbound Business records have an exact Telegram peer in the saved verified
`anytour / tgmini / telegram / 98615635` visit history. It does not infer customer
intent, calculate value, resolve an attribution winner or deliver a conversion.

## Existing protected entry

`list_current_dialogues` advertises the `quality_history.lanes.archive.business_identity`
descriptor. Follow its `start_cursor` through the same tool. This finite operation
requires the three existing, explicitly granted capabilities `quality:read`,
`archive:read` and `forecasts:read`. A claimed scope list does not replace the
stored active grant. The fixed cursor is accepted only by the inventory tool;
`limit`, if supplied, must be 25. It cannot select another project, peer or time.

The cohort definition is the original fence **326088**, at **26 November 2026,
14:05 Europe/Moscow** (`1795691100` UTC epoch seconds), with up to 25 records in
descending archive row order. The archive stores current versions. This fence
does **not** reconstruct a historical snapshot. Rows whose current version is
later than the cutoff have their own exclusion count. The report describes the
currently retained, currently authorized portion of that finite selection.

`ArchiveResearchReadService` owns the read-only consistent snapshot, account
permissions, fixed owner/service exclusions, query deadline and rollback.
Authorization and exact account/bot/connection permissions are checked again
outside that snapshot before disclosure. The MCP boundary rechecks the same
three grants before returning the packaged answer.

## Admission and privacy

Only active AnyTour project registry accounts with current approval, provider
enablement and the same enabled connection enter selection. The first query
selects at most 25 Business row IDs. SQL then extracts typed, bounded metadata
scalars for only those IDs. The application never receives the raw JSON, message
text, captions, links or attachments through this operation.

Stored account/chat/message IDs must agree with their columns. The source kind,
bot key, connection, deletion flag and creation/version clocks must be valid.
An inbound customer requires `sender_id == chat_id`; an outbound manager requires
`sender_id == account_id`. Known native test peers are excluded. Unknown legacy
test status and per-dialogue brand remain explicitly unknown: registry membership
does not prove either. The report cannot certify a count of qualified conversions.

`counts.selected_records` partitions into verified inbound/outbound records and
the reported exclusion reasons. `distinct.account_chat_pairs` and
`distinct.telegram_peers` refer to admitted incoming records. Telegram peers are
not a proven count of physical people.

The existing `AdvertisingVisitRepository::entryContextCandidates` supplies the
complete bounded history in the same transaction, with exact scope and identity
conflict checks. Its opt-in `yclid_present` projection returns only `0`, `1` or
unknown for malformed data; raw YCLID never leaves SQL. Ordinary callers retain
their previous projection. ClientID remains private process data solely for
uniqueness checks. No message-level or identity-level rows leave the report.

The shared additional projection budget is 1,001 rows and 1 MiB, within the
archive reader's eight-second deadline. The candidate sentinel consumes the
remaining row budget; reaching it withholds the aggregate. A missing required
history, timeout, permission loss or changed source is unavailable, never a
fabricated zero-match result. Optional unavailable overlap/checkpoint sources
are explicitly unknown with null counts.

## Reading the result

Metrics are provided separately for incoming records and distinct peers. They
overlap and must not be added together. For a peer with multiple records, a
feature means it held for at least one selected event.

| Metric | Meaning |
| --- | --- |
| `prior_visit` | A saved verified candidate has a valid visit time not later than the message; conflict and invalid-history flags remain separate. |
| `verified_prior_match` | Prior candidate with no identity conflict or invalid history for this peer. |
| `prior_visit_21d` / `verified_prior_match_21d` | The corresponding diagnostic condition within 21 days before the message. This is not an export window or send permission. |
| `prior_visit_available_at_message` | At least one prior candidate was both observed and received no later than the message. |
| `unique_client_id` / `multiple_client_ids` | Exactly one usable prior ClientID, or several different prior ClientIDs. No winner is selected. |
| `click_present` | At least one prior candidate contains a syntactically valid click ID. Click uniqueness is not established. |
| `same_observation_pair` | One usable ClientID and a valid click ID occur together in an actual prior observation. Separate rows are never spliced into a pair. |
| `late_observed` / `late_imported` | Prior visit evidence was observed or imported after the message. |
| `future_visit` | A saved visit occurred after the message. It does not establish a prior match. |
| `identity_conflict` / `invalid_history` | Conflicting visit ownership or invalid saved clocks/identifiers prevent usable identity claims. |
| `missing_history` | The bounded complete query returned no verified observation for this peer. It does not mean the person never visited. |

The `window_21d_*` identity fields use only prior candidates inside that diagnostic
window. Equal seconds mean **not later**, not independently established causality.

Native dialogue, signal and outbox overlaps concern current saved rows and are
not immutable evidence of what existed at the original cutoff. A checkpoint
bound to its exact observation proves that local binding only. Its receiving
time cannot prove current feed freshness or the completeness of external history;
those remain unknown. Old subscribers without captured advertising identities
are expected and are not automatically a tracking defect.

## Verification and release

Synthetic SQLite coverage executes the real read-only transaction, typed JSON
projection, exclusions, time boundaries, candidate sentinel, same-observation
pairing, scopes and revocation. The existing required native MySQL archive suite
executes the same assertions. The actual MCP test covers successful disclosure
under the triple grant and withdrawal of each grant after source reads.

Ordinary required source CI and release admission apply. No new worker, queue,
workflow or schedule is introduced. A source test or successful installation is
not the production result: acceptance requires one actual private read through
the returned descriptor, with its denominators and unknown coverage retained.
