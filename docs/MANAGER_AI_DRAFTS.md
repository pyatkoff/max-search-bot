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
  `OPENAI_API_KEY`, feature-specific model/reasoning/tier, strict JSON schema, `store:false`, no tools,
  no redirects, bounded response and a 20-second timeout. No automatic retries.
- `workspace-v2-ai-draft.js`: preview, stale-result fencing and explicit insertion
  through `WorkspaceV2Conversation.insertQuickReply`; no new send implementation.

The draft profile defaults to `gpt-6.1-sol` with explicit
`reasoning: {effort: "low"}` and `service_tier: "fast"`. Optional
`MANAGER_AI_DRAFT_MODEL`, `MANAGER_AI_DRAFT_REASONING` and
`MANAGER_AI_DRAFT_SERVICE_TIER` constants affect this feature only; the bot's
`OPENAI_MODEL` is neither read nor changed by the draft client. Supported draft
efforts are `low`, `medium`, `high`, `xhigh` and `max`; `none`, `minimal` and
malformed values fail closed. The tier override allows only `default`, `fast`
and its alias `priority`; `auto`, `ultrafast`, other tiers and malformed values
fail closed. No runtime config change is included in this slice.

[Official model documentation](https://developers.openai.com/api/docs/models/gpt-6.1-sol)
confirms these efforts. With reasoning enabled, the
[GPT-6.1 Sol migration guidance](https://developers.openai.com/api/docs/guides/latest-model?model=gpt-6.1-sol)
excludes sampling parameters such as `temperature`; none are sent.
[Fast mode](https://developers.openai.com/api/docs/guides/fast-mode)
uses `service_tier: "fast"` (or `priority`) and is
[priced at twice Standard token rates](https://developers.openai.com/api/docs/pricing)
for this model. Actual service can fall back to Standard; requested tier does
not prove the served tier or a specific latency. Omitting `service_tier` means
`auto`, which follows the project's setting; explicitly selecting `default`
requests Standard. The draft client selects Fast explicitly without changing
the bot or project settings. Existing timeout/token/byte limits remain in force;
compare quality, actual served tier and elapsed time on private representative
cases before claiming a speed improvement. Synthetic regressions do not call
the provider.

For isolated evaluation, `requestJson()` and the pure `parseResponse()` accept
an optional third callable. After a completed assistant JSON object is decoded,
it receives only the provider's bounded model name, allowlisted served tier and
nonnegative integer token counts (up to 10,000,000). Missing or invalid model/tier
remain `null`; invalid usage fields are omitted, never inferred from the request.
Only input/output/total tokens, cached input tokens and reasoning output tokens
are projected. No response IDs, instructions, content, raw body or arbitrary
metadata reach this observer. It is not invoked on errors, refusals or incomplete
responses; observer exceptions do not change the result. No observer is installed
in the manager endpoint, and this client does not log or persist the metadata.
The eight-field manager result remains unchanged; its schema and evidence checks
still run separately after decoding.

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

Archive-derived procedural learning is a versioned instruction change, not
weight fine-tuning or automatic ingestion of historical replies. The draft
instructions preserve the bot's existing whole-party budget default unless the
customer explicitly gives a per-person amount; genuine ambiguity between
families/rooms still needs clarification. They distinguish service type, room
plans, actual travellers versus tariff labels, days versus nights and arrival
deadlines. Forwarded posts do not establish the speaker's personal authorship,
customer agreement or current offer facts.

When a question depends on an already supplied attachment or selection, the
manager should inspect it before asking the customer to repeat the same data.
Unread old media must not block an unrelated answer. Neither the customer draft
nor the internal reason may claim that unread media were inspected or contain a
particular document. AI limitations stay in reason/checks, not in the manager's
voice to the customer; a concrete check can be more useful than an empty promise.
These procedures do not approve company facts, learn an individual manager's
style or establish production accuracy. Validate changes on separate past-only
histories with the same fixed model/profile, distinguishing helpful drafts from
justified abstentions and policy-only results from actual provider calls.

The provider payload always receives an empty `verified_company_facts` list in
this slice. A narrow deterministic check of the latest real customer question
about the agency's office/address or a personal visit returns manager checks
without a customer draft or provider call. Prior dialogue claims do not bypass
this boundary. Questions about a hotel's or a consulate's address remain ordinary
conversation analysis. Evaluation records distinguish policy results from actual
model calls.

Model output separates the customer draft from the reason and manager checks.
It can decline a new message after a stop, purchase, thanks without a new
question, or a pending promise to check options. It must not invent prices,
availability, policy, hotel properties, payment details or completed actions.
Schema/evidence validation cannot prove factual correctness: the manager reviews
every draft. Links and attachments are not opened; historical offers are not
presented as current availability.

`no_contact` is advice about the proposed message, not a status change or a
permanent prohibition. This slice does not implement timed follow-up scoring,
automatic tour selection, weight fine-tuning, personal style learning or autonomous sends.

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
