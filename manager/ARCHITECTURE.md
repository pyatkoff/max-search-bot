# Manager Workspace architecture

This directory is the owned interface boundary for the manager product. Changes here must not alter Yandex Metrica, lead sending, routing bonuses, manager shift state, or neighboring products.

## Target boundaries

- **Entrypoint / shell** — `index.php` is the single production workspace entrypoint. It renders the workspace and never performs HTTP canonical redirects. Browser-visible `/manager/index.php` is normalized with `history.replaceState`, not a network navigation.
- **Workspace modules** — inbox, conversation, lead card, pipeline, tasks, media, notifications, kanban and mobile behavior own their corresponding `assets/workspace-v2-*` files.
- **Manager HTTP interfaces** — `api.php`, `pipeline-api.php`, media and push endpoints are thin interfaces. Authentication, authorization and validation converge on `lib/ManagerHttp.php` instead of being reimplemented per endpoint.
- **Business rules** — remain outside presentation/interface code. UI and HTTP code may project and invoke manager use-cases but must not become a second owner of routing, technical conversation state, sales-stage semantics or delivery rules.

## Keep / merge / move / delete map

### Keep

- `index.php` — canonical production workspace entrypoint; redirect-free.
- `lib/ManagerHttp.php` — shared Manager HTTP/session/auth/CSRF/conversation-authorization boundary. `start()` owns session bootstrap for binary/non-JSON endpoints; `startJson()` layers JSON headers on the same lifecycle. Keep it business-service free.
- `assets/workspace-v2.js` — shared state/transport/boot/auth-recovery core only; feature rendering stays in modules.
- `assets/workspace-v2-inbox.*` — inbox owner.
- `assets/workspace-v2-conversation.*` — transcript/composer owner.
- `assets/workspace-v2-lead-card.*` — structured lead information owner.
- `assets/workspace-v2-pipeline.*` and `workspace-v2-kanban.*` — sales pipeline UI owner.
- `assets/workspace-v2-media.*` — outbound media UI owner.
- `assets/workspace-v2-notifications.*` — notification UI owner.
- `assets/workspace-v2-mobile.*` — mobile navigation/layout owner only; business behavior stays in feature modules.
- `assets/admin.css` + `assets/admin.js` — admin presentation/interaction owner; `admin.php` remains markup/session shell only.
- `sw.js`, `push-enable.php`, `push-status.php`, `push.php` — keep; push behavior remains in services while endpoints use the shared HTTP boundary.

### Merge / centralize incrementally

- **Completed:** push API/status/enable surfaces, `media-upload.php`, `media-file.php`, `pipeline-api.php` and the main `api.php` use `ManagerHttp` for their applicable session/auth/CSRF/response lifecycle.
- Remaining repeated response/session/auth/CSRF handling in admin/routing actions and other Manager endpoints → `ManagerHttp`, one endpoint family at a time with behavior regressions.
- Duplicated frontend fetch/error/auth behavior in workspace/admin/routing → one small manager HTTP client module.
- Admin/routing visual primitives → shared manager admin CSS, without coupling them to conversation CSS.

### Move / split incrementally

- **Completed:** inline CSS/JS from `admin.php` → `assets/admin.css` + `assets/admin.js`.
- Inline CSS/JS from `routing.php` → `assets/routing.css` + `assets/routing.js`.
- Shell markup in `index.php` → small templates/components only when it reduces real complexity; do not create a second workspace entrypoint.
- Manager-specific endpoint helpers duplicated in top-level PHP files → `manager/lib/` interface helpers; shared business services stay outside `manager/`.

### Delete only after compatibility evidence

- `workspace-v2.php` — **deleted after #252**. Do not recreate it or another workspace alias.
- Network redirect code between `/manager/` and `/manager/index.php` — keep deleted/forbidden; canonicalization must not create requests.
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
2. **In progress:** central request/auth/error interface layer. Push API/status/enable, media upload/preview, Sales Pipeline API and the main Manager API are migrated; continue with narrow slices of the remaining endpoints rather than widening `ManagerHttp` into a business layer.
3. **In progress:** split admin and routing monolith assets. Admin CSS/JS is extracted; routing remains.
4. Keep `workspace-v2.js` small and feature-neutral while auth/session recovery remains shared core behavior.
5. Consolidate remaining endpoint validation and structured errors in narrow slices.
6. Continue Workspace V2 and Sales Pipeline feature work only on top of these stable boundaries.
