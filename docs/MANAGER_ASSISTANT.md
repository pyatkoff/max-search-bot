# Manager2 assistant

The Manager2 rail opens one private assistant across the signed-in manager's active assigned clients. The conversation toolbar opens the same client recommendation. Both paths read the same prepared proposal; opening the assistant never generates a reply, reads media/selection links, changes unread state, or sends a customer message.

The feed prioritizes the full freshly authorized own-active scope before paging (20 per page), independent of the Inbox's capped candidate set. Canonical unanswered-manager-response facts come first, then arrived existing open tasks assigned to the actual owner (or unassigned), then other clients; conversation ID is the stable tie-breaker. Callback/start-only entries are not new human questions. Future/completed/foreign tasks do not become arrived work. Sorting and filters describe the loaded part of the feed. An absent or expired proposal means **no fresh analysis**, not that all work is complete. The existing preparation collector is not an analysis of every client. A `no_contact` result is displayed as waiting, with no automatic due date or new reminder.

Returning from the assistant to the already-open conversation retains explicitly
loaded older history through the canonical History/Conversation option. Existing
message/media nodes, reading position and reply/File remain under those owners;
opening another client still resets history and rechecks its access normally.
Navigation dismisses the originating menu instead of reopening it over the
conversation. Explicit Close/Escape still restores that menu and its opener focus.
Returning to the same mobile conversation adds no duplicate browser-history
entry; Back still reaches the Inbox once. Opening another client or returning
from the Inbox keeps the canonical navigation entry.

Prepared proposals retain the canonical one-hour TTL and all content, instructions, knowledge, ownership and source/project visibility fences. Only the actual assigned manager sees recommendations, including when the viewer is an administrator. Opening a different client rechecks the current proposal. An open detail refreshes every 20 seconds, pausing while a deadline is being edited; explicit refresh updates the loaded client list. Identical background reads retain the actual controls, expanded basis, text selection and scroll position. Changed or expired reasoning still disappears. No background scan runs while the assistant is closed.

`Выполнено`, `Неактуально` and `Вернуть в работу` change only private assistant decision metadata in migration048. Decisions are bound to the precise proposal and context, so a new analysis does not inherit an old dismissal. Compare-and-set state protects conflicting actions; an unknown response is reconciled by a read, never by automatically repeating a write.

Task completion and deadline changes delegate to the existing Pipeline/LeadTask service. Scheduling a recommendation opens the current client's canonical task editor. Existing open tasks are shown first; no second reminder delivery system or automatic task creation exists. An unfinished task/reply/file remains under its existing draft owner.

The read endpoint is `manager/assistant-api.php`, using ManagerHttp authentication and CSRF. UI lives in `workspace-v2-assistant.js` and its stylesheet, loaded only by Manager2. The legacy workspace retains its existing assets. The service exposes at most50 open tasks per client and reports truncation; the full task card remains canonical.

Verification covers own-manager authorization, revoked sources, expiry and edits, more than200 clients, revision-bound decision conflicts, read-only behavior, browser account/navigation races, and the actual Manager2 shell at390/430/768/1440px. CI browser evidence and natural manager use are separate acceptance facts; a passing fixture is not production-use evidence.


## Operational facts and stable continuation

The message-without-recorded-reply fact and arrived task are independent of AI.
The panel asks the manager to review the conversation, never assumes every inbound
requires a send. Fresh canonical `no_contact` retains its waiting state; expiry or
new context removes old AI reasoning while operational work remains visible.
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
