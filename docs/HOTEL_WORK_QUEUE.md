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

Finite completion releases the metadata lane and creates no fourth batch.
Continuous mode advances only after an accepted intake and its due criteria.
Neither mode modifies the legacy protected reservations or owns the actual
hotel business database. Stop future hosted triggers through the existing owner
when retiring the old rotation, after checking its exact current runs.
