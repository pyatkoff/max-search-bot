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
