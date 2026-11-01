# HOTEL operation journal: dormant prototype

This is an isolated trial of server-owned admission and once-only canonical
snapshot acceptance. It is **not enabled in production** and does not establish
`BUSINESS_ACCEPTED`, native execution, a restored collection, or a live takeover.
There is no migration, runtime DDL, MCP registration, production factory wiring,
scheduler, launcher, provider request, or Library writer in this change.

## Authority and transaction boundary

`HotelWorkOperationJournalPrototype` receives the authenticated administrator and
OAuth connection from its internal server caller. A separate, random control
capability must be kept on the server-owned control path, outside worker prompts,
shared checkpoints and status responses. Workers receive only an individual
operation capability. An OAuth connection and a visible generation number alone
cannot create an intent, rotate control, admit another operation or commit data.
Server provisioning/storage of the control secret is not implemented here.

The fixture schema models four SQL tables: stage heads, operations, request ACKs
and immutable snapshots. It is created only by tests. InnoDB locks stage heads;
SQLite uses `BEGIN IMMEDIATE`. Each mutation and its ACK commit together. The
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

## Legacy mutation inventory and limits

The optional third constructor argument of `HotelWorkQueue` is a narrow test
bridge. No production factory supplies it. With the bridge injected, a new
generation-less `started` or `complete` on an enrolled stage is rejected inside
the Queue transaction. Exact existing immutable completion receipt readback
remains available and writes nothing; conflicting receipt replay still fails.

Other public legacy methods are outside this bridge:

| Methods | Existing role; prototype coverage |
| --- | --- |
| `create`, `claim`, `release` | Queue/lane/claim lifecycle; not converted |
| `handoff_connection` | OAuth ownership handoff; not converted |
| `resolve_existing_run`, `skip_research` | Recovery/advance/skip writes; not converted |
| `repair_delivery`, `pendingEvent`, `markEventDelivered` | Delivery cursor/recovery/ACK writes; not converted |
| `status`, `delivery_repair_preflight`, `handoff_preflight`, `eventCandidates` | Existing read/eligibility surfaces; not journal authority |

The existing `HotelWorkExecutionAttempts` mutations and the MCP adapter also
remain unchanged and are not converted to journal authority. Therefore this
prototype does **not** claim that all legacy bypasses are closed. A negative
integration test deliberately shows a default legacy Queue factory still
advancing its old metadata without producing a journal canonical snapshot.

SQL cannot fence arbitrary direct writes to an external Library. A negative
test allows a stale actor to overwrite a synthetic Library file and verifies
that the independently stored SQL canonical snapshot remains unchanged. This
is not proof of external write isolation. Current production Library readers
still read their old source and are not redirected to the journal pointer.

Production conversion needs a separate reviewed slice: provision control secrets
through a genuinely server-owned path; implement a production migration and
business validation; switch canonical readers; fence every legacy acceptance,
recovery and write path; and decide safe handling of existing UNKNOWN work.
Enrollment alone cannot serve as that conversion. No adoption or modification
of the current live queue, workers, CENTRAL intake, checkpoints, EG skip or
historical reservations is part of this trial.

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
