# Prospective common-goal runtime preparation

New source only. Nothing here installs schema, activates a live stream, registers
a cron callback, discovers credentials or invokes the existing model/export tick.
Frozen migration112 and its accepted source package are unchanged. Migration113
must be rechecked against the app head before publication and installed only by
the normal forward migration path.

## State and activation

Migration113 adds five private tables: settings, ticks, profile work outcomes,
lookup intents and authenticated entry/message frames. Its sole initial settings
row is OFF. Missing/incompatible schema fails closed; request code never creates
or repairs tables.

`Max2CommonValueStateV2::activate(sourceGitSha, requestSha)` is an INTERNAL method
for a future source-checked operator path, with no public endpoint or CLI in this
package. The hashes record provenance, not permission. It samples the trusted
server clock and actual current MAX(id) from AnyTour saved profiles inside one
transaction. It initializes collect mode, a stable activation hash, the floor and
cursor at that maximum. Both max and telegram profiles are included; there is no
platform filter. Repeated activation cannot reset the floor or replay history.

`setMode(off|collect|send, requestSha)` retains the epoch, original floor and cursor.
It refuses a transition during an active tick. A source-checked future caller
must bind these operations to the actual release/authorization evidence; synthetic
fixture values never provide that authority.

Ticks have a180-second lease, immutable ID and aggregate summary. Each processed
profile records its outcome privately before the cursor moves. HOLD and UNKNOWN
remain observable; they are not erased by cursor advancement. An expired worker
cannot move another tick's cursor. Expired ticks become unknown. The runtime does
not automatically replay held profiles or clear a consumed lookup intent.

The signed reader client invokes its beforeRequest callback immediately before
transport. The gateway commits one lookup intent keyed by activation+profile+
action before that callback returns true. New nonces and changed request digests
cannot obtain another list or recheck for that accounting step. An uncertain
commit returns no call permission. Once a read occurred, its observed/unknown
result digest is saved; no raw token, private key, init data or response body is
stored in this table.

## Worker integration contract

The constructor receives State, a factory for the accepted own Repository, the
actual new signed LookupClient, a factory for the separate new MetrikaClient,
and two INTERNAL source callbacks. The app's source-checked integration supplies
them; request JSON is not an allowed adapter.

`page(cursor, limit, floor)` returns an ordered list of `{profile_id: string}`
entries. The complete page is validated before its first profile is handled.
Default page size is1 and the cap is3. `project(profile, gateway, activation)`
returns READY with binding/estimate/identity/recheck, or HOLD with a reason. The
existing new saved-profile reader's exact-ID `read()` can be called inside this
projection; native subject, journal, actor and entry-frame proof remain the
projection adapter's responsibility.

The gateway provides `lookup(actor, context)` and
`recheck(originalPrivateLookup, recordId)`. The original private lookup must stay
unchanged; app binding state is added to a copy of normalized candidates. Each
profile gets at most one list and one recheck. A missing/partial/unknown result
forces HOLD even if an erroneous projection tries a full-value fallback. An
unknown intent commit stops later provider operations in that tick.

Collect mode only captures own reservations. In send mode each tick performs at
most one known-upload poll, one goal-readiness read and one new upload attempt.
Goal readiness precedes `beginAttempt`. Only a committed ATTEMPT_CLAIMED event is
passed to the new client. That client rebuilds CSV and checks the persisted hash.
Commit UNKNOWN never returns a sendable row. Actual HTTP status from the client
is retained in the normalized result; no success code is invented. A normalized
result SHA hashes the bounded validated outcome, not a claimed raw provider body.

Accepted and processed are separate. A known exact upload ID is polled without
resending. PROCESSED requires the repository's exact source/line quantity checks.
Unknown/failed states keep their amounts reserved. No provider outcome changes
goal678187097, old amounts, old settings or old sender behavior.

Worker output contains only aggregate counts. COMPLETED means the bounded tick
finished; held/unknown counts still describe incomplete individual projections.
It is not a declaration that every inquiry converted, or that a provider event
was matched to an individual visit.

## Frame schema for the separate authenticated capture helper

`max2_common_entry_frames_v2` is immutable capture storage: frame_key primary key,
unique nullable entry_id, exact project/platform/actor/customer-channel/customer/
conversation, nullable entry/publication/version/entry_key as one group, observation
clock, bounded frame_json, frame_sha256 and activation_sha256. Message frames may
have all entry columns null. Capture must verify actual webhook authentication,
the exact canonical native message or post entry and subject before inserting.
The helper is prepared separately by root; this package does not invent that proof.

MySQL JSON can normalize whitespace and key order. Frame hashes must use a
deterministic canonical decoded object or typed tuple, not raw returned JSON
bytes. The capture helper owns that canonicalization. No raw secret or init data
belongs in frame_json. Frames must not be updated/deleted to repair conflicts.

## Verification

`tests/state.php` covers default OFF, current project maximum and clock sampling,
immutable activation, mode/lease behavior, durable lookup-before-call, changed
nonce rejection, unknown commits and retained held-profile outcomes.

`tests/worker.php` uses the actual accepted policy/repository/lookup/client classes
with memory-only SQLite and fake signed-read/provider transports. It covers
bounded capture, one poll/send per tick, actual202 normalization, readiness before
attempt, committed intent before network, unknown intent no-call, and forbidden
full fallback after unknown lookup. Synthetic old v1 data stays unchanged.

The local Python SQLite translation of exact112+113 DDL succeeded, including
default OFF. PHP is unavailable locally: lint and fixture execution remain native
SOURCE-ONLY CI work. SQLite is not evidence of MySQL row-lock concurrency, actual
database permissions, live schema installation or runtime activation. No native
DB/provider calls or external source changes were performed for this preparation.
