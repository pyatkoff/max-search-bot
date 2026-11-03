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
packet identity, exactly 19 distinct accepted intake packets, and by default complete mapping
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

### Explicit continuation with unavailable originals

The owner may end recovery of specific unavailable legacy originals while retaining
the saved history. This is an optional, pinned input policy; it does not silently
relax the complete-history default or enable a campaign. Add a private manifest
file entry named `legacy_availability` with the same path/SHA256 rules as the
required inputs. Its UTF-8 JSON is bounded to 64 KiB and has exactly these fields:

| Field | Required value |
| --- | --- |
| `schema` | `hotel-legacy-availability-policy-v1` |
| `mode` | `available_confirmed` |
| `retirement_sha256` | The exact already-authorized retirement receipt hash |
| `owner_decision` | Nonempty bounded reference to the owner's decision |
| `unknown_legacy_overlap_acknowledged` | Boolean `true` |
| `new_acceptance_credit` | Integer `0` |
| `unavailable` | Nonempty list of exact unavailable historical identities |

Each unavailable identity has exactly `kind` (`accepted` or `quarantined`),
`stage_index`, `stage_id`, `packet_sha256`, `receipt_sha256`, and
`reason: original_unavailable`. Every identity must match the immutable retirement
registry, with no duplicates, guessed scopes or extra fields. A decision reference
records provenance; it is not an authentication capability. The existing explicit
bootstrap and enable authorizations remain necessary.

All nineteen historical acceptances and the full quarantine list remain in the
immutable input package. Complete semantic mapping and accepted-context coverage
are required for every remaining available acceptance; at least one must remain.
Unavailable entries cannot appear in accepted_context or continuous retained
coverage. Unlisted quarantine still requires complete mapping. The existing
earlier-fact authority, original variants/qualifiers, protected reserves and
incomplete/uncertain overlaps in available data retain their existing checks.

The policy acknowledges an unavoidable limitation: without an original's semantic
scope, duplication against that unavailable history cannot be ruled out. The
explicit policy applies only to the enumerated unavailable identities. It does
not waive other unresolved records, turn quarantined work into accepted data,
resolve CENTRAL41, or replay any invocation. No original or history is deleted.

Status and new acceptance receipts disclose `retained_admission`: available and
unavailable acceptance counts, mapped and unavailable quarantine counts, and
`duplicate_prevention: available_confirmed_scopes_only`. The historical count19
is not presented as19 restored packets. The initial import still earns zero
credit; only a new independently reviewed acceptance earns one. Policy bytes are
included in the immutable input hash and rechecked before work. They are not sent
to models. Synthetic cases exercise this mode through the existing loader and
producer/reviewer/accept/next-stage path in both SQLite and native MySQL CI.

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

### Forward handoff after a completed campaign

`HotelServerCycle::bootstrapNext(predecessorId, expectedBootstrapSha256,
expectedBaselineSha256, successorId, inputs, plan, authorized)` creates one new
**disabled** campaign. It makes no provider call and does not change the private
runtime, model, scheduler or old campaign plan. The ordinary `enable` operation
remains separate. A new ID and explicit authorization are required; this is not
an idempotent replay or a replacement for an UNKNOWN invocation.

One shared HOTEL transaction checks the exact current owner, predecessor
bootstrap/final-baseline CAS, terminal state and complete stage count. Every
producer/reviewer must have succeeded independently; candidates, reviews,
canonical records, receipt bytes, baseline chain and done work outboxes must
agree. Counts and full pessimistic reservations must match the bounded plan.
The transaction installs new immutable inputs and a compact derived seed,
inherits dynamic exclusions, disables the predecessor and moves the lane to the
disabled successor. Any failure rolls all of this back. Both remain disabled
until the operator installs the matching runtime campaign/bootstrap and invokes
the existing enable action. A crash in that interval cannot dispatch work.

The unchanged historical material is checked byte-for-byte: baseline,
accepted_mapping, quarantine_mapping, accepted_context, legacy_availability and
reserves, plus all retained parts and their interpreted identity, retirement,
registry and quarantine. Criteria, corpus, map, archives, instructions and
stage_contexts may change for the new assignments. Existing global semantic keys
still prevent recollection; inherited dynamic reserves remain exclusions. New
labels or dates must not be used to disguise a repeated factual question.

The canonical JSON seed has schema `hotel-server-continuation-seed-v1`, the
original `retained_baseline_sha256`, and:

- `predecessor`: campaign_id, bootstrap_sha256, baseline_sha256, inputs_sha256,
  and ordered receipts containing stage_index and receipt_sha256;
- `records`: all prior server acceptances, sorted by semantic_key, each with
  semantic_key, campaign_id, stage_index, candidate_sha256, receipt_sha256 and
  record_sha256.

Seed records are server-derived references to immutable canonical bytes. The
reader checks them against original candidate/reviewer/receipt/baseline/outbox
evidence; the seed cannot certify its own acceptance. The seed is at most 1 MiB
and is the successor's sole initial baseline row, with `parent_sha256=NULL`.
Earlier deltas stay in their original campaigns. The existing ten-delta walk now
ends at that campaign's own root, so repeated handoffs do not accumulate an
unbounded delta chain. The accepted view still includes original historical
facts plus every verified prior and current server acceptance. Import earns zero
new credit; each successor starts its own new_accepted and budget reservations at
zero, while all predecessor counts and evidence remain unchanged.

Let `seedBytes=HotelServerInputs::json(seed)` and `seedSHA=sha256(seedBytes)`.
The successor's bootstrap pin is:

```php
hash('sha256', HotelServerInputs::json([
    $newInputsSha256,
    $plan,
    ['continuation_seed_sha256' => $seedSHA],
]))
```

The returned disabled projection supplies this pin and the seed baseline SHA.
Before enabling, the private operator must independently read back those exact
bytes/pins and CAS the old runtime configuration to the new campaign/bootstrap.
The source API never performs that filesystem write or assumes it succeeded.

Pending catalog projections retain their original payloads and receipt identities.
`HotelServerLane::acknowledgeProjection` is a fixed operation under the same HOTEL
lock: it permits the current owner or an exact complete-and-disabled predecessor,
verifies the canonical receipt and baseline against the payload hash, and only
marks that exact projection outbox done. It does not relax `own`, reserve a lane
or permit an old tick. The existing projector retains catalog-commit-first and
lost-ACK replay ordering, so a handoff cannot strand a previously committed
projection or create duplicate acceptance credit.

The existing SQLite/native-MySQL cycle suite covers two successive handoffs after
a ten-stage campaign, preserved partial-history disclosure, changed task inputs,
inherited facts/reserves, exact CAS, UNKNOWN and unfinished evidence rejection,
atomic rollback, forged seed pins, and catalog lost-ACK replay after handoff.

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
identities is mandatory in the default complete-history mode; missing mapping
HOLD unless the exact unavailable identity is explicitly covered by the policy
above. Known quarantined scopes are excluded
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

## Concrete existing-provider integration

HotelServerAiProvider now uses ManagerDraftClient::requestHotelJson on the existing
bounded Responses transport, with separate producer/reviewer schemas and private
response/usage lineage. An optional local-only preflight runs before UNKNOWN or
budget persistence. No provider is registered and flags/config remain unchanged.
Supported research_mode is explicitly assigned_retained_excerpts, with exact
hashed/capture-timed source slices and complete independent review coverage.
Fresh-web acquisition and budget-policy certification are explicitly absent
contracts, with owners in HOTEL_SERVER_AI_PROVIDER.md; they are not hidden behind
a readiness stub or fabricated source/cost claim.


## Explicit correction of a known rejected final stage

`HotelServerCycle::correctRejectedFinalStage(campaignId, correction, authorized)`
adds a separately reviewed answer correction. It performs no provider call and
never calls `tick`, creates a campaign, changes an enable flag, refunds or expands
a reservation, changes a deadline, or writes runtime/cron configuration. The
private authenticated caller must supply explicit authorization. Actor names and
decision references in the evidence are provenance, not authentication tokens.
New principal IDs use 1–256 ASCII characters: an alphanumeric first character,
then alphanumerics or `_.:/@-`; whitespace aliases are rejected.

This operation is deliberately limited to an enabled campaign in HOLD at its
last planned stage. The preceding stage count and original two-calls-per-stage
reservations must be complete. Both current attempts must have succeeded with
known results and done outboxes. Provider rejection, UNKNOWN, incomplete review
coverage, another rejected scope, a nonfinal stage, stale pins or conflicting
accepted/protected scope all refuse the transaction. The original negative
review must cover each assigned scope once: the target is false and every
unchanged scope is true. No old negative result is rewritten as an approval.

The correction document has schema `hotel-server-correction-v1`, a `proposal`
and a `review`. The strict proposal supplies:

- campaign/stage, bootstrap, inputs, current baseline and plan pins;
- original producer/reviewer attempt IDs and exact request/result hashes;
- the target four-field scope and replacement answer;
- `context_sha256` for the canonical assigned scope/dependencies object,
  global `criteria_sha256`, and the assigned source URL/excerpt SHA;
- `author_principal` and `authorization_ref`.

The supplemental review has schema `hotel-server-correction-review-v1`, a
separate `principal`, `proposal_sha256`, boolean `approved: true`, concrete
`evidence` and `review_reference`. It approves the exact answer-only
transformation of the pinned original producer result. Its principal must differ
from the correction author and original producer. The caller is responsible for
establishing those actual independent identities; changing a label is not an
independent review. Approval of a loose wording example does not approve a
different or unhashed proposal.

Only the target answer changes. Its original scope, evidence, URLs and quotes,
and every other record remain identical in canonical bytes. Target evidence must
still quote the exact retained excerpt at the pinned URL. This is a correction
against existing assigned evidence, not fresh acquisition or a claim that the
source is valid on future travel dates. The original producer/reviewer requests,
results, candidate and negative review remain unchanged.

Under the existing HOTEL/campaign lock, one transaction appends immutable
`hotel_server_corrections` evidence, the corrected canonical records, baseline,
`hotel-server-correction-acceptance-v1` receipt, one acceptance credit and the
catalog outbox. It moves the last stage to `complete` without another producer
outbox. The receipt links the original candidate and attempts to the correction
and supplemental-review hashes. No partial writes survive an exception.

The correction validates the exact current baseline document and scoped immutable
input identity without walking accepted predecessor history. It does not query
earlier canonical records, traverse B01/B02 campaigns or read retired-queue tables;
current baseline bytes are checked only against the pinned boundary. Previously
accepted data remains unchanged. This bounded mutation does not weaken the normal full-history reader
or handoff checks: those readers understand the new receipt and independently
reconstruct the original-to-corrected candidate plus both kinds of review proof.
The catalog acknowledgement similarly checks the correction ledger and complete
new receipt instead of accepting an additional schema name alone.

An exact repeated correction can return the already committed receipt, after
verifying its complete current-stage lineage, without another credit or write.
A different proposal/review at that campaign/stage conflicts. This idempotence is
not authorization to replay a consumed operational command. The private operator
must retain the original mutation receipt and obey the existing operation guard.

Migration089 creates only the empty append-only correction ledger and its update/
delete rejection triggers. Deploying this source does not apply a correction or
change any live campaign. Existing cron health and `verify-cron` remain outside
this change; data correction completion is established by the new receipt, not
inferred from an unrelated successful deploy or worker heartbeat.
