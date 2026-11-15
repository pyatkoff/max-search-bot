# Promise-linked calculation completion

## Purpose and owners

An assigned manager can explicitly associate the complete contents of the normal
reply composer with an existing `send_calculation` promise and its existing task.
Only confirmed sending of that exact composition can complete the unchanged task.
There is no separate calculation editor, automatic task creation, new sender,
worker, polling schedule or inference from an arbitrary outgoing message.

`ManagerPromiseTaskService` owns the original promise-to-task relationship.
`ManagerPromiseCalculationService` owns the reviewed composition, its intent and
completion evidence. The existing `ManagerSendAttemptService`,
`ManagerOutboundService`, adapters and `ConversationRecorder` retain their sending
and transcript responsibilities. `LeadTaskService::completeForPromise` remains
the only task-state writer at fulfillment.

The initial calculation path supports the ordinary MAX and Telegram senders.
Their successful native response must identify the actual outgoing message.
Website sending retains its existing behavior and does not qualify through a
boolean success value without a native message identity.

## Manager path

1. Use **Отправить расчёт** on an open task with a saved calculation promise.
2. Prepare the calculation in the existing reply composer and choose ordinary
   attachments. Existing text, selected `File` objects and task edits remain with
   their existing owners.
3. Review the chosen task, original promise, current trip and complete outgoing
   composition. A text-only calculation has one part. An attachment calculation
   has the existing ordered file batch, with the text as the first file's caption.
4. The ordinary **Отправить** action explicitly confirms the displayed review,
   records the intent and then uses the existing sender. A changed composition or
   changed task/context first requires a new review and another explicit click.
5. The task completes only after every reviewed part has sufficient recorded
   sending evidence. Failure, partial sending or an unknown result leaves it open.

Selecting a task, reading a preview or arming an intent sends no customer message.
Arming alone neither completes a task nor marks the promise fulfilled. Removing
the association cancels automatic completion, preserving the task and history.
An empty composer can be prepared after selecting the task; it is not an error
requiring a different editor or a new task.

## Exact composition and context

Preview and arm use the same typed composition: trimmed text and an ordered list
of file descriptors containing SHA-256, byte size and normalized filename. The
server defines the canonical manifest. Browser-supplied hashes are declarations
to review, never proof of sending: the sender recomputes the actual uploaded
file's digest and size before reserving its part. The existing uploader determines
the MIME type; a browser-provided MIME string is not trusted proof.

The intent pins:

- the existing promise, its original message and source revision;
- the current immutable trip, profile version and digest;
- the canonical customer-message and human-request frontier;
- the existing task and its monotonic business revision;
- the acting manager, assignment and exact conversation/transport recipient;
- the complete ordered text/file manifest.

No similar title, quote, public-link view, reaction, “спасибо” or generic `ok`
substitutes for these pins. Changing the task and changing it back does not
restore the earlier business revision. Completing and reopening it manually
does not let an old result complete it again.

A manager may explicitly start a new calculation for a reopened task. That is a
new review and intent pinned to its new business revision. A restored historical
intent never turns itself into this new authorization or resends its old result.

## Sending evidence and failure boundaries

The part association is fixed before provider work, together with the ordinary
durable send-attempt reservation. A part cannot be silently moved to a different
operation or reinterpreted as a later promise. No database lock is held across a
network request.

Only the branch that actually receives a successful adapter result with a
nonempty native message ID may create calculation sending evidence. Suppression
of an immediate duplicate never reaches that branch. The proof identifies the
operation, part, actor, recipient and actual content/file digest. It means the
channel accepted the send; it does not mean that the customer read the message
or accepted the price.

Canonical transcript recording and application of completion are checked against
that exact proof. Logical refusal because of a changed source, trip, profile,
customer frontier, task or assignment retains the sending fact and leaves the
task under manual control. Storage failures must not produce a completed task
without its matching evidence. Durable native evidence can be finalized locally
without calling the adapter a second time.

Partial batches retain their individual results. A confirmed part is not resent
because another part failed. A missing or unknown operation result is not proof
of non-delivery and does not authorize automatic replay. The ordinary sender's
existing UNKNOWN and explicit retry rules remain authoritative.

## Recovery and access

The browser records the exact pending arm command and its scope before arming.
An uncertain arm response blocks sending and is reconciled through its operation
receipt. Closing the conversation or logging in again does not create a new
operation to bypass the unresolved one. Account changes and lost access clear
private UI state under the existing fences.

An explicitly absent receipt permits a separate retry of that identical arm
command, including its original operation ID and preview hash. Its immutable
command snapshot contains the outgoing text and file descriptors, not file bytes,
the source quote or private profile fields. Retrying this command only arms the
reviewed composition; it never sends or replaces a newer draft. Sending still
requires the current composer and selected files to match the armed composition.

`catalog`, `preview`, `operation` and `status` are bounded reads. They do not call
a model/provider, send a message, alter a task or move a read marker. Explicit
`recover` finalizes already recorded sending evidence locally. It cannot create
delivery proof from an ordinary success flag or from a supplied native ID.

Every endpoint action requires the current ManagerHttp session, CSRF, explicit
expected manager ID and fresh canonical conversation permissions. Ordinary
supervisor visibility alone does not grant the assigned manager's sending
authority. Task changes remain protected by a transaction and revision check.

## API

`manager/promise-calculations-api.php` accepts POST JSON with `action`, `csrf`,
`conversation_id` and `expected_manager_id`.

| Action | Additional input | Result |
| --- | --- | --- |
| `catalog` | None | Existing calculation promises/tasks and retained intents |
| `preview` | `task_id`, `promise_id`, `composition` | Current source/task/profile and exact reviewed manifest |
| `arm` | Preview input, `operation_id`, `preview_sha256` | One immutable intent, or the identical operation's receipt |
| `operation` | `operation_id` | Actor-scoped operation lookup, including explicit absence |
| `status` | `intent_id` | Current retained intent and part progress |
| `cancel` | `operation_id`, `intent_id` | Explicitly disarmed intent with history preserved |
| `recover` | `intent_id` | Local finalization of already saved native evidence |

The existing text-send and media-upload endpoints accept optional
`calculation_intent_id` and `calculation_part_index`. Their ordinary operation IDs,
send receipts and recovery remain in use. Legacy calls without a calculation
association retain their existing request and response shapes.

## Verification boundary

Acceptance must exercise real services, the production HTTP endpoint and native
database concurrency with synthetic inputs. It must cover complete and partial
composition, wrong bytes/order/recipient/task, duplicate suppression, unknown
send/arm results, local recovery without resending, source/context changes,
manual edits/reopening, access revocation and atomic storage failure. Browser
acceptance covers the actual Manager2 shell at desktop and mobile widths in
Chromium and WebKit, preserving the existing composer, files and task drafts.

Technical acceptance and installation are separate from natural manager use.
Synthetic successful sends do not establish real customer delivery or semantic
accuracy of promise recognition. Those observations stay with their respective
existing owners and do not retain a technically completed application release.
