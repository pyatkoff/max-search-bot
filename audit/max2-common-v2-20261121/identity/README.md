# Common V2: saved ClientID projection

New private source reader for the common goal. This package does not modify
the old v1 exporter, outbox, settings or paid goal. Publish it as
`audit/max2-common-v2-20261121/identity/`, with sibling `policy/` and `source/`
packages. The future application installation places the new class next to
`Max2ValueBindingPolicyV2.php` in its services directory.

```php
$reader = new Max2CommonIdentityReadV2($pdo);
$identity = $reader->read($admittedSavedValue, $inquiryKey, $now);
```

`$admittedSavedValue` is the complete internal result of
`Max2ModeledValueReaderV2`, not a request body. A successful result has exactly
`admitted`, `subject`, `inquiry_key`, `client_id`, `source_sha256`, and `basis`.
`null` means HOLD. It never means zero value or absence of MAX2. The separate
`privateReceipt()` is bounded private evidence for the binder's own durable
state; it contains identity coordinates and must not be logged or exposed.

The caller still owns authenticated webhook/subject binding, the current
allowed-dialogue gate, and proof of no applicable own early reserve. In
particular, Telegram with a linked MAX customer channel requires that separate
MAX coverage proof or a HOLD. This reader does not manufacture those proofs.
It does not require a hotel, publication or offer for an ordinary inquiry.

Every read verifies the exact saved profile bytes and subject, current native
conversation/customer-channel binding, the saved inquiry's evidence reference,
and its original native customer message/snapshot. A revised source version
blocks identity use. It repeats the anchors before returning. MySQL uses a
READ COMMITTED, READ ONLY transaction, so the immediate selected-PK readback
does not merely repeat the first consistent snapshot. The transaction always
rolls back. The 250 ms SQL hints and 2 second admission check are bounded work
guards, not a hard wall-clock deadline.

For MAX, the first eligible source is an existing own common-goal account with
the exact subject and immutable inquiry, a zero initial reserve and no tracker
binding. A mismatching existing account blocks fallback. This permits later
saved estimates to retain their own fixed identity even when v1 created no
new positive-delta row. Otherwise, the reader requires exactly one old v1 row
for the exact saved profile and event, canonical old target/contract and actor,
and a saved tracker identity. Only ready, uploading, accepted and unknown rows
are eligible; those states already have identity, and are not interpreted as
confirmed delivery. The row's ClientID is limited to the old canonical 40 digits.
No latest row from another profile/inquiry is selected. Own identities retain
the separately admitted 64-digit maximum. Both paths require exact PK readback.

For Telegram, the source is the complete bounded set of verified
`advertising_visit_observations` under exact binary AnyTour / tgmini / Telegram /
counter / actor scope. A prefix count is checked before conflict projection;
65 rows means UNKNOWN, while at most 64 rows are inspected. Conflicting visit
owners, malformed relevant records, invalid clocks and multiple eligible
ClientIDs block the whole result. Multiple rows agreeing on one ClientID are
allowed. As in v1, an identity visit must be no later than the frozen model
event; this is an identity witness, not a claim of paid attribution. A later
visit cannot replace the earlier identity. The bounded prefix count and complete
projected list are read again and must match before the selected exact PK is
checked. This proves the observed stable set across those reads; it does not lock
out a future concurrent append. YCLID remains private source evidence:
YCLID-only has no ClientID shortcut.

Canonical behavior was read at app commit
`b678bc2f211c5f141751137453b199036491222d`:

- `ModeledConversionValueExport.php`, git blob
  `a90239a3706a60b7696fd63391b07ce07a95e001`: saved v1 identity resolution.
- `AdvertisingVisitRepository.php`, git blob
  `d56b03a838d653e9c43452fafead8f783d09c321`: complete bounded verified history and
  same-visit actor conflict checks.
- `AdvertisingVisitContract.php`, git blob
  `52f4e2c7d6c4cfce8720a571df61beeec6a86591`: canonical strings and source clocks.

`tests/identity.php` prepares 23 real in-memory SQLite cases covering exact MAX
profile and own-account reuse, immutable scope, CID limits, missing point
readbacks, native/profile changes and revisions, Telegram completeness,
ambiguity, actor conflicts, clocks, bounds, complete-history drift, YCLID-only refusal, storage failure
and outer-transaction preservation. The existing saved-reader fixture builder
is a verified excerpt, with explicit synthetic monetary seams. The actual new
identity class executes; every case verifies unchanged source/v1 rows. Faulted
point reads affect only the synthetic query parameters, never stored rows.

Status at source preparation: PHP execution is pending native source-only CI.
No live database, provider request, app installation or activation was performed.
