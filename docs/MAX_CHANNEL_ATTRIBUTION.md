# MAX channel attribution

Owner decision, 2026-10-15: select the latest known ClientID; use the actual
occurrence time of each conversion event, reconciling tracker timezones separately.

## Selection

`MaxSubscriptionAttributionPolicy` accepts MAX subscription candidates normalized
by the SELECT-only tracker adapter. Match the exact positive external MAX user ID and
effective counter. A nonempty `UF_METRIC_SOURCE` takes precedence over `UF_SOURCE`;
an unknown explicit override must not fall back to another counter.

Select the highest canonical decimal tracker record ID with a valid nonempty
ClientID. This matches the existing `ORDER BY ID DESC` tracker read. A newer empty
record does not erase a previously known ClientID. IDs remain strings, including
values larger than signed 64-bit integers. Conflicting copies of the winning
record fail closed. Observation time is optional metadata, not an ordering key.

The latest known ClientID may have become available after the event. Do not add a
pre-event ClientID cutoff to this owner-selected rule. Runtime enrichment persists the resolved attribution once per event; retries must retain
that resolution and the original event time.

## Event time

Use the original message/manager-response event time, not queue insertion,
enrichment, retry or subscription time. Convert the instant to Unix seconds.
The ClientID selection policy never reads the clock or changes this timestamp.

Read-only source/clock inspection on 2026-10-15 confirmed:

- tracker FPM `date.timezone=Europe/London`, OS timezone `Europe/London`;
- Bitrix timezone conversion disabled, timestamp writers construct Bitrix DateTime;
- `UF_DATE` and `UF_SUB_DATE` are DATETIME without a CURRENT_TIMESTAMP default;
- MySQL uses SYSTEM/BST and was UTC+3600 during the capture; latest tracker time
  was consistent with that local clock;
- the conversation trial verified its message/start database clock was UTC.

Interpret tracker wall timestamps as `Europe/London`; never subtract a fixed hour.
London uses BST in summer and GMT in winter. For timezone-free historical values
inside a DST overlap, require an explicit offset or other reliable disambiguation.
Reject nonexistent local times rather than silently normalizing them.

Evidence: [tracker time inspection](https://github.com/pyatkoff/tour-max/actions/runs/38726287142),
[conversation trial](https://github.com/pyatkoff/max-search-bot/actions/runs/38724100239).

## Runtime flow

Migration `037_max_channel_conversion_outbox.sql` creates only feature settings
and its event outbox. Initial mode is **off**. It changes neither subscription
records nor existing Metrika goals or counters.

The canonical `MetrikaConversionGoalService` supplies conversation context to
`MaxSearchApi::queueMetrikaGoal`. For an eligible MAX conversation in collect/send
mode, that method captures the first recorded occurrence in the SQL outbox.
Other calls retain the existing YCLID path, including Telegram and CRM goals.

Eligibility requires project `anytour`, channel `max`, a non-test conversation,
exact positive `customer_channels.external_user_id`, and the matching negative
internal chat ID. Entry must have either a registered canonical MAX publication
or an existing project-owned MAX source with `handling_mode=manager`. A tracker
identity match does not itself prove channel subscription or a post entry.

| Existing goal | Recorded event time |
| --- | --- |
| `max_bot_activity_start` | First accepted customer message, callback or contact after entry/activation |
| `max_manager_reply` | First recorded successful outbound manager message after entry/activation |
| `max_customer_reply_after_manager` | First customer message/contact after that manager message; callbacks excluded |

The two reply goals retain the existing waiting-manager/manager-message guards.
The existing destination exclusion also applies. Timestamp ordering uses
`created_at,id`, so a later message in the same second is retained. UTC is
verified before capture. The mirror's `messages.created_at` is the persisted
accepted-event clock; a raw provider timestamp is not currently persisted by the
recorder. No queue/enrichment timestamp is substituted for it.

`cron_metrika.php` runs a bounded worker before checking the old CSV queue. The
existing scheduler therefore handles channel conversions even when that CSV is
empty. Customer/manager requests perform no new HTTP calls. A 50-message cursor
recovers missing direct captures; each event has a unique project/conversation/
goal key. First activation starts at the current cursor, without historical replay.

`MaxTrackerLookupRepository` authenticates before connecting, opens a read-only
MySQL transaction, selects only the requested MAX identities and correct effective
counter (`YA_METRIK_ID`, IBlock 8), and rolls back. It requests at most 25 users.
`UF_PLATFORM` is a device field in this MAX-only table and is not a messenger
filter. It returns the latest valid ClientID by numeric tracker ID; invalid/empty
newer IDs and wrong counters cannot replace it. A missing identity retries later.
The first successful resolution freezes ClientID and tracker record for the event.
Tracker observation time is optional metadata and never replaces event time.

## Tracker authentication and release

The lookup is a separate endpoint at
`https://tour-max.ru/new/max-attribution/lookup.php`. Existing lead delivery is
untouched. Read-only preflight found no readable retained lead-bridge config at
the tracker root. The old deployment credential also failed authentication.
Consequently this implementation uses a dedicated RSA/SHA-256 signing key, with a
purpose-bound body, a 90-second validity window, exact counter/identity validation
and strict response/body bounds. It does not reuse or copy another service token.

The private signing key belongs outside the canonical bot webroot:
`data/private/max-attribution/signing.pem`, mode 0600 in a 0700 directory.
`php tools/max_attribution_signing_key.php init` creates it once and emits only
the public verification key; an existing key is preserved. Keep that output in a
private operator/runner temp file. The tracker receives only that public key.
Requests require HTTPS certificate verification and never follow redirects.
Public endpoint responses are no-store; authentication failures expose no IDs or
SQL/config details. Signed requests are read-only; replay within the small time
window cannot mutate subscriptions or trigger conversion uploads.

Build a public-code-only release with:

```sh
python3 tools/build_max_attribution_lookup.py /absolute/operator-temp/lookup-release
```

The installer validates the release manifest and public key, pins the established
tracker SSH identity, and writes only `/tour-max.ru/new/max-attribution/` using
`github-audit@158.160.185.1`. It exports no tracker data or database credentials:

```sh
python3 tools/install_max_attribution_lookup.py \
  --bundle /absolute/operator-temp/lookup-release \
  --public-key /absolute/operator-temp/verification.pem \
  --ssh-key /absolute/operator-temp/audit-key \
  --known-hosts /absolute/operator-temp/pinned-known-hosts
```

The host key must match
`SHA256:WuqyxyQM6A4Sd0ZLddDBF1g/90VD2NFMdOmTjpSX2XQ`. No installer or key creation
runs automatically during deploy or CI. The release contains four PHP code files,
one public manifest and the separately supplied public key; never put secret
config, raw samples, private keys, logs or backup copies under `/new`.

## Explicit activation and receipts

After authorized publication, migration and tracker release:

1. Run `php tools/max_channel_attribution.php probe`: signed read-only probe,
   no conversion request. Confirm actual tracker PHP/FPM supports PDO MySQL/OpenSSL.
2. Run `php tools/max_channel_attribution.php collect`: start fresh collection
   and resolve identities without any Metrika upload. Check aggregate `status`.
3. Review a natural channel entry and the recorded event/ClientID match privately.
4. Run `php tools/max_channel_attribution.php send` only for the authorized pilot.
   It verifies the existing exact goals with a GET and the configured counter.
5. Observe an actual upload ID followed by processing status. HTTP acceptance is
   separate from validated rows, session attribution and Direct optimization.

Temporary CSV files also stay under the private feature directory (0700/0600)
and are removed after each request. Uploads contain `ClientId,Target,DateTime` and use the existing counter/token.
Every attempt claims the event atomically before POST. Successful receipts store
Metrika upload ID; subsequent checks use GET. A transport timeout, 5xx, malformed
response or crash after claim moves the event to **unknown**, never to resend.
GET-only reconciliation uses an opaque deterministic upload comment. Explicit
rate limiting or a proven pre-send DNS/connect failure can retry with backoff.
Recovery reads at most 300 upload records; an older/unfindable outcome stays held
for operator review. The comment is a recovery marker, not an API idempotency key.

A single tick resolves at most 25 queued events, uploads at most five, and polls at
most three accepted plus three unknown events. Stage uniqueness and SQL claims
protect concurrent workers. Pending/ready events older than 21 days expire.
PROCESSED with one source row and one validated row is tracked as processed;
LINKAGE_FAILURE is tracked as unmatched. Neither status claims a sale or a change
in advertising performance.

`off` stops future worker work/capture through this path; in-flight requests may
finish. Keep receipts and the applied migration. Do not drop tables or replay
unknown uploads. Turning the feature off returns future calls to existing YCLID
behavior. Recorded canonical goal markers remain, preventing an old event from
being queued again.

## Verification and current status

Required regression covers the pure policy and durable capture/worker behavior.
The native integration workflow runs the same queue cases on MySQL 8, tests
read-only tracker selection and independent connection claims, then exercises the
real PHP HTTP endpoint with signed requests and fixture database rows. It uses
isolated fixture data and no production secrets or external conversion requests.

Publication and real MAX conversion activation were explicitly approved by the
owner on 2026-10-15 (coordination #55, comment 6194132633). PR #958 includes
the current notification/sound release as its base. This document does not assert
that the lookup is installed, the mode is enabled, a production conversion has
been accepted, or a natural event has been attributed. Those require explicit
release/activation evidence recorded in #55.
