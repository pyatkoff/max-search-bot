# MAX2 own early reserve lookup v2

Local source preparation only. These new `Max2ValueLookup*V2` classes do not replace or import the existing `MaxTrackerLookup*` latest-identity path. There is no endpoint, active request, key, database connection, migration, provider call or deployment in this package.

## Purpose and admitted producer contract

The separate RSA/SHA256 purpose is `anytour-max2-own-early-reserve-lookup-v2`, with evidence header `max2-own-early-reserve-v2`. Requests contain exactly one canonical MAX actor, metric project **1538**, counter **98615635**, a request nonce/time and immutable event/frame/cutoff context. Bodies are bounded and canonical; duplicate or extra keys, an old purpose/header, stale clocks or a different scope fail. Old signed lookup requests cannot authorize this read.

`producer-contract.json` records the actual installed four-file producer/receiver package and independently accepted serving evidence. Its exact SHA256 is compiled into the protocol. Root supplied and authenticated the source reports; this package copied their public pins only. The source operation was `05322884a0704b4363b31f572707345a148f798d`, original run 44902061112/job 136839835171, observed installed at 2026-11-21T12:04:36.510319+00:00. Independent acceptance was `3f1b98eee9180040038e13c44da5631e8ed04fb9`, run 44902190913/job 136840215846.

The repository separately requires the admitted producer-contract hash from trusted sidecar configuration. It never obtains that admission from incoming request fields. A future sidecar release must seal and admit this producer contract and the new read source through the normal original-run review gates. The local module and request alone do not establish deployment authority or re-verify current runtime files.

Under this specific producer contract, all four blank/null value fields mean **no own new early reserve**. They do not mean the actor never visited MAX2. The producer emits the new goals only after a same-add reservation and exact readback. Existing/reused saves do not reissue. Partial values, malformed positive values or uncertain clocks produce UNKNOWN, never zero. `issued_unconfirmed` remains a reservation without a browser-delivery acknowledgment.

## Bounded source read

The caller supplies an already configured PDO connection, using the established connection/read role and connection timeout. Only MySQL 8, native prepares and no pre-existing transaction are admitted. A single read-only, repeatable-read transaction is opened and always rolled back. The repository never bootstraps Bitrix, loads credentials, grants rights, updates a row or commits a write.

Two bounded SELECTs run: one metadata/config observation, then one actor-prefix or exact-record observation. The first verifies HL9's fixed `max_sub_links` table, InnoDB, all thirteen required columns, the actual TEXT shape of the four new fields, a single ID primary key, an existing actor-prefix BTREE index and one exact project-1538 counter configuration. There is no schema repair or index creation.

The actor query forces that validated index and applies `LIMIT 65` to the whole index prefix **before** exact actor/project/value filtering. A prefix containing 65 rows is UNKNOWN even if most rows belong to another actor. At most 64 rows are accepted. The outer SELECT has `MAX_EXECUTION_TIME(250)` and a window-based packet-size guard before values are transferred. The total DB packet budget is 32768 bytes and the observed local operation budget is 500 ms. Unsupported storage, missing indexes, query errors or any bound failure returns a closed UNKNOWN result with no candidates/counts and a fixed error code. Native connection establishment is the caller's responsibility; this module cannot establish or bypass a connection.

Exact actor rows in effective project 1538 are examined without a latest-row selection, post filter or inquiry-time filter. Canonical all-blank rows are counted separately. Positive rows require all four exact strings, current model/state, equal initial amounts of exactly 1799 or 2636 kopecks for this immutable bootstrap model (the general 10000000-kopeck cap remains a separate bound), a canonical saved MAX2 code, exact hotel pair and a CID up to 64 digits. Every positive candidate is retained, sorted only for deterministic hashing. Multiple candidates remain ambiguous for policy. Records later than the inquiry cutoff remain visible and must not become a full-value fallback.

The retained source clock is the existing Europe/London wall clock. Date normalization, future clocks and daylight-saving folds/gaps fail instead of guessing a timestamp. The immutable fingerprint covers the exact thirteen-field source tuple, including the saved code and original clock; those raw values are not exposed in the normalized candidate.

## Private protocol

List receipts use proof version 2 and coverage `all_exact_actor_own_early_reserves_at_observation`. `status` is `complete`, `complete_empty` or `unknown`. `complete_within_bound`, `scanned_rows`, `scoped_rows`, `blank_row_count`, the full candidate-set digest and the explicit producer-contract hash preserve the distinction. Both successful statuses retain `own_reserve_only=true` and `financial_permission=false`.

Each private candidate has exactly these policy fields: `tracker_record_id`, `immutable_tuple_sha256`, `counter_id`, `metric_project_id`, `external_user_id`, `post_id`, `offer_id`, `client_id`, `early_kopeks`, `common_kopeks`, `model_version`, `issuance_state`, `recorded_at_unix`. The app must add its own binding state; HL9 does not claim an inquiry or choose a conversation.

A separate `recheck` action binds the selected immutable candidate, original list request/set/receipt hashes and original observation clock. It performs one exact primary-key read with no alternative candidate or prefix fallback. Recheck coverage is `exact_selected_record_only`, with `match`, `missing`, `changed` or `unknown`; a missing point row cannot masquerade as an empty actor-wide set. The future app must freshly recheck before its own atomic unique claim.

The client is an injectable seam for a new guarded HTTPS sidecar. It signs the exact body, requires durable intent acknowledgment before one transport invocation, requests the existing 5-second connect/12-second overall limits and response streaming bound, requires verified TLS/no redirect/one request, and validates the exact private response. Transport adapters must enforce these properties while reading; none is installed here. It has no fallback to the old lookup or sender. Transport/malformed failures return null, not an empty receipt or zero.

Raw actor/CID/post/record keys and private receipt payloads must not be logged or shown. `publicSummary()` is the only public projection and emits status/counts/reason only. No method grants financial permission, uploads a conversion, adjusts v1 or confirms browser delivery.

## Verification and integration boundary

`tests/run_lookup.php` contains synthetic tests against the actual protocol, normalization, PDO query builder and client seam. PDO/HTTPS are fakes; temporary RSA keys are generated only inside the fixture. Scenarios cover complete/empty/multiple/blank/partial records, 64/65 bounds, byte/time limits, schema/index failures, exact actor/project scope, clocks, purpose/signature rejection, private projections, exact-PK rechecks and one consumed transport intent.

The local workspace has no PHP binary. These fixtures and PHP lint must run in the parent's native source-only CI; no PHP runtime PASS is claimed here. The package does not add an application claim/PR or activate a sidecar. Future app integration must bind the signed actor to the exact subject/channel/inquiry, consume the complete source proof, enforce unique conversation/inquiry/receipt claims and keep v1 independent.
