# Manager media delivery recovery

Wazzup plan item 4 extends the existing manager send-attempt owner to individual
files. It does not introduce another sender or restore file bytes after reload.
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
that exact file attempt. It stores metadata and caption intent, not file bytes.
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
selection. Recovery does not turn a saved descriptor back into a selected File.
Restoring the draft with its actual attachments is separate plan item5.

## Verification and installation

The focused regressions cover durable media outcomes, partial batches, lost
responses, reload, cancellation races, identity/context changes and storage
failure. Native MySQL verifies the forward migration and concurrent execution
fence. Responsive cases use the actual Manager2 shell at390/430/768/1440 widths.
Shared runtime changes retain the complete required CI and ordinary installation
gates. Physical-phone use and a natural customer delivery case are separate from
technical installation acceptance.
