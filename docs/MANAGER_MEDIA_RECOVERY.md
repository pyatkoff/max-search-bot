# Manager media delivery recovery

Wazzup plan item 4 extends the existing manager send-attempt owner to individual
files. Item5 adds reloadable attachment drafts to the existing browser Media owner,
without introducing another sender.
It tracks new media attempts; historical untracked sends and their unknown
outcomes are not reconstructed or replayed.

## One file, one immutable attempt

`ManagerSendAttemptService` and `manager_send_attempts` own the execution fence
and outcome for both text and media. Migration100 adds an explicit operation kind
with `text` as the default for existing rows. The actor/operation primary key
remains shared: an ID cannot be reused across text and media.

The server binds a media attempt to the authorized actor and conversation context,
and fingerprints the actual uploaded bytes, size, sanitized filename, detected
MIME type and caption. It commits `processing` before any preview or transport
work. Repeated requests read the saved attempt; they never invoke the sender
again. The winning call uses the existing `ManagerOutboundService::sendMedia`.
Fresh access and the actual recipient are checked again before dispatch.
Media upload, lookup and cancellation also require the browser's captured
manager ID to match the current session actor. An account switch cannot bind
an old tab's operation to the new account, even if its CSRF token is unchanged.

The reply queue still captures the unanswered boundary before the provider call.
New inbound messages during a send remain in the queue. Read state, assignment,
manager shifts, sales state and historical first-response metrics remain separate.

## Outcomes and recovery

- `confirmed`: the existing sender confirmed that the provider accepted the send.
  This does not claim that the tourist read the file.
- `rejected`: a definite refusal or a confirmed failure before dispatch.
- `processing` or `unknown`: the send may have happened. No resend is permitted
  for the same attempt.
- `not_found`: the server has no recorded attempt yet. This is not proof that a
  delayed original request cannot arrive.
- `cancelled`: an explicit cancellation reserved a tombstone before sending
  started. A delayed original request cannot send with that ID.

`media_receipt` only reads a freshly authorized, actor-owned media outcome.
`media_cancel` can reserve a tombstone for a not-yet-started operation; it does
not cancel a provider call or change a processing/unknown result. Neither action
sends a file. Receipt responses omit file bytes, raw captions, recipient details
and provider payloads. Network failures, ambiguous status codes and server errors
remain uncertain rather than becoming a claimed rejection.

## Browser contract

Before each upload, the browser durably saves an account-scoped descriptor for
that exact file attempt. It stores metadata and caption intent, not file bytes. The separate draft store below owns selected local blobs.
The immutable intent and terminal outcome have separate keys for the same
operation. A delayed pending result from another window cannot overwrite a
confirmed outcome. Pruned terminal history cannot resurrect an old intent.
If persistence fails, the upload does not begin. Unresolved attempts are never
evicted merely because they are old or storage is full.

The outcome strip is separate from the current attachment selection. Reload and
reopening a conversation may reconcile outcomes, but never replay an upload.
The manager can check each file and explicitly cancel an unstarted attempt.
Unknown results remain visible and fenced even if a local selection is removed.

A batch remains a sequence of individual canonical sends. Confirmed attempts
never dispatch again; the confirmed caption is not repeated on remaining files from
the same batch. Late results belong to their original account, conversation,
batch and draft context, and must not erase or consume a newer draft or file
selection. The delivery journal remains separate from selected local file bytes.

## Reloadable draft attachments

The existing Conversation module remains the only text-draft owner: account-bound
sessionStorage, including text version and unresolved text-send fences. Untouched
AI previews are still excluded. Media remains the only selected-File owner.

`workspace-v2-media-drafts.js` stores immutable binary file bytes in IndexedDB
and a bounded selection manifest in this tab's sessionStorage, scoped by manager and
conversation. Native ArrayBuffer payloads avoid relying on persistable File/Blob
handles; loading reconstructs the original File metadata and bytes. A duplicated
tab inherits an independent manifest; there is no
shared mutable selection record. New selection writes fork through immutable blob
identities. Removing a selection does not delete bytes another tab may reference.
Blobs expire after24hours and storage is bounded to100MiB, with at most10files per
conversation and20saved conversation selections per tab. Browser eviction, private
mode or quota errors can prevent restoration; the UI reports failure and retains
current RAM files rather than claiming they were saved.

Only a freshly authorized, manager-owned editable conversation can hydrate its
selected files. Loading never replaces text. Late loads cannot overwrite newer
files, another conversation, an expired session or another account. Explicit logout
and identity changes clear selection manifests and revoke pending commits; access
loss or loss of assignment clears the affected selection. The delivery journal's
unresolved operations remain fenced independently.

Before each upload, file bytes must be committed and the exact row's operation and
batch IDs must be recorded in its reloadable manifest. After reload, the existing
delivery journal is overlaid by these exact IDs: confirmed rows are removed,
rejected/cancelled rows become ready and unresolved/missing receipts remain
blocked. Matching by filename does not consume a new selection. Confirmed batch
captions stay associated with remaining files so an explicit continuation does not
repeat them. Restoring, receipt checks and storage writes never send a file.

## Verification and installation

The focused regressions cover durable media outcomes, partial batches, lost
responses, reload, cancellation races, identity/context changes and storage
failure. Native MySQL verifies the forward migration and concurrent execution
fence. Responsive cases use the actual Manager2 shell at390/430/768/1440 widths.
Shared runtime changes retain the complete required CI and ordinary installation
gates. Physical-phone use and a natural customer delivery case are separate from
technical installation acceptance.
