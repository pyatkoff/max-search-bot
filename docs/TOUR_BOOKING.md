# Selected tour to booking in Manager2

“Оформление бронирования” opens lazily from an exact liked native offer or a
retained web/search inquiry in the same customer card. It does not choose the
latest draft or transfer a forwarded visitor's original customer identity.
The original quoted price and conditions remain immutable; checked full-party
price and agreed terms are separate manager-entered facts.

The manager records:
1. checking — missing facts remain empty;
2. ready — full amount/currency, availability, verification source/time, complete
   conditions and the customer's agreement evidence are required;
3. confirmed — also requires the operator, actual booking reference and confirmation
   instant;
4. cancelled — requires a reason and retains the preceding immutable versions.

Ready verification is at most 24 hours old. A demo can be investigated but cannot
be marked ready or confirmed. Confirmation is a staff attestation, not supplier
API verification, payment, a sale event or an automatic booking. No passport or
card data is requested. Operator submission and payment remain the existing
manager's explicit external work; this surface never sends them.

`TourBookingService` appends versioned records to existing private conversation
events; no runtime DDL, new queue, scheduler or second transcript. The current
account and conversation are locked before canonical assignment/visibility
authorization repeats. Optimistic revision checks reject a colleague's stale
update. Account/conversation/source operation receipts reconcile a lost response
without another write. The browser retains an opaque pending operation before
submission; UNKNOWN is read-only across reload until that original receipt is found.
Changing customer/project/channel binding rejects adoption of the old record.

The existing protected pipeline API owns auth/CSRF. The tour-choice module owns
the lazy form in both presentations. Opening the workspace performs no booking
read; customer send, routing, shifts, pipeline sale and advertising goals are unchanged.

Required regressions cover immutable source binding, authorization, revisions,
receipts, all state/evidence gates, demo rejection, cancellation and changed
customer context. Existing native Chromium/WebKit Manager2 tests exercise the
actual form at mobile/desktop widths, readonly access and uncertain-save recovery.
Synthetic fixtures do not prove that a real tourist booked or that a supplier
accepted a reservation.
