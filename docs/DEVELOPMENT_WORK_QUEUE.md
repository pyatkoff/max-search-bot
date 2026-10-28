# Development plan metadata queue

The model-free production minute cron checks a bounded queue of explicitly
authorized development tasks. A signed readiness event wakes the existing
general coordinator; the cron does not call a model, dispatch a hosted worker,
edit code, merge, deploy, read customer data, or change the hotel queue.
Actual development still uses the existing owners and their original tools.

## Authority and immutable tasks

The canonical plan and each original workstream remain the source of task
scope and acceptance criteria. This queue stores metadata, not another roadmap.
A task contains its immutable ID, original owner task ID, distinct independent
reviewer task ID, input SHA-256, acceptance-contract SHA-256, priority and prior
dependency IDs. The coordinator must retain the actual approved input and
contract privately and verify their bytes before admission. A digest alone is
not permission, saved-file evidence, a source reservation or a publisher lease.
Owner/reviewer IDs and distinct run strings are not actor authority. Before
arming delivery, verify the actual already-authorized owner and independent
reviewer routes and the host's declared execution/event capabilities. An
unsupported route is a blocker; never infer a native pairing, wake a disabled
owner, message a new recipient or clone an executor. Record a pre-dispatch
capability blocker in the canonical plan without claiming or enqueueing it as
ready. The terminal-blocked completion API is only for an already-bound run.

Create one queue for the existing general coordinator after reconciling its
actual current runs, delivery intents and existing plan. New tasks are appended
against the exact current plan digest and a separately approved new revision;
existing definitions and results cannot be changed. Dependencies must identify
an earlier task in this queue (including earlier in the same append), preventing
cycles and missing references. Only accepted dependencies satisfy readiness.
Duplicate IDs, conflicting repetitions and stale plan compare-and-swap fail
closed. An identical creation/append returns existing state without replay.

Do not import already completed work as a new task, clone an occupied source,
or dispatch a currently active owner again. Active existing runs remain with
their current owner. The initial queue may observe an already active original run only when the
explicit approved immutable input names that exact known run and the original
owner permits this handover. This is reconciliation of the existing operation,
not another dispatch. All other active or unknown overlapping work prevents
admission. A product dependency that requires a session, a separately
admitted private read or new user authority remains blocked until that precise
input exists. Minute checks do not authorize browser access, new data capture,
customer sends, spending, new workers, new credentials or a policy change.

The initial rollout serializes two admitted coordination/control tasks at the
existing general coordinator. It is not an exclusive scheduler for all five
product directions. Independent original workstream owners and their already
authorized work continue; do not pause them to obtain the development lane.
The queue permits one admitted development WIP across its owned queues.
Hotel research and its own shared HOTEL lane are unaffected. The existing
external source registry and the sole ready publisher remain mandatory:
acquire the publisher only when current-head review and required CI are ready,
then hold it through normal installation and explicit terminal release.
A metadata claim does not acquire or replace either external reservation.

## Existing coordinator protocol

1. Receive the actual trusted HOST-routed signed development pending event.
   Match its received event ID, queue, task, spec digest and original owner
   against fresh server status and the saved private checkpoint. Apply the
   declared event freshness policy; server claim deadlines are not event TTL.
   Never obtain a missing received ID from status or manufacture routing proof.
2. For a new dispatch independently prove exact terminal/idle state. For an
   explicitly admitted observation-only handover, match only its already-known
   actual run without dispatch. Check actual original-owner binding,
   unresolved private delivery/dispatch intents, relevant source/file/resource
   claims and external publisher rules. A server READY row is not proof that a
   hosted task is idle. Save and read back the exact once-only dispatch intent
   using the existing private checkpoint's version guard before admission.
3. Claim with the client-held random key, task/spec and the received
   `expected_pending_event_id`. The required identity comparison happens
   atomically under the shared development lane and queue locks, before the
   idempotent branch. An `already_claimed_do_not_replay` response means inspect
   the original operation, never dispatch again.
4. Start the one admitted original owner with its already-authorized scope and
   record the actual supported run ID with `development_work_started`.
   If the task owner is the same coordinator consuming the event, perform the
   admitted work in that current event turn and bind its actual supported HOST
   run. Never invoke `run_now` on itself or create another worker for a demo.
   A human/hourly observer turn cannot substitute for a fresh pending event;
   missing actual event-run binding remains a precise capability blocker.
   A self-owned worker turn saves its result/checkpoint and ends without
   attesting that its own HOST run is terminal. A later reconciliation attention
   turn verifies that original run's terminal result and independent review,
   then completes it once. It never substitutes the observer's current run.
   An uncertain delivery is not repeated. Do not infer terminal from age,
   last-updated time, disabled future triggers or unavailable transport.
5. Match that run's terminal result to the exact task/input/contract. Check the
   actual saved immutable result and readback, the original independent review
   and its distinct actual run, required code CI/install/release where the
   contract calls for them, and release of the task's own claims. Retain the
   exact source head/tree/current integration, dated review disposition and
   original explicit RELEASE/lease readback when applicable; a boolean alone
   is not independent proof. Only then
   attest `accepted_readback` with result/readback/acceptance digests.
6. After one accepted completion, an already-approved next task may be appended
   as metadata only, using the real accepted receipt as its frozen input,
   deterministic task ID and durable append intent with a fresh plan CAS. An
   unknown append outcome is reconciled by exact identity/hash, never a new ID.
   End that event pass; the next ordinary server tick produces a separate new
   pending for the next task. Never tail-dispatch it from the old event.

The server accepts authenticated attestations, not remote artifact truth.
The coordinator's independent bytes and acceptance checks are indispensable.
A valid hash, callback HTTP success, active task, source release or green CI
alone never proves the requested product scenario was accepted.

`terminal_blocked` requires the exact bound run's verified terminal result,
saved result/readback and released own claims. It does not satisfy dependencies.
Another independent ready task may then be selected. Unknown dispatch, run,
save, external effect or release outcome must be `unknown`, which retains WIP
without expiry or automatic replay. A later resolution can attest either accepted completion or verified terminal
blocked, using only the retained claim, original UNKNOWN receipt digest and
exact already-known run. The original receipt and any non-null result anchor
remain immutable. A blocked resolution releases WIP for an independent task
and never satisfies the blocked task's dependencies. Missing run identity stays
held; never invent a replacement.

Receipts and resolutions are append-only and duplicate completions cannot
advance twice. Private claim keys, callback secrets, internal plan instructions,
customer records and raw worker outputs stay outside public source/status.

A proven capability refusal before dispatch uses `development_work_retire_unclaimed`,
not a fabricated worker run or completion. The controller and independent
reviewer must first check the private once-ledger, unresolved delivery/dispatch
intents, actual original-owner runs and external leases. Any uncertainty holds.
Save/read back the exact retirement intent and decision evidence before calling.
The server locks lane then queue and requires the exact task/spec, current plan
CAS and delivery identity with no persisted claim, run, receipt, resolution or
UNKNOWN. This delivery ID is a generation compare-and-swap guard, not signed
received evidence and never permission to dispatch. The decision digest,
readback and actual independent reviewer run are attestations, not boolean proof
of private state. One append-only retirement audit records the blocked task;
identical retries reconcile without a second transition. The next independent
task receives its own fresh ordinary event. A claim that wins the lock first
prevents retirement; a retirement that wins prevents old claims/acknowledgements.
Status reports worker receipts and unclaimed retirements separately.

## Events and installation

Existing connections use the authenticated `/manager/ai-mcp.php` endpoint and separately
consented `development_work:write` capability. Reply and hotel grants keep their
current meaning. Discovery can advertise the new capability but does not grant
it; refresh tokens cannot enlarge a grant. Development step-up preserves only
already-consented scopes and adds the explicit development capability. Hotel
step-up retains the original reply+hotel profile for a reply grant and preserves
already-consented development capability if present.

### Isolated development connection

A new connection may use `/manager/development-mcp.php` with the fixed issuer
`/manager/development-oauth.php`. Its resource and consent allow only
`development_work:write`. The issuer's discovery document is served at
`/.well-known/oauth-authorization-server/manager/development-oauth.php`.
This endpoint exposes exactly the eight existing development queue tools and
the two development events. It rejects hotel, reply and archive operations
before dispatch. It reuses the canonical queue, ledger and signed event
transport; it does not create a parallel scheduler.

The original endpoint, issuer and five supported capabilities remain available
to existing connections. Development clients are registered in a separate
fixed client namespace. Authorization codes, refresh tokens, consent sessions,
connection lists and revocations are bound to the selected resource and client
profile. A request to the wrong issuer cannot consume a code or refresh token,
or revoke the other profile's grant. Refresh never transfers a connection,
changes its resource or expands its scopes.

The ordinary development event sender resolves only a stored grant's canonical
resource into one of these two fixed profiles and freshly validates the grant
before delivery. A storage failure, unreadable resource or unknown profile
defers delivery; it is not treated as proof of revocation. HTTP callers cannot
select this background authorization mode.

A newly consented development connection has its own identity. It cannot see,
claim or adopt a queue belonging to an older connection, even for the same
administrator. Installing this endpoint does not register a new app, transfer
credentials, revoke an old grant, rebind a queue, change frozen task inputs or
clear any pending operation with an unknown outcome. Keep an existing queue
and its current connection in place until its actual acceptance or a separately
reviewed migration. Native event-configuration failures need authoritative
reconciliation independently of this source change.

Normal installation smoke verifies both fixed resource metadata URLs and both
exact issuer discovery routes over HTTPS, without redirects. It sends only a
known-invalid bearer, requires the matching resource challenge, and checks a
trusted endpoint-specific diagnostic category for header delivery. A legacy
probe cannot stand in for a development probe. This proves installed HTTP
routing and rejection, not production consent, native event registration or
automatic task acceptance. Those require the separate runtime evidence below.

Subscribe the same existing coordinator to both `development_work.pending`
and `development_work.attention`, filtered by project `anytour` and the exact
queue ID, through the host's supported native event configuration. The actual
client supplies its verified HTTPS callback and signing secret. Do not invent
callback URLs, hidden APIs, worker bindings or dispatch permissions. Keep reply
and hotel subscriptions unchanged. Do not create a second controller to work
around unsupported event attachment.

The common webhook transport retains challenge/HMAC/HTTPS protections. Delivery
leases exclude concurrent senders, retries retain the exact serialized event
body and ID with a fresh transport signature timestamp, backoff belongs to that
event and revocation/expiry/terminal callback failures stop delivery. A new task
does not inherit an older task's delivery delay. Delivery acknowledgement is
not a task receipt.

Unclaimed acknowledged readiness produces attention after its claim deadline.
Claimed and UNKNOWN work emits bounded reconciliation attention after the
watchdog interval. Attention always has `dispatch_allowed=false`; it permits
inspection of the existing exact operation, never a new run, retry or release
of claimed/UNKNOWN work. An independently verified pre-dispatch refusal may use
only the bounded audited unclaimed-retirement operation described above.
No metadata timeout proves an external operation failed.

Normal PR/current-head independent review/required CI/sole publisher installation
applies. Migration 074 stores metadata only and is forward-only. Deployment
preserves all unrelated entries and installs exactly one development line:

```text
* * * * * DEVELOPMENT_WORK_TRIGGER=cron flock -n /tmp/max-search-development-work.lock /usr/bin/php <root>/tools/development_work.php tick >/dev/null 2>&1
```

`verify-cron` observes two different completed future ordinary cron ticks
45–90 seconds apart; it never invokes a tick. Manual invocations, old records
or repeated reads of one tick do not prove a working minute schedule. The
marker is operational provenance controlled by the administrator, not an
unforgeable clock or guaranteed exact execution time.

Private bounded health omits queue/task IDs and payloads. The CLI `status`
reads it without application configuration. `awaiting_connection` means
the scheduled server pass has no usable development event subscription;
it is not proof that the task queue ran or is empty.

Existing supervision continues while runtime progression is unaccepted.
A hosted task cannot combine event triggers with a time schedule; use a
supported handover of the same coordinator to event-only operation. Do not
assume that nullable native fields document schedule clearing, invent sentinel
strings or create a second controller. Preserve the already-existing separate
oversight while proving the new route. See the official
[scheduled-task contract](https://learn.chatgpt.com/docs/automations?surface=app).
Any retained separate discovery may only reconcile/read or ask the existing
operator to admit a genuinely new task through its private CAS/server guards;
worker dispatch still requires a fresh actual pending. No task is requeued
merely to demonstrate an automatic transition.

Before replacing the old general coordinator's hourly trigger, verify the same
owner's actual binding and event support, preserve its prompt/permissions/
once-ledger and check exact current runs. Keep future duplicate dispatch off
only after a supported single-owner event handover and readback. Installation
or a nominal schedule alone is insufficient.

Runtime acceptance requires an ordinary cron tick, accepted signed pending,
one actual original-owner run, independently saved/read-back acceptance,
one completion receipt and a separate ordinary pending for the next eligible
task at the same owner. If no product task is currently ready, report that
precise limitation and keep unattended monitoring; do not seed fake completed
tasks or claim that synthetic tests prove a live product transition.

A rollback must retain claims, UNKNOWN states, immutable task identities and
receipt/resolution/append/retirement history. Never delete audit rows, migrate down, reset a
held operation, replay work, remove the protected external-log boundary or
weaken the existing source/review/install gates to make the queue advance.

## Private status authorization diagnostics

The existing `development_work_status` and `hotel_work_status` inputs and
successful owned-queue responses are unchanged. After fresh canonical
administrator, connection and required-capability authorization, an absent
queue and a queue unavailable to that same caller return the identical tool
result: `ok=false`, `reason=OWNED_QUEUE_UNAVAILABLE`,
`queue_available=false`. This is an availability diagnostic, never a receipt
or permission to create, claim, rebind or retry work.

That private result includes only the current authenticated connection's
`caller_self`: its opaque connection and client identifiers,
`active_project_admin=true`, and boolean capabilities for reply preparation,
hotel work, archive research and development work. Values come from the fresh
canonical stored grant, not supplied arguments or the queried queue. No queue
existence, identity, state, owner or foreign connection is disclosed. Do not
copy this private self metadata into public diagnostics or release receipts.

Invalid or stale caller context on those status tools retains RPC code
`-32001` with the stable message code `AUTH_CONTEXT_UNAVAILABLE`; no self
identity is returned. A valid context lacking a capability that is ineligible
for the existing consent challenge uses `AUTH_SCOPE_UNAVAILABLE`. Eligible
missing-capability calls keep their existing `mcp/www_authenticate` challenge
and exact requested scope profile. Tool discovery, refresh and this diagnostic
never grant a capability. No connection, queue, subscription or worker is
created by a status read.

Missing or invalid HTTP bearer credentials are rejected before the MCP service
and retain the existing HTTP 401 OAuth challenge. A status diagnostic does not
supply credentials or bypass that boundary. Authorization is checked again
before any tool result leaves the service.

## Private development subscription metadata

The existing `development_work_status` result adds `subscription_setup`
without changing its input or the existing queue fields. It reads only the
two fixed development event names for the canonical current administrator,
OAuth connection and owned queue, with fresh authorization and ownership
checks before and after the read. The read requires autocommit and makes no
callback request or changes to subscriptions, delivery acknowledgements,
claims, tasks or receipts.

`available=true` means the metadata read succeeded. Each event contains its
name, state, matching-row count and nullable technical fields:

| State | Meaning |
| --- | --- |
| `absent` | A successful scoped read found no matching row. Eligibility is false; verification, retry and HTTP details are unknown. |
| `active_verified` | One active, unexpired row has a valid past callback-verification timestamp. |
| `expired` / `inactive` | One well-formed row is expired or explicitly inactive. It is ineligible. |
| `ambiguous` | More than one matching row exists. Eligibility, verification, retry, HTTP and expiry fields are all unknown. |
| `unknown` | Metadata is malformed or unavailable. Nullable fields are never inferred as false. |

For one well-formed row, `eligible` describes its current server-side active,
expiry and recorded-verification state. `verified` describes that stored
callback-verification timestamp. `retrying` describes a stored retry marker
on an active row; it does not prove the current task was attempted.
`last_http_status` contains only a recorded HTTP code from 100 through 599;
a null value or transport status0 provides no HTTP result. Expiry and the
observation time are UTC. These fields are metadata attestations, not a
worker result or proof of current callback reachability.

The projection filters project, administrator, connection, queue and both
event names before its limit. It reads at most 21 matching metadata rows and
fails closed with `available=false`, `reason=row_limit` and unknown event
entries if more than 20 are returned. Storage failures or a custom store
without this diagnostic produce `available=false`, `reason=unavailable`.
A read never returns callback addresses, signing material, callback or
consumer identifiers, event payloads, claim keys, raw errors or foreign rows.

`subscription_setup` explicitly uses the semantics
`server_subscription_metadata_not_native_commit_proof`. It can reveal
partial server registration, but cannot establish whether an external
task-service operation committed its full trigger configuration. It does
not authorize retrying an unknown setup operation, dispatching from status,
or bypassing the fresh signed-pending and independent acceptance protocol.

A rollback of the isolated development surface must preserve the original
endpoint and all grant, subscription, claim and audit histories. Contain new
admission through a reviewed forward change; do not remove authorization or
reconciliation needed by existing new-profile connections, silently transfer
their ownership, or restore an older version that weakens protected boundaries.

## Bounded subscription registration observations

For an authenticated real development queue, the existing status projection
adds `subscription_setup.registration_trace`. This is a bounded observation
of the existing shared MCP diagnostic log, not a queue-correlated audit or a
native task-service transaction receipt. Its fixed semantics are
`observations_only_not_queue_binding_or_native_commit_proof`.

Only the fixed protocol categories `tools/call` and `events/subscribe` are
added to the existing diagnostic method allowlist. For subscription calls,
`event_name` is restricted to `development_work.pending` and
`development_work.attention`; a missing or null name is `absent`, and every
other name is `other`. A non-subscription row cannot carry an event name,
including when a persisted row is sanitized again. No arguments, identity,
queue ID, callback URL, credential, nonce, exception text or stack is exposed.
The existing time, numeric result and other fixed categories remain bounded.

An opt-in coverage projection reads at most 512 KiB and returns at most 20
sanitized records. `available` reports successful access to that bounded
log window; `coverage` is `bounded_log_tail` or `unavailable`.
`tail_truncated` reports whether the initial file size exceeded the byte
window, while `sample_truncated` reports more than 20 valid observations
inside that window. Both are unknown on failed access. The fixed `reason`
is null, `unavailable` or `read_error`. A readable empty window remains
available; neither it nor an unreadable window proves that no RPC occurred.
The historical no-argument snapshot retains its top-level two-field contract.

The trace is read before the canonical subscription metadata getter. That
getter must still perform its fresh administrator, grant and queue ownership
checks even if the technical log read fails. An authorization failure
suppresses the entire status result and trace. Callable test stores do not
implicitly acquire access to the shared log. The trace does not authorize
repeating an unknown configuration operation or dispatching from status.

The normal deployment HTTP smoke also makes one fixed anonymous request to
`purchase-study-api.php?view=operational` and requires HTTP 401 with the
existing unauthorized marker. It sends no authentication, reads no protected
snapshot and establishes neither ordinary cron provenance nor model quality.
