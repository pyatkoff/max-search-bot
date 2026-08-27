# Production Operations Runbook

## Source and deploy

Source of truth: repository `main`.

Production deploy is driven by `.github/workflows/deploy.yml` after merge/push to `main`. The normal autonomous path is PR → required CI → merge → automated production deploy. Avoid manual production edits.

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
- SSH probe;
- production checkout sync to expected SHA;
- migrations applied with no pending/checksum corruption;
- production smoke checks;
- production diagnostics transfer;
- deploy stage telemetry/final health gate.

Do not treat a diagnostics-download failure as a successful deployment.

## Autopilot first read

The diagnostics branch publishes `autopilot_snapshot.json` as the compact first-read artifact for autonomous work. It combines, without transcript text:
- production SHA/branch;
- migration count/pending/checksum state;
- health flags;
- manager response/push/visibility state;
- handoff health;
- current live-session funnel summary and flagged conversation IDs/reasons;
- website smoke summary and ops status;
- pointers to the detailed diagnostic files.

Use this file to decide what detailed artifact to inspect next. It is an index/triage surface, not a replacement for message-level evidence.

`tools/compose_autopilot_snapshot.php` composes it from the detailed production artifacts and deliberately excludes `recent_messages` and live `message_tail` content.

## Production snapshot

`tools/production_snapshot.php` is the canonical detailed machine-readable production snapshot generator. It contains production SHA/branch, database/migration state, manager visibility/response/push health, handoff integrity, website attribution, recent messages/events and manager delivery failures.

Generated production diagnostics belong on the diagnostics artifact/branch, not in `main`.

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