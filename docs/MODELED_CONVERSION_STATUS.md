## Private owner delivery registry

The canonical closed page is /manager/assigned-value.php?view=delivery, also linked as «Конверсии» from the existing administrator area. The link is hidden until current administrator identity is accepted and hidden again on refusal. The server independently requires an active existing admin, AnyTour project access and the same current session actor before and after reading. Ordinary managers do not gain access. There is no new role, named-login exception, grant, password or manager-workspace entry.

The default page reads actual recorded rows for counter98615635 / targetanytour_modeled_value_v1 / contractsemantic_stage_value_25000_v1. Former members/profiles views remain explicit legacy research views and are not the delivery ledger.

- Day means the saved assignment/export event clock (`event_unix`) in Europe/Moscow. This differs from the existing daily upload-claim, first API-observation and processing-observation cohorts.
- One row is one conversation: name, platform, selected-day delta, lifetime reserved value under the same contract and strongest selected-day stage. It is not a proven unique tourist or verified trip.
- Expanded rows contain the up to six real stage awards, current state/allowlisted hold reason, origin, independent lifecycle clocks and exact frozen-profile metadata. A saved-stage profile is not a quotation or fresh interpretation of the transcript. Missing or invalid profile grounds remain unavailable.
- Day totals and visible rows share the same exact project/counter/contract/current binding/test/internal-owner predicate. Invalid stage/target/state/origin/value anywhere in the selected-day scope withholds all totals before the first page.
- API-receipt and processed values overlap. Receipt acceptance, successful upload processing and individual advertising-visit linkage remain distinct. Legacy unknown acknowledgement clocks are never backfilled from claim/updated time.
- Pagination reads 25 conversations with a signed cursor bound to current session/actor, date, platform, upper ID and event cutoff; expires after one hour. States and authorization are fresh per read. Upper ID/event cutoff does not freeze late commits or later scope changes across separate requests.
- The separate HTML reader has a 512KiB/5s budget, four first-page/three subsequent bounded SELECTs, read-only transaction and post-transaction authorization. Existing MCP limits/scopes remain unchanged.
- HTML escapes private names, exposes no advertising/native peer identifiers or raw profile JSON, performs no network calls and does not open the workspace detail/read-marker API. The page uses GET forms and a manual refresh only.

### Monitoring provenance and regularity

The primary control now uses the real main-server cron. The owner-private
external_monitor object preserves all former external fields and adds a nested
pipeline status. Local and external clocks, roles, histories and notification
times remain independent. Local freshness never refreshes the external check.

The local reader accepts only the bounded private activation.json and typed
pipeline-checks.json: role=pipeline, origin=server_cron, distinct advancing real
cycle IDs, valid source SHA and clocks. It never reads runtime-state.json or the
generic runtime-observations file. Three healthy original cron receipts within
fifteen-minute gaps and a fresh last receipt establish pipeline regular=true.
Manual invocations, generic tests, GitHub jobs and copied IDs do not qualify.

Pipeline business alerts and external host/heartbeat alerts reuse the same
canonical prepare/send implementation with strict role/flag isolation. The
external GitHub timer remains best effort; source configuration and postDeploy
success do not prove its cadence. The page must display each status honestly,
including stale or unknown evidence, without treating one as the other's proof.

# Private verification of saved early assessments

The `get_modeled_conversion_status` MCP tool verifies a bounded window of
already saved AnyTour profile versions. It is read-only and accepts no filters,
identifiers, paths or query parameters. It requires a current administrator,
current AnyTour project access and an active OAuth connection explicitly granted
`forecasts:read`. Existing archive, reply, hotel or development consent does not
authorize this operation. Refresh never adds this scope.

## What the result establishes

A valid packet in `modeled_conversion_profile_versions` proves that a version
of the contextual signals and early assessment was persisted. The result
summarizes saved packets, their versions and actual freeze times, observed
goal events, early versus already-observed outcomes, and initial versus updated
observations. The joint confirmation requires a valid early assessment and
goal events in the same saved version. Engagement-only observations are not
counted as goal-bearing packets. Counts describe inspected saved versions, not unique customers,
trips, purchases or calibrated probabilities. A first assessment of a
conversation does not prove that the person or inquiry is new.

The reader does not rely on the latest processing receipt as proof of historical
saves: an idle tick overwrites that receipt with a zero batch. It reads immutable
derived versions at a fixed fence and reports the inspected window and omissions.
An empty, oversized, stale, malformed or version-mismatched window cannot prove
that no assessments exist elsewhere. The full source corpus is not read.

The last recorded processing error includes its historical timestamp; it is not
reported as the health of a later successful tick.

The tool neither interprets customer text nor measures predictive quality.
Current readiness remains uncalibrated and provisional. Probability and value
stay null, and export remains disabled. Existing purchase-study state and its
separate outcome contract are not read or changed.

## Bounds and privacy

Metadata is inspected before copying the complete saved forecast and signals
subdocuments, with the fixed parent bindings needed to validate their origin.
Unrelated factual profile fields are not copied. At most 20 metadata rows are
selected and at most four fitting saved packets are inspected. Packet copies
have a 48 KiB budget within the shared 64 KiB selected/copied-data budget,
including authorization metadata. An oversized packet is explicitly omitted rather than
truncated or interpreted. These are data-transfer bounds, not a claim about
physical database pages or rows examined internally by the engine.

One five-second execution budget is shared across authorization, reader queries and the
final authorization check. MySQL SELECTs use the remaining execution time;
checks before and after work reject an exhausted budget. The budget is not a
guarantee about network latency or initial database connection establishment.
The serialized JSON-RPC response, including both text and structured content,
is bounded to 64 KiB.

Only fixed aggregate fields, versions, timestamps and bounded reason categories
leave the server. No message text, person/conversation/source ID, evidence
reference, individual hash or arbitrary saved field is returned. Authorization
is checked again before release; a revoked connection or project access does not
receive previously computed results. Responses are private and no-store.
Diagnostics contain only existing fixed protocol health, never result contents.

## Verification and operation

Synthetic tests exercise actual derived-profile reads, malformed/oversized and
legacy packets, limited coverage, deadline and byte exhaustion, revocation,
denied grants, scope step-up and complete response privacy. Existing native
MySQL and required repository checks remain part of acceptance.

After technical installation, current explicit OAuth consent and a successful
private read are still required for natural-data acceptance. Report actual
returned timestamps and counts only in the authorized private conversation.
Do not publish private runtime research results in GitHub issues, Actions logs
or public diagnostic artifacts. Do not generate customer messages, run a
manual tick, reset collection or replay archives to manufacture proof.

## Authorization failures and working connections

The bounded forecast request is recognized before credential validation even when
its bearer is missing or malformed. Its initial unauthorized response asks only
for `forecasts:read`; unrelated legacy requests retain their established challenge.
A valid connection missing the forecast capability receives the existing exact
scope-upgrade challenge through both HTTP 403/WWW-Authenticate and the MCP error
metadata. The response does not add permissions to any saved grant.

A storage failure during the initial forecast bearer lookup returns HTTP 503
without an OAuth challenge. Response-level deadline or serialized-size failures
also return 503 and remove any already prepared challenge, including when a
deadline expires during diagnostic logging. Later dispatcher or reader failures
retain their explicit existing error contracts; they do not imply invalid credentials.
This avoids requesting repeated consent for these technical failures. Diagnostics use
existing fixed status/stage/error fields and never include credentials, grant
identities, requested scope strings or private forecast aggregates.

These changes do not modify client registration, consent issuance, token refresh
or revocation, existing connections, queue ownership or subscriptions. Do not
reconnect an operational plugin as a diagnostic retry: a new consent can create a
new grant and does not transfer an existing queue. Preserve the separated DEV
resource and its connection. Use the already authorized read and distinguish
its actual returned result from a connector message saying authentication was
accepted.

An isolated native MySQL regression joins the real migrated OAuth tables,
budgeted bearer validation, default MCP dispatcher and saved-version reader.
Its read phase uses a read-only MySQL session and compares persisted fixture
rows before and after; unavailable-storage cases are confined to its disposable
database. Successful synthetic execution does not replace private natural-data
acceptance after installation.

## Targeted saved-stage verification

Reader version `saved_forecast_status_v3` first nominates at most one saved
version for each fixed stage: general purchase intent, current-agency purchase
intent, and conversation payment evidence. Readiness prefers the latest matching
`early_assessment` prefix, falling back to the latest matching observed prefix.
Payment matches only `payment_self_report`, `manager_payment_ack` or
`purchase_supported_candidate`; payment links, terms, promises, documents and
booking confirmation alone do not establish payment.

One bounded aggregate query captures a global version-ID fence and those three
nominees. Strict integer goal counters from 1 through 800 are nomination metadata,
not proof. All subsequent metadata and packet copies remain at that fence.
At most three unique target IDs consume metadata slots first. Remaining slots,
within 20 total selected metadata rows, use one latest customer-context version
per conversation scope (platform, customer key and conversation). A scope without
a strict integer customer-turn count from 1 through 50 uses its latest version
as an explicit fallback. Context-bearing baseline candidates precede fallbacks.
The baseline does not filter for any goal or outcome.

Targets precede baseline candidates for copying, with version-ID deduplication
before the four-packet allowance. Different historical prefixes of one
conversation may prove different stages; `distinct_conversation_scopes`
counts those scopes once. Raw metadata rows, unique candidate versions and
duplicate candidate rows are reported separately. This is goal-targeted
diagnostic selection, not population sampling or prevalence estimation.

Each stage reports candidate presence separately from fully validated saved
evidence. A nominee omitted by byte/row limits remains uninspected; malformed,
unsupported or event-inconsistent nominees do not become proof. Validation
still reconciles source namespace, scope, parent bindings, freeze times, fence,
event-derived goal counts and the uncalibrated forecast contract. It never
recomputes labels from source messages and does not search older versions just
to replace an invalid selected candidate.

Validated stages expose the saved forecast status and whether it was an
`early_assessment`. Readiness in an already-paid prefix is retrospective,
not an early prediction. Conversation payment reports and manager acknowledgments
are not independent bank settlement confirmation. Current agency means the
agency addressed in that booking context; an operator name alone (for example,
Anex) is not proof that the tourist selected this agency.

Each inspected packet's saved extractor code fingerprint is classified
`current`, `other` or `unknown`, without returning the hash. A stable
rule/state version does not imply identical rules after a compatible patch.
Older saved readiness is not silently revalidated by corrected short-answer
rules. Newly observed natural prefixes use the deployed code through the
existing collector; no manual tick or archive replay is part of this read.

An older initial version may remain outside the selected window, so first-marker
linkage can be explicitly unverified. Frozen prefixes do not prove current
willingness or unique tourists/trips. An absent stage means no eligible saved
nominee was found at the fence, not that no such real dialogue or purchase exists
in the archive. Archive semantic research is a separate read-only interpretation;
its ephemeral labels are not persisted live signals or early forecasts.


## V1 launch readback on the same granted tool

After the contextual V1 installation, the default store of the existing
get_modeled_conversion_status tool is ModeledConversionForecastStatusReader,
returning saved_semantic_prediction_status_v1. The name, empty argument
schema, forecast scope, OAuth admission, repeated final authorization and
shared64KiB/five-second budget remain unchanged. No connection is replaced.
The older V3 reader remains available in source/tests but this operation does
not invoke its historical targeted-stratum query or retry its consumed read.

The new operation takes a fixed newest20 immutable AnyTour MAX/Telegram profile
version suffix before JSON inspection. Only semantic_purchase_heuristic_v1
blocks with the exact installed model and processor hashes can be copied.
One latest matching version per scope is inspected, at most four, within48KiB
raw projected packets and the shared64KiB selected-query/protocol budget.
Metadata without a matching V1 namespace is not a source-payload read.
Selection is neither a corpus scan nor a population/cohort estimate, and it
does not search older history to replace an absent or invalid example.

The copied projection contains only the new prediction, its saved followup,
and fixed bindings required for the canonical registry/digest validation.
It does not copy transcript, native messages, arbitrary factual profile fields,
old ordinal evidence, person records or advertising visits. Fixed aggregate
counts, per-channel/status/stage counts, probability min/max, actual freeze and
input-cutoff and latest customer-feature occurrence/availability ranges, later-payment-evidence counts and explicit omissions are
returned privately. Individual scope IDs, source refs, hashes/digests and text
do not leave the server. An idle zero batch is not zero historical saves.

A saved current-model prediction is evidence of persisted V1 computation,
not a verified new person/trip or calibration. A fresh snapshot alone may be backlog; natural launch acceptance must also compare its validated customer-feature clocks with the actual installation time. A saved followup is conversation
payment evidence, not bank reconciliation. Unknown/no payment does not become
a negative label. Missing storage, byte/time exhaustion, invalid or oversized
blocks, denied access and missing natural new traffic retain their distinct
meanings. Existing read-only MySQL and real OAuth/MCP regression fixtures prove
mechanics; only a separately authorized post-install natural read establishes
actual new saved runtime forecasts. No manual tick, replay or customer action
may be used to manufacture that evidence.


## Readback V1.1: fixed input-coverage diagnosis

saved_semantic_prediction_status_v1_1 additionally projects only fixed typed
counters from the same selected current-model profile packets: admitted
customer/manager turns, saved ordinal status and three positive event counters,
validated wizard-application count and recorded action/unknown-type count.
These frozen parent counters are reconciled with the model's current customer
turn count; malformed required counters cannot corroborate a model block.
Missing optional counters stay unknown, not zero observations.

The result separately reports current meaningful turns, request anchors,
context resets and the recorded linking-disabled flag. These explain whether
the scorer actually received human text or only other updates. They do not
replay messages, establish a unique trip, reinterpret a callback as intent or
promote a probability from a counter. Query-side integer/boolean/enum guards
prevent arbitrary saved strings or objects from entering this projection.
The same20metadata/4packet/48KiB/shared64KiB/five-second limits and final OAuth
checks remain;12KiB is reserved for authorization and the complete response.
No input filters, endpoint, scope, connection or native/source-table read is
added. Natural diagnostic values remain private; source tests prove mechanics.


### V1.2 source continuity and rejection categories

The same default `forecasts:read` tool now reports
`saved_semantic_prediction_status_v1_2`. It retains the current-model suffix,
20metadata/4packets/48KiB bounds inside64KiB/5seconds. Optional fixed continuity
counters come from the same selected frozen input summary: preserved AI replies,
preserved handoff receipts and barriers caused by unresolved clock, long gap,
unavailable message/event/other source. Every counter is a typed integer0..50;
missing or invalid optional counters remain unknown, including older packets.
They never manufacture intent, probability or payment evidence.

Four fixed rejection categories distinguish invalid JSON, parent binding,
prediction contract and required input summary. They carry no raw reason,
message, event type, identifier, hash or new source query. A rejected packet is
still omitted from numeric confirmation. Source barrier counters are not all
semantic episode resets. Actual percentages still require a validated current
model block and dated customer evidence; installation/CI and a fresh packet alone
do not prove a new inquiry, model accuracy or complete channel coverage.


### V1.3 canonical integrity diagnosis

The SAME `forecasts:read` default readback returns
`saved_semantic_prediction_status_v1_3`. Its fixed
`prediction_contract_reason_counts` splits already-rejected copied prediction
blocks into `digest` or `fields`; this is additional diagnosis, not weaker
validation or a numerical promotion. It emits no raw field/reason/hash/identifier,
and adds no query, selected row, byte or time budget. Existing4 rejection groups
and source-continuity counters remain.

Numeric integrity is shared with the producer and parallel later-payment checker
and is independent of CLI/web float settings. A current-model snapshot must pass
the revised canonical verifier; the legacy numeric fallback is limited to older
model hashes/comparison capsules. Current-model namespace filtering and all other
parent/access/source/clock/shape checks remain. Numeric natural-save acceptance
still requires real customer evidence and actual freeze times after installation;
a passed regression or a nonnumeric saved block alone does not close it.

### V1.4: private real-value delivery and cached session report

The SAME forecasts:read/admin/project route additionally reads only the derived
ledger of the explicitly authorized modeled-value goal678187097 on98615635.
No OAuth grant/connection/identifier input changes.20metadata/4forecast packets
remain; the shared64KiB/5sec budget also covers a fixed aggregate over at most201
latest value rows and a sanitized existing-settings cache. Release reserve16KiB.
No person/conversation/ClientID/upload ID/source ref/text or arbitrary cache field
is copied into the response. Limits/auth are shared and checked before queries
and on final release. No network, profile/source processing, POST or write occurs
during this read. Existing model_calls/conversion_uploads describe this READ,
while value_delivery contains bounded previously recorded delivery history.

The suffix reports selected-row completeness, disjoint state counts/RUB sums,
confirmed API receipt sums (including later rejected/unmatched rows), valid
processed states, stage increment sums and fixed error categories. It does not
sum these overlapping views as extra income. A current cached Reporting API
aggregate establishes goal reaches/goal visits/convertedRUBRevenue by SESSION
dates including the21day preactivation attribution window. It does not establish
each upload's individual visit match or attribution source/campaign quality.
Statuses sampled/forbidden/unavailable/not_recorded and age>900sec remain explicit;
missing or stale reporting data is never fabricated as zero or exact linkage.
Counters/values are private results, never published into Git/Actions/diagnostics.

### V1.5: diverse saved-input diagnosis

The same private route additionally projects fixed scalar counters from at most
100 newest immutable versions, separately from the original20metadata/4validated
forecast packets. It deduplicates the newest version of each current model/processor
scope and reports human/wizard/recorded action coverage, recorded (unvalidated)
semantic statuses and fixed adapter omission categories. Up to8 anonymous examples
prefer different channels/input shapes. No text, person/conversation/journal/visit
identifier or arbitrary saved string is returned. Invalid/missing
counters stay unknown; omitted reasons are zero only for an existing object with
that key absent. A frozen profile after activation does not prove new customer
evidence. These counters cannot confirm a prediction or create advertising value.

The100 scalar rows are query-side whitelisted and charged to the SAME64KiB/five-second
budget, including final authorization. This diagnosis reads saved derived profiles
only: no source read, model, processing, queue write or upload. Metadata suffix
completeness describes this finite saved-version window, not all inquiries/trips.
Actual examples, counts and financial results remain private.

Progress also reads the existing recorded profile pending-revision count and an
at-most201-row saved-version suffix beyond the value cursor. It never returns or
advances cursors, and missing checkpoint storage remains unknown rather than zero.

### Today's private timing

The existing empty-argument forecasts:read status adds today_latency under the same shared64KiB/5s budget. It inspects at most2001message revisions captured on the current Europe/Moscow day and at most201live value rows. Only scalar timing/status aggregates and at most6anonymous distinct message-scope examples plus6value examples leave the server. Source/profile text, customer/conversation/native/provider IDs are never returned.

First customer messages persisted today are timed separately from edited messages, older messages first captured today and invalid clocks. A correlated metadata lookup finds the first later profile of the same project/platform/customer/conversation whose journal cursor covers that revision. This proves profile-save progress; it does not prove every message was admitted into a numeric prediction. Recorded prediction statuses remain descriptive, not new semantic validation. The captured suffix does not establish completeness of native source capture.

Live value rows exclude owner historical review. The view reports profile→queue delay, existing upload-claim five-minute intervals and actual provider-receipt presence separately from processed rows and cached goal visits. claimed_at_unix is the existing attempt tick, not a new exact API-response timestamp. Missing clocks, pending profiles/ready rows, identity holds and incomplete bounds remain explicit. This read never captures, ticks, reclassifies, uploads or mutates anything.

### Bounded recent-scope timing queries (reader v1_7)

The first live v1_6 measurement exhausted the five-second budget in the all-message correlated profile join. This was a read-view performance defect, not evidence of forecast or delivery delay. v1_7 keeps aggregate capture counters over the same at-most2001message-version suffix, then selects the latest first customer message of at most32recent distinct scopes. Parameter-bound first-profile lookups use ordinary column collations/indexes instead of joining JSON-derived subject keys against every profile for every message.

Profile-delay/status/waiting metrics describe that explicit32scope sample, with its own inspected count and completeness within the captured window. Capture-delay counters remain over all eligible first messages in the bounded captured suffix. Neither is a claim of complete native message capture. At most6anonymous examples (up to3slow/pending followed by the newest distinct scopes) and the independent live-value examples are returned. The v1_8 reader accepts the producer's bounded8-profile receipt; its separate4-packet validation sample is unchanged. Shared64KiB/5s limits, authorization/revocation checks, read-only queries, historic-value exclusions and API/claim/processing precision remain unchanged.

The regression includes2005new human message revisions over128scopes and2688older/current profile versions; it verifies the32scope cap, exact later-profile delay, privacy and successful completion under the existing budget on SQLite/nativeMySQL. It does not claim a measured production improvement until the real private route succeeds.


### Batched timing lookups under real authorization (v1_9)

Current-day latency keeps all32 recent scope lookups in one UNION ALL SELECT. Every term retains the same parameter-bound ordinary-column identity, journal cursor, capture-time fence, first-profile order and one-row limit. The statement returns at most one scalar profile per sampled scope; invalid scopes are omitted and counted. This avoids separately charging the same current-grant authorization around each scope query, within the unchanged64KiB/five-second shared budget. Final authorization and all ordinary revocation checks remain.

The composed view retains100 scalar diagnosis versions and the existing20metadata/four-packet/48KiB maxima. No coverage or limit is raised or lowered. A native migrated-OAuth/dispatcher fixture composes100 saved packets,32 timing scopes and value delivery, then checks the shared budget,30,000-byte result,64KiB RPC envelope and byte-identical source/OAuth rows. The existing large timing fixture additionally bounds authorization invocations while retaining all32 exact later-profile results.

The combined fixture also checks that reserved final-authorization headroom cannot consume the entire useful packet window: the per-packet guard retains8KiB after the doubled projected-copy allowance plus2KiB framing, rather than a separate16KiB holdback. The total64KiB, two-copy guard, actual copy charging, real final authorization and4KiB OAuth lookup floor remain enforced. Exhaustion still fails closed; no limit or grant rule is raised.

### Compact selected timing fields (v1_18)

The same two timing selections use short private SQL result aliases for the six
message-scope fields and four indexed-profile fields. Immediately after each
budgeted query, the reader restores the original named internal row before any
validation, parameter binding, delay calculation or example selection. Result
names, values, types and nulls are unchanged; aliases never appear in the private
API response. The same predicates, order,32-scope limit and query count remain.

Repeated field names are part of the selected-row byte accounting. The compact
selection saves48 bytes per sampled scope and46 bytes per returned profile,
or3008 bytes with32 rows in each selection. This leaves more of the existing
shared budget for the following daily delivery and Telegram views. It does not
change any authorization reservation, admission floor, read order or response
limit. Daily and Telegram coverage is checked together with the monetary core
through the existing real-OAuth/native composed regression.

The saving depends on the number of selected rows; it is not a guarantee that
every optional view fits for every input. Existing explicit budget omissions
remain unavailable data, not zero totals. No raw source, new query, provider
request, forecast calculation, delivery operation or stored-row mutation is added.


### Telegram identity diagnosis (v1_10)

The same empty-argument forecasts:read status adds telegram_linkage after packet validation, only with sufficient final-authorization headroom. It reads at most32 latest pending modeled-value Telegram rows and a fixed-scope1001-observation metadata suffix. One scalar SELECT classifies absent same-scope observations, unverified identity, missing ClientID, visits after the signal, candidates unavailable at assessment, unique or ambiguous eligible ClientIDs, and uniquely eligible rows with deferred retry. Original visit/event/observation/receipt times remain distinct. The result is diagnosis, not a new attribution decision or permission to bypass sender checks.

Only aggregate counts/value, coverage bounds, last received time and sanitized existing receiver readiness/last-success metadata leave the server. No native IDs, ClientIDs, source cursor, key material, transcript or private path is returned. Reading existing transport status cannot initialize/enable/pull/tick the source, call a provider, resolve an outbox row or send. Missing storage/readiness and insufficient headroom remain explicit; no new OAuth capability, worker, schedule or data mutation. The shared64KiB/5s, result/RPC limits and final authorization remain.

SQLite/native tests cover scope isolation, late enrichment, chronology, ambiguity, retries, privacy, immutable rows and source/window failure. The real migrated-OAuth combined fixture includes this subview alongside100saved packets,32timing scopes, value delivery and a validated current forecast.


## Daily delivery and technical monitoring (release 1)

The same empty-argument forecasts:read result includes daily_delivery. It uses
Europe/Moscow calendar boundaries converted once to UTC and direct
COUNT(DISTINCT conversation_id) for every cohort/platform/history subset.
It does not truncate the day at the old 201-row delivery-view bound. The fixed
project/counter/target/value-contract scope and shared 64KiB/5s budgets apply.

upload_claims_today is the current persisted claim-day cohort, including
historical signal origins. It is not a count of HTTP attempts or API acknowledgements.
api_receipts_observed_today and processed_observed_today use once-only first
retained server observation clocks introduced by migration090. These are not
internal provider event times. Old accepted rows keep NULL acceptance clocks;
legacy_receipts_with_claim_today explicitly labels their known claim day, and
coverage shows receipts whose acknowledgement observation day remains unknown.
The cohorts overlap and must not be added together.

A POST's guarded receipt transition and observation clock commit in one short
transaction after the network call. An unsuccessful receipt commit remains
uploading/unknown for GET-only recovery. Reconciled unknowns record the first
retained GET observation. Ordinary polling of a legacy accepted row never
backfills its unknown API-acceptance date. First validated processing may be
observed for such a legacy row without inventing acceptance time. Repeated and
stale polls cannot replace the retained clocks. Ordinary conversion lanes,
goal/value/model contracts and send limits are unchanged.

The last successful aggregate Metrika report is retained separately. If the
latest attempt fails, last_successful_metrika_goal_report remains available
with its real fetched_at/freshness and sampling marker. It is still aggregate
session-date evidence, not per-upload visit linkage.

external_monitor is a whitelisted, owner-private receipt from the independent
scheduled check. It shows observation freshness and notification outcome, never
recipient identity, bot credentials, message IDs, customer text or monetary values.
The CLI-only tools/modeled_conversion_monitor.php health uses fixed read-only
metadata queries; route emits an existing credential only into protected ephemeral
SSH output; record stores only sanitized technical metadata outside the webroot.
No HTTP route is provided and no customer transcript/read marker is touched.

The live-diagnostics workflow retains its exact hourly publisher. The single
lightweight monitor is registered in modeled-conversion-monitor.yml with one
2-59/5 schedule and the existing successful-Deploy/manual triggers. It keeps
the same repository-wide notification concurrency and encrypted artifact state;
the former workflow no longer owns a monitor job. Schedules are best-effort
GitHub Actions execution, not a guaranteed five-minute SLA.
A stopped business cron is observed externally; zero conversions or missing
advertising identity alone are not failures.

The owner recipient is supplied only through the protected
MODELED_CONVERSION_ALERT_CHAT_ID Actions secret. GET verification requires that
exact private Telegram chat. One activation message tests the authorized owner
route. Notifications then describe technical incidents/recovery with no customer
or financial details. An age-encrypted retained route allows notifications while
the application server is unavailable. Encryption reuses the pinned pyrage
runtime and a domain-separated secret derived from the protected deploy identity.
No plaintext recipient/credential is committed, logged or uploaded.

Before any send, the encrypted original intent is retained as a separate
immutable artifact. Only the same original run/attempt1 may execute it. Final
encrypted state preserves confirmation or UNKNOWN. A later run never blindly
replays unresolved intent, including a crash between send and final retention.
Missing/corrupt retained state or route/identity drift holds instead of resetting
deduplication; if server metadata shows a previous notification, missing artifacts
cannot bootstrap another initial message. Encrypted artifacts use seven-day
retention and are refreshed by healthy checks. Notification UNKNOWN requires
reconciliation, not an automatic repeated send.


### Earlier GitHub registration attempt (3 November)

This earlier replacement did not establish recurring execution. The current
primary server-cron control is described below; its evidence is independent.

The old combined workflow's API state was active, its configured timers and job
filters agreed, and complete schedule-event pagination found no missing new
runs. The absence was before a scheduled run existed, not a health/SSH queue
failure. Its internal provider cause remains unknown; the independent monitor
workflow is a bounded replacement of that registration, not a second worker.

A reachable old installed SHA defers before opening notification state only if
the exact current main has an identified active canonical Deploy. Unreachable
servers retain the existing external transport alert path. Changed main, failed
API metadata or untrusted deployment context cannot authorize a deferred green
health check. Deferred installation attempts do not count toward regularity.

A receipt write/SSH failure now fails the monitor job. Runtime acceptance requires
three distinct original schedule run IDs, successful recorded technical results,
and private readback of the matching three persisted checks. Manual/postDeploy
checks and reruns do not qualify. Preserve prior last_notification_at on healthy
quiet checks; do not send another bootstrap message or reset retained state.


## Primary server-cron control and external heartbeat

After the owner confirmed that only the main server is available, the primary
checker moved to its existing OS cron. The external GitHub checker remains a
separate best-effort observer for host/source reachability and local heartbeat.
There is no second sender for the same business fault domain. Its provider's
missing schedule-event cause remains unknown; extracting a new workflow did not
produce recurring proof and is not described as a successful scheduling repair.

The availability CLI keeps legacy SQL health until a controlled activation
marker exists. Active mode performs no business SQL: it reports a stale/missing
local checker after the first ten-minute grace, or uncertain local delivery, as
external control-plane flags. Fresh handled business incidents belong to the
local role. Complete main-server failure still depends on the existing external
observer; its detection interval is not guaranteed.

Activation is one original checked operation after the new postDeploy monitor.
The current quiet initialized/verified state, no-pending/no-incident baseline and
private owner identity are verified before typed role handoff. An immutable
external-role ciphertext ACK precedes the local marker and one owned cron entry.
No bootstrap, tick or send is executed by activation. No deploy key is moved to
the application server. Unknown outcomes hold without reimport/reset/retry.

The local implementation persists prepared/sending/final state with file and
directory fsync under one private lock, preserves notification history, blocks
replay after uncertain delivery, and writes a separate4096-byte typed receipt.
Failure to persist a result cannot be a successful check. The private reader
retains the existing64KiB/five-second budget, authorization checks and all
customer/forecast/value immutability boundaries.

Runtime acceptance is THREE natural server-cron receipts with different cycle
IDs, advancing real clocks, healthy quiet results and preserved last notification.
Do not count synthetic fixtures or the postDeploy/activation workflows.

The combined forecast RPC admits the optional nested pipeline file view only
with at least12KiB of shared headroom. Otherwise it reports budget_headroom for
that subview and preserves existing forecasts/external fields and final access
checks. Total64KiB/five-second limits are unchanged. The dedicated owner page
starts a separate read budget and does not depend on the composed RPC's suffix.

## Join to first observed substantive inquiry

The existing administrator-only assigned-value page has the isolated
`view=acquisition` surface. Its existing read-only initialization, active administrator,
AnyTour project access, session release and final fresh authorization remain mandatory.
The reader has its own bounded read transaction; it does not extend the already
composed forecast RPC or its budget. Managers receive no new access.

The default cohort is people whose FIRST OBSERVED saved substantive customer
inquiry falls in the selected 30-day Moscow window. The end date is inclusive;
7 and 90 days are also available. One customer is counted once per platform;
MAX and Telegram are separate. The minimum is taken across admitted immutable
profile versions BEFORE applying the inquiry-date window, not from the latest
profile, current stage, advertising outbox or the displayed financial page.

The fixed rule is the saved `contextual_signals.events.goal=substantive_interest`
linked to the exact admitted customer-message evidence and original event clock.
A greeting, /start, callback, manager handoff or mini-app visit is not substituted.
Edited/revised or unresolved source clocks are not backdated into a first inquiry.
Current ownership, project/platform/customer bindings and owner/test exclusions
are checked; a latest denied scope must not resurrect earlier saved material.
No model or semantic extractor is rerun and no message/read marker is modified.

MAX join dates come only from unambiguous saved membership receipts. Their time
basis is the retained membership-journal event clock, whose original provider
clock has not been independently established. Missing joins, multiple joins,
conflicting identity and a join later than the inquiry remain explicit exclusions.
The bridge is prospective and already-dialogue-bound; it does not cover the full
channel membership population. A not-retained-at-30m receipt is not an exact leave
clock. Absence of a leave does not prove uninterrupted membership.

Telegram inquiry counts use the same saved semantic rule. Membership dates are
unavailable pending the owner's later Bitrix access. This is a missing source,
not zero subscribers, zero conversion or zero elapsed time. A verified tgmini
visit and bot start remain different facts and never become a join proxy.

Coverage is valid unambiguous join/inquiry pairs divided by all eligible inquiry
people in that platform's cohort. It is NOT the fraction of all subscribers who
contacted the agency. Median, nearest-rank p90 and duration bins use valid pairs
only; empty populations have null statistics. Source profile counts, actual saved
observation time range and the fixed report fence are returned separately.
The source history is explicitly incomplete/prospective; this is not first-ever
contact and does not silently include the separate 16k Telegram archive.

Data queries are SELECT-only and metadata-only, in a read-only consistent
transaction with fixed as-of/fences and repeated authorization. Hard time/byte/row
bounds fail the whole report visibly rather than publishing a truncated minimum
or a falsely complete denominator. No collector/profile tick, provider/tracker
lookup, advertising send, schema migration, processing queue or credential change
is performed by this view.

The cross-backend regression exercises original event clocks, earliest historical
versions, person/channel deduplication, membership ambiguity, unknown Telegram,
read limits and revocation. Its MySQL fixture uses a guarded fresh disposable
local test database after existing native cleanup. The pure view and responsive
fixtures use synthetic aggregates only. Live acceptance checks the installed
read-only projection and arithmetic without publishing real business totals or
customer identifiers in the public repository.


## Stage-baseline policy for new live forecasts

The owner's 2026-11-06 policy uses `contextual_stage_baseline` with
`assumptions_version=stage_baseline_2026_11_06_v1`. The semantic stage still
selects the same illustrative prior. Deferral and long-range planning no longer
multiply odds by 0.5 or 0.65. Urgency and meaningful engagement are also descriptive
observations, without their earlier numeric boosts. The existing direct-refusal
guard remains separate at 0.25; observed outcomes and access/coverage exclusions
retain their existing behavior. These are owner-selected scoring rules, not
learned or calibrated purchase probabilities.

Both production profile entry points use this stage baseline without loading
an archive candidate package. Offline comparison and explicit isolated weighted
prediction remain available for research; a saved candidate does not become live
through the stream. Timing, engagement, deferral, continued offer search, office
and payment-method evidence continue to be frozen with source references for
later comparison against payment evidence. This release does not replay the
archive, rewrite earlier predictions, reset evaluation cohorts or alter export
values, delivery, goals, schedules, source messages or customer sends.

The immutable registry distinguishes old heuristic, stage-baseline and explicit
archive-weight packet contracts. It validates the new stage/guard arithmetic
without recalculating old saved percentages. The private status reader reports
a separate `stage_baseline` count in its bounded current-source window.
Synthetic semantic and SQLite/native-MySQL readback cases cover neutral timing,
deferral and engagement, preserved evidence, direct refusal, genuine stage
changes, rejected inconsistent re-signed packets and unchanged historical scores.


### Shared read-budget headroom

The private status reader validates one core frozen forecast before optional
diagnostic sections, then inspects additional candidates only with spare budget. Optional sections reserve bytes for the final reader, adapter and dispatcher
authorization checks, alongside their existing 4 KiB floor. The Telegram linkage
reader retains its own existing 8 KiB admission gate without a second reserve. A skipped section explicitly returns unavailable with
`reason=budget_headroom`; it does not publish partial statistics or zeros.

The 64 KiB shared read budget, 30 KiB result cap, five-second deadline and all
fresh OAuth checks remain. Reservations do not refund consumed reads. A copied
result that exceeds its slice is still charged; absolute global exhaustion and
the deadline still fail closed. Revocation and unrelated errors propagate.
The same registered SQLite/native-MySQL regressions exercise optional pressure,
unchanged saved rows, mandatory final authorization and absolute exhaustion.


### Owner-authorized early advertising V1 — 2026-11-06

New live profiles opt into contextual_advertising_v1. Stage priors remain the
starting point. Expert odds modifiers are bounded: continued meaningful dialogue
1.10, continued offer search1.10, office question1.10, payment-terms question1.15;
combined odds are capped at1.30. Deferral, trip horizon and urgency are neutral.
Direct refusal retains its conservative guard. These are explicit advertising
hypotheses, not fitted or calibrated purchase probabilities. Isolated historical
and explicit research-weight calls keep their original contracts.

The same immutable profile stores deal_value_prediction, bound to the semantic
digest, cutoff, subject and current request. It records first-hour, first-three-
hours, first-day or later age from the observed request anchor, not a claimed
channel-join time. Each natural admitted update appends a forecast; an idle tick
does not fabricate a new customer observation.

The commission rate is the owner's7%. From6 November2026, new monetary capsules
use `early_deal_commission_7pct_v2`: first round the estimated tour amount times0.07,
cap the commission base at100000 RUB, then round probability times that base.
Without a usable tour price, the provisional commission base is10000 RUB; price
and estimated commission remain null. This is an assumption multiplied by
probability, not a minimum conversion award. A later known price replaces it,
including a commission below10000 RUB. Existing V1 capsules retain their exact
source hash,25000 RUB cap and25000 RUB fallback; validation selects their original
policy and never reprices a saved forecast. Customer budget, native selected hotel
inquiry, delivered party-price offers and a pinned channel publication range are
distinct observational sources. Unknown/foreign/nightly/per-person prices do not
become a verified party quote.
A fresh price observation is frozen at its admission time; source text, routing,
messages and native publication records are never modified.

The existing scoped outbox/goal/counter/transport contract remains the delivery
owner. Live prediction rows have an immutable profile-ID key, allowing a positive
increment within the same stage. The collector holds its existing settings lock
and subtracts every previously reserved value for that conversation. Repeated,
lower or identical estimates create no event. Commission base and total cumulative
value for V2 remain capped at100000, including all earlier reservations. Historical
stage-only targets and saved V1 values retain their25000 ceiling. Historical rows,
activation/cursors, existing claims
and receipts are retained; they are not repriced or replayed. Explicit frozen-funds
context suppresses a new payment-stage award. No new scheduler or sender exists.

The private bounded forecast reader validates the new probability contract and
reports monetary coverage/source/window/ranges without customer text or IDs.
Registered semantic, real-interpreter, SQLite and native-MySQL exporter checks
cover same-stage upgrades, amount revisions, duplicates, decrease, cap, unknown
price, currency/unit guards, immutable readback and frozen funds.


### Private V1 delivery calculation readback

The existing forecasts:read status now includes value_delivery.predictive_v1. It selects only exact anytour_value_pred_<profileId>_v1 keys from the newest201 fixed-goal rows, inspects at most17 predictive rows and returns at most16 anonymous scalar examples. Repeated increments retain an anonymous same-dialogue grouping; legacy rows affect only the independently summed earlier reservation. Other projects/counters and stage-only keys are excluded. Global coverage remains unknown; a full17-row slice is explicitly incomplete.

The view reads frozen price basis,7% commission, monetary-policy version and its cap (V1:25,000 RUB; V2:100,000 RUB), probability, prior reserved amount and actual delta without extracting transcript text or rerunning a model. It independently checks the stored arithmetic and profile/scope binding. This is a scalar readback, not a new signature attestation or permission to export. Missing/inconsistent calculations are explicit while the recorded row/status stays visible. All source kinds, stages, states, factor reasons and times are whitelisted; native/customer/provider identifiers and free text never leave the server. Existing shared64KiB/five-second limits, authorization revocation and30,000-byte result cap remain unchanged.

API receipt presence, saved upload status, processed state and visit attribution remain separate. Even MATCHED/PROCESSED is not per-event session evidence. The cached all-goal visit report cannot establish that a particular V1 increment reached a visit. This read performs SELECT-only queries, no provider request, poll, capture, upload, queue or conversation mutation.


### Monetary continuity when a price disappears

A missing current tour price must not restore a larger advertising base over an earlier priced valuation. The existing exporter now caps that unpriced candidate by the latest valid priced commission base recorded earlier for the same bound conversation. This is a conservative monetary cap, not a claim that the old tour price is the current trip price. Frozen forecasts stay unchanged. A genuine probability increase still uses the retained cap; an explicitly known new price may change the base normally. V2 takes the lower of its10000 RUB provisional base and that earlier priced base; it does not turn the unknown-price assumption into a floor for known prices.

The same existing tick checks at most1001 fixed-goal ledger rows and32 previously unaudited predictive rows before resolution/sending. Exact subject/binding, immutable monetary digest and the prior row order are checked. Unattempted pending/ready overstatements may be reduced; a fully false delta becomes a zero-valued failed derived row with price_loss_reconciled. The new append-only price-audit table retains the original amount, applied amount, earlier reservation, reference basis, hash and assessment time. No source profile or conversation is rewritten. Any existing claim, attempt or API receipt stays unchanged and is explicitly flagged review_required; the exporter sends no negative reversal or duplicate.

Fresh predictive rows record the applied decision atomically with their reservation. The normal ready claim requires that audit and the same amount, rejecting stale send snapshots. No new worker, queue, timer, provider write, goal, credential or customer message is introduced. The private forecasts:read receipt includes bounded price_continuity_audit coverage, action totals and anonymous corrections; zero-valued voided records are counted separately from active predictive increments.


## Predictive ledger and finite visit reconciliation

The administrator-only delivery registry accepts both exact stage keys and exact
profile-bound predictive keys. A voided zero-value price-loss correction is not
an active award. Conversation totals include all bound rows; each page shows at
most 10 conversations and the latest 32 events per conversation, with an explicit
omission count. Earlier events remain in totals. No customer/native/advertising
identity or transcript is returned by the private status projection.

The unchanged 64 KiB selected-data budget and 30,000-byte MCP result ceiling are
preserved. Scalar packet lengths are checked before copying optional diagnostics.
Optional examples are shortened explicitly when the result approaches its ceiling;
their aggregate denominators and the saved verification cohort are retained.

    php tools/modeled_conversion_visit_proof.php check UTC_CHECKPOINT

performs one finite server check using the existing counter and OAuth token. The
checkpoint is the completed earlier technical check's public UTC timestamp. Its
cohort contains predictive rows first accepted before that instant and not yet
recorded processed at that instant. New receipts do not change that cohort. All
requests are GET; the outbox, profiles, identity observations, configuration,
customer messages and schedules are never updated.

The receipt is saved atomically outside the document root in
max-search-bot-modeled-visit-proof/last-check.json (directory 0700, file 0600).
The owner registry reads it per exact event fingerprint; visit_delivery_check
in the existing private status tool exposes its anonymous checkpoint cohort.
Neither reader makes a provider request. Missing, changed, corrupt or stale
evidence is explicit. A finite check creates no standing monitoring claim.

A unique visit is reconciled only when an unsampled complete report has exactly
one goal-bearing visit for that exact ClientID and second-precision visit start,
and both its reaches and RUB value equal every known processed one-row receipt
for the same ClientID/goal. Legacy increments are included. The verified counter
timezone converts the start to UTC; the original event must lie within 21 days
after the visit. Multiple possible visits, missing receipts, a sampled/truncated
report, wrong scope or unequal counts/amounts remain unverified. This is unique
visit reconciliation, not a native VisitID or offline-event-log export.


The optional 100-version input diagnosis first checks only source lengths. It does
not parse the cohort when its frozen JSON exceeds 2 MiB, and it is not started
without four seconds of the existing execution budget. Both omissions are
explicit; they never represent zero signals. The private saved visit cohort and
the administrator financial ledger are independent of that optional diagnosis.
No global deadline, response ceiling, query-row limit or authorization floor is
increased.

Private cache reads use standard filesystem ownership checks against the deployed service-file owner, for both the cache directory and file. Reading does not require the optional POSIX extension used by the finite CLI writer. The fixed path, 0700/0600 modes, schema/hash validation, freshness and event fingerprints remain mandatory. A synthetic subprocess verifies reading with posix_geteuid disabled.

## Moscow calendar and presentation

Current delivery, daily reconciliation, acquisition and forecast-payment reports use
`Europe/Moscow` for default dates, explicit date validation and half-open calendar
windows. Their two private daily status sections share one observation instant.
Known event instants in conversion, profile and membership views are displayed in
Moscow; legacy values without a validated explicit timezone remain as saved.

This is a read/presentation change: stored UTC instants, frozen profiles, monetary
values, IDs, attribution, upload receipts and advertising conversions are not
rewritten or replayed. The already completed historical-review windows and their
original timezone metadata remain unchanged. Old calendar-bound pagination tokens
are rejected; refresh the selected report to start a new consistent snapshot.

## Saved monetary-policy visibility and calculation availability

Reader `saved_semantic_prediction_status_v1_18` exposes
`saved.deal_value.by_monetary_policy` from the monetary capsules already validated
in the ordinary core-packet read. Each supported saved version reports its count,
policy cap and unknown-price base, unknown-price/numeric-value counts, and the
range of actual saved commission bases. V1 retains its 25000/25000 policy; V2 uses
100000/10000. A valid V2 packet without a purchase probability can show its 10000
base and 100000 cap while probability/value remain null and it adds no prospective
estimate. Known-price bases can be below 10000. Invalid monetary capsules do not
enter this aggregate; the semantic-profile count remains separate.

The predictive delivery view now distinguishes two scalar arithmetic explanations.
The existing probability-based admission and its calculation fields are unchanged.
A separately named `stage_fraction_arithmetic_checked_not_probability_prediction`
basis can explain an existing stage award without inventing a purchase probability.
It requires verified profile binding, the expected contextual mode,
`insufficient_context`, null probability and frozen monetary value, a supported
monetary policy, checked price/commission/base arithmetic, an allowed fallback
stage and the exact positive integer difference after prior reservations.

Only the producer's fallback ranks 1, 4 and 5 are admitted: `interest`,
`purchase_intent` and `agency_choice`. The `stage_fraction` object names the
literal numerator (1250, 8333 or 12500) and denominator (25000).
`stage_cumulative_value_rub` is the rounded fraction times the saved base;
`prior_reserved_rub` and the example's existing `increment_rub` explain the
positive difference. Both `estimated_probability` and
`estimated_cumulative_value_rub` remain null. Probability multipliers and expert
factors are not attached to this stage calculation.

For example, an unknown-price V2 interest row has a 10000 base and a stage target
of `round(1250 / 25000 * 10000) = 500` RUB. With 200 RUB already reserved, only a
stored 300 RUB increment matches that explanation. A known tour price of 100000
RUB yields a 7000 commission base and a 350 RUB interest target. A high known
commission capped at 100000 yields a 5000 RUB interest target. These fractions
are not saved 5% purchase predictions, and no new award is produced by reading.

The fixed `calculation_basis_counts` keys `probability_prediction`,
`stage_fraction` and `unavailable` partition the inspected eligible events.
Their sum equals `events`; the unavailable count equals
`calculation_unavailable_rows`. Counts survive optional example compaction.
Invalid-bound and voided zero-value rows remain separate from those events.

Examples still carry `calculation_unavailable_reason` (null when either
explanation is available), with `calculation_unavailable_reason_counts` for
the remaining unexplained rows. The existing first-failure categories are retained:

| Reason | Existing numeric check that prevents an explanation |
| --- | --- |
| `profile_binding_unverified` | Saved access or profile/subject binding |
| `probability_prediction_unavailable` | Expected prediction mode/status absent and no admitted stage explanation |
| `monetary_policy_unavailable` | Missing or unsupported monetary version |
| `scalar_contract_unverified` | Scalar bounds, types or source/basis allowlist |
| `price_base_unverified` | Price, commission and base do not explain one another |
| `frozen_value_unverified` | Frozen value differs from probability times base |
| `increment_not_explained` | Frozen value minus earlier reservations differs from the stored increment |

These are the first blocking numeric check, not validation of all later fields.
A failed optional stage check leaves that existing reason unchanged. In particular,
a predictive key alone does not establish either calculation basis.

The scalar readback is an arithmetic consistency check, not a new validation of
the full capsule, digest, input evidence or producer decision. Four existing
nullable scalar expressions preserve JSON types (probability, price point,
commission and frozen value), so JSON null is distinct from the string "null",
false, zero or malformed text. A missing path remains indistinguishable from JSON
null in this projection; full structural validity is not claimed.

### Frozen contextual value compared with the stage baseline

Each predictive example also reports `stage_comparison` version
`frozen_context_vs_stage_v1`. For an already explained probability-based row,
it compares the saved cumulative contextual value with
`round(VALUES[stage_rank] / BASE * frozen_full_value_base_rub)`. The saved
semantic stage must agree with the ledger stage. Both sides use the same
validated monetary policy and frozen base, including the original V1/V2 cap or
unknown-price base. The signed difference is contextual minus stage value.

A valid fallback row has status `stage_only`: its stage baseline is available,
but contextual value and difference stay null. Unexplained arithmetic or an
unverified stage yields `unavailable`, a fixed reason and null comparison
amounts. Unavailable is never interpreted as zero effect.

`stage_comparison_counts` partitions all eligible events in the bounded reader
window into `above_stage`, `equal_stage`, `below_stage`, `stage_only` and
`unavailable`. Counts remain when optional examples are removed to fit the
response budget. They describe saved event rows, not unique people or trips.
The difference is between two formulas, not an isolated effect of contextual
factors. For example, the semantic prior 0.083333 and the stage fraction
2083/25000 produce 8333 and 8332 RUB on a 100000 RUB base even without a context
multiplier. Inspect the existing factor and probability fields before attributing
a difference to the conversation.

This is a read-only diagnostic for the existing value policy, not a comparison
with an independently verified PRAETOR sender. It does not reconstruct an
alternative reservation/export history, predict additional sales or establish
model calibration. Recorded increments, profile values, policy versions, source
queries, authorization and delivery behavior are unchanged. No model call,
customer message, collector, schedule or advertising event is added.

Price-continuity adjustments may use another saved reference base. Their omitted
audit evidence is not reconstructed from totals or the stage name; an unmatched
increment remains unexplained. This does not declare the saved award erroneous.

The same queries, 38 selected scalar positions, row selection, read order and
authorization rules remain. The one-core-packet priority and explicit omissions,
20 metadata/four packets/48KiB packet-copy, 64KiB shared, 30000-byte result and
five-second limits are unchanged. There is no new query, model call, provider
request, capture, reservation, upload, replay or mutation of saved values.
