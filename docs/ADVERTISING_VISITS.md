# Advertising entries for predictive conversions

Owner direction, 25 October 2026: model future tour purchases from current signals;
preserve all available observations while keeping export cutoffs configurable.
Telegram and MAX collection, conversations and Yandex integrations live on
different servers. This slice supplies the private receiving store, page contract
and manual-journal reader. It does not activate an HTTP endpoint, background import,
prediction, Metrika goal or advertising strategy.

## Confirmed source boundary

The owner supplied `/tgmini/index.php`, `save.php` and `bot_webhook.php`. The first
posts Telegram ID, ClientID, initData and advertising metadata; save logs the pair
and supplies only ClientID to `TelegramSubApi::getLink()/getLinkNew()`.
The provided save version has signature verification disabled and may receive an
empty ClientID after the frontend's 2.5-second wait. An unverified posted Telegram
ID must never become a verified identity merely because a page was imported.

Read-only static tracker inspection on 25 October, run40211239879 at
audit3968913c63954ad06e51a6a28353b405110ab888, confirms that getLink and getLinkNew
call saveLink, which persists UF_CLIENT_ID and an empty UF_CHAT_ID in the shared
Telegram main store. saveQuery fills UF_CHAT_ID upon subscription; processJoin
copies the link's ClientID into join-conversion storage. This existing path is
useful for subscribers but does not create the direct pre-subscription pair.
No PHP/bootstrap/database/log execution or server write occurred in that audit.
The Mini App itself was not found under the three inspected tracker roots; this
does not identify its actual host or prove installed equality with the uploads.

## Record and scope

One feed is explicitly scoped by `project_key`, `source_key`, `platform` and
`counter_id`. The trusted importer configuration, not the incoming JSON, owns
that scope. Telegram and MAX numeric identities are separate. Brand/project and
counter scopes never mix even if their decimal IDs happen to be equal.

Every advertising entry has a source-generated `visit_id` stable across transport
retries. A repeated ad entry gets a new visit ID even when ClientID is unchanged.
Each observation/enrichment has an immutable positive decimal `source_cursor`.
The producer must persist cursor order and make only committed observations
visible in its feed; an allocated sequence number alone must not authorize
advancing past an uncommitted transaction. IDs and cursors are JSON strings,
including values beyond signed 64-bit range. Timestamps are Unix seconds.

An initial observation can contain empty ClientID, YCLID and user ID; its identity
status is `missing`. A supplied but unvalidated messenger ID is `unverified`.
Only a source that actually validated Telegram initData (or the platform's
authenticated identity) may assert `verified`. This database does not perform
Telegram signature verification and has no public ingestion route.
`verifiedHistory()` returns bounded candidate observations with a nonempty
ClientID; it deliberately does not select an attribution winner. The existing
owner-selected MAX latest-known-ClientID policy and original event time remain
unchanged. Late availability of a link is distinct from the ad-entry time.

Later enrichment appends another observation for the same visit. Empty or
unverified later observations do not erase earlier verified ClientIDs. History is
immutable about the original entry time and cannot bind one entry to two
different verified messenger users. Conflicts roll back the complete page. It is
never converted into a fabricated zero purchase probability. Raw initData,
credentials, names, transcripts, arbitrary URLs and unknown fields are rejected.

## Atomic page import

Schema1 pages carry the four scope fields, `after_cursor`, `next_cursor` and up to
100 strictly ordered observations. Each row has exactly `source_cursor`,
`visit_id`, `visit_at_unix`, `observed_at_unix`, `external_user_id`,
`identity_status`, `client_id`, `yclid`, `campaign_id`, `region_id`.
The next cursor must equal the last row, or the after cursor for an empty page.
The transport must bound raw bodies at262144bytes before JSON decoding.

The importer validates the complete page before writing. MySQL serializes each
feed through its cursor-row lock; the observations and checkpoint commit in one
transaction. A changed replay, gap, stale overlapping append or row conflict rolls
the whole page back. An old exact replay verifies all original row fingerprints
and never moves the stored cursor backward. No outer business transaction is
silently committed. Migration063 owns all schema; runtime code performs no DDL.

`tools/advertising_visits.php validate` reads one bounded JSON page from stdin
without loading runtime configuration or opening a database. `import` loads the
existing conversation connection, verifies its active project and writes the
page. Both commands require explicit `--project_key`, `--source_key`, `--platform`,
`--counter_id` arguments. Output contains aggregates only; failures do not print
payloads, private IDs, SQL, credentials or source JSON. HTTP execution returns404.

## Mini App manual journal

Capture package `anytour-tgmini-20261025-1` is based on the supplied files;
installed bytes and a live pass are still unverified. It writes
`entries-v1.jsonl` outside the document root before invitation/subscription.
Its envelope has exactly `schema_version`, the four scope fields and
`observation`, following the row contract above. A source cursor is the row's
byte offset plus one. Entry IDs are stable across journal transport retries;
a new save request creates another entry. ClientID stays empty if the existing
2.5-second API/cookie wait expires; late enrichment is not yet implemented.
Telegram verification uses its production Ed25519 key, fresh auth_date and
sodium. Unverified IDs remain explicit. Legacy bot_start fallback may supply
metadata from a previous /start within a day, not proof of a new advertising click.

The existing CLI also accepts `journal-validate` and `journal-import`, with the
same four explicit scope arguments plus `--journal=/absolute/private/file`.
Validation checks the first bounded page without runtime bootstrap or SQL.
Import verifies the active project and resumes from the database checkpoint;
no caller-supplied offset can skip data. Each invocation reads up to 100 rows under
a shared source-file lock and imports one atomic page. Aggregate output includes
`has_more`; invoke again while true. A completed-file retry adds no observations.

Only a regular local file with no group/world permissions is accepted. Limits
are 512 MiB per file, 4096 bytes per line, 100 rows and 262144 encoded bytes per page.
Every byte offset, scope and row is checked before the page writes anything.
Resume compares the last committed observation's fingerprint to the copied file.
Changed/truncated anchors, unterminated records, wrong scope and unknown fields
fail closed; source data and checkpoint remain coupled by the existing database
transaction. Concurrent importers cannot append stale overlapping pages.

Copy the journal through an authenticated access-controlled channel into private
storage and preserve mode 0600. This CLI does not authenticate a sender: a local
JSON assertion alone cannot prove that Telegram verified an identity. There is
no public feed, automatic copy, cron, source credential/config change, goal
emission or bot-handler wiring here. Manual PHP upload and real
Telegram/Bitrix/writability checks remain pending.

## Following implementation

1. Confirm the uploaded Mini App package, private writable storage, signature
   status, ClientID and the existing channel-opening path on its actual runtime.
2. Add late ClientID enrichment with its original visit identity if required,
   without creating another invitation or double-counting a save request.
3. Add an authenticated feed/client or verified private copy using the existing signed
   server-to-server pattern, persist source progress and reconcile failures.
4. Connect Telegram events behind the existing goal owners; repair only the
   already evidenced handoff/customer-reply gaps with behavior regressions.
5. Add versioned current-trip/brand-aware predictions, retaining evidence time,
   confidence and budget semantics. Decide export timing and deduplication before
   any monetary event is emitted. No top-percent cutoff is fixed by this storage
   slice; late purchases will calibrate the predictions as they arrive.
