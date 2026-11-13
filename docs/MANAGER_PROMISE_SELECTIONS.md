# Promise-linked selection completion

## Scope and owners

Manager2 can explicitly associate an existing `send_selection` promise and its
existing task with one frozen tour-selection publication. The existing native
selection sender remains the only sender. Its confirmed delivery boundary can
complete that task after the entire associated composition is delivered.

`ManagerPromiseTaskService` retains ownership of message-to-task provenance.
Its pure `sourceEvidence()` validator is shared by both authorized promise
readers; each caller still verifies its snapshot and the recorded author.
`ManagerPromiseSelectionService` owns the explicit result association and its
receipts. `LeadTaskService` remains the sole owner of task-state changes.
The existing selection editor owns editing, preparation and sending. This feature
adds no worker, polling schedule, delivery queue, model call or customer message.

This source is prepared for existing release owners. Source checks, native
acceptance, installation and semantic/product acceptance are separate states.

## Explicit user path

1. Associate the sent promise of a selection with an existing task through the
   existing promise dialog.
2. Prepare the selected offers through the ordinary selection editor. Preview
   creates an immutable publication; publishing a public link is optional and
   does not prove customer delivery.
3. In Manager2, choose **Связать подборку с обещанием** beside the selection
   launcher. Select the promise and a saved publication explicitly.
4. Review the original promise, the task, current trip conditions and every card
   in that publication. An old saved publication has no intrinsic request-profile
   trip binding: the explicit confirmation assigns this composition to the
   displayed current trip.
5. Confirm the association. This action sends nothing and changes no task field.
6. Send through the existing selection editor. Only complete, verified delivery
   of this exact composition can apply completion.
7. Inspect the result or explicitly cancel automatic completion through the same
   dialog. Cancellation disarms automatic completion and retains the intent's
   history. It does not mark the task or the underlying promise fulfilled.

A publication contains the canonical one-to-twenty selected offers. The complete
explicitly reviewed composition is the unit of fulfillment; an arbitrary card,
one card from a larger composition, a similar hotel or a public-link view is not.
There is no arbitrary minimum offer quota.

## Admission before sending

The association requires a freshly authorized active account and the canonical
conversation edit policy. It pins:

- the existing promise ID, source message revision and current trip ID;
- the request-profile version and digest;
- the canonical customer-message and human-request frontier;
- the existing task ID and monotonic business revision;
- the immutable publication ID, draft digest and transport identity;
- its entire ordered manifest of card index, offer ID and offer digest.

All selected delivery fences must still be `pending` or `rejected` while the
conversation is locked. A rejected attempt proves no delivery; the existing
sender still requires a separate explicit retry. A `reserved`, `unknown`,
`known` or `delivered` fence cannot be newly associated.

That restriction is necessary because existing selection deliveries are shared
by offer digest across publications. An already delivered shared card cannot be
reinterpreted as performance of a later promise. A publication may have been
prepared earlier; its age alone neither proves nor disproves its current purpose.
The manager reviews the whole composition against the current conditions.

The read-only preview returns `preview_sha256`. Confirmation recomputes it from
the same current evidence. The browser does not supply trusted delivery states,
a replacement task, a subset of cards or its own trip/hash authority.

## Task revision and manual control

Migration109 adds `lead_tasks.business_revision`, initially one for existing
tasks. Actual edits of title/due, status, pin and assignee increment it. Identical
commands do not. Reminder-attempt and reminder-notified bookkeeping do not
increment it; wall-clock timestamps are not a revision.

This prevents both `open → done → open` and `A → B → A` from restoring an old
completion authorization, even within the same second. Reassignment follows the
same rule through the existing conversation owner.

The transaction-aware task-completion method requires an open task with the exact
conversation, ID and expected business revision. It changes completion state,
ordinary update/completion timestamps and the revision. Manual title, due, pin
and assignee remain intact.

## Native delivery and atomicity

The authoritative hook is `ManagerTourSelectionDeliveryService::finish()`.
Before it, native creation or possession of an MID is insufficient. The existing
transport verifies the native result; `finish()` records the canonical delivery
event, outbound transcript and `delivered` fence in one transaction.

The fulfillment check uses the entire pinned manifest and matching publication
evidence. Delivery of a shared offer through another publication does not fulfill
this association. All cards must have the corresponding canonical native and
outbound evidence.

Fresh source, trip/profile and recipient/assignment identity must still match.
The profile context digest is deliberately not held constant after sending:
the newly sent cards themselves extend the transcript. The saved profile
version/digest and source revision remain necessary.

The existing Replies owner supplies a separate `captureSendBoundary()` frontier.
Any new canonical customer message or `waiting_manager` event after the explicit
association blocks automatic completion with `context_changed`, even when the
saved profile has not yet been updated. Own outbound cards and callback/start
events do not advance this frontier. This guard does not interpret a new message
as changed travel conditions; it leaves the task for a manager to check.

The original native sender is checked as the current acting manager. Lock order
starts with that actor before the conversation, retains delivery locking, and
then reaches the task. There is no nested public promise transaction or
creator-manager lock acquired after the conversation lock. The administrator
who associated the result remains recorded as provenance; actual sending stays
with the ordinary assigned-manager sender.

Logical refusal, such as changed task or trip, preserves the confirmed delivery
fact and leaves task completion to the manager. Database failure while writing
the completion proof or applying task CAS rolls back the local last-card,
proof and task updates together. The existing `known` recovery path verifies the
already-created native message; it does not create another one.

The optional initial intent lookup does not make an unrelated legacy send depend
on a missing new schema. Once a linked intent is known, storage failures are not
silently treated as success.

## Intent and operation receipts

Intent states are `armed`, `completed`, `cancelled` and `blocked`. At most one
intent is active for each promise and for each publication. Terminal records
retain their immutable original association. A later explicit association may be
made only with newly admissible evidence and current task revision.

The bounded catalog shows active associations from every trip in this
conversation before the current trip's terminal history. Changing the trip does
not hide a still-armed association: the manager can explicitly cancel it and
release the unstarted publication without editing its task.

A completed intent is historical evidence that automatic completion was applied.
If a manager later reopens the task, the old delivery or receipt does not close
it again. UI wording must not infer the current task state solely from this
historical intent state.

Attach and cancel commands use actor-scoped operation IDs and exact request
digests. The browser stores only action, operation and opaque identifiers/hash
before writing. It stores no promise quote, private trip conditions or card text.
After an unknown response it reads the existing operation first; a missing
receipt permits only a separate explicit retry of the identical command.
Closing a dialog, expiry or changing the selected pair never replaces a pending
operation.

When a dialog is reopened from that pending record, it shows receipt recovery
and the saved intent state. Empty selection fields stay hidden. An explicit
catalog refresh is required to review an association or obtain current editing
rights before cancellation; recovery itself never grants those rights.

## Interface boundary

`manager/promise-selections-api.php` uses canonical ManagerHttp authentication,
CSRF and JSON responses. Its operations are bounded database work and hold the
ordinary session lock through authorization and response. Service transactions
refresh the account/project/conversation policy.

Actions:

| Action | Input beyond action, CSRF and conversation ID | Meaning |
| --- | --- | --- |
| `catalog` | none | Bounded existing promises, publications and intents |
| `preview` | `promise_id`, `publication_id` | Fresh complete review, no write |
| `attach` | `operation_id`, `promise_id`, `publication_id`, `preview_sha256` | Explicit immutable association |
| `cancel` | `operation_id`, `intent_id` | Explicit cancellation of automatic completion |
| `operation` | `operation_id` | Read-only reconciliation of an uncertain command |
| `status` | `intent_id` | Read-only current intent status |
| `view` | `task_id`, `promise_id`, `intent_id`, `publication_id` | Exact read-only historical association and saved composition for the plan |

The local Manager2 launcher delegates to the separate scoped workspace module.
It does not inspect a standalone editor's private DOM, intercept its transport or
infer its selected publication. The Manager2 shell gains only the local lazy
launcher. The shared editor payload, PromiseTasks dialog and pipeline endpoint
remain owned by their existing implementations.

### Read-only plan projection

The existing plan uses `forTasks()` for at most 50 already visible task IDs in
one selected conversation. It does not call catalog/preview once per task. The
binding owner validates saved promise provenance and original deadline; the
profile owner reads the current profile and the needed sources in one bounded
initial batch and a final freshness recheck. The selection owner prefers the
active association and otherwise the latest saved history. An aggregate 8 MiB
budget bounds heavy intent/publication/receipt data; exhausted coverage is
reported as unavailable. Missing optional storage is also unavailable, not an
empty result, and does not remove ordinary tasks.

The plan's `selection.progress.confirmed` is specific to the immutable publication.
It reuses the existing private native/event/transcript evidence validator for
each card, preserving its original manifest index. The general status dialog's
shared-fence `delivered_count` is not a substitute for this evidence. A card sent
through another publication cannot advance the plan's confirmed count. The
stored intent state and reasons observed during the read remain separate.

`view` derives the relation from saved data and compares the entire supplied
tuple. Current ACL and recipient/scope checks precede disclosure of the frozen
card text. A changed responsible manager or conversation status keeps the
automation stale but permits the newly authorized viewer to inspect the same
recipient's saved composition. Changed recipient, source or other privacy scope
still hides it. An older trip's association can be inspected with its stale reasons;
its publication is not reinterpreted as belonging to the current trip. The
browser's `openForTask` mode is read-only: it loads no catalog, opens no preview,
and has no attachment, cancellation or send controls. The ordinary explicit
dialog remains the owner of those actions and any pending uncertain operation.

The same projection is documented in `MANAGER_ASSISTANT.md`. It never adds a
completed task to the open-task list or changes a manually reopened task's status.

## Editor compatibility

The delivery hook changes the existing editor backend fingerprint. The new
fulfillment service and its direct conversation/task/promise/profile/Replies owners are explicitly
listed in the existing `editor.json.backend_inputs`. Dependency traversal is not
assumed.

The ordinary editor-module build and existing post-deploy host installation
remain with the current release owner. A common package can be rebuilt against
new backend bytes. If an active independent editor seal requires
`install-common`, the release owner must check its accepted version and apply
the existing version rule; source preparation does not prove the live seal is
compatible. No new deployment workflow or independent publisher is introduced.

## Verification boundary

Use a focused real-SQLite service suite with synthetic native transport, actual
workspace-assets UI cases and isolated real HTTP/session cases. Extend the
existing native MySQL delivery runner for revision/CAS concurrency and rollback.
Do not resend real customer cards or rerun unrelated AI/profile suites.

Required cases include complete versus partial delivery, historical/shared
fences, wrong publication, changed source/profile/recipient, manual edit and ABA,
reminder-only updates, cancellation, operation identity, lost acknowledgements,
and rollback followed by existing-message recovery. Browser/native HTTP/MySQL
execution and required release CI must be reported separately from source checks
or test discovery.

`tests/visual/manager-promise-selection.spec.js` covers review at 390, 430, 768
and 1440 pixels plus a short mobile viewport, explicit attachment, lost-response
readback, partial versus completed history, and preservation of the composer,
real File and task draft. Actor/conversation changes fence late receipts.
The existing visual registry includes the spec in `MAIN` and exactly one
`PART_SPECS` group; no partition, workflow or publisher is added. The actual
canonical service tests separately prove native-delivery and task effects;
browser API fixtures prove only interface behavior. Test discovery alone is
not execution, and neither local proof replaces current required release CI.
