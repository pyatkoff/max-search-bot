# MAX channel attribution

Owner decision, 2026-10-15: select the latest known ClientID; use the actual
occurrence time of each conversion event, reconciling tracker timezones separately.

## Selection

`MaxSubscriptionAttributionPolicy` accepts MAX subscription candidates normalized
by the SELECT-only tracker adapter. Match the exact positive external MAX user ID and
effective counter. A nonempty `UF_METRIC_SOURCE` takes precedence over `UF_SOURCE`;
an unknown explicit override must not fall back to another counter.

Select the highest canonical decimal tracker record ID with a valid nonempty
ClientID. This matches the existing `ORDER BY ID DESC` tracker read. A newer empty
record does not erase a previously known ClientID. IDs remain strings, including
values larger than signed 64-bit integers. Conflicting copies of the winning
record fail closed. Observation time is optional metadata, not an ordering key.

The latest known ClientID may have become available after the event. Do not add a
pre-event ClientID cutoff to this owner-selected rule. Runtime enrichment persists the resolved attribution once per event; retries must retain
that resolution and the original event time.

## Event time

Use the original message/manager-response event time, not queue insertion,
enrichment, retry or subscription time. Convert the instant to Unix seconds.
The ClientID selection policy never reads the clock or changes this timestamp.

Read-only source/clock inspection on 2026-10-15 confirmed:

- tracker FPM `date.timezone=Europe/London`, OS timezone `Europe/London`;
- Bitrix timezone conversion disabled, timestamp writers construct Bitrix DateTime;
- `UF_DATE` and `UF_SUB_DATE` are DATETIME without a CURRENT_TIMESTAMP default;
- MySQL uses SYSTEM/BST and was UTC+3600 during the capture; latest tracker time
  was consistent with that local clock;
- the conversation trial verified its message/start database clock was UTC.

Interpret tracker wall timestamps as `Europe/London`; never subtract a fixed hour.
London uses BST in summer and GMT in winter. For timezone-free historical values
inside a DST overlap, require an explicit offset or other reliable disambiguation.
Reject nonexistent local times rather than silently normalizing them.

Evidence: [tracker time inspection](https://github.com/pyatkoff/tour-max/actions/runs/38726287142),
[conversation trial](https://github.com/pyatkoff/max-search-bot/actions/runs/38724100239).

## Runtime flow

Migration `037_max_channel_conversion_outbox.sql` creates only feature settings
and its event outbox. Initial mode is **off**. It changes neither subscription
records nor existing Metrika goals or counters.

The canonical `MetrikaConversionGoalService` supplies conversation context to
`MaxSearchApi::queueMetrikaGoal`. For an eligible MAX conversation in collect/send
mode, that method captures the first recorded occurrence in the SQL outbox.
Other calls retain the existing YCLID path, including Telegram and CRM goals.
The owner selected the three existing dialogue goals on 2026-10-16.
The ClientID transport uses their original identifiers and numeric goal IDs.
Historical channel identifiers remain attached to their original receipts only.

Eligibility requires project `anytour`, channel `max`, a non-test conversation,
exact positive `customer_channels.external_user_id`, and the matching negative
internal chat ID. Entry must have either a registered canonical MAX publication
or an existing project-owned MAX source with `handling_mode=manager`. A tracker
identity match does not itself prove channel subscription or a post entry.

| Existing identifier | Goal ID | Recorded event time |
| --- | --- | --- |
| `max_bot_activity_start` | `611089509` | First accepted customer message, callback or contact after entry/activation |
| `max_manager_request` | `600725752` | First durable `waiting_manager` event after the shared-goal cutover |
| `max_customer_reply_after_manager` | `605075686` | First customer message/contact after the manager message; callbacks excluded |

A request means a committed handoff, not an attempted greeting or a manager reply.
The post-commit hook performs only a local capture. An independent, bounded
`waiting_manager` event cursor recovers missed hooks and outer transactions.
The customer-after-manager stage retains waiting-manager/manager-message guards.
The existing destination exclusion also applies. Timestamp ordering uses
`created_at,id`, so a later message in the same second is retained. UTC is
verified before capture. The mirror's `messages.created_at` is the persisted
accepted-event clock; a raw provider timestamp is not currently persisted by the
recorder. No queue/enrichment timestamp is substituted for it.

`cron_metrika.php` runs a bounded worker before checking the old CSV queue. The
existing scheduler therefore handles channel conversions even when that CSV is
empty. Customer/manager requests perform no new HTTP calls. A 50-message cursor
and a separate 50-request-event cursor recover missing direct captures; each event
has a unique project/conversation/goal key. The original activation and message
cursor are preserved. The new request stage has both a cutover timestamp and an
immutable event-ID boundary, preventing a pre-cutover request in the same second
from being uploaded later.

`MaxTrackerLookupRepository` authenticates before connecting, opens a read-only
MySQL transaction, selects only the requested MAX identities and correct effective
counter (`YA_METRIK_ID`, IBlock 8), and rolls back. It requests at most 25 users.
`UF_PLATFORM` is a device field in this MAX-only table and is not a messenger
filter. It returns the latest valid ClientID by numeric tracker ID; invalid/empty
newer IDs and wrong counters cannot replace it. A missing identity retries later.
The first successful resolution freezes ClientID and tracker record for the event.
Tracker observation time is optional metadata and never replaces event time.

### Prospective membership receipts

Migration `068_max_membership_receipt_bridge.sql` freezes one installation-time
boundary and performs no backfill.  After that boundary, the same signed lookup
may request the exact MAX membership history for the already selected users and
counter.  The tracker reads the existing HL10 history by exact chat and source;
type1 opens a subscription period and type2 closes the currently open period.
The source's timezone-free `UF_DATE` is interpreted with the established
`Europe/London` DST contract.  Current membership state, the legacy export flag
and the time a Yandex goal was exported are not substituted for history.

The lookup returns at most20 receipts per user and100 per response.  At1799
seconds it can return only the subscription.  At1800 seconds, a complete exact
history window returns `retained` unless a matching leave was recorded by the
check instant; an earlier leave returns `not_retained`.  Wrong-counter rows,
oversized histories and ambiguous clocks fail closed.  Rejoins create distinct
periods.  The query remains SELECT-only and rolls back its read-only transaction.

The existing worker cycles through at most25 already-admitted prospective MAX
people from the existing conversion outbox.  Its cursor is bounded work progress,
not an information fence, and wraps only after the current suffix is exhausted.
This lets a join after ClientID resolution and a late producer correction become
visible without inventing a new queue, worker or schedule.  A failed membership
observation does not advance the cursor and falls back to the identity-only lookup
for pending ordinary conversions, so the optional observer cannot stall delivery.

`MaxMembershipReceiptJournal` binds those receipts to an existing non-test
AnyTour MAX conversation only when external user and negative chat ID match.  It
writes the two versioned event types `channel_subscription_observed` and
`channel_retention_30m_observed` into the existing `conversation_events` journal.
If stored eligible post-fence conversations contain multiple dialogue episodes
for one person, no episode is guessed and the receipt remains unassigned; this
check is not limited to the transient lookup batch.
The journal persistence time is the receipt's recorded time; normal journal
intake supplies its later first-observed availability.  Exact repeats are
idempotent, while conflicting later evidence is preserved as another receipt and
never overwrites the first.  The existing worker performs bounded cyclic
observations of admitted people; there is no new queue, worker, cron, API endpoint
or conversion goal.  Observer failure cannot manufacture a membership fact,
block identity resolution or cause a conversion POST replay.

## Tracker authentication and release

The lookup is a separate endpoint at
`https://tour-max.ru/new/max-attribution/lookup.php`. Existing lead delivery is
untouched. Read-only preflight found no readable retained lead-bridge config at
the tracker root. The old deployment credential also failed authentication.
Consequently this implementation uses a dedicated RSA/SHA-256 signing key, with a
purpose-bound body, a 90-second validity window, exact counter/identity validation
and strict response/body bounds. It does not reuse or copy another service token.

The private signing key belongs outside the canonical bot webroot:
`data/private/max-attribution/signing.pem`, mode 0600 in a 0700 directory.
`php tools/max_attribution_signing_key.php init` creates it once and emits only
the public verification key; an existing key is preserved. Keep that output in a
private operator/runner temp file. The tracker receives only that public key.
Requests require HTTPS certificate verification and never follow redirects.
Public endpoint responses are no-store; authentication failures expose no IDs or
SQL/config details. Signed requests are read-only; replay within the small time
window cannot mutate subscriptions or trigger conversion uploads.

Build a public-code-only release with:

```sh
python3 tools/build_max_attribution_lookup.py /absolute/operator-temp/lookup-release
```

The installer validates the release manifest and public key, pins the established
tracker SSH identity, and writes only `/tour-max.ru/new/max-attribution/` using
`github-audit@158.160.185.1`. It exports no tracker data or database credentials:

```sh
python3 tools/install_max_attribution_lookup.py \
  --bundle /absolute/operator-temp/lookup-release \
  --public-key /absolute/operator-temp/verification.pem \
  --ssh-key /absolute/operator-temp/audit-key \
  --known-hosts /absolute/operator-temp/pinned-known-hosts
```

The host key must match
`SHA256:WuqyxyQM6A4Sd0ZLddDBF1g/90VD2NFMdOmTjpSX2XQ`. No installer or key creation
runs automatically during deploy or CI. The release contains four PHP code files,
one public manifest and the separately supplied public key; never put secret
config, raw samples, private keys, logs or backup copies under `/new`.

## Explicit activation and receipts

After authorized publication, migration and tracker release:

1. Run `php tools/max_channel_attribution.php probe`: signed read-only probe,
   no conversion request. Confirm actual tracker PHP/FPM supports PDO MySQL/OpenSSL.
2. Run `php tools/max_channel_attribution.php collect`: start fresh collection
   and resolve identities without any Metrika upload. Check aggregate `status`.
3. Run `php tools/max_channel_goals.php status` to inspect the exact selected IDs.
   The explicitly authorized `apply` command changes only their single condition
   from `contain` to `exact`, preserving names, IDs, prices, favourites and other
   attributes. It uses PUT `/goal/{id}`, never creates or deletes goals, and checks
   the full before/after goal list. A missing/ambiguous goal or an uncertain PUT
   stops the operation; inspect with GET before any further mutation.
4. Run `php tools/max_channel_attribution.php shared` in collect mode after exact
   goal verification. Migration `038_max_channel_shared_goals.sql` adds a frozen
   `transport_target` to each row and explicit shared-goal activation settings.
   The transition changes only never-attempted pending/ready activity/customer
   rows to the old identifiers. It retires never-attempted manager-reply rows.
   Accepted, uploading and unknown rows keep their target, event time, ClientID,
   claim and receipt. Previously attempted retries retain their frozen targets
   and stay held if that target belongs to the historical channel goals. All
   future conversion POSTs require one of the three selected old identifiers.
5. Run `php tools/max_channel_attribution.php send`. It verifies all three exact
   identifiers and pinned existing IDs with GET, and requires shared activation.
6. Review a natural entry and the event/ClientID match privately; observe actual
   upload IDs followed by processing status. HTTP acceptance is separate from
   validated rows, session attribution and Direct optimization.

Until shared activation, new code performs no conversion POST and creates no
new canonical markers for managed conversations. The existing message cursor
remains available to recover activity after the short deployment interval.
Already accepted conversions are never replayed into the selected old goals.
The three `max_channel_*` goals and their history are retained without new
manager-reply captures; exact upload-ID/comment GET recovery continues.

Temporary CSV files also stay under the private feature directory (0700/0600)
and are removed after each request. Uploads contain `ClientId,Target,DateTime` and use the existing counter/token.
Every attempt claims the event atomically before POST. Successful receipts store
Metrika upload ID; subsequent checks use GET. A transport timeout, 5xx, malformed
response or crash after claim moves the event to **unknown**, never to resend.
GET-only reconciliation uses an opaque deterministic upload comment. Explicit
rate limiting or a proven pre-send DNS/connect failure can retry with backoff.
Recovery reads at most 300 upload records; an older/unfindable outcome stays held
for operator review. The comment is a recovery marker, not an API idempotency key.

The live BASIC upload API can omit `client_id_type` in both upload listings and
individual receipts. Receipt validation permits that omission, while rejecting
explicit values other than `CLIENT_ID`, an explicit non-`BASIC` type, malformed
IDs and unknown statuses. Polling requires the exact stored upload ID; lost
acknowledgement recovery requires one unique exact deterministic comment. This
compatibility check never resets an unknown event to ready or repeats its POST.
An event captured after the worker tick starts waits in ready until the next tick
before being claimed; its occurrence time and resolved identity remain frozen.

A single tick resolves at most 25 queued events, uploads at most five, and polls at
most three accepted plus three unknown events. Stage uniqueness and SQL claims
protect concurrent workers. Pending/ready events older than 21 days expire.
PROCESSED with one source row and one validated row is tracked as processed;
LINKAGE_FAILURE is tracked as unmatched. Neither status claims a sale or a change
in advertising performance.

`off` stops future worker work/capture through this path; in-flight requests may
finish. Keep receipts and the applied migration. Do not drop tables or replay
unknown uploads. Turning the feature off returns future calls to existing YCLID
behavior. Recorded canonical goal markers remain, preventing an old event from
being queued again.

## Existing goal compatibility

The original 2026-10-15 readiness probe found two legacy `contain` goals and no
`max_manager_reply` goal. The first rollout created three dedicated exact
`max_channel_*` goals. A full actual list read on 2026-10-16 found 77 goals and
confirmed the three old IDs selected by the owner above. The new selection
replaces manager **reply** with actual manager **request**; those events must
never share a relabelled timestamp.

Yandex requires exact JavaScript conditions for offline conversions
([official guide](https://yandex.ru/dev/metrika/ru/management/offline-conv)).
The owner-authorized correction retains the selected IDs/history and verifies
that the only configuration change is `contain` to `exact` on those three goals.
The other 74 goals, including `g1_dialog` and the historical channel goals, remain
unchanged. Direct campaign selections are not edited. No CRM implementation or
historical conversion replay belongs to this change.

## Verification and current status

Required regression covers the pure policy and durable capture/worker behavior.
The native integration workflow runs the same queue cases on MySQL 8, tests
read-only tracker selection and independent connection claims, then exercises the
real PHP HTTP endpoint with signed requests and fixture database rows. It uses
isolated fixture data and no production secrets or external conversion requests.

Publication and real MAX conversion activation were explicitly approved by the
owner on 2026-10-15 (coordination #55, comment 6194132633). PR #958 includes
the current notification/sound release as its base. This document does not assert
that the lookup is installed, the mode is enabled, a production conversion has
been accepted, or a natural event has been attributed. Those require explicit
release/activation evidence recorded in #55.

