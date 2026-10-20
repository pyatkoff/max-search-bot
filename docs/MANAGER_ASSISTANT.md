# Manager2 assistant

The Manager2 rail opens one private assistant across the signed-in manager's active assigned clients. The conversation toolbar opens the same client recommendation. Both paths read the same prepared proposal; opening the assistant never generates a reply, reads media/selection links, changes unread state, or sends a customer message.

The feed pages by conversation ID (20 per page), independent of the Inbox's capped candidate set. Sorting and filters describe the loaded part of the feed. An absent or expired proposal means **no fresh analysis**, not that all work is complete. The existing preparation collector is not an analysis of every client. A `no_contact` result is displayed as waiting, with no automatic due date or new reminder.

Prepared proposals retain the canonical one-hour TTL and all content, instructions, knowledge, ownership and source/project visibility fences. Only the actual assigned manager sees recommendations, including when the viewer is an administrator. Opening a different client rechecks the current proposal. An open detail refreshes every 20 seconds, pausing while a deadline is being edited; explicit refresh updates the loaded client list. Identical background reads retain the actual controls, expanded basis, text selection and scroll position. Changed or expired reasoning still disappears. No background scan runs while the assistant is closed.

`Выполнено`, `Неактуально` and `Вернуть в работу` change only private assistant decision metadata in migration048. Decisions are bound to the precise proposal and context, so a new analysis does not inherit an old dismissal. Compare-and-set state protects conflicting actions; an unknown response is reconciled by a read, never by automatically repeating a write.

Task completion and deadline changes delegate to the existing Pipeline/LeadTask service. Scheduling a recommendation opens the current client's canonical task editor. Existing open tasks are shown first; no second reminder delivery system or automatic task creation exists. An unfinished task/reply/file remains under its existing draft owner.

The read endpoint is `manager/assistant-api.php`, using ManagerHttp authentication and CSRF. UI lives in `workspace-v2-assistant.js` and its stylesheet, loaded only by Manager2. The legacy workspace retains its existing assets. The service exposes at most50 open tasks per client and reports truncation; the full task card remains canonical.

Verification covers own-manager authorization, revoked sources, expiry and edits, more than200 clients, revision-bound decision conflicts, read-only behavior, browser account/navigation races, and the actual Manager2 shell at390/430/768/1440px. CI browser evidence and natural manager use are separate acceptance facts; a passing fixture is not production-use evidence.
