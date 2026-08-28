# Technical Audit Baseline

Updated: 2026-08-29

This document is the current engineering baseline for fast autonomous development. It complements `AGENTS.md`, `docs/AUTOPILOT.md`, `docs/ARCHITECTURE.md` and `docs/REFACTOR_ROADMAP.md`.

The current execution mode is intentionally **technical/checks first, Manager UX/visual second**. A confirmed production outage, lead-loss defect, data-integrity failure or handoff failure still interrupts the refactor pass immediately.

## Executive findings

### 1. Required test coverage is broad but difficult to operate

The project has strong focused regression coverage, but `tests/run_required_checks.sh` is a large manually maintained sequential command list. This creates three engineering costs:

- a new regression can exist without being attached to a required gate;
- failures are slower to locate because most checks run inside one CI step;
- unrelated test domains cannot yet run in parallel.

Immediate action:
- keep `tests/run_required_checks.sh` as the canonical full local gate;
- add required-regression inventory/orphan detection;
- next introduce a manifest with stable groups and make both the shell runner and CI consume it;
- then shard PR CI by group while retaining one all-groups command for local/deploy verification.

Do not remove deploy verification until main-branch provenance guarantees are explicit and protected.

### 2. Durable documentation has drifted behind the implementation

The architecture moved faster than the old roadmap. Current facts:

- `manager/index.php` is the canonical Workspace V2 shell;
- `workspace-v2.php` is retired;
- Workspace frontend responsibilities are already split across `workspace-v2-*` modules;
- `ManagerHttp` exists and owns the shared Manager request/session/auth/CSRF boundary for multiple endpoint families, including the main Manager API work completed incrementally;
- Sales Pipeline outcome, tasks/reminders, Kanban and multiple Manager reliability states already exist;
- architecture inventory tooling already exists.

Action:
- keep `REPO_MAP.md`, `REFACTOR_ROADMAP.md` and Manager architecture synchronized with actual main;
- checkpoints belong in issue #55, durable ownership/rules belong in docs.

### 3. CI should become faster without reducing coverage

Current PR CI runs the full required suite sequentially. Deploy verify repeats the full suite on main before production sync.

Target:
- classify required checks into deterministic groups such as `architecture`, `dialogue`, `website`, `manager`, `diagnostics`;
- run groups in parallel in PR CI;
- surface group names and duration/failure independently;
- retain a manifest-integrity gate that proves every required regression is assigned;
- retain a canonical full runner for local/autopilot use and deploy until branch protection allows a safer provenance-based deploy optimization.

### 4. Architecture has good boundaries but several remaining concentration points

Healthy direction already exists:

- transport/provider contracts under `contracts/` and `integrations/`;
- dialogue actions/controllers/services instead of one webhook monolith;
- canonical recognized-need flow toward `NeedValueResolver → NeedApplicationService → NeedProgressionService / DialogueView`;
- Manager feature modules and shared `ManagerHttp`;
- structured production/live diagnostics.

Remaining concentration points:

- root legacy search/state classes still coexist with the newer application layer;
- `services/` is a flat mixed domain/infrastructure namespace;
- Manager admin/routing pages still contain inline presentation/application code;
- some frontend fetch/auth/error handling is duplicated;
- direct SQL writes and authorization decisions remain spread across several services/endpoints;
- diagnostics event producers do not yet share one stable correlation/envelope convention.

No mass rewrite or mass file move is recommended. Refactor one caller-backed ownership boundary at a time.

### 5. Production observability is useful and should become the autopilot control plane

`production_snapshot`, live sessions, handoff diagnostics and deploy telemetry already provide good evidence. The next improvement is to make technical state equally easy to consume:

- publish architecture/test inventory summaries in a stable machine-readable form;
- add test-group timing/failure information;
- expose hotspot trend and direct-SQL/runtime-DDL signals without requiring a fresh manual repository scan;
- unify correlation IDs for callback → dialogue → handoff → push → manager reply/delivery where practical.

## Prioritized refactor queue

### P0 — Autopilot/test safety

1. Required regression inventory and orphan detection.
2. Required-check manifest and stable test groups.
3. Parallel PR CI with clear group-level failures.
4. Verify that Visual QA is triggered by the correct Manager/user-facing path set and that its viewport matrix remains 390/430/768/1440.
5. Review duplicate CI work and move only safe, provenance-protected duplication out of the critical path.

### P1 — Documentation and architecture truth

1. Synchronize `REPO_MAP.md` and `REFACTOR_ROADMAP.md` with current Manager V2 reality.
2. Generate/surface architecture inventory regularly rather than relying on old prose.
3. Maintain caller-backed legacy candidate inventory before deleting anything.
4. Classify tests by behavior/scenario/integration/static architecture; reduce formatting-sensitive source assertions as touched.

### P2 — Manager technical structure

1. Finish narrow `ManagerHttp` migrations for remaining Manager/admin/routing endpoint families.
2. Split `admin.php` inline CSS/JS into owned assets.
3. Split `routing.php` inline CSS/JS into owned assets.
4. Introduce one small Manager browser HTTP/auth/error client used by workspace/admin/routing where semantics match.
5. Centralize conversation visibility/ownership authorization behavior so API and pipeline paths cannot drift.

### P3 — Dialogue and handoff ownership

1. Inventory remaining direct trip/status mutations in legacy classes/handlers/actions.
2. Move deterministic recognized values through canonical resolver/application/progression services when touched.
3. Consolidate duplicated working-hours/availability/fallback policy behind one handoff decision/application boundary.
4. Keep AI as interpretation/fallback, not parallel state owner.

### P4 — Persistence and observability

1. Review architecture-inventory `runtime_ddl` and `direct_sql_writes` signals one by one.
2. Confirm all production schema mutation is migration-only and applied migration checksums remain immutable.
3. Review indexes for Manager inbox/tasks/pipeline and diagnostics hot queries using production query patterns before adding indexes.
4. Introduce repositories only where repeated SQL leaks domain rules; do not wrap simple one-off queries mechanically.
5. Define a stable diagnostic event envelope/correlation convention for new producers, then migrate old producers incrementally.

### P5 — Manager UX and visual pass

After P0/P1 and the highest-value P2 slices are stable:

1. Full responsive visual audit at 390/430/768/1440.
2. Inbox information hierarchy, scan speed and urgency states.
3. Conversation/composer resilience, keyboard/mobile ergonomics and media states.
4. Lead Card density, dirty/saving/error/success states and Sales Pipeline clarity.
5. Kanban usefulness and mobile fallback.
6. Admin/routing visual consistency after their structural split.

Every material UX change must preserve drafts, selected lead/context, scroll where appropriate, server-side authorization and original tourist transcript.

## Definition of a successful technical pass

The refactor pass is considered complete enough to return focus to product UX when:

- every focused regression is either required or explicitly documented as manual/optional;
- PR CI gives fast, domain-specific failure feedback and runs independent groups in parallel;
- durable docs match the actual repository entrypoints/modules;
- architecture inventory has no unexplained runtime DDL and has a reviewed direct-write list;
- remaining Manager endpoint/auth duplication has named owners and a short caller-backed queue;
- no known production/deploy/handoff integrity incident is unresolved.
