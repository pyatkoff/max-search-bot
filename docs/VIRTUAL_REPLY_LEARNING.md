# Private virtual replies and later review

The learning unit is a real tourist question, its bounded preceding context, our
exact saved virtual reply, and a later independent review of that reply. Manager
messages are context, not approved target answers. The actual continuation of a
conversation is not a response to a virtual answer that was never sent.

This flow is separate from ordinary prepared replies and `messages`. It does not
send, mark read, assign a manager, change shifts, route leads, or invoke a model
in message intake. Archive imports are replay data, not new live conversations.

## Shared policy and implementation boundaries

`TourConsultationPolicy` owns the common tourist-intent and consultation rules.
`ManagerAiDraftService` and both `VirtualReplyProtocol` roles consume the same
policy text; role-specific evidence, output schemas and operational permissions
remain separate. A shared policy edit therefore changes all affected instruction
revisions. Active/completed virtual attempts retain their frozen contracts;
ordinary prepared work retains its existing revision/freshness fences.

This closes the previous gap in which a validated virtual lesson did not update
the ordinary draft instruction. Learning-specific content/noise filters stay separate from message intake. The
shared internal-owner exclusion also fences automatic preparation, as described
below. Publishing instructions still
requires separate runtime verification and does not prove improved model quality.

The fixed, owner-confirmed bot demo dialogues are internal work, not tourist
questions. `InternalOwnerDialogueScope` owns their exact project/channel/
conversation scope. The reader filters them before discovery limits and again
on snapshots; Telegram archive exclusion follows only the same bound private
channel identity, not every peer in an approved account. Normal tourists named
like the owner or asking about cards remain eligible. Existing captured internal
cases are removed through bounded source reconciliation and its normal private
payload/result purge, without resetting consumed quotas or unknown attempts.
This changes neither message intake nor dialogue visibility, routing or demo
interactions. Manual owner tests remain explicit separate work.

Explicitly adult children or relatives count as adults. Preliminary selection or
price comparison does not require their exact ages without a concrete, confirmed
age condition. Preserve known ages; clarify an unknown minor's age or genuinely
ambiguous age category briefly when needed for calculation, while retaining any
independent useful answer. This does not forbid age/date-of-birth collection when
actually needed, for example to book or check a confirmed offer condition.

Synthetic calibration examples for source review, not a model-quality run:

| Context | Expected boundary |
| --- | --- |
| Children are explicitly adults, age 22 | Count adults; do not request their ages again for preliminary room/price comparison. |
| A child is 15 | Preserve the known age for calculation; do not ask again. |
| A minor is mentioned without an age | Ask their age briefly when needed to calculate. |
| Only “children” is stated, with no age category | Clarify whether they are adults or minors; do not infer either category. |
| A verified offer has a specific age eligibility rule | Request only the age information needed to check that rule. |

## Roles

| Role | Model | Reasoning | Tier |
| --- | --- | --- | --- |
| Author | `gpt-6.1-sol` | `low` | Standard (`default`) |
| Reviewer | `gpt-6-astra` | `high` | Standard (`default`) |

Profiles, instructions, schemas and their revision are private, versioned job
data. Existing `MANAGER_AI_DRAFT_*` settings are unchanged. Review has its own
claim and is available at least 60 seconds after the author's saved result.
It receives the same frozen source prefix plus that exact saved result. No
future messages, current external searches, invented reactions or manager
answer as a gold label are supplied.

Changing current instructions does not erase claimed attempts, saved answers,
reviews or lesson provenance. Those jobs retain their frozen contracts; only
untouched pending author work can be recreated under new instructions. A frozen
profile/schema that the current worker cannot support is explicitly blocked
before a new claim, preserving the existing evidence. Source edits, deletions
and revoked permissions still invalidate and purge dependent private content.

For a ChatGPT worker, selecting the requested model and reasoning is the worker's obligation.
The saved declaration is explicitly `worker_declared`, `provider_verified=false`;
it does not prove provider identity, token usage or billing. ChatGPT delegation
does not expose an API service tier: declare `service_tier=unknown`, preserving
that unknown value rather than claiming Standard was observed. The profile's
`default` tier is the explicit setting for the optional direct Responses path.
The optional isolated
Responses client has separate request profiles, `store=false`, bounded time and
bytes, and no retry. No capture, MCP call or default CLI command invokes it.

## Capture and integrity

`VirtualReplySourceReader` reads ordinary customer content in the current project
and permitted Telegram archive scopes. Empty starts/callbacks, test dialogs and
non-customer service events are excluded. A snapshot contains at most 120
messages and 40,000 text characters. Personal identifiers are redacted before
storage; raw scoped hashes detect edits without exposing source identity.
Telegram's [service-notification peer](https://core.telegram.org/constructor/updateServiceNotification)
is excluded by its canonical identity, independently of wording or legacy role.
Standalone technical test probes and the explicit Russian/English Telegram
account-login notification templates are also excluded, including from retained
prefixes: old private-chat imports may label them as customer messages. This is
a narrow content boundary, not a general spam classifier; travel tests, booking
references and substantive questions remain eligible. Discovery cursors still
advance over excluded rows. Existing captured copies are invalidated/purged by
the ordinary bounded reconciliation and claim/context/save/read rechecks.
Raw prefix lengths are checked before loading values, with at most 262,144
length units (at most 1 MiB of UTF-8) in the retained suffix. Oversized earlier
metadata shortens the prefix and marks history incomplete. A question that cannot
fit that raw bound is unavailable. Technical source failures propagate as
sanitized errors; they do not masquerade as deletion or purge stored results.
Missing history, unread attachments and reconstructed text remain explicit
limitations. Only evidence IDs in that snapshot can be cited.

Two independent discovery lanes exist per source. `fresh` bootstraps from a
bounded recent ID window, then advances. `history` starts from zero. Discovery
IDs are not an edit/delete cursor: bounded rotating reconciliation rechecks
captured cases, and every claim/context/save/review read fences source validity.
Edited or deleted data and revoked source permissions invalidate and purge
dependent private context, answers and reviews. An eligible revised question can
be captured again; old results never silently become reviews of new wording.
Purging captured copies is incremental; it does not guarantee immediate deletion
of every previously captured case before a reconciliation pass reaches it.

## Private MCP worker sequence

The existing AnyTour OAuth project-admin boundary exposes separate learning
tools alongside the three ordinary prepared-reply tools. Learning mutations
require an active connection. The event `manager_replies.pending` and ordinary
prepared queue continue to represent ordinary manager replies only.

1. `capture_virtual_replies(source, lane, limit)` captures and reconciles at most
   20 source candidates. It makes no model calls.
2. `claim_virtual_replies(role, limit)` reserves work before generation. Limits:
   20 per call and 200 claims per role per UTC day. This is a protective sampling
   limit, not a claim that every incoming question has already been processed.
   Questions from the last 48 hours precede older pending cases. With migration
   051 installed, select one representative per private whole-dialogue group
   before the bounded candidate limit: within the freshness class prefer a
   substantive question, then the latest eligible prefix. Across groups prefer
   those least recently sampled. This prevents neighboring prefixes from
   displacing other dialogues. Fresh capture still precedes historical replay.
3. `get_virtual_reply_context` returns frozen instructions, required profile,
   source prefix and evidence for the exact claim.
4. Run the author on Sol low, or a separate reviewer on Astra high. Pass only
   the returned frozen job data. Return schema-valid JSON; never send it.
5. `save_virtual_reply` saves the exact result and declared model, reasoning and
   tier. A reviewer cannot save an author's result or cite unseen messages.
6. Read metadata using `list_virtual_reply_reviews`; inspect individual cases
   privately using `get_virtual_reply_review`. Unresolved `revise` and
   `insufficient_context` findings are ordered oldest first before the page
   limit; a `pass` is not a finding requiring resolution. The additive
   `requires_disposition` flag identifies those unresolved findings. Remaining
   reviews are newest first. Listing does not record a disposition automatically.
7. After the development pass, `record_virtual_reply_lesson` records `rejected`,
   `duplicate` or `applied` with the reason and validation. Applied lessons require
   a same-repository PR URL; the worker must verify its merged/deployed state and
   separate-case validation before recording that outcome. This is an auditable
   declaration, not an automatic GitHub verification or rule write. Unresolved
   findings remain visible for the next pass.

Claims expire to `unknown`, never back to pending. An uncertain or interrupted
attempt cannot be silently repeated. No old bulk-evaluation allowance is renewed
by this feature. Capture/reconciliation and ordinary manager replies remain
available independently of model work.

## Turning findings into improvements

The reviewer produces concrete findings, a possible corrected reply and a
**lesson candidate**. None is automatically accepted as a fact or durable rule.
The development pass checks the cited phrase and context, determines whether the
cause is an instruction, knowledge gap, source/tool defect or application code,
then changes the canonical owner through the normal PR and verification gates.
Validate a proposed general rule on other cases, including counterexamples;
keep those cases out of the evidence used to propose the rule. A previously
used evaluation set is not a new holdout. Do not optimize for agreeing with a
manager edit or a reviewer score.

Keep transcript text and private job payloads out of public issues, repository
fixtures, Actions artifacts, cron logs and diagnostics. Public change reports
use synthetic regressions and aggregate outcomes. This is an application-level
improvement process; it does not train model weights.

## Explicit destination and optional itinerary changes

A named destination city or resort starts the requested selection; an optional
city-plus-beach or multi-stop alternative is not a missing mandatory condition.
When essential conditions are present but verified offers are not, the virtual
author can return no outgoing text with `prepare_selection` and concrete checks.
This does not claim that any search has run. Needed questions remain appropriate
for an arrival airport without a chosen destination, multiple places, conflicting
requirements or missing essential conditions. Country-only requests can still
need a city/resort question. Reviewer instructions use the same boundary.

Synthetic calibration examples: an explicit Madrid city stay with dates, party,
origin and budget can proceed to selection; a Faro arrival with no chosen resort
can require a location question; a Portugal-only request can require a resort
question; an explicit destination with no budget can still require a budget
question. These are calibration examples, not independent model-quality results.
Changing these instructions versions untouched work only; active and completed
jobs retain their existing frozen contracts.

## Service scope versus time in transit

Preserve whether the tourist requests a package, accommodation only or flights
only. A phrase excluding travel time from the stay length is not evidence that
air tickets should be removed. If the frozen context leaves both materially
different meanings possible, ask one short question about that boundary and
continue independent checks. Explicit service choices and already clear hotel
night counts do not need confirmation. Ordinary colloquial days/nights wording
alone must not create an extra question. The same grounding applies to internal
reason/checks, even when no outgoing draft is generated.

Synthetic calibration: during flight comparison, an unclear stay duration that
excludes the flight needs scope clarification; an explicit hotel-only request
with already purchased tickets does not; seven hotel nights with travel time
explicitly excluded keeps the package; a normal five-to-eight-day package request
keeps its duration range without a terminology question. These are development
calibration examples, not new independent model-quality measurements. Existing
claimed/completed jobs still retain their frozen instructions.

## Concrete comparisons versus general explanations

Choose `next_action` for the material work still needed. A concrete request to
compare dates, hotels or other offer conditions usually requires `check_offer`
or `prepare_selection` when the working conditions are available. Listing future
checks alongside a generic price explanation does not complete that comparison
as `answer_question`. Either action can be appropriate; a useful grounded draft
may accompany it, while no useful text means no outgoing draft. A theoretical
pricing question or a prediction of future price changes alone does not mandate
a selection. Ask only a genuinely missing essential condition.

Synthetic calibration: comparing a package on adjacent departure dates requires
actual price checks; asking whether prices might fall next month does not prove
new dates were requested; a question about a specific flight can use `check_offer`
and retain facts already supported by the context. These are instruction
calibrations, not a new model-quality benchmark. Frozen active/completed jobs
retain their original contracts.

## Whole-dialogue sampling and unseen reserves

Migration `051_virtual_reply_sampling.sql` adds hash-only metadata outside the
frozen payload and source revision. Group conservatively by conversation and
validated customer identity. A bound positive private Telegram peer can connect
live conversations with permitted archive copies across accounts. Group chats,
malformed or uncertain bindings cannot establish an unseen private-person
cohort. This is whole-dialogue grouping, not semantic trip segmentation or proof
that every copy in every source has been discovered.

Allocate at most one author claim per group per UTC day atomically with the
existing role quota. Completed and unknown attempts retain the consumed slot;
merging aliases does not refund role quota or reopen a job. Social-only greetings
and acknowledgments have a separate maximum of 20 groups per UTC day within the
same author quota. They remain eligible; substantive requests containing thanks
are not classified as social-only. New metadata never rebuilds an existing
claimed/completed contract, result or lesson decision.

Existing cases are marked legacy by the forward migration. A bounded attachment
pass links their identities before new author allocation; while active unmapped
cases remain, the additive `sampling.author_blocked_reason` reports
`legacy_backfill_pending`. Capture and eligible independent reviews continue.
Legacy material is development evidence, including old unclaimed cases. Prior
author claims import their already consumed group/day slots. Any unmapped legacy
exposure, including an invalidated row whose identity cannot currently be read,
blocks new reserve enrollment separately. `legacy_unresolved_exposure` and
`reservation_blocked_reason` expose this limitation. Ordinary author work need
not wait for unreadable invalidated sources; a zero reserve can be the correct
outcome, and source permissions must not be bypassed to populate it.

Author allocation shares a bounded 100-row maintenance budget between active
legacy attachment and rotating identity-only recovery of invalidated legacy
rows. Recovery reads permitted project/channel identity metadata and only a
bounded archive bot-key scalar needed for the existing per-bot permission check.
It never reloads message text, media or frozen source payloads. Only complete,
unambiguous aliases can link an old exposure into development evidence; source
status, purged content, frozen jobs, unknown outcomes and quotas stay intact.
Missing/revoked/ambiguous identities remain unresolved. Noncanonical or oversized
archive permission metadata fails closed; technical failures remain errors. The
claim's aggregate `sampling.backfill` reports actual bounded progress.

Only a reliably linked, complete live prefix beginning after enrollment, with no
known exposure or legacy alias, can enter the deterministic roughly one-in-five
reserve. A historical, incomplete, old or newly ambiguous prefix, exposure, or
merging previously distinct groups demotes any affected reserve to contaminated
development evidence. Ordinary author/reviewer allocation, context, private
review and lesson operations cannot disclose reserved groups. Anonymous group
exposure and consumed quota metadata survive source invalidation; private source
content/results retain their existing purge rules. Reserving a group alone does
not run a control experiment or prove independent model improvement.

Capture/reconciliation/claim responses expose aggregate `sampling` status and
counts. A fully absent 051 schema reports `migration_unavailable` for rolling
compatibility; a partial or broken schema is a technical failure. Production
acceptance requires `sampling.status=enabled`, zero pending/checksum failures,
preserved quotas and actual installed source behavior.

## Next quality checks and completion criteria

These are explicit next steps, not measurements already completed. Continue the
existing reviewer-first worker loop and its per-role UTC quota; do not create a
new schedule, call a paid provider, or reopen expired/unknown claims.

1. Verify installed whole-dialogue grouping and diverse selection, including
   linked copies and contamination. Related prefixes are not independent
   successes. Grouping remains conservative and does not establish whole-archive
   discovery or statistical representativeness.
2. Use reserved whole groups for a separately registered control evaluation.
   Keep proposer examples, validation examples and final controls distinct.
   Compare frozen current and previous instruction versions on the same bounded
   prefix with blind answer order and independent review. Any control inspected
   to design a repair becomes development material. Paired registration,
   allocation and review use the separate bounded protocol below with normal
   permission/quota/provenance fences; ordinary review jobs are not that control
   channel. No paired control run is claimed by deployed software or this document.
3. Cover necessary clarification, useful factual response, concrete comparison,
   budget/placement compromises, unread evidence and no-contact decisions.
   Record unnecessary questions, lost constraints, unsupported claims and
   useful-next-action judgments with their denominators and episode counts.
   A batch of clarification-only answers prompts a sampling/usefulness audit;
   it does not itself prove that the questions were wrong.
4. Close the transfer loop for each accepted lesson: shared instruction/knowledge
   owner, separate-case and counterexample validation, exact-head CI, installed
   version, actual relevant runtime observation, then private disposition.
   Old unresolved findings need explicit missing evidence or a concrete repair;
   new pass reviews must not be treated as lessons requiring disposition.

An initial comparison can support only a bounded result for its specified cases.
Model acceptance rate, number of claims, deployed prompt text and a manager reply
are not substitutes for measured improvement or proof of whole-archive coverage.

## Explicit paired control protocol

Migration `052_virtual_reply_controls.sql` stores a separately registered bounded
experiment. Registration freezes two distinct supported author contracts and an
independent reviewer rubric before allocating at most 20 previously unused
reserved whole groups. The normal capture path never registers an experiment.
The provider-free administrator command accepts local contract JSON files:

```
php tools/virtual_reply_learning.php control-register baseline.json candidate.json 1 admin_id
php tools/virtual_reply_learning.php control-status
```

Each contract has the exact `role`, `instructions`, `profile`, `schema` and
`instruction_revision` returned by
`VirtualReplyControlProtocol::authorContract(instructions)`. Pin the baseline to
the actual previously deployed instruction, not a rewritten imitation, and pin
the candidate before generation. Registration validates hashes, schema and model
profile; it does not verify GitHub/deployment provenance for the operator. The
operator must record that separate evidence. Invalid files are rejected before
configuration/database access. Output contains only safe aggregates, run IDs and
revisions. No CLI operation calls a provider.

The seven existing MCP tools retain their default learning behavior. The separate
active-admin OAuth tool `register_virtual_reply_control` accepts those two exact
contracts plus a bounded limit. It makes registration possible through the
connection without remote shell access. Verify prior/current deployment and
instruction provenance separately before calling it; registration itself makes
no model call. No normal capture or claim automatically registers an experiment.
The existing MCP HTTP request limit of 196,608 bytes still applies to the complete
registration request, independently of the adapter's additional contract limits.
Explicit
`claim_virtual_replies(purpose="control", role=...)` allocates only registered
control jobs. Context/save routes each opaque job ID to its own store; a control
failure never falls back to the ordinary queue. Each author receives the same
frozen source prefix and evidence with one frozen contract. The private random
A/B mapping is fixed at registration. A reviewer becomes available only after
both exact author results are saved and at least 60 seconds have elapsed after
the later completion. It receives only the original prefix, exact A/B results,
evidence and its independent contract/profile, with no version mapping, author
profiles, completion times, future conversation or manager gold answer.

Use separate Sol-low author agents and an independent Astra-high reviewer agent,
each with no inherited conversation. Never review an answer generated during the
same worker pass. Control claims use the existing 200-per-role/UTC-day ledger;
both authors consume a slot. Unknown outcomes exclude the pair permanently and
are never retried or refunded. Current instruction changes alone do not replace
frozen control contracts. Source changes, deleted/revoked evidence or group
contamination purge both arms, review, private mapping and tokens. Normal source
capture/reconciliation and alias merges invoke the same purge boundary, and
every control read/save/claim rechecks source validity. Technical failures do not
masquerade as deletion.

`list_virtual_reply_reviews(purpose="control")` lists aggregate run status;
supplying its `run_id` returns paired metrics. Count unnecessary questions, lost
constraints and unsupported claims, plus useful next actions, with matched
denominators, distinct groups, unknown/invalidated/incomplete exclusions and
unassessable metrics. A null assessment excludes both arms from that metric's
denominator. Duplicate groups cannot inflate a report. A registered run, deployed
protocol or successful synthetic pair is not an observed model-quality gain.

The connected tool catalog must actually advertise `register_virtual_reply_control`
and the optional `purpose`/`run_id` inputs before a worker uses them. If it does not, report that exact
connection refresh blocker without bypassing OAuth or simulating a run. The
ordinary seven-tool learning loop and source capture can continue independently.

## Operations

Migrations `049_virtual_reply_learning.sql`, `051_virtual_reply_sampling.sql` and
`052_virtual_reply_controls.sql`
own the learning tables. Runtime code does not create schema. No additional cron
is installed by this change. The CLI
has two explicit, bounded, provider-free maintenance operations:

```
php tools/virtual_reply_learning.php capture conversation fresh 20
php tools/virtual_reply_learning.php capture telegram_archive history 20
php tools/virtual_reply_learning.php reconcile 20
```

Its output contains only aggregate counters. A deployed table/client/profile is
not proof that a scheduled worker is connected, that Astra actually ran, or that
all historical/new dialogs have been processed. Confirm these separately with
private saved jobs and actual worker execution evidence before reporting them.

Release shared publication coordination promptly after the mandatory exact-head
CI, deploy/diagnostic/provenance gates and minimal relevant runtime verification.
Remaining private author/reviewer saves are separate work and must not hold the
publisher. Quota exhaustion or a pending private control experiment is reported
truthfully; neither justifies bypassing gates or delaying unrelated publication.
