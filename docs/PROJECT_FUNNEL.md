# Shared MAX sales funnel

The product chain is demand → reviewed channel publication → Direct feed → signed
MAX miniapp → exact native post → existing conversation and manager → observed
sales events → separately calibrated predictive value. AppleService39 and an
unrelated configured business use the same runtime. AnyTour retains its existing
MAX2 route, counters, goals, model and conversion behavior.

## Public entry and feed

`project_funnel.php?project=<project_key>` renders only a short transition status,
retry and a confirmed native link. It is not a catalogue or another landing site.
`&format=csv` exports the universal UTF-8 CSV. Every row resolves through the
existing verified publication owner before export. IDs, approved pictures and
descriptions remain stable; a missing reviewed price stays empty.

The ad URL follows the actual MAX2 context contract:
`https://max.ru/<bot>?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_<base64url>`.
The canonical JSON context contains string `post_id` and `offer_id` fields.
Direct expands its three macros; no paid click, publication ID or price is
invented. `post1_<post_id>` is an organic publication entry; an ordinary launch
opens the configured default post and creates no advertising observation.

On a branded host deploy the identical `modules/project-funnel/relay.php` as
`/new/<project_key>/index.php`. The fixed application upstream owns all behavior;
the server folder selects the configured project. The relay keeps the MAX
webview origin, forwards no cookies, accepts no upstream URL, and stores no MAX
credentials. The shared application verifies the signed body. A relay replaces
only its explicitly owned predecessor; old webhook/certificate files remain.

## Company configuration

`ProjectMaxConnection` remains the credential and bot owner. Put `sales_funnel`
in its existing trusted project settings, or use a versioned public fallback at
`resources/project-funnel/<project_key>.json`. No token belongs in that document.

Required data: schema version 1, enabled flag, project/bot identity, brand,
conversation source, separate advertising source, channel ID/public URL, default
post, `metrika` and a map of reviewed posts. Each post supplies its existing owner
and publication ID, stable offer ID, title, description and direct image URL.
Five optional labels retain the existing feed segmentation. A configured new
business sets `handoff_mode: manager_first`, its business kind and canonical
`messenger.max.webhook_url`. It uses the existing authenticated project webhook,
manager handoff, assignment, quiet reentry, push and conversation history. Device
repair retains its own reviewed answer/context adapter; another business does
not inherit repair questions or a diagnosis. The current client-creation UI still
has its own business-kind scope; this adapter does not overwrite that owner's UI.

Normal projects use `channel_post_registry` and `publication_id` with a sent,
content-preserved, button-verified receipt. The existing AppleService39 pilot
store is a compatibility reader, not a second registry: its exact campaign,
project, bot, channel, source and verified original create receipt are checked.

## Launch, attribution and manager

### Paid page address before counter initialization

Owner correction, 17 November 2026: AppleService39 must keep the original
`yclid` decoded from MAX `startapp`, plus its region/campaign values, in the
actual miniapp page URL before ordinary Metrika initialization. The address
remains the branded `/new/appleservice39/` page (or the existing application
entrypoint when opened directly). A synthetic analytics-only URL is not a
substitute for retaining the advertising parameters in that real address.

The client captures launch data in memory, then replaces the paid page address
with its analytics-safe URL: original YCLID/region/campaign retained, raw MAX
initData/user/signature and launch hints excluded. Only then does it initialize
the existing counter for its automatic pageview and obtain ClientID through
that counter's callback. For paid Apple/MAX launches, follow the installed
MAX2 reference: pass the sanitized `metricUrl` explicitly as `init.url`, with
an empty referrer, and queue `notBounce` once at initialization and once
before confirmed navigation. The real URL is still normalized before init;
this avoids exposing signed launch data to the analytics script. Telegram and
other project clients retain their existing behavior. No `defer:true`, manual pageview hit, extra goal,
reload, second visit writer or counter change is introduced. The authenticated
POST retains its existing endpoint and exact signed launch body; only its
acknowledged durable receipt permits the existing exact-post redirect.

AnyTour keeps its separate MAX2 receiver and is not migrated by this correction.
Tests prove the real address and ordering at initialization, large YCLID strings,
branded routing, raw-signature privacy, signed context precedence, conflict
refusal, blocked analytics and exact native destinations. Those source fixtures
do not prove actual Direct attribution. Metrika page-goal totals, Direct-linked
goal totals, stored observations and conversation attribution remain distinct
acceptance results. The observed URL-contract defect is not by itself proof of
the sole cause of Direct's reported zero.

MAX initData uses the official two-step WebAppData HMAC, a one-hour age bound
and 60-second future tolerance. Duplicate signed keys, conflicting starts,
another bot, unknown post/offer and arbitrary destinations are rejected. The
signed identity proves a MAX user, not independent ownership of a paid click.
ClientID is obtained through the selected counter's callback, never another
counter's cookie. Analytics URLs contain no raw MAX identity or signature.

Advertising visits use version 2 of the existing append-only observation owner.
The forward migration adds post/offer/context columns without changing version 1
fingerprints, checkpoints or existing visits. A canonical signed launch is one
visit; a transaction rechecks it after locking the scope cursor. An empty CID
can acquire one immutable enrichment; a conflicting CID cannot replace it.
Only a persisted/reused receipt allows the client to redirect. An unknown network
outcome exposes a manual retry. Neither launch nor retry sends a bot message.
For paid Apple/MAX only, the confirmed link uses ordinary `location.assign`
first, as in installed MAX2; a throwing ordinary navigation falls back to
[MAX Bridge openMaxLink](https://dev.max.ru/docs/webapps/bridge/). Other flows
retain their native-bridge-first order. The visible confirmed link is retained;
an unsuccessful save opens neither route.

When the customer starts the business bot, the existing ingress writes a
`project_funnel_entry` event in the same conversation transaction. It matches the
exact project, bot, user, post, offer, counter and a preceding 24-hour visit.
Several distinct visits remain ambiguous. Missing/bounded-out history is not
silently replaced by the latest other offer. Enrichment of one visit is not a
second visit. The existing authorized source panel shows the original native
post and attribution status, without CID, YCLID or signed initData. An optional
attribution failure must not lose the underlying customer/manager handoff.

## Observed conversions and predictions

The same `MaxChannelConversionWorker` runs the company adapter; there is no new
cron, outbox or receipt state machine. Per-company mode is `off`, `collect` or
`send`, with its own counter and unique goal targets for customer activity,
manager request, customer reply, working, offer sent, booking and won. Recorded
Unix receipts supply event time. A CRM won assertion is not a payment receipt.
The first eligible attributed acquisition anchors one goal per conversation;
reentries and later stages never rewrite a previously queued event.

Collection cycles through 25 eligible conversations per project. It rejects
incomplete histories at 101 entries or 501 messages/events. Identity enrichment
can only use the exact originally saved visit/offer. It cannot change the counter
or select another user's newer click. Policy changes hold incompatible ready
events rather than retargeting them. Upload claim, accepted, unknown, processed,
failed and expiry states are the existing outbox states. A crash/unknown POST is
reconciled through reads; it is never blindly sent again.

`send` needs an explicit credential source. The default is a private server binding:
`MAX_SEARCH_PROJECT_METRIKA[project_key]` with exact `counter_id`, `bot_key` and
`oauth_token`. An explicitly selected `credential_source: platform` instead uses
the existing private platform OAuth account, without exporting or copying its
token. Counter access is checked for own/edit permission. Existing unique exact
goals or owner-pinned `goal_ids`/`goal_match` conditions pass read-only admission.
An existing contains condition is never rewritten by admission; it blocks offline sending.
No goal is created, renamed or changed. New businesses cannot borrow AnyTour's
counter. `tools/max_channel_attribution.php project-probe <project_key>` is a
GET-only admission check and returns only fixed result enums, never a credential.
For AppleService39 it also includes the bounded `stored_funnel` read described
below. The original top-level `ok`, `status`, `conversion_requests` and exit status
remain the result of goal admission, independently of the stored-data read.

An optional `prediction` provides a model ID/version and evidence SHA-256.
The existing calibrated form keeps its validation/expiry instants (maximum
90 days), base value and conditional probabilities for observed stages.
Missing, expired or invalid calibration produces no export from that form.
Retraining and validation evidence remain an explicit input; this adapter does
not claim to train a new model from an unvalidated sample or replace the existing
MODEL owner.

An explicitly approved `basis: owner_assumption` instead records the owner's
decision, approval instant, probability and exact model/service price list. Its
evidence is an instruction and dated price sources, not an empirical calibration.
The assumption remains selected until the owner changes its version or disables
it. It does not acquire a fabricated validation date or silently inherit the
calibrated model's expiry. The embedded prices and evidence participate in the
existing conversion policy hash. Unknown or ambiguous repairs have no value.

Both forms use the existing `predictive_value` goal with expected RUB value;
neither asserts payment. A recorded `won` stage suppresses a new prediction.
There are no default travel weights, commission multipliers or inferred prices.
The original acquisition, event clock, durable value and one-row-per-conversation
uniqueness remain frozen. A later policy version does not rewrite an old row or
authorize another upload of it.

## AppleService39 activation checkpoint

The profile keeps the existing 67 advertising offers and their publication IDs.
The owner supplied counter 42611469 and goal 680570232. The target `predict` is
pinned for `predictive_value`, with the required offline condition `exact`.
[Yandex requires an exact action condition for offline uploads](https://yandex.ru/dev/metrika/ru/management/offline-conv).
A live contains condition returns `goal_condition_unsupported`, never a ready
status; no goal is rewritten by this code. The selected private credential source
is the platform account. Normal installation diagnostics still run the GET-only
admission; its successful original observation on 16 November 2026 established
counter and goal access separately from conversion delivery.

On 16 November 2026 the owner explicitly chose the available service prices and a
20% dialogue-to-sale probability as a starting assumption, to revise later from
actual results. The Apple profile therefore selects `send` with an explicitly
versioned owner assumption: **listed service price × 0.20**, in RUB. For example,
the accepted 5000 RUB iPhone 13 battery price gives 1000 RUB of expected revenue.
This is neither a paid repair nor profit, and it does not use AnyTour's 7% factor.

The initial price list contains 123 reviewed fixed model/service pairs, including
the six accepted battery-price corrections. The dated source files and their
digests are retained in the prediction evidence. Display variants without a
selected part, prices "from", prices on request, missing prices and the
unconfirmed iPhone 14 Pro Max front-glass amount are excluded. No average price is
substituted for a generic model, generic service or symptom.

The collector resolves the price from the recorded `bot_started` belonging to
the exact attributed entry, using its `delivery_key`, recorded clock and scoped
repair reply context. Model-card service selections retain their original
advertising post/offer. Later mutable conversation metadata cannot supply an
earlier price or retarget the original visit.

When the original entry is a valid model card with no service and no forecast
row exists, the first explicit `model_service` selection may refine that entry.
Both recorded bot and funnel receipts must have the same project, bot, user,
source, advertising scope, model, post, offer and original visit. The original
visit must resolve to one verified ClientID before refinement; later enrichment
may have a new observation cursor without changing that visit. The original
acquisition is retained exactly, with a separate frozen `price_context.refinement`
receipt containing both pairs of event IDs, delivery keys and recording clocks.
The selected bot event follows the original funnel event and precedes its own
funnel event by ID, even within one second; its recording clock falls between
the original anchor clock and its own funnel clock, neither in the future.

The collector selects the first explicit service before looking up its price.
An unpriced first choice, an invalid or missing receipt, ambiguous attribution,
a different request or an intervening matched advertising visit holds the
forecast. A canonical unattributed menu with valid receipts is a neutral step.
A bounded native-event check also holds when optional funnel recording failed,
so a later priced service cannot hide an earlier explicit choice without an
attribution receipt. An already complete but unpriced original entry does not
use this fallback. Free-text repair extraction is not part of this model.

An observed dialogue stage is still required; a recorded won stage suppresses
the forecast. Prediction time is the later of the observed stage and selected
priced-context clock. Any existing forecast row, in any state, preserves its
original value, evidence and anchor; the unchanged unique outbox insert remains
the final concurrent-capture guard. No old row is repriced or replaced.

The existing cron, outbox and reconciliation perform delivery. Enabling `send`
is distinct from a natural first candidate, API acceptance and final processing.
No test visit, dialogue or synthetic conversion is created to demonstrate it.

No existing native post is recreated, republished or reconciled by this change.
No feed is imported into Direct and no ad spend is started by these endpoints.

## Reading saved Apple funnel evidence

`tools/max_channel_attribution.php project-status appleservice39` reads the saved
Apple/MAX funnel without provider requests. The existing Apple `project-probe`
adds the same report as `stored_funnel` after its existing GET-only goal check,
so ordinary installation diagnostics can expose it without a new operation or
workflow. Other projects retain their original probe result. Neither reader
collects candidates, resolves identity, enriches visits, reconciles an upload,
runs a worker or sends a conversion. `conversion_requests: 0` records that this
diagnostic sent nothing; it is not evidence of historical conversion delivery.

The report is safe for the existing public diagnostic log. Its fixed keys and
allowlisted reasons expose no ClientID, native/customer/conversation/visit/upload
identifier, message text, service price, expected revenue, raw context or private
configuration value. `approved_public_policy_matches` compares the loaded
effective configuration with the approved public Apple profile, including
`send`, the 20% assumption, exact prices, bot, counter, target and offer mappings.
It reports only a boolean; missing settings and a policy mismatch have separate
fixed reasons. The reader does not inspect or publish the source of an override.

`outbox` separates allowlisted saved states under the approved policy from other
historical policies. Its population includes marked-test rows, before receipt
validation. `intake` reports marked and unmarked conversations separately;
`is_test = 0` means only that no test mark is present. It also counts saved
`bot_started` events, starts whose trusted server-side reply context was
captured, those reviewed starts lacking `project_funnel_entry`, conversations
with no stored bot start, and recorded human replies. Each count is bounded to
the same recent Apple/MAX conversation cohort, contains no identifiers or
customer text and is read without changing the source. A reviewed start without
a funnel event warrants a scoped investigation of the entry writer; it does
**not** prove a paid click or authorize a retroactive conversion. An absent
saved start cannot be labelled organic without further evidence.
The observations section reports scoped rows and stored ClientID presence. On a complete read, its click-tag summary counts nonempty YCLID rows and distinct nonempty YCLIDs in SQL across the full scoped history and by visit day in Europe/Moscow. The daily series includes all represented visit dates plus today and yesterday. A late enrichment stays on the original visit day. Distinct daily totals can overlap. Missing or truncated history produces unknown counts. These are stored tags and observations, not unique visitors or send-eligible forecasts. None of these counts proves
organic traffic or a sale.

`first_saved_forecast` selects the earliest saved Apple predictive row by ID
before checking policy, conversation scope, test marks or outcome. An orphan,
test-marked, historical, ambiguous or invalid first row stays unconfirmed; the
reader never substitutes a later convenient success. Verification follows its
original project/bot/customer/channel/user/visit/ClientID binding, native bot and
funnel receipts, immutable estimate, and any frozen refinement receipt. It
checks the stored price times the stored 20% probability in integer kopecks and
the saved event time against the recorded stage and selected bot clock. It does
not reprice the row or use today's sending window to invalidate an older receipt.
A refinement funnel receipt may have been saved just after the selected bot and
forecast clock; those distinct clocks are preserved.

`retained_api_receipt` and `retained_processed_receipt` describe stored worker
evidence, without a fresh provider poll. A saved upload ID and coherent accepted
status establish the former. Only `state = processed` with `upload_status =
PROCESSED` and no saved error establishes the latter. An accepted upload already
reporting `PROCESSED` or `LINKAGE_FAILURE` remains accepted until the normal
worker polls it. A failed row reporting `PROCESSED` after quantity rejection
remains failed. The worker originally requires one source and one accepted row,
but the generic outbox does not retain those quantities or a provider processing
timestamp; this reader cannot independently reauthenticate them. `observed_at`
is the diagnostic read time, and processing time is explicitly not observed.
Manager-path flags describe saved requests, assignment, outbound messages and
events, without claiming fresh delivery or actual manager acceptance.

All database reads use one read-only repeatable snapshot. A caller's existing
transaction is refused without changing it. MySQL 5.7.8+ uses the SELECT execution
timeout hint; supported MariaDB 10/11 uses a per-statement time limit. Unsupported
timeout capability fails closed. Each statement is limited to at most two
seconds within a five-second reader budget; this does not bound establishing the
PDO connection or guarantee a hard network wall-clock deadline. Queries cap row
counts and text fields in SQL. The largest event result is 201 rows times 64 KiB
of payload, about 12.6 MiB plus fixed columns. The separate 128 KiB cumulative
budget counts processed row JSON; it is not a hard buffered-PDO or network-memory
limit. SQLite fixtures enforce query-only mode and restore the caller's mode.

Each aggregate slice has `available` and `complete` flags. A 101st aggregate row,
missing table, invalid evidence, row-byte limit or deadline produces an explicit
incomplete/unavailable result and unknown counts or receipt flags, never a
fabricated zero. The standalone status exits successfully only when its read is
available and complete. A nested read failure does not change the goal probe's
top-level admission result or exit status. Successful synthetic regressions
prove this contract; a first natural processed forecast remains unobserved until
the corresponding saved evidence exists and is read after installation.

## AppleService39 Telegram forecasts

The existing project worker can collect a Telegram forecast for a newly
recorded paid start. It verifies the active AppleService39 Telegram bot and
source, the stored private start message, the native post receipt and the
exact earlier visit with a click tag and ClientID. The confirmed post must
identify one model and service with one approved price. A general entry or a
historical attribution without the native message receipt stays ineligible.

The same outbox retains one prediction per conversation. A new conversation
cannot acquire a visit already recorded for that Telegram user. The project
keeps its approved goal and computes listed repair price × 20%. The worker
rechecks the evidence before sending and uses the original queue state for
unknown uploads. The saved-status report shows Telegram click observations
and outbox states separately from MAX. A processing receipt does not
independently prove an advertising visit association in the provider.

## Verification

The required manifest includes shared PHP and client fault-path regressions.
The existing advertising storage workflow applies the actual forward migration
and exercises v1 preservation, v2 cursor locking/replay, late identity and the
same outbox on native MySQL. The existing protected source-panel visual suite
covers desktop/mobile Chromium and WebKit. Synthetic checks establish code
behavior; they do not substitute for a native MAX launch, manager acceptance or
Direct feed validation on the connected business account.

Primary contracts: [MAX launch validation](https://dev.max.ru/docs/webapps/validation/),
[Direct universal CSV](https://yandex.ru/support/direct/ru/feeds/requirements-other-categories),
[Metrika offline conversion API](https://yandex.ru/dev/metrika/ru/management/offline-conv).
