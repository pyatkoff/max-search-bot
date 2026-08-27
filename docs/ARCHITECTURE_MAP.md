# Architecture keep / merge / move / delete map

This is the current incremental refactoring map. It is intentionally conservative: production behavior wins over directory purity, and entries move only behind regression coverage.

## KEEP — canonical owners / forward paths

- `services/DialogueStateMachine.php` — dialogue transition validity.
- `services/NeedValueResolver.php` — deterministic need interpretation boundary.
- `services/NeedApplicationService.php` — applying recognized need values.
- `services/InteractionGuard.php` — callback concurrency/staleness safety.
- `services/ManagerRequestContext.php` — manager conversation authorization/visibility context.
- Sales-pipeline services/repositories — business lead state, independent from technical conversation status.
- `manager/workspace-v2.php` plus `manager/assets/workspace-v2-*` modules — forward manager UI, kept thin and progressively modular.
- `migrations/` — only owner of production schema evolution; applied files immutable.
- `tests/live_regressions/` — production-derived reusable dialogue scenarios.
- `tools/production_snapshot.php`, `tools/live_session_snapshot.php`, `tools/architecture_inventory.php` — bounded operational evidence for autopilot.

## MERGE — duplicate responsibilities to converge behind one owner

- Remaining direct field parsing in handlers/actions → `NeedValueResolver`.
- Remaining direct need mutation / next-field choice → `NeedApplicationService` and canonical progression owner.
- Manager endpoint authorization / CSRF / validation plumbing → shared manager request/application boundary.
- Repeated handoff policy wording/availability decisions → canonical handoff policy/application owner.
- Repeated sales-stage mutation paths → one sales-pipeline application service.

## MOVE — responsibilities that should leave their current layer when touched

- Business/state decisions still inside transport handlers → application/domain services.
- SQL persistence embedded in request/UI code → repositories/infrastructure services.
- Rendering/network logic remaining in `manager/workspace-v2.php` → focused `manager/assets/` modules or view helpers.
- Manager UI business mutations → manager application services; PHP endpoints remain interface adapters.
- Structured operational decisions embedded only in text logs → typed diagnostic events/snapshots.

## DELETE — only after callers are migrated and production verification is complete

- Dead duplicate parser/state branches superseded by canonical services.
- Legacy manager UI branches after Workspace V2 feature parity and production proof.
- Ad-hoc authorization/validation helpers duplicated by the shared manager boundary.
- Runtime schema-creation/alteration code after equivalent forward migrations exist.
- Bespoke regression runners when their scenario is represented by the reusable scenario engine without losing coverage.

## Audit rule

Run `php tools/architecture_inventory.php` during periodic full-repository audits. Treat its output as triage evidence, not an automatic refactor order: production/lead safety and the roadmap priority order still decide what is changed next.
