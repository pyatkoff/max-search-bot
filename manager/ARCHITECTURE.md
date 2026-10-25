# Manager Workspace architecture

This directory is the owned interface boundary for the manager product. Changes here must not alter Yandex Metrica, lead sending, routing bonuses, manager shift state, or neighboring products.

## Target boundaries

- **AI draft feedback** — `ManagerAiDraftFeedbackService` stores bounded private
  proposals and insertion requests. The canonical composer carries an optional
  scoped identity; only the existing outbound recorder attaches it to a real
  text-message row. See [feedback provenance](../docs/MANAGER_AI_DRAFT_FEEDBACK.md)
  for the optional `validate` observation, expiry and interpretation limits.

- **Entrypoint / shell** — `index.php` owns the canonical production workspace shell. The owner-authorized `/manager2/index.php` is a thin opt-in presentation include of this same shell; it owns no API or business state. It renders the workspace and never performs HTTP canonical redirects. Browser-visible `/manager/index.php` is normalized with `history.replaceState`, not a network navigation.
- **Workspace modules** — inbox, filters/search, conversation, lead card, pipeline, tasks, stage history, media, notifications, kanban and mobile behavior own their corresponding `assets/workspace-v2-*` files.
- **Manager HTTP interfaces** — `api.php`, `pipeline-api.php`, media and push endpoints are thin interfaces. Authentication, authorization and validation converge on `lib/ManagerHttp.php` instead of being reimplemented per endpoint.
- **Business rules** — remain outside presentation/interface code. UI and HTTP code may project and invoke manager use-cases but must not become a second owner of routing, technical conversation state, sales-stage semantics or delivery rules.

## Keep / merge / move / delete map

### Keep

- `index.php` — canonical production workspace entrypoint; redirect-free.
- `lib/ManagerHttp.php` — shared Manager HTTP/session/auth/CSRF/conversation-authorization boundary. `start()` owns session bootstrap for HTML/binary/non-JSON manager surfaces; `startJson()` layers JSON headers on the same lifecycle. Conversation visibility/edit ownership delegates to `ManagerConversationAccessPolicy` through request/application services; keep the HTTP helper business-service free.
- `services/ManagerQueueProjectionService.php` — actionable Manager queue/count projection owner. It composes canonical conversation lists with delivery-state eligibility, while preserving notification-unread semantics; HTTP endpoints and `ManagerConversationService` must not rebuild these projections.
- `assets/workspace-v2.js` — shared state/transport/boot/auth-recovery core only; feature rendering stays in modules.
- Loading keeps notification setup/health outside the Inbox and recovered-conversation prerequisite chain. Catalog/filter reconciliation still precedes the first list. Late startup continuations are scoped to the current authentication generation.
- `assets/workspace-v2-inbox.*` — inbox owner: queue selection, list projection, list/kanban view handoff.
- Automatic Inbox/transcript refresh is coalesced while a list or earlier automatic cycle is pending; explicit queue/filter navigation remains available and the latest list wins.
- `assets/workspace-v2-filters.js` — inbox search/filter state, persistence, filter controls and task-filter shortcut orchestration. It may invoke the inbox reload/queue boundary but must not own sales-stage or task business semantics.
- `assets/workspace-v2-conversation.*` — transcript/composer owner.
- New audio/video controls initialize after the complete reconciled message batch
  is connected, before viewport restoration. Retained native players keep their
  existing controls, source, playback and DOM identity. This is synchronous
  rendering order; history paging, media preload and reply ownership are unchanged.
- `assets/workspace-v2-history.*` — bounded transcript-page presentation for the current authenticated conversation. It reuses the canonical detail transport, merges explicit older pages, exposes incomplete intervals and rejects late responses after cursor/context changes. Conversation keeps unchanged message/media DOM connected and owns reply drafts; history is an in-memory view, not another transcript or read-state store. Disjoint latest/retained windows trigger canonical gap recovery: one older request at a time until the retained frontier is bridged, at most10 new pages per cycle. Error or the cycle limit requires explicit continuation; hidden views revoke the recovery generation so a stalled request cannot block return or expire a newer context. Manual paging coalesces with repair; a newer latest cursor and post-send reopening retain their existing context fences.
- `ManagerConversationService::detail()` selects the latest 200 messages or an explicit older cursor page, with full-conversation human-reply existence independent of that page. All detail callers and older-page reads are side-effect free. The visible active conversation explicitly acknowledges shown customer IDs through `ack_read`; `ManagerReadService` rechecks the active account, canonical visibility and captured assignment/project/source context under the conversation lock. Only a shown prefix of actual unread rows advances the existing monotonic marker. Global ID gaps do not imply missing conversation rows. A newer window cannot acknowledge an unavailable unread prefix.
- `workspace-v2-jump.js` owns transcript anchoring across composer/media reflow. Alignment and restoration skip unchanged scroll positions: assigning the same `scrollTop` interrupts native WebKit wheel animation, even when no correction is needed.
- A fresh conversation switch may explicitly request `open_unread=true` on canonical detail. Eligible personal reads return `history.initial_unread_id`; owner/supervisor previews do not seek another manager's unread. If the target lacks preceding context in the latest200, the same service selects up to20 preceding and100 following actual rows plus the latest80 (at most200 total). Explicit `gap_frontier`/`gap_before_id` seed the existing bounded gap repair, without another history transport/store. Jump positions the target after the mobile conversation becomes visible, preserving context above it; refresh, same-dialogue reopening and explicit latest do not reposition the reader.
- Authorization-only attachment, message-edit and outgoing-send reads use `visibleConversation()` without loading or marking the transcript. Opening a cached attachment, looking up edit candidates, editing an earlier manager message or attempting a send must leave newly arrived customer messages unread until the active visible conversation acknowledges their display.
- `assets/workspace-v2-jump.js` — transcript viewport owner: follows latest messages through composer/viewport/media layout changes, preserves a visible message anchor while reading history, and exposes the explicit jump control. It never renders or fetches messages; Conversation establishes viewport intent after composing the reply controls.
- `assets/workspace-v2-message-navigation.*` — quote-to-original navigation and
  return anchors. The protected `message_target` read resolves the quote from its
  stored source row in the same conversation and channel; the browser cannot
  choose a provider identifier or a foreign conversation. An already rendered
  original uses Jump; an older original opens a bounded context view through the
  canonical safe message renderer. That view leaves the main transcript, media
  DOM, single-gap history cursor, drafts and read acknowledgements unchanged.
  Account, auth, navigation and access loss revoke pending lookups and clear the
  secondary view. Unavailable or ambiguous originals remain explicit.
- `ManagerClientEventService`, `client-events-api.php` and
  `assets/workspace-v2-client-events.*` — lazy read-only client event view over
  existing conversation events, stage/name history, notes and tasks. It owns no
  event store or mutations. Each source has a bounded keyset read and an initial
  high-water ID; paging never relies on OFFSET or loads every record. Notes and
  tasks explicitly show their current contents, not a fabricated edit history.
  Ordinary conversation visibility protects note/name data; extended supervision
  alone does not grant it. Fresh access is checked again before returning data;
  employee names are projected only to admins. Opening the view does not load a
  second transcript, acknowledge messages or add a request to ordinary card load.
- `assets/manager-time.js` — shared UTC/offset → Moscow display and Moscow deadline input boundary. Device timezone must not change Manager timestamps or entered deadlines; date-only values and server-owned business-day/urgency rules remain separate.
- `assets/workspace-v2-lead-card.*` — structured lead information/composition owner; delegate feature-specific subviews rather than growing another monolith.
- `assets/workspace-v2-tasks.*` — lead-task presentation and task mutation orchestration owner. It captures the source conversation for create/update/toggle/pin requests and keeps per-lead/task in-flight guards outside rerendered DOM; task persistence/business semantics remain server-owned.
- `assets/workspace-v2-stage-history.js` — sales-stage history presentation owner; stage-history persistence and semantics remain in `SalesPipelineService`.
- `assets/workspace-v2-pipeline.*` and `workspace-v2-kanban.*` — sales pipeline editor/board UI owner. Pipeline code owns sales mutations and editor state, not inbox filters.
- `assets/workspace-v2-media.*` — outbound media UI owner.
- After an uncertain media response, an explicit removal affects only the local
  draft row, never delivery or the account/conversation/file retry fence. The UI
  warns that the file and caption may already have been sent. Removal stays
  blocked while sending; further text/files require their ordinary explicit send.
- `assets/workspace-v2-notifications.*` — notification UI and browser subscription activation/repair owner, shared by the workspace and authenticated `push-enable.php` shell. Native jobs keep ownership until actual settlement, independently of bounded UI waits; origin Web Locks serialize supported tabs. Without Web Locks, the pending-job guard protects one page; cross-tab native serialization is not guaranteed. A click captures the installation capability/version before permission/worker/native waits. A newly observed revocation requires another explicit click. Account-scoped operation records in local storage survive lost responses/reload and reconcile through read-only receipt lookup; they never trigger blind replay. Connection-list reads are lazy and cannot hold the Inbox/composer. The service worker displays notifications and handles navigation/sound coordination, but never persists or recreates a subscription, including old `SYNC_PUSH` messages.
- `assets/workspace-v2-mobile.*` — mobile navigation/layout owner only; business behavior stays in feature modules.
- `assets/manager-http-client.js` — small shared same-origin JSON transport owner for admin/routing pages; pages continue to own their CSRF/session state and business-specific error copy.
- `assets/admin.css` + `assets/admin.js` — admin presentation/interaction owner; `admin.php` remains the canonical markup shell and delegates the legacy session bootstrap to `ManagerHttp`. `/manager2/admin.php` includes that shell as identity-free presentation with the canonical `/manager/` base and one scoped stylesheet; it owns no API, session, identity, CRUD or workspace modules.
- Administrative sections, employee filters and bounded audit search reuse the current `admin_snapshot`; switching sections or filtering never adds a request. Account, shift and push reachability are separate read-only facts. Editors keep their existing canonical form IDs, CSRF payloads and snapshot/error/session guards; `admin-edit-safety.js` owns opening, draft replacement confirmation, cancellation and keyboard focus. A confirmed create binds any newer retained draft to the returned record ID before another save.
- `assets/routing.css` + `assets/routing.js` — routing-admin presentation/interaction owner; `routing.php` remains markup shell only and delegates session bootstrap to `ManagerHttp`.
- `sw.js`, `push-enable.php`, `push-status.php`, `push.php` — keep; push behavior remains in services while endpoints use the shared HTTP boundary. `push-status.php` is read-only; protected POST may project safe current-installation ID/version/enabled state beside the two-field endpoint proof. `push.php` owns protected own-list/operation reads and receipt-backed explicit intent, subscribe and revoke commands. A versioned command captures the authenticated manager; public connection IDs/receipts expose no browser capability or native key material. Unversioned subscription saves are refused, including legacy workers with a new provider endpoint.

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
- Shell markup remains in `index.php`. `/manager2/workspace-chrome.php` adds only opt-in navigation/header/dialog chrome; do not duplicate the workspace shell or API endpoints.
- Manager-specific endpoint helpers duplicated in top-level PHP files → `manager/lib/` interface helpers; shared business services stay outside `manager/`.

### Delete only after compatibility evidence

- `workspace-v2.php` — **deleted after #252**. Do not recreate it or another workspace alias.
- Network redirect code between `/manager/` and `/manager/index.php` — keep deleted/forbidden; canonicalization must not create requests.
- `ManagerConversationService::queueCounts()` — **deleted after #627 projection migration** once repository caller inventory confirmed no compatibility caller remained. Keep queue-count business rules in `ManagerQueueProjectionService` only.
- Legacy/duplicate endpoint helpers only after the owning endpoint has moved to the shared boundary and required behavior tests are green.
- Dead CSS selectors / JS hooks only after visual QA and DOM contract checks prove no use.

## Invariants

1. One Manager product with the canonical `/manager/` surface and the owner-authorized `/manager2/` presentation. Both use the same protected APIs and domain modules.
2. No HTTP redirect loops inside the manager application.
3. Technical states (`ai`, `waiting_manager`, `manager`, `closed`) remain system-owned and separate from sales stages.
4. Original tourist transcript is preserved; structured trip/contact/source/handoff data lives outside it.
5. Manager composer must remain reachable and usable on supported desktop/mobile viewports.
6. Authorization and CSRF are server-side requirements; UI hiding is not authorization.
7. Sales Pipeline mutations require assigned-manager/admin authorization at the shared interface boundary and remain independent of technical conversation state.
8. Production verification requires real HTTP entrypoint smoke in addition to static regression and screenshot QA.
9. Bounded list visibility uses a fresh batch of manager/project/source/group permissions through `ManagerConversationAccessPolicy`; it never retains permissions across mutations or replaces singleton write authorization. Existing production visibility probes report numeric `load_ms` for their real-database reads without adding probes or exposing transcript data.

The read-only `pipeline-api.php` detail releases its PHP session lock after
authentication, CSRF and exact conversation visibility/edit checks, before loading
secondary card data. A slow card therefore cannot serialize a same-account
transcript request behind those loaders. Card writes retain their existing session
lock; no shared auth, session-cookie or mutation owner is changed. The actual HTTP
concurrency regression uses isolated sessions and synthetic secondary loaders;
its timings establish this causal lock boundary, not production response times.

Read-only Inbox list and queue-count projections also release the PHP session
lock after authentication, CSRF and captured request identity. Their canonical
loaders keep fresh data-access checks and perform no session writes. The same
actual HTTP regression holds each projection open while a same-session request
completes; write actions retain their existing serialization. This is a session
concurrency boundary, not evidence that database queries or production p95 are
faster. Inbox viewport restoration, like Jump, skips an unchanged scrollTop
assignment so retained list renders do not unnecessarily interrupt native scroll.

## Refactor sequence

1. **Done:** entrypoint stability, single `index.php`, cache-busted assets and real production HTTP smoke.
2. **Done for the current endpoint inventory:** push API/status/enable, media upload/preview, Sales Pipeline API, main Manager API, and admin/routing shells use the shared request/auth boundary; push subscription writes now use the same CSRF contract. Keep `ManagerHttp` business-service free and inventory new endpoints as they appear.
3. **Done:** split admin and routing monolith assets. Admin and routing CSS/JS are extracted; PHP files remain markup shells.
4. **Done for admin/routing frontend transport:** one small JSON client owns duplicated fetch/network/invalid-response behavior while page modules retain role gates, CSRF state and domain-specific errors.
5. **Done for the current Workspace frontend inventory:** `workspace-v2.js` remains feature-neutral; stage history, inbox filters and task mutation orchestration are split into focused modules. The required client-transport inventory keeps feature endpoints out of the core and records why multipart, push timeout/recovery, standalone and service-worker callers do not share the ordinary JSON helper.
6. Consolidate remaining endpoint validation and structured errors in narrow slices.
7. Continue Workspace V2 and Sales Pipeline feature work only on top of these stable boundaries.

## Manager2 presentation (owner request, 2026-10-13)

- `manager2/index.php` selects the agreed modern presentation and includes the canonical shell. Its identity-free HTML must not start the `/manager/`-scoped session; canonical APIs retain all session/auth/CSRF and cookie issuance. `<base href="/manager/">` keeps API, multipart, media, worker scope and admin URLs canonical. `/manager2/index.php` normalization stays browser-only.
- `manager2/assets/manager2.css` is body-scoped; the existing `/manager/` styling is preserved.
- `manager2/assets/manager2.js` moves canonical controls, groups secondary sales/task/actions into native disclosures and projects the already-available canonical trip budget. It performs no transport, database, routing, identity, reply or notification mutation.
- Lead Card, Tasks and Conversation expose optional presentation hooks after rendering. Existing modules retain all event binding, role checks, mutation guards, session/attachment/draft handling and delivery truth.
- The canonical Mobile owner selects a 1100px breakpoint only for the Manager2 body; the original surface retains 900px.
- Real PHP shell rendering, anonymous/canonical login, shared API/worker paths, task and reply mutations, role restrictions, notification dialog and responsive Chromium/WebKit flows are covered by required/visual QA. Public artifacts contain synthetic clients only.

## Convenient response library and transcript tools

- `services/ManagerPersonalWorkspaceService.php` owns private tabs, labels/memberships, pins and start/readability settings. `manager/personal-workspace-api.php` uses the canonical ManagerHttp auth/CSRF/visibility boundary. Account state and operation receipts use forward-only migration 034; no runtime DDL or customer/business-state rewriting. Every membership/pin command checks fresh conversation access, and selected lists reuse the canonical bounded visibility/queue/decorator projection. Deleted label references remain non-broadening.
- `workspace-v2-personal-workspace.*` owns these private controls in both presentations. It reads preferences in the background, loads one selected tab only, rejects late account/revision responses and reconciles uncertain commands by receipt without resend. Reply drafts/media stay owned by Conversation/Media. Personal selections use the ordinary list view, independent of sales stages or board semantics.
- `services/ManagerInternalNoteService.php` owns employee-only notes for an exact visible conversation; `manager/internal-notes-api.php` uses canonical ManagerHttp auth/CSRF and fresh visibility without transcript hydration/read marking. Note text never enters customer messages or a channel transport. Visible staff can add a note; editing is author/admin-only with optimistic versions and atomic account/conversation operation receipts. Forward-only migration035 holds notes/receipts outside business and original transcript tables.
- `workspace-v2-internal-notes.*` lazily opens the explicitly staff-only note editor/list. It owns note drafts, not message drafts; read/write/UNKNOWN/focus state is account/session/conversation scoped, and it never blocks Inbox, reopens the transcript/card or automatically repeats a write. Closed notes create no polling or data reads.

- `services/ManagerQuickReplyService.php` is the canonical account-scoped personal/shared reply library owner; `manager/quick-replies-api.php` uses `ManagerHttp` auth/CSRF and no message transport. Personal replies and favorites belong to the authenticated account; only admins edit shared replies. Version checks prevent concurrent overwrites and transactional operation receipts reconcile uncertain writes without repeating commands. Schema changes use forward-only migration 029.
- `workspace-v2-quick-replies.*` lazily loads the selected library/filter only. It resolves an explicit preview and inserts through Conversation's context-pinned composer boundary; it never sends, writes another draft store for messages or blocks Inbox startup.
- `workspace-v2-conversation-search.*` searches only the currently loaded messages, explicitly labels that scope, and preserves original text and message IDs. Global archive search is a separate unimplemented phase.
- Inbox retains a bounded authenticated/filter-scoped successful selection for the explicit next-waiting action. A pending/failed load or changed account/filter invalidates that selection; opening still checks canonical conversation access and dirty-task navigation.
- Media retains selected files per account/conversation in memory during navigation. Files cannot survive reload and are discarded on account change; no new media archive or draft persistence service is introduced.

## Manager client working names

`ManagerCustomerNameService` owns shared manager-only client labels by exact customer ID. Its protected API accepts a visible conversation context and delegates visibility/write policy to canonical Manager owners. Labels live separately from `customers.display_name`; source identity, channel IDs, attribution, original transcript and lead payload never change. The projected display name is shared by list/detail/board/card, while `original_display_name` remains available and both names are searched inside the existing bounded list. This does not widen archive-search coverage.

The forward-only migration030 retains a monotonically increasing name version even after restoring the original. Customer/conversation locks, optimistic versions and atomic history/receipt/audit preserve assignment and recover uncertain writes. The name dialog is lazy, account/session/conversation scoped, and updates visible name labels plus the selected Inbox without reopening the transcript or rerendering unfinished task/outcome forms. Quick reply `{client_name}` uses the original source name rather than the manager's working label.

## Optional customer portraits

`services/ManagerCustomerAvatarService.php` resolves a portrait only after fresh canonical account/project/conversation visibility checks. The binary `manager/customer-avatar.php` interface uses ManagerHttp, private no-store headers and validated bounded bytes; provider URLs and credentials never enter UI. The session lock is released before provider work. A private, fixed-slot cache outside the application stores positive/negative results with bounded lifetime; no schema or business writes are introduced.

`workspace-v2-customer-avatar.*` owns optional background display in Inbox/header/card, two concurrent visible-only requests, bounded account/generation-scoped blobs, reset/revocation and initials fallback. It does not delay list/detail/notification startup. Telegram uses documented profile photos; MAX private-dialogue portraits use the bounded, identity-verified adapter described in [MAX customer portraits](../docs/MAX_CUSTOMER_AVATARS.md); website clients keep initials without photo requests. Node reuse detaches the old record even when the next channel is unsupported. Name changes refresh initials without rebuilding the composer or client forms.

## Outgoing multi-file selection

Media owns a bounded per-account/per-conversation in-memory selection (maximum ten files), image preview and explicit clipboard/file-choice input. Conversation retains the only message draft store and submit owner. Files still use the canonical single-file upload endpoint, sequentially as individual messages; there is no new album transport, schema or background send. The current MAX/Telegram adapters are supported; website attachments remain explicitly unavailable.

Confirmed uploads are removed from the captured queue. Caption receipts compare the exact text; a new caption after partial success is sent, while a confirmed unchanged caption is not repeated. Partial delivery refreshes history once and retains remaining files; an unresolved upload prevents retries, removal/reselection within the active tab. The endpoint has no durable media operation receipt: reload/device loss cannot automatically reconcile an unknown result, and the client never replays uploads after reload. New files added during an attempt remain pending for another explicit action.

## Confirmed handoff cancellation context

Manager detail projects an optional cancellation notice only after its existing fresh conversation visibility check. `ManagerHandoffCancellationViewService` reads at most32 indexed events for the selected AI conversation, uses the newest authoritative lifecycle transition and exposes only confirmed customer `ai_resumed` with `handoff_cancelled` reason plus time. Missing/unparseable/out-of-window context is unknown; literal customer text/callback names cannot establish this event. No new query runs on Inbox or manager-owned detail.

The focused presentation module renders outside the original transcript with ManagerTime Moscow formatting; Conversation owns loading, current-context, account/access reset and clearing. MAX/Telegram handoff copy describes the existing cancellation consequence explicitly, without changing callbacks, transport, ownership, source or queue policy.

## Project supervision and readonly analytics

Owner-authorized project/source capabilities, explicit audited grants, separated
AI viewer/assigned owner and complete server cohorts are owned by
`ManagerSupervisorAccessService` / `ManagerDialogueAnalyticsService`. The
identity-free `/manager2/analytics.php` uses the canonical protected endpoint;
legacy mutation visibility and ordinary sending remain separate from extended
reads. See [supervision and analytics](../docs/MANAGER_SUPERVISION_ANALYTICS.md)
for exact cohort/event/current-state definitions, freshness and grant boundaries.


### Ordinary text-send receipts and UNKNOWN recovery

The existing Conversation draft row/session envelope stores an opaque text-send
operation ID before the request, with its original draft version and AI provenance.
`ManagerOutboundService::sendAttempt` wraps the unchanged canonical sender/adapters:
`ManagerSendAttemptService` commits a unique account/operation reservation before
transport, without holding conversation locks over the network. Migration059 adds
`manager_send_attempts`, a payload-free receipt journal, not a queue, transcript,
second sender or automatic retry. Legacy callers and attachment sending retain
their existing contracts; no provider or model is called by opening the workspace.

After a lost/invalid response, “Проверить отправку” calls protected `send_receipt`
only. Confirmed receipt consumes only the original unchanged draft version; newer
text and selected files remain. Processing/UNKNOWN never permits another send. A
missing row is not proof of non-delivery: explicit `cancel_send_attempt` can reserve
a cancelled tombstone only before execution reserves that ID. A delayed original
then cannot reach transport. If execution won the race, cancellation cannot reverse
it. A subsequent new send always requires a separate explicit user action. Receipt
reads and cancellation freshly enforce active account, canonical project/source
visibility, actual assignment/status and the captured recipient/channel context.
Supervisor read/AI access grants no ordinary-send or receipt authority.

The pending fence survives same-account reload, draft clearing, the ordinary draft
TTL and later storage quota failures; first send is refused if its intent cannot
be saved. Existing account switch/access-loss privacy fences remain. No real
customer sends or read/assignment changes are needed for acceptance: actual JS and
services use isolated synthetic fixtures, with native MySQL race proof in the
existing personal-workspace workflow. Browser fixtures are not natural/physical
manager acceptance.

The reservation context is passed into the canonical sender, which checks the
fresh authorized row after any advisory-lock wait and uses that exact row for
the adapter destination. Active account, actual assignment and recipient/project/
source/channel mismatches prove zero dispatch and persist a rejected receipt.
Only that zero-dispatch rejection may reconcile across a changed context for the
same freshly authorized actual owner; confirmed/UNKNOWN receipts stay context
fenced. The UI refreshes authorized detail before unlocking a new explicit send,
retaining the intent fence if the refresh fails. No database lock spans transport.
