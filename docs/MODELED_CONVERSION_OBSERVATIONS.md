# Model observation preparation

Issue: [1140](https://github.com/pyatkoff/max-search-bot/issues/1140).
The offline standard-library CLI builds time-bounded observations from an
already authorized, normalized private conversation snapshot. It does not read
production, classify raw text, call a model, train, assign arbitrary scores or
enqueue conversions. No runtime caller or automatic job is added.

## Frozen first target

`option_selection_or_booking_intent_7d_v1` asks whether a current trip
subsequently reaches an explicit option choice or booking intent within seven
days. Seven days is the initial versioned working window, not a measured sales
cycle or an advertising-goal change. This proxy is distinct from purchase.
Changing its window or event definition requires a new target version.

A high-confidence `offer_selected`, `booking_intent`,
`booking_confirmed` or `paid` observation in the future window gives label1.
An already reached target or incomplete prefix makes the observation ineligible.
Label0 requires a mature, complete window and an explicit `declined` or
`purchased_elsewhere` observation without subsequent in-window progress.
Silence, missing history, an immature window or uncertain interpretation gives
an unknown label. A positive remains progress even if a later sale is lost.

These are candidate labels, not independently verified truth. The upstream
semantic extractor must distinguish actual customer intent, manager promises,
hypotheticals, negation, another trip and another seller. Independently review a
held-out sample of those interpretations. Keep unknowns and their denominator
visible: training only on explicit outcomes creates selection bias and does not
establish calibrated purchase probabilities.

## Canonical source boundaries

| Existing source | Permitted input and limitation |
| --- | --- |
| `messages` via existing authorized conversation readers | Customer/manager direction, source ID and time. Reuse canonical exclusion of start/bot_started/callback when producing customer-message observations |
| `conversation_events` and selection-owned records | Only actually persisted events and exact selected variant; a sent offer, URL, static post token or manager request does not prove a choice |
| Existing conversation-stage interpretation | Emit typed stage evidence with message/event references and its actual first-availability time. This CLI does not perform that interpretation or authenticate source references |
| `ManagerDialogueAnalyticsService` | Useful current operational totals, not a historical feature snapshot. Current assignment/outcome and lifetime-first request must not be backdated into an earlier prediction |
| Advertising1137 and funnel1138/940 | Preserve their owners. A producer asserts a genuinely verified project/platform/person/source/counter-scoped join; this CLI validates supplied scope and time, not its cryptographic or installed provenance |
| `MetrikaConversionGoalService` / existing delivery | Existing meanings, IDs and delivery remain unchanged. This tool never calls them |

The latest-chat traffic cache is mutable and is not historical visit identity.
A post ID is not a visitor or visit ID. Missing linkage does not mean organic
traffic and does not prevent preparing a conversation observation; it prevents
claiming a usable advertising match. The supplied `verified` assertion is not
itself proof of a real signed Mini App roundtrip.

## Input contract

Use UTF-8 JSON with exactly `schema_version:1` and an `observations` array.
Unknown fields, duplicate JSON keys, invalid times/types, cross-subject records
and conflicting duplicate evidence IDs fail the whole document. Limits are
2 MiB, 300 observations, 500 evidence rows per observation and 20 references per row.
Keys are bounded ASCII opaque IDs; all times are UTC seconds with a trailing Z.
No transcript text, names, contacts, private prompt or credentials belong here.

Each observation has these fields:

| Field | Meaning |
| --- | --- |
| `subject` | Exactly project_key, platform(max/telegram), customer_key, conversation_id and episode_key |
| `context_from` | The accepted beginning of this current trip, not the lifetime of the chat |
| `prediction_at` | The fixed information cutoff |
| `observed_through` | How far the supplied follow-up has actually been observed |
| `prefix_complete` / `followup_complete` | Explicit booleans from the authorized producer; truncated/failed reads cannot claim completeness |
| `evidence` | The immutable typed observations described below |
| `advertising_entry` | Optional scoped identity observation, or null |

`customer_key` must be the canonical person key, stable across their trips and
conversations. Use the same key across platforms only when that identity is
actually resolved. `episode_key` comes from current-trip interpretation and its
evidence; it does not require managers to maintain another manual funnel.
The producer retains the accepted trip-boundary evidence privately.

An evidence row contains exactly `id`, `subject`, `kind`, `at`,
`available_at`, `confidence` and `references`. The subject must match the
observation. Canonical kinds are customer_message, manager_message and
offer_sent; they require high confidence. Semantic kinds are request,
clarifying, comparing, offer_selected, booking_intent, booking_confirmed, paid,
declined and purchased_elsewhere. Confidence is high, medium or low.
Only high-confidence semantic observations produce a stage or target label.

Each reference contains exactly source(message/conversation_event/selection_action),
id,at and available_at. Observation occurrence must equal the latest reference
occurrence; its availability cannot precede any reference's availability.
Processing an old message later cannot create a new future customer action.
References supporting an in-trip observation cannot precede context_from.
The producer must use stable evidence IDs, and retain extraction revision/model/
instruction provenance with its private immutable snapshot. This CLI does not
discover a source row, validate a message's semantic interpretation or resolve a
person/trip automatically.

An advertising entry contains id,project_key,platform,customer_key,source_id,
counter_id,at,available_at and verification(verified/unverified). A later link is
indistinguishable from missing in the feature snapshot. It cannot enrich past
features retroactively. Scope mismatches fail even when the link is in the future.

## Use and review

Run against an already permitted private input, with private output permissions:

```sh
python3 tools/build_conversion_observations.py /private/observations.json
python3 tests/run_conversion_observations.py
```

Stdout is emitted only after every record passes. Validation errors produce a
fixed machine reason on stderr and exit2; they do not echo source content.
Output is private analytical data even without raw text. Do not commit actual
inputs/outputs to the public repository or paste them into issues.

Each row has a stable observation_id, a person-level group_key, eligible,
features, feature_fingerprint, source evidence IDs and a separate label/reason.
Both occurrence and availability must be at or before prediction_at to enter
features. Only later occurrences within the fixed window can become labels.
Equal-time conflicting stages remain unknown. Exact repeated evidence is
idempotent; duplicate observation keys within a batch are rejected.

Later follow-up preserves the observation ID and feature fingerprint; persist
label updates under that same key rather than multiplying examples. The existing
consumer must reject a changed feature fingerprint under an already frozen
observation ID. Preserve the original prefix rather than rebuilding past
features from mutable current database state. Split
evaluation by group_key before model development, keeping a person's duplicate
or continued cases together. Current stage, advertising join and evidence
fingerprints are observations, not scores.

The envelope is always shadow_only, probability=null and export_allowed=false.
This first source slice has synthetic mechanical tests only. Real snapshot
extraction, semantic quality review, an actual shadow calculation, later held-out
evaluation and an authorized conversion delivery remain separate gates in1140.
Do not infer production/model readiness from this utility or its CI.
