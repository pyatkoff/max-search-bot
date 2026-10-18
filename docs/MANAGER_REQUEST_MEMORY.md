# Manager request memory projection

`ManagerRequestMemoryProjection::project(array $input, int $cutoffOrdinal)`
builds a bounded, deterministic evidence view of a conversation at a source-record
cutoff. It accepts explicitly prepared typed events; it does not interpret text.
The class has no runtime callers and is not connected to the AI draft endpoint.
It changes neither the selected model nor the active instructions.

This is an evidence projection, not the owner of trip, CRM, task, payment or
conversation state. Runtime trip application remains with the existing canonical
services. There is no persistence, current clock, provider call or external I/O.

## Input and validation

The envelope has exactly `schema_version` (integer `1`), `conversation_ref`,
`source_revision` (64 lowercase hexadecimal characters), `source_record_count`,
`support` and `events`. References are opaque; the caller supplies a revision
for its immutable source. A revision string is not authentication or proof that
the source was read. Authorization and trusted event extraction remain caller
responsibilities before any future integration.

Each support item has `ordinal`, `message_ref`, `direction` (`IN`, `OUT` or
`SERVICE`) and nullable `date_raw`. Ordinals and message references are unique.
Dates remain raw source values, without an inferred timezone. A cutoff is a
record ordinal from 1 through `source_record_count`, not a parsed timestamp.

Every event has `id`, `type`, `request_id` and nonempty `evidence_ordinals`.
An event becomes known at the greatest ordinal of its evidence. Events must be
in nondecreasing source order, and references must exist in support. The full
envelope, including events after the cutoff, is validated before projection.
Malformed future evidence cannot hide behind an earlier cutoff.

The limits are 1,200 support records, 1,000 events, 262,144 encoded input bytes,
4,000 Unicode characters per string and nesting depth 12 (root at depth 1).
Invalid UTF-8, objects, resources, non-finite numbers, duplicate identifiers,
unknown keys, unsupported event/field values and invalid references are rejected
with `InvalidArgumentException`. Dictionary identifiers begin with a letter,
contain only letters, digits, `_` or `-`, and are at most 64 characters.

Callers should use `declare(strict_types=1)`. The integer cutoff signature is
a PHP API boundary; a non-strict caller can coerce a scalar before the method
receives it. Envelope values are validated explicitly inside the method.

## Event semantics

| Event | Meaning and boundary |
| --- | --- |
| `open_request` | Opens a separate request with relationship `unknown`, `new_request`, `conditional_alternative`, `parallel_alternative` or `later_request`. An optional related request must already exist. No requirements are inherited. |
| `requirement` | Records a value for one existing field with provenance and strength. A replacement preserves the earlier value and evidence in `requirement_history`. A hypothetical requirement belongs only to a conditional alternative. |
| `offer` | Records a distinct manager offer with nonempty fields. It cannot rewrite customer requirements. A historical offer does not establish current price, availability or a send-time page snapshot. |
| `accept_scope` | Records what the customer accepted. `service_format` cannot accept offer fields. `specific_offer_change` names an existing offer and only its explicitly accepted fields. Evidence must be inbound; no acceptance marks the whole offer accepted or silently rewrites a requirement. |
| `lifecycle` | Preserves an ordered report about payment, cancellation, refund, pending/confirmed booking, documents or trip completion. It does not verify a financial ledger or imply any other stage. |

Requirement fields are `product_mode`, `trip_and_revision`, `party_actual`,
`room_plan`, `destination_constraints`, `travel_dates`, `duration`,
`departure_and_connections`, `flight_requirements`, `budget`,
`meal_and_room_features`, `hard_needs_and_eligibility` and
`open_questions_and_outcome`.

Evidence states are `customer_stated`, `unattributed_request_text`, `ambiguous`
and `mixed_customer_and_owner_default`. Optional subfield provenance distinguishes
`customer_statement` (with evidence references), `owner_default` (with explicit
source and revision) and `unknown`. A mixed value requires both customer and
owner-default provenance. For example, a customer's amount and the existing
whole-party budget default must remain distinguishable; currency and the price
basis of an offer do not become known from that default.

An inbound evidence direction alone does not prove agreement or the meaning of
an utterance. Typed annotations must be supplied by a trusted caller. Arbitrary
JSON requirement values are preserved; the projection does not extract ages,
currencies or dates from them. `requirement_history` is keyed by field; each
ordered prior snapshot includes `superseded_by`, the replacing event ID.

Lifecycle values are `payment_reported`, `payment_cancellation_reported`,
`refund_reported`, `booking_pending_reported`, `booking_confirmed_reported`,
`documents_sent_reported` and `trip_completed_reported`. An optional
`operation_id` links reports only when explicitly supplied. Cancellation does
not imply refund; a payment report does not imply booking confirmation.

## Output and safe use

The detached output identifies schema
`manager_request_memory_projection_v1`, source revision, cutoff, requests and
applied event IDs. It includes `production_writes: false` and coverage
`explicit_evidence_events_not_automatic_extraction`. Each applied value retains
its evidence and known-after ordinal. Evidence after the cutoff is absent.

The output does not establish company policy, manager authorship or a personal
profile. Shared outbound history is not proof of an individual manager's style.
Consumers must preserve unknowns and functional customer requirements without
propagating unnecessary sensitive labels. Unknown free-form values are not
automatically approved facts.

The public regression uses neutral synthetic examples only and runs in the
required `manager` group. It covers revised dates, scoped acceptance,
conditional party sizes, actual ages versus tariff labels, days versus nights,
budget provenance, separate lifecycle reports, new requests, determinism and
malformed/bounded input. Passing it demonstrates projection semantics, not
automatic extraction or live AI reply quality.

Private historical replay inputs and outputs stay outside the public repository
and Actions artifacts. Their inputs stop at the selected source cutoff and must
exclude later replies/outcomes and present-day selection-page contents. Any
future endpoint integration needs its own authorized extraction, access,
revision-fencing and runtime validation slice.
