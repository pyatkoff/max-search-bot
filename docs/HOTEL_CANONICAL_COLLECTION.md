# Dormant canonical HOTEL collection boundary

This change prepares an explicit server-owned write path. It is not deployed,
bootstrap is not run, existing queue rows are not enrolled, and no worker is
started. It does not migrate or adopt the live claimed/UNKNOWN stage. The
journal's synthetic stage-local snapshot and this typed collection overlay are
distinct contracts; neither establishes business acceptance.

## Actual storage and authority

`HotelCollectionCanonicalService` owns each transaction and reauthenticates the
active AnyTour administrator and current hotel OAuth grant. It locks HOTEL lane,
the exact existing queue, and the journal head, in that order. A protected queue,
current stage/generation, active operation, exact private capability and admitted
instance are required for worker access. An externally supplied context provider,
Library pointer, scope, reserve inventory or acceptance flag is never worker
authority. The internal Store's provider is constructed privately by the Service.

The core producer path exposes two tools under the existing hotel OAuth scope:

- `hotel_work_collection_context`: read a pinned assignment and typed overlay.
- `hotel_work_collection_candidate`: save a bounded candidate for that assignment.

The cycle extension additionally exposes producer entry, bounded WORKING reads,
review context and review submission. See HOTEL_WORKING_SERVER_CYCLE.md for the
protected cron, import and atomic domain acceptance extension.

Bootstrap, assignment preparation, reserve maintenance, control review and commit remain
internal control methods, requiring the separate journal control capability.
There is no public control/commit tool, launcher or scheduler. Provisioning and
custody of that control capability, live cutover and native dispatch integration
remain separate future work. Merely listing the new tools enables no work.

## Baseline, scope and protected reservations

Bootstrap imports actual immutable bytes and checks their approved SHA256 against
the queue/journal baseline. The legacy document is opaque: this code does not
pretend to decode arbitrary WORKING formats or build a cumulative legacy WORKING.
A typed baseline reference index and protected-reservation inventory are explicit
control attestations. Their completeness and mapping to the opaque original must
be independently reviewed before any future import. The index is checked for
shape, digest binding and duplicate references, not independent source truth.

The control path pins country, scope, hotel keys, logical source packet, current
collection revision and reserve digest before dispatch. Country comes from the
queue. Protected reservations are generic typed entries, with no expiry or
worker-written release; refresh cannot remove a prior reservation or shrink its
hotel set. A changed inventory invalidates older assignments. Melissa, Ibis and
legacy162 are representative protected fixtures, not hardcoded production hotels.

Candidates contain only typed sources, facts, read updates and questions. Exact
field allowlists, duplicate JSON-key detection, country/hotel scope and reference
checks reject arbitrary ledger, baseline or reservation replacements. Digest
references do not prove that an external source was actually fetched or is true.
Semantic identity canonicalizes typed content and country while excluding new
operation/envelope identifiers; it is exact structured deduplication, not NLP.

## Atomic commit and readback

A control reviewer reads the stored candidate bytes, then commits their exact
hash with the assignment's base revision and snapshot hash. The transaction
writes an immutable overlay snapshot, server-generated ledger, queue-wide CAS
head and exact request receipt together. Unique logical source packet, semantic
packet and revision constraints reject duplicate intake across operations/stages.
A stale base or reserve snapshot is held; it is not automatically rebased.
An exact prior commit receipt can be recovered after a later HOLD without
renewing mutation authority. Unknown transaction outcomes require receipt
reconciliation, not a new request.

The overlay preserves the original bytes and appends typed records. Reads verify
stored bytes and digests and never fall back to Library. An old agent overwriting
an external Library export cannot change this server head, baseline or ledger.
Missing/corrupt canonical data holds the operation. The service returns at most
64 KiB of normalized context JSON (the MCP wrapper adds its own envelope); oversized projections fail explicitly without truncation or
Library fallback. The public worker tool accepts at most 24 KiB of raw candidate JSON, fitting
the existing 192 KiB HTTP request budget even after JSON escaping; the internal
control/store validator has a separate 256 KiB cap. Baseline is capped at 64 MiB,
and stored overlay lists/ledger at 20,000 entries each and 16 MiB total. Limits are
checked before a write can create an unreadable subsequent state.

Core derived receipts retain `business_accepted=false` and `native_run_verified=false`.
The separate cycle finalizer issues a domain acceptance only after independent
review and atomic queue advancement; it still does not attest native execution.
The data commit is `derived_overlay_committed`; it does not advance the business
queue, verify native identity or guarantee once-only provider compute/cost.

## Legacy and external boundary

Migration 082 prepares the sticky queue marker and journal; migration 083 prepares
collection tables only. Neither file performs live enrollment or activation.
Every legacy Queue mutation enforces the marker even in precreated/raw objects;
see `HOTEL_JOURNAL_MUTATION_INVENTORY.json`. Cron treats protected legacy dispatch
as a nonfatal hold, preserving subscriptions. An already-in-flight webhook may
arrive, but its later legacy claim or ACK cannot mutate an enrolled queue.

This repository does not control ChatGPT Library ACLs or old deployed binaries.
The guarantee applies to this explicitly enrolled server collection and readers
using its service. External workflows that still treat Library as authoritative
must be moved before claiming a full live cutover. Existing repository-owned
`knowledge/hotels` readers and the separate hotel catalogue database are unchanged.
Privileged direct database access remains outside application fencing.

## Verification contract

The existing required Queue suite includes document, Store and authenticated
Service tests on SQLite and through its native MySQL wrapper. Coverage includes
strict validation, reserve/scope enforcement, lost acknowledgements, rollback,
process exits, competing commits, stale assignments, cross-stage duplicate intake,
corrupt/missing bytes and external Library overwrite isolation. The legacy
boundary suite races enrollment against claim, handoff and release in both lock
orders using independent processes. Separated MCP tests verify the two worker
methods, existing OAuth audience/scope, strict arguments, bounded wire payloads
and absence of public control methods. Actual CI run results belong in the PR;
static review or a previous commit's passing run is not a new runtime PASS.
