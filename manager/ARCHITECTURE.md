# Manager Workspace architecture

This directory is the owned interface boundary for the manager product. Changes here must not alter Yandex Metrica, lead sending, routing bonuses, manager shift state, or neighboring products.

## Target boundaries

- **Entrypoint / shell** — `index.php` is the single production workspace entrypoint. It renders the workspace and never performs HTTP canonical redirects. Browser-visible `/manager/index.php` is normalized with `history.replaceState`, not a network navigation.
- **Workspace modules** — inbox, filters/search, conversation, lead card, pipeline, tasks, stage history, media, notifications, kanban and mobile behavior own their corresponding `assets/workspace-v2-*` files.
- **Manager HTTP interfaces** — `api.php`, `pipeline-api.php`, media and push endpoints are thin interfaces. Authentication, authorization and validation converge on `lib/ManagerHttp.php` instead of being reimplemented per endpoint.
- **Business rules** — remain outside presentation/interface code. UI and HTTP code may project and invoke manager use-cases but must not become a second owner of routing, technical conversation state, sales-stage semantics or delivery rules.

## Keep / merge / move / delete map

### Keep

- `index.php` — canonical production workspace entrypoint; redirect-free.
- `lib/ManagerHttp.php` — shared Manager HTTP/session/auth/CSRF/conversation-authorization boundary. `start()` owns session bootstrap for HTML/binary/non-JSON manager surfaces; `startJson()` layers JSON headers on the same lifecycle. Keep it business-service free.
- `services/ManagerQueueProjectionService.php` — actionable Manager queue/count projection owner. It composes canonical conversation lists with delivery-state eligibility, while preserving notification-unread semantics; HTTP endpoints and `ManagerConversationService` must not rebuild these projections.
- `assets/workspace-v2.js` — shared state/transport/boot/auth-recovery core only; feature rendering stays in modules.
- `assets/workspace-v2-inbox.*` — inbox owner: queue selection, list projection, list/kanban view handoff.
- `assets/workspace-v2-filters.js` — inbox search/filter state, persistence, filter controls and task-filter shortcut orchestration. It may invoke the inbox reload/queue boundary but must not own sales-stage or task business semantics.
- `assets/workspace-v2-conversation.*` — transcript/composer owner.
- `assets/workspace-v2-lead-card.*` — structured lead information/composition owner; delegate feature-specific subviews rather than growing another monolith.
- `assets/workspace-v2-tasks.*` — lead-task presentation and task mutation orchestration owner. It captures the source conversation for create/update/toggle/pin requests and keeps per-lead/task in-flight guards outside rerendered DOM; task persistence/business semantics remain server-owned.
- `assets/workspace-v2-stage-history.js` — sales-stage history presentation owner; stage-history persistence and semantics remain in `SalesPipelineService`.
- `assets/workspace-v2-pipeline.*` and `workspace-v2-kanban.*` — sales pipeline editor/board UI owner. Pipeline code owns sales mutations and editor state, not inbox filters.
- `assets/workspace-v2-media.*` — outbound media UI owner.
- `assets/workspace-v2-notifications.*` — notification UI owner.
- `assets/workspace-v2-mobile.*` — mobile navigation/layout owner only; business behavior stays in feature modules.
- `assets/manager-http-client.js` — small shared same-origin JSON transport owner for admin/routing pages; pages continue to own their CSRF/session state and business-specific error copy.
- `assets/admin.css` + `assets/admin.js` — admin presentation/interaction owner; `admin.php` remains markup shell only and delegates session bootstrap to `ManagerHttp`.
- `assets/routing.css` + `assets/routing.js` — routing-admin presentation/interaction owner; `routing.php` remains markup shell only and delegates session bootstrap to `ManagerHttp`.
- `sw.js`, `push-enable.php`, `push-status.php`, `push.php` — keep; push behavior remains in services while endpoints use the shared HTTP boundary.

### Merge / centralize incrementally

- **Completed:** push API/status/enable surfaces, `media-upload.php`, `media-file.php`, `pipeline-api.php` and the main `api.php` use `ManagerHttp` for their applicable session/auth/CSRF/response lifecycle.
- **Completed for admin/routing shells:** direct `ManagerRequestContext::startSession()` ownership is removed; both HTML shells now enter through `ManagerHttp::start()`.
- **Completed for current endpoint inventory:** all Manager PHP interfaces enter through `ManagerHttp`; authenticated business-write JSON/form endpoints, including push subscription persistence, use its shared CSRF guard. Re-run caller inventory before adding or migrating an endpoint instead of assuming completion is permanent.
- **Completed for admin/routing frontend:** duplicated `fetch` / malformed-response / network-error transport uses `assets/manager-http-client.js`. Do not force Workspace V2 auth-recovery behavior into this small client; converge further only when the contracts genuinely match.
- Admin/routing visual primitives → shared manager admin CSS only when duplication becomes material; do not couple them to conversation CSS.

### Move / split incrementally

- **Completed:** inline CSS/JS from `admin.php` → `assets/admin.css` + `assets/admin.js`.
- **Completed:** inline CSS/JS from `routing.php` → `assets/routing.css` + `assets/routing.js`; keep routing business rules/server authorization outside these assets.
- **Completed:** sales-stage history renderer moved out of `workspace-v2-lead-card.js` → `assets/workspace-v2-stage-history.js`; lead card composes it, while `SalesPipelineService` remains the single business owner of stage history.
- **Completed:** inbox search/filter persistence and task-filter shortcut orchestration moved out of `workspace-v2-pipeline.js` → `assets/workspace-v2-filters.js`; pipeline stays focused on sales mutations while `ManagerLeadInboxService` / task services retain server-side semantics.
- **Completed:** lead-task mutation transport, source-lead capture and in-flight guards moved out of `workspace-v2-lead-card.js` → `assets/workspace-v2-tasks.js`; Lead Card now only composes the task subview while task services remain the server-side business owner.
- **Completed:** actionable waiting filtering and queue-count assembly moved out of `manager/api.php` → `ManagerQueueProjectionService`; `ManagerDeliveryStateService` owns interpreting the already-decorated suspended-recipient state.
- Shell markup in `index.php` → small templates/components only when it reduces real complexity; do not create a second workspace entrypoint.
- Manager-specific endpoint helpers duplicated in top-level PHP files → `manager/lib/` interface helpers; shared business services stay outside `manager/`.

### Delete only after compatibility evidence

- `workspace-v2.php` — **deleted after #252**. Do not recreate it or another workspace alias.
- Network redirect code between `/manager/` and `/manager/index.php` — keep deleted/forbidden; canonicalization must not create requests.
- `ManagerConversationService::queueCounts()` — **deleted after #627 projection migration** once repository caller inventory confirmed no compatibility caller remained. Keep queue-count business rules in `ManagerQueueProjectionService` only.
- Legacy/duplicate endpoint helpers only after the owning endpoint has moved to the shared boundary and required behavior tests are green.
- Dead CSS selectors / JS hooks only after visual QA and DOM contract checks prove no use.

## Invariants

1. One visible product: Manager Workspace at `/manager/`.
2. No HTTP redirect loops inside the manager application.
3. Technical states (`ai`, `waiting_manager`, `manager`, `closed`) remain system-owned and separate from sales stages.
4. Original tourist transcript is preserved; structured trip/contact/source/handoff data lives outside it.
5. Manager composer must remain reachable and usable on supported desktop/mobile viewports.
6. Authorization and CSRF are server-side requirements; UI hiding is not authorization.
7. Sales Pipeline mutations require assigned-manager/admin authorization at the shared interface boundary and remain independent of technical conversation state.
8. Production verification requires real HTTP entrypoint smoke in addition to static regression and screenshot QA.

## Refactor sequence

1. **Done:** entrypoint stability, single `index.php`, cache-busted assets and real production HTTP smoke.
2. **Done for the current endpoint inventory:** push API/status/enable, media upload/preview, Sales Pipeline API, main Manager API, and admin/routing shells use the shared request/auth boundary; push subscription writes now use the same CSRF contract. Keep `ManagerHttp` business-service free and inventory new endpoints as they appear.
3. **Done:** split admin and routing monolith assets. Admin and routing CSS/JS are extracted; PHP files remain markup shells.
4. **Done for admin/routing frontend transport:** one small JSON client owns duplicated fetch/network/invalid-response behavior while page modules retain role gates, CSRF state and domain-specific errors.
5. **In progress for Workspace frontend ownership:** `workspace-v2.js` remains feature-neutral; stage history, inbox filters and task mutation orchestration are split into focused modules. Continue extracting only where ownership is clear and behavior regressions can lock the boundary.
6. Consolidate remaining endpoint validation and structured errors in narrow slices.
7. Continue Workspace V2 and Sales Pipeline feature work only on top of these stable boundaries.
