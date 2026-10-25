# Automatic current-trip profiles — offline v1

`tools/extract_conversion_profiles.py` automatically reads an already authorized
Russian dialogue prefix and produces factual trip conditions, a conservative
current stage and the existing1144 observation. It is a versioned deterministic
extractor, **not a trained purchase model**. No model/provider, network, database,
customer send, goal export or runtime bot integration occurs. No third-party
Python dependency is needed. It does not replace the dialogue's state owner.

## Input and provenance

The UTF-8 JSON document has exactly `schema_version:1`, `extracted_at` and `cases`.
Each case has exactly:

| Field | Contract |
| --- | --- |
| subject | The unchanged1144 project/platform/canonical person/conversation/episode scope |
| brand_key | Producer-supplied brand context; never guessed or merged across brands |
| context_from | Accepted current-trip boundary, supplied by the authorized producer |
| prediction_at | Information cutoff |
| source_complete_through | Last time through which the source prefix was actually read completely |
| prefix_complete | True only when coverage reaches prediction_at; otherwise false |
| messages | At most500 source rows, each id/speaker/kind/at/available_at/text |

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

## Behavior and limits

- Numeric budget preserves lower/upper bound, currency and total/per-person
  basis. Missing currency may use the explicitly labelled RUB working default;
  missing basis uses the explicitly labelled agency whole-party default.
  Currency is not converted; per-person amounts are not multiplied by an
  assumed party. Qualitative economy preference is not a numeric low budget.
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
