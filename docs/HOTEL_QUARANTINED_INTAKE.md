# Same-claim CENTRAL intake after quarantine

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
separate work. The old handler remains UNKNOWN; native/Library privileges are
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
