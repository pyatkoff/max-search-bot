# Architecture keep / merge / move / delete map

This is the current incremental refactoring map. It is intentionally conservative: production behavior wins over directory purity, and entries move only behind regression coverage.

## KEEP — canonical owners / forward paths

- `services/DialogueStateMachine.php` — dialogue transition validity.
- `services/NeedValueResolver.php` — deterministic need interpretation boundary.
- `services/NeedApplicationService.php` — applying recognized need values.
- `services/InteractionGuard.php` — callback concurrency/staleness safety.
- `services/TourSearchHandoffService.php` — canonical normalization boundary from saved dialogue/claim data to the public tour-search query contract (route, dates, nights, tourists/child ages, stars, meal and yclid); `ProjectConfig` may keep compatibility URL helpers but must not regain field-mapping ownership.
- `services/RuntimeBootstrap.php` — canonical runtime bootstrap for MAX webhook, Telegram webhook and followup cron; standalone mode is explicit/opt-in and must not be inferred from hostname.
- `services/RuntimeStorage.php` plus the MySQL runtime repositories — canonical storage switch for standalone conversation/claim/runtime persistence while legacy production remains available until cutover proof.
- `services/DestinationCatalogRepository.php` — destination catalog storage boundary; consumers must not read Bitrix HL directly when a repository path exists.
- `services/LeadDeliveryGateway.php` — canonical lead-delivery boundary. `BitrixLeadDeliveryGateway` preserves current local production delivery; `HttpLeadDeliveryGateway` is the authenticated standalone transport for the already-built canonical element and must not own business fields.
- `services/StandaloneReadiness.php` + `tools/standalone_readiness.php` — cutover gate. A new host is not standalone-ready until runtime/database/catalog storage and the supported lead bridge configuration are all explicitly green; secret values must never be printed.
- `services/ManagerRequestContext.php` — manager identity plus conversation authorization/visibility context.
- `manager/lib/ManagerHttp.php` — shared Manager HTTP/session/auth/CSRF/JSON/error boundary for thin PHP endpoints; new endpoint plumbing should converge here instead of recreating local guards.
- `manager/assets/manager-http-client.js` — shared browser request/auth/error helper for Manager/Admin/Routing and focused feature endpoints where semantics actually match; endpoint selection is a transport concern, while feature-specific state stays in its owning module.
- `services/AdminDirectoryService.php` — bounded admin directory/read snapshot composition.
- `services/AuditLogService.php` — admin audit persistence plus bounded data-minimized read projection; UI must not expose raw before/after payloads.
- `services/LeadTaskService.php` — lead task/reminder mutations, explicit pin/priority state, ordering, canonical urgency semantics (`overdue` / `today` / `upcoming` / `unscheduled`, Europe/Kaliningrad business day), and the operational work buckets/rank consumed by Manager read models.
- `services/SalesPipelineService.php` — canonical per-lead business sales state, stage history, tags, outcome and sale facts; independent from technical conversation status.
- `services/SalesPipelineCatalogAdminService.php` — admin-only stage/tag catalog mutations, lifecycle validation and usage-aware safety; audit every catalog change, prevent active stages or assigned tags from being silently deactivated while leads still depend on them, and never mutate technical dialogue state.
- `manager/pipeline-api.php` — thin authorized Sales Pipeline interface; ordinary lead mutations and role-gated catalog administration delegate to their application owners.
- `manager/pipeline-admin.php` + `manager/assets/pipeline-admin.css` + `manager/assets/pipeline-admin.js` — focused role-gated business-funnel catalog UI; kept separate from Workspace V2 and general admin page to avoid another monolith.
- `manager/assets/workspace-v2-pipeline.js` — Workspace V2 browser owner for sales-stage/tag/outcome mutations plus pipeline filters; lead-card rendering delegates mutation wiring here instead of issuing sales writes directly.
- `manager/index.php` plus focused `manager/assets/workspace-v2-*` modules — canonical forward Manager UI entrypoint, kept thin and progressively modular; do not recreate a second Workspace PHP shell.
- `manager/admin.php` + `manager/assets/admin.css` + `manager/assets/admin.js` — current role-gated admin interface; PHP remains a thin shell and presentation/behavior stay in owned assets.
- `manager/routing.php` + `manager/assets/routing.css` + `manager/assets/routing.js` — current role-gated routing interface; PHP remains a thin shell and routing policy is not to be duplicated in frontend code.
- `migrations/` — only owner of production schema evolution; applied files immutable.
- `services/MigrationRunner.php` — migration execution infrastructure only. DDL here is reported separately as `schema_infrastructure_ddl`; it must never be treated as permission for business/request services to own schema.
- `tests/required_checks_manifest.php`, `tests/run_required_group.php`, `tests/run_required_checks.sh` — canonical required-check inventory/group/full-suite orchestration.
- `tests/visual/workspace-v2-layout.spec.js` plus visual fixtures/workflow — executable responsive layout contracts plus screenshot evidence for material manager surfaces, including focused admin pages; fixtures load production assets directly and must not be rewritten in CI.
- `tests/scenarios/<suite>/` + `tests/support/ScenarioEngine.php` — reusable production-derived behavior scenarios; add new step handlers only when a real case needs them.
- `tools/production_snapshot.php`, `tools/live_session_snapshot.php`, `tools/architecture_inventory.php` — bounded operational evidence for autopilot. `runtime_ddl` is reserved for request/business runtime code; migration infrastructure is classified separately so the signal remains actionable.

## MERGE — duplicate responsibilities to converge behind one owner

- Remaining direct field parsing in handlers/actions → `NeedValueResolver`.
- Remaining direct need mutation / next-field choice → `NeedApplicationService` and canonical progression owner.
- Remaining claim/saved-dialogue → public search query mapping or hand-built tour-search parameter lists → `TourSearchHandoffService`; do not duplicate the public search contract in `TourResultsService`, `PostTourService`, handlers or manager UI.
- Remaining direct Bitrix HL/catalog reads in destination/search code → `DestinationCatalogRepository` or the relevant repository boundary.
- Remaining direct lead persistence/transport calls → `LeadDeliveryGateway`; business payload construction stays in `LeadPayloadService` and transport adapters must not re-map lead semantics.
- Remaining runtime/bootstrap decisions in webhook/cron entrypoints → `RuntimeBootstrap`; do not create per-transport standalone switches.
- Remaining Manager endpoint session/auth/CSRF/JSON/error plumbing → `manager/lib/ManagerHttp.php`; conversation edit visibility stays delegated to `ManagerRequestContext` rather than duplicated.
- Remaining browser fetch/auth/error wrappers with the same semantics → `manager/assets/manager-http-client.js`; do not merge feature-specific request behavior merely for code-count reduction.
- Repeated admin directory/audit read assembly → `AdminDirectoryService` / `AuditLogService`, leaving `manager/admin.php` as an interface renderer.
- Repeated handoff policy wording/availability decisions → canonical handoff policy/application owner.
- Repeated sales-stage/tag/outcome mutation paths → sales-pipeline application services on the backend and `workspace-v2-pipeline.js` for Workspace V2 browser orchestration.
- Any stage/tag catalog writes outside `SalesPipelineCatalogAdminService` → delegate there; `SalesPipelineService` remains the per-lead state owner and read catalog consumer.
- Any remaining lead-task deadline/urgency/pinning/priority classification or mutation outside `LeadTaskService` → delegate to `LeadTaskService` and keep UI/read models projection-only.
- Production-derived bespoke regression runners → shared scenario suites where the scenario engine can represent the behavior without weakening coverage.

## MOVE — responsibilities that should leave their current layer when touched

- Business/state decisions still inside transport handlers → application/domain services.
- SQL persistence embedded in request/UI code → repositories/infrastructure services.
- Rendering/network logic remaining in `manager/index.php` → focused `manager/assets/` modules or view helpers.
- Manager UI business mutations → manager application services; PHP endpoints remain interface adapters using the shared `ManagerHttp` boundary.
- Admin/routing business logic that grows beyond the current thin pages → focused application services; do not rebuild PHP/CSS/JS monoliths.
- Structured operational decisions embedded only in text logs → typed diagnostic events/snapshots.
- Repository-wide technical inventory that currently requires an ad-hoc run → stable diagnostics artifact consumed by autopilot.
- `CSiteParams::$isAnytourOnline` and any other lead metadata source still read directly from the Bitrix application layer → a small compatibility/config boundary before no-Bitrix cutover. Preserve the current emitted business value; only move ownership.
- Any remaining legacy `\Bitrix\Main\Type\Date*` use reachable from the forward dialogue/search/lead path → native date services with behavior-locked regressions before standalone activation.

## DELETE — only after callers are migrated and production verification is complete

- Dead duplicate parser/state branches superseded by canonical services.
- Legacy manager UI branches after Workspace V2 feature parity and production proof.
- Ad-hoc Manager session/auth/CSRF/JSON/error helpers duplicated by `ManagerHttp` after all callers have migrated and regressions cover the boundary.
- Duplicate browser HTTP/auth/error wrappers after caller search proves `manager-http-client.js` covers their semantics.
- Runtime schema-creation/alteration code after equivalent forward migrations exist.
- Bespoke regression runners when their scenario is represented by the reusable scenario engine without losing coverage.
- Direct local Bitrix lead insertion from the standalone host after bridge cutover is production-proven; keep the legacy receiver only as long as the compatibility phase requires it.

## Audit rule

Run `php tools/architecture_inventory.php` during periodic full-repository audits. Treat its output as triage evidence, not an automatic refactor order: production/lead safety and the roadmap priority order still decide what is changed next. `runtime_ddl` should be empty unless a real request/business runtime schema owner remains; `schema_infrastructure_ddl` may contain only the migration execution boundary. The next control-plane step is to publish this inventory as a stable diagnostics artifact after deploy so technical drift is visible without repeating a manual tree reread.
