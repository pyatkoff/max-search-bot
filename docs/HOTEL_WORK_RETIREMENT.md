# Administrative retirement of one hotel contour

This independent package supersedes old-queue continuation PR1261. It retires
one exact existing queue without completing its claimed/UNKNOWN stage, accepting
TR40, replaying research, revoking OAuth or creating a replacement queue. The old
branch and accepted source/history remain retained.

## Boundary and authority

The server CLI `tools/hotel_retirement.php` exposes this internal service. The
fixed-scope deployment helper `tools/hotel_retirement_ops.php` reuses it for the
explicitly authorized retirement of `HOTEL-CONTINUOUS-20261027`.
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


## Recovery of the deployed migration083 privilege failure

The ordinary application MySQL identity failed with error1419 while creating
the first trigger with binary logging enabled. This does not establish whether
the deployment OS account already has root/socket, a private maintenance client
profile, or noninteractive sudo authority.

The normal production deployment first runs the fixed `migrate083` helper.
It verifies the unchanged migration SHA256, all applied checksums and the exact
pending set. If083 is already applied, it does no privileged migration work.
Otherwise only083 may be pending; an existing partial trigger installation is
held for inspection. No trigger is dropped and no migration record is invented.
The canonical `MigrationRunner` receives a verified maintenance PDO for this
same database and server. The usual application migration command follows.

Provider selection inspects privileges in read-only rolled-back transactions.
It reuses the application identity when eligible, the existing local root/socket
identity only when the OS process is already root, or an existing private client
profile belonging to root/current OS user. Only `[client]` authentication fields
are used; profiles cannot select another target. Credentials, filenames' contents
and exception text never leave the server. The workflow may reuse already
configured `sudo -n` authority only for the fixed helper after exit3 reports no
eligible provider. No grant, password, account or global MySQL setting changes.
An unavailable existing identity leaves deployment held.

After the ordinary migration command, `retire-old` uses the existing active
AnyTour administrator check and actual fresh preflight. It persists one private
request outside the webroot, with mode0600 in an owner-only directory, and
fsync/readback before the service transaction. Its request ID is stable:
`retire-HOTEL-CONTINUOUS-20261027-owner-decision-20261101`.
A stale CAS leaves that original intent held. A lost acknowledgement reads the
immutable committed retirement record without applying a second retirement or
clearing another queue's lane. A server advisory lock serializes helper actions.
The source queue must still be stage41 with19 accepted batches and claimed or
UNKNOWN work. Any mismatch holds instead of changing the requested operation.

### Retained-input isolation scope

The owner-approved retained base is the prior operator-verified private snapshot:
246123355 bytes, SHA256
`61917edb636c752176a5d369d885e1aea249dae8be0d1764d46d7b744b5ca1f2`,
attested at2026-11-01T15:05:22.967454+00:00. This helper does not reopen or copy it
and does not access Mac or Windows checking chats.

The three operator isolation attestations have the explicit scope
`retained-input-isolation-v1`: hosted Library/native writers cannot address that
private retained copy; old mutable Library IDs are historical noninputs; late
old-queue results cannot be admitted. The same atomic retirement installs the
server-side queue/receipt/event/audit fence. This is not a claim that every hosted
run terminated or that all Library write grants were revoked. Those outcomes
remain unknown. The complete policy is saved with the private request and hashed
into the retirement receipt. Its future queue allowlist is empty and
`new_start_allowed=false`.

The original CENTRAL41 remains historical UNKNOWN with zero acceptance credit.
Readback checks all original queue fields other than state/update timestamp,
exact original receipt bytes/hashes, all37 trigger names, inactive old
subscriptions with pending/leases cleared, and conditional release of the old
lane. A different lane owner is preserved. No research, resume, native task,
replacement queue or OAuth revocation is invoked.

The additional regression runs only in isolated SQLite/native MySQL fixture
databases. It exercises provider target checks and secret redaction, the real
canonical083 runner, durable intent and stale CAS, crash-before-apply and
lost-ACK-after-commit, original receipt preservation and replay after another
fixture queue owns the lane. It is not production execution evidence.
