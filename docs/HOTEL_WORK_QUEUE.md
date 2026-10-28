# Hotel readiness queue

The production minute cron inspects bounded hotel-work metadata and sends a
separate MCP event only when the next stage is ready. It does not run a model,
read Library archives, query a supplier, or write hotel facts. The existing
coordinator remains responsible for choosing the original country worker,
checking its actual result, and admitting one CENTRAL intake.

## Authorization and installation

The existing authenticated resource remains `/manager/ai-mcp.php`. Hotel tools
and subscriptions require an explicitly consented `hotel_work:write` grant.
Existing `queue:read prepared:write` grants retain their previous capabilities;
refresh cannot enlarge them. A hotel-only grant cannot read reply contexts.

Deploy through the ordinary PR, required CI and sole production deploy owner.
Migrations 070–072 contain metadata only. Deployment preserves unrelated
crontab entries and installs exactly one line:

```text
* * * * * HOTEL_WORK_TRIGGER=cron flock -n /tmp/max-search-hotel-work.lock /usr/bin/php <root>/tools/hotel_work.php tick
```

Deployment then runs `php tools/hotel_work.php verify-cron`. This observer never
invokes the worker: it requires two completed future ticks with an observed
interval of 45–90 seconds. A preexisting checkpoint, a repeated read of one tick,
or two ordinary manual invocations do not satisfy this check. The installed
crontab sets a fixed `HOTEL_WORK_TRIGGER=cron` marker, which is captured in the
private health record and required by the observer. This is operational provenance;
an administrator who can alter the crontab can also forge it. This proves the
observed server schedule, not a connected model or completed research.

`php tools/hotel_work.php status` reads private bounded health without loading
application configuration. The same health appears in authorized
`hotel_work_status`. `awaiting_connection` means the server tick ran without a
usable hotel subscription, not that the research queue was processed and empty.
Health omits queue IDs, callbacks, secrets, hotel facts and customer data.

## Activate the existing owner

1. Rescan the connected MCP server's tools and events after deployment. Hotel
   tool metadata declares its separate hotel scope. With an existing reply
   connection, the implementation returns a tool-level OAuth challenge before
   storage access, requesting explicit consent for both reply and hotel scopes.
   This is the server contract, not evidence that ChatGPT displayed or accepted
   the linking UI. Verify the actual consent flow after deployment and keep the
   queue inactive until it succeeds. Deployment and token refresh do not enlarge
   an old grant. Invalid or revoked credentials still fail closed. After consent,
   confirm that both hotel events are discovered under the actual new grant.
   The challenge format follows the official authentication contract:
   https://developers.openai.com/plugins/build/auth#triggering-authentication-ui
2. Through the existing coordinator, call `hotel_work_create` with a unique
   queue ID and the independently checked current baseline SHA. Finite mode
   defaults to three batches. Continuous rotation requires `mode=continuous`
   and no `batch_limit`. Preserve the existing country-phase counter and choose
   the actual next country; do not replay an already accepted batch.
3. Subscribe the same owner to **both** `hotel_work.pending` and
   `hotel_work.attention`, filtered by `{project_key:anytour, queue_id:...}`.
   Each subscription uses its actual client-supplied callback and signed
   challenge. Only one active callback per owned queue/event is allowed.
   Keep `manager_replies.pending` and its subscription unchanged.
4. Verify a real `events/subscribe`, successful challenge and saved expiration,
   server tick, accepted signed delivery, exact queue claim and actual worker
   receipt. A discovery declaration or HTTP 2xx alone is not completion.

If the hosting UI cannot attach an event to the current owner, report its
actual capability limit. Do not invent a callback, target-thread field or hidden
hosted-status API. A new event must not silently replace the general roadmap's
hourly schedule or create a second hotel writer.

## Stage protocol

The queue rotates EG → TH → VN → TR, beginning at the explicitly selected
country. Each research stage is followed by one intake stage. Before any hosted
dispatch, the coordinator claims the exact current stage using a client-held
random claim key. `already_claimed_do_not_replay` is a reconciliation result,
not permission to invoke the worker again. After one supported dispatch it
attests the actual run ID with `hotel_work_started`.

The coordinator independently reads the saved packet and readback, checks
membership, old values, protected reservations and once-only ledger, then calls
`hotel_work_complete`. An intake must consume the exact preceding research
packet SHA. Every second intake also requires a checked criteria receipt.
Receipts are authenticated attestations; their hash format does not establish
the truth or contents of a remote file. Report processed, enriched, partial and
held results separately; a source-integrity hold is not a confirmed hotel fact.

A single shared HOTEL lane excludes concurrent queues, including queues owned
by different connections. UNKNOWN holds that lane and never expires into a
retry. Release is permitted only for an explicitly withdrawn unclaimed stage;
it does not cancel a hosted run. Reconcile uncertain external effects through
the existing owner instead of inventing a new run or releasing a foreign claim.

## Delivery and recovery

The server uses the canonical verified HTTPS webhook transport. Stable event
IDs and serialized bodies survive bounded retries; signatures have fresh
timestamps. Same-event backoff is respected, while a different ready stage
does not inherit the old stage's delay. Expiry, revocation, unsubscribe and
terminal callback failures stop delivery.

A delivered event with no claim after 180 seconds produces one separate
attention event with `dispatch_allowed=false`. A claimed or UNKNOWN stage also
emits a bounded attention event every 300 seconds after acknowledgement, so a
coordinator turn that ended before its child does not strand the queue. The
consumer must inspect only the existing exact run and push its verified receipt;
attention never authorizes research, intake, release, or another dispatch.
Duplicate acknowledgements do not postpone the watchdog, and a completed stage
clears it. An acknowledged callback is not proof the coordinator claimed,
researched, wrote or accepted anything.

`hotel_work_resolve_existing_run` closes an UNKNOWN result only with the retained
claim, original UNKNOWN receipt hash, and exact already-bound run and packet.
The original UNKNOWN receipt remains immutable; a separate append-only resolution
attests the later recovered result. An unknown dispatch with no established run
identity stays held; neither age nor an invented ID makes it safe to repeat.

After explicit hotel consent, `hotel_work_handoff_connection` may transfer the
same administrator's exact queue/stage/baseline to the current hotel connection.
Ready mode requires no claim or run and positive canonical proof that the old
binding is a known hotel grant of that same administrator, project and resource.
The old grant may still be active: moving this known idle queue does not revoke
credentials or change reply permissions. The shared lane and queue locks make
old-family claim and handoff mutually exclusive. A winning old claim blocks the
transfer; a winning transfer makes old-family operations fail ownership checks.
The stage ID stays unchanged, while delivery identity follows the new binding.
A connection that already relinquished this queue cannot reclaim it through
another handoff; fresh consent may use a new binding without revoking credentials.

Explicit existing-run mode still requires a canonically revoked or expired old
grant, the retained claim and non-null exact run ID. It preserves the state,
run, claim and UNKNOWN receipt and emits only attention. Missing or foreign
grants, noncanonical scope profiles, access/storage errors and client proof
booleans never establish ownership or retirement. Token expiry alone does not
retire a grant. Persist the nonsecret connection binding and stage metadata and
keep the claim key in the owner's private checkpoint. The new connection still
requires verified subscriptions; handoff does not create callbacks, expand an
old grant, start a run, or release a held operation.

For a queue already owned by the current connection, `handoff_mode=ready` with
`old_connection_id` equal to that current binding is a one-time delivery resume.
It requires the exact saved stage/baseline, a READY stage with no claim, bound
run or receipt, and an acknowledged unattended delivery. The owner must also
verify its private checkpoint has no unresolved claim/dispatch intent and that
no hotel worker is actually active. An uncertain operation remains held.

The existing lane/row transaction appends a same-binding audit record once per
queue/stage; it does not change ownership, stage, claim keys or hotel data.
That record gives both readiness and unclaimed-attention deliveries a new event
identity. Untouched stages keep their previous event IDs. The ordinary server
cron delivers the new pending event; the resume call never dispatches a worker.
Repeated identical requests return the prior result without resetting a newer
acknowledgement, deadline, claim or run. Real authority transfers still enforce
the relinquished-binding fence; existing-run self-handoff remains forbidden.

Before resuming delivery, confirm the existing handler supports the optional
`hotel_work_claim.expected_pending_event_id` input. A pending event must carry a
fresh trusted signed `eventId`, matching the status `expected_pending_event_id`
and the saved task/queue/stage binding. Pass that received ID to the claim call;
never manufacture it from status. For a resumed stage the server requires this
input and compares it with the current delivery identity under the same lane/row
lock as the claim, so a pre-resume handler cannot claim after the namespace
changes. Historical unresumed claim inputs remain compatible. Apply event TTL
and the existing once-only admission checks. Attention permits reconciliation
only.

A resumed READY stage can receive one bounded automatic metadata redelivery if
its pending and unclaimed-attention deliveries were both acknowledged without a
claim, run or current-stage receipt. The existing server tick waits at least
60 seconds after the attention acknowledgement. This is delivery backoff, not
proof that a hosted run or private operation is safe. Only a valid original
same-binding resume belonging to the current owner is eligible; ordinary legacy
deliveries and a real connection transfer do not acquire this retry.

The lane/queue transaction records a separate append-only delivery-recovery
audit, unique for that queue/stage. It retains the original resume digest,
previous pending and attention IDs, and the stored delivery/deadline/attention/
update times before clearing only the current delivery pointers. The original
handoff record is unchanged. A fresh generation has new pending and attention
IDs; the ordinary event service builds a new signed body with a fresh timestamp.
Retries of that same new delivery retain its exact body. An old event or late
acknowledgement cannot claim or acknowledge the new generation. The existing
claim input and its atomic event fence are unchanged; no tool refresh is needed.

The server refuses this recovery while any relevant persisted hotel claim, run,
UNKNOWN or current-stage receipt exists. It does not read the owner's private
checkpoint or hosted worker state. Before claiming, the same handler must still
check private unresolved intents, all existing hotel jobs and file reservations.
On every pending invocation, save and read back a sanitized admission decision in
the same private checkpoint: actual received field presence and source event ID
when available, supported route/schema evidence, exact failed guard or successful
admission, and the observed current stage. Do not persist secrets or claim keys
in that diagnostic. An uncertain checkpoint write or missing trusted event ID
permits no claim; status must never supply a missing received ID.

After this one recovery, another unclaimed acknowledgement may produce its one
normal attention event, then remains quiet. It cannot replenish its budget,
rearm the old resume, admit attention, relax event TTL or restart a worker.
A second safe HOLD exhausts recovery without proving the original failed guard
has been fixed. Runtime acceptance still requires the fresh signed pending,
one actual research run, the matching CENTRAL intake and independently verified
saved evidence for the same packet.

Once a recovery audit exists, a rollback must retain its namespace, budget and
atomic claim fence. Do not deploy an older implementation that ignores this
audit: it could recreate a previously acknowledged identity. Use a forward fix
that preserves the audit; never delete its rows, reset the queue or migrate down.

Finite completion releases the metadata lane and creates no fourth batch.
Continuous mode advances only after an accepted intake and its due criteria.
Neither mode modifies the legacy protected reservations or owns the actual
hotel business database. Stop future hosted triggers through the existing owner
when retiring the old rotation, after checking its exact current runs.
