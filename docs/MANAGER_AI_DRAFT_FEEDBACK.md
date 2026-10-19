# Manager AI draft feedback

This feature extends [Manager AI reply drafts](MANAGER_AI_DRAFTS.md). It changes
the older read-only `validate` contract only when an optional draft identity is
provided: the endpoint may record an explicit insertion request. Context capture
remains read-only. `ManagerAiDraftFeedbackService` owns private persistence;
`ManagerOutboundService` and the existing recorder remain the only send owners.

## Feedback provenance

After generation passes the existing fresh session, authorization and context
checks, a separate best-effort service stores the validated eight-field proposal
in `manager_ai_drafts` and returns a random per-generation `draft_id`. The context
revision alone is not a proposal identity. The private row contains the actual
manager, conversation and project, context/instruction revisions, latest source
message ID and timestamps. Proposal JSON is bounded to 32,768 bytes; raw context,
prompt, page HTML, external identities and provider responses are not copied.
Rows follow the existing private conversation-store retention; this feature adds
no automatic deletion, public export, scheduled capture or model training.
Schema creation belongs only to forward migration 043.

The optional `draft_id` on `validate` records `insert_requested_at` only after
the ordinary fresh-context checks. It observes the explicit request, not browser
insertion, quality approval or sending. Repeated validation is idempotent. Closing
the panel, Escape, regenerating, or the absence of a later action is not rejection.
Legacy clients and unavailable feedback storage still receive usable suggestions
and fresh validation, with no tracked identity.

The canonical composer carries an optional identity beside its existing scoped
unsent text. Edits retain the association; clearing, account changes and access
loss remove it. Reload restores it under the existing authorization and 24-hour
session-draft limits. A local draft version prevents an old send response from
consuming a newer draft with identical text but a different AI identity. No extra
request is added to the send path, and no send is retried automatically.

For ordinary text sending, the server rechecks proposal ownership, conversation,
project, insertion request and 24-hour reference eligibility. Only an actual
canonical `messages` INSERT receives minimal `metadata_json.ai_draft` provenance:
the draft ID, equality with the trimmed proposal text, and the initial sent-text
SHA-256 before HTML escaping. The canonical row's own ID is the message link.
Suppressed duplicates and delivery failures produce no new linked message.
Feedback failure does not change normal delivery; a recorder failure does not
establish a persisted-message receipt. Later message edits are distinct from the
initial text hash. The first slice leaves media and captions unlinked.

Matching text describes equality only. A difference can include an existing
manual prefix, added details or complete replacement; it is not an approval,
rejection or quality score. Natural manager use and enough reviewed examples are
still needed before drawing conclusions or proposing instruction improvements.

Production continues to use Fast. All provider-backed offline tests, replays,
comparisons and graders must explicitly request `service_tier: "default"`
(Standard), retaining the selected model and reasoning. Mock request/profile
contract tests may describe Fast without making a provider call. This feedback
feature makes no provider test call and renews no previous evaluation budget.
