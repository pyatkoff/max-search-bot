# Architecture & Maintainability Refactor Roadmap

This roadmap complements GitHub issue #55. It is intentionally incremental and must not displace confirmed production defects or Manager Workspace V2 product work.

## Audit findings to address

### 1. Manager frontend monolith risk
- `manager/index.php` is a legacy all-in-one PHP/CSS/JS screen.
- Workspace V2 now has a thin PHP shell plus extracted CSS/JS after #183.
- Action: split the extracted JS into focused modules only as features are touched; do not rebuild the frontend framework.

### 2. Manager API duplication
- `manager/api.php`, `manager/pipeline-api.php`, media/push/admin endpoints repeat session/auth/CSRF/visibility patterns.
- Action: use `ManagerRequestContext` as the shared manager HTTP/session/authorization boundary and migrate endpoints one by one.

### 3. Dialogue business logic spans multiple generations
- root legacy orchestration, handlers, actions/callbacks and services coexist.
- `StateMessageHandler`, `AiMessageHandler`, callback actions and legacy base still own pieces of progression.
- Action: continue moving field resolution/application and transition validity to `NeedValueResolver`, `NeedApplicationService` and `DialogueStateMachine` instead of adding new local branches.

### 4. Services directory is too flat
- `services/` contains dialogue, manager, routing, persistence, analytics, UI helpers, parsers and infrastructure in one namespace/directory.
- Action: do not mass-move files. Introduce conceptual modules first and move only touched clusters when imports/tests make the boundary clear.

Candidate clusters:
- Dialogue: state, trip, parsers, need resolution/application, interaction guard, views.
- Handoff: manager request, availability, dispatch, phone fallback, timeline/integrity.
- Manager: auth, conversations, outbound/media, push, admin directory.
- Sales: pipeline, tasks/reminders/outcomes.
- Infrastructure: DB/migrations/logging/external adapters.

### 5. Authorization is easy to duplicate incorrectly
- Workspace V2 exposed how source-format-sensitive regression checks can hide actual permission behavior.
- Action: centralize `manager/admin`, visibility and ownership rules and test behavior rather than endpoint formatting.

### 6. Migration discipline needs explicit guardrails
- partial/repaired pipeline migrations demonstrated the cost of changing already-recorded files.
- Action: keep applied migrations immutable and repairs forward-only; preserve required checksum/migration-runner checks.

### 7. Regression suite contains fragile source assertions
- many tests are valuable, but some test exact source strings/formatting.
- Action: convert ordinary behavior assertions to service/API behavior tests as each area is touched. Keep static assertions for architecture/security invariants only.

### 8. Observability is strong but fragmented
- structured callback, handoff, push and delivery diagnostics exist, but there is no single event contract across all domains.
- Action: define stable event envelope/correlation conventions and migrate new events first; backfill old producers incrementally.

## Execution phases

### Phase A — Repository architecture baseline
- [x] Add `docs/ARCHITECTURE.md` target dependency direction and invariants.
- [x] Add this explicit refactor roadmap.
- [ ] Add lightweight module/dependency inventory generated or maintained from the real tree.
- [ ] Identify legacy candidates with evidence of callers before any deletion.
- [ ] Add architecture checks only where they protect important boundaries without coupling to formatting.

### Phase B — Manager Workspace V2 structural split
- [x] Extract Workspace V2 CSS to `manager/assets/workspace-v2.css` (#183).
- [x] Extract Workspace V2 JS to `manager/assets/workspace-v2.js` without changing behavior (#183).
- [ ] Split JS into small modules only as the relevant UI areas are touched.
- [ ] Introduce shared manager API bootstrap/session/CSRF helper. `ManagerRequestContext` foundation is in #187; migrate remaining endpoints incrementally.
- [ ] Introduce shared manager conversation authorization helper. Owner/admin pipeline edit authorization moves into `ManagerRequestContext` in #187; visibility/read access still needs consolidation.
- [ ] Keep legacy manager UI operational until V2 feature parity and production verification.

### Phase C — Sales workflow product work on the new structure
- [x] Wire lead outcome (`open/won/lost`), close reason and note into Lead Card V2 (#181/#184).
- [x] Make Inbox V2 lead-centric with outcome/search filters and batch projection (#186).
- [ ] Add manager tasks/reminders with due/overdue inbox views.
- [ ] Add admin-configurable pipeline stages/tags UI.
- [ ] Add kanban only after list-mode stage management is stable.
- [ ] Add close/outcome analytics after enough real usage exists.

### Phase D — Dialogue canonical ownership
- [ ] Inventory remaining direct trip-state/status mutations in handlers/actions/legacy base.
- [ ] Route remaining deterministic need fields through `NeedValueResolver`.
- [ ] Route application/progression through `NeedApplicationService`/canonical progression boundary.
- [ ] Incrementally replace scattered transition conditions with `DialogueStateMachine` validation.
- [ ] Keep AI as understanding/fallback, not state owner.
- [ ] Remove dead legacy paths only after caller search + regression + production evidence.

### Phase E — Handoff/routing consolidation
- [ ] Inventory duplicated working-hours/availability/fallback decisions.
- [ ] Keep one canonical handoff policy/application boundary.
- [ ] Centralize owner/visibility rules shared by manager API and pipeline/media endpoints.
- [ ] Continue structured request → selection → push → take → reply → delivery lifecycle.

### Phase F — Persistence and infrastructure cleanup
- [ ] Inventory runtime DDL and eliminate remaining request-path schema mutation.
- [ ] Review indexes for manager inbox pipeline filters/tasks and hot diagnostics queries.
- [ ] Move search/outcome filtering closer to SQL only when data volume/latency evidence justifies it; current V2 batch projection avoids N+1 but still decorates a bounded candidate set in PHP.
- [ ] Introduce repositories where direct SQL currently leaks into domain/application logic and causes duplication.
- [ ] Keep simple direct queries where abstraction would add no value.

### Phase G — Test architecture cleanup
- [ ] Tag/source-list tests by type: behavior, scenario, integration, static architecture.
- [ ] Replace fragile formatting assertions in touched areas. Workspace V2 ownership/outcome assertions have started moving to shared contracts in #187.
- [ ] Keep production-derived live regression corpus bounded and reusable.
- [ ] Add manager V2 behavior tests for permissions, outcome, tasks and filters.

### Phase H — Legacy retirement
Only after V2 and canonical services are proven:
- [ ] publish a caller-backed legacy candidate list;
- [ ] deprecate one path at a time;
- [ ] verify no production/live dependency;
- [ ] delete in isolated PRs with required CI and production smoke.

## Continuous quality loop

Every material UI feature should include:
- desktop and mobile UX review;
- empty/loading/error/read-only states;
- permission behavior;
- no text/draft loss during refresh;
- production-safe incremental updates rather than unnecessary full rerenders where practical.

Every touched backend area should ask:
- who is the canonical owner of this rule?
- is the same rule duplicated elsewhere?
- can the test assert behavior rather than source formatting?
- is the change retry/idempotency safe?
- does structured diagnostics explain failure?

## Immediate next sequence

1. Finish #187 and verify the shared manager request context in production.
2. Migrate media endpoints to the shared manager request/session/CSRF boundary without changing media behavior.
3. Migrate the main manager API bootstrap/admin checks incrementally, preserving login/session semantics.
4. Add a caller/dependency inventory and identify safe legacy candidates before moving/deleting files.
5. Review hot Inbox V2 queries/indexes before tasks/reminders add new due/overdue filters.
6. Build tasks/reminders on the cleaned manager boundary, then add Kanban only after list-mode workflow is stable.
7. Continue dialogue-core canonicalization in parallel only when no live/product-manager issue outranks it.
