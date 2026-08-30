# Architecture & Maintainability Refactor Roadmap

Updated: 2026-08-30

This roadmap complements issue #55 and `docs/TECHNICAL_AUDIT.md`. Current execution mode is **technical/checks first, Manager UX/visual second**. Confirmed production outages, lead-loss/data-integrity defects and broken manager handoff still interrupt refactor work immediately.

## Current baseline

Already completed and production-proven:

- [x] durable architecture/autopilot/operations/repository documentation exists;
- [x] `manager/index.php` is the single Workspace V2 production entrypoint; `workspace-v2.php` is retired;
- [x] Workspace V2 frontend is split into feature-owned `workspace-v2-*` modules;
- [x] shared `manager/lib/ManagerHttp.php` boundary exists and multiple push/media/pipeline/main API paths have been migrated incrementally;
- [x] `manager/admin.php` and `manager/routing.php` presentation/behavior are split into owned CSS/JS assets and both use the shared browser HTTP client;
- [x] Sales Pipeline foundation, lead outcomes, tasks/reminders and Kanban exist;
- [x] Manager startup/session/send/load/mutation integrity has focused regression coverage;
- [x] one machine-readable required-check manifest exists with stable `architecture`, `dialogue`, `website`, `manager`, `diagnostics` groups;
- [x] PR CI runs required groups independently in parallel while preserving the aggregate `regression` gate;
- [x] `tests/run_required_checks.sh` remains the canonical full local/deploy gate;
- [x] required-regression inventory/orphan detection prevents focused regressions from silently escaping the required suite;
- [x] Workspace visual QA captures 390/430/768/1440 evidence and, since #334, also enforces browser layout contracts for viewport overflow, mobile composer usability, bubble/media sizing and desktop three-zone geometry;
- [x] visual fixtures load production styles directly; CI no longer rewrites the main Workspace fixture at runtime;
- [x] architecture inventory tooling exists (`tools/architecture_inventory.php`);
- [x] production/deploy/live-session diagnostics are published and used by autopilot;
- [x] production migrations are forward-only with checksum health in diagnostics.

Do not use old unchecked roadmap items as implementation truth. Verify current main first, then update this document when a technical milestone lands.

## Phase A — Test architecture and autopilot speed

- [x] Keep one canonical full gate: `tests/run_required_checks.sh`.
- [x] Required-regression inventory/orphan detection.
- [x] Machine-readable required-check manifest with stable groups.
- [x] Parallel PR groups: `architecture`, `dialogue`, `website`, `manager`, `diagnostics`.
- [x] Preserve one aggregate required result (`regression`) and one all-groups local/deploy command.
- [x] Promote responsive Workspace Visual QA from screenshot-only evidence to executable layout assertions.
- [ ] Add timing metadata/reporting per required group so slow areas can be optimized from evidence rather than intuition.
- [ ] Classify optional/manual tests explicitly instead of relying on naming accidents.
- [ ] Review duplicated PR/deploy verification only after main-branch provenance/branch protection makes removal safe.
- [ ] Convert formatting-sensitive source assertions to behavior tests when those areas are touched; keep static checks for true architecture/security invariants.

## Phase B — Architecture truth and inventory

- [x] Generate lightweight code-area/hotspot/runtime-write inventory from the real tree.
- [x] Correct repository map so Manager V2 ownership matches production.
- [x] Maintain explicit keep/merge/move/delete ownership map in `docs/ARCHITECTURE_MAP.md`.
- [ ] Publish/surface architecture inventory summary as a stable diagnostics/autopilot artifact after deploys.
- [ ] Add caller-backed legacy candidate inventory before any deletion.
- [ ] Review every `runtime_ddl` signal; production request paths must not mutate schema.
- [ ] Review `direct_sql_writes` signals and identify only the places where repositories/owners would reduce duplicated domain logic.
- [ ] Track hotspot trend rather than refactoring files solely because they are large.

## Phase C — Manager technical structure

Do this before the next broad visual polish pass.

- [x] Workspace core and feature modules separated.
- [x] Push status/API/enable, media upload/preview, Sales Pipeline and main Manager API work moved toward shared `ManagerHttp` lifecycle in narrow slices.
- [x] Admin and routing CSS/JS moved out of PHP shells into owned assets.
- [x] Shared `manager-http-client.js` is used by Workspace/Admin/Routing where request/auth/error semantics match.
- [ ] Inventory remaining Manager endpoint families not using the canonical HTTP/auth/error boundary.
- [ ] Centralize conversation visibility/ownership authorization behavior shared by Manager and Sales Pipeline APIs.
- [ ] Keep `workspace-v2.js` feature-neutral; do not turn shared core into another frontend monolith.
- [ ] Review duplicate client-side request/error wrappers and remove only caller-proven duplicates.

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

- [ ] Publish architecture/test inventory in stable diagnostics so technical drift is visible without a manual repository reread.
- [ ] Review Manager inbox/tasks/pipeline and diagnostics query patterns before adding indexes.
- [ ] Keep schema changes migration-only; applied migrations immutable and repairs forward-only.
- [ ] Introduce repositories only where repeated direct SQL leaks application/domain rules.
- [ ] Define a stable structured diagnostic event envelope and correlation convention for new producers.
- [ ] Incrementally correlate callback/dialogue/handoff/push/manager-delivery events where useful.

## Phase G — Manager UX and visual pass

Resume broad UX/visual work after the technical/check baseline is stable.

- [ ] Full responsive visual audit at 390, 430, 768 and 1440 CSS px, using both screenshots and executable layout assertions.
- [ ] Inbox hierarchy, scan speed, urgency and task states.
- [ ] Conversation/composer keyboard/mobile/media/error states.
- [ ] Lead Card density and mutation/dirty/saving/error/success clarity.
- [ ] Kanban usefulness and mobile fallback.
- [ ] Admin/routing visual consistency after their structural split.

Every material UI slice must preserve drafts, selected lead/context, scroll where applicable, server-side authorization and the original tourist transcript.

## Phase H — Legacy retirement

Only after canonical owners are proven:

- [ ] publish caller-backed legacy candidates;
- [ ] deprecate one path at a time;
- [ ] verify no production/live dependency;
- [ ] delete in isolated PRs with required CI and production smoke.

## Immediate autonomous sequence

1. Publish architecture inventory as a stable deploy/diagnostics artifact and review current signals.
2. Add test-group timing/reporting and explicit optional/manual classification if it can be done without weakening the required gate.
3. Finish the highest-value remaining Manager HTTP/auth/authorization ownership slices.
4. Audit dialogue and handoff canonical ownership in small behavior-preserving slices.
5. Review direct SQL/runtime DDL/hotspots from current inventory and create caller-backed refactor candidates.
6. Then return to the full Manager UX/visual pass with the stronger layout gate in place.

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
- executable layout assertions plus visual evidence captured when relevant?
