# Manager2 tour selection constructor

Owner specification: AnyTour_selection_development_spec_2026-10-22.docx.
Coordination: issue #55, source claim 6335241336.

## First implementation slice: editable draft

Manager2 adds «Подборка» to the current conversation header (inside its actions
menu on phones, preserving the client-name/card action). Its separate editor
supports up to 20 manually entered offers, party/child ages, departure/date/nights,
room/meal, total party price/currency, recommendation, compromise and source.
Managers can reorder, exclude, restore or remove offers. Existing reply text and
attachments remain owned by the conversation composer.

`ManagerTourSelectionDraft` validates editable input only; no submitted price
verification, external identity or publication flags are trusted.
`ManagerTourSelectionService` owns persistence, existing visibility/edit policy,
optimistic revision conflicts and manager-scoped save receipts. Migration 053
adds drafts and receipts without changing sent offers or tourist choices.
`manager/tour-selections-api.php` uses canonical ManagerHttp auth and CSRF.
The browser module is loaded only by the Manager2 opt-in shell.

Autosave occurs after a pause; explicit Save is available. Failed/uncertain
saves keep the exact command. An operation lookup checks its receipt before an
explicit identical retry. A stale revision never silently replaces a colleague's
draft. Unsaved edits are scoped to manager and conversation in session storage;
late responses from another conversation/authentication generation are discarded.

This slice has no customer sending, public page, verified-fare action, photo
downloader, AI/search import or browser extension. Stored draft revisions are
editable and are not immutable offer snapshots. These are subsequent portions
of the same owner-authorized constructor, not completion of the specification.

## Verification and next sequence

Required regression includes actual HTTP auth/CSRF/access and save recovery,
validation, conflicts and unchanged transcript/read markers. The real PHP shell
and production JS/CSS are used by synthetic Playwright cases at 390, 430, 768 and
1440 px, including lost responses and conflicting edits.

## Native preview and explicit manager delivery

The next constructor package adds three explicit direct HTTPS photo sources
(hotel, beach/pool, room), a customer-visible preview, immutable complete sent
terms and native Telegram slideshow / MAX mosaic delivery. The manual room image
remains illustrative. Prices are whole-party observed terms, never marked as
verified final fares. Departure plus nights is displayed without claiming that a
calculated lodging boundary is a confirmed return flight. Inclusion, flight and
unverified-supplement text is retained in the canonical liked snapshot and shown
in Manager2. Source and observation metadata remain attached to that version.

`ManagerTourSelectionDeliveryService` composes the existing access policy,
`TourChoiceService`, card renderer and outbound transcript recorder. Preview
freezes a complete version but sends nothing. Migration054 stores immutable
publications, ordered card references and one delivery fence per exact offer
and conversation. Reordering, adding another hotel or reopening a selection
does not resend its already-delivered unchanged offers. New conditions require
a new explicit manager send. Only the assigned manager in an active manager
conversation may send, with fresh auth/CSRF/project/source/recipient checks.

`TourSelectionPhotoStore` retains the uploaded input bytes privately outside
the checkout, binding them to the canonical offer. It permits bounded JPEG/PNG
downloads over verified HTTPS, pins resolved public IPv4 addresses and refuses
redirects, local addresses, credentials and arbitrary ports. Preview and sender
reuse the same hashed bytes. Browser image reads remain authenticated and do
not serialize the manager session while downloading. No arbitrary-source fetch
runs on draft save or Inbox startup.

Each creation is durably reserved before the sole provider POST. A known native
MID/response is saved before verification; then canonical delivery, exactly one
outbound transcript row and the delivered receipt commit together. MAX readback
and Telegram's returned rich-message evidence verify the exact content, private
recipient and callback. A known-MID recovery never creates another message.
Missing creation results stay UNKNOWN and block automatic or manual replay of
that quote. Partial batches stop; reopening history only reads saved results.
Closing the editor or changing conversation prevents the next card in a batch.
Completed structured native 4xx rejections are distinct from unknown outcomes:
they record no delivery and permit a later explicit retry. Network errors,
timeouts, malformed replies and 5xx never receive that permission. Overlong cards
are rejected in preview rather than losing quoted conditions. Derived lodging
end dates are explicitly marked `date_to_basis=lodging_estimate` in saved data.

The source package includes synthetic actual-HTTP access coverage, real-shell
desktop/mobile preview/send fixtures, loss/recovery and native-MySQL migration /
reservation checks. Source, current-head CI, installed runtime and natural
manager use are separate evidence. Public screenshots contain synthetic clients;
tests do not send to a customer. The previously approved owner-only native format
and trial receipts are preserved rather than replayed.

Next in the same constructor: shareable revocable public page, convenient photo
intake/import and the first bounded TO extension adapter. This native sender is
not a public link, a TO browser collector or live flight/fare verification.

## Explicit shared public page

The next bounded implementation uses `ManagerTourSelectionShareService` and
migration055 for an explicit publish/update/revoke action in the existing
Manager2 preview. The public page uses the same immutable publication. Editing
or previewing a draft never changes the shared page; publishing changed terms
retains its URL. Revocation denies both page and images. Re-publication after
revocation creates a new token so a revoked URL cannot become live again.

The public projection is an allowlist: whole-party amount, conditions, public
comment and already-reviewed images. It excludes conversation/customer/channel
identities, contacts, exact child ages, callbacks and source/photo URLs. Public
requests never download remote images, read the transcript, mark it read, send
messages or attribute a visitor to the original client. Responses are no-store,
noindex and no-referrer. A stale publication cannot overwrite current draft
terms; a reused identical immutable publication is valid. Concurrent and lost
responses require status readback, never automatic replay.

This first page package is read-only for visitors. Native bot choices continue
through their existing exact-offer path. Direct web choices, confirmed visitor
binding/contact, hotel grouping, page-interest events, convenient import and the
first FUN&SUN extension adapter remain parts of the same unfinished constructor.
No placeholder web action is reported as a saved tourist preference.

Owner specification v1.2 moves FUN&SUN reconnaissance to the beginning alongside
native sender acceptance, requires confirmed/manual hotel grouping, avoids
asking an already verified client for contact again and measures manager effort
on the complete path. Extra templates and detailed analytics cannot block that
path. FUN&SUN remains the first required V1 adapter; observing its public search
form alone does not prove offer extraction or final-fare verification.

## Explicit hotel variants

The editor's “Варианты одного отеля” control records a manager's explicit decision,
never a fuzzy name match or a claimed local hotel ID. It is optional on old drafts
and publications. Changing that variant's hotel name clears its decision; choosing
“Отдельный отель” separates it without deleting its terms. Exclusion, deletion,
reordering and optimistic save conflicts retain their canonical owners.

A publication freezes grouping separately from each canonical native offer. Thus
regrouping unchanged terms does not resend an already-delivered native card or
rewrite a historical choice. Public grouping exports only per-page ordinals, not
private draft/group IDs. The first variant supplies the shared hotel/territory
photos. Each variant retains its own illustrative room photo, dates, room, flight,
whole-party price, restrictions and visible variant number. Equal/similar names
alone remain separate hotels. Previous publications and links keep their frozen
terms until explicit publish/update; revocation still closes all images.

This package does not add web likes, visitor identity/contact, measured interest
or the FUN&SUN collector. Those remain the next connected constructor stages;
public grouping must not be described as a saved tourist choice.

## Exact web variant through the existing bots

Owner clarification of 23 October replaces the proposed website contact form:
each public variant links directly to the configured canonical Telegram and MAX
bots. The compact opaque selector binds project, bot, publication, link version
and card using the existing private offer capability. The full public URL and
native callback are not sent in the selector. Opening a page or following a link
does not itself save an inquiry, prove identity, send a message or mark a chat read.

Only the trusted native private `/start` or MAX `bot_started` envelope identifies
the sender. The same client continues their existing native conversation without
re-entering contacts. A forwarded visitor uses their own conversation and default
channel source, never the original customer's assignment, attribution, private
history or exact child ages. An unrelated account in another messenger is not
silently linked to the original customer. The UI says that saving occurs after
the bot is started; it retains a manual-contact fallback if bots are unavailable.

`TourSelectionBotEntryService` rechecks the active project/source, recipient and
current published version under the origin and target conversation locks. It
commits an immutable allowlisted public quote to canonical `conversation_events`
and one labelled native-action projection to existing `messages`, updating normal
Inbox activity/unread/reply facts without a second message or reminder store.
The projection has type `tour_web_inquiry`; it is not a fabricated literal text,
native message ID, delivered card or native `Нравится` callback. A repeated
variant/version/sender preserves the first event, quote and transcript projection.
Manager2 shows these inquiries separately from native liked offers with their
shown whole-party price and a warning that availability/price need checking.

The dedicated entry family is consumed before ordinary source/wizard effects.
An invalid, stale or revoked selector never resets the trip, changes assignment,
falls through to an advertising start or creates a successful inquiry. A rejection
acknowledgement has a durable once intent; an unknown provider outcome is not
replayed. Updated/revoked pages also invalidate their old bot selectors, while
previously accepted conditions remain readable in their actual sender's history.

After inquiry commit, manager queue entry delegates to the existing handoff
owner. A retry may finish a failed queue transition without duplicating the saved
inquiry. Only the process that creates the durable `ai → waiting_manager`
transition owns its initial greeting; concurrent starts and unknown greetings
do not create another send. Working hours, shifts, routing, push and conversion
goal policy stay canonical. MAX selection starts use this DB intent rather than
the ordinary pre-work file deduplicator so a failed save/handoff can recover.

This slice does not implement measured page/interest events, FUN&SUN import,
booking or fare verification. Native physical/owner acceptance remains distinct
from synthetic source/browser checks and the exact installed-release receipts.
