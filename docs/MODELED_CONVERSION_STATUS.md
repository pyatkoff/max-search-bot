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

The existing empty-argument forecasts:read status adds today_latency under the same shared64KiB/5s budget. It inspects at most2001message revisions captured on the current Europe/Kaliningrad day and at most201live value rows. Only scalar timing/status aggregates and at most6anonymous distinct message-scope examples plus6value examples leave the server. Source/profile text, customer/conversation/native/provider IDs are never returned.

First customer messages persisted today are timed separately from edited messages, older messages first captured today and invalid clocks. A correlated metadata lookup finds the first later profile of the same project/platform/customer/conversation whose journal cursor covers that revision. This proves profile-save progress; it does not prove every message was admitted into a numeric prediction. Recorded prediction statuses remain descriptive, not new semantic validation. The captured suffix does not establish completeness of native source capture.

Live value rows exclude owner historical review. The view reports profile→queue delay, existing upload-claim five-minute intervals and actual provider-receipt presence separately from processed rows and cached goal visits. claimed_at_unix is the existing attempt tick, not a new exact API-response timestamp. Missing clocks, pending profiles/ready rows, identity holds and incomplete bounds remain explicit. This read never captures, ticks, reclassifies, uploads or mutates anything.

### Bounded recent-scope timing queries (reader v1_7)

The first live v1_6 measurement exhausted the five-second budget in the all-message correlated profile join. This was a read-view performance defect, not evidence of forecast or delivery delay. v1_7 keeps aggregate capture counters over the same at-most2001message-version suffix, then selects the latest first customer message of at most32recent distinct scopes. Parameter-bound first-profile lookups use ordinary column collations/indexes instead of joining JSON-derived subject keys against every profile for every message.

Profile-delay/status/waiting metrics describe that explicit32scope sample, with its own inspected count and completeness within the captured window. Capture-delay counters remain over all eligible first messages in the bounded captured suffix. Neither is a claim of complete native message capture. At most6anonymous examples (up to3slow/pending followed by the newest distinct scopes) and the independent live-value examples are returned. The v1_8 reader accepts the producer's bounded8-profile receipt; its separate4-packet validation sample is unchanged. Shared64KiB/5s limits, authorization/revocation checks, read-only queries, historic-value exclusions and API/claim/processing precision remain unchanged.

The regression includes2005new human message revisions over128scopes and2688older/current profile versions; it verifies the32scope cap, exact later-profile delay, privacy and successful completion under the existing budget on SQLite/nativeMySQL. It does not claim a measured production improvement until the real private route succeeds.
