# AnyTour Manager2

Owner-authorized production presentation at `/manager2/`, based on the agreed concept 03 from the manager design prototype.

## Runtime ownership

`index.php` includes the real `manager/index.php` shell. The canonical auth/session, CSRF, protected APIs and all domain modules are retained. The Manager2 HTML shell never starts a session: the canonical cookie is deliberately scoped to `/manager/`, so starting it from `/manager2/` would replace an existing authenticated session. All authentication, cookie issuance and CSRF stay in the canonical protected APIs; cookie scope and security flags remain unchanged. A same-origin `/manager/` base keeps fetch, media, service-worker scope at their existing endpoints. The administration link explicitly opens `/manager2/admin.php`. No additional API, mock customer data, authentication implementation, schema or business-state store exists in this directory.

The new chrome and body-scoped assets provide the forest navigation rail, compact queue/search tools, central conversation and a client card opened on demand. Primary tasks remain visible; secondary tasks, task creation, sales outcome/tags, reassignment and board controls use native keyboard/touch disclosures. If a dirty task prevents a client change, Manager2 reveals and focuses that task instead of leaving the warning behind a hidden mobile pane. Disclosure state is bounded in memory; task/outcome/reply drafts stay owned by the original modules.

## Administration

`admin.php` includes the canonical `manager/admin.php` markup with an identity-free Manager2 presentation. It reuses `admin.js`, `admin-edit-safety.js`, the shared JSON client and Moscow-time helper, without loading Inbox or Conversation code. The canonical `/manager/` base keeps all protected reads and saves on the existing API; the new HTML never issues or replaces a session cookie. The previous administrative URL remains compatible.

Managers, project access, projects, existing priority rules and the last 50 safe audit summaries retain their existing forms, validation, drafts and account/generation guards. Local section switches, search and combined filters reuse the single snapshot. Account, working shift and notification reachability remain separate facts. Return links lead to `/manager2/`; routing and sales-catalog links keep their existing destinations.

The Manager2-only stylesheet provides a compact forest navigation rail, a restrained header, clear sections and responsive list/editor layout. Desktop and mobile fixtures cover 390/430/768/1440px, short screens, keyboard focus and preservation of newer form input. Saves in the acceptance suite use synthetic data only.

## Feature preservation

| Function | Canonical owner |
|---|---|
| New / Mine / All / Closed queues, search and all existing filters | Inbox / Filters |
| Live original transcript, linked replies/forwards, arrival refresh and scroll anchoring | Conversation / Jump |
| Own-message sending, draft/session recovery, emoji, first-five-minute editing | Conversation / Message Edit |
| Personal quick-answer buttons, account-owned selection/order, editable library and known name/trip substitution | Quick Replies / canonical Manager Quick Reply service |
| Personal tabs, labels, multiple memberships, pins and reading/start settings | Personal Workspace / canonical Manager Personal Workspace service |
| Images, video, audio and files; upload/context/delivery failures | Media / Conversation |
| Multiple tasks, deadlines in Moscow time, pin, completion/reopen, postpone, dirty drafts | Tasks / Task Draft / Task Presets |
| Stages/history, tags, outcomes, loss reason, sale amount/date, desktop board | Pipeline / Outcome / Kanban / Stage History |
| Assignment, claim/release/close/reopen and role/access restrictions | Canonical Manager APIs / Conversation / Lead Card |
| Shift control, push setup/health/repair and open-workspace sound | Shift / Notifications / Sound |
| Mobile navigation, browser Back/Forward, composer and client-card focus | Mobile with presentation focus helpers |

The visible budget is the existing canonical `trip.budget`, including its actual `total` or `per_person` basis. Missing budget stays explicitly unknown. Technical dialogue status remains independent of business stage/outcome. Sent messages are not labelled as read by the customer.

The composer quick-answer row shows only replies selected by the current account, in its saved order. The trailing “Настроить ответы” action opens the library; “Моя панель” has keyboard/touch reorder controls, and “Добавить на панель” / “Убрать с панели” changes personal selection. A shortcut inserts resolved text into the current draft without sending; unknown parameters open the editable preview. Selection/order use the canonical favorites and account revision with transactional receipts. A toolbar read loads in the background once per authenticated lifecycle and never waits ahead of Inbox. Reload or another device loads the saved selection; account changes clear the previous panel and late responses cannot restore it. An empty or failed panel does not substitute fixed standard replies.

The template editor offers seven reusable trip placeholders: `{trip_city}`, `{trip_country}`, `{trip_date}`, `{trip_nights}`, `{trip_adults}`, `{trip_children}` and `{trip_budget}`. Resolution reads only the current conversation's already-loaded protected `detail.lead.trip`; it makes no extra HTTP/SQL request and never parses an old recorded summary. Dates require a complete valid calendar date, zero children is known only when explicitly stored, and budget requires its actual currency plus `total`/`per_person` basis. Missing or malformed values stay unresolved and block insertion; no year, currency or units are inferred. A late/refreshed card never rewrites an edited preview. “Подставить известное” fills only remaining known placeholders explicitly, preserving manual text and selection. Account/auth/conversation/reopen fences remain required, channel-original client names are used instead of internal aliases, and insertion preserves the canonical draft and selected files; sending remains a separate explicit action.

Saved-reply editing and insertion preview reuse the composer's emoji choices and preserve the current text selection. Each “Моя панель” row has “Убрать с панели”, which removes only that account's shortcut and keeps the library reply and edited preview. “Удалить ответ” uses the existing role-gated library deletion with confirmation; deleting a shared reply remains administrator-only.

## Verification

- The real loopback HTTP/cookie regression verifies existing login survives Manager2 navigation/reload, the alternate HTML never replaces the scoped Manager cookie, and anonymous API access stays unauthorized.
- `bash tests/run_required_checks.sh` includes actual rendering of both entrypoints and all existing state/ownership/transport regressions.
- `tests/visual/manager2-workspace.spec.js` renders the real PHP shell, loads production assets and runs Chromium/WebKit at 390, 430, 768 and 1440px, plus reduced viewport height. All API/push/customer fixtures are synthetic and local.
- `tools/manager_http_smoke.sh` checks both Manager2 entrypoint forms and published modern asset markers alongside the canonical Manager auth/API checks.
- Publication follows the existing exact-main deploy and all migration/TLS/webhook/diagnostic gates.

The prototype itself uses demonstration data. Its experimental behavior is not used as a production transport or authorization source. Real physical iPhone keyboard use and physical Firefox provider/OS notification receipt remain separate from browser-fixture verification.

A real native setup failure from an explicit connection attempt sends one detached, bounded technical report through the canonical authenticated push endpoint. It records only fixed call/outcome categories, timings and browser/permission/worker state in the existing `manager_push/setup_failed` diagnostic events. No console/configuration work is required from a manager. Background-only attempts, unresolved native work, successful recovery and stale accounts are not reported. Telemetry has a per-session rate cap and cannot affect setup, command receipts, shifts or notifications. Firefox can hide its original provider error behind `AbortError`; this report supplies context, not that privileged cause or proof of a repair. Raw text, endpoints, key material and full user-agent strings are excluded. The canonical capture exposes only a projected `manager.push_setup` summary of client-reported failures in its last 50 sampled push events, with no account identity; that sample is neither a failure rate nor an active-device count. Raw recent push events remain excluded from public diagnostics.

## Convenient response workflow

Personal workspace controls are independent of sales/CRM. “Мои вкладки” manages named selections, order, labels and start/readability settings; the client action manages private labels and pins. A client can belong to several labels. Only the selected tab is fetched through the canonical visibility/list projection, with the existing bounded 200-row scope shown explicitly. Deleting a referenced label keeps its tab empty until explicitly edited; it never silently expands the selection.

Preferences use account-scoped canonical storage (forward-only migration 034), version checks and transactional receipts. Another device loads the saved settings; stale or old-account responses cannot restore another account's controls. The background preferences read never delays the Inbox; if it arrives late, the saved start tab is offered explicitly without interrupting reading. Unknown writes remain blocked for receipt reconciliation, never automatic resend. These controls do not send customer messages or create a second reply draft/media store.
Internal notes are a separate employee-only list/editor marked “Видно только сотрудникам”. Opening them is explicit; loading cannot hold the Inbox or composer. Existing conversation access controls apply on every read/write; staff can add context, while only its author or an administrator can edit a note. Note saves use versions and transactional receipts; uncertain results are read back without repeating the command. Original client messages, reply drafts and files are not changed or sent by this feature. Storage is canonical and introduced only through forward-only migration035.

The canonical reply library supports personal/shared answers, categories, search, favorites and explicit editable insertion. Shared editing is role-gated on the server; uncertain library saves reconcile their original operation receipt. Library loading is lazy and cannot delay the Inbox or transcript.

Transcript display and composer authorization complete independently of the secondary client card. Search explicitly covers loaded messages; the next-waiting control covers the current successfully loaded selection, with unchanged filters and access checks. Message drafts retain their existing account-scoped storage; selected files are preserved in memory between authorized conversations and cannot be restored after a page reload.

## Recognizable clients

Working names are common manager-only labels visible to colleagues with access to that exact client. The original channel name remains separate and can be restored. Editing uses the canonical assigned-manager/admin policy, versions, protected change history and retained operation receipts. Coinciding names never merge clients or channels. The name dialog loads independently; successful changes refresh only name labels and the selected list/board, preserving message/file/task drafts. Search by original and working name retains the existing bounded-list scope.

## Customer portraits

Optional Telegram and MAX portraits appear in the list, conversation header and client card. Missing or unavailable photos and website clients keep initials. MAX verifies a stored customer message and its real private chat before loading one thumbnail through an exact-origin, bounded transport. Photos load after the main screen, only for visible identities; navigation, access loss and account recovery invalidate stale portrait work. See `docs/MAX_CUSTOMER_AVATARS.md` for provider, cache and verification boundaries.

The canonical protected binary endpoint owns fresh authorization and a bounded private cache. Offline provider/SQLite/loopback regressions and actual PHP-shell browser scenarios cover cache permissions, delayed photos, unsupported channels, account/client switching, drafts and responsive layout. Those checks do not confirm natural Telegram profile-photo availability or physical-device behavior; no real customer messages or operational changes are required for acceptance.

## Screenshot and multi-file replies

An explicit paste into the composer or native file choice prepares up to ten files with image previews and individual removal. Sending remains an explicit action. Files are sent separately through the existing MAX/Telegram upload endpoint; the text accompanies the first file. Progress, partial confirmation and unknown outcome have distinct states; confirmed files are not repeated and a newly edited caption is preserved/sent correctly. Unsupported website attachment sending retains the selection and text with an explanation.

Selection remains in memory only and cannot survive reload. Unknown results cannot be durably reconciled by the current media endpoint; no automatic replay is introduced. Actual PHP-shell browser fixtures cover desktop/mobile and reduced height, clipboard preview, partial retry, account/client switching and caption guards. Fixture success is not proof of natural customer receipt.

Received audio and video keep their native controls with `preload="none"`, set before their source. Opening or paging a transcript does not need to download every recording; explicit playback loads the chosen file through its existing protected URL. Browser preload is a hint, and the isolated Chromium/WebKit tests verify the actual request boundary rather than assuming every browser follows it. Playing nodes survive ordinary refresh and older-page insertion; native decode/network failures retain the existing open-file fallback. No new media transport, cache, storage or playback owner is introduced.

## Why a client continued with the bot

An explicit continuation button explains that it cancels waiting for a manager. A confirmed cancellation event appears with Moscow time in the client conversation; a literal message naming a callback never produces this notice. Later lifecycle changes or missing evidence suppress an outdated cause. The original transcript and technical/business states retain their canonical owners.

Before/after actual PHP-shell screenshots wait for the mobile transition to finish; transient animation frames are not treated as layout defects. Focused acceptance covers day/night MAX/Telegram copy, bounded authorized context, cross-client drafts, refresh, account/access clearing,390/430/768/1440 and420pxheight. Production and natural customer receipt remain separate.

## Messenger layout

The work area starts at the top of the viewport. The branded workplace strip is removed; the account button in the navigation opens shift, browser sound/push health, administrator links and the previous cabinet. A persistent colored account indicator still reports notification problems. Inbox heading tools reveal search and filters, select personal pins, and open the existing personal workspace editor. Saved personal tabs remain visible; their conditions, rename and deletion stay in that editor. A collapsed nonempty search retains an active indicator and continues to filter until explicitly cleared.

The same notification panel lazily lists the current account's browser connections, safe browser/OS names, current-browser mark and last recorded browser contact in Moscow time. Saved subscriptions are not an online or OS-display receipt. “Отключить уведомления” revokes one account-owned installation; sound in open pages and the shared shift remain independent. A shared native endpoint saved by another manager is preserved, and revoke never invokes native unsubscribe. In-flight provider work may still arrive.

Migration039 adopts legacy rows without changing their delivery history. Explicit setup obtains a durable browser intent before native creation/repair; later endpoint replacements use that same installation version. Old unversioned pages/workers cannot restore a revoked connection, including with a new endpoint. Re-enable requires a new explicit action in the originating browser. Lost command responses show an unconfirmed result and use read-only receipt reconciliation, including after reload, instead of repeating setup/revoke. Account changes discard visible old-account rows and ignore late responses. Existing physical Firefox AbortError and natural OS delivery remain separately unconfirmed.

The maintained service/HTTP/native-job/actual-shell regressions cover independent profiles, shared endpoints, revoke→fan-out→lateE2/provider results→explicit renewed intent, auth/CSRF/foreign IDs, lost responses, account changes and responsive conversation preservation. Only synthetic local data and native stubs are used; production device/settings writes and real sends are excluded. Rollback is a forward repair or presentation-only revert retaining the installed intent/revocation and receipt protections; do not restore the old unversioned writer or unfiltered fan-out after migration039.

Chat rows show the portrait/initials, name, a two-line last-message preview, activity, unread count and channel. The compact activity stays beside the name: today uses HH:mm, yesterday uses “Вчера”, earlier dates use DD.MM (DD.MM.YY outside the current year). Dates are compared in Moscow time through the canonical ManagerTime parser; the tooltip and accessible label retain the full Moscow date and time. The preview uses the full available text width, and unread counts stay on the metadata row. Green ownership marks mean the current manager, amber means free; accessible labels and tooltips explain the colors. Administrators additionally see the assigned manager. The list uses the same authorized rows and queue/filter projection. Travel/sales/task-summary rows are kept in the client card; waiting-for-reply signals stay compact in the list. The plan section retains task grouping.

Canonical Inbox refresh reconciles the fresh authorized projection by conversation ID. Unchanged rows keep their native opener, portrait, personal markers and text selection; changed rows alone are replaced, with same-account/customer/channel portraits retained. Reordering preserves backward selection, focus and the first visible conversation's offset (including a changed anchor); a removed focused row falls back to an available adjacent opener. Metadata lives only in a WeakMap keyed by live DOM nodes, not another conversation store. Account/auth/filter changes invalidate reuse; local unread removal invalidates the affected projection, and relative activity labels roll over on the Moscow day. Personal snapshot adoption also leaves unchanged marker nodes intact. API calls, refresh coalescing, access fences and composer/history owners are unchanged.

The SQL elapsed-wait counter advances even for already-answered conversations. Its DOM fingerprint ignores it when no waiting badge is rendered; otherwise it uses the existing displayed minute and urgency precision. Fresh canonical data is not altered, and a visible minute/urgency change still updates the row. Browser regressions advance all200 answered counters between actual list loads, not just replay identical JSON.

The client card is closed by default on desktop. The client name is a native button, and the explicit client button performs the same action. A top-right × button labelled “Закрыть карточку клиента” returns to the conversation without changing its status. Close restores focus to the opener; mobile keeps canonical Back/Forward history. The conversation menu uses “Закрыть обращение” for the existing technical close action. Dirty-task navigation guards can reveal the card on desktop as well as mobile. Working-name history/editing, existing tasks, status/actions and source/service details retain their original canonical modules. Opening the card or heading tools does not request another API read or replace reply/file/task state.

`tests/visual/manager2-messenger-layout.spec.js` verifies the real shell at390/430/768/1440px and420px height, including tools, optional card, keyboard focus, roles, personal tabs and preserved composer data. Local JavaScript checks are separate from the required PHP/rendered-browser CI gates.

Personal tab conditions support checkbox selection of several queues, channels, sources and personal labels. Values within one field are alternatives; fields intersect. “All open” and “Closed” can be combined. Optional empty fields impose no restriction; a queue is required. Legacy single-value tabs are read and edited without losing conditions. An unavailable selected label remains visible for correction and cannot silently turn its condition into an unrestricted one.

## Iterating a related UI package

Develop related layout/navigation changes together on an isolated `m2-preview/**` branch before opening the final PR. `Manager2 preview checks` runs eight existing real-shell scenarios in each of Chromium and WebKit, at390/430/768/1440px including short-height, focus, drafts, selected files and navigation. API replies and sends are synthetic. Its screenshots are CI artifacts; this workflow does not create a hosted preview URL or a second application. Add the task's existing focused checks locally as needed; smoke alone does not cover every affected behavior or prove real-device push delivery.

When the package is stable, open its PR. The full `Workspace V2 visual QA` workflow still runs on every qualifying PR update, including draft PRs. A preview-smoke result cannot satisfy the full `screenshots` gate. Any shared or unknown dependency requires the full release check before merge; do not use a green preview as publication authority. Keep confirmed urgent defects separate from a larger UI package. A relevant edit after acceptance requires evidence for the new version.

The full workflow runs the existing 22 Chromium and 21 WebKit main specs, including the compact Inbox/favorites suite in both engines, plus daily avatar-cache checks in both engines, 26 Chromium CLI captures plus 8 layout captures, and the separate Firefox history/notification checks. Each engine's main specs are divided into two explicit whole-spec partitions on isolated runners, still with one worker per runner. Pinned npm/browser download caches include OS/architecture, Node22, Playwright1.55.0 and engine; system dependencies are installed even on a cache hit. Only superseded read-only runs for the same PR or preview branch are cancelled; manual runs and production workflows are outside those groups.

The stable `screenshots` aggregate requires every browser job to succeed and verifies both engine artifacts, complete receipts and exact tested/head SHA. Failure, cancellation, skip or missing evidence fails the gate. The canonical artifact remains `workspace-v2-visual-<tested-sha>` with `chromium/` and `webkit/` subdirectories and `visual-evidence.json`; equal filenames from different engines cannot overwrite one another. Receipts identify run/attempt and actual capture inventory. An already successful engine from the same run and exact version can be reused when only failed jobs are rerun. These are execution changes, with no product assertions removed and no change to the normal main/deploy verification.

### Full-suite execution partitions

Partitioning changes execution placement, not test selection or product behavior.
Every current main spec belongs to exactly one partition per engine; cache runs
once per engine, while Chromium partition zero alone owns the original layout and
CLI capture inventory. Fixtures retain their own local server, profiles, output
directories and `workers=1`. No test is split across runners or removed.

Part artifacts retain separate namespaces and receipts for their actual browser
executor, assigned specs, exact source/head/run/attempt, executed case identities
and PNG hashes. The existing `browser-tests (chromium)` and `browser-tests (webkit)`
checks assemble their respective proofs only after all required children succeed;
the stable `screenshots` check still requires both engines and both Firefox jobs.
Assembly is not described as browser execution. Missing, failed, skipped,
cancelled, duplicated or retargeted parts, overlapping or missing cases, changed
source and conflicting or altered screenshots cannot become successful evidence.

The final canonical artifact preserves the original per-part proof as well as the
complete engine case union. Reuse remains limited to the independently audited
spec-only scope below; a partitioned full donor must itself pass the complete
partition proof. Product/shared/unknown changes still run FULL. Installations,
runner queues, assembly and publication remain part of elapsed release time;
partitioning is not a twelve-minute SLA or a product/browser-latency claim.

### Conservative spec-only follow-up

Schema-2 receipts collect exact Playwright case identities and actual outcomes,
including the separately executed daily-cache spec: 23 Chromium / 22 WebKit
specs in total, not only the 22 / 21 specs in the longer commands. The gate
compares fresh execution with the current collected case inventory, checks each
PNG's digest and validates the actual clean source tree. Firefox checks stay
fresh and required. No test, assertion, browser or production gate is removed.

The first affected-selection scope is intentionally limited to modifications of
the two independently audited leaf specs `manager2-inbox-compact.spec.js` and
`manager2-messenger-layout.spec.js`. A same-PR, same-repository, ancestor **full
successful** run of the current receipt schema is required. Product/CSS, shared
fixture/helper, configuration, workflow, inventory, added/deleted/renamed files,
unknown changes or missing proof select FULL. Thus this first implementation
does not promise faster ordinary UI changes or a bound on the entire release.

All tracked files outside those two leaves must match the donor. A fixed audited
test-harness digest additionally prevents a later helper from importing a leaf
and silently making it shared, even after another successful full run. Changes
to that harness need an explicit independence re-audit and versioned pin update.
Only the two CI selftests and required-runner manifest are exempt from this
harness pin; they are still covered by the full shared-source comparison.
Source-root, history-baseline and browser/report overrides are rejected.

For eligible changes both engines rerun the changed specs, the existing full
Manager2 workspace checks, daily-cache checks and current responsive captures;
Firefox continues unchanged. Unchanged cases may come only from the validated
full donor. Current and donor reports/screenshots remain separate, and the
receipt records their original run, attempt, source and immutable artifact
identity instead of relabelling old screenshots as new captures. Their union
must exactly match the current case list: no missing, duplicate, skipped,
failed, flaky or expected-failure cases are accepted. Composite donors are not
reused, and a small full-only index lets later iterations find the original
successful full proof rather than alternating full/affected runs.

Donor ZIPs are downloaded by immutable artifact ID, checked against the API's
SHA-256 digest and extracted with bounded path/symlink/duplicate protection.
Unavailable or unprovable donors during planning fall back to FULL; invalid
execution evidence fails the release gate. A changed browser runner identity
also selects FULL before execution. Initial adoption changes the workflow and
therefore necessarily runs FULL. No benchmark dispatch or production operation
is needed, and no measured speedup is claimed until a genuine eligible edit
uses the new path.

## Ordinary conversation actions and uncertain outcomes

Take/release/close/reopen use structured results from the existing canonical
conversation service; its bool callers remain compatible. Existing eligibility,
ownership, off-shift take guard, admin exception, locks and provider/event order
are unchanged. Known refusals have stable reasons; missing and inaccessible
conversations remain indistinguishable. No extra query explains a refusal.

A transport error, timeout, malformed/legacy response or server error is not a
confirmed rejection: the action may already have happened. The inline notice
keeps text/files editable, blocks another action/send and offers only an explicit
current-state check. That protected visibility-only read never marks messages
read or replays a mutation. It updates current technical state under the existing
access/ownership clear policy; it is explicitly not a receipt of the old action.
Both requests have a 20-second abort deadline, with no automatic retry. Late
navigation/account results cannot paint or unlock a newer operation. A confirmed
write stays confirmed if a later transcript/card/list refresh fails. Secondary
reads do not retain the write lock. Canonical lifecycle buttons carry a semantic
hook so busy/recovery guards survive presentation moving them into its menu.

Acceptance uses isolated service/actual HTTP fixtures and a separate empty
loopback MySQL database for concurrent-take/idempotency checks; never live client
actions. Browser coverage uses the real PHP shell with synthetic API data and
390/430/768/1440 widths, short height, native abort, focus, Back and retained draft/
file/history. Physical-device behavior and natural customer outcomes are separate
evidence, not inferred from these fixtures.


### Reply field and expanded editor

The main reply field starts at one visible line for empty or short text, with its existing actions alongside. Wrapping or a newline gives the text the full composer width and puts those same actions below it; selected files and reply notices also use that wider layout. Long drafts can grow to twelve lines when the available conversation height permits. The composer normally stays near a third of the conversation area; short screens retain a usable transcript and may show fewer lines. Focus alone does not enlarge the field. Clearing, confirmed send and collapse size it from the remaining canonical draft.

Manager2's focused `reply-editor.js` owns sizing. A hidden, non-editable text measure avoids resetting the live textarea to zero height, preserving native selection and canonical transcript reading/following intent. Short transitions reserve vertical room before widening the text; shrinking first makes room for the existing actions. Reduced motion, short viewports and the reduced visible area of a mobile keyboard disable them so reply and overlay geometry settle immediately. Initial/context restoration and the expanded dialog do not animate. The original cabinet retains its current sizing path; there is no second draft, textarea or submit owner.

“Развернуть” presents the same canonical form and `#replyText` inside a labelled native dialog, showing the current recipient. It never copies the draft or File queue. Collapse, Escape and browser Back retain native selection/direction, text, attached files and focus; the existing mobile overlay owner removes the same history entry. A context/auth/access change closes the presentation before another recipient can use it. The current Conversation, Media and submit owners retain all authorization, IME, in-flight, partial and unknown-outcome behavior. Layout performs no additional HTTP, SQL or persistence.

Actual-PHP-shell synthetic checks cover the four baseline widths, short420 layout, text/File/selection identity, account recovery and loss of access, resize, canonical insertion/emoji/IME/send and partial unknown media delivery. Emulated visible-area checks do not establish physical iPhone keyboard or natural manager use.

### Notification navigation

A notification click selects one same-origin workspace at `/manager/` or `/manager2/`, including `index.php`; setup, admin and other service pages cannot consume it. A disappeared or unfocusable workspace falls through to the next usable one, then one canonical fallback. An existing workspace receives a command without navigation or reload.

The page binds that command independently of push registration/readiness. Canonical startup consumes the fallback `#conversation=ID` only after authentication and Inbox readiness, once, with account/open-generation guards. An anonymous login may continue that explicit target; switching a known account discards its old intent. Current-conversation clicks retain loaded history, reading position and selected files through the existing Conversation owner. No click sends, takes or changes a subscription. Synthetic browser verification is separate from natural OS clicks and the existing physical Firefox subscription error.

Expiry during startup keeps that explicit URL target available for the same account's next login, including another failed Inbox load. A known account change discards the old target before its first protected read, so a second expiry cannot carry it into the new account. Clicks while the canonical sign-in form is already open update only the target; typed credentials, native selection and an in-flight submit remain intact. The latest click and a newer explicit conversation choice retain their existing navigation priority. The URL is still the only pending intent; detail failures keep canonical recovery and never replay a send.

An accepted conversation choice withdraws an older startup URL before starting its protected detail read. That choice keeps priority across session expiry, including a failed newer detail; the old client cannot return as a login fallback. Refused navigation (expired authentication, invalid target or an unsaved task) leaves the pending URL available. Withdrawal changes only a valid notification fragment through the core URL owner and preserves the current history entry.

## Project supervision and readonly analytics

Owner-authorized project/source capabilities, explicit audited grants, separated
AI viewer/assigned owner and complete server cohorts are owned by
`ManagerSupervisorAccessService` / `ManagerDialogueAnalyticsService`. The
identity-free `/manager2/analytics.php` uses the canonical protected endpoint;
legacy mutation visibility and ordinary sending remain separate from extended
reads. See [supervision and analytics](../docs/MANAGER_SUPERVISION_ANALYTICS.md)
for exact cohort/event/current-state definitions, freshness and grant boundaries.


## Explicit personal read acknowledgement

Transcript detail and history requests no longer mark messages read. The current,
visible, focused conversation records only customer rows actually intersecting its
viewport and not covered by another surface. `ack_read` uses the existing authenticated
CSRF transport and a bounded batch of at most200 IDs. Canonical access and the captured
assignment/project/source/state are rechecked under the conversation lock. The existing
personal marker advances through actual unread rows only, stopping before any row
not shown; globally nonconsecutive IDs are valid. Inbox rereads authoritative counts
after confirmed progress rather than optimistically hiding every unread badge.

Hidden tabs/mobile Inbox, failed or superseded reads, account changes and foreign
owner/supervisor views acknowledge nothing. Failed acknowledgements preserve unread,
draft/File and the viewport. History with an unavailable unread prefix stays unread
and offers the existing explicit older-page control. Disjoint latest and retained
windows now recover their missing interval automatically through the same bounded
history transport, stopping once the retained frontier is bridged. Recovery keeps
the reading anchor, message/media DOM, draft and selected files. It pauses while
hidden, resumes on return or network reconnection, and rejects old context responses.
A page failure or the ten-page cycle limit requires explicit continuation through
the existing history control; it never drains untouched older history automatically.
Fresh conversation opening positions the first personal unread customer message
with preceding context. When it lies outside the recent window, the explicit
initial read returns bounded context and the actual latest tail (at most200 rows
combined); the existing gap owner fills their declared interval. No whole-history
drain is needed before the initial message is shown. Refresh and same-dialogue
reopening preserve the reader's position; explicit latest still opens the tail.
Foreign owner/supervisor previews keep latest-window semantics and never seek or
acknowledge another manager's unread. Initial loading failure and obsolete results
retain the existing selected dialogue, draft and file guards.
No second transcript/read store, migration or provider delivery/read receipt is added.


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
