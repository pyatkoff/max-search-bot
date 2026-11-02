# Automatic factual profiles — offline tools and admitted journal

## Early readiness snapshots from admitted conversations

New profile versions additionally freeze `contextual_signals` and
`early_forecast` in the same existing append-only profile transaction. The
ordinary five-minute processor remains the only execution path; there is no
new capture, queue, scheduler, source reset or customer action. Previously
saved profiles and the independent purchase-study registry are not rewritten.

The shared contextual extractor runs only on messages admitted by the canonical
Python profile adapter. Its evidence references are an allowlist, not permission
to interpret every raw journal row. Omitted, forwarded, action, inaccessible or
uninterpretable rows interrupt short-answer context. Actual journal references
remain `journal:<id>`; they are never presented as archive message identities.
The bridge minimizes text in memory and persists bounded rule events and
references, not another copy of message text.

The early estimate is an explicitly **uncalibrated ordinal readiness signal**.
Substantive interest, intent to buy in general, seller-specific intent and weak
office/payment logistics are distinct. An absent rule match means unknown,
not a negative outcome. Purchase probability, commission and expected value
remain null; advertising export stays disabled. Already visible payment or
booking evidence cannot count as an early prediction of that same outcome.
These are signals for subsequent evaluation, not a measured 30-day purchase
probability or a new conversion counter.

Each snapshot retains its actual prediction time, immutable information fence,
rule provenance and coverage. The first estimate for an unresolved conversation
prefix and subsequent revisions are distinguishable. Repeated snapshots are
not independent leads, trips or sales. Trip boundaries remain provisional;
current-trip target admission is still blocked when identity or boundary is
unresolved. Useful direct signals may nevertheless be retained. Later source
edits and outcomes yield later versions rather than changing an earlier forecast.

Synthetic tests establish admission, contextual barriers, cutoff preservation,
immutable storage and retry behavior. Installation and a naturally recurring
saved forecast require separate evidence. Aggregate private processing receipts
are not published as customer-level evidence; there is no new public report or
implicit expansion of archive OAuth access. The existing study's trained
probabilities and 30-day outcome evaluation remain a separate contract.

## Prospective purchase-outcome research

New immutable journal profiles also contain a versioned `purchase_study` packet.
It freezes current-signal and condition vectors, actual availability, source
fingerprints and a person-level deterministic train/control assignment before
later outcomes. Existing profile/intent eligibility, unknown-trip and monetary
export guards remain unchanged. Earlier profiles are never rewritten.

The existing cron can advance the private study supplier after a successful
profile tick. Its actual activation clock excludes earlier leads; operational
CRM assertions remain separate from payment proof and trip identity. Research
state, fitted models and future paired predictions stay outside the webroot.
Only fixed program health is added to ordinary stream telemetry, without cohort
counts or metrics. `study-status` is a read-only CLI operation. See
`MODELED_CONVERSION_PURCHASE_STUDY.md` for the fixed horizon, source/binding,
coverage, uncertainty and acceptance contracts.

## Requested conditions and alternatives (v1_18)

The canonical extractor now preserves bounded compact adult/child counts, slash
duration choices, qualitative month parts, explicit hotel options and service
inquiries. A compact child count is not an age. Slash durations retain discrete
`options`; invalid or mixed ranges cannot become a valid numeric suffix. A
request from a month part retains an open end and unknown day/year; an exact
supported date refinement takes precedence. Unsupported mixed month alternatives
stay unresolved instead of being narrowed to one month.

`departure_day_preferences` retains explicit day numbers with a departure unit
without inventing their month/year. The same values inside a hotel option remain
local to that option. A later qualitative date window does not silently supply a
month to those earlier day-only preferences.

`hotel_candidates` describes an explicit numbered list in an affirmative hotel
request. This bounded parser recognizes Latin labels and quoted labels; it does
not resolve catalogue identities, availability, a chosen hotel or a booking. Each
option retains its own name and condition spans in the original parent message,
room preferences and departure-day alternatives. Remaining option text is retained
by `constraint_span` with `partial_rules_only` coverage, not guessed as normalized
conditions. Unlabelled places are not resolved from a case-specific location
dictionary. Generic numbered tasks, reported offers and historical/negated lists
cannot establish requested hotel candidates. Existing `hotel_interest` keeps its
separate confirmation meaning.

`service_alternatives` accumulates source-bound price/availability questions and
flight-date searches across the observed prefix. Requested package and hotel-only
options remain visible when a later turn asks about flights. Its selected service
is unknown: an inquiry is not an agreed service, a flight refusal or booking
intent. This inquiry history does not claim that an earlier option remains the
final present choice. `flight_requirement` retains its existing direct-flight
semantics.

The new fields use the existing fact availability, evidence and feature-fingerprint
contracts. Unknown trip/prefix, target restrictions, null purchase probabilities,
null commission/value and disabled export remain unchanged. Frozen old profiles
are not rewritten. A private replay is a new interpretation at its actual current
extraction time, and development controls are not predictive-quality evidence.

## Grounded deterministic wizard inputs (v1_9)

New explicit wizard applications can emit `wizard_value_applied` into the existing
private conversation event history. This is best-effort observation after the
unchanged update-only application succeeds, not a new state owner. The receipt
binds the exact newly mirrored inbound message ID, input kind and content digest
to the exact applied field/storage value. Context is request-local and cleared
before every inbound, including duplicates and failures. Plain EditFlow snapshot
restoration and general AI application do not opt in. Observer failure does not
change application success, progression, search or delivery.

The existing journal adapter admits a receipt only with the original customer
message in the same allowed bounded scope and immutable information fence. Both
sources must have resolved clocks and unedited first revisions; forwarding,
missing/changed/wrong-role inputs, incorrect callback field/value, contradictory
receipts and future evidence fail closed. Legacy empty age separators cannot
become a customer-stated zero age. Original observations remain in the journal.

Accepted facts use `canonical_wizard_application`, with separate event and input
references and their own actual availability. They describe the successfully
applied deterministic input, not acceptance of subsequent bot summaries or
offers. Composition, nights and departure date are typed; city/country/meal/class
native directory values remain exact references with unresolved labels. Buttons
do not become customer text, a positive stage or a completed trip. Unknown trip,
partial prefix, null probabilities/commission/EV and disabled export persist.

Old frozen examples without these receipts remain unresolved. Synthetic controls
prove the code path only; installation, a natural new receipt, useful live profile
and measured predictive benefit require separate evidence. No start/cursor reset,
new capture, provider/model call, schedule, store or advertising goal is added.

The admitted-journal runtime path is documented at the end of this file. The
original file/stdin tools below still have no live source reader themselves.

`tools/extract_conversion_profiles.py` automatically reads an already authorized
Russian dialogue prefix and produces factual trip conditions, a conservative
current stage and the existing1144 observation. It is a versioned deterministic
extractor, **not a trained purchase model**. The CLI has no model/provider,
network, database, customer-send or goal-export access. The admitted-journal
runtime processor below reuses this same extractor. No third-party
Python dependency is needed. It does not replace the dialogue's state owner.

## Input and provenance

The UTF-8 JSON document has exactly `schema_version:1`, `extracted_at` and `cases`.
Each case has these required fields, with optional `source_fence_at`:

| Field | Contract |
| --- | --- |
| subject | The unchanged1144 project/platform/canonical person/conversation/episode scope |
| brand_key | Producer-supplied brand context; never guessed or merged across brands |
| context_from | Accepted current-trip boundary, supplied by the authorized producer |
| prediction_at | Information cutoff |
| source_complete_through | Last asserted complete-through time; null when coverage is not established (then explicit source_fence_at is required) |
| source_fence_at | Optional explicit upper source-read fence, separately from completeness; rows beyond it are rejected |
| prefix_complete | True only when coverage reaches prediction_at; otherwise false |
| messages | At most500 source rows, each id/speaker/kind/at/available_at/text |
| membership_receipts | Optional, at most20 already-produced subject-bound subscription/30-minute receipts; no source query is performed |

`speaker` is customer/manager/other; `kind` is text/action. The producer owns
speaker authentication, canonical identity, episode boundaries and clock
normalization. Recorded DB persistence time must not be presented as provider
occurrence time. The utility validates syntax/isolation and availability, not
the truth of a producer's assertions. Messages before the trip, after the cutoff,
late-available messages, actions and system/unknown-sender text cannot become
customer trip facts. Validate every supplied row before filtering it.

Fact references retain message ID, role, occurrence, source availability, SHA256
of the original text and exact Unicode character span `[start,end)`. Source
texts remain in the authorized private snapshot, not the normalized output.
Canonical messages preserve source availability. Derived semantic facts become
available no earlier than actual extraction; an offline interpretation made
later cannot enter historical features retroactively. `usable_at_prediction`
makes that boundary explicit. Past development snapshots remain incomplete for
a new present-time prediction when their intervening history was not observed.

Membership receipts are a separate factual lane, not dialogue semantics. A
subscription receipt carries provider occurrence, producer persistence and first
admitted availability separately. A `retention_30m` receipt must reference that
exact subscription period, use the same platform membership source, assert
`same_chat_and_source`, and be checked at least1800 seconds after subscription.
Before the window matures retention is `pending`; after maturity a missing receipt
is `unknown`, never retained merely because no leave was supplied. A current/latest
state row, late observation, source-only lookup, different platform or unmatched
period cannot be backdated into an early cutoff. Rejoins are distinct periods.
The journal adapter recognizes only the versioned existing-producer events
`channel_subscription_observed` and `channel_retention_30m_observed`; it does not
read tracker, HL, native source, model or provider APIs.

For MAX, the existing signed identity lookup now carries a bounded optional
prospective membership window from the established HL10 producer into these two
event types.  Its migration-time fence prevents historical replay.  Exact chat,
source and counter own the period; the source row ID owns idempotency.  A
`retained` receipt additionally requires the actual HL12 30-minute row joined
through the matching HL9 subscription and independently reconciled to that exact
HL10 period; absence of a leave never creates it.  A bounded MAX-only cyclic pass
over the existing prospective outbox can observe a join after identity resolution,
the actual 1800-second result and a late source correction without a new worker or
queue.  One per-user invalid/oversized history remains unknown without starving
the rest of the batch.  Its progress cursor cannot widen the activation-time information
fence.  This supplies future comparable
early signals without changing dialogue goals or treating a missing receipt as
retention.  Natural post-installation receipts and useful same-person comparisons
remain separate acceptance evidence from code and synthetic controls.

## Behavior and limits

- Numeric budget preserves lower/upper bound, currency and total/per-person
  basis. Missing currency may use the explicitly labelled RUB working default;
  missing basis uses the explicitly labelled agency whole-party default.
  Currency is not converted; per-person amounts are not multiplied by an
  assumed party. Qualitative economy preference is not a numeric low budget.
  Decimal comma/point thousands retain the entire amount and source span
  (`150,5 тыс. ₽` =150500); standard, no-break and narrow no-break grouping
  spaces are accepted. Scaling is exact integer arithmetic. This integer-valued
  schema leaves amounts with fractional currency units or unsupported scales
  unresolved; it never clips their decimal suffix into another budget. An
  unrecognized explicit three-letter Latin currency code cannot become a RUB
  default. This does not add currency conversion or general currency parsing.
- Manager quotes are separate. Price questions, repeated quoted prices and
  reported past payments are not customer budgets. Equal-time conflicting facts
  remain ambiguous. Later explicit statements may update the current profile.
- Explicit adult/child counts, total and child ages keep their sources; party
  arithmetic conflicts remain ambiguous. An adult-pair working assumption for
  `на двоих` is separate from confirmed composition. Missing data prompts no
  customer questions and does not become zero.
- Days and nights remain separate observations. Date windows retain missing
  year and unspecified departure/stay meaning. Explicit breakfast, meal choice,
  date flexibility, unrestricted room arrangement and family context are kept.
  The bounded destination/city lexicon does not cover every location. An
  unqualified city mention is not automatically assigned a departure role.
- Named month-only requests retain month precision with unknown days and an
  unspecified year unless supplied. Invalid day/year syntax, multiple month
  alternatives and open month bounds are left unresolved by this bounded rule.
  Explicit hotel class retains numeric ranges versus discrete alternatives and
  lower/upper limits. Questions, reported offers, past trips and refusals do not
  create either new requirement. Source roles, spans and semantic availability
  apply to these fields exactly as to other facts; class is not hotel quality or
  a monetary/purchase score.
- Explicit monsoon avoidance, swimming and evening-walk/environment preferences
  have separate source-bound fields. They describe the customer's wishes, not
  verified destination weather, a mandatory hard filter or inferred nightlife.
  Later explicit removal updates only its own preference; separate evening
  components keep independent source age. Reported offers, past trips, questions,
  actions and other speakers cannot establish these preferences. Full clause
  evidence retains qualifiers such as 'not important'. Unrecognized wishes stay
  partial/unknown; this bounded rule set is not general dialogue understanding.
- A bounded affirmative answer may confirm hotel interest in the preceding
  manager question. It never accepts a later alternative date or price and
  never means offer selection. Bare yes, a manager link/claim or an action
  represented as arbitrary text cannot establish purchase progression.
- Stage rules require explicit customer wording for comparing, offer choice,
  booking intent, reported booking/payment with us, explicit abandonment or
  purchase elsewhere. Conditions/questions/negated positive statements do not
  raise those stages. A purchase report without seller identity stays unresolved.
  Manager claims do not become customer actions. Silence has no negative rule.
- All current customer statements retain text-free references and a
  `partial_rules_only` coverage marker. An unrecognized statement is **not**
  evidence of no conditions or low interest. This conservative v1 cannot claim
  complete semantic understanding of arbitrary Russian conversation. Broad
  free-text corrections, novel locations, ellipsis, complex alternatives and
  implicit agreements require further validation or a separately authorized
  semantic model. Do not use rule coverage or field count as a purchase score.

### Available facts and target admission

Version `ru_trip_prefix_rules_v1_3` emits `prediction_features` separately from
`target_admission`. Existing typed facts were already retained for incomplete
prefixes; this change makes their permitted use explicit instead of treating
the observation's `eligible` flag as validity of the whole factual profile.

`prediction_features.facts` includes only known facts actually available at the
prediction: budget/basis, party components, conditions and `observed_stage`.
Each retains its evidence, actual semantic availability, last source-recorded
time and age in seconds. A later unrelated message does not refresh an older
budget's age. Ages use the declared persistence clock, not the time an intention
actually arose. An observed stage is not proof of the person's present state
across an unobserved gap. Ambiguous, unknown and not-yet-available facts stay
explicit in `unavailable_facts` and the original profile; no zero imputation.
Manager quotes and working composition assumptions are not admitted customer
facts. The projection retains `partial_rules_only` semantic coverage, the actual
source fence/gap and asserted complete-through time; partial data can remain
useful without becoming complete or independently validated features.

`prediction_features.early_signals` and the identical top-level `early_signals`
projection expose subscription and retained/not-retained-at-30-minutes separately.
They are included in the feature fingerprint, but never change stage, target,
probability or value by themselves. This enables a same-person/same-cutoff study
once the authorized existing producer has placed immutable receipts in the
existing journal. It does not turn current state into history or certify a legacy
source-only no-unsubscribe decision as a per-person fact.

The projection's own fingerprint binds its values/references/availability,
brand, full subject/trip, context boundary and extractor source. It is distinct
from the builder's event-feature fingerprint. A source fence omitted by a legacy
input stays unknown (null fence/gap); the prediction bounds filtering but is not
invented as an actual capture receipt. Legacy completeness remains an explicit
producer assertion. No utility authenticates that assertion.

`target_admission` explains eligibility for the existing7-day proxy only. The
builder additionally accepts optional `target_history_available_at`, which the
extractor always supplies as its actual extraction time. Interpretation built
after a historical prediction cannot establish that the target had not already
occurred at that prediction, even when source history was complete. It blocks
target eligibility with `target_history_unavailable_at_prediction`, without
rewriting source completeness or making a later positive an admissible label.
Incomplete prefixes and targets already observed at prediction remain separate
blocking reasons. Early target-history availability alone does not prove source
or semantic completeness. Existing builder callers without this optional field
retain their producer-owned completeness contract.

These are offline data admission rules, not measured predictive improvement,
a trained model or an advertising architecture. Retain all observations,
including ones with no known facts or unknown labels. Compare existing and new
features on the same people at one predetermined information cutoff.

Output always keeps probabilities, commission and expected value null and
`export_allowed:false`. The existing builder retains its7-day explicit
option-choice/booking-intent proxy and unknown labels; this utility does not
infer future outcomes from the current profile. Advertising linkage stays
missing here, independent of the installed identity-delivery lane. Neither a
profile nor a unique identity decides which fraction to export to Yandex.

## Run and acceptance

```sh
umask 077
python3 tools/extract_conversion_profiles.py /private/prefix.json > /private/profiles.json
python3 tests/run_conversion_profiles.py
```

Limits:2MiB input,100cases,500messages/case,12000Unicode characters/message.
Duplicate keys, unknown fields, invalid clocks, conflicting message identities
and duplicate observations fail atomically with a fixed reason and no partial
stdout or source-content error. Exact duplicate messages are idempotent.

The declared synthetic controls test meanings, role/time/episode isolation,
no invented monetary targets, provenance and CLI atomicity. They are development
tests, not held-out accuracy. The accepted five-case source/output from1140 may
also be used for development. Compare automatic extraction with its prior
reviewed factual conditions; report critical-error-free profiles/all reviewed
profiles, exact omissions and limits. Do not commit its real inputs, outputs,
references, ciphertext or private key to the public repository. Preserve actual
availability and frozen-source completeness; do not recapture production or
call a paid model merely to reproduce this development comparison.

Required acceptance distinguishes source/tests, actual automatic extraction on
the declared development group, later independent held-out semantic assessment,
later outcome validation and authorized advertising delivery. First automation
does not wait for purchases, and passing its development examples does not
establish general accuracy or justify turning profiles into ad values.

## Canonical snapshot adapter

`python3 tools/profile_conversion_snapshot.py /private/received-snapshot.json`
normalizes the existing bounded canonical snapshot row shape and calls the
installed extractor. It reads a file/stdin only: no live reader, database,
provider/model, worker, capture, new schedule or real-data processing is enabled.

The envelope has exactly schema_version1, received_at, extracted_at, snapshot
and trips. `snapshot` uses the existing capture schema: schema_version1,
captured_at, database_writes=false, conversion_uploads=0 and cases; bounded
capture metadata may also be present. Each case supplies subject without episode,
started_at, messages_complete, events_complete, messages, tour_choices and the
exact source_clock `database_persistence_normalized_to_utc_not_provider_occurrence`.
Optional events are ignored because their mixed writer clocks are not accepted
as temporal or semantic evidence. They are never promoted to a stage.

Each original message has id, direction, sender_type, text, recorded_at, type
and metadata_revision. The SHA256 metadata revision is required. Only inbound
customer and outbound manager pairs map to those roles; contradictory pairs,
bots and system roles stay other. Only type=message is text; callbacks/start and
other actions cannot become customer statements. Every row is validated before
the extractor excludes old-trip content. Conflicting IDs, unknown clocks,
future rows, foreign or duplicate trip mappings fail atomically.

`trips` contains one exact matching four-field subject, brand_key and
trip_start_message_id per case. This is the accepted producer's episode marker,
not a new manual CRM stage or an answer lookup. It must reference a nonempty
customer text. Brand and canonical identity are producer assertions, not
authenticated or inferred by this adapter. Runtime collection and independent
episode interpretation remain separate admission steps.

Require capture <= actual central receipt <= extraction. Messages first become
available at the supplied actual receipt; semantic facts at extraction. Receipt
and source lag are reported separately. The adapter `canonical_snapshot_profile_v1_1`
always passes the actual capture as `source_fence_at`. Asserted complete-through
is null for truncated/unavailable history or uninterpreted native choices/events,
while known customer facts remain available in the projection. Completeness is
true only if all history and native-choice flags are complete, there are no
uninterpreted native offers/events AND capture reaches the prediction second;
an older snapshot stays incomplete even if its original history was complete.
This conservative adapter does not solve source-to-prediction lag by backdating
features. Nonempty native offers and mixed-clock events keep completeness false:
this adapter does not interpret their selected/booking state, so their availability cannot
certify full progression coverage. Available choice projections require a bounded
offers list. Output contains source/input receipt hashes and text-free profile
references, not raw messages. It is private analytical data. No P, commission,
value, attribution choice, future label or export policy is introduced.

The source-shaped regression runner tests normalization → typed profile → the
canonical observation, receipt timing, incomplete/stale coverage, episode/role
isolation, metadata integrity, and atomic redacted CLI failures. Synthetic
acceptance is not a new live source receipt or held-out semantic accuracy.

## Selecting new dialogue-review episodes

`tools/ModeledConversionCandidateSelection.php` owns the bounded metadata query
and pure private read plan. It opens no connection, executes no SQL, reads no
source text, changes no exposure ledger and enables no collector. It replaces
the **selection logic** of the closed one-shot audit, not that operation's
permission or execution. The corrected collector still needs an authorized
producer/current data scope; this module is not a new runtime hook.

`candidateQuery(from, through, platform)` returns prepared SQL and bindings for
at most100 AnyTour MAX/Telegram conversations with recorded customer text in the
database-local persistence-time window. The caller owns clock normalization,
read-only transactions, project/access checks and actual execution. The fence
applies before latest-activity ranking; equal times use conversation ID order.
There is no registration/start-date, manager-assignment, budget or stage filter.
Explicit owner/test/foreign project rows stay excluded. Text presence defines a
dialogue-review population, **not all advertising visitors**. Metadata callbacks
or arbitrary text cannot establish a semantic trip anchor. Reaching100 does not
prove whether more candidates exist.

`plan(rows, ledger, excludedPeople, from, cutoff)` receives at most100 metadata
episodes with five-field subject, anchor_id, context_from, last_activity_at,
identity_status (bound/unknown), trip_status (accepted/unknown), access_status
(allowed/denied/unknown) and source_message_count. Those statuses and anchors
are authorized producer assertions, not authenticated or inferred by this tool.
The producer must verify that the anchor is current-trip customer text and the
declared count covers **all** source message rows before role/trip filtering.
Any missing/truncated prefix retains its actual coverage elsewhere.

The private ledger has episodes/people maps from episodeKey/groupKey to explicit
unseen/reviewed/unknown assertions. No entry means unknown. Ledger completeness
and the truth of an unseen assertion remain the producer's responsibility;
absence alone never proves freshness. Current explicit person exclusions always
win. A new episode of a reviewed person may be selected for development, while
its person exposure remains reviewed. `unseen_person_candidate` is only a
producer-asserted candidate flag, **not accepted independent holdout quality**.
All linked trips/platforms of a person retain one project-scoped group for
later splitting. Unresolved canonical identity, trip or access stays excluded
from a fresh text-read plan, with its reason retained; no identity is guessed.

The plan preserves every unique metadata decision/reason, is stable under row
or subject-field order and ignores exact duplicates. Conflicting episode rows
fail atomically. At most5episodes/50source rows each/250planned message rows are
selected. Every actual fetch, including later-rejected manager/other/action
rows, consumes the caller's real read budget. Do not fetch limit+1text rows to
discover a50row limit: pre-count metadata or use a properly budgeted window.
The result reports planned reads separately from actual reads/operations0 and
capture_authorized=false/export_allowed=false. It neither consumes nor grants
a source operation. Future collector receipts must report their own actual
counts, cutoff, exclusions and source/semantic coverage.

The SQLite regression reproduces the old empty new-person/assigned-manager
selection and proves admission of old unassigned conversations with fresh
activity, source fences, owner/test isolation and bounded metadata. Pure tests
cover exact episode/person exclusions, unknown exposure, returning persons,
grouping, conflicts and read budgets. This is code/synthetic acceptance only;
the closed zero-case real audit and old five development cases are not rerun.

## Automatic admitted-journal profiles

The same installed scheduler can now transform already admitted immutable journal
revisions using `tools/profile_conversion_journal.py` and
`ModeledConversionProfiles`. No native source table is reread by this transformer,
and no network/model/provider/customer/goal call occurs. Migration066 adds only
private derived source-reference indexes, an atomic cursor and immutable profile
versions in the existing common DB; raw transcript snapshots stay in the source
journal. No second worker, work queue, schedule or business-state owner is added.

This stage is **default OFF**. After reviewed installation, the existing CLI has
profiles-start/profiles-stop/profiles-status/profiles-tick. Start requires the
already enabled source stream. Its once-initialized journal cursor starts from
the admitted journal and is preserved on stop/resume; source floors/start and
either consumed historical capture are never replayed. The ordinary existing
five-minute observer invokes the transformer after source intake. Transformer
failure cannot roll back intake or change the uploader result. Failed processing
keeps references/profiles/cursor unchanged and records only a fixed aggregate
processing_failed category; no private exception or interpreter stderr is logged.

The batch reads at most40new revisions and affects at most4conversation scopes.
Each factual window contains at most50latest source references. It fetches at
most500journal rows, copies at most1MiB raw snapshots (reserving protocol framing
within2MiB), and bounds interpreter input/output to2MiB with a5second hard process
limit. Native intake's separate500rows/2MiB/10000revisions/day envelope remains
unchanged. Size/row limits preserve source history, flag partial windows and
retain the exact unprocessed cursor. They do not certify complete trip history.
A window may omit older source facts until a later profile update; it never turns
missing information into zero or erases the underlying journal.

Every profile binds its separate work cursor and immutable information fence,
immutable source revision references,
adapter/extractor/processor source hashes and actual semantic availability.
The bounded work cursor enumerates affected scopes. Each calculation independently
freezes the available journal information fence and selects the latest revision of
already-known source IDs within it. Thus an already received clock normalization
is usable now even when its revision lies beyond the work cursor. Revisions beyond
the information fence, mutable native tables and future arrivals cannot enter that
profile. Exact chosen references stay frozen; later catch-up never rewrites an old
prediction. Reassigned source ownership is an explicit omission, and revocation or
an unchecked older exclusion keeps facts denied until restoration is observed. Snapshot versions,
references and cursor commit together. Repeated empty/retried ticks do not create
another profile version or monetary value. Source edits, reversion and removal
recompute the affected scopes; scope revocation invalidates current usable facts,
with the exclusion reference retained, and scope restoration needs a new receipt.

Canonical source metadata now additionally records DB-session
UNIX_TIMESTAMP(created_at) (SQLite fixture equivalent) and its explicit
database_session_timezone_to_unix basis. This is DB persistence, never
authenticated provider occurrence or the actual time of a later edit.
Legacy missing normalization, invalid/future source clock, unknown metadata,
oversize payloads and uninterpreted events have explicit coverage reasons.
A first receipt or metadata refresh is not invented as a customer's action time.
Repeated edits under one source ID can have unresolved semantic chronology;
independent semantic/temporal validation remains necessary.

The canonical extractor accepts optional trip_status accepted/unknown (legacy
producer-supplied trip anchors keep accepted behavior). The automatic journal
path always declares **unknown**, with a provisional unresolved-prefix identifier
that is not an accepted trip ID. Known factual statements belong to that observed
conversation prefix; they must not be advertised as confirmed conditions of one
current trip. Features expose feature_scope=unresolved_conversation_prefix;
Exposure stays explicitly unknown and identity assurance is a canonical-source
assertion, not independently authenticated cross-channel identity. Semantic
evidence has medium confidence, target admission is blocked with
trip_boundary_unresolved, and no future label/value/export is created. A new
message never silently creates a new trip. Resolving multiple trips/continuations
and general Russian semantics is a later bounded, validated stage, not a field
count or regex-coverage score.

Inbound customer text requires canonical type=message; callback/start/contact,
unknown types, contradictory direction/speaker and bot/system text cannot become
customer conditions. Canonical outbound manager text may have no metadata type;
it remains manager evidence/quotes. Event payloads and unbound advertising entries
do not become customer intent or organic/negative outcomes. All remain in the
admitted journal and denominator research. Existing owner/test/project/channel
scope is inherited, with no manager/budget/positive-stage/new-person filter.

Synthetic controls exercise actual PHP→Python→canonical extractor→private profile,
native source tables unavailable, atomic rollback and retry, exact as-of cutoff,
scope revocation/restoration, bounded100scope processing, and native concurrent
transformer exclusion. They do not establish general semantic accuracy, trained
purchase probabilities, baseline improvement or advertising export permission.
Real installed activation/first derived profile/natural scheduler receipt must
be recorded separately. Existing frozen development error counts remain unchanged
until exact replay; no old source or paid model is invoked by these controls.

# Request, delivered offer and reaction — prospective first slice (31 October)

The journal adapter now retains `tour_offer_prepared`, `tour_offer_delivered`
and `tour_offer_liked` from the existing TourChoiceService producer, only for
the admitted canonical subject and immutable event revision 1. It requires
matching producer roles, an exact prepared snapshot, a unique later delivery
receipt for that offer/chat, and a later customer preference receipt. A demo,
edited event, missing snapshot/delivery, duplicate receipt or mismatched chat
cannot confirm a preference. All dependencies must already be available at the
frozen input cutoff. Derived availability is the latest availability of the
participating receipts, independently of producer recording times.

Private `offer_journey` preserves the exact supplied offer snapshot and source
revisions. Callback token hashes and native message/chat/user IDs are omitted
from this derived projection. Prepared and delivered remain distinct. No reaction
means unknown, not rejection. A like is an explicit preference for one offer,
not booking, payment, willingness to accept its price or abandonment of other offers.
Offer price and party never overwrite the customer's stated conditions.

The saved ordinal assessment adds `early_quality` independently of its purchase
hypothesis: a substantive request and explicit agency intent remain rule
candidates; verified offer preferences are retained separately. A contrary
signal such as explicit deferral suppresses a positive quality state even when
earlier intent remains in the evidence history. While the current
trip boundary is unresolved, a preference is not promoted into the current-trip
discussion target. Verifying actual trip identity and interpreting unanchored
free-text reactions remain open. There is no new advertising goal
or export, no change to ordinary goals, no historical rewrite or new producer.
The existing profile writer saves the added projection on subsequent ordinary
processing only; this does not prove new natural saves or independent accuracy.

## Provisional request / exact offer / named price reaction

`early_quality` version `request_offer_reaction_v2` additionally projects
`request_offer_links` and `specific_option_discussions` through the existing
freeze. It reuses the canonical semantic extractor's request evidence and
boundary/party/product/gap flags; it does not create another trip parser or
persistent state owner. Omitted content and edited messages break linkage.
Only the exact, already-admitted prepared/delivered receipts may bridge those
context barriers. Their roles, subject, revision, recording clock, actual
availability, private-chat binding and exact saved snapshot are rechecked in
memory. Native identifiers and tokens are never included in the projection.
Before any receipt advances the semantic clock, the canonical long-gap
predicate is checked. A gap over 30 days breaks the request link even when it
ends at preparation or delivery; a fresh substantive request may anchor a new
candidate segment. A gap alone still does not establish another trip.

A request must precede preparation in the checked segment. A textual reaction
must strictly follow delivery; a same-second order is left unresolved. The
initial narrow rule requires the exact saved hotel name followed by a direct
price objection and an explicit request for cheaper alternatives, for example
`Fixture Beach A дорого, найдите дешевле с таким же пляжем`. This preserves an
observed continuing search, not a rejection of all searching. A name alone,
plain yes, like, manager send, unnamed objection, quotation, hypothetical or
past-trip statement cannot establish this discussion. Multiple delivered offers
with the same matching name remain ambiguous, with an explicit omission count.
Unmatched wording remains unknown; this is not a general language classifier.

Grounds are journal references to request, preparation, delivery and customer
reaction, not copied message text. Dependency availability is their actual
maximum, never the nominal delivery or reply time. Later candidate boundaries
retain earlier discussion as history but prevent it from promoting the new
request. A matched current segment may yield the ordinal state
`specific_options_discussion`, between substantive request and explicit agency
booking intent; explicit deferral still suppresses a positive state. A like
alone remains separate preference evidence.

All linkage is explicitly `same_observed_candidate_segment_not_verified_trip`.
Trip identity, independent quality, natural post-install saves, outcome and
advertising eligibility are not established. Existing false export/evaluation
guards, null probabilities/value, immutable old versions, fixed input limits
and ordinary processor remain unchanged. Real independently admitted people
and separately checked early-goal delivery are still required.



## Experimental assigned-value implementation and observed funnel

Policy correction (2 November): 50 RUB is the desired acquisition cost of a
subscriber. The configured 50/25/50/50/100 weights and 275 RUB sum are an
experimental example; installation and past tests do not approve them as
income, expected commission or an advertising price scale. DRR 10% remains
a candidate. Current production rules and historical stored values are
preserved by this documentation/view correction.

The next analytical result is a comparable AnyTour subscriber cohort for one
explicit period: subscriber denominator, linked substantive dialogues,
specific requests and substantive reactions to exact delivered selections,
with known coverage and unknown linkage. Actions/callbacks, a thank-you and
current saved advertising IDs do not establish these semantic stages.
Operational dialogue counts remain useful context but cannot replace the
subscription denominator or matched semantic stages. Determine proposed
stage acquisition prices and Metrika goals from this evidence before any
separate advertising admission.

### Existing implementation record (not approval of its experimental weights)

`ModeledConversionAssignedValue` adds a versioned private `assigned_value` to new
ordinary profile versions. It leaves all historical frozen snapshots, purchase
probabilities and expected-value fields untouched. The five once-per-scope weights
are subscription 50 RUB, observed exact-period 30-minute retention 25, actual
customer dialogue 50, substantive request 50, exact delivered-offer text reaction
100: a complete row is 275 RUB. The suggested extra discussion weight 75 remains
disabled. Repeating a receipt, rejoining, reading or reprocessing never adds a new
copy of a stage; the current total is recomputed from a keyed stage map.

The existing signed tracker endpoint now accepts an exclusive `member_page_after`
mode, with at most five current HL9 members and actual HL12 observations per page,
independent of conversations/outbox. HL10 remains the existing historical-period
source; current registry reads do not backdate its facts. No membership is inferred
from silence. Invalid/ambiguous clocks fail closed. Current tracker ClientID is
labelled as current-row attribution, not a verified historical advertising visit.
The same public-code bundle installer must install this endpoint addition before
the new client can read it. No key, grant, endpoint host or schedule is changed.

The existing private CLI gains `value-members [after]` and `value-profiles [after]`.
The former joins only an exact unambiguous MAX private-chat binding, retaining
subscriber-only rows when no dialogue exists. The latter pages current admitted
MAX/TG profiles; unresolved profile identities must not be summed with membership
rows. Both operations return private identity/evidence, so use only the existing
admin admission/transport. They are **not** invoked as part of source tests. The
registry is a read-time projection of existing stores: no new collector, queue,
worker, timer, accrual table or history rewrite. It reports bounded coverage,
unknown fields and a known subtotal; it does not claim all members were checked.
TG membership is explicitly unavailable from the MAX-only bridge, whereas TG
dialogue profiles remain visible. Profiles above 128 KiB are omitted rather than
copied; a page is five profiles. No transcript or native message table is read by
the registry. Current profile membership/role proofs remain inherited from the
admitted builder.

Offer-response coverage deliberately reuses installed exact named-price discussion
links. Other acknowledgments/replies remain unknown until their exact linkage is
available; a thank-you is never payment. The registry is therefore implemented as
an explainable partial subtotal, not a claim of complete general reaction recall.
Export stays false and conversion uploads zero. No current total is automatically
sent to Metrika, and repeated totals must never be summed as new revenue.


## Private assigned-value viewer

The administrator-only `/manager/assigned-value.php` page renders the existing
read-time registry. Explicit `view=profiles` shows one page of admitted current
MAX/Telegram profiles; `view=members` shows one page of MAX tracker members,
including subscribers without a linked usable dialogue profile. Each request
copies at most the existing five-row page and offers explicit next-page navigation.
There is no automatic scan or combined person/revenue total.

Each row presents its private scope/identifiers, explicitly experimental subtotal, all five stage
weights and awarded/unknown status, evidence references and actual
occurred/recorded/available/evaluated times. Unknown fields and unavailable
sources remain explicit. A missing usable linked profile does not prove absence
of conversation. A missing recorded timestamp is unknown. Page coverage is
reported separately from total population coverage; an unknown denominator
cannot produce a percentage. Telegram membership is unavailable from the
MAX-only bridge while Telegram dialogue profiles remain eligible.

The route checks the current active admin, AnyTour project and access before
reading and rechecks session/account/project access before releasing the HTML.
Responses are private/no-store; all values are escaped. There is no transcript
or native message lookup, customer operation, collection tick, model call or
advertising export. Private identifiers and registry output must never enter
public GitHub comments, CI logs or diagnostics.

The MAX members tab requires the already accepted current-member tracker bundle
to be installed through its existing guarded route. Until then, a failed lookup
shows unavailable and does not establish an empty cohort. Source tests prove
presentation/auth behavior only; normal installation and separately admitted
private real-page acceptance remain required.

The guarded tracker upgrade requires `--expected-preimage`, `--bundle`,
`--ssh-key` and `--known-hosts`. It admits only the pinned accepted target
and an explicitly supplied checked historical source candidate; the actual
installed predecessor is established only by the remote preimage checks.
The existing verification public key is pinned and preserved, never replaced.
Unexpected files, symlinks/hardlinks, type/subtree or preimage/key mismatches
halt before staging. Endpoint-last replacement is per file, not a transactional
directory cutover: a failure after replacement is an explicit incomplete upgrade,
with no automatic rollback/retry. A previously failed operation is never replayed
by this helper change.

The old installer run used a standby credential and failed authentication before
execution. The existing tracker repository has its own audit credential/route;
that credential must stay in its original scope. Preparing this public source
neither authorizes execution in another repository nor establishes successful
tracker installation. A concrete separately approved runner can reuse this
fixed upgrade without copying keys or creating a generic remote executor.
