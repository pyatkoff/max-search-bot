# AnyTour price reliability and 36-hour activation

Status: source preparation; production activation requires Pavel's separate approval.
This change has no recovery operation and must not replay the November 15 TV1236 operation.

## Accepted policy

Pavel approved a 36-hour life for a provider observation on 2026-11-16. Age starts
at the original observation, not at export, caption edit or feed publication.
Exactly 36 hours is expired. The transport snapshot remains valid for at most
15 minutes, additionally bounded by the observation's remaining life.

The collector's existing 24-hour due threshold is a separate collection policy.
It is not extended to 36 hours by this patch. The existing 1800-second callback
budget and 300-second finishing reserve are operational limits, not a promised
30-minute end-to-end service level.

The publisher accepts the explicit 24- or 36-hour contract in each snapshot.
Historical 24-hour receipts keep their recorded limit. The price record ID and
operation identity do not change merely because the policy changes. A completed
operation with the same current native record takes the existing unchanged path.
Terminal and uncertain outcomes continue to block replay.

## Confirmed starting point

The owner's server receipt at 2026-11-16 17:02:51 UTC reports `pending=null` and
557 completed hotels. The last cycle, collector `43841494167`, is
`budget_exhausted`: 152 updated pairs, 304 scanned pairs, 304 unscanned pairs.
This is a bounded partial pass, not a remaining TV1236 pending operation.

The separate runtime receipt at 17:08:52 UTC confirms app HEAD
`f7672e01b2be618fb16138debe653bcef742da15`, clean tracked files and an installed
608-row bundle, 583598 bytes, SHA256
`ff8adc4a22fca3d1305648688a72fac23dfaa203a917fe4eb29d49f09a9827e1`.
Source preparations and newer GitHub heads are not evidence of a server install.

The new bridge assembled from this source is 599113 bytes, 628 pairs, SHA256
`420264aed77b93957b235c80d9aeb07b2b5414796045a4e7df4769ae738e4cf9`.
Rebuild and compare after any publisher source or roster change. Concurrent
content work owns additional publications; integrate its current admitted roster
before activation and never replace a larger installed roster with this older one.

## Reliability changes

* Both app checkout Sync paths acquire the existing monitor journal's exclusive
  flock in the same remote Bash process as `git reset`. The descriptor is opened
  read-only; deployment waits up to 2100 seconds, checks the inode again, and
  fails before resetting if the lock is unavailable or its file is replaced.
  This serializes checkout changes, not every later deploy configuration/DB step.
* The publisher revalidates the installed HEAD, tracked cleanliness and all
  22 exact source pins after acquiring its own journal lock and before appending
  any state. Git failure cannot be interpreted as a clean status. Source hashes
  are checked in one ordered Git invocation with exact output cardinality.
* The global journal is read a line at a time. Every prior collector ID remains
  part of replay admission; the original full-prefix SHA256, byte count and line
  count remain available to historical recovery guards. No journal rotation,
  truncation, checkpoint replacement or history rewriting is introduced.
  Corruption and incomplete records stop the pass. Trailing empty lines can be
  inspected but stop a mutating pass before any append.
* A snapshot refresh reads only the unprocessed roster tail, including the
  current row. Saved source validation, native message checks and the three
  feed readbacks keep their existing responsibilities.
* `timings_ms` reports accumulated runtime validation, history read, monitor read,
  post context, post edit, feed preparation, feed writer and feed readback time.
  Failed stages also contribute their elapsed time.

The result adds `result_status` (`completed`, `partial`, `blocked`, `skipped`,
`inspected`), `scan_complete`, `all_pairs_applied_or_unchanged`, and
`no_fresh_observation_pairs`. A full scan with missing observations or holds is
partial. A successful bounded process exit is not proof that all prices are
current. Legacy `ok` and journal cycle states retain their existing meaning.

## Activation order

1. Review and pass native CI for the app deploy mutex and compatible publisher.
   Publisher sources live on the existing content carrier, not app `main`.
   Integrate only this scoped source diff; preserve concurrent content work.
2. Approve and deploy the app checkout mutex through its existing deployment.
   Its only new server coordination is a read-only open and exclusive flock of
   `/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl`.
3. **Phase A:** pin both validation and production publisher references in
   `poisk-turov-test/.github/workflows/collect-anytour-tours.yml` to the exact
   reviewed bridge commit. Keep the reader at 24 hours. Use the next already
   scheduled original run for delivery; do not dispatch or rerun a collector.
4. Obtain a read-only receipt of the installed bundle SHA256/size/roster and
   runtime HEAD. Verify no old consumer remains in flight and no queued old
   delivery can subsequently restore the 24-only bundle. Reconcile current
   content-roster ownership before proceeding. A merge or green build alone
   does not satisfy this installed-state gate.
5. **Phase B:** after approval of the concrete reader patch and the Phase A
   receipt, enable the reader's five strict 36-hour predicates, metadata and
   expiration clamp. Retain the same compatible publisher pin. Confirm installed
   file hashes and the reader metadata with read-only checks.
6. Observe the next ordinary original callback: fresh valid records follow the
   existing editor, verified clickable price and feed path. Already completed
   current records are unchanged; no old operation is replayed to prove links.

The existing SCP copies the reader before the bundle and is not a transaction.
Therefore a single combined activation is insufficient. Bootstrap also delivers
the reader separately and must respect the same installed-bridge prerequisite.
Rollback of the reader to 24 is compatible with the bridge; rollback to a
24-only publisher is inadmissible while the reader is at 36.

## Monitoring decisions for discussion

Use the original collector and its saved observations. Track four separate facts:
the expected original event arrived; collection completed; a valid candidate is
available; that record was applied to both native messages and all three feeds.
An empty successful search, a provider error and an unscanned window are distinct.

Suggested operating target: revisit due work daily with the 36-hour price limit
as the hard admission boundary. Prioritize published hotels approaching expiry,
then other due published hotels, then the remaining existing calendar work, with
fair rotation inside each group. Supplier request budgets and the calendar
horizon require capacity evidence before a policy change.

Suggested alerts, not activated here: two missed hourly event slots; an unresolved
pending or unknown write outcome; candidates waiting for application; remaining
unscanned pairs; and published observations approaching or exceeding 36 hours.
Use a single incident identity with recovery notification instead of repeating
the same alert each pass. Notification channel and thresholds remain a user
decision.

The 36-hour reader/publisher admission limit does not by itself remove numeric
prices from already published static posts or XML after expiry. Automatic stale
price removal is a separate content operation. It needs an agreed presentation,
identity/CAS checks, durable per-channel receipts and approval before execution.
The current patch must not be described as having implemented that removal.

## Validation boundary

Local PHP tests use PHP-WASM with explicit success markers because that CLI can
return process exit 0 after a PHP fatal error. Native PHP CI remains required.
The history regression processes more than 100 MB under a 32 MB PHP memory limit.
The deploy tests execute the actual Sync shell blocks with inert SSH/Git/PHP and
real native Bash/flock on temporary files. No supplier, customer message,
production feed, recovery or financial operation is used for validation.
