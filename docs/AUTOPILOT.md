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
8. Verify the changed behavior with the focused regression and relevant actual-source UI first; then run the required CI selected for the complete final diff.
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

## Finish the user path before broad verification

Choose a slice that completes an observable user action, including its error or
recovery state. For the selection collector, first verify the real search-row
action, popup/editor and saved draft with the shipped scripts and styles. Use a
current authorized source session when available; otherwise mark live extension
acceptance open. Synthetic source fixtures and a green full-panel run do not
prove that the installed extension works on the operator's current page.

Use focused regressions and changed desktop/mobile states while iterating.
Resolve known fixture wiring, styling and interaction failures before asking CI
to certify the candidate. Group related behavior fixes and fixture corrections
into that finished slice; a checkpoint does not need its own docs/report PR.
The final complete diff determines component or full coverage through
`docs/CI_SCOPES.md`; do not add broad manual browser replays to
a component change. Full coverage remains mandatory for shared owners and CI
rule changes. A later source edit needs its own selected current-head checks.

Each reviewer reads a run's original evidence once and records the result;
independent acceptance still requires that reviewer's own verification. Reuse
that verification for the same immutable evidence instead of downloading and
checking it again for each status update. Retry only failed/cancelled jobs on
the same source when allowed; a new head or a new failure is new evidence.
Keep one product WIP and preserve the normal sole-publisher/install gates.

Independent source work and focused checks may proceed in parallel. Reserve
the shared main/deploy publisher only after current-head required CI and
artifact acceptance are complete and fresh-main admission is ready. If admission
requires another source sync and CI before merge, release the publisher while
those checks run so another ready direction can publish. Reacquire only after
fresh claims/main/gates are checked. After merge, keep the sole publisher through
installed verification and explicit terminal release; do not overlap deploys.

Status updates lead with the user-visible effect and remaining blocker. Test
counts and artifact receipts support that conclusion; they are not the product
result or evidence of a live path that has not been exercised.
Keep one current checkpoint with links to its accepted evidence. Add a new
checkpoint for a result, blocker or ownership transition, not every unchanged
CI poll; preserve the existing claim and terminal-release records.

## Merge/deploy gate

Required PR CI must be green. Never bypass it because a change looks safe.

After merge, do not call the slice complete until production deploy reports successful:
- verify;
- SSH/sync;
- migrations;
- production smoke;
- production diagnostics download;
- deploy telemetry/final health gate.

Owner clarification, 2026-09-06 (recorded in START_HERE and #55): delayed human
manager replies / accepted-without-reply backlog alone are non-blocking for
independent development. Preserve `manager_response_ok` and the real counts;
do not make them green, modify business data or repeatedly pause/notify solely
for the backlog's age/count. This does not waive confirmed message/lead delivery
failure, handoff-integrity or security regressions, or any required technical
CI/deploy/provenance/migration/TLS/webhook/diagnostic-publication gate. Report
technical production verification separately from the unresolved response warning.

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
