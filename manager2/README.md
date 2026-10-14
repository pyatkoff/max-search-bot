# AnyTour Manager2

Owner-authorized production presentation at `/manager2/`, based on the agreed concept 03 from the manager design prototype.

## Runtime ownership

`index.php` includes the real `manager/index.php` shell. The canonical auth/session, CSRF, protected APIs and all domain modules are retained. The Manager2 HTML shell never starts a session: the canonical cookie is deliberately scoped to `/manager/`, so starting it from `/manager2/` would replace an existing authenticated session. All authentication, cookie issuance and CSRF stay in the canonical protected APIs; cookie scope and security flags remain unchanged. A same-origin `/manager/` base keeps fetch, media, service-worker scope and administrative links at their existing endpoints. No additional API, mock customer data, authentication implementation, schema or business-state store exists in this directory.

The new chrome and body-scoped assets provide the forest navigation rail, account/shift/notification header, queue/search, central conversation and compact client card. Primary tasks remain visible; secondary tasks, task creation, sales outcome/tags, reassignment and board controls use native keyboard/touch disclosures. If a dirty task prevents a client change, Manager2 reveals and focuses that task instead of leaving the warning behind a hidden mobile pane. Disclosure state is bounded in memory; task/outcome/reply drafts stay owned by the original modules.

## Feature preservation

| Function | Canonical owner |
|---|---|
| New / Mine / All / Closed queues, search and all existing filters | Inbox / Filters |
| Live original transcript, linked replies/forwards, arrival refresh and scroll anchoring | Conversation / Jump |
| Own-message sending, draft/session recovery, emoji, first-five-minute editing | Conversation / Message Edit |
| Personal quick-answer buttons, account-owned selection/order, editable library and name substitution | Quick Replies / canonical Manager Quick Reply service |
| Images, video, audio and files; upload/context/delivery failures | Media / Conversation |
| Multiple tasks, deadlines in Moscow time, pin, completion/reopen, postpone, dirty drafts | Tasks / Task Draft / Task Presets |
| Stages/history, tags, outcomes, loss reason, sale amount/date, desktop board | Pipeline / Outcome / Kanban / Stage History |
| Assignment, claim/release/close/reopen and role/access restrictions | Canonical Manager APIs / Conversation / Lead Card |
| Shift control, push setup/health/repair and open-workspace sound | Shift / Notifications / Sound |
| Mobile navigation, browser Back/Forward, composer and client-card focus | Mobile with presentation focus helpers |

The visible budget is the existing canonical `trip.budget`, including its actual `total` or `per_person` basis. Missing budget stays explicitly unknown. Technical dialogue status remains independent of business stage/outcome. Sent messages are not labelled as read by the customer.

The composer quick-answer row shows only replies selected by the current account, in its saved order. The trailing “Настроить ответы” action opens the library; “Моя панель” has keyboard/touch reorder controls, and “Добавить на панель” / “Убрать с панели” changes personal selection. A shortcut inserts resolved text into the current draft without sending; an unknown name opens the editable preview. Selection/order use the canonical favorites and account revision with transactional receipts. A toolbar read loads in the background once per authenticated lifecycle and never waits ahead of Inbox. Reload or another device loads the saved selection; account changes clear the previous panel and late responses cannot restore it. An empty or failed panel does not substitute fixed standard replies.

## Verification

- The real loopback HTTP/cookie regression verifies existing login survives Manager2 navigation/reload, the alternate HTML never replaces the scoped Manager cookie, and anonymous API access stays unauthorized.
- `bash tests/run_required_checks.sh` includes actual rendering of both entrypoints and all existing state/ownership/transport regressions.
- `tests/visual/manager2-workspace.spec.js` renders the real PHP shell, loads production assets and runs Chromium/WebKit at 390, 430, 768 and 1440px, plus reduced viewport height. All API/push/customer fixtures are synthetic and local.
- `tools/manager_http_smoke.sh` checks both Manager2 entrypoint forms and published modern asset markers alongside the canonical Manager auth/API checks.
- Publication follows the existing exact-main deploy and all migration/TLS/webhook/diagnostic gates.

The prototype itself uses demonstration data. Its experimental behavior is not used as a production transport or authorization source. Real physical iPhone keyboard use and physical Firefox provider/OS notification receipt remain separate from browser-fixture verification.

## Convenient response workflow

The canonical reply library supports personal/shared answers, categories, search, favorites and explicit editable insertion. Shared editing is role-gated on the server; uncertain library saves reconcile their original operation receipt. Library loading is lazy and cannot delay the Inbox or transcript. Current default answers remain available beside the library.

Transcript display and composer authorization complete independently of the secondary client card. Search explicitly covers loaded messages; the next-waiting control covers the current successfully loaded selection, with unchanged filters and access checks. Message drafts retain their existing account-scoped storage; selected files are preserved in memory between authorized conversations and cannot be restored after a page reload.
