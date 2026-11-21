# New common value goal: private transactional ledger

Prepared source only. This directory has not installed a migration, opened a
native database, changed a running collector, or sent a conversion. Its production
constructor requires MySQL and exception mode. The explicit synthetic constructor
flag admits only SQLite with empty database filenames, for memory-only fixtures.

The service defaults to OFF. A future source-checked caller must supply its own
real durable activation, authenticated binding, saved profile and identity facts.
Fixture hashes and `admitted` booleans do not authenticate a public caller or
grant a release. There is no web endpoint, command-line writer or cron here.

## Own storage and accounting unit

`112_max2_common_value_v2.sql` creates only `max2_common_value_accounts_v2` and
`max2_common_value_events_v2`. Migration number112 was available at pinned app
`b678bc2f211c5f141751137453b199036491222d`; root must recheck before publication.
The existing migration runner owns actual forward installation and records the
migration only after success. Runtime code contains no DDL or repair path.

Each account freezes one observed substantive inquiry journal/native-message
anchor within the exact project/platform/customer/conversation. This first slice
allows only one accounted inquiry per conversation. The existing saved model
describes conversation-level value, so inventing multiple trip episodes could
apply that total more than once. Account, inquiry, tracker receipt and profile
unique constraints prevent reusing the same admitted source in this new stream.

The original common MAX2 reserve is imported once. Later events contain only
positive increments beyond that baseline and all own previously reserved deltas.
Every delivery state still reserves its amount, including unknown and failed.
Zero evaluations are also stored, with `not_needed`, to reject a changed amount
for the same immutable profile. Model/source, amount and event clock remain fixed.

An inquiry with a complete proof of zero OWN NEW early reserve receives its full
saved modeled value on this new goal. Historical blank MAX2 rows are compatible
with that proof under the actual pinned producer contract. Partial/ambiguous
evidence never becomes a full fallback. A linked inquiry receives only its delta.

Existing goal678187097, its full values, old tables, settings and sender are not
read or modified by this repository. The external saved-value adapter may use a
verified old identity receipt as read-only evidence. It must not pass an old
outbox delta or old reserved sum as the new account's amount or baseline.

## Public service methods

- `reserve(binding, estimate, identity, recheck, now)` commits an own account and
  one immutable profile evaluation under an account-first transaction lock.
  Results are `RESERVED`, `EVALUATED`, `ALREADY_RECORDED`, `HOLD` or `UNKNOWN`.
  This call never provides delivery permission.
- `receiptClaim(trackerId, inquiryKey)` reads the exact own receipt claim and
  returns unbound/same_inquiry/other_inquiry, or UNKNOWN. It never picks a latest row.
- `readback(inquiryKey, eventKey)` reconciles only those exact stored keys. A
  missing row is not retry permission; UNKNOWN never becomes zero.
- `pendingReservations(now, limit=20)` reads only positive reserved rows with no
  attempt from the exact current own activation.
- `beginAttempt(eventKey, now)` requires the future own `send` mode. It commits
  attempt_count=1, attempt_key, exact CSV SHA256 and attempt time before returning
  a sendable event. All other results contain no sendable event. A competing
  worker or an unknown existing attempt cannot obtain another attempt.
- `recordAttemptResult(eventKey, attemptKey, result, now)` stores accepted,
  unknown or failed from the new client without releasing the amount. It accepts
  normalized `state`, `csv_sha256`, `response_sha256`, `upload_id|null`, integer
  `http_status` (0 for unknown transport), and a bounded machine `code`.
- `pendingProviderReceipts(now, limit=20)` reads only accepted known upload IDs.
- `recordProviderStatus(eventKey, attemptKey, receipt, now)` stores an exact known
  upload's status. `PROCESSED` requires source_quantity=1 and line_quantity=1;
  if processed_quantity is supplied, it must also equal1. Browser callbacks and
  early `issued_unconfirmed` state are not processing evidence. Visit linkage is
  never asserted by this service.

The activation object has version1, mode off/collect/send, exact counter98615635,
goal687941321, target `anytour_modeled_value_v2_max2`, actual producer contract
SHA256, `activation_sha256` and a prospective `activated_at_unix`. This service
does not invent or install that activation. Its epoch and accounting floor are
immutable for the created account; later mode changes retain that epoch.

The estimate extends the pure accounting input with `event_unix` and
`profile_observed_at_unix`. Actual saved-source ordering is enforced:
inquiry time <= input cutoff <= frozen event time <= profile observation <= now.
The source adapter must validate the original saved profile/model and subject.

The identity contains admitted=true, exact subject/inquiry_key/client_id,
source_sha256 and basis `bound_max2_receipt`, `saved_inquiry_identity` or
`saved_v1_identity_read_only`. For a linked receipt its CID must equal the frozen
MAX2 CID. Its point recheck must be fresh, `match`, `action=recheck`, coverage
`exact_selected_record_only`, and repeat the exact PK and immutable tuple digest.

The sendable event is compatible with the separate new
`Max2CommonValueMetrikaClientV2`: project_key, string counter_id/goal_id, target,
value_contract, delivery_state attempted, attempt_count1, event_key, attempt_key,
client_id, event_unix, delta_kopeks and csv_sha256. The client rebuilds the canonical
CSV and checks its hash before POST. The original request can be reconstructed
from those frozen fields; no duplicate CSV blob or token is stored here.

## Uncertainty and reverse order

A commit exception may mean the transaction already committed. The result then
is UNKNOWN, without a sendable row, and requires exact readback. The service
never retries that mutation or drops a reservation automatically. It retains
all uncertain provider attempts and never returns them to the send queue.

An inquiry already valued at full amount cannot be rebound to a later MAX2
receipt. The early producer has no app-account visibility, so a later independent
launch can still produce its own early reserve. The policy treats reverse order
and multiple possible launches conservatively. This package does not claim
global uniqueness per trip across those independent event orders, perform
cross-channel person inference, alter the public hotel CTA, or subtract from v1.

## Verification and remaining native gates

`tests/repository.php` uses the actual forward migration with only dialect syntax
translated for SQLite memory. It exercises unique source ownership, repeated and
changed profiles, transaction rollback, simulated lost commit acknowledgement,
reverse account binding, actual model-clock order, one-attempt intent, CSV parity
with the new client, unknown delivery retention and exact processing receipts.
An old v1 table exists only as a synthetic untouched sentinel in this fixture.

Local Python SQLite creation of the translated migration succeeded. PHP is not
installed in this preparation container: PHP lint and fixture execution are
pending native SOURCE-ONLY CI. SQLite does not prove MySQL engine selection,
native row-lock concurrency, production permissions or actual migration state.
Those remain bounded native preflight and release checks. No runtime activation,
new schedule, provider send or old-source change is included here.
