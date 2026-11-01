# Administrative retirement of one hotel contour

This independent package supersedes old-queue continuation PR1261. It retires
one exact existing queue without completing its claimed/UNKNOWN stage, accepting
TR40, replaying research, revoking OAuth or creating a replacement queue. The old
branch and accepted source/history remain retained.

## Boundary and authority

Only the server CLI `tools/hotel_retirement.php` exposes this internal service.
It requires the current active AnyTour project administrator, checked in SQL
after locking the existing HOTEL lane and queue. The expected original OAuth
owner is an equality pin, not the executing connector. No MCP tool, cron hook,
model call, worker capability or public HTTP apply route is added.

Migration083 installs an initially empty immutable retirement audit and database
triggers. Installation alone retires no queue and changes no lane. The service
checks every expected trigger definition and its two-row failure gate before
application; incomplete installation means HOLD.

A private request needs:
- the exact queue/admin/expected owner;
- a fresh preflight state digest and one stable request ID;
- an operator-verified backup reference and actual SHA256;
- strict true attestations that native writers, Library writers and pending
  callback effects are stopped or completely fenced, with a concrete evidence
  SHA256.

The backup URL alone is not byte proof. These external attestations are not
verified by the database service and are labelled as such in its receipt.
Unavailable isolation means HOLD. Native task shutdown and Library data custody
are separate operator actions; this package cannot terminate a hosted run or
block a Library API. It does not delete common cron, chats, other queues or data.

## Exact server action

After the small package is independently released through normal checks, use
the existing production administrator shell and private request file:

```sh
php tools/hotel_retirement.php preflight /PRIVATE/retirement-request.json
php tools/hotel_retirement.php retire /PRIVATE/retirement-request.json
```

These are prepared commands, not evidence of execution. Preflight serializes on
the same SQL lane/queue but makes no business mutation. Apply must use the exact
digest just read. Keep both the request and all evidence private and retain the
same request for lost-ACK reconciliation.

One transaction preserves the original queue/claim/run and complete per-queue
receipt/audit image, excluding subscription callback credentials from the
duplicate image; the original inactive subscription rows retain their history.
It changes only the exact old queue to terminal `retired`, deactivates all of its
AnyTour subscriptions across old connections, conditionally frees the lane still
owned by that queue, then commits the immutable retirement marker. A competing
writer must serialize against the actual lane/queue/receipt locks.

Both fault boundaries roll back terminal state, subscriptions, lane and audit.
After commit, database triggers reject late inserts, updates and deletes on the
old queue, receipts, subscriptions and all existing handoff/recovery/skip/
execution-attempt audits. They also prevent reattaching the retired queue to the
shared lane or editing/deleting its retirement marker. Cached old PHP cannot
bypass the fence. A different future queue may acquire the free lane normally;
this service never creates it.

Accepted batches, stage index, original claim/run and all original receipts are
unchanged. Claimed/UNKNOWN work is retained as historical UNKNOWN with zero
acceptance credit, not labelled failed, cancelled or accepted. Current status
reports the queue as inactive `retired`; stale same-ID create cannot resurrect
it and retired subscriptions are rejected before callback verification.

## Verification and live readback

The existing queue regression exercises SQLite and native MySQL8 using the
actual migration. It checks false/missing isolation, backup/admin/owner/CAS
denial, two transaction rollback boundaries, a real old raw SQL process racing
retirement, immutable source receipts, all-old-connection subscription shutdown,
other-queue preservation, no old pending, lost ACK after a different fixture
queue acquires the lane, and permanent resurrection denial. These are isolated
fixtures, not production shutdown evidence.

After actual application, read the original queue state/counters/claim, original
receipt digests, retirement receipt, inactive old subscriptions and HOTEL lane.
A successful retirement receipt does not prove native/Library shutdown; retain
the separate isolation evidence. Never take repeated MCP Access denied or an
unsuccessful SSH login as an applied administrative action.

