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

## Fixed observed-listing owner trial

`tools/owner_tour_live_quote_trial.php` adds one separately authorized real-listing
example for the same two independently verified private owner conversations.
Invoke only `/owner-tour-live-quote-trial <installed-main-SHA>` on issue 55, or the
equivalent exact-SHA workflow input. Its CLI accepts only
`--expected-sha=<installed-main-SHA> --confirm-owner-live-quote`.

The literal source is a public FUN&SUN search observed on 2026-10-21 at 19:54 UTC:
Grand Park Lara 5*, Lara, departure 05 June 2027 from Moscow, seven nights,
Standard / 2AD, Ultra All Inclusive, 242154 RUB for the party. The source URL,
row and observation time are archived in `ownerLiveQuoteSource`. This is an
observed listing, not a selected-flight quotation, final fare, booking or a
personal travel requirement. The displayed end date is calculated from seven
nights; the card explicitly says return date, flights, availability and mandatory
supplements remain to be checked. `is_demo=false`, `price_verified=false`, empty
flight/inclusion arrays and the unverified-price footer preserve that distinction.

Its three fixed genuine hotel images come from the official Corendon Grand Park
home, beach and Standard Room galleries. The room image remains illustrative.
Only these three literal Corendon assets are added to the bounded downloader;
redirects, arbitrary URLs and image type/size/TLS limits remain unchanged.

This uses the existing two-channel preparation/native-proof/delivery-binding
runner with the separate ID `owner-live-quote-20261021-grand-park` and private
directory `/var/www/anytoour/data/owner-tour-live-quote-trial`. All old fixed modes,
IDs, content, receipts and creation hashes remain unchanged. Creation is permitted
only from the observation time until 21:54 UTC the same day; this operational
window is not a price-validity guarantee. The guard runs before downloads or
preparation and again before durable POST reservation. Expired known-MID receipts
still allow read/proof/rebinding without another POST; unknown outcomes remain
blocked. Changing IDs or deleting receipts must never be used to replay a send.

This trial does not expose or implement a general manager composer. An actual
owner press, rather than this delivery, is required to prove the natural choice
and Manager2 path for the new listing.

## Compact native visual revision

The owner-approved `compact-v1` revision uses the existing photo-choice mode:
`/owner-tour-photo-choice-trial <installed-main-SHA> compact-v1`. The optional
workflow input `card_revision` accepts only that exact value with photo-choice;
the ten existing modes and every old no-suffix command keep their old behavior.
Its fixed CLI is `tools/owner_tour_compact_choice_trial.php
--expected-sha=<installed-main-SHA> --confirm-owner-photo-choice
--revision=compact-v1`. It accepts no recipient, content, source URL or other
revision. HTTP access returns 404.

This is a new visual example using the historical Grand Park conditions, with
`is_demo=true`, `price_verified=false`, no flight/inclusion claims and a short
explicit demo footer. It is not a refreshed quotation. Source observation remains
in the historical live-quote entrypoint. The new layout shows departure date and
nights, without presenting a calculated return as confirmed; title, whole-party
price, four icons and genuine choice callback remain. Substantive rationale or
compromises are not hidden by the renderer. The compact footer is permitted only
for explicit demos; normal real-offer rendering and its unverified notice remain
byte-preserved when no layout is supplied.

Three genuine official Grand Park photographs are bundled under
`assets/owner-tour-cards/compact-v1/` with their source URLs, original/output
SHA256, dimensions and reproducible framing command in `sources.json`. Cover and
beach are horizontal centre crops to 1440×1080, with no stretching, padding,
retouching or generated content; the 800×600 room is copied byte for byte. This
provides three 4:3 inputs instead of relying on Telegram's blurred aspect filler.
Client presentation still requires observation in the actual bot. The installed
CLI verifies source binding, output hash, JPEG MIME, dimensions and bounds before
copying fixed assets to mode-0600 private upload files. Production needs no image
processing extension or executable for this fixed example.

Its separate ID `owner-card-compact-20261022-grand-park` and private directory
`/var/www/anytoour/data/owner-tour-compact-choice-trial` reuse the existing native
identity/proof/receipt/delivery-binding owner. Historical visual and live-quote
receipts are never edited or replayed. Creation with an unknown result remains
blocked; known MIDs permit verification/rebinding only. The new owner messages
do not synthesize a like or prove a natural Manager2 saved choice.

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
