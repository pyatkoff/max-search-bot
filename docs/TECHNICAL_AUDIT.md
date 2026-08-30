# Technical Audit Baseline

Updated: 2026-08-30

This document is the current engineering baseline for fast autonomous development. It complements `AGENTS.md`, `docs/AUTOPILOT.md`, `docs/ARCHITECTURE.md`, `docs/ARCHITECTURE_MAP.md` and `docs/REFACTOR_ROADMAP.md`.

The current execution mode is intentionally **technical/checks first, Manager UX/visual second**. A confirmed production outage, lead-loss defect, data-integrity failure or handoff failure still interrupts the refactor pass immediately.

## Executive findings

### 1. Required test architecture is now strong enough to be the default development control plane

The project now has:

- one machine-readable manifest: `tests/required_checks_manifest.php`;
- stable groups: `architecture`, `dialogue`, `website`, `manager`, `diagnostics`;
- required-regression inventory/orphan detection;
- parallel PR execution per group;
- aggregate `regression` gate;
- PHP syntax gate;
- canonical all-groups local/deploy command: `tests/run_required_checks.sh`.

The old finding that required checks are one manually maintained sequential CI list is no longer true.

Remaining work:
- record/report group duration so test cost can be optimized from evidence;
- explicitly classify manual/optional checks;
- keep reducing formatting-sensitive source assertions when touched, replacing them with behavior tests where possible;
- do not remove deploy verification until branch-protection/provenance guarantees make reuse of PR evidence safe.

### 2. Visual QA now has executable layout protection, not only screenshots

Workspace visual QA covers 390, 430, 768 and 1440 CSS px. Since #334 it also fails on concrete responsive regressions:

- horizontal viewport overflow;
- mobile composer width/height collapse;
- mobile quick replies occupying the wrong layout position while typing;
- collapsed message bubbles;
- unusable media bubble sizing;
- broken desktop three-zone geometry.

The main Workspace fixture now loads production mobile CSS directly. CI no longer mutates that fixture with `sed` before capture.

Screenshots remain useful review evidence, but a green screenshot workflow is no longer treated as proof by existence alone: high-risk geometry has executable assertions.

### 3. Durable architecture documentation must track main continuously

Current facts:

- `manager/index.php` is the canonical Workspace V2 shell;
- `workspace-v2.php` is retired;
- Workspace responsibilities are split across focused `workspace-v2-*` modules;
- `ManagerHttp` owns shared Manager request/session/auth/CSRF lifecycle for multiple endpoint families;
- `manager-http-client.js` is already shared by Workspace/Admin/Routing where semantics match;
- `manager/admin.php` and `manager/routing.php` already use external owned CSS/JS assets;
- Sales Pipeline outcomes, tasks/reminders and Kanban exist;
- architecture inventory tooling exists.

Issue #55 is the live roadmap/status. Durable ownership belongs in docs. When main contradicts an unchecked doc item, inspect main and correct the doc rather than reimplementing completed work.

### 4. Architecture direction is healthy; remaining work is ownership consolidation, not file shuffling

Healthy boundaries already exist:

- transport/provider contracts under `contracts/` and `integrations/`;
- dialogue handlers/actions/controllers/services instead of one webhook monolith;
- canonical recognized-need direction: `NeedValueResolver → NeedApplicationService → NeedProgressionService / DialogueView`;
- `DialogueStateMachine` and `InteractionGuard` as state/safety owners;
- Manager feature modules plus `ManagerHttp`;
- Sales Pipeline and lead-task services separated from technical conversation state;
- migration-only schema evolution with checksum verification;
- structured production/live/handoff diagnostics.

Remaining concentration points:

- root legacy search/state classes still coexist with the newer application layer;
- `services/` remains a flat mixed application/domain/infrastructure namespace;
- direct SQL writes and some authorization/visibility decisions remain spread across multiple services/endpoints;
- working-hours/availability/fallback handoff policy still needs a single clearly enforced owner;
- diagnostics producers do not yet share one stable envelope/correlation convention;
- legacy retirement still lacks a regularly published caller-backed candidate list.

No mass rewrite or mass directory move is recommended. Refactor one caller-backed ownership boundary at a time, behind focused behavior coverage.

### 5. Technical observability should become the autopilot control plane

Production observability is already strong for runtime behavior: production SHA, migrations, manager visibility, response health, push reachability, handoff integrity, website attribution and bounded live-session evidence are published.

The next missing layer is repository/engineering state:

- publish `architecture_inventory.php` output as a stable diagnostics artifact after deploy;
- surface runtime-DDL/direct-SQL/hotspot signals without requiring a manual repository reread;
- track hotspot trend instead of reacting to file size alone;
- add test-group timing;
- later define one diagnostic event envelope/correlation convention for new producers.

## Prioritized refactor queue

### P0 — Autopilot/test safety

Completed:
1. Required regression inventory and orphan detection.
2. Required-check manifest and stable groups.
3. Parallel PR CI with domain-specific failures.
4. Aggregate required gate plus full local/deploy runner.
5. Responsive Workspace viewport matrix.
6. Executable browser layout assertions for high-risk conversation/composer/media/desktop geometry.

Next:
1. Add group timing/reporting.
2. Explicitly classify manual/optional tests.
3. Review duplicate CI work only after provenance/branch protection makes optimization safe.

### P1 — Documentation and architecture truth

1. Keep `REPO_MAP.md`, `REFACTOR_ROADMAP.md`, `TECHNICAL_AUDIT.md` and `ARCHITECTURE_MAP.md` synchronized with main.
2. Publish architecture inventory regularly rather than relying on stale prose.
3. Maintain caller-backed legacy candidates before deleting anything.
4. Classify tests by behavior/scenario/integration/static architecture as those areas are touched.

### P2 — Manager technical structure

Completed:
- core Workspace feature split;
- shared Manager browser HTTP client where semantics match;
- Admin/Routing external CSS/JS split;
- multiple Manager endpoint families migrated to `ManagerHttp`.

Next:
1. Inventory remaining Manager endpoints outside the canonical HTTP/auth/error boundary.
2. Centralize conversation visibility/ownership authorization behavior shared by Manager and Sales Pipeline APIs.
3. Remove duplicate client/server request/authorization helpers only after caller search and focused regressions.
4. Keep `workspace-v2.js` feature-neutral.

### P3 — Dialogue and handoff ownership

1. Inventory remaining direct trip/status mutations in legacy classes/handlers/actions.
2. Move deterministic recognized values through canonical resolver/application/progression services when touched.
3. Incrementally replace scattered transition conditions with state-machine validation.
4. Consolidate duplicated working-hours/availability/fallback policy behind one handoff decision/application boundary.
5. Keep AI as interpretation/fallback, not parallel state owner.

### P4 — Persistence and observability

1. Publish architecture inventory in diagnostics and review its `runtime_ddl`, `direct_sql_writes` and hotspot signals one by one.
2. Confirm all production schema mutation remains migration-only and applied migration checksums immutable.
3. Review indexes for Manager inbox/tasks/pipeline and diagnostics hot queries using actual query patterns before adding indexes.
4. Introduce repositories only where repeated SQL leaks domain rules; do not wrap simple one-off queries mechanically.
5. Define a stable diagnostic event envelope/correlation convention for new producers, then migrate older producers incrementally.

### P5 — Manager UX and visual pass

After P0/P1 and the highest-value P2/P4 slices are stable:

1. Full responsive audit at 390/430/768/1440 using executable layout checks plus screenshots.
2. Inbox information hierarchy, scan speed and urgency states.
3. Conversation/composer resilience, keyboard/mobile ergonomics and media/error states.
4. Lead Card density, dirty/saving/error/success states and Sales Pipeline clarity.
5. Kanban usefulness and mobile fallback.
6. Admin/routing visual consistency.

Every material UX change must preserve drafts, selected lead/context, scroll where appropriate, server-side authorization and the original tourist transcript.

## Definition of a successful technical pass

The technical pass is complete enough to return primary focus to product UX when:

- every focused regression is required or explicitly manual/optional;
- PR CI gives domain-specific parallel feedback and its slow groups are visible;
- durable docs match actual entrypoints/modules;
- architecture inventory is published automatically and has no unexplained runtime DDL;
- direct-write/legacy candidates have named owners and caller evidence;
- remaining Manager endpoint/auth/visibility duplication has a short explicit queue;
- no known production/deploy/handoff integrity incident is unresolved.
