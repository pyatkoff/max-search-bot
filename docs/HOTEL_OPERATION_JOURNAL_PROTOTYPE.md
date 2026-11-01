# HOTEL operation journal: dormant prototype

This is an isolated trial of server-owned admission and once-only canonical
snapshot acceptance. It is **not enabled in production** and does not establish
`BUSINESS_ACCEPTED`, native execution, a restored collection, or a live takeover.
Forward migration `082_hotel_operation_journal.sql` is prepared but not applied.
No live enrollment, runtime DDL, scheduler, launcher, provider request, or Library
writer is performed by this journal. Collection APIs are documented separately.

## Authority and transaction boundary

`HotelWorkOperationJournalPrototype` receives the authenticated administrator and
OAuth connection from its internal server caller. A separate, random control
capability must be kept on the server-owned control path, outside worker prompts,
shared checkpoints and status responses. Workers receive only an individual
operation capability. An OAuth connection and a visible generation number alone
cannot create an intent, rotate control, admit another operation or commit data.
Server provisioning/storage of the control secret is not implemented here.

The prepared schema models four journal tables plus the sticky `write_protocol`
marker in the existing queue row. Tests alone create their isolated schema. Every
InnoDB operation locks HOTEL lane, then current queue, then journal head; SQLite
uses `BEGIN IMMEDIATE`. Enrollment validates current owner, lane, stage, baseline
and idle state under those locks, and commits marker and head together. Each
mutation and its ACK commit together. The
same authorized request and inputs return the stored receipt; reusing a request
with different inputs fails. A stale capability/generation fails before replay.
Lost handover ACK recovery requires possession of the new control secret.

The randomly generated worker capability is retained in the private, immutable
intent ACK inside the same transaction as its hash and operation. Only a valid
control caller replaying that exact request can recover the original capability
after a lost ACK, without generating another key or logical operation. Enrollment
itself uses caller-provisioned control and generates no worker key; its lost ACK
is also covered by a replay test. No raw secret is included in public status.

1. Internal enrollment requires an owned, ready, unclaimed legacy stage and the
   same baseline. Adoption of an already claimed/UNKNOWN stage is unsupported.
2. `intent` atomically records a stable event, operation and private worker key.
3. `dispatchIntent` persists uncertainty before an external send. It does not
   send anything. Its ACK always has `dispatch_authority=false`; replay cannot
   authorize another external call. A crash after this commit remains UNKNOWN.
4. `admit` binds one instance to the operation. Replaying its ACK is readback,
   not a second work grant. Another instance is rejected.
5. `result` stores the exact JSON bytes containing `schema_version`, `hotels`,
   `delta` and `ledger`, at most 256 KiB. Hashes or Library references alone are
   insufficient. The worker may submit one candidate; conflicts are rejected.
6. A control-plane reviewer retrieves those stored bytes with `readCandidate`.
   `commit` checks the reviewed hash and expected canonical pointer, and saves
   the snapshot bytes, pointer, operation outcome and receipt in one transaction.
   A unique queue/stage acceptance and unique queue/packet constraint reject a
   second intake, including simultaneous commits on different stage heads.
7. `canonicalSnapshot` reads the protected SQL pointer and verifies stored bytes.
   `status` returns a bounded history of 50 operations, total count and explicit
   truncation, without capabilities, their hashes, or private ACK contents.

The journal preserves `native_effect=unknown` even after a canonical snapshot
commit. Before dispatch intent, handover can retire a prepared operation; after
dispatch intent, handover retains the unknown effect and blocks replacement.
There is no expiry, refund, new nonce or implicit cancellation. Generation change
is a fence for this service, not evidence that an old hosted computation stopped.

An optional reported native run ID/evidence hash is only caller attestation;
`native_run_verified` stays false. Existing workers can compute candidates
without a new exact-history API because once-only canonical acceptance is a
separate server property. This does **not** guarantee once-only computation or
cost. There is no production retry policy or autonomous dispatcher here.

## Sticky legacy mutation boundary

`HotelWorkQueue` enforces the queue row's `write_protocol` on each first write,
even for a raw Queue, an object created before enrollment, or a factory called
with false. A factory/config flag cannot downgrade an enrolled queue. Enrollment
is internal and explicit; it refuses a schema without the marker and does not
adopt claimed/UNKNOWN work. The marker and head commit/roll back together.

All 14 public mutation paths, including the execution-attempt trait and cron's
`pendingEvent`, are inventoried in `HOTEL_JOURNAL_MUTATION_INVENTORY.json`.
`pendingEvent` is a mutator and may emit dispatch authority even without SQL
writes. Protected queues block both; the event service treats this as nonfatal
HOLD, preserving subscription activation and frozen delivery evidence. A callback
already in flight cannot be cancelled by SQL locks; its later claim/ACK is fenced.

Exact existing receipt readback retains its original actor/request checks and
never authorizes new work. Entry replay keeps `work_authorized=false`. Normal
legacy rows preserve their behavior without requiring an optional journal store.
Marked rows with missing/corrupt journal state fail closed. Read-only status and
preflights remain diagnostic, not admission. All actual server factories use the
same intrinsic Queue policy; optional test callbacks add checks rather than
being the protection boundary.

This does not fence old deployed binaries, privileged direct SQL, or external
Library ACLs. Any authoritative collection consumer must use the separate
canonical collection service with no Library fallback. The original synthetic
journal snapshot is not a WORKING converter or business acceptance. Existing
live queue/worker/checkpoint adoption and production migration application remain
outside this dormant change; no hotel stage is launched here.

## Verification

`tests/hotel_work_operation_journal_checks.php` runs directly and is included by
the existing Queue regression. It exercises lost claim/intent, admission and
commit ACKs; duplicate event/intake; conflicting candidates; same-OAuth stale
workers and generation handover; independent saved-byte readback; legacy bridge
rejection/read-only receipt replay; and fail-closed UNKNOWN handling.

Fault injection verifies transaction rollback at intent, dispatch, admission,
candidate, snapshot, pointer and receipt boundaries. Two child processes exit
before/after dispatch-intent commit. Four actual two-process SQL races cover
admit/admit, commit/commit, commit/handover and the same packet across two stages.
These are local SQL/fixture tests, not HOST or live-business verification.

The same fixture supports the existing allowlisted native MySQL test database
environment; SQLite passing does not establish MySQL passing. Required CI stays
unchanged. This change must remain dormant while integration remains incomplete.

The existing queue regression also executes `hotel_work_journal_boundary_checks.php`
on both SQLite and the native MySQL wrapper. It covers intrinsic raw/cached factory
protection, all new legacy mutations, receipt-only replay, missing-store behavior,
enrollment rollback/lost ACK, and six deterministic two-process enrollment races
against claim, handoff and release in both orders. Event regressions cover nonfatal
HOLD and enrollment during an already-in-flight synthetic webhook. Prepared DDL
is exercised in test databases only. Local PHP/MySQL execution is not assumed;
publication CI results must be inspected before claiming PASS.
