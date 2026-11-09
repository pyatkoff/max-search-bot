# Production Operations Runbook

## Source and deploy

Source of truth: repository `main`.

Production deploy is driven by `.github/workflows/deploy.yml` after merge/push to `main`. The normal autonomous path is PR → required CI → merge → automated production deploy. Avoid manual production edits.

`Deploy production` is the sole automatic owner of active-server checkout synchronization and database migrations. Production diagnostics runs only after that workflow completes successfully and is read-only. The legacy standby deploy is manual recovery tooling and must not be restored as a parallel `main` push deployment.

Before loading deployment credentials or building a bundle, the production workflow explicitly fetches the authoritative `refs/heads/main` from GitHub and requires it to equal the full workflow SHA. It must not trust a cached local `origin/main`. A superseded push run or a manual run bound to a non-current commit is rejected and recorded as a failed provenance stage; rollback is performed by merging a new revert commit to `main`.

Production checkout path is documented in `README.md` and deploy workflow. Treat the repository/deploy workflow as canonical if paths change.

The MAX webhook, strict MAX TLS, Telegram production webhook and live-session
production gates run automatically after a successful `Deploy production`
completion on `main`. They no longer race source synchronization on the same
push. Each gate selects the upstream deploy's exact `head_sha`, records the
upstream run ID/attempt and rejects missing or invalid provenance before preparing
SSH credentials. A workflow-run event must identify a completed successful
`Deploy production` from this repository's `main`, originally triggered by push
or manual dispatch. An absent upstream SHA never falls back to the downstream
workflow's `github.sha`.

Each gate checks out that exact repository source, checks checkout HEAD and reuses
`tools/verify_deploy_main_provenance.sh` for a fresh authoritative-main comparison
before private access. Its existing production SHA equality check, bounded waits,
TLS/webhook/smoke assertions and diagnostic sanitization remain mandatory. A
superseded upstream SHA fails provenance; a newer default-branch SHA is not proof
that the older deploy was checked.

Manual dispatch remains available on current `main`. Live-session diagnostics
also retain their hourly `25 * * * *` schedule and existing concurrency group;
newer runs do not cancel an in-progress capture. Manual and scheduled runs use
their own full repository workflow SHA and the same current-main gate. The shared
release resolver supplies the authenticated application SHA for production checks.
Release evidence must associate automatic checks with the recorded upstream
Deploy run and exact target SHA, rather than searching only for `push` runs.

## Retaining an accepted application without installation

`tools/deployment_content_plan.py` is the single complete-tree/history policy.
Its first audited scope is an ordinary content change to the regular file
`tests/run_purchase_study_runtime.py`; it is not a blanket exemption for tests or
documentation. Compare the actual installed application A with candidate R, not
merely the last main commit. Added/deleted/renamed files, mode changes, runtime or
workflow changes, incomplete Git history and unknown paths cannot authorize the
shortened route. Existing activation/value markers anywhere in that history,
unknown marker families and explicit manual/configuration intent retain the
normal installation route.

`Deploy production` keeps the same source inventory, syntax and required groups,
including PHP 8.3. The shared `tools/deployment_release.js` first authenticates a
previous accepted **installed** baseline using immutable diagnostics Git history
and the original GitHub run, job and step metadata. A prior unchanged release is
never promoted to an installation. Missing optimization evidence chooses the
ordinary path; contradictory authority or confirmed unsafe runtime state is a
refusal, not permission to overwrite it.

Before choosing `application_unchanged`, the existing installer takes one fresh
read-only observation with `tools/deployment_runtime_fingerprint.php`. It compares
actual tracked bytes/modes and application HEAD, the supported static configuration
closure, existing owned cron state and the actual accepted public/editor packages.
Only keyed configuration digests and finite component identities leave the server;
configuration values, customer data and raw logs do not. An unsupported configuration
loader or unavailable signing key reports `unknown` and cannot authorize a skip.
Tracked drift, an invalid component seal or a pending component operation reports
`blocked`. A legitimately activated independent editor may differ from the common
application when its existing backend fingerprint and native acceptance prove the
same installed backend; preserve that identity rather than reinstalling it.

For an unchanged release, bundle/sync, migrations, webhook/cron reconciliation,
snapshot export/transfer and the dedicated host installation are **not performed**.
Original technical checks are inherited explicitly from A. Automatic downstream
workflows validate the mapping and complete their metadata-only route without
fresh captures, sends, activation or configuration. Their skipped actions cannot
be described as fresh runtime health measurements. The existing retired-domain
source guard and all source checks still run.

The existing `diagnostics/deploy_status.json` retains its schema-2 actual
installation receipt: `sha`, `run_id`, stage outcomes and `reported_at` still
describe A. Its versioned `repository_release` distinguishes `installed` from
`application_unchanged`, repository R from application A, original installation
evidence from current observations, and performed/inherited/not-performed stages.
`pending_closure` and `pending_terminal` are not independent assertions of success.
Acceptance also requires the digest-bound publication and final successful
terminal step of the original closer. See `docs/RELEASE_PRIORITY.md` for ordering.
Artifact expiry does not replace the immutable Git record or original run metadata.

All downstream, scheduled and manual consumers use `resolve-consumer`. Automatic
unchanged children accept the authenticated pending mapping so they can finish
without waiting on their own closer. Scheduled/manual work requires completed
acceptance, proves current repository R and targets A. A pending schedule defers
without refreshing operational receipts; an unauthorized manual operation fails.
Explicit application inputs must equal A. No consumer guesses that R was installed.

The independent editor admits source S against current repository R and actual
backend A: the same trusted policy proves A→R, the existing editable scope proves
R→S, and exact-source PR CI uses base R. Server installation retains the existing
package CAS, native operation/activation proof and backend identity A. GitHub
credentials remain on the runner.

The first ordinary release containing this integration must establish the new
baseline; older schema-2 telemetry alone cannot authorize a skip. Unknown baseline
observations leave that otherwise successful ordinary release ineligible for future
skips. Approved post-observation configuration/activation intent also makes it
ineligible until a later ordinary installation establishes fresh matching evidence.
Revert through the normal checked release if this contract is defective. Never
edit production or weaken a failed gate to obtain a baseline.

## Required scheduled-worker observations

Production still requires both existing `verify-cron` commands to observe two
new scheduled ticks, with their original interval, deadline and failure rules.
The cron entries are reconciled serially. Once both entries pass their existing
count checks, `tools/verify_deploy_crons.sh` starts the two read-only observers
together and requires both to succeed. It never invokes a worker tick or provider.

The pending hotel backup stays available until both results are known. A hotel
failure restores that earlier boundary; a development-only failure preserves the
confirmed hotel entry and restores the development boundary. A configuration
failure before observation restores the still-unverified pair. Temporary
snapshots and observer logs are removed; cancellation stops both child observers.
Schedules, enable flags, business states and the verifier implementations do not
change. A faster overlap does not waive either mandatory result.

## Required pre-merge check

Run:

```bash
bash tests/run_required_checks.sh
```

This full command includes PHP syntax plus dialogue, manager, integration, website, diagnostics and architecture regressions. Required PR CI uses the bounded selection in `docs/CI_SCOPES.md`; unknown/shared/mixed changes and manual runs keep full coverage. Production deploy verification remains the full command.

## Production verification

A successful ordinary installation must include all of:
- verify job / required checks;
- fresh authoritative-main provenance check;
- SSH probe;
- production checkout sync to expected SHA;
- migrations applied with no pending/checksum corruption;
- production smoke checks;
- production diagnostics transfer;
- deploy stage telemetry/final health gate.

Do not treat a diagnostics-download failure as a successful deployment.

An `application_unchanged` release instead requires the authenticated evidence and
fresh observation described above; it must retain the original installation receipt
and label omitted actions truthfully. It is not a new installation or health capture.

## Optional Manager list attribution

The existing production diagnostics workflow has a **default-off** manual input
`profile_manager_lists`; the CLI equivalent is `php tools/production_snapshot.php
--profile-manager-lists`. Use it only after this source is published through the
normal gates and the specific capture scope is authorized. Automatic post-deploy
captures remain unchanged. Do not patch an installed checkout to enable it.

The option observes the same three bounded `ManagerConversationService::list`
probes (All/Mine/Waiting, limit 200) on their canonical PDO connection. It does not
repeat the loaders, add a transport, mark conversations read or change data.
Before opt-in probes, the canonical metadata-only project initializer verifies
the configured project's existing active/name postcondition, preventing the
legacy project UPSERT bootstrap. A mismatch fails capture without repairing
configuration. This adds one startup metadata SELECT outside loader timings;
the default capture/bootstrap path is intentionally unchanged.
One ordinary EXPLAIN (never ANALYZE) may follow each loader after the original
statement class is restored. `load_ms` excludes EXPLAIN but includes observer
overhead; opt-in and default timings are not an optimization before/after pair.

`read_profile` exposes fixed categories, at most 64 statement records, explicit
execute/fetch milliseconds, fetched rows and scalar-value bytes. It never exports
SQL, bindings, identifiers, fetched text, exceptions or plan conditions. Plans
contain only fixed access enums and numeric estimates, not table/index names.
Prepared-statement count includes unexecuted prepares; `PDO::query` execution is
not timed by this observer. Scalar bytes exclude keys, are fetch-mode dependent
(e.g. FETCH_BOTH duplicates cells), and are not HTTP/wire bytes. `fetchColumn`
cannot distinguish a boolean-false cell from EOF. Nondefault statement classes
and persistent connections are not replaced and report unavailable coverage.
When `available=false`, zero observer counters mean **not measured**, not zero
SQL work; only the original callback's duration is measured.

Each capture is one sample with uncontrolled cache state, not p50/p95, database
internal execution time, browser latency or an SLA. Compare only like scenarios
with explicit coverage and instrumentation state; retain the existing encrypted
private evidence and sanitized public diagnostics boundaries.

## MAX TLS rollout gate

`tools/max_tls_preflight.php` is the non-customer-facing production gate for MAX certificate verification. It performs `GET /subscriptions`, optionally reserves an upload URL and probes that host with a bodyless request, forces peer and hostname verification even when the legacy compatibility flag is set, and reports no token, subscription URL or upload URL. The upload probe does not transfer a file or send a message.

The `Verify production MAX TLS` workflow must be green on the exact production SHA before any customer-facing MAX transport switches from the legacy compatibility mode to verified TLS. A failed preflight is evidence to keep the current transport unchanged and repair the CA trust path first.

All MAX API, media-upload and subscription-admin requests use `MaxTlsConfig`. Verified peer/hostname checks are the production default. `MAX_SEARCH_MAX_API_INSECURE_COMPAT=1` remains an emergency compatibility path only when no readable CA bundle is installed; remove the flag and restore the managed bundle immediately after recovery.

## Autopilot first read

The public diagnostics branch publishes only redacted aggregate artifacts. `autopilot_snapshot.json` is the compact first-read artifact and combines, without transcript text or customer/operator identifiers:
- production SHA/branch;
- migration count/pending/checksum state;
- health flags;
- aggregate manager response/push/visibility health;
- handoff health;
- current live-session funnel summary and aggregate anomaly reasons;
- website smoke summary and ops status;
- pointers only to other public-safe aggregate artifacts.

Use this file as an index/triage surface. Detailed message-level evidence is generated only ephemerally inside the protected production workflow and must not be committed to the public repository.

`channel_daily` reports seven calendar days across MAX, Telegram, website and an
`other` transport bucket, scoped to this project and excluding explicit tests.
Active means a conversation started or had a recorded message/event in that day.
New and continued partition active conversations by their original start date;
these are conversation counts, not unique people or distinct visits. Inbound
activity includes recorded callbacks. Requests count conversations with a recorded
manager-request/waiting-manager event in the day; replies count conversations
with any recorded outbound manager message in the day, including replies to older
requests. The two counts are not a matched conversion rate or delivery receipt.
No source labels, IDs, transcript or advertising values are published. Missing
source counts flag absent IDs only, not proof of source correctness. Zero activity
does not establish whether an external channel is connected. Collector errors
are explicit (`ok=false`), never fabricated zero totals.

The owner-authorized encrypted export is described in
`docs/PRIVATE_DIALOGUE_DIAGNOSTICS.md`. It uses a separately held private key and
a ciphertext-only Actions artifact; ordinary artifacts in this public repository
must not be treated as private storage for readable transcripts. Public summaries
remain unchanged, and successful encryption is not a replacement for health gates.

`tools/compose_autopilot_snapshot.php` composes it from the detailed production artifacts and deliberately excludes `recent_messages` and live `message_tail` content.

## Production snapshot

`tools/production_snapshot.php` is the canonical detailed machine-readable production snapshot generator. It contains production SHA/branch, database/migration state, manager visibility/response/push health, handoff integrity, website attribution, recent messages/events and manager delivery failures.

Raw generated production diagnostics must not be committed to any public branch. The diagnostics branch may contain only outputs passed through `tools/sanitize_public_diagnostics.php` plus non-sensitive deployment/architecture status.

Raw deploy diagnostics must also stay outside the production document root. `export_debug_logs.php` rejects an output directory inside the application tree; deploys use a mode-0700 directory under `/tmp`, download it over SSH, and remove it immediately afterward. Legacy `diag-*.json` files and the old `diagnostics/` webroot directory are deleted during smoke checks.

Raw AI debug/error logs use `AiRuntimeLogger` and default to the external mode-0700 directory `/tmp/max-search-bot-ai`; files are mode 0600 and bounded to 1 MiB. `MAX_SEARCH_AI_LOG_DIR` may override that absolute path, but the logger rejects any directory inside the application/document root. Production deploy verifies the external path/modes, removes legacy `ai_debug.log` files from webroot and fails unless the public paths return HTTP 403 or 404.

Sensitive customer content should remain bounded and redacted; never expose secrets/config values in snapshots.

Structured events have a single canonical path from `DiagnosticLogger::filePath()` in the dedicated `max-search-bot-private-logs` directory beside the checkout. This shared external default avoids CLI/PHP-FPM temporary-directory differences; the directory is mode 0700 and files are mode 0600. The writer and all diagnostic readers use it, with no fallback to the public application path. Event JSON, sanitization and append retention remain unchanged.

`tools/structured_runtime_log_smoke.php` contains the old public filename immediately after source sync, before migrations or unrelated checks. It retains the legacy inode in a unique private archive, then atomically replaces its public name with an empty mode-000 permission fence. Already-open old writers continue into the private inode; later old-code opens cannot recreate the public log. The tool seeds a missing private active log from that history and preserves an already-existing active log. Historical archives are retained privately; they must never be published or deleted as routine cleanup. Repeated containment is idempotent. Do not remove the public permission fence or restore an old public writer during rollback.

Containment must pass private-write, path, inode and permission checks and an anonymous HTTP 403/404 probe. The final smoke repeats the local fence check and public probe. A symlink, unexpected nonempty/recreated fence, unsafe directory, failed preservation or any other HTTP result fails deployment. These checks establish the observed serving boundary; they cannot revoke copies already obtained before containment.

Manager setup observations are available in the normal capture as `manager.push_setup`: fixed error/native-call categories, bounded timings and coarse browser state from at most 50 sampled push events. They are client-reported context, not trusted proof of provider cause or successful delivery. The summary excludes account identity, raw messages, endpoints, key material and full user-agent strings; raw push events remain private. No extra capture schedule is introduced.

## Live conversation evidence

Use `tools/live_session_snapshot.php` and published live-session diagnostics for bounded recent conversation analysis. Prefer exact transcript evidence over aggregate flags before modifying dialogue behavior.

Useful checks include:
- repeated question after a recognized answer;
- repeated customer input;
- excessive turns before tours;
- needs complete but tours not opened;
- manager requested but first reply delayed/missing;
- phone fallback behavior after 5 minutes;
- delivery failure vs routing failure;
- edit-flow values not applied.

## Manager operations

Manager working status and push health are separate facts.

Never auto-enable a manager or change routing bonuses because push is unhealthy. Report the operational condition instead.

For handoff health distinguish:
- `routing_blocked` / no eligible route;
- eligible manager but no push subscription;
- successful push delivery but delayed human reply;
- outbound customer delivery failure.

Fix code only when evidence points to code/routing integrity rather than operator state.

## Migrations

- numbered migrations only;
- applied migration files are immutable;
- repairs are forward-only;
- migration status must be checksum-safe;
- runtime requests must not create or alter schema silently.

## Rollback posture

Prefer forward fixes for small safe defects. For a material production break, revert/rollback the offending source change through Git rather than editing production in place, then verify the resulting production SHA and health again.


## AnyTour modeled-conversion monitoring

The primary pipeline check runs from the existing main server's own cron every
five minutes through tools/modeled_conversion_monitor_local.py --cron. It uses
the SAME canonical Python prepare/send implementation as the external checker,
with role=pipeline and an isolated private JSON state. It checks the existing
read-only delivery/profile/queue/report metadata and sends only technical owner
alerts. It never captures a conversation, calls a model, processes an outbox,
changes a goal/value or sends to a customer.

The existing modeled-conversion-monitor.yml keeps the external role. After the
controlled cutover it checks host/source availability and the local checker's
heartbeat, not business SQL flags. An unreachable host can still be reported
using its retained age-encrypted route. A stale local heartbeat or uncertain
local notification is a control-plane failure. GitHub schedule remains best
effort and its five-minute regularity is not established by source configuration.
Do not claim a guaranteed external detection deadline.

Pipeline and external roles have disjoint business/transport flag ownership,
separate state and receipts, and one shared notification implementation. Role
crossloads are rejected. Configuration/clock errors retain explicit role labels.
The protected MODELED_CONVERSION_ALERT_CHAT_ID is used only for the exact
verified owner's private chat. Customer identifiers, transcript and financial
values never appear in alerts, public diagnostics or artifacts.

Before activation, the availability operation retains the legacy full SQL
check. The one-original activation workflow follows a successful original
postDeploy monitor, requires its unique approved merge marker, exact current
main/installed source, and shares anytour-modeled-conversion-monitor concurrency.
It waits for legacy senders, requires an initialized/verified quiet donor with
no pending intent or active incident, and preserves last_notification and route.

Only the validated pipeline state JSON crosses the existing authenticated SSH
channel into max-search-bot-modeled-pipeline outside the webroot. The deployment
SSH private key stays on the GitHub runner. External-role ciphertext must be
retained and acknowledged before activation.json and the owned cron are enabled.
No new bootstrap/test is sent. Missing, uncertain or mismatched state holds
instead of resetting or replaying. The activation tool cannot execute a tick.

The local runtime uses private0700/0600 files, no-follow reads, one nonblocking
lock, bounded JSON and atomic file+directory fsync before prepared/sending can
reach Telegram. The final state is durable before a receipt. Lost delivery
remains UNKNOWN and cannot be automatically resent. The local runtime needs
only the Python standard library; age/pyrage is imported only by the existing
external evidence codec. State import and activation are separate from cron.

tools/reconcile_modeled_conversion_monitor_cron.sh reconciles only the activated
owned entry, preserves all other workers, refuses an unreadable/changed crontab
and restores its prior snapshot on a failed owned installation. Normal deploy
calls it after the existing scheduled-worker observations. Before activation it
does not create a timer. Crontab installation never invokes the monitor.

The typed pipeline-checks.json and external last-check.json remain independent.
Only the real guarded server-cron entry can produce scheduled pipeline evidence;
generic runtime observations and tests do not qualify. Acceptance requires three
distinct advancing natural cron receipts, healthy quiet outcomes and unchanged
last_notification_at. External manual/postDeploy/schedule evidence never
substitutes for local records, or vice versa. Release shared publication after
original installation/activation technical closure, not after waiting for ticks.

### Owner conversion ledger and check provenance

Use the administrator-only /manager/assigned-value.php?view=delivery page for actual recorded monetary deltas and per-conversation drilldown. The closed page performs read-only GETs and does not trigger customer read markers, tracker calls, processing or advertising sends. Its assignment-day totals differ deliberately from the upload-day/receipt-observation/processed-observation cohorts.

The external receipt retains its previous event/run/attempt/source and scheduled
history. Its freshness is not refreshed by local checks. The nested pipeline
status reads only private activation and technical receipt metadata, never the
credential state. It exposes role/origin, real cycle IDs, clocks, health,
notification and a last-three series. Three fresh healthy original server-cron
checks with gaps no larger than fifteen minutes establish the primary series.
Unknown origin, generic observations, stale/invalid clocks or uncertain
notifications cannot become a green primary result. Quiet checks retain the
previous confirmed notification time.

The closed owner page shows primary pipeline control and external availability
separately. A healthy external check cannot cover missing local evidence, and a
healthy local result cannot make external availability fresh. Keep the latter's
best-effort limitation visible. Do not replay old helpers, accepted workflows,
business ticks or a notification intent to manufacture proof.


### Automatic visit reconciliation

The existing activated five-minute pipeline monitor now calls the fixed
`modeled_conversion_visit_proof.php scheduled <5..20 seconds>` operation **after**
the primary health/owner-notification cycle. The 45-second cron timeout, route,
activation and all delivery/profile workers remain owned by their existing
components. A proof subprocess receives only the remaining bounded time; its
failure does not replace the primary monitor result.

The scheduled CLI requires the existing cron origin and canonical production
root, takes a separate nonblocking proof lock, applies a GET-only API deadline,
and uses a read-only DB session. It never retries an upload or changes identities,
outbox rows, profiles, transcripts, read markers, goals or queues.
The original-cohort checkpoint is retained from the validated current receipt.
Stable original-cohort proofs are reused for at most a day. Each five-minute
batch checks one newest due row and one oldest unchecked/due row; processing
receipts are due after five minutes and terminal evidence after a day. The
bounded ledger currently refuses more than 1,000 eligible value rows explicitly;
it must be extended before that safety bound is reached.

Only derived private evidence is written outside the webroot:
`max-search-bot-modeled-visit-proof/{last-check.json,automatic.json,refresh.lock}`
and `events/<event-fingerprint>.json`, under owner-only 0700/0600 permissions.
Atomic hashed event receipts preserve individual results after rotation out of
the current bounded report. Fingerprints bind row, dialogue, amount, time,
ClientID and provider upload. Per-event check times remain truthful when reused.
The owner page and fixed no-argument MCP status read these caches without live
provider calls. Automatic status retains the last three successful natural
checks, separate from manual verification. A failed/stuck refresh or a stale
successful refresh enters the existing pipeline owner-alert role.

A provider LINKAGE_FAILURE stays failed. An exact, complete, unsampled ClientID
visit report without a goal filter only distinguishes no reported visits, visits
outside the attribution window, eligible visits despite provider rejection, or
an unavailable diagnosis. It never supplies a replacement identity or credits
a candidate visit. Native VisitID/event-log attribution remains outside this
reconciliation contract.


New unresolved MAX identities also require the retained tracker recording time
to be known, no later than the signal, and at most 21 days before it. A missing,
future or expired source click stays in the existing pending lane without an
upload; the original positive signal and immutable forecast remain saved.
A later lookup may resolve that same row only from an eligible prior click.
This is a conservative source-clock admission, not proof that the counter
recorded a visit. Actual visit linkage still requires the provider report.
Already accepted/processed/failed receipts are never reset or resent by this
guard; no historical queue sweep is introduced.
