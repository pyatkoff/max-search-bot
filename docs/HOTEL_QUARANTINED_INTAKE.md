# Same-claim CENTRAL intake after quarantine

## Prepared onward continuation

Migration089 and the internal `HotelCollectionCanonicalService::adoptQuarantined`
bridge the first independently accepted original intake into the existing journal.
The exact domain receipt and accepted WORKING bytes must match, the original
queue identity/counters must advance exactly once, and the next stage must have
one existing held event and no receipt or execution attempt. Protected inventory
cannot omit a key retained by the original intake. Adoption adds no acceptance
credit, claim, native run, queue, delivery resume or OAuth change.

One transaction imports the complete immutable accepted WORKING, creates the
canonical baseline/index, reserve policy and next-stage journal head, switches
to journal control and changes that same held event to pending. The queue's
original baseline remains unchanged; the separate data baseline pins the exact
accepted WORKING. Opaque legacy variants are retained byte-for-byte. Their IDs
are reserved against new credit, without inventing country/hotel/source-evidence
joins. Unmapped old sources cannot become typed foreign-key evidence. Full
scoped WORKING reads remain available through the existing server reader.

`persistent_fence=1` remains sticky after `journal_v1`. Forward triggers permit
only exact before/after business hashes or a new exact receipt bound to the
current database session and caller-owned transaction. Permits are deleted
before commit. Queue deletion, historical receipt rewriting, marker downgrade,
fence removal and cached old SQL remain blocked after adoption and later cycles.
This database fence still does not cancel a native run or fence Library/host APIs.

The existing dormant cron runtime accepts an optional private `continuation`
plan: exact acceptance/WORKING pins, request ID, protected inventory/attestation,
existing producer task and approved next scope/source-packet identity. Both
strict dispatch flags govern adoption and all later sends. Missing continuation
keeps the previous HOLD behavior. With a configured plan it prepares one durable
next-stage intent, waits for fresh delivery of the existing pending event, then
uses the existing native adapter once. UNKNOWN is never resent. A recovery plan
becomes inert as soon as its next stage advances; it cannot repeat TR40/CENTRAL41.
On the next producer's admitted candidate plus immutable reported native run,
cron seals the stored result, waits for a separate fresh reviewer pending event,
dispatches the existing CENTRAL once, and waits for its independent verdict.
An accept verdict finalizes against the server-stored assignment revision/head;
HOLD, reject, missing run, missing bytes or UNKNOWN never starts another producer.
Both flags govern sealing and finalization as well as sends. The normal path
retains criteria cadence and atomic acceptance/queue/outbox. This recovery plan
stops after that following cycle and cannot infer further country scopes.

Tests use synthetic data in SQLite and the allowlisted native MySQL database:
two adoption rollback boundaries, lost ACK, exact receipt/snapshot/reserve pins,
one next event and producer send, old SQL concurrent with protocol adoption,
continued fencing through the following independent acceptance, and object-shaped
legacy question history. No production plan or activation is installed.

Dormant code in PR1261, no live application or native execution. This is a narrow
continuation of the original claimed intake, using its already completed and
server-receipted producer packet. It never creates a queue/claim, repeats research,
changes OAuth, resumes delivery or announces that the original handler stopped.

## Stage the original bytes

The server-only `HotelQuarantinedIntake::stage` requires the existing queue to be
continuous, quarantined, claimed, current_run_id=null, at the original odd stage,
with criteria not due. It compares the retained original claim, stage, owner,
baseline/plan and counters with the preservation barrier. A due-criteria change,
existing run, owner/claim change or unavailable database barrier means HOLD.

The exact handoff SHA must equal the quarantine pin. The original preceding
research receipt must already exist, with its original accepted packet/run and
receipt hash. No synthetic research completion is generated. The source packet
must identify the existing queue, preceding stage, actual country and frozen
WORKING SHA. This supports the saved TR40 shape; non-empty legacy read-update
shapes currently HOLD instead of guessing their merge semantics.

All referenced delta, ledger, source-read, admission, release and evidence members
are stored verbatim and checked against the sealed handoff. Unexpected/missing
members, changed delta, mismatched counts/IDs, foreign hotel scope, source FK,
protected/reserved overlap and prior import/acceptance are rejected. Scope uses
the existing hotel_index and the separately approved protected inventory. The
oldvalues, quotes, periods, limits, failed reads, source observations and original
budgets stay in the original packet; staging does not reinterpret them as facts.

One immutable stage row stores source bytes, source provenance, frozen WORKING
pin and a new independent reviewer capability. It does not change the claim,
business queue, producer receipt or accepted count. Exact replay returns the same
private receipt with no fresh authority. Public MCP has no stage/finalize API.

## Existing cron and existing CENTRAL

Staging seeds one protected outbox event for **the already claimed intake**.
`HotelWorkQueue::pendingEvent` exposes it through the existing signed cron path;
delivery acknowledges only this outbox row. There is no second claim or resume.
The coordinator/Automation adapter uses the existing review_ready route and
existing CENTRAL ID, preserving both strict dispatch flags and the exact host
binding gate. No producer event is sent. Missing native binding still means HOLD.

`HotelCollectionCronRuntime::configureQuarantinedIntakes` is a dormant trusted
bootstrap hook for this path. A private pinned plan stages once, waits for signed
delivery, dispatches CENTRAL once, waits for the immutable verdict and finalizes
once. Both strict dispatch flags govern every step. Hold/reject, waiting, UNKNOWN
and already accepted rows do not resend. No plan is installed by this PR; there
is no new timer. Waiting ticks use the sealed stored packet instead of repeatedly
hashing unchanged source archives. Concurrent ticks still pass the transactional
dispatch/finalization guards. The next stage is never adopted by this hook.

Transport consumes one delivered permit before the socket. UNKNOWN does not
refund it. `hotel_work_review_context` binds one fresh CENTRAL instance using its
new review capability; old claim/literal tokens, producer/control capabilities,
foreign owners and duplicate instances do not admit review. The old handler's
native outcome remains UNKNOWN and does not become the new reviewer identity.

`hotel_work_quarantine_read` provides byte-cursor/pointer reads of the exact
handoff, all sealed members, frozen WORKING and, after acceptance, the accepted
server snapshot. The context lists mandatory reads: the complete packet/member
bytes and scoped hotel/index/knowledge records including every old variant and
unclaimed source for those hotels. Unrelated WORKING/archives are not re-read.
The server tracks sequential complete byte delivery. An accept verdict requires
all mandatory bytes, but byte delivery is explicitly not semantic review proof.
CENTRAL must still independently check every new assertion, oldvalues, source
scope, provenance, contradictions, HOLD/PARTIAL and limitations, with no web.

One immutable verdict binds the original packet SHA, winning instance and a
distinct reported reviewer run. It cannot use the producer or original handler
run. Hold/reject cannot finalize; criteria must remain null for this path.
Run strings and capabilities do not supply independent native HOST attestation.

## Atomic acceptance, with the old writer still fenced

`finalize` is internal control only. It checks fresh ownership, the original claim,
frozen WORKING and source/decision pins, current barrier and protected scope. It
appends raw claims/sources with original IDs/fields and origin wrappers, updates
the hotel index, preserves all existing variants/objects/history and appends the
original question outcomes, one import and one acceptance. Ledger revision moves
once. WORKING is a new immutable **server** snapshot, labelled
server_snapshot_protocol=quarantined_intake_v1; Library is never overwritten.
Original producer counts remain separately labelled and no hash self-cycle is
created by embedding the resulting snapshot hash into its own bytes.
Legacy aggregate/publication metadata remains inherited baseline history; the
explicit new receipt and server stage/counter are authoritative for this intake.
General onward readers/adoption remain held. Origin pointers address actual
zero-based elements of the preserved packet arrays, without rewriting record IDs.

Migration088 replaces two quarantine triggers without editing087. A private
transaction-local certificate is keyed to the actual database session, original
stage/claim and exact receipt digest/target counters. The receipt INSERT trigger
requires this certificate. The queue UPDATE trigger permits only the exact
claimed-intake -> next-ready transition, after that receipt exists. Queue identity,
plan/baseline, owner and quarantine protocol remain unchanged. No generic bypass,
session-variable switch or unquarantine is exposed.

Canonical bytes, intake receipt, accepted counter, phase change and same-queue
advance commit together. A failure before or after receipt/certificate creation
rolls everything back. The certificate is deleted before commit and is invisible
to concurrent old sessions. A cached/delayed old PHP writer and raw SQL still
cannot modify the protected queue/history. A real separate-process race checks
the old writer waits for this transaction then fails after commit. Exact final
ACK replay returns the stored acceptance without another mutation or credit.

For the retained CENTRAL41 snapshot the intended result is the same queue at the
next stage, 19 -> 20 accepted batches, with the original claim hash retained in
the quarantine record. The next event is **held**, the next stage remains under
the quarantine marker and no next research starts. General onward adoption is
separate work. Status reports delivery_state=quarantined, no legacy pending ID
and no legacy claim/automatic-next-dispatch permission. The old handler remains
UNKNOWN; native/Library privileges are
outside this canonical-effects fence. A delayed old host call may still affect
availability; this path does not claim host termination or native isolation.

## Verification and future application

Targeted regressions use synthetic legacy packets with the real TR40 structure,
actual forward MySQL migrations and SQLite. They check source/member changes,
protected scope, current owner/capability/instance, mandatory byte coverage,
single CENTRAL dispatch, both rollback boundaries, acceptance replay, history/
variants/empty-object preservation, permanent old-write rejection and a real old
writer race. They do not publish private packet bytes or claim live acceptance.

After a separately authorized release/application, control must stage the retained
original packet and evidence against fresh affected pins, obtain confirmed native
binding and explicit enablement/dispatch authorization, then have CENTRAL review
and control finalize once. No production import, quarantine, native settings,
queue, timers or accepted batches are changed by preparing or testing this PR.
