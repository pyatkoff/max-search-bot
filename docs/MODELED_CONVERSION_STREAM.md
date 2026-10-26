# Prospective modeled-conversion observations

Canonical plan: issue1140, v3. This journal is the input of future semantic
profiles and comparison with existing signals. It does not infer a trip,
probability, commission, monetary value, label or export permission.

The existing cron_metrika shutdown observer calls the same feature after the
ordinary uploader. No second schedule, worker or queue is added. Errors are
isolated and logged as fixed categories. Existing uploader bytes and output
after its header remain unchanged. The feature starts OFF with no state row.

After reviewed installation, the CLI-only tools/modeled_conversion_stream.php
supports status, start, tick and stop. start establishes once-only per-source
ID floors and current receipt time. It copies no old text. Resume preserves
those floors/cursors. stop preserves observations. Runtime uses only the
canonical AnyTour project/DB; initializeReadOnly verifies project configuration
without changing project roles or config. An anonymous HTTP request cannot run
the CLI. Source captures/activation need the current owner scope, not a replay
of old consumed operations. Never emit private snapshots via status or logs.

Sources: canonical messages and conversation_events with bound matching
customer/channel/project; only MAX/Telegram, excluding owner/test conversations.
All roles/actions are retained, without a manager/budget/stage/text filter.
Existing tgmini Telegram entries are scoped to AnyTour and counter98615635;
known owner/test identities are excluded. Entries without a chat stay entries,
not invented people or negative outcomes. Telegram Business/desktop archives,
other brands and providers are not read by this feature.

Each tick is one repeatable-read transaction with the project state row locked.
A database-scoped advisory lock returns busy immediately for another observer;
a five-second soft deadline limits work between statements, not a running query.
Forward pages
and bounded cyclic sweeps recover source IDs committed behind an advanced
forward cursor. Sweep and reconciliation delay grows with retained source count;
there is no fixed all-history freshness SLA. Reconciliation cycles over already admitted IDs to detect
edits and physical disappearance. Revoked scope is distinct from deletion.
Only current source snapshots are observed: intermediate edits between two
reads are not recovered or claimed complete. Source-ID floors intentionally
exclude pre-start allocated IDs, including transactions still in flight then;
do not claim that this represents every business event after activation.
The source clock is database persistence, not authenticated provider occurrence.

The private journal and cursor/quota update commit atomically. Exact unchanged
content is free of new versions; A→B→A has three observed versions. A crash
before commit leaves neither a journal append nor an advanced cursor. Native
MySQL tests include actual late transaction commit and competing observer lock.
No source record, read marker, shift, dialogue, goal or delivery state is changed.

Limits are500native metadata/payload rows and2MiB copied snapshot JSON per tick,
10000new revisions per UTC day. Body metadata is inspected before copying;
oversize records retain hash/scope/explicit omission instead of partial text.
Scope-approved pages are bounded; receipt counts are fetched native rows, not
MySQL examined rows or all advertising clicks. Budget exhaustion keeps the
unprocessed position for later; caps do not justify dropping observations or
inventing zero outcomes. Counts/timestamps/source coverage are public-safe;
source IDs/roles/text/metadata/ClientID/YCLID remain private in the common DB.
Native-row counts exclude aggregate watermark queries and the feature's own state.
Payload bytes include omitted/deletion/scope-change metadata on actual append.

Tables065: state and latest-source indexes plus append-only observed versions.
They are analytical history, not a second work queue. All received observations
are retained. There is no automatic archive backfill, deletion/retention policy,
source upload, paid/model/provider/customer call or Metrika conversion output.
The first source receipt and recurring receipt must be verified separately
after activation; code/CI/installed do not establish either one.

Acceptance: local SQLite and native MySQL exercise new pages, exact retry,
rollback, late commits, same-ID edits, reversion, deletion/scope change,
concurrency, role/source bounds, row/byte/daily caps, stop/resume and clock
regression. Synthetic700-source transfer is a development/control result,
not700real people, profiles or a measured predictive improvement.
