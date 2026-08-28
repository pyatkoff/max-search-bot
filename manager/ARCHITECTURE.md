# Manager Workspace architecture

This directory is the owned interface boundary for the manager product. Changes here must not alter Yandex Metrica, lead sending, routing bonuses, manager shift state, or neighboring products.

## Target boundaries

- **Entrypoint / shell** — `index.php` is the production entrypoint. It renders the workspace and never performs HTTP canonical redirects. Browser-visible aliases are normalized without a network navigation.
- **Workspace modules** — inbox, conversation, lead card, pipeline, tasks, media, notifications, kanban and mobile behavior own their corresponding `assets/workspace-v2-*` files.
- **Manager HTTP interfaces** — `api.php`, `pipeline-api.php`, media and push endpoints are thin interfaces. Authentication, authorization and validation must converge on shared request helpers instead of being reimplemented per endpoint.
- **Business rules** — remain outside presentation code. UI code may project and invoke manager use-cases but must not become a second owner of routing, technical conversation state, sales-stage semantics or delivery rules.

## Keep / merge / move / delete map

### Keep

- `index.php` — canonical production entrypoint; redirect-free.
- `workspace-v2.php` — current workspace shell while it is progressively split; must not accumulate module logic.
- `assets/workspace-v2-inbox.*` — inbox owner.
- `assets/workspace-v2-conversation.*` — transcript/composer owner.
- `assets/workspace-v2-lead-card.*` — structured lead information owner.
- `assets/workspace-v2-pipeline.*` and `workspace-v2-kanban.*` — sales pipeline UI owner.
- `assets/workspace-v2-media.*` — outbound media UI owner.
- `assets/workspace-v2-notifications.*` — notification UI owner.
- `assets/workspace-v2-mobile.*` — mobile navigation/layout owner only; business behavior stays in feature modules.
- `sw.js`, `push-enable.php`, `push-status.php`, `push.php` — keep until push lifecycle is centralized and behavior is covered.

### Merge / centralize incrementally

- Repeated JSON response, session, auth, CSRF and HTTP-error handling across `api.php`, `pipeline-api.php`, media and push endpoints → one manager HTTP bootstrap/request layer.
- Duplicated frontend fetch/error/auth behavior in workspace/admin/routing → one small manager HTTP client module.
- Admin/routing visual primitives → shared manager admin CSS, without coupling them to conversation CSS.

### Move / split incrementally

- Inline CSS/JS from `admin.php` → `assets/admin.css` + `assets/admin.js`.
- Inline CSS/JS from `routing.php` → `assets/routing.css` + `assets/routing.js`.
- Shell-only markup from `workspace-v2.php` → components/templates once entrypoint stability is proven. Do not move all files in one PR.
- Manager-specific endpoint helpers currently duplicated in top-level PHP files → `manager/lib/` or equivalent interface helpers; shared business services stay outside `manager/`.

### Delete only after compatibility evidence

- Network redirect code between `/manager/`, `/manager/index.php` and `workspace-v2.php` — delete/avoid; canonicalization must not create requests.
- Legacy/duplicate entry aliases only after production logs and links confirm they are unused. Until then aliases may render the same implementation but must not become separate versions.
- Dead CSS selectors / JS hooks only after visual QA and DOM contract checks prove no use.

## Invariants

1. One visible product: Manager Workspace at `/manager/`.
2. No HTTP redirect loops inside the manager application.
3. Technical states (`ai`, `waiting_manager`, `manager`, `closed`) remain system-owned and separate from sales stages.
4. Original tourist transcript is preserved; structured trip/contact/source/handoff data lives outside it.
5. Manager composer must remain reachable and usable on supported desktop/mobile viewports.
6. Authorization and CSRF are server-side requirements; UI hiding is not authorization.
7. Production verification requires real HTTP entrypoint smoke in addition to static regression and screenshot QA.

## Refactor sequence

1. Entrypoint stability and real HTTP smoke.
2. Central request/auth/error interface layer.
3. Split admin and routing monolith assets.
4. Reduce `workspace-v2.php` to shell/components.
5. Consolidate endpoint validation and structured errors.
6. Continue feature work only on top of these stable boundaries.
