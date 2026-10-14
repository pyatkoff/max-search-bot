# Production Operations Runbook

## Source and deploy

Source of truth: repository `main`.

Production deploy is driven by `.github/workflows/deploy.yml` after merge/push to `main`. The normal autonomous path is PR → required CI → merge → automated production deploy. Avoid manual production edits.

`Deploy production` is the sole automatic owner of active-server checkout synchronization and database migrations. Production diagnostics runs only after that workflow completes successfully and is read-only. The legacy standby deploy is manual recovery tooling and must not be restored as a parallel `main` push deployment.

Before loading deployment credentials or building a bundle, the production workflow explicitly fetches the authoritative `refs/heads/main` from GitHub and requires it to equal the full workflow SHA. It must not trust a cached local `origin/main`. A superseded push run or a manual run bound to a non-current commit is rejected and recorded as a failed provenance stage; rollback is performed by merging a new revert commit to `main`.

Production checkout path is documented in `README.md` and deploy workflow. Treat the repository/deploy workflow as canonical if paths change.

## Required pre-merge check

Run:

```bash
bash tests/run_required_checks.sh
```

This includes PHP syntax plus dialogue, manager, integration, website, diagnostics and architecture regressions.

## Production verification

A successful deploy must include all of:
- verify job / required checks;
- fresh authoritative-main provenance check;
- SSH probe;
- production checkout sync to expected SHA;
- migrations applied with no pending/checksum corruption;
- production smoke checks;
- production diagnostics transfer;
- deploy stage telemetry/final health gate.

Do not treat a diagnostics-download failure as a successful deployment.

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
