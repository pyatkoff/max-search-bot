# Architecture & Maintainability Refactor Roadmap

Updated: 2026-08-29

This roadmap complements issue #55 and `docs/TECHNICAL_AUDIT.md`. The current user-directed execution mode is **technical/checks first, Manager UX/visual second**. Confirmed production outages, lead-loss/data-integrity defects and broken manager handoff still interrupt refactor work immediately.

## Current baseline

Already completed and production-proven:

- [x] durable architecture/autopilot/operations/repository documentation exists;
- [x] `manager/index.php` is the single Workspace V2 production entrypoint; `workspace-v2.php` is retired;
- [x] Workspace V2 frontend is split into feature-owned `workspace-v2-*` modules;
- [x] shared `manager/lib/ManagerHttp.php` boundary exists and multiple push/media/pipeline/main API paths have been migrated incrementally;
- [x] Sales Pipeline foundation, lead outcomes, tasks/reminders and Kanban exist;
- [x] Manager startup/session/send/load/mutation integrity has focused regression coverage;
- [x] architecture inventory tooling exists (`tools/architecture_inventory.php`);
- [x] production/deploy/live-session diagnostics are published and used by autopilot;
- [x] production migrations are forward-only with checksum health in diagnostics.

The old roadmap lagged behind implementation; do not use old PR numbers or the retired Workspace alias as planning truth.

## Phase A — Test architecture and autopilot speed

Highest priority for the current technical pass.

- [x] Keep one canonical full gate: `tests/run_required_checks.sh`.
- [x] Add required-regression inventory/orphan detection so focused regression files cannot silently remain ungated.
- [ ] Introduce one machine-readable required-check manifest with stable groups.
- [ ] Group checks into at least `architecture`, `dialogue`, `website`, `manager`, `diagnostics`.
- [ ] Make PR CI run independent groups in parallel and expose group-level failures/timing.
- [ ] Preserve an all-groups local/autopilot command.
- [ ] Review duplicated PR/deploy verification only after main-branch provenance/branch protection makes removal safe.
- [ ] Classify optional/manual tests explicitly instead of relying on naming accidents.
- [ ] Convert formatting-sensitive source assertions to behavior tests when those areas are touched; keep static checks for true architecture/security invariants.

## Phase B — Architecture truth and inventory

- [x] Generate lightweight code-area/hotspot/runtime-write inventory from the real tree.
- [x] Correct repository map so Manager V2 ownership matches production.
- [ ] Publish/surface architecture inventory summary as an autopilot artifact or stable checkpoint.
- [ ] Add caller-backed legacy candidate inventory before any deletion.
- [ ] Review every `runtime_ddl` signal; production request paths must not mutate schema.
- [ ] Review `direct_sql_writes` signals and identify only the places where repositories/owners would reduce duplicated domain logic.
- [ ] Track hotspot trend rather than refactoring files solely because they are large.

## Phase C — Manager technical structure

Do this before the next broad visual polish pass.

- [x] Workspace core and feature modules separated.
- [x] Push status/API/enable, media upload/preview, Sales Pipeline and main Manager API work moved toward shared `ManagerHttp` lifecycle in narrow slices.
- [ ] Inventory remaining Manager/admin/routing endpoint families not using the canonical HTTP/auth/error boundary.
- [ ] Centralize conversation visibility/ownership authorization behavior shared by Manager and Sales Pipeline APIs.
- [ ] Split inline CSS/JS from `manager/admin.php` into owned assets.
- [ ] Split inline CSS/JS from `manager/routing.php` into owned assets.
- [ ] Introduce one small browser HTTP/auth/error client where workspace/admin/routing semantics genuinely match.
- [ ] Keep `workspace-v2.js` feature-neutral; do not turn shared core into another frontend monolith.

## Phase D — Dialogue canonical ownership

No large rewrite. Move one caller-backed rule at a time.

- [ ] Inventory remaining direct trip/status mutations in root legacy classes, handlers and callback actions.
- [ ] Route deterministic recognized values through `NeedValueResolver` where not already canonical.
- [ ] Route application/progression through `NeedApplicationService` / `NeedProgressionService` / `DialogueView` boundaries.
- [ ] Incrementally replace scattered transition conditions with canonical state-machine validation.
- [ ] Keep AI as understanding/fallback, never a parallel state owner.
- [ ] Remove legacy paths only after caller search + focused regression + production verification.

## Phase E — Handoff/routing consolidation

- [ ] Inventory duplicate working-hours, availability and fallback decisions.
- [ ] Keep one canonical handoff decision/application boundary.
- [ ] Centralize visibility/owner eligibility rules without changing operator shift state or routing policy as incidental refactor.
- [ ] Continue structured request → selection → push → take → reply → delivery evidence.
- [ ] Preserve the fixed product handoff policy documented in `AGENTS.md`.

## Phase F — Persistence and diagnostics

- [ ] Review Manager inbox/tasks/pipeline and diagnostics query patterns before adding indexes.
- [ ] Keep schema changes migration-only; applied migrations immutable and repairs forward-only.
- [ ] Introduce repositories only where repeated direct SQL leaks application/domain rules.
- [ ] Define a stable structured diagnostic event envelope and correlation convention for new producers.
- [ ] Incrementally correlate callback/dialogue/handoff/push/manager-delivery events where useful.

## Phase G — Manager UX and visual pass

Resume broad UX/visual work after the technical/check baseline is stable.

- [ ] Full responsive visual audit at 390, 430, 768 and 1440 CSS px.
- [ ] Inbox hierarchy, scan speed, urgency and task states.
- [ ] Conversation/composer keyboard/mobile/media/error states.
- [ ] Lead Card density and mutation/dirty/saving/error/success clarity.
- [ ] Kanban usefulness and mobile fallback.
- [ ] Admin/routing visual consistency after their asset split.

Every material UI slice must keep draft/context/scroll safety where applicable, server-side authorization and the original tourist transcript.

## Phase H — Legacy retirement

Only after canonical owners are proven:

- [ ] publish caller-backed legacy candidates;
- [ ] deprecate one path at a time;
- [ ] verify no production/live dependency;
- [ ] delete in isolated PRs with required CI and production smoke.

## Immediate autonomous sequence

1. Land required-regression inventory/orphan protection and synchronized technical docs.
2. Build required-check manifest + grouped runner without changing test semantics.
3. Parallelize PR regression groups and measure the speedup.
4. Audit Visual QA trigger/viewport coverage and production smoke coverage against current surfaces.
5. Run architecture inventory against current main and review runtime DDL/direct SQL/hotspots.
6. Finish the highest-value remaining Manager HTTP/auth/admin/routing structural slices.
7. Audit dialogue/handoff canonical ownership in small behavior-preserving slices.
8. Then return to the full Manager UX/visual pass.

## Continuous refactor questions

For every touched backend area:

- who is the canonical owner of this rule?
- is the same rule duplicated elsewhere?
- can the test assert behavior rather than source formatting?
- is the change retry/idempotency safe?
- does structured diagnostics explain failure?

For every user-facing change:

- desktop and mobile states checked?
- loading/empty/error/read-only states checked?
- no draft/context/scroll loss?
- permission behavior server-side?
- visual QA evidence captured when relevant?
