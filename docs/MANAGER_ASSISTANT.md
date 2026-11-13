# Manager2 assistant

The Manager2 rail opens one assistant. Ordinary employees see their active assigned clients; the existing active administrator role opens the workspace owner's team view across all currently accessible projects, including unassigned waiting clients. The conversation toolbar opens the same client recommendation. Both paths read the same prepared proposal; opening the assistant never generates a reply, reads media/selection links, changes unread state, or sends a customer message.

The feed prioritizes the full freshly authorized own-active scope before paging (20 per page), independent of the Inbox's capped candidate set. Canonical unanswered-manager-response facts come first, then arrived existing open tasks assigned to the actual owner (or unassigned), then other clients; conversation ID is the stable tie-breaker. Callback/start-only entries are not new human questions. Future/completed/foreign tasks do not become arrived work. Sorting and filters describe the loaded part of the feed. An absent or expired proposal means **no fresh analysis**, not that all work is complete. The existing preparation collector is not an analysis of every client. A `no_contact` result says that no new outgoing message is needed; it does not establish that the next action belongs to the customer. It assigns no automatic due date or reminder.

An active MAX delivery suspension removes automatic reply/SLA urgency from the
Inbox and Assistant. The client remains available to its authorized viewers with
a neutral explanation that replying resumes after new customer activity. This
does not close or reassign the conversation, mark messages read, or delete/change
human-created tasks; an explicit task retains its own deadline and controls.
The canonical delivery owner supplies the same latest-failure, later-inbound
and MAX system-restart evidence to SQL ordering and the detail/send guard.
Suspension participates in Assistant continuation and detail freshness checks,
so an obsolete reply recommendation cannot survive a delivery-state change.

Returning from the assistant to the already-open conversation retains explicitly
loaded older history through the canonical History/Conversation option. Existing
message/media nodes, reading position and reply/File remain under those owners;
opening another client still resets history and rechecks its access normally.
Navigation dismisses the originating menu instead of reopening it over the
conversation. Explicit Close/Escape still restores that menu and its opener focus.
Returning to the same mobile conversation adds no duplicate browser-history
entry; Back still reaches the Inbox once. Opening another client or returning
from the Inbox keeps the canonical navigation entry.

Prepared proposals retain the canonical one-hour TTL and all content, instructions, knowledge, ownership and source/project visibility fences. The workspace owner also reads the actual assigned manager's current recommendation and decision. Foreign recommendation decisions remain owned by that employee; their completion controls are not shown to the viewer. The team view labels each client's project and responsible manager. Employee personal feeds retain their original visibility and task filters. Opening a different client rechecks the current proposal. An open detail refreshes every 20 seconds, pausing while a deadline is being edited; explicit refresh updates the loaded client list. Identical background reads retain the actual controls, expanded basis, text selection and scroll position. Changed or expired reasoning still disappears. No background scan runs while the assistant is closed.

`Выполнено`, `Неактуально` and `Вернуть в работу` change only private assistant decision metadata in migration048. Decisions are bound to the precise proposal and context, so a new analysis does not inherit an old dismissal. Compare-and-set state protects conflicting actions; an unknown response is reconciled by a read, never by automatically repeating a write.

Task completion and deadline changes delegate to the existing Pipeline/LeadTask service. Scheduling a recommendation opens the current client's canonical task editor. Existing open tasks are shown first; no second reminder delivery system or automatic task creation exists. An unfinished task/reply/file remains under its existing draft owner.

The assigned manager may explicitly plan internal work from a current
`no_contact` recommendation, including an already promised calculation. This
does not expose a reply/send action or mark the recommendation completed. An
existing open task keeps its own title and open/due status even when the AI says
not to send, or its separate recommendation decision is marked done. The action
filter and ranking stay unchanged: `no_contact` alone is not evidence of work.

Planning reopens the canonical conversation and reads its full current task
card, instead of relying on the older, filtered assistant task list. A failed or
degraded task/pipeline read never proves that there are no tasks. An existing
open task opens that card; a new editor requires current edit rights and an open
sales outcome. Before copying a recommendation check into an empty editor, the
existing assistant detail is read again and must have the same unexpired
revision. A changed or missing recommendation opens an empty editor without
copying old advice; existing manual title/deadline values remain untouched.
For `no_contact` with no check, the title stays empty rather than becoming
"Сейчас не писать". The manager chooses any deadline and explicitly saves.

Account, conversation/open generation, current transcript/detail and assistant
navigation fences reject late planning reads. The Assistant enables the canonical
Conversation `protectTaskDraft` option: after each open/card read, a dirty create
or existing-task edit blocks replacement of the detail or card DOM before it
can erase the draft. This also guards a late initial card response after a newer
card is already visible. Other callers keep their existing refresh behavior.
A task draft typed while a read was pending stays with the canonical draft owner.
Planning never saves, sends
or automatically repeats a write. Showing current existing tasks is a UI guard,
not server-side deduplication of promises. Saved promise identity, trip binding
and fulfillment evidence have separate explicit owners; the plan reads their
confirmed facts through the contract below.

The read endpoint is `manager/assistant-api.php`, using ManagerHttp authentication and CSRF. UI lives in `workspace-v2-assistant.js` and its stylesheet, loaded only by Manager2. The legacy workspace retains its existing assets. The service exposes at most50 open tasks per client and reports truncation; the full task card remains canonical.

Verification covers own-manager authorization, revoked sources, expiry and edits, more than200 clients, revision-bound decision conflicts, read-only behavior, browser account/navigation races, and the actual Manager2 shell at390/430/768/1440px. CI browser evidence and natural manager use are separate acceptance facts; a passing fixture is not production-use evidence.


## Operational facts and stable continuation

The message-without-recorded-reply fact and arrived task are independent of AI.
The panel asks the manager to review the conversation, never assumes every inbound
requires a send. Fresh canonical `no_contact` suppresses the contact prompt while
tasks and confirmed promises remain visible; expiry or new context removes old AI
reasoning while operational work remains visible.
Foreign tasks stay in the canonical customer card, outside this own-work projection.

Two full-scope metadata-only statements bind each page to the same authorized
operational ordering (including a final recheck after selected-page AI validation).
No transcript, task bodies, prepared contexts or model calls are loaded for the
remaining clients. The versioned cursor binds actor/project/visible identities,
source/channel and response/due buckets, not mutable message text. Same-priority
traffic does not starve continuation. Changed access/assignment/priority rejects
that cursor with `list_changed`; the open panel discards old pages and reads the
first page once. It does not append across cohorts or repeat a mutation. Account
and request epochs still discard late responses. An unfinished inline deadline
blocks list refresh/continuation until explicitly saved or cancelled, preserving
that input and the canonical conversation's reply/File/history/reading position.
These are live read pages, not a frozen historical export or full AI coverage.

The final whole-feed visibility/active-account check also replaces repeated
per-item trailing singleton checks for a listing only; selected-client detail
retains its independent recheck. A concurrent revoke/reassignment while task or
AI data is read returns no page. No read permission is retained between calls.

## Workspace owner (23 October 2026)

The existing `admin` role is the technical owner capability; there is no named-login
exception, role elevation, new store or automatic project grant. Its team feed
intersects fresh active project membership before pagination and binds the role,
project set, actual assignment and state into continuation receipts. Role or project
revocation discards foreign rows and saved continuation. Existing admin task editing
continues through canonical task commands; another employee's AI feedback and
recommendation decision are never impersonated.

Only metadata is scanned for the whole team. The selected page hydrates labels,
existing tasks and current separately prepared reasoning, using the real assigned
manager's snapshot and project. Unassigned waiting preparation keeps owner ID zero
and never takes a client. No model is called merely by opening the team panel.

## Confirmed promise facts in the existing plan

The selected client's existing task rows can show a saved promise, its original
source and deadline, and the state of the explicitly associated selection. One
`task_id` is still one row. `LeadTaskService` owns the title, due date, status and
pin. Promise metadata never replaces them, creates another task or changes task
ordering. An AI candidate appears here only after the existing explicit binding
has been saved. Facts do not depend on a current recommendation, `should_draft`,
`no_contact` or the viewer's authority to mark another manager's recommendation.

`ManagerAssistantService` first filters and slices the canonical task list to
`MAX_TASKS=50`. Only detail enriches those exact IDs through one bounded
`ManagerPromiseSelectionService::forTasks()` read. Listing reports
`promise_coverage=not_loaded` and does no promise/profile/delivery hydration;
detail reports `checked`. `tasks_truncated` remains the coverage signal for tasks
outside the returned slice. The full canonical card remains available.

Each returned task has an optional `promise` projection with `availability`,
`reason`, `binding`, `stale_reasons` and `selection`. Availability distinguishes
`available`, confirmed absence (`none`), unavailable evidence (`unavailable`) and
an inapplicable project (`not_applicable`). An absent migration or failed read is
never represented as absence of a promise. Ordinary tasks remain visible when
the optional facts store cannot be read.

The original promise deadline keeps its saved precision. A `date` is a Moscow
calendar date without an invented hour; an `instant` is formatted in Moscow;
null means no deadline was recorded. A manually changed task deadline remains
canonical and any different original commitment is shown separately.

For an open task with a current confirmed promise, the same row can say
«Срок обещания прошёл — проверьте выполнение.» A date-only promise includes the
whole saved Moscow calendar day; the signal starts on the following day. An
instant is elapsed strictly after that moment, including its seconds and offset.
The original promise deadline is independent of the manual task date and urgency:
postponing a task, receiving another customer message or cancelling automatic
completion does not itself erase the saved commitment. The task's canonical
title, date, status, pin, ordering and controls remain unchanged.

This is a display hint using the existing device clock, `ManagerTime` and detail
render/refresh, not a persisted SLA or proof that a result was not delivered.
The existing refresh still waits for an unfinished deadline editor. No extra
timer, request, metric, notification or write is introduced. Unchecked facts,
missing/invalid deadlines, changed or unavailable source/trip/profile/scope,
unknown reason codes, unavailable selection/proof, historical completion after
manual reopen and a fully confirmed exact composition suppress the warning.
The saved original deadline and existing factual warnings remain visible.
Partial `delivery_unknown`, `delivery_unverified` or rejected delivery with
available progress retains its existing instruction to inspect the result;
the elapsed deadline never authorizes a resend or completes the task. A current
calculation promise or a selection promise without an attached composition can
also display this reminder; its manual task controls remain the next action.

Selection facts keep the stored intent and observed freshness separate. An
`armed` association does not prove delivery. Partial progress requires the exact
publication's native receipt, delivery event and outbound transcript for each
confirmed card. Shared offer fences from another publication do not count.
Unknown or unverified delivery requires checking the result, never an automatic
resend. A new customer message asks the manager to review the work; it does not
claim that the trip conditions changed. A profile change, edited source or
changed task revision has its own reason. Reads never cancel or complete an
intent. Cancelled automatic completion leaves the task manual. A historical
`completed` intent does not close a task that a manager subsequently reopened.

The source action uses the existing bounded `message_context` reader even when
the source is outside the loaded transcript or belongs to another selected plan
client. The selection action uses the existing dialog's read-only `openForTask`
mode and the exact task/promise/intent/publication tuple. Neither action switches
the current conversation or acknowledges messages. They preserve the current
composer, real File, task form and reading position, and return focus to the plan.
Actor, session, selected plan row and workspace navigation fences reject late
responses. The exact publication viewer has no attach/cancel/send controls and
does not replace a pending uncertain write in the ordinary dialog.

The backend checks current access and relevant scope again before returning
private evidence. Assistant list/detail reads release the PHP session while
working, then reopen it read-only and match the original actor and CSRF before
responding. Logout, account replacement and token rotation revoke the old result.

Focused verification lives in `run_manager_assistant_promise_facts_regression.php`,
`run_manager_assistant_promise_facts_http_regression.php` and
`run_manager_promise_plan_behavior_regression.js`. The real HTTP suite needs a
native PHP server and PDO SQLite. The prepared visual spec is
`tests/visual/manager-promise-plan.spec.js`; the existing release owner must add
it to the explicit Manager2 visual inventory and run the required browsers.
Test discovery or PHP syntax checks are not those native/browser gates.
