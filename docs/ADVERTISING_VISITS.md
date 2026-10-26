# Advertising entries for predictive conversions

Owner direction, 25 October 2026: model future tour purchases from current signals;
preserve all available observations while keeping export cutoffs configurable.
Telegram and MAX collection, conversations and Yandex integrations live on
different servers. This slice supplies the private receiving store, page contract
and manual-journal reader. It does not activate an HTTP endpoint, background import,
prediction, Metrika goal or advertising strategy. The opt-in private HTTPS
delivery below reuses this receiver; it stays off until explicitly provisioned
and enabled after source installation.

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
its complete installed bytes are not independently audited. On25October the
owner's real passage and an independent readiness GET confirmed a fresh
verified Telegram identity with nonempty ClientID and YCLID
([receipt1137/6403369948](https://github.com/pyatkoff/max-search-bot/pull/1137#issuecomment-6403369948)). It writes
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
no unauthenticated feed, goal emission or bot-handler wiring here. The source
capture/writability passage is confirmed as above; central receipt of the same
source data and private delivery acceptance remain separate checks.

## Opt-in authenticated source delivery

`AdvertisingVisitTransport` reads exactly
`https://tg.rhat.ru/tgmini/tgmini_identity_feed.php`, AnyTour/tgmini/telegram/
98615635. It has no caller-supplied URL, redirect, HTTP or certificate bypass.
The transport does not use `1.txt`, Bitrix, another brand's feed or an old
latest-ClientID cache. A wrong-counter journal fails closed; separate mixed
counter history before using this fixed feed. No row is silently discarded.

`key-init` creates independent Ed25519 signing and X25519 response-encryption
secrets in `max-search-bot-advertising-transport/receiver.json`, beside and
outside the application document root (0700directory/0600file). A retry reuses
the keys; no rotation or existing secret/config edit is performed. Only the
public keys/fingerprint leave the receiver. The source package contains those
public keys, never a shared bearer or either private secret.

The exact body (at most2048bytes) is domain-separated and signed. The source
authenticates it before opening a journal, checks project/platform/counter,
timestamp(-120/+60seconds), nonce and anchored checkpoint, then reads one
existing locked100-row page. The response is a sodium sealed box addressed to
the separate receiver key. Inside it are the exact request digest and source
time. Receiver limits400000wire bytes, validates the binding and complete
original page contract, and commits through `AdvertisingVisitImportService`.
The source never deletes or advances its history. Replayed signed read requests
have no acknowledgement side effect and cannot expose plaintext to a holder
of the request; response nonces/digests prevent substituting an earlier reply.
HTTPS authenticates the fixed source host; sealed-box encryption alone is not
sender authentication.

Build and installation are explicit:

1. Run `php tools/advertising_visit_transport.php key-init` on the installed
   receiver. Keep its private file there. `public-keys` returns only public data.
2. Build with `python3 tools/build_tgmini_feed.py --request-public-key=HEX64
   --response-public-key=HEX64 --output=/private/upload/tgmini`.
3. Back up the current source capture helper outside the public site. Upload
   only `tgmini_identity_capture.php` and `tgmini_identity_feed.php` into the
   existing `/tgmini/`. `index.php`, `save.php`, webhook, private journal and
   status script stay in place. The helper's path-only method reuses its exact
   storage owner; capture format and redirect remain unchanged. Source code is
   packaged for PHP7.4+ with private-namespace compatibility helpers. The
   capture-helper build becomes `anytour-tgmini-20261026-2`.
4. Run `php tools/advertising_visit_transport.php once` for one authenticated
   page import. Its output contains counts/cursor only. This does not enable a
   recurring reader. `enable` accepts and commits one page before atomically
   enabling the worker. Failed auth/HTTP/TLS/page validation never enables it.
5. `status` returns readiness and aggregate last-success telemetry. `disable`
   removes only the enable receipt; all keys, source history and DB observations
   remain intact. Subsequent `enable` resumes the DB checkpoint.

No new scheduler is installed. The existing five-minute `cron_metrika.php`
registers an isolated shutdown worker after its normal uploader finishes,
including an empty-queue exit. Unconfigured/disabled is a no-op with no network
or DB access. Once enabled, one page per tick is serialized by a private local
lock and the existing DB cursor transaction. Failure is reported as an aggregate
private diagnostic; the uploader result and goal semantics stay unchanged.
Source calls are bounded to2seconds connect/4seconds total. A lost reply,
source restart or lock conflict leaves the committed cursor unchanged for retry.

The CLI is HTTP-inaccessible. Private telemetry never includes customer/native
IDs, ClientID/YCLID, raw initData, request signatures, source lines or key data.
Importing an entry does not emit any conversion or select an attribution winner.
Source upload, first actual row receipt and recurring delivery acceptance must
each be recorded separately from local tests, required CI and installed code.

Protocol primitives: [sodium signatures](https://www.php.net/manual/en/function.sodium-crypto-sign-detached.php)
and [sealed boxes](https://www.php.net/manual/en/function.sodium-crypto-box-seal.php).

## Telegram signals before source installation

The canonical dispatcher now asks the existing conversion-goal owner to observe
an exact persisted Telegram input before its legacy goal-queue call. The same
owner observes a persisted manager request even if its MAX-only queue cannot
accept it. The observer writes only `advertising_signals`; it cannot send a goal,
change conversation state, own a funnel stage or train a probability model.

The first observed `customer_activity`, `manager_request` and `customer_reply`
per scoped conversation are immutable and independently deduplicated. These are
observations since installation, not historical first-ever actions or distinct
people/clicks. Source IDs reference canonical message/event rows. Timestamps use
those rows' existing database-recorded time, distinct from observation capture;
they are not a reconstruction of an earlier native messenger timestamp.

Incoming observation requires the exact recorded native message/callback ID,
matching recorded input kind, current project, non-test active private Telegram
conversation and consistent customer-channel identity. Missing source rows,
negative group chats, other brands/platforms and callbacks-as-replies are rejected.
A reply additionally needs a real waiting-manager event, subsequent manager event
and prior outbound manager message. Same-second message ordering uses IDs, and
the evidence references are retained without text, profiles or raw payloads.
Storage failures are swallowed by the observer and do not alter the legacy queue
result. It never opens or commits a transaction; observations inside an existing
transaction roll back with their canonical evidence.

The runtime scope is explicitly AnyTour/Telegram/tgmini/counter98615635; other
brands need their own reviewed scope. Migration064 adds only the analytic table;
the applied063 and canonical business tables are unchanged. Existing MAX policy,
goal semantics, conversion upload, configuration and schedules stay unchanged.

`tools/advertising_signals.php status` and `preview` require the same four explicit
scope arguments as the visit CLI. They check the active project, are CLI-only and
read-only, and return aggregates without customer IDs, ClientIDs or source paths.
Preview accepts `--after_id=0 --limit=100` for bounded keyset reads.

Identity assessment is computed on read, never frozen as a final attribution:
only verified same-scope entries at or before the event are eligible, and both
observation and receiving times must be available at assessment time. Zero known
ClientIDs means `missing`, one distinct ID means provisionally `unique`, and two
or more means `ambiguous` with no winner. Repeated entries with one ID do not add
ambiguity. Later verified information can assess earlier retained signals while
preserving event time; another late browser ID can revoke a prior unique result.
There is no claim of complete source history, a chosen advertising lookback or
an export-ready match. Uncertainty is never represented as zero purchase chance.

## Following implementation

1. Confirm the uploaded Mini App package, private writable storage, signature
   status, ClientID and the existing channel-opening path on its actual runtime.
2. Add late ClientID enrichment with its original visit identity if required,
   without creating another invitation or double-counting a save request.
3. Add an authenticated feed/client or verified private copy using the existing signed
   server-to-server pattern, persist source progress and reconcile failures.
4. Verify natural Telegram signal capture and connect the actual source transport;
   then decide the explicit attribution/export policy behind existing goal owners.
5. Add versioned current-trip/brand-aware predictions, retaining evidence time,
   confidence and budget semantics. Decide export timing and deduplication before
   any monetary event is emitted. No top-percent cutoff is fixed by this storage
   slice; late purchases will calibrate the predictions as they arrive.
# Telegram dialogue conversions through imported identities

The owner's 26 October instruction to finish the Telegram dialogue→advertising
entry→existing Metrika goal seam authorizes this ordinary delivery separately
from modeled conversion values. `TelegramDialogueConversions` uses the existing
`advertising_signals`, immutable `advertising_visit_observations`, conversion
outbox and `MaxChannelConversionWorker`; no new worker, queue or schedule.
The existing five-minute `cron_metrika` runs when either platform is opted in.
MAX activation, cursors, latest-known-ClientID policy and lookup remain separate.

Migration067 adds default-off Telegram settings and immutable per-row platform,
signal and imported-identity provenance to the existing outbox. Existing rows
retain `platform=max`. Pre067 source compatibility keeps MAX working; Telegram
cannot activate without the forward migration. The original three goals/IDs and
counter98615635 remain pinned; the bridge never configures goals or campaigns.

CLI-only `php tools/telegram_dialogue_conversions.php collect|send|off|status`
uses the existing config and read-only project initializer. `collect` and `send`
verify the existing exact goals with GET before changing only Telegram mode.
First activation freezes the current signal ID floor and actual activation
instant; resume retains them. No old signal or historical source is backfilled.
`collect` resolves identities but performs no conversion POST. `send` permits
ordinary existing-goal POSTs in the existing worker; neither CLI command forces a
tick. Off stops subsequent TG work, retains receipts and may leave in-flight work.

The three retained signals map to `max_bot_activity_start`,
`max_manager_request`, `max_customer_reply_after_manager`. Their actual recorded
occurrence is preserved; a request is a durable handoff, and a callback alone is
not a customer reply. Exact project/channel/customer/private-user linkage,
owner/test exclusions and the original destination exclusion must hold.
Imported entries must be verified AnyTour/tgmini/Telegram/counter98615635, with a
visit no later than the event and actual observation/import available by lookup.
One distinct eligible ClientID resolves the event; zero or multiple ClientIDs
remain pending with an explicit reason. A later import may enrich an earlier
retained event without changing its event time. The first successful ClientID and
source observation are frozen. This conservative TG rule does not alter MAX's
different owner-selected policy. Current scope is rechecked before upload.

For managed TG goals, the live queue hook consumes the shared outbox path instead
of creating a parallel legacy YCLID CSV event. A bounded signal cursor recovers
durable captures, including a manager request created after the pre-handoff hook.
Previous legacy markers and existing outbox rows prevent repeated stages. Existing
atomic claims, accepted receipts and GET-only unknown reconciliation remain the
delivery owner; uncertain POSTs are never blindly resent. MAX retains its existing
five-upload/three-poll bounds; Telegram has the same separate per-tick bounds,
so either platform's backlog cannot consume the other's delivery allowance.
All outputs are aggregate; no native ID, text or key appears in public status.

SQLite and native MySQL tests exercise the same path. Code, installed migration,
explicit activation, natural event, upload acceptance, validated rows and Yandex
visit linkage are separate evidence. This implementation does not establish any
of those live outcomes by itself. No Price, probability, commission or modeled
value is emitted; future modeled export remains disabled.
