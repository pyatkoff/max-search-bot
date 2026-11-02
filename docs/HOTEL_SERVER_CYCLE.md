# Dormant server cycle

This is callable server code, not an installed or enabled collector. No cron,
endpoint, native task binding, subscription, campaign or external provider is
created by including these classes or applying the forward schema. The existing
preparation plan remains authoritative: `ready_for_launch=false`. CENTRAL41 stays
UNKNOWN and receives no new acceptance or cancellation claim.

## Inputs and historical credit

`HotelServerInputs::load` reads the existing immutable retirement row on the
application PDO and a private server manifest. It verifies the exact receipt and
snapshot bytes, original intake receipts and any linked resolutions, producer
packet identity, exactly 19 distinct accepted intake packets, and complete mapping
of all 19 receipt identities to country/hotel/question/source scopes. Research,
UNKNOWN and skipped stages earn no historical intake credit. Unaccepted source
records, including TR40, must not be included in the private baseline or map.

The private JSON manifest has schema `hotel-server-input-manifest-v1` and `files`
entries for baseline, criteria, corpus, map, archives, instructions, reserves and
accepted_mapping. Each entry supplies a relative private path and exact SHA256.
Files must exist under the supplied private root, outside the document root;
symlinks resolving outside it are rejected. Each file is bounded to 4 MiB.
`accepted_mapping` is a list of exactly 19 objects with `stage_id`,
`packet_sha256`, `receipt_sha256`, and a nonempty `scopes` list. `reserves` is a
scope list. Every scope has the four explicit fields country, hotel, question and
source. Original baseline bytes are retained without reserialization or import
credit. The importer cannot infer missing semantic mapping from packet hashes.

These checks establish byte identity and complete declared mapping, not the truth
of a human-authored semantic map. The operator must validate that mapping against
the retained WORKING and exclude every unaccepted source before authorization.
No private baseline, actual scope, input path or original receipt bytes belong in
the public repository. The current server inventory did not verify those private
files; production remains HOLD for inputs/scope mapping.

## Database and lock boundary

`migrations/084_hotel_server_cycle.sql` is a new, forward-only ConversationDb
migration. 082 and 083 are untouched. Canonical new records, immutable candidate,
review, baseline chain, domain receipt, new credit and both outboxes use the same
operational PDO as the existing `hotel_work_lane/HOTEL` lock. Lock order is HOTEL
lane, exact new campaign, then attempt/candidate. The constructor rejects a
different lane PDO. Cached legacy admission sees the same occupied HOTEL row.

Bootstrap needs its own explicit authorization and a new campaign ID. It first
durably reserves the shared lane, then imports a disabled campaign. A crash between
those steps leaves a busy lane, with zero sends and zero credit. An exact bootstrap
readback is idempotent; different input/plan under that ID conflicts. No timeout
releases the reservation. Enabling needs another explicit authorization, exact
bootstrap pin, complete inputs, two configured independent provider principals,
explicit models and bounded stage/call/token/cost/deadline limits. No production
bootstrap or enable is performed by this PR.

HotelDatabase is a separate projection only. Its schema is under
`migrations/hotel/084_hotel_server_projection.sql`, outside MigrationRunner's
nonrecursive application glob, and must be applied explicitly to HotelDatabase.
`HotelServerCatalogProjector` commits by exact receipt/hash on that separate PDO,
then acknowledges the operational outbox. A lost ACK can replay this idempotent
projection; it cannot repeat research or grant new acceptance credit. This is not
a distributed transaction or an assertion that existing catalog readers use the
new projection. No existing accepted/catalog records are overwritten.

## One bounded cron step

An eventual existing-cron integration calls `tick` once. Disabled, complete, HOLD
or UNKNOWN states send nothing. A ready step serializes admission, checks current
accepted/reserved semantic keys and budget, writes a fresh immutable request and
UNKNOWN attempt/outbox, pessimistically reserves the full per-call token/cost
ceiling, commits and releases all SQL locks, then performs at most one provider
send. There is no refund, lease-expiry replacement, automatic retry, or provider
default. Provider readiness must be a local configuration/capability check and
must not make a network call. The private adapter must itself send at most once.

The producer can only save a bounded, exact-scope candidate with answers and HTTPS
source evidence. A later cron step creates a separate reviewer invocation and
principal, pinned to candidate, baseline, immutable inputs and criteria bytes.
Only an approved, nonempty independent review allows a later network-free accept.
Acceptance rechecks baseline CAS, candidate/review lineage and current exclusions,
then atomically appends canonical records, baseline, receipt, one new credit and
the next producer outbox. Producer output/import/projection never grants credit.

After an uncertain external send, `recordResult` can reconcile only the exact
original attempt. Identical saved result readback is idempotent; a different result
under the same attempt conflicts. Deadline expiry never causes another send. A
late protected reserve defeats acceptance and leaves all data/credit unchanged.
Unconfigured private research/reviewer adapters and absent manifests remain launch
blockers. This library supplies neither a generic short AI client nor a fake live
readiness callback.

## Verification and remaining gates

`tests/run_hotel_server_cycle_regression.php` is in the existing required manager
group. The same test runs natively from the existing MySQL hotel-work harness,
using only a generated `maxbot_prepared_test_hs_*` fixture database and the existing
CI fixture credentials. It never connects to a production DSN. Actual SQL tests
cover immutable evidence, disabled/import 19/0, independent review, rollback,
UNKNOWN/lost ACK, duplicate result/projection, reserves and real process contention.
SQLite uses the same table DDL and equivalent abort triggers; native MySQL runs the
actual forward SQL. Neither fixture applies migration083 or alters the old queue.

The remaining gates are real private manifest/mapping, actual private provider
adapters and capabilities, current required CI and independent source review,
separately authorized disabled installation/readback, then explicit campaign scope
and budget and zero-send dry run. A single live canary requires its own later
authorization. This PR does not claim those gates passed.

## Source review corrections after the first packet

The first native MySQL run41402913343 failed on reserved column name CURSOR.
The draft084 and every query now use stage_cursor; no production084 was applied.

Immutable raw input bytes are retained as cold evidence. Model requests never
receive the raw package. A separate required accepted_context projection binds
every declared historical scope to its accepted packet and receipt. The current
reader verifies the entire baseline chain and combines those historical records
with every new accepted delta. Assigned model views select only the requested
hotels and carry current baseline and derived view hashes. Required stage_contexts
supply bounded scope-specific criteria/corpus/map/archive-index/instruction
projections; assignments larger than256KiB HOLD before attempt creation.

The archives entry is a bounded approved index/projection, not a claim to hold
all original country ZIP bytes. Those original retained files must remain in the
separate private source inventory. The current loader still has a4MiB per-entry
limit; a real larger WORKING or raw archive cannot be silently sliced and called
a full input import. Full-byte staging/format conversion and its review remain a
launch prerequisite until actual inputs are available.

The registry also derives every producer without accepted intake from the
retirement snapshot. Full quarantine_mapping for those exact packet/receipt
identities is mandatory; missing mapping HOLD. Quarantined scopes are excluded
at assignment and acceptance, including completed-but-unaccepted TR40. Raw TR40
records cannot be included in the accepted projection.

tools/hotel_work.php now contains a dormant HotelServerCronRuntime hook before
the legacy hasActiveSubscriptions gate. Three trusted bootstrap flags
HOTEL_SERVER_CYCLE_ENABLED, HOTEL_SERVER_DISPATCH_ALLOWED and
HOTEL_SERVER_REPLACEMENT_DISPATCH_ALLOWED must each be boolean true. Missing,
null, strings or false return disabled before loading context/reading new tables.
No configuration is changed by the PR. A private ANYTOUR_HOTEL_SERVER_PROVIDER
instance must implement the real adapter; the runtime selects exactly one
persisted enabled campaign and never bootstraps or enables it. Active-mode error
is HOLD with no legacy fallback and no fabricated zero-call claim. Inactive old
subscriptions are no longer a prerequisite for a future server-only cycle.
