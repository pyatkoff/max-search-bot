# Product Contract

This document keeps durable product decisions out of issue comments and chat history.

## Primary outcome

The bot should move a tourist from intent to a useful next step with as little friction as possible. During staffed working hours the preferred conversion is a successful manager handoff even when no phone has been collected. When live response is not available, preserve the lead through self-service tours/site and optional phone capture.

## Priority outcomes

For product decisions, optimize in this order when the context allows it:
1. useful manager conversation / manager reply;
2. tour results opened with correct parameters;
3. phone captured when manager response is unavailable or delayed;
4. site/channel continuation where appropriate;
5. reduction of repeated questions and unnecessary turns.

Do not optimize a micro-conversion at the cost of a higher-value handoff.

## Working-hours handoff

Working window: 10:00–20:00 Europe/Kaliningrad.

During this window:
- a manager request is sent to the working queue without making phone mandatory;
- if manager availability is positively known, the UX may say so;
- if availability cannot be proven in advance, say truthfully that the request was passed to the working queue;
- after 5 minutes without a manager reply, the existing fallback may offer phone capture once;
- phone capture must remain optional rather than becoming a gate before routing.

Outside the working window:
- prefer useful tour/site continuation;
- phone may be offered as optional callback data;
- promise only a truthful next-working-period response expectation.

## Dialogue quality

The bot should not ask again for information already supplied and should tolerate ordinary natural-language variants and common typos when deterministic recognition is safe.

Confirmed live defects should become regression scenarios using the exact customer wording where practical.

AI is a comprehension tool, not the owner of business state. Known deterministic values and canonical state transitions must remain reliable without relying on AI extraction.

## Manager experience

Manager Workspace V2 is the target workspace. It should help the manager answer the most important lead quickly, understand the original tourist conversation, see structured trip/contact/source data, and manage sales stage/tags/outcome/tasks without confusing these with the technical dialogue state.

Important Inbox signals include:
- unread messages;
- first-reply waiting time and 5-minute risk;
- trip summary;
- sales stage/tags/outcome;
- next task/reminder;
- current owner/technical handoff state.

## Media

Customer ↔ manager communication should support photo, video, audio/voice and common files through the same protected conversation model. Text-only behavior must remain intact when media is unavailable.

## Guardrails

Do not change Yandex Metrica goals/counters or the existing lead-sending mechanism as part of product/refactor work unless the user explicitly requests that exact change.

Do not automatically change manager shift state to improve queue health. Push health and working status are separate operational facts.
