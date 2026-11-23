# Separate MAX2 and common estimated-value goals

This release connects the new common-goal pipeline to the existing application.
It starts **OFF**. Migrations, source installation, explicit activation, a natural
customer case, provider processing and visit attribution are separate outcomes.

Counter98615635 has two new destinations:

| Goal | Target | Purpose |
| --- | --- | --- |
| 687931224 | `max2ok` | Initial estimated value of an eligible newly saved MAX2 launch |
| 687941321 | `anytour_modeled_value_v2_max2` | Initial MAX2 value plus later positive increments from an admitted inquiry |

Goal678187097, target`anytour_modeled_value_v1`, its existing settings, reservations,
sender and active300RUB CPA campaign are unchanged. The old URL goal563541588
keeps its original page-visit semantics. This package does not select goals in
Direct or change an advertising campaign.

## Meaning of the value

These amounts are advertising estimates. They are not revenue, commission,
payment confirmation or a calibrated probability of purchase.

The separately installed initial MAX2 producer uses the explicit bootstrap model
`max2_bootstrap_observed_value_v1`:1799kopeks for campaign715826855 and2636kopeks
for the admitted four-campaign hotel cohort. This is a historical campaign prior
transferred to a new saved launch as a stated assumption. It does not multiply a
current hotel minimum/average price by a claimed conversion probability.
Unknown eligibility or schema does not become a zero-value eligible launch.

For a later inquiry, V2 reads an exact existing immutable modeled profile through
the canonical model's read-only candidate method. It does not call the old model
tick, configure method or sender, generate another forecast or rewrite a frozen
price/probability capsule. Supported price continuity retains the admitted
previous priced base when a later profile lacks price, with an explicit bound
and no selection of a profile that appeared after the current profile.

The new common account applies this rule, in integer kopeks:

```
delta = max(0, current_saved_total - own_initial_MAX2_common_reserve - all_own_later_reserves)
```

An initial2636kopeks and a later100RUB estimate therefore permit only7364kopeks
of new common-goal value. A repeated100RUB estimate permits no new amount. A
later80RUB estimate sends no negative amount and does not reverse a reservation.
Unknown/failed external outcomes keep their reserved amounts to prevent repeat
credit. The total and any admitted increment remain bounded by100000RUB.

The early goal is not sent again by this application. The old V1 reservations
are not imported into the new ledger or reused as new send attempts. An existing
V1 profile identity may be read as narrowly bound identity evidence; that read
never invokes its paid sender.

## Exact binding and unknown results

For MAX, the new purpose-bound signed sidecar reads at most the bounded admitted
tracker history. It preserves the original legacy lookup endpoint and signing
keys. A durable own lookup intent is committed before each list or selected-row
recheck. Changed nonces do not create permission to repeat an uncertain read.

The lookup distinguishes a complete record with all four new reservation fields
blank from an unknown/partial record. The first can prove **no own new initial
reserve under the installed producer contract**; it does not prove there was no
MAX2 visit. Partial fields, unsupported values, ambiguous rows, clock/schema
errors and exhausted bounds hold the whole affected inference.

A linked hotel entry uses the exact immutable publication version and the frozen
1055-row public mapping asset. Its publication, hotel, typed offer, post URL and
alias must agree. No hotel-name match or latest-publication guess is used. The
selected raw map hash is581591d2165213a65b53a457123f3078fd36b0db1bbf664d91008efa359e5595;
the projection hash is
c5ee090912b5e20402a46b79294137ef3045a02b1dd37abf4a1f9cb5fae50172.
The original archive's whole workflow failed; only the independently verified
selected bytes match the accepted live helper/map. That history is not relabeled
as a successful whole release. A regional override absent from this mapping is
not silently admitted.

An inquiry must have the actual customer message, immutable source journal,
current subject/channel/actor binding and a saved profile that contains that
source. Existing ownership and manager routing are preserved. Message and entry
observers only record prospective private frames after the canonical handler or
entry transaction completes.

For an unlinked MAX inquiry, identity requires the exact saved V1 profile's
already bound ClientID or its immutable own zero-initial-reserve account. For
Telegram, the reader requires complete bounded verified tgmini history, stable
across two reads, agreeing on one eligible ClientID, followed by primary-key
readback. It does not substitute YCLID, a messenger ID, another profile or the
latest arbitrary visit. Missing identity remains HOLD. These are observed
source checks, not a lock against all future external changes.

## Own state and delivery

Migrations112and113 create only the new V2 accounts, events, runtime settings,
tick summaries, profile outcomes, lookup intents and captured frame tables. The
initial setting is OFF. Application requests never create/repair schema.
Only the normal forward migration owner installs these migrations.

Explicit activation samples the real server clock and current AnyTour profile
maximum in one transaction. It enters collect mode with an immutable activation
epoch, initial maximum and cursor. It does not replay older profiles. A repeated
activation cannot move this floor. Mode changes preserve that epoch and cursor
and are refused during an active tick.

The existing cron receives an independent third shutdown callback. The first
two callbacks and the old uploader remain byte-for-byte unchanged around that
addition. No new timer or schedule is introduced. OFF returns without sending.
Collect mode persists new reservations; send mode permits the bounded new
worker. Each new event can have one durable attempt, committed before CSV is
passed to the separate new client. Unknown commit/transport is not retried.

The new client is fixed to common goal687941321 and counter98615635. It verifies
the goal definition before an attempt and emits one exact RUB CSV line for the
positive delta. A known upload ID can be polled without sending it again.
PROCESSED requires the exact upload/source/one-line receipt. A JavaScript callback,
HTTP acceptance, saved ClientID, issued initial reservation or completed cron
tick is not that receipt, and does not prove Direct or visit attribution.

## Integration and verification

All new production classes are ordinary flat`services/Max2*V2.php` files.
There are three optional integrations: post-entry observation after the existing
registry returns, message observation after the existing MAX handler returns,
and the independent shutdown callback. Each checks the new file and catches
observer errors, preserving the canonical customer result. The preserved
`ChannelPostRegistryService.php` source hash explicitly admits its recording
clock; a changed producer requires review.

The 2026-11-23 producer review admits SHA256
`bb46fe35c207545d6711d8bd4a04cd09f2e8003609ee97df2092ee6590c5790d`:
both entry paths still record `self::now()` from `gmdate('Y-m-d H:i:s')`; the
reviewed publication changes preserve those UTC recording clocks. The runtime
retains its exact regular-file check. Integration tests call that actual guard
with the current producer and reject changed bytes, a missing file and a symlink
to the correct bytes. They keep the Runtime source unchanged in every case.

The release manifest's `originals` retain their historical source hashes. This
pin correction preserves the existing activation epoch, profile floor and
cursor. It does not replay preactivation profiles or invoke a manual tick; the
existing scheduler resumes the already authorized prospective work.

`php tests/run_max2_common_value_v2_regression.php` is registered in the full
required diagnostics group. It executes the exact reviewed grouped fixtures in
private temporary views whose service directory points to the actual flat app
classes. Actual112/113migrations and the actual map are linked into that view;
there is no second test-only implementation of production services. Synthetic
SQLite/fake transports, default model parity, negative identities, deduplication,
unknown delivery and optional-hook failures are included. The actual Runtime
also has an unavailable-settings regression: UNKNOWN is reported as HOLD,
distinct from an observed OFF setting. Network/mail functions are disabled for
the thirteen PHP fixture subprocesses. The same required wrapper then executes
the operation carrier's25synthetic Python cases with fake transports and private
temporary files. Missing required PHP extensions or a failed suite is a failure,
not a skip.

The full existing regression, guard, native MySQL and ordinary deployment gates
remain required. Their rules are not changed by this feature. Source fixture
success is not production installation, activation or a natural conversion.

The finite`tools/max2_common_value_state_v2.php` operation belongs to the existing
authenticated deployment/operations caller after actual ordinary installation.
Its bounded source/request/epoch inputs record provenance; hashes and caller
flags do not create authority. It operates only on new state and never calls a
tick, goal creation, old configuration or sender. The separately admitted outer
carrier must prove the actual installed source and sidecar acceptance before
activation/send. No public activation route, new secret or role is introduced.

The finite `max2-common-value-state-v2.yml` workflow is manual, owner/main-bound
and attempt1 only. It uses the existing canonical release resolver and SSH
credential, shares the existing production-deploy concurrency, verifies all
release-manifest files and the actual accepted sidecar resource, then reads
status before one selected new-state command. Initial activation enters collect;
send requires the same observed epoch in collect mode. An unknown result is not
retried. Its source-only regression runs through the same required wrapper, and
the canonical resolver's existing required suite tests the one new finite
registration. It adds no automatic post-deploy tail or schedule.

After activation, report own reserved, attempted, accepted and processed amounts
separately, and use natural new-goal Reporting API observations for visit counts.
Reporting dates describe sessions and cannot automatically be treated as an
exact post-install launch cohort. Keep actor IDs, ClientIDs, signatures and
private receipts out of public logs/artifacts.
