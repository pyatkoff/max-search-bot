# Private virtual replies and later review

The learning unit is a real tourist question, its bounded preceding context, our
exact saved virtual reply, and a later independent review of that reply. Manager
messages are context, not approved target answers. The actual continuation of a
conversation is not a response to a virtual answer that was never sent.

This flow is separate from ordinary prepared replies and `messages`. It does not
send, mark read, assign a manager, change shifts, route leads, or invoke a model
in message intake. Archive imports are replay data, not new live conversations.

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
   Questions from the last 48 hours precede older pending cases, preserving FIFO
   order within each group so the historical replay backlog cannot displace new
   questions merely because it was captured first.
3. `get_virtual_reply_context` returns frozen instructions, required profile,
   source prefix and evidence for the exact claim.
4. Run the author on Sol low, or a separate reviewer on Astra high. Pass only
   the returned frozen job data. Return schema-valid JSON; never send it.
5. `save_virtual_reply` saves the exact result and declared model, reasoning and
   tier. A reviewer cannot save an author's result or cite unseen messages.
6. Read metadata using `list_virtual_reply_reviews`; inspect individual cases
   privately using `get_virtual_reply_review`.
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

## Operations

Migration `049_virtual_reply_learning.sql` owns persistent tables. Runtime code
does not create schema. No additional cron is installed by this change. The CLI
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
