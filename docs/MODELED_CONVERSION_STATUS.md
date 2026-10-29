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
