# Native offer cards

`TourOfferCardRenderer` is the pure presentation owner for an immutable
`TourChoiceService::normalize` snapshot and its prepared `tour_like_…` callback.
It builds the working Telegram slideshow and MAX three-image message caption:
bold hotel and whole-party price, four condition icons, a native callback button,
an explicit unverified-price footer and, where applicable, an illustrative-room
label. It does not verify current availability, fares or photos of a booked room.

Dates, departure, party including repeated child ages, room, meal, flight text,
whole-party amount and currency come from the normalized snapshot. Destination,
ordered cover/beach/room source URLs and room-photo status are explicit
presentation metadata. Optional inclusion statements are display input, not yet
part of the saved choice contract: a future manager sender must archive these
before using them as quote conditions. The fixed functional trial supplies none.
The renderer performs no network access; a future delivery owner must enforce
its own authorized source/download policy rather than fetch arbitrary metadata.

## Fixed owner photo-choice trial

`tools/owner_tour_photo_choice_trial.php` is CLI-only (HTTP 404) and accepts only
`--expected-sha=<installed-main-SHA> --confirm-owner-photo-choice`. The additive
workflow command is `/owner-tour-photo-choice-trial <installed-main-SHA>` on
issue 55, with the existing exact owner identity, current main and installed
SHA fences. No recipient, content or source URL can be supplied through the CLI.

It sends one fixed Imperial demo to each independently verified owner private
conversation, using the already exercised native Telegram slideshow and MAX
mosaic transports. All core terms remain demonstrative. The three real photos
come from fixed official hotel URLs. The room image is explicitly illustrative;
the trial makes no extra transfer, insurance or verified-flight claims.

For each channel the trial prepares a separate canonical offer, receives its
callback, uploads the three photos, permanently reserves native creation, verifies
the returned Telegram rich message or reads MAX back by its native MID, and only
then calls `TourChoiceService::recordDelivered`. An actual owner press is handled
by the existing callback/storage/Manager2 path. The script does not synthesize a
press or claim that delivery proves a natural like or Manager2 observation.

The private directory `/var/www/anytoour/data/owner-tour-photo-choice-trial`
uses 0700/0600, file locks, inode checks and fsynced bounded receipts. The
specification binds exact terms, source URLs and independently proved identity;
the creation hash binds the callback body and actual uploaded input byte hashes.
Both channels use the same ordered downloaded files. MAX upload tokens are saved
with those hashes before creation. Native proof checks exact caption, bold spans,
button payload, recipient/bot/chat/MID and three photo attachments. Native photo
IDs do not provide a reverse proof of the uploaded image bytes.

Both receipts are inspected before new preparation/upload/creation. Unknown or
reserved creation without a known MID stops the batch permanently; it must not be
replayed by deleting receipts, changing the fixed trial ID or using another mode.
A known MID permits verification and idempotent delivery binding without another
creation. Source changes and corrupt, mismatched or unsafe receipts stop work.
Temporary downloaded files are removed; receipts and historical native messages
are retained. All older text/visual modes and their receipts remain unchanged.

## Remaining delivery work

The general manager sender is a separate bounded implementation. Existing
`sendMedia` intentionally sends one file per message and is not a three-photo
offer sender. Before exposing a manager composer, connect manager authorization,
immutable complete conditions, durable creation state, native verification,
canonical delivery binding and a single outbound transcript record. Keep MAX
Search's search-result/site-link flow separate from this manager offer workflow.

Verification: focused renderer and photo-choice failure/recovery regressions,
the actual workflow authorizer regression, canonical choice/dispatch tests and
the required suite. Actual send evidence belongs in issue 55 after production
verification and the single authorized activation.
