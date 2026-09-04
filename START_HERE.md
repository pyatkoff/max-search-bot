# max-search-bot — current autonomous handoff

Repository: `pyatkoff/max-search-bot`

Roadmap/checkpoint thread: GitHub issue [#55](https://github.com/pyatkoff/max-search-bot/issues/55)

Primary language with the user: Russian.

Read this file first. Then read `AGENTS.md` and only the durable documents relevant to the selected slice.

## Authority and evidence ownership

Do not trust a SHA, branch name or unfinished task copied from an old chat/export. Facts and authority have different owners:

- explicit current user instructions define authorized scope; production safety remains the stop condition;
- `AGENTS.md` and `docs/PRODUCT.md` own stable scope and product policy;
- `docs/ARCHITECTURE.md` and `docs/ARCHITECTURE_MAP.md` own dependency and code-ownership direction;
- current GitHub `main`, production/deploy diagnostics and fresh live customer/manager evidence own current factual state;
- newest comments in issue #55 own completed checkpoints and verification evidence;
- this file owns the current cross-document handoff sequence;
- historical handoffs and superseded roadmap sequences are context only.

Live evidence can confirm and reprioritize a defect, but it cannot authorize a hard-scope or product-policy change. When current facts make documentation stale, update the documentation in a docs-only PR; do not restart completed phases.

## First actions in every new session

1. Fetch current `main`, issue #55 and its newest comments.
2. Read `AGENTS.md`, `docs/AUTOPILOT.md`, `docs/OPERATIONS.md`, `docs/ARCHITECTURE.md`, `docs/ARCHITECTURE_MAP.md` and the relevant roadmap section.
3. Confirm `main`, deploy status, ops status and production snapshot resolve to the same full SHA.
4. Confirm migrations have zero pending/checksum failures.
5. Confirm required production workflows: deploy, diagnostics, strict MAX TLS, MAX webhook and Telegram webhook.
6. Review fresh live evidence and operational warnings before selecting roadmap work.
7. Create a fresh branch from current `main`; never revive a historical branch merely because an old handoff names it.

## Confirmed completed position

- Phase C Manager technical structure is complete for the current caller inventory through PRs #656–#659. Do not reopen it without a new caller or confirmed defect.
- The month/date/show-tours InteractionGuard work from the old handoff is merged and production-proven. Do not continue the historical `refactor/month-change-interaction-guard` branch.
- P0 public AI-log exposure was contained in PR #660:
  - raw AI debug/error writers are outside the document root;
  - runtime directory/file modes are `0700/0600`;
  - legacy AI-log paths are publicly inaccessible with an accepted 403/404 response; the #660 deployment returned 404;
  - this containment must never be removed by rollback.
- Phase D is complete through the selected low-risk slices: D1–D6, adults callback, stars callback and the observe-only nights → date transition shipped in PRs #663–#671. The meal callback contract and update-only runtime slice shipped in PRs #680 and #681. Do not restart those slices.
- The coupled children/child-age inventory shipped in PR #683. It confirms one comma-space age-status value and keeps free-text age migration blocked until an exact array-to-storage projection is executable.
- The `child_*` callback update-only runtime slice shipped in PR #685. It preserves the existing child-age value for `child_0`, fails closed when the existing child step is missing and keeps free-text child ages out of scope. Do not repeat that runtime slice.
- Phase E's read-only handoff inventory and the first two proven consolidations shipped in PRs #672, #675 and #676. They centralized queue-decision application and contact send/status application without changing handoff policy.
- The Manager notification activation incident reported by Anna was repaired in PRs #673, #674 and #677. PR #678 added redacted reason counts, and Anna's healthy green state was naturally confirmed after deployment.
- The exact current SHA and run IDs belong in live GitHub/diagnostics and the latest issue #55 checkpoint, not hard-coded here.

Known operational signals are evidence to investigate, not permission to change policy:

- older overdue/stuck assigned leads may remain while `routing_blocked_count=0`;
- a working manager may lack a usable browser push subscription;
- a historical Telegram 500 may remain visible while current pending updates and controller smoke are healthy.

Do not infer a routing, shift, lead-delivery or webhook defect from those aggregate signals alone.

## Current execution point

Phase D's selected slices, including the contract-backed meal callback, and Phase E's first two consolidations are complete. The detailed sections below remain as historical acceptance and rollback contracts, not as an instruction to rerun them.

The remaining Phase E inventory was re-audited and no further exact duplicate responsibility was proven, so handoff consolidation is stopped. The two `ManagerAvailabilityService::withinWorkingHours()` calls in `ManagerPhoneFallbackService` are intentionally separate: one bounds candidate selection, while the second rechecks policy inside the per-conversation lock immediately before external delivery. Do not collapse that safety check merely to reduce occurrence count.

The next safe item is contract/test work only for free-text child ages; it is not yet an authorized runtime migration. First make the integer-array-to-storage projection executable with the exact comma-space representation, and prove parity for every currently accepted and rejected separator form before selecting a runtime slice. Preserve exact child-count validation, the `0..17` age range, tourists edit return, existing-step-only/no-insert semantics and fail-closed behavior. Do not change the current free-text age writer until that contract is green and the handoff explicitly names a separate runtime PR. Keep city, country and date mutations deferred, and do not make observe-only transition validation blocking, without their own explicit contracts and clean production evidence.

## Protected behavior

A confirmed defect permits only a narrow fix that preserves the values and policies below. Changing any of them requires explicit user authorization for that exact change:

- Yandex Metrica counters, goals or goal semantics;
- the existing lead-delivery destination/mechanism;
- operator-controlled manager shifts/`is_working`;
- routing eligibility or bonus values;
- neighbouring repositories, domains or projects; every exact neighbouring project always requires separate explicit authorization.

The fixed handoff product policy remains owned by `AGENTS.md`: 10:00–20:00 Europe/Kaliningrad working window, live manager handoff without mandatory phone during working hours, one phone offer after five minutes without reply, and truthful self-service/optional-phone presentation outside hours.

## Risk and business priority

Use the canonical priority order in `AGENTS.md`; do not maintain a competing numbered order here. A confirmed security exposure or broken release/deploy is a production-safety interruption. Confirmed customer/manager message loss or handoff failure is lead-loss interruption. Proactive control-plane hardening precedes optional roadmap cleanup but does not displace an active customer/business defect.

An aggregate metric is a diagnostic lead, not automatically a code defect. Capture the message/state evidence and a failing regression before changing behavior.

## Completed execution record after Phase C and P0

The items in this section are complete and retained so their original acceptance and rollback contracts are not lost. Do not execute them again. Keep every future item independent and do not combine neighbouring cleanup.

### Documentation truth checkpoint — covered by the introducing PR

The PR that introduces this file also synchronizes the first-read pointers, marks the obsolete `docs/REFACTOR_ROADMAP.md` immediate sequence as superseded and extends the required operating-contract regression. Treat this checkpoint as complete only after its required CI is green, it is merged, the exact merged SHA is production-verified and issue #55 records that evidence. Do not repeat it unless a new contradiction is found.

CI: full required suite, including the operating-contract regression and PHP syntax gate.

Production: exact merged SHA across `main`, deploy status, ops status and production snapshot; migrations, strict MAX TLS and both webhook checks green; no runtime behavior change.

Rollback: revert the introducing docs/test PR if the handoff is materially wrong, then deploy and verify the revert SHA through the same gates.

### Control-plane checkpoint — no application PR

- Enable a GitHub ruleset/branch protection for `main`: require PR, required green checks, and prevent force-push/deletion.
- Verify exact check contexts before requiring them. Keep `Regression tests` and `Retired domain guard`; require Workspace V2 visual QA only after it reliably reports a successful no-op for unaffected changes or through the existing UI-change policy.
- Review and close stale/conflicting PRs as superseded; do not reuse old branches as the base for new work.

Acceptance: ordinary changes cannot reach `main` without the selected checks.

Rollback: relax only the misconfigured rule that blocks legitimate green PRs; do not disable all protections.

### PR S2 — `hardening/deploy-main-provenance`

Goal: a manual or automatic production deploy may deploy only the exact current authoritative GitHub `main` SHA.

Scope:

- fetch the authoritative current `main` ref before bundle/sync and fail if the deployment SHA is not exactly that fresh SHA; never trust a stale local `origin/main`;
- cover current-main and non-current SHA cases in deployment contract regression;
- retain the deploy's full repeated required suite because `main` protection is not yet assumed.

CI: full required suite plus executable provenance cases.

Production: exact-main deploy; all existing stages and status artifacts must agree on the merged SHA.

Rollback: revert this PR if it blocks a legitimate current-main deploy; rollback production remains a new revert commit on `main`, not arbitrary old-SHA deployment.

### PR D1 — `audit/dialogue-mutation-inventory`

Add a machine-readable, required inventory of direct dialogue/trip mutations, including:

- `setStatus`;
- `saveLastValue`;
- `upsertStatusValue`;
- `deleteAll`;
- `applyAiParameters`.

Classify each caller as trip value, transition, reset, metadata or Manager technical state. CI must fail on a new unclassified writer.

CI: inventory behavior and full required suite.

Production: exact SHA and unchanged diagnostics baseline; no runtime behavior change.

Rollback: revert the inventory/guard if its classification is wrong, without touching runtime state.

### PR D2 — `fix/ai-completion-contract`

Define and repair the `AiNeedCompletionService::resolveApplyAndAdvance()` return contract so `applied` has one stable type and meaning.

Required behavior tests:

- recognized and applied;
- rejected input does not advance;
- progression occurs exactly once;
- caller compatibility;
- stable `applied` contract.

No intentional dialogue behavior change. Roll back by reverting the single PR if a caller incompatibility appears.

### PR D3 — `refactor/resolver-application-bypasses`

Move only the caller-proven resolver-side direct `applyAiParameters()` calls through the existing `NeedApplicationService::applyParameters()` boundary where the underlying storage semantics remain identical.

CI must prove the caller list and preserved applied values. Production verification must show no new dialogue/live flags. Roll back by reverting this PR.

### PR D4 — wizard existing-step application contract

Introduce an explicit application contract for an already-existing wizard step. It must preserve update-only/no-insert behavior and the current start boundary.

Required tests cover both storage modes, zero children, exact value representation, stale/missing step and absence of a hidden status transition. Couple it to at most one caller if an otherwise-unused abstraction would be created.

### PR D5 — free-text nights only

Move only free-text nights in `StateMessageHandler` through the deterministic resolver and existing-step application contract.

Required executable cases:

- `На 6`;
- `7-10`;
- `3,4`;
- invalid text;
- normal flow to calendar;
- edit flow back to check;
- no insert on a stale/missing step.

Do not claim live confirmation until a natural post-deploy case occurs.

### PR D6 — `nights_*` callback only

Move only the nights callback through the same application contract while preserving `InteractionGuard`, duplicate/stale/concurrent suppression, edit behavior and the next calendar view.

Required tests must execute the callback action and assert exact stored value and next UI. Roll back on repeated prompts, wrong value, lost edit state or weakened suppression.

### Later Phase D slices

- Migrate adults and stars one field per PR after nights is stable.
- Meal is complete through the contract-backed callback slice in PRs #680 and #681.
- Defer children, child ages, city, country and date until their ID/directory/pending-month/edit semantics have explicit contracts.
- Add state-machine validation observe-only for one transition first, such as nights → date; introduce blocking only after clean production evidence.
- Never mechanically replace `saveLastValue` with an upsert: code rollback cannot undo status rows already written.

Phase D non-negotiable semantics:

- `saveLastValue` updates an existing wizard row, while `NeedApplicationService::applyParameters()` may upsert; do not equate them without an explicit application contract;
- classic wizard/button progression is not automatically equivalent to AI free-text missing-field progression;
- callback payload IDs are not automatically equivalent to resolved semantic values;
- preserve `InteractionGuard`, edit return targets and duplicate/stale/concurrent suppression in every callback slice.

## Phase E — handoff consolidation

Phase E started only after the selected Phase D slices were stable. Its read-only inventory and first two caller-proven consolidations are complete; use the current execution point above for the next decision.

First create a read-only caller/policy inventory for:

- working hours and manager availability;
- five-minute fallback;
- outside-hours behavior;
- presentation versus mutation;
- golden cases for 10:00–20:00 Europe/Kaliningrad, the five-minute boundary and outside hours.

Only then centralize one proven duplicate per PR. A confirmed defect may justify a narrow policy-preserving fix, but changing lead delivery, operator shifts, routing eligibility/bonuses or the fixed product handoff policy still requires explicit user authorization.

## Required gate for every PR

Before merge:

- `bash tests/run_required_checks.sh`;
- green aggregate Regression tests and Retired domain guard;
- Workspace visual QA for relevant UI changes.

For a runtime change, also require an exact caller inventory and a behavior regression, not only source-string assertions.

After every merge, before starting another PR:

- `main = deploy_status = ops_status = autopilot production SHA`;
- deploy verify/bundle/sync/migrations/webhook/smoke/diagnostics stages all succeed;
- migrations: pending 0, checksum failures 0;
- strict MAX TLS on the exact SHA: API and upload HTTP 200, curl errno 0, SSL verify 0;
- MAX and Telegram webhook checks succeed;
- manager visibility, lead detail, handoff integrity, admin project access and website attribution do not regress;
- no new operational failure or count regression; separately document expected elapsed-time aging of an unchanged historical item;
- natural live confirmation is stated only after a real post-deploy case.

## Rollback contract

- Prefer a new revert PR for one merge commit, with the same CI and exact production deploy.
- Do not migrate down or rewrite applied migrations.
- Do not delete/repair production business data merely to make diagnostics green.
- Treat update-versus-upsert semantics as irreversible enough to test before merge.
- Never restore public AI logs or weaken the external AI-log boundary from PR #660.

## Stop conditions

Stop the current slice and investigate before another merge when any of these occurs:

- `main` and production SHA differ unexpectedly;
- required CI, deploy, migration, strict TLS or webhook verification is red;
- manager/customer message delivery or lead delivery regresses;
- pending Telegram updates grow or a current-SHA 500 repeats;
- a new security exposure appears;
- a protected product-policy or hard-scope change seems necessary without explicit user authorization;
- rollback would require destructive data or migration reversal.

After each material, production-verified change, update issue #55 with the PR, CI runs, merge SHA, deploy/diagnostic/TLS/webhook evidence, remaining uncertainty and the next safe item.
