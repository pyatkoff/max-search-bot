# First real experimental semantic-stage value goal

Owner authorization in SAME1140, 2 November: create a separate Metrika goal and
begin actual offline value delivery now. Owner explicitly selected the 25,000 RUB
base and incremental delivery. This is a business-assigned advertising estimate,
not actual revenue, measured commission or a calibrated purchase probability.

Counter: 98615635. Exact action target: `anytour_modeled_value_v1`.
Name: AnyTour: оценочная ценность обращения v1.
Contract: semantic_stage_value_25000_v1.
Cumulative stages: interest1250, specific request2083, linked offer discussion4167,
general purchase intent8333, explicit agency choice12500, conversational payment
report25000 RUB. Actual payment report/manager acknowledgment remain distinct
source observations; a payment link, booking or document alone is not payment.
Callbacks, greetings and office/payment-method questions alone award no stage.

Only the increase over all previously reserved values in the same conversation
is appended. Example:1250 then2083 yields1250+833, not3333. A first observed agency
choice yields one12500 row. Repeat profiles/lower stages add nothing; all states,
including UNKNOWN, reserve their value to prevent duplicate credit. Failed or
expired delivery can understate the total and requires separate review, never
automatic extra credit. Total per conversation cannot exceed25000. Conversation
identity is the explicit initial unit; repeat trips in one conversation are not
yet separately valued. These values must not be described as expected commission.

Migration086 adds nullable value provenance to the EXISTING conversion outbox
and one default-OFF configuration row. It does not create another queue, process,
timer or source collector. The existing five-minute cron runs the bounded value
step after the ordinary admitted-profile processor. Eight immutable profile rows
(max128KiB each), five pending identities and three POST candidates per tick.
No native transcript/archive is reread, no old profile/value rewritten, no
customer/read-marker/send operation and no provider/model call.

New legacy-lane filters preserve their existing historical target keys and leave
new typed rows to this value operation. Atomic claims, receipt storage, transport
ambiguity and GET-only recovery use the existing outbox state machine. A timeout
after POST is UNKNOWN and never blindly resubmitted. An expired uploading claim
becomes UNKNOWN. Only a definitely-not-sent transport failure or explicit rate
limit returns to the same existing row for a later bounded attempt.

Creation is a finite manual operation with a persisted single creation intent.
An ambiguous create result can only be reconciled by reading the exact goal.
Existing goals are never renamed, edited or deleted. Post-creation readback checks
the exact definition and that all pre-existing goals retain their semantics.
Enable requires this verified exact goal. The first activation freezes current
profile floor/time; no historical profile replay/backfill occurs. Repeated enable
does not reset those floors. Disable holds future sending; already in-flight
external requests cannot be revoked. Ordinary deploy always leaves a new install OFF.

Eligibility requires the same currently bound private non-test/non-owner AnyTour
MAX/Telegram person, an allowed latest profile and the existing destination
exclusion policy. Source roles, source/freeze/availability times and current
extractor/adapter hashes come from immutable admitted profiles. Foreign scopes,
future/backdated old evidence, missing refs and contrary outcomes award nothing.
Standalone hotel/flight semantic events do not receive the package commission
scale. A validated current numeric stage may supply its own source-bound candidate.

MAX uses the established same-counter tracker lookup. Telegram uses a unique
verified tgmini ClientID available before sending with a visit no later than the
value event. Missing/ambiguous IDs remain pending; never substitute messenger IDs,
another brand's identity or an invented ClientID. Revalidate current scope and
destination before POST. CSV is ClientId,Target,DateTime,Price,Currency (RUB), dated
at actual value freeze and within Yandex's21day upload window. Upload acceptance,
valid processed rows and attribution to a visit remain separate evidence.

The finite `modeled-conversion-value-export.yml` operation is restricted to
status/create-goal/enable/disable/launch-v1, authoritative installed current main and the
existing canonical credential. It offers no arbitrary shell/PHP/SQL input.
It emits goal/configuration/technical delivery state only, never individual IDs,
actual cohort counts/values, profile references, private text or API bodies.
Public logs are not a customer research report. Source/native/CI and ordinary
installation gates precede the actual explicitly authorized goal operation.

Sources: https://yandex.ru/dev/metrika/ru/management/openapi/goal/addGoal
https://yandex.ru/dev/metrika/ru/management/offline-conv

## Explicit first-release launch

The connected GitHub interface has no manual workflow-dispatch operation. The
owner-authorized first launch is therefore bound to the successful ordinary
Deploy production run whose main commit message contains the exact [anytour-value-launch-1288] marker. No PR run or
other release can trigger it. It waits for all seven current mandatory release
workflows, then rechecks authoritative main and the exact installed server SHA
before using the existing credential. No deployment/customer action is replayed.

launch-v1 creates/verifies the exact goal and prospectively enables once. After
an activation timestamp exists, later launch-v1 calls are read-only metadata and
never resume a deliberately disabled export or reset floors. A lost creation
ack remains held and only the exact-goal GET can reconcile it; no blind POST.
The first setup is one finite event-bound release operation, not another cron
or recurring work plan. Future value delivery uses only the existing cron.

## Actual delivery and session attribution audit

The existing value cron also obtains one fixed GET-only Reporting API aggregate
for goal678187097/counter98615635, no dimensions or person identifiers. It caches
only typed goal reaches, goal visits and explicit convertedRUBRevenue, period,
sampling and fetch time in the existing private receipt JSON. Reporting failure
is independent of uploads:403 is unknown permission/access, never zero, and never
blocks or resends an otherwise valid conversion. No new timer, worker or collector.

The report starts21days BEFORE activation because offline values can be attached
to earlier sessions. Reporting API dates are session dates. A report limited to
the activation day would miss correctly linked older sessions. Goals and RUB
totals from this unique new goal are separate from management-upload status.
Processed/valid upload rows alone do not prove visit attribution. Reporting can
lag; sampled/stale/missing results never become exact current linkage evidence.

The same private forecasts:read/admin/project status returns a fixed derived
value_delivery suffix. It selects at most201latest goal ledger rows inside one
aggregate query, no raw IDs/text/receipts, and labels truncation explicitly.
Disjoint queue states, reserved/accepted/processed RUB subtotals, incremental
stage subtotals, upload-status and fixed error counts are separate.201rows means
coverage might be incomplete. No global people/trip or paid-cohort rate is inferred.
API calls occur only in the existing background value step, never during MCP.


## Explicit historical review: yesterday first

The owner separately authorized the last two weeks, starting with2Nov2026 in
Europe/Kaliningrad. The first finite operation is launch-history-20261102:
1793570400 <= original profile/source clocks <1793656800. Migration087 adds
nullable historical origin/review time to the existing outbox and inactive
cursor/ceiling/counters in existing settings. No new queue, process or timer.

Only this exact historical operation accepts known admitted-profile wrapper
versions independently of current source hashes. Original hashes/IDs/cutoffs
remain intact. Typed roles, exact subject/reference bindings, available-at and
day clocks, canonical semantic version, product and contrary evidence remain
required; saved model stages pass the full canonical structural/digest validator.
No legacy probability is published as a current early prediction. Ordinary live
collection keeps its existing source namespace and prospective activation floor.

The ordinary value tick reviews at most40 bounded metadata rows and2MiB of
original immutable packets, at most128KiB each, then uses existing outbox identity,
claims, receipts and UNKNOWN reconciliation. The historical high-watermark includes
all reserved live and historical values, so repetition/overlapping stages cannot
increase a conversation beyond its existing25000RUB cap. Current access, personal
binding, destination gate and later contrary evidence are checked; denied/unknown
does not become an assumed positive. No original dialogue, profile, read marker
or customer send is changed.

The conversion timestamp is when the historical advertising value is assigned,
while value_profile_id retains the original dated evidence and
value_reviewed_at_unix distinguishes this from a saved early forecast. It is
neither a bank payment time nor a backdated prediction. The private reader exposes
historical progress and includes legitimate historical rows in the same receipt
and goal-session checks; public operational output contains technical state only.

A unique anytour-value-history-1293 merge marker permits the existing finite
configuration workflow to launch only this fixed day after all7 current release
gates and exact-main/server checks. It never creates a goal, enables a disabled
sender, resets activation/cursors or repeats a completed historical window.
Remaining authorized dates require subsequent explicit finite windows; they are
not claimed complete by this first-day operation. Positive natural upload and
provider receipt must still be observed privately after installation.

Historical progress keeps fixed private reason counters for inspected customer
text, qualifying signal, invalid packet, absent day signal, current binding/scope
hold and already-reserved value. Unknown counters remain null in the reader.
