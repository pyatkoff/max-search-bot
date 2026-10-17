# Manager AI reply drafts

An assigned manager can explicitly request a proposed reply, inspect its reason
and checks, and append it to the current composer. Sending remains the existing
manager action. Nothing is generated on startup, polling or conversation open.

## Owners and boundaries

- `manager/ai-draft.php`: canonical ManagerHttp authentication, CSRF and JSON
  boundary; `generate` and read-only `validate` actions.
- `ManagerAiDraftContextService`: fresh active-account, project/source visibility
  and strict reply ownership; short read-only snapshots; revision fingerprint.
- `ManagerAiDraftService`: isolated draft instructions and strict result checks.
- `ai/ManagerDraftClient.php`: fixed OpenAI Responses endpoint, existing
  `OPENAI_API_KEY` / `OPENAI_MODEL`, strict JSON schema, `store:false`, no tools,
  no redirects, bounded response and a 20-second timeout. No automatic retries.
- `workspace-v2-ai-draft.js`: preview, stale-result fencing and explicit insertion
  through `WorkspaceV2Conversation.insertQuickReply`; no new send implementation.

The normal project initializer has a legacy UPSERT. This endpoint invokes
`ProjectAccessService::initializeReadOnly()` before auth instead. Draft generation
does not call the HTTP `detail` action, mark messages read, update needs, take or
release conversations, create tasks, or invoke bot delivery/state services.
Admin edit permission does not allow drafting replies for another manager.

Each context contains at most 120 recent messages and 40,000 Unicode characters.
Older history, truncated text and unread attachments/selection links are explicit.
The model receives only the projected conversation: external identities, raw
metadata, media URLs, phone numbers and email addresses are excluded/redacted.
Names and other personal content can still occur in message text; the payload
is private and must not be logged or committed.

The source fingerprint includes edits in place and ownership/status, not only
the latest message ID. The initial transaction ends and session lock is released
before the provider call. Afterwards the endpoint reloads the session and reads
a new authorized snapshot. New messages, edits, revoked access, reassignment,
closure, delivery suspension, account changes or CSRF rotation invalidate the
result. A separate fresh `validate` precedes insertion. These are read-time checks,
not a reservation; the existing send path remains authoritative at send time.

## Knowledge and output

This first slice uses generalized communication procedures only. Private pilot
examples and candidate company/personal knowledge are not production facts and
are not bundled or silently loaded. Future knowledge retrieval must require
approval provenance for company/team facts and scope personal knowledge to its
actual author; current conversation ownership is not authorship.

Model output separates the customer draft from the reason and manager checks.
It can decline a new message after a stop, purchase, thanks without a new
question, or a pending promise to check options. It must not invent prices,
availability, policy, hotel properties, payment details or completed actions.
Schema/evidence validation cannot prove factual correctness: the manager reviews
every draft. Links and attachments are not opened; historical offers are not
presented as current availability.

`no_contact` is advice about the proposed message, not a status change or a
permanent prohibition. This slice does not implement timed follow-up scoring,
automatic tour selection, training, personal style learning or autonomous sends.

## Verification

Required synthetic suites exercise context/read-only access, strict provider
results, actual endpoint/session races and canonical composer insertion. Browser
coverage uses the actual PHP shell/assets with synthetic API responses, including
mobile widths and stale authorization. Existing required and visual checks stay
mandatory; a fixture pass does not establish live provider quality.

Private historical evaluation must contain only messages through each cutoff,
without later outcomes or current selection-page contents. Generated results are
reviewed against evidence separately from manually prepared reference replies.
Do not publish plaintext examples, model inputs/outputs, private URLs or credentials
in GitHub, Actions logs or artifacts.
