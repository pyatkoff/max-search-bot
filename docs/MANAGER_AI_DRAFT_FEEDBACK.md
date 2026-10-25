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

Prepared replies from ChatGPT use the same private provenance when the assigned
manager explicitly opens their preview. Readiness polling and worker saves remain
read-only with respect to feedback. `preparation_job_id` identifies that source;
reopening the same job as the same manager reuses its immutable feedback row.
Fresh session, ownership and context are rechecked before recording. Insertion
and sending use the existing composer reference and canonical outbound recorder.
A storage outage leaves the reply usable with unknown attribution. Viewing alone
does not mean use. Prepared counts are previewed proposals, not every worker save.

## Read-only usage report

`ManagerAiDraftFeedbackReport` reads a fixed seven-day UTC generation cohort
through the existing trusted production-diagnostics CLI. It performs no request
authorization bootstrap, read-marker update, data repair, provider call or DDL.
The report owns its read transaction and refuses a borrowed transaction. Explicit
test conversations and other projects are excluded. At most 1,000 draft rows and
5,000 canonical outbound manager-message rows are inspected, with one extra row
to detect each limit. Limits, errors and incomplete coverage are explicit;
unavailable counts are not replaced with zero and no percentages are inferred.

Persisted proposals are not all AI requests: proposal recording is best effort.
Reply proposals and proposals without a reply are counted separately. An insertion
request counts each draft once. Linked draft count is distinct from linked message
count because the same draft can accompany more than one real canonical send.
Only valid historical conversation, actor, exact project, metadata and timestamp
bindings qualify. Historical links are not rejected merely because the current
owner or current 24-hour reference eligibility has changed. Unresolved references
outside the captured cohort and malformed bindings remain explicit. Unlinked text
does not mean the manager never used AI, and a still-open reference window is not
evidence of rejection.

`production_snapshot.php` exposes a fixed aggregate summary as
`manager_ai_feedback`; `compose_autopilot_snapshot.php` projects only allowlisted
summary fields into the public snapshot. Private samples are separately held in
`manager_ai_feedback_private`, which the public sanitizer always removes. The
existing encrypted evidence command accepts this same production snapshot with
`--production`, verifies its SHA/freshness and exports only allowlisted redacted
fields. No plaintext sample or raw draft/message/conversation identity is
published. Per-capture aliases cannot correlate people across captures.

At most ten recent verified linked messages have private comparison samples,
bounded to 2,000 characters per proposed/current text with explicit truncation.
Recorded equality describes the initial send. Later message edits preserve that
provenance but replace the current stored text: the report checks the initial hash
and labels whether the initial text is still available. A changed current text
must not be presented as the original send or used as a training target without
review. Samples are neither random nor representative, and none is automatically
added to instructions, company knowledge or training.

The summary separately counts prepared proposals and linked prepared messages
(unchanged or changed). Private samples label `prepared` versus `generated` and
retain the proposed/current texts for review. Source job identities stay private.

## Owner review of real applications

`/manager2/ai-usage.php` (also linked from team Analytics for the existing owner
role) reads the canonical draft/send provenance through `ManagerAiUsageService`.
It pages20 valid applications in current owner projects, over7 or30 days; each
read scans at most1000 candidate records in40-row batches and advances a bound
keyset cursor across malformed or unavailable records. Scope and session are
checked freshly; cursor access/filter/actor bindings expire after one hour.
Test/internal-owner cases and unsupported/media provenance are excluded. A
viewer or foreign project never gains access through a saved report link.

The report shows the proposal, actual author's message and last tourist message
before preparation, with explicit later-edit/unavailable/truncation labels. It
never represents a changed current text as the original sent version. Original
send equality remains distinct from later edits and from quality. The existing
seven-day diagnostic counters and ten private samples keep their contract.

Forward migration061 stores only `(manager_id,message_id,case_revision,
reviewed_at)`. An explicit CSRF-protected command marks the exact comparison
viewed; it does not acknowledge chat, assign clients, generate or send. Marks
are private per owner and shared across that owner's devices. A later changed
comparison reappears; a stale tab cannot mark its newer revision viewed. A lost
response is checked by refreshing, without automatic mutation retry. Deleting
the canonical message/account cascades its minimal review metadata. No new
capture, learning queue, model work or scheduled report is introduced.

Only the accepted page hydrates tourist questions, in one bounded SQL statement
for at most20 applications. Every target retains its own conversation and draft
message cutoff; several applications in one dialogue must not share a single
latest-question lookup. Edit labels still compare against each proposal's own
generation time. Missing text, Unicode bounds and single HTML decoding are
unchanged. The actual-PDO regression checks constant query growth and complete
output equality against the pinned installed source in SQLite and native MySQL;
query-count reduction is not a production-latency measurement.
