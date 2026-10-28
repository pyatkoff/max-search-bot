# Prospective inquiry outcome study

`tools/purchase_conversion_study.py` is an executable, bounded research contract.
The fixed target is `manager_recorded_inquiry_outcome_30d_v1`: the manager-recorded
won/lost outcome for one genuinely new canonical conversation/lead within thirty
days of its frozen profile. It is not verified payment, a per-trip purchase
probability, expected commission or the existing seven-day intent target. Thirty
days is a preregistered study window, not a measured sales-cycle claim.

## Future packets and existing runtime

The admitted-journal adapter attaches a `purchase_study` packet outside the
unchanged canonical `profile`. It freezes both feature vectors, feature names,
protocol/source hashes, original references, actual semantic availability and
source fence. It assigns each canonical person to train/control by the fixed
protocol/project/person SHA256 half split and stores the role and actual freeze
timestamp in the packet. Neither role nor its time is reconstructed later from
labels. It makes no source/DB/model/provider call and changes no legacy
trip, completeness, target, probability or export guard. Reprocessing an old
profile now cannot create a historical prediction. Existing old profiles are not
rewritten. A packet is descriptive until separately admitted to an inquiry cohort.
The protocol pins the exact `v1_18` extractor SHA. An unregistered later extractor
keeps a descriptive packet without interpreting its changed conditions and cannot
silently enter this cohort. That does not change legacy profile processing.

Baseline A includes observed activity/message/offer counts, recency, customer reply
after manager, observed manager request, delivered offer, booking intent, current
subscription and 30-minute retention. Missing source observations have explicit
known flags where needed: absent membership is not unsubscribed and an unobserved
handoff is not a verified no-handoff. Counts describe the admitted prefix. Denied
scope cannot contribute native event flags or enter the study.

Candidate B uses exactly A plus bounded `v1_18` conditions: budget amount/basis,
party, duration alternatives, qualitative dates, hotel-option count and scoped
room-preference types, and service inquiry categories. No hotel names, identities,
raw text or count of recognized regex/fields becomes a model feature. Both arms
use the same fixed-transform L2 logistic learner, penalty and training people.

## Reader input and trust boundary

The root input is `{manifest, cases}`. A manifest has exactly:

```json
{"study_id":"opaque-study-key","registered_at":"UTC-seconds",
 "protocol_sha256":"PROTOCOL_SHA from the installed module",
 "reader_kind":"reviewed_runtime_reader","reader_sha256":"64 lowercase hex"}
```

Synthetic tests use `reader_kind=synthetic_contract`. The reader kind and matching
hashes do not authenticate a JSON producer. Runtime source/installation/run/fence
evidence must be independently checked; a caller's `verified:true` is not accepted
as a substitute. The tool always reports this epistemic boundary. Its functional
comparison conclusion describes conditional score advantage or failure to establish
one; runtime provenance still needs independent verification. It never sets
`full_buyer_quality_ready` from offline input. Contract readiness, checked runtime
provenance and conditional numerical comparisons are separate results.

Each case supplies the exact saved packet, `assignment` (train/control/excluded),
`assigned_at`, optional admission, immutable outcome envelopes and coverage. The
assignment is copied from the immutable packet; its timestamp must exactly equal
the original actual packet freeze. The reviewed reader cannot invent a later role
or backdate enrollment. All
cases of a canonical person have one assignment. The earliest customer-text packet
per person is selected before labels are inspected; entry-only packets remain
descriptive and cannot permanently block the first customer text. Later resolved
customer-text cases cannot replace an
earlier unknown case. Repeated packets and cross-arm people are rejected.

An admission object has these exact fields:

- `scope`: exact AnyTour project/platform/canonical-person/conversation scope;
- `packet_sha256`, native `profile_id`, `reader_sha256`, `run_id`, `observed_at`;
- `conversation_created_at`, `person_first_seen_at`, `prior_conversations=0`,
  `earlier_profiles=0` (earlier customer-text study profiles), `exposure=unseen`, `exposure_ledger_sha256`;
- `source_reference`: `{kind:native_inquiry_metadata,id:conversation_id,sha256,information_fence}`.

The reader SHA must match the registered manifest. The clocks must prove a new
canonical person and conversation created after protocol registration, before
profile freeze; actual reader observation is at or after freeze. A customer-text
profile is required without a condition-count/positive-stage filter. Development,
prior-profile and unknown-exposure people are excluded. Cross-channel identity is
only as strong as the separately checked canonical-person source. This is a new
inquiry namespace: an unknown trip remains unknown, rather than blocking every
descriptive packet or being relabelled an accepted trip. Without this new-lead
admission, per-inquiry labels remain unknown and only conditional conversation
outcome totals are available.

## Immutable outcome envelopes

The canonical producer emits future `sales_pipeline_outcome_observed` events.
The study's normalized envelope is exactly:

```json
{"receipt_id":"source-event-key","scope":{},
 "event_type":"sales_pipeline_outcome_observed","payload":{},
 "recorded_at":"UTC-seconds","available_at":"UTC-seconds",
 "source_reference":{"kind":"conversation_event","id":"source-id",
                     "sha256":"source hash","journal_id":"admitted revision"},
 "sha256":"SHA256 of this envelope excluding sha256"}
```

Payload `version=1`, `origin=existing_sales_pipeline`,
`producer=sales_pipeline_manager_assertion`,
`evidence_basis=manager_operational_assertion`, exact canonical `scope`,
`outcome=won|lost|open` and canonical `_recorded_clock` are required. Only a manager
assertion can label a manager outcome. A later system correction leaves it
unresolved. Producer optional amount/date assertions are sale revenue/date-only,
not payment timing or commission. Nullable trip/profile bindings are expected:
the independently proven new canonical lead/card supplies inquiry identity.

The actual event recording Unix clock must match `recorded_at`; journal availability
must follow recording. Mutable CRM snapshots, queued goal markers and caller
supplied labels are not accepted outcome inputs. Exact original receipts and
corrections remain in the input/fingerprint. A won-to-lost/open change or equal-time
conflict remains unresolved instead of inventing a refund, cancellation or negative.

Coverage has exactly `{from,through,available_at,status,source_reference}`;
its reference is `{sha256,information_fence}`. Both positive and negative primary
labels require a mature thirty-day window and explicitly complete coverage of the
**admitted journal receipt query** for this inquiry. This is not completeness of
all native business changes, physical purchases or observer success. Until then won
assertions can be reported descriptively. Open,
silence, missing observer receipts, immature windows and incomplete coverage are
unknown/censored, never zero. Complete coverage is a reader/source claim requiring
independent verification, not proof that managers recorded every real purchase.
Resolved-only results remain conditional; they cannot calibrate every lead.

## Commands, prediction chronology and comparison

The stdin CLI accepts these operations with no filesystem/source access:

```text
{operation: assemble|readiness|fit, document: {manifest,cases}}
{operation: predict, model, packet, admission, manifest}
{operation: evaluate, model, document, predictions}
```

Artifacts use canonical JSON SHA256, fixed protocol/feature names, finite bounded
numbers and explicit clock validation. Integral floats and integers share one
canonical digest representation so the existing PHP JSON decode/encode persistence
does not invalidate Python packet hashes. String values remain distinct. The model binds the complete registered
study manifest; prediction and evaluation cannot replace its registration, study
identity or reader contract. Duplicate source-event aliases and cross-person reuse
are rejected. `fit` keeps only eligible resolved training
people; it needs at least forty people and eight in each class. These are technical
support floors, not a statistical sufficiency guarantee. Training outcome availability
must precede the fitted model and every evaluated control cutoff. Unknown training
people remain in model exclusion groups and cannot later enter control.

`predict` accepts no outcome payloads. It requires a control-assigned frozen profile no earlier than
the model, an independently supplied new-inquiry contract and an unseen canonical
person. It returns paired research estimates plus an actual prediction timestamp.
Control-assigned people whose first customer-text packet preceded the first fitted
model cannot be promoted by selecting a later packet. The runtime supplier retains
them as excluded/unknown under its preregistered pre-fit rule; prospective control
evaluation starts with genuinely new people after actual model fitting.
The saved prediction must precede every won/lost assertion for that control inquiry;
an earlier open assertion is not a purchase label.
`evaluate` checks both probabilities against the same model and frozen vectors;
predictions cannot be reconstructed after labels and called prospective.

Evaluation needs forty resolved control people and eight in each class. It reports
Brier, log loss, fixed top-quarter precision, calibration bins and the same-person
paired differences. A fixed-seed, 200-replicate person bootstrap gives paired 95%
intervals. Top-quarter is a study metric, not an advertising export policy. A
numerical advantage is reported only for both score intervals below zero and is
explicitly limited to the resolved conditional subset. Synthetic gains and support
floors do not establish real-world uplift or all-lead probability calibration.

Every operation is bounded to 2 MiB input/output, 256 cases and sixteen receipts
per case; the CLI checks a five-second elapsed limit. The existing journal caller
retains its separate hard interpreter budget. Invalid input is rejected atomically
with a fixed redacted error. No worker, schedule, backfill or advertising delivery
is added by this study module. The separately reviewed runtime reader/assembler
must supply actual immutable packet/outcome/coverage/exposure evidence and preserve
the study registration/assignment and prediction receipts before outcomes arrive.

## Existing server supplier bridge

`tools/purchase_study_runtime.py` adds a pure `protocol`/`init`/`advance` bridge for
the separately reviewed PHP supplier on the existing scheduler. Python performs
no filesystem, database or network access. PHP owns the lock, native bounded reads,
private exact-byte state persistence and fixed aggregate status. This bridge adds
no worker or schedule.

The exact init/advance request fields are `operation`, `state_json` (null or an
opaque canonical JSON string), actual UTC `now`, `reader_sha256`, `profile_fence`,
`profile_cursor`, `profiles` and `updates`. Init accepts no profiles/updates and
freezes registration at the first actual post-install tick, setting its cursor to
the current database fence so all earlier profiles remain outside this cohort.
Future conversation/person recording clocks must be strictly after registration.
Advance requires a monotonic processed metadata cursor, bounded by the captured
profile fence. It never advances blindly to an unread maximum.

Profile entries are `{db_profile_id,observed_at,wrapper,admission}`. A minimal wrapper
has `{subject,access_status,purchase_study}`; no raw customer text is needed. Native
profile ID, exact packet hash/time/scope and admission proof are bound together.
The first customer-text packet is preserved. Invalid exposure/admission and pre-fit
control people have sticky exclusions; a later richer or resolved profile cannot
replace them. Their permanent ledger retains the person hash, reason, first packet
fingerprint, profile ID and time without storing a full case/vector. Old contacts
therefore do not consume the 256 admitted-case slots. At fitting, these excluded
people are also bound into the model exclusion set. Entry-only descriptors are not
an enrollment opportunity.

The source-bound runtime capacity policy is fixed before installation: at most
128 first-seen training people and 128 first-seen control people, within the same
256-case limit. Arm overflow creates a compact permanent exclusion and advances
the metadata cursor; labels and condition richness do not choose a replacement.
Training cases, including their appended outcome envelopes, have a separate
524,288-byte ceiling. The other half of the one-MiB state remains available for
the fitted model, future controls/forecasts and shared metadata. This is resource
headroom, not a promise that every maximum-sized packet or receipt set will fit.
`protocol` exposes both quotas, the training-byte ceiling and its policy name;
the registered native-reader hash binds the exact policy implementation while
the inquiry target and feature/assignment protocol remain unchanged.

The exclusion ledger has a separate 131,072-byte ceiling. Before persisting an
overflowing ledger, only exclusions without an admitted case are compacted into
a chained digest and an accumulated distinct-person count. Every case exclusion,
including training invalidation and saved control-forecast disposition, remains
explicit. Compaction also requires a bound native creation clock/source reference
with creation no later than the original packet freeze. Missing or inconsistent
creation proofs remain sticky and fail closed if their bounded ledger fills;
they are never forgotten as if proven old. A monotonic creation-time admission floor moves to at least the latest
first-packet freeze among the compacted entries. Any future enrollment must prove
that its canonical conversation was created strictly after that floor. Thus a
forgotten exclusion cannot return with a richer packet or restored scope, and
new people created after the floor can still enter the other arm. Existing
cases/models/forecasts are never rewritten by compaction.

This capacity policy conservatively excludes old-created conversations whose
first customer text had not yet appeared when the floor advanced. It is fixed
before installation, uses neither labels nor condition richness, and is not a
claim about their conversion behavior. `excluded_people`/`unknown_people` include
retained and compacted accounted exclusions once. Profiles filtered by the floor
do not increment those distinct-person totals or grow the ledger: their unique
people cannot be reconstructed from a time fence. Those totals therefore do not
count every distinct person among floor-filtered observations. Train/control and
resolved-cohort denominators continue to describe the actual admitted cases.

Updates are `{person_group,outcomes,coverage,access_status}`. Existing receipt IDs
must match their original immutable payloads; exact duplicates are free and source
aliases/mutations fail closed. Coverage advances monotonically and means only the
admitted-journal query described above. Access denial/unknown is a sticky exclusion;
revocation of a training person invalidates use of the frozen model pending review.
It does not silently retrain or rewrite forecasts. A seventeen-row outcome sentinel
exceeds the sixteen-receipt study limit and explicitly excludes the case rather
than manufacturing complete coverage.

At fit, `training_truth` freezes each used person's packet hash, resolved label and
the IDs/hashes of target-window receipts (plus any pre-profile won assertion affecting
the risk set). A later immutable target receipt is a material provenance revision:
it invalidates the model and clears the current comparison without modifying the
model or earlier forecasts. A complete reread that makes the training target
unknown or changes it does the same. Transient partial query coverage without new
contrary evidence does not invalidate the model; `training_revalidation_pending`
counts those used people and suppresses a current comparison claim until their
coverage is complete again. Reread availability clocks and receipts recorded after
the fixed thirty-day window do not change this frozen target truth.

The state holds `manifest`, `profile_cursor`, `profile_fence`, `last_now`,
`monitor_cursor`, normal study `cases`, sticky `exclusions`, one `model`, frozen
`training_truth`, `predictions`, latest `evaluation`, validation metadata and a canonical hash. PHP
can rotate over `cases[].packet.scope/person_group` and
`cases[].admission.profile_id`. The complete state is returned as `state_json` plus
flat fixed aggregates only. PHP persists this string without JSON round-tripping
its numeric model data. State is bounded to 1 MiB and transport to 2 MiB.
The monitor cursor advances only for updates to groups present before this pass;
new-person outcome checks do not skip older monitors. A fresh update whose profile
could not enroll due to capacity is explicitly deferred without losing existing
case updates; unrelated unknown update groups remain invalid.
If a known outcome update cannot fit the training or overall byte budget, a small
sticky capacity exclusion is persisted instead of retaining old complete coverage
as current truth. A used training person invalidates the model; a stale comparison
is cleared. The original source stays in the canonical journal, and saved packets,
models and forecasts are not rewritten to manufacture readiness.

Mature supported training data fits one paired model. All control people seen
before fitting stay excluded. Only future unseen control packets can get actual
predictions before won/lost assertions; later matched mature cases feed evaluation.
If a late journal receipt proves a won/lost assertion was already recorded at or
before a person's saved forecast, only that control person becomes permanently
chronology-ineligible. Its packet, receipt and forecast remain in the audit; a
stale comparison containing it is cleared. The core evaluator still rejects any
such assertion in an eligible control rather than weakening the chronology rule.
Cases/models/forecasts stay frozen while receipts append. Case/byte capacity causes
explicit backpressure and an unprocessed cursor, retaining the original source in
the canonical store. Corruption, reader/protocol replacement or clock regression
fails without a replacement state. No old state is silently reset or backfilled.
