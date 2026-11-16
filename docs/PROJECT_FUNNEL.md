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
The confirmed link opens through [MAX Bridge openMaxLink](https://dev.max.ru/docs/webapps/bridge/)
when available. A missing/throwing bridge retains ordinary navigation and the
visible confirmed link; an unsuccessful save opens neither route.

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
earlier price or retarget the original visit. A later independent entry does not
silently fill an incomplete original entry; free-text repair extraction is not
part of this model. An observed dialogue stage is still required, and the
prediction clock cannot precede the recorded priced context.

The existing cron, outbox and reconciliation perform delivery. Enabling `send`
is distinct from a natural first candidate, API acceptance and final processing.
No test visit, dialogue or synthetic conversion is created to demonstrate it.

No existing native post is recreated, republished or reconciled by this change.
No feed is imported into Direct and no ad spend is started by these endpoints.

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
