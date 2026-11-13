# Manager promise links to existing tasks

This contract lets an authorized manager explicitly link a recorded
promise about the current trip to one existing canonical task. The link preserves
the source identity/revision, exact profile version and declared deadline provenance.
`LeadTaskService` remains the only task-state owner. The initial implementation is
limited to the `anytour` project, matching its current-trip profile owner.

Implementation owners are [ManagerPromiseTaskService](../services/ManagerPromiseTaskService.php),
the bounded helper in [ManagerRequestProfileService](../services/ManagerRequestProfileService.php),
the existing [pipeline API](../manager/pipeline-api.php) and
[LeadTaskService](../services/LeadTaskService.php) for all task state.

Manager2 exposes this explicit link from an outgoing manager message. It enables
no model extraction, automatic task creation, task-field updates, automatic
completion or customer send. The existing task editor and its manual create, edit, complete and reopen
commands retain their behavior. A successful bind means that a relationship was
recorded; it does not mean that the promised work was performed.

## Manager2 workflow

The message action **Связать с задачей** opens a separate dialog for the selected
outgoing manager message. The UI owner is
[workspace-v2-promise-tasks.js](../manager/assets/workspace-v2-promise-tasks.js),
with its scoped [stylesheet](../manager/assets/workspace-v2-promise-tasks.css).
Message decoration reads only already loaded transcript data and performs no
background request. The server still verifies the original message, its author,
native message ID and current-trip boundary before accepting a binding.

The manager explicitly selects calculation or selection, one existing task, and
a deadline mode: no declared deadline, a Moscow calendar day, or a precise Moscow
date/time. A fresh `pipeline detail` read supplies task options in the dialog's
private state. Completed tasks remain selectable for inspecting an existing
relationship; a first binding requires an open task and `promise_context.can_bind`.
The dialog never renders the canonical task editor or creates a task.

**Проверить связь** reads `promise_context` and shows the checked source, task,
declared deadline, current request label and canonical field values. Profile and
source hashes remain opaque command pins. The returned `needs_review` state is
shown as a request to check the current conditions. A separate **Подтвердить
связь** button performs the write. Changing a selection or deadline invalidates
the preview. A changed server context requires another explicit preview.

If `context.binding` already exists, the dialog shows that saved relationship
and the linked task's current status without another confirmation/write. This
also applies when the manager selected a different task in the picker. If the
saved binding's profile version differs from the current profile, the UI labels
both versions and identifies the displayed conditions as the **current** request;
it does not present them as the original binding's historical profile. This slice
does not add a historical binding list after the current trip changes.

Before sending a command, the UI persists its exact operation ID and typed
payload under an actor/conversation/operation key. It stores no source-message
text or profile-field text. Storage failure blocks dispatch. An uncertain result
keeps this pending command and blocks a new mutation. **Проверить сохранение**
only reads `promise_operation`; `found:false` permits an explicit **Повторить эту
попытку**, using the identical operation ID and payload. There is no automatic
retry or replacement of pins on retry. Successful reconciliation reports the
task returned by the receipt, including a manually completed task.

Modal requests have a 20-second client deadline. A timed-out or locally aborted
write remains uncertain; closing, browser Back, navigation or authentication
recovery does not erase its operation record. Durable records are isolated by
manager. The same manager may reconcile after a new login; another account does
not receive the previous account's pending command or dialog contents.

The dialog fences reads and rendering by conversation, manager, authentication
generation, conversation-open generation, owner/status and its own modal/read
tickets. Close/Escape and the canonical mobile overlay release focus only to the
same current conversation. Access loss or identity change clears private DOM.
Opening, previewing and completing a binding never call `Conversation.open`,
`refreshLeadData` or `LeadCard.render`, so they do not replace a task edit form,
the reply editor or its selected file. All task updates remain in their existing
owners. A missing profile leads to the existing request-profile panel, whose
editor and history ownership are unchanged.

## Identity and ownership

The supported promise kinds are `send_calculation` and `send_selection`. One
promise is identified by the configured project, conversation, immutable trip
ID, kind and original manager-message ID. The server hashes that typed identity
into `promise_id`; clients do not invent it. Profile version, analysis run,
message revision, task title and deadline are not identity components. A new
profile version or a changed wording therefore cannot manufacture another link
for the same promise.

The exact identity encoding and hashing belong to the service. The database also
enforces uniqueness of `(conversation_id, trip_id, kind, source_message_id)` and
`task_id`. The same promise cannot be redirected to another task, and one task
cannot silently acquire another promise. There is no unlink, replace or relink
command in this slice. A successful reuse returns the original binding and its
original provenance rather than updating it with the latest read.

The task must belong to the same conversation and be open for its first binding.
All access checks use the current manager and canonical conversation policy.
Read access alone, including a supervisor's read-only access, does not grant
permission to create a binding. Existing operations are reconciled under current
canonical visibility; the service checks fresh access before disclosing a receipt.

Task title, due time, status, pin and assignee stay in `lead_tasks`. Binding rows
contain no second current task status. A later manual edit, completion or reopen
does not cause a new task, an overwrite or an automatic reopen on bind/replay.
If the linked task no longer exists, the retained binding is an integrity error;
the service must not recreate the task or reuse its promise identity elsewhere.

## Profile and source evidence

`promise_context` provides the current trip's exact profile version, profile hash
and a freshly checked profile-context stamp. `bind_promise` must recheck these
pins against the same conversation and trip before accepting a new link. Profile
history remains owned by `ManagerRequestProfileService`; this feature neither
saves a profile nor advances its version.

This explicit manual binding permits `profile.status = needs_review` when the
saved citations remain valid and the command supplies the freshly read context
hash. That status is not eligibility for automatic promise extraction or task
creation; neither automation is enabled by this contract.

The small `ManagerRequestProfileService::forTaskBinding` helper supplies the
profile/citation stamp within an already authorized service context. It does not
call the public profile `read` or `save` methods inside another transaction: those
methods own their transactions. Existing cited sources must still pass their
canonical integrity checks. A missing, changed or unavailable profile/source is
not interpreted as an empty but acceptable basis. Its bounded context uses the
latest 40 messages plus at most 14 distinct older sources: the trip boundary,
up to 12 profile citations and the selected promise source. Other profile
consumers retain their existing limit of 13 additional sources.

The promise source is a recorded outgoing manager message in this conversation,
at or after the current trip boundary. Its manager author must be known, and its
stored native external message ID must be nonempty. The command pins the server's
`source_message_revision`; the caller cannot substitute another message's text
or a model's explanation. Linked-message and forwarded-container sources are
initially refused. Source readback includes its ID, revision, author, bounded
text and `recorded_at_utc`.

These checks validate stored provenance; they do not contact the provider.
Source integrity establishes which recorded message the manager selected. It
does not prove that the text semantically promises a particular result, that the
customer agreed, that a quoted price is current, or that the task has been done.
There is no inference from `no_contact`, an assistant reason, a similar task title
or an arbitrary outgoing message.

## Deadline provenance

The required `deadline` member has exactly one of these forms:

```json
null
```

```json
{"kind":"date","value":"2026-11-20","zone":"Europe/Moscow"}
```

```json
{"kind":"instant","value":"2026-11-20T16:45:00+03:00"}
```

A date is a validated calendar day in `Europe/Moscow`, not an invented midnight
instant. An instant uses the whole-second RFC3339 form with `Z` or an explicit
`+HH:MM`/`-HH:MM` offset, up to 14:00. Fractional seconds, leap-second notation and
unzoned local timestamps are not accepted. `null` records that no deadline was
declared. The service does not supply a time, date or timezone from an assistant
label or a relative phrase.

The deadline is provenance of this explicit binding command. It never changes
`lead_tasks.due_at_utc`, schedules a reminder, changes task urgency or claims that
the existing task deadline matches it. The canonical task due time remains the
manager's separate field. User-facing date projections use Moscow time. Source
`recorded_at_utc` is an explicit recording instant when its recording receipt is
valid, otherwise null. Historical bare DATETIME values are not silently
reinterpreted or bulk-shifted.

## Existing pipeline API

All commands use authenticated POST requests to `manager/pipeline-api.php`, the
existing CSRF check and `conversation_id`. The action-specific members are:

| Action | Request members | Meaning |
| --- | --- | --- |
| `promise_context` | `task_id`, `source_message_id`, `kind` | Read the binding context for this selected task and source. |
| `bind_promise` | `operation_id`, `task_id`, `kind`, `trip_id`, `profile_version`, `profile_sha256`, `profile_context_sha256`, `source_message_id`, `source_message_revision`, `deadline` | Explicitly record or reuse the immutable relationship. |
| `promise_operation` | `operation_id` | Reconcile one manager-scoped command after an uncertain response. |

Context readback contains `can_bind`, the canonical task, current profile pins,
label/fields/field labels, source provenance and any existing binding. `can_bind` is a current eligibility
result, not an enduring grant. The write rechecks authorization and the relevant
context under its transaction. Clients must preserve exact opaque IDs and hashes
from this read; formatted UI text is not a replacement for them.

`operation_id` is a 32-character lower-case hexadecimal value scoped to the
manager. The service stores a canonical request hash. Reusing the same operation
with the same command returns its original relationship. A different payload or
conversation with that operation is a conflict, never another bind.

A new operation that reuses the same promise still checks the current context.
It must keep the same task, source revision and deadline provenance. It may not
change the recorded deadline; a conflicting target or deadline is
`binding_conflict`. Fresh profile pins can differ from the stored original, but
successful reuse preserves the original binding's profile pin.

After fresh authorization, matching operation replay precedes checks against
the now-current profile or source context. A later profile version, task edit or
completion cannot make an already committed operation happen again. A replay
must still resolve the original binding and task consistently; it must not hide
corrupt or orphaned storage as success.

If the original write response is lost, read `promise_operation` before deciding
whether an explicit retry is appropriate. Retain the same operation ID and
identical payload for that retry. No poll, page reload or uncertain receipt read
automatically replays a bind. Receipt lookup itself has no task or binding write.

## Storage and transaction invariants

Migration `108_manager_task_promises.sql` adds only two tables:

- `manager_task_promises` stores the immutable promise identity, task ID, source
  author/revision, profile pins, deadline JSON, scope JSON, creator and creation
  time. It contains no task title, current due time or completion state.
- `manager_task_promise_operations` stores the manager/operation key, request
  hash and original binding reference. A receipt's conversation must equal its
  binding's conversation; the service checks this relationship as well as the
  separate foreign keys.

`deadline_json` is bounded to 512 bytes and stores JSON `null` for a null
deadline. `scope_json` records the service's exact canonical scope. The binding
and operation are committed together, or neither is committed. Uniqueness is a
database constraint, not a title comparison or an in-memory duplicate guard.

The service owns one transaction and validates the current actor, conversation,
profile/source pins, task identity and existing relation before committing. It
uses the canonical locking order rather than inventing another task writer.
Concurrent requests for the same identity can result in one binding and receipts
for that relationship; they cannot produce two task links or redirect one task.
No branch executes an `INSERT`, `UPDATE` or `DELETE` against `lead_tasks`.

Both tables cascade with conversation retention. Operation receipts also cascade
with their promise row. There is deliberately **no foreign key to `task_id`**:
an orphaned task reference must remain detectable, and the existing conversation
and task purge order must not be blocked. Missing tasks do not authorize repair
or recreation. There is no historical backfill, applied-migration rewrite or
runtime schema bootstrap.

Missing tables, corrupt rows, unavailable evidence and uncertain storage remain
explicit failures for the new binding commands. They must not fall back to an
ordinary task mutation or an unbound success. Existing task commands remain
independent of whether promise storage has been installed.

## Acceptance boundary

The service and real pipeline API regressions must cover:

- a valid explicit bind and readback, with every canonical task field unchanged;
- operation replay and relationship reuse after manual title/due changes, done,
  reopen and A-to-B-to-A edits, without a task write;
- operation/payload mismatch, another task for the same promise, another promise
  for the same task, and concurrent duplicate admission;
- stale profile pins or source revisions, another trip/conversation, invalid or
  forwarded evidence, revoked access and an unverified source author/native ID;
- all deadline forms, invalid dates and unzoned instants, with no task-due change;
- lost-response reconciliation, missing/corrupt storage, an orphaned task and
  unchanged legacy task commands.

These are implementation acceptance requirements, not a claim that CI,
installation or production use has already been verified. This backend slice
and its explicit Manager2 dialog do not enable autonomous obligation tracking.

UI regressions must additionally cover the real message action and separate
preview/confirm path, an existing completed binding, Moscow date/instant forms,
pending storage and receipt lookup without automatic writes, account/navigation
fences, and preservation of the real task edit form, reply text and selected
file. The current request view and a binding's older profile version must remain
visibly distinct. Browser and production verification are separate release gates.

Future automatic field updates need a monotonic canonical task version or an
equivalent durable generation: comparing title/due/status snapshots cannot
detect manual A-to-B-to-A edits or done-to-open transitions. No such version is
needed here because the binding operation never writes task state. Future
completion from delivery must bind the exact result and required delivered
contents to this promise and use verified canonical receipts; a generic outgoing
reply, `known` without verification, or a partial selection is not fulfillment.
Models, delivery, Replies authority and their existing consumers retain their
current ownership until those separate changes are explicitly implemented.

## Promise suggestions in the existing AI result

The same manual generation and prepared-reply analysis may now return
manager_promises independently of should_draft or next_action. A no_contact
decision may therefore contain an obligation suggestion. The new provider
schema requires this array, including an empty array; historical eight-field
proposals remain readable with their original JSON order and digest.

Each of at most three wire elements has exactly kind, source_message_id, quote
and deadline_text. The result kinds are send_calculation and send_selection.
The quote is a literal nonempty fragment of at most600 characters from the
actual supplied message; the optional deadline phrase is a literal fragment of
at most160 characters from that same message. A duplicate source/kind or an
unsupported field is rejected. The model never selects a task or supplies
profile/source pins.

ManagerPromiseCandidates captures at most20 eligible sources from the existing
authorized AI snapshot. It shares the manual binding's pure source admission:
outgoing human-manager message, known author, native message ID, valid metadata,
no linked/forward container and no truncated source. The source must be inside
the supplied window and at or after the saved trip boundary. Its typed revision
includes the author and native ID without exposing those raw identifiers to the
model. The same ReferenceContext revision seals these sources and the immutable
profile version. Optional promise evidence is admitted last under the existing
transport cap; it cannot displace the transcript or other accepted references.

An absent, unavailable or source_changed profile supplies no actionable
candidate. needs_review permits only a suggestion with an explicit human check
of the trip: a new manager message alone can make an earlier saved profile
require review. Fresh means the saved textual context is unchanged, not that
the promise's meaning or every condition has been verified.

The UI receives a separate promise_candidates projection after the existing
result/context checks. The model proposal and persisted hash are not enriched.
This projection includes source, trip and profile pins plus the literal quote
and deadline phrase. It appears in the current AI panel even without a customer
draft, and opens the same explicit promise dialog. A valid AI source need not be
in the browser's shorter current message window. The original source/trip/profile
pins must still match the fresh promise_context before any suggested values are
used. Task choice and final confirmation remain explicit.

Only a small literal deadline grammar is normalized: a complete calendar date,
a whole-second zoned instant, or today/tomorrow/the following day anchored to
the source's confirmed recorded_at_utc and projected into Europe/Moscow.
An explicit time with a known day becomes an instant; a day alone stays a date.
A missing recording clock, omitted year, vague time or unsupported phrase
leaves the deadline unresolved. A null deadline always requires an explicit
manager choice and is never silently converted into the manager's decision
that no deadline exists.

These source, schema, persistence and UI checks establish provenance and
integrity. They do not establish semantic extraction quality. Negation,
conditional promises, a quoted third party, another trip, cancellation and
continued/replaced promises also require the model instruction and human
confirmation. Synthetic contract tests are not a held-out language-quality
evaluation. No model/provider call, new processing queue, automatic task write
or customer delivery is introduced by opening or accepting a suggestion.
