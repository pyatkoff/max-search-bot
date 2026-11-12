# Product Contract

## Owner clarification — tour choices, 2026-10-21

The agreed immediate path is native cards in Telegram/MAX → an explicitly liked
offer with its exact sent conditions → that preference in the existing Manager2
customer card. Preserve each offer version, whole-party amount/currency, demo
marker, rationale and material compromise. A preference is not a booking or
proof of a current final price. See `docs/TOUR_CHOICES.md` for implementation and
the next bounded sequence: shareable selection, selected-offer flight/price
verification, measured interest and evidence-backed dialogue learning.

This document keeps durable product decisions out of issue comments and chat history.

## Primary outcome

The bot should move a tourist from intent to a useful next step with as little friction as possible. A manager request enters the existing queue at any hour without mandatory phone capture. Outside staffed hours, keep the handoff with a truthful response expectation; self-service is an optional continuation. Contact/manual-phone actions remain customer initiated.

## Priority outcomes

For product decisions, optimize in this order when the context allows it:
1. useful manager conversation / manager reply;
2. tour results opened with correct parameters;
3. optional phone captured through a customer-initiated contact/manual-phone action;
4. site/channel continuation where appropriate;
5. reduction of repeated questions and unnecessary turns.

Do not optimize a micro-conversion at the cost of a higher-value handoff.

## Working-hours handoff

Working window: 11:00–21:00 Europe/Moscow.

The current handoff contract applies at every hour:
- every explicit manager request and non-hotel manager-first source enters `waiting_manager` at any hour, before customer confirmation; phone capture is optional and never gates handoff;
- outside working hours, keep that handoff and give a truthful next-working-period response expectation; self-service remains an optional continuation;
- hotel-channel and feed entries collect useful missing trip needs first; entry alone neither promises a manager nor enters `waiting_manager`, while an explicit manager request still uses immediate handoff; once enough conditions are available, the qualifying flow uses canonical manager handoff before confirming it;
- send the initial manager-entry greeting only for a new handoff; waiting or assigned conversations keep their owner, quiet source reentry and no duplicate queue events or push;
- after the initial manager-entry greeting, ordinary customer messages go to the existing manager/queue with notification and no bot reply, including legacy phone-step messages and typed numbers;
- automatic five-minute phone offers are disabled; explicit contact/manual-phone actions remain customer initiated.

During staffed hours, the UX may assert manager availability only when it is positively known. Otherwise, confirm only that the request was passed to the existing queue. Presentation never changes operator shifts or routing eligibility.

## Dialogue quality

The bot should not ask again for information already supplied and should tolerate ordinary natural-language variants and common typos when deterministic recognition is safe.

Confirmed live defects should become regression scenarios using the exact customer wording where practical.

AI is a comprehension tool, not the owner of business state. Known deterministic values and canonical state transitions must remain reliable without relying on AI extraction.

## Manager experience

Manager Workspace V2 is the target workspace. It should help the manager answer the most important lead quickly, understand the original tourist conversation, see structured trip/contact/source data, and manage sales stage/tags/outcome/tasks without confusing these with the technical dialogue state.

Important Inbox signals include:
- unread messages;
  Counts include actual inbound customer messages and attachments, including
  historical/malformed metadata and literal text that resembles a button token.
  Recorded callback/start/bot_started actions stay visible in history but do not
  add unread messages. Counts, first-unread seeking and display acknowledgement
  use the same canonical customer-message population. Reading and replying are
  independent: reading never settles reply work, and “Ответ не требуется” never
  marks unseen messages read. Mine and the sales board use the current reply
  decision (including recurring questions), then the canonical task rank/deadline;
  unread volume does not rank conversations. Waiting retains its current-wait
  chronological queue. Delivery suspension continues to suppress automatic reply
  urgency without changing human tasks or operator shifts.
- current-reply waiting time and 5-minute risk;
- trip summary;
- sales stage/tags/outcome;
- next task/reminder;
- current owner/technical handoff state.

Quoted replies and forwards retain their captured text separately from the saved
original. The existing bounded, authorized original-message reader compares text
only: equal text, a matching potentially truncated excerpt, different text, no
comparable text, or unavailable original. Differences do not prove who edited
which version; an unavailable row never proves provider deletion. The comparison
does not fetch a provider, replace historical quotations, acknowledge messages,
or alter the reply draft. A refreshed quote/context invalidates a late read.

The conversation materials overview reads saved messages across the available
conversation history. It separates files, literal web links and explicitly
recorded sent offers, with sender, recorded date and a link to the original
message/context. Reading is bounded and continues across empty partial pages;
only an exhausted cursor means the saved history has been inspected. Later
arrivals appear after an explicit refresh. The overview does not fetch provider
media, mark messages read or create a new offer. Its modal and original-message
navigation retain the current reply, selected files and transcript position.

## Media

Customer ↔ manager communication should support photo, video, audio/voice and common files through the same protected conversation model. Text-only behavior must remain intact when media is unavailable.

## Guardrails

Do not change Yandex Metrica goals/counters or the existing lead-sending mechanism as part of product/refactor work unless the user explicitly requests that exact change.

Do not automatically change manager shift state to improve queue health. Push health and working status are separate operational facts.


## Owner correction — 2026-09-29

Every explicit manager request (button or recognized text), and a non-hotel manager-first source, enters `waiting_manager` at any hour, before attempting a customer confirmation. Night hours affect response expectations only; phone is optional. Already waiting/manager-owned conversations keep ownership and do not receive duplicate queue events or push. Operator shifts and routing eligibility remain unchanged. This supersedes the older outside-hours self-service-only policy above.


## Owner correction — quiet manager waiting (2026-09-29)

After the initial manager-entry greeting, ordinary customer messages go to the existing manager/queue and trigger manager notification without a bot reply. This includes legacy phone-step conversations and typed phone numbers. Automatic five-minute phone offers are disabled; no scheduled bot followup should ask for a number while a manager is awaited. Explicit contact/manual-phone actions remain customer initiated. This supersedes the older automatic phone fallback policy.


## Owner correction — one greeting per handoff (2026-09-29)

A manager-source entry sends the initial concise greeting only when it creates a new handoff. Reopening a source link while already waiting or assigned is silent, including after a manager has replied. Preserve the genuine `bot_started` recovery event and entry attribution without changing ownership, queue or transcript. This supersedes the earlier acknowledgement on every source reentry. Night entry retains truthful working-hours wording.


## Owner correction — hotel-channel needs first and Moscow time (2026-11-04)

Hotel-channel and feed entries first establish the missing trip needs through the
existing dialogue flow. They do not, by themselves, promise a manager or enter
`waiting_manager`. Use the related publication as context, preserve the tourist's
explicit conditions and ask only useful missing questions. A direct request for a
manager still uses the existing immediate handoff; a waiting or assigned dialogue
keeps its owner and quiet reentry behavior. When enough conditions are available,
the qualifying flow uses the canonical manager handoff before confirming it.

Manager-facing dates, task calendar days and user-visible event times use
`Europe/Moscow`. Project a validated server recording receipt as an explicit instant
when available; historical bare DATETIME values have mixed bases and must not be
bulk-shifted or silently declared UTC. Preserve stored values and the original
physical working interval, now expressed as 11:00–21:00 Moscow time. This does not
change operator shifts, runtime server clocks or local travel departure/arrival times.
