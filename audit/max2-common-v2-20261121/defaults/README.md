# Common V2: actual saved-model defaults and price parity

Source-only native fixture for the new common goal. Publish this directory as
`audit/max2-common-v2-20261121/defaults/`, beside the already sealed `source/`
package, in a complete `pyatkoff/max-search-bot` checkout. Run
`php audit/max2-common-v2-20261121/defaults/tests/default-parity.php` with PHP 8.2,
PDO SQLite, JSON and mbstring. It uses only fresh in-memory SQLite databases.

The fixture invokes the actual existing
`ModeledConversionValueContract::candidate()` and the read-only
`ModeledConversionPriceContinuity::adjust()` from the checkout, then the new
`Max2CommonValuePriceReadV2` and the default constructor path of
`Max2ModeledValueReaderV2`. It does not inject a replacement candidate, price
adapter or producer hash. It never invokes the old tick, configuration, price
audit writer, reconciliation, conversion sender or transport.

Two pure synthetic capsule builders are a verbatim excerpt of the accepted app
fixture at commit `b678bc2f211c5f141751137453b199036491222d`:
`tests/run_modeled_conversion_value_export_regression.php`, git blob
`e0d2616b275e5ecc331657cc4b47465c19ed11fa`. Before executing them the test verifies
the complete original fixture's git blob and the exact excerpt. The original
fixture's top-level code is not executed. These builders exercise the pure
semantic projection to prepare synthetic saved capsules; they are not a model
tick or a live assessment. Ordinary profile-envelope and native-source fields
are added without changing either capsule or its digest.

A private temporary assembly contains symlink views of the actual checkout
services and exact copies of the two new reader files. The fixture checks the
accepted old price-rule SHA-256 and verifies that every original service's
bytes are unchanged afterward. A negative source-pin check replaces only the
temporary symlink. No existing repository file is written.

Eight checks cover four monetary parity paths (known price, unknown price with
probability, stage fallback, stronger probability); the actual new reader's
default path and exact native inquiry anchor; exclusion of a later stored
priced profile; refusal of an oversized earlier price reference without a
larger fallback; and refusal of a changed old price-rule source. Every database
case verifies unchanged before/after rows, including synthetic v1 settings,
outbox and price-audit sentinels. The default-reader case verifies rollback and
no financial delivery permission. SELECT-only assertions cover the actual
read paths after setup.

This fixture checks the new bounded adapter's monetary parity for admissible
prior profiles. Its additional profile-ID cutoff and payload guards are
intentional constraints, tested separately; the old implementation is not
modified. SQLite supplies real JSON and transaction execution here, but does
not establish MySQL engine, lock, query-hint or live-data behavior.

Status at source preparation: **PHP runtime execution pending native CI**.
There is no local PHP binary. This package alone makes no claim of runtime
success, deployment, activation, processed conversions or observed live values.
The native runner must preserve source pins and report its actual exit status.
