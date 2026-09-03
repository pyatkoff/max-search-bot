# Autopilot Execution Loop

This is the default execution order for autonomous work.

## One pass

1. Read `START_HERE.md`, then `AGENTS.md` and the relevant durable docs.
2. Check current `main`, open PRs and latest production/deploy state.
3. Read the newest production snapshot/diagnostics artifact.
4. Inspect fresh live conversations first, including exact customer input and bot/manager replies.
5. Classify evidence using the repository priority order.
6. If a production/user defect is confirmed, fix only that defect plus the smallest required regression.
7. If no higher-priority defect exists, take the smallest useful roadmap slice.
8. Run required checks and any focused regression locally/CI.
9. Open PR with explicit behavior/non-behavior scope.
10. Merge only after required CI is green.
11. Verify deploy, migrations, smoke, diagnostics and production SHA.
12. Review fresh live evidence once after deploy when available.
13. Update issue #55 with verified facts, remaining uncertainty and the next safe priority.

## Evidence hierarchy

Prefer evidence in this order:
1. exact live conversation transcript/state;
2. production health/snapshot and structured events;
3. deterministic regression reproduction;
4. aggregate funnel/anomaly metrics;
5. source-code suspicion.

An aggregate flag is a lead for investigation, not automatically a bug.

## Defect triage

A defect is actionable when there is enough evidence to describe:
- what the customer/manager did;
- what the system did;
- why that result is wrong or conversion-harming;
- the canonical code owner that should change;
- a regression that would fail before the fix and pass after it.

Avoid speculative behavior changes when only old/historical diagnostic noise exists.

## PR size

Prefer one logical product or architecture slice per PR. Small related test/diagnostic changes belong in the same PR; unrelated cleanup does not.

For architecture work, preserve behavior first. Extract one owner/boundary, switch callers, verify, then remove dead code later.

## Merge/deploy gate

Required PR CI must be green. Never bypass it because a change looks safe.

After merge, do not call the slice complete until production deploy reports successful:
- verify;
- SSH/sync;
- migrations;
- production smoke;
- production diagnostics download;
- deploy telemetry/final health gate.

## Live verification language

Use precise language:
- `regression verified` means automated reproduction passes;
- `production deployed` means deploy/smoke/diagnostics passed on the production SHA;
- `live confirmed` means a natural post-deploy conversation exercised the same scenario successfully.

Never substitute one for another.

## Roadmap rule

Issue #55 stores current checkpoints and next priorities. Durable rules belong in repository docs so a new session does not need to reconstruct them from hundreds of comments.

## Periodic full audit

Do not only reread recently changed files. Periodically re-audit the repository architecture, hot paths, production diagnostics and live UX. For user-facing surfaces, include responsive visual QA when screenshot tooling/artifacts are available.
