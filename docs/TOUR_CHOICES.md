# Sent offers and tourist preferences

This is the first slice of the owner-approved card → preference → Manager2 path.
It records what was offered and what the tourist explicitly liked. It does not
book a tour, verify a current price, request a manager or change lead routing.

## Immutable conditions

`TourChoiceService` uses the existing private `conversation_events` store. A
prepared offer, verified native delivery and first preference are separate
append-only events; there is no request-time DDL or new schema. Each version has
its own callback and preserves the hotel, departure, dates, nights, party and
duplicate child ages, room, meal, flight description, amount/currency, price
basis, demo marker, rationale and compromise. The source's later price changes
cannot rewrite the sent version. `price_verified` remains false in this slice.

Preparing an offer is not delivery. The sender must verify the native creation
and register its exact private message/chat IDs before a preference is accepted.
A timeout or unknown message creation must never authorize a retry. The separate
owner trial retains durable once receipts, independently proves both owner
conversations and never modifies the existing visual-preview messages/receipts.

## Callback and access boundary

Only the new `tour_like_` callback family is handled. An ordinary text
«Нравится», a demo preview button or an unrelated callback does not imply a
specific saved preference. The store checks the server-selected project, active
conversation, channel, external customer, private native chat and original
delivered message. Repeated callbacks preserve the first saved choice and do not
repeat manager notifications. AI/manager ownership and operator shifts remain
unchanged; no phone request, queue transition or booking is introduced.

The existing native callback acknowledgement runs before preference handling.
The manager's conversation is still readable if the optional choice projection
is unavailable. Its read runs only after the existing conversation visibility
check and returns no callback token. The customer card shows liked offers with
their exact conditions and an explicit demo/unverified-price notice. Callback
technical payloads are labelled as button presses in the manager transcript.

## Agreed next sequence

1. Verify this bounded preference path in the owner's existing MAX and Telegram
   conversations and confirm the exact chosen variant in `/manager2/`.
2. Add a branded public selection page which opens without registration, retains
   the same offer versions and returns the selected variant to the conversation.
3. Add actual flight/price verification for the selected offer, showing check
   time and explaining changed or unavailable terms. Search price and a confirmed
   final price remain distinct.
4. Add measured selection-page opens and hotel-detail views separately from the
   explicit preference. Neither delivery nor an open is a sale.
5. Reconcile the saved offered versions, customer reactions and evidenced manager
   corrections into the existing dialogue-learning workflow. No automatic lesson
   approval or new learning schedule is introduced by this slice.

The existing Telegram/MAX native card formats remain under the owner's visual
acceptance. A full complex flight chooser, price-watch subscription, automatic
follow-ups and a separate analytics/CRM product are later work.

## Verification

Required focused regressions cover immutable conditions, native-message and
conversation binding, rejected/duplicate callbacks, preserved acknowledgement
and routing boundaries, safe manager rendering and the fixed owner-only trial.
CI also runs the canonical required suite and relevant Manager2 visual checks.
Source/CI, deployed runtime, owner trial delivery and the owner's actual click
are separate evidence; report only the stages that have really completed.

## Inquiry from a shared web variant

The owner's 23 October clarification routes the selected public variant straight
to the existing Telegram/MAX bot instead of collecting website contact details.
The public link identifies its original selection, not the visitor. A verified
native start saves the exact shown public conditions in the actual sender's
conversation; a forwarded visitor never acquires the original customer's history,
contact or identity. Known native clients need not supply their contact again.

`tour_web_inquiry` is separate from prepared/delivered/native-liked events. Its
immutable publication/version/card and public quote live in the existing private
event store. A labelled inbound action in canonical messages is committed in the
same transaction, keeping normal Inbox unread/activity and response facts useful.
It has no fabricated native MID and does not change the strict `tour_like_`
envelope or authorize any native card send. Manager2 exposes the allowlisted quote
under “Обращения из веб-подборки”, apart from “Понравившиеся туры”.

Origin/target locks serialize equal native inquiries. Current project/source and
publication/link checks reject revoked or updated capabilities before saving.
The previous quote is not rewritten by a later update. Failed handoff recovery
reuses the saved intent; a durable queue-transition receipt gives only its creator
the initial canonical greeting. Rejection and already-owned inquiry acknowledgements
also save their once intent before sending and never repeat an UNKNOWN outcome.
An open/page visit is not an inquiry, a like or a sale.


## Selection activity projection

`TourChoiceService::selectionActivity` is read-only and called only after fresh
canonical publication visibility checks. It intersects existing native like
receipts with that publication's exact offer IDs, and aggregates canonical
`tour_web_inquiry` receipts for that publication within the same project/window.
The bounded query returns only counts/limit flags, never another visitor's
identity, contact or public-offer payload. Original inquiry history stays with
the actual native sender's conversation. A public page/hotel observation or bot
link click is an anonymous intent in the separate058 projection; it cannot call
`like`, become a trusted callback, create an inquiry, or assign the original
customer. Old publication/offer versions and strict native envelope checks keep
their existing owners.
