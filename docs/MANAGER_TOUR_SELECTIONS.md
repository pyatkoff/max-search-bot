# Manager2 tour selection constructor

Owner specification: AnyTour_selection_development_spec_2026-10-22.docx.
Coordination: issue #55; anonymous interest source6367229738 follows installed FUN&SUN1091/RELEASE6366634955. Historical source6335241336 owns the first draft slice only.

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

When versions conflict, “Показать версию коллеги” is a read-only comparison,
not a decision to discard local edits. The original draft, selected offer and
unparsed child-age input remain in the same account/conversation recovery
envelope across close and reload. The manager explicitly continues with their
own edits or the server version. Choosing local edits adopts only the latest
observed revision for the normal optimistic save; comparison never saves,
publishes or sends, and a later colleague edit can still cause a new conflict.

This slice has no customer sending, public page, verified-fare action, photo
downloader, AI/search import or browser extension. Stored draft revisions are
editable and are not immutable offer snapshots. These are subsequent portions
of the same owner-authorized constructor, not completion of the specification.

## Verification and next sequence

Required regression includes actual HTTP auth/CSRF/access and save recovery,
validation, conflicts and unchanged transcript/read markers. The real PHP shell
and production JS/CSS are used by synthetic Playwright cases at 390, 430, 768 and
1440 px, including lost responses and conflicting edits.

## Bounded FUN&SUN browser collector

`extensions/selection-collector/` is the MV3 package for the observed public
`https://b2b.fstravel.com/search_tour` template. The installable ZIP is built
from an explicit file list and checked against the exact source in regression.
Its README owns installation/update instructions and the supported-source limits;
physical Chrome/Yandex acceptance is separate from local/CI browser fixtures.

The Manager2 selection sidebar opens `/manager/tour-selection-collector.php`.
This bridge uses the canonical `/manager/` session and ManagerHttpClient/CSRF;
it never starts a separate Manager2 cookie/session or exports a bearer login.
Migration057 stores revocable 12-hour connection metadata bound to the same
session hash, current actor, exact conversation/assignment/project/source and
trip binding. Every import and import-status request rechecks these fences
under the canonical draft parent locks. Account, access, source, session or trip
changes stop the connection. Manager2 can revoke it without deleting the draft.

The extension keeps the selected client/trip in its own UI, never the third-party
TO DOM. The manager explicitly connects the current client/trip once. A normal
FUN&SUN/ANEX row click then uses a short-lived single-use worker ticket bound
before extraction to that target, source role, tab and document. Target/connection
changes reject the click rather than retargeting. Only the opaque local ticket
and the source's own bounded operation state return to the TO content script.
The existing local intent is durable before canonical receipt lookup/append;
the source button says saved only after a known saved revision. Waiting/lost
results remain pending and another click reads that same operation. Legacy rows
without a ticket retain explicit destination review. Tourvisor's unresolved
per-room price basis and changed-price/ambiguous variants still require review.
One server draft receives different tabs. Worker/browser restart and transport
loss retain the intent; status checks its exact normalized payload/hash before a
permitted identical retry. Reconnecting requires the original client/trip;
changing the active destination blocks old local rows rather than retargeting.
Queue bounds are50 pending plus20 recent saved/cancelled rows; the canonical draft retains
its existing20-offer bound. Removing a queue row never deletes a saved offer.

The adjacent pencil captures the same settled row/destination but stages an
extension-owned price editor without a server import. The client/trip, original
amount, discount/markup calculator, rounding and direct final amount stay in the
extension UI. Edit inputs persist in the existing local queue across popup close
and worker restart. Only explicit “Добавить с этой ценой” freezes the final amount
and queues the canonical command; cancellation remains a bounded local terminal
state and never creates a server offer. Reconnection does not auto-confirm an
unfinished price edit. Tourvisor still requires its explicit room/party basis.

The optional `manager_amount` is separate from the normalized source observation.
The canonical import validates it through ManagerTourSelectionDraft, stores it in
the editable offer and preserves the original import_source byte-for-byte. Absent
adjustment retains the collector-v1 receipt hash. Adjusted commands use a distinct
hash including the exact final amount; status/retry carries that same amount.
Changing an UNKNOWN command is refused. An exact existing offer with another
manual amount returns `manual_price_conflict`, without overwrite, duplicate,
receipt or variant bypass. The manager reviews that existing offer in Manager2.
An adjustment alone never verifies the full fare or sends/publishes anything.
The bridge advertises manager-price support and canonical import-status must
acknowledge the exact final amount before append. An already-open older bridge
or backend cannot silently ignore the adjustment: it stays waiting for update.

`ManagerTourSelectionImportDraft` accepts only bounded source observations and
cross-checks the row's visible current price/currency/date/party with normalized
conditions and a secret-free search URL. The adapter waits for a stable row/form
and refuses mismatches or changed templates. CSS old-price text is not the current
amount. Raw quote/room/meal/placement/tour/program/SPO IDs, original conditions and
observation time stay separate from editable manager price. No local hotel match,
photo, selected flight, baggage, fuel or full-fare verification is invented.

`ManagerTourSelectionService::import` appends to the fresh draft using the existing
draft/operation store and locks. Exact same quote+terms deduplicates without a new
draft revision or overwriting manual price. Changed price or equivalent-looking
rows with different opaque IDs require explicit variant review; hidden flight/fare
identity is not guessed. Normal editor CAS conflicts protect concurrent edits,
and retained source provenance cannot be forged/replaced by ordinary save.
Private publication entries freeze this provenance; native snapshot/presentation
and delivery fingerprints are unchanged, public projection excludes it. Import
never changes the active public URL/version/history, prepares or sends a card,
marks read, creates an inquiry, calls AI, or accesses TO credentials/cookies.

Protected API actions: `collector_get`, `collector_connect`, `collector_revoke`,
`collector_import`, `collector_operation`. The last action returns only exact
intent saved/missing status and server count, with current connection fences.
No second draft store, sender, authentication owner or customer contact flow.
First-source limitations and natural A7–A8, full V1 and owner-only native
acceptance remain explicit in the canonical specification and latest#55 receipt.

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


## Observed interest and confirmed native activity

The preview's «Активность подборки» reads the selected immutable publication,
including an older publication opened from history. It separates visible page
and hotel observations, exact-variant bot-link intent, canonical native likes
and canonical bot inquiries. A forwarded visitor is never identified as the
source customer. Native inquiry totals can include other native conversations;
the activity projection exposes no identity, contact, quote or transcript.
Those inquiries remain in their actual canonical Manager2 conversation.

The public page issues a domain-separated signed15-minute capability for its
current link version. Ordinary GET/HEAD is not a browser view. Visible JavaScript
reports after one second; a hotel heading must be at least half visible.
The bot link remains unchanged and navigation never waits for observation save.
A per-tab random visitor key groups repeat observations; it is not a person ID.
No IP address, full user agent, account token or contact is stored. Recognized
crawler/preview GETs are separate fetch events; the protected Manager2 open-page
link carries a12-hour signed preview flag. Copy/send retains the ordinary URL.
Classification is heuristic; an unrecognized bot or a manager opening a copied
ordinary URL can still appear as a browser observation.

Migration058 creates the sole anonymous-observation projection. An exact event
retry returns its known result; a changed payload cannot overwrite it. Recording
uses the canonical publish/revoke parent lock and rechecks current public source,
link and version. Revoked/replaced links reject old capabilities. Observations
never prepare/send cards, create choices/inquiries, alter read/assignment/queue
or update the conversation's last message. Existing publication/TourChoice and
native-envelope owners are unchanged.

The indexed30-day read is capped at1000 observations; native likes use the
canonical20-offer projection and inquiries inspect at most1000 recent project
records. Limits are explicit lower-bound warnings, never a complete count.
Writes are capped at100 events per anonymous visitor/publication/day and10000
per conversation/day; bounded opportunistic cleanup removes at most1000 expired
anonymous rows for that conversation. This is a rolling observation window,
not guaranteed scheduled deletion. Preview/robot/repeated observations appear
separately. Browser blockers, storage/network errors or capability expiry can
hide events; a failed activity read says unavailable instead of zero. Manager2
timestamps use the existing Moscow formatter; text/File/context remain owned
by the conversation component. Natural/physical owner-onlyA1–A8 remain OPEN.

## Explicit saved-trip prefill

“Добавить по условиям поездки” reads a bounded optional proposal through the
constructor's own `trip_context` endpoint. It reuses the AnyTour wizard start
boundary and canonical value projection. The displayed saved basis is never
automatically described as the current enquiry: the manager confirms it before
`trip_apply` rechecks its exact revision, account/conversation/source/assignment,
selection version and latest message. The read does not create a draft or send.
Only a new offer receives the six known compatible fields. Existing recovered,
manual and imported offers are unchanged. Past dates, inexact dates/night ranges
and inconsistent party ages remain explicit manual work. Budget is request
metadata, never the offer price. Unsupported projects, unavailable catalog/state
or unbound starts retain the normal blank manual path. Closing/navigating rejects
late results; no trip store, migration, model or pipeline/auth owner is added.


## Second bounded source: agency ANEX rows

`anex-agent-v1` extends the same import dispatcher through
`ManagerTourSelectionAnexImportDraft`; FUN&SUN grammar is unchanged. The source
adapter reads only the explicitly clicked rendered search row and current form
on `agent.anextour.ru/search/tour`, retaining opaque CLAIM, hotel/SPO/tariff and
raw terms. It supports only the observed Moscow/Turkey, two-adult, no-child,
RUB, exact-date DBL template. Other conditions fail closed to manual entry.
The extension source role is fenced to its own adapter/origin; it cannot read
client identity, borrow FUN&SUN grammar or send an import until the existing
extension destination confirmation. No new store, session, sender or migration.

A form-control interaction invalidates the current document until the searched
result is reloaded: readonly date text omits the year, so matching day/month alone
cannot prove a fresh result. A 750ms unchanged row/form check then excludes
changing async results; price text must
be one current row amount. Commission/additional-service amounts are not parsed
into the offer. Hotel names alone do not establish local match. Full fare,
mandatory fuel, selected flights, baggage, photos and return flight remain
unknown. The booking restriction seen in this account is not a search outage;
import never clicks a provider booking/price action or creates an inquiry.
The same canonical locks, session/assignment/source/parent fences, intent
recovery, source provenance, manual price preservation and explicit variant
review apply to mixed-source drafts and old FUN&SUN imports.

Version0.2.0 adds the exact ANEX search host permission; installation/update
requires the manager's ordinary browser approval. Real Chrome/Yandex installation,
live manager import, natural owner-only native use and whole V1 remain separate
from source/fixture/CI validation. Tourvisor is still unimplemented: its observed
regenerated `tvtourid` and operator link shared by different rooms do not prove
stable exact offer identity. Do not use either alone as a deduplication key.

## Third bounded source: Tourvisor card snapshots

`tourvisor-snapshot-v1` uses the same dispatcher, durable extension queue,
destination confirmation and canonical draft/receipt/ambiguity owners. It reads
only the open card and visible card menu on `pro.tourvisor.ru/search`; initial
support is the observed ANEX-operated Moscow/Egypt, two-adult/no-child, RUB, AI,
exact-date template. No TO request, booking, share, cart or customer action runs.
The menu's general search URL supplies the explicit date/year, hotel and party
basis; it is not advertised as an exact provider permalink. A matching operator
menu/card reference, snapshot handle and 750ms unchanged observation are required.

The snapshot handle survives card reload but may change on another row opening.
It proves neither a stable underlying quote nor fare identity. The same handle
and exact terms deduplicate; a new handle with the same conditions is refused
by canonical ambiguity review, and changed prices retain price-review semantics.
Different shown rooms/programs/flights remain separate. A changed base/fuel split
under the same total cannot silently reuse the earlier snapshot. Original terms
and manual edits keep their independent immutable provenance.

Because the source says “за номер”, the popup requires an explicit manager
confirmation of one room for the shown two adults before any server import.
Source content cannot self-confirm; reconnect/restart cannot bypass this state.
Base plus shown fuel must equal the displayed total; fuel is never added twice.
This manager acknowledgement does not mark a full fare verified. Visible flight
text is retained as an observation, with its selected/current status unverified.
The source amount/basis/time remain visible separately from manager price.
The existing publication, native envelope/sender, auth, ACL and session contracts
are unchanged. Version0.3.0 adds only this search host to extension permissions.
Physical Chrome/Yandex permission/install/live import, the mixed-source full path,
natural owner acceptance and A1–A8 remain separate from fixtures/CI/publication.

## Channel-specific customer preview

Preview retains the immutable native renderer text and highlights its exact hotel
and whole-party price. Telegram preview provides three horizontally scrollable
4:3 photos with previous/next buttons and a photo counter; MAX keeps the three-photo mosaic. This is a browser representation,
not physical native acceptance. No terms, publication, delivery or recipient
contract changes; source text is escaped, never interpreted as HTML.


## Ordinary FUN&SUN AJAX results and compact source actions (0.5.1)

The source content script captures immutable allowlisted conditions at the
ordinary native Search click/submit. Only newly inserted result row nodes after
that search use its context; old or reinserted existing row nodes cannot acquire
it. Changed form/child ages/program labels, loading and old-generation rows reject
import, with the existing exact row/price/ID and750ms stability checks. Initial
legacy exact-address imports remain compatible until a new search is attempted.
The live share link is deliberately ignored: further observation showed it can
change on input blur without a search, so it cannot prove submitted conditions.
Only allowlisted reconstructed search fields enter private provenance. PROGRAMINC0
means the observed unfiltered program selector; exact row program/tour/SPO IDs stay
mandatory. No price, full-fare or flight verification is inferred.

The source row uses compact32px checkmark/pencil actions with accessible names,
tooltips and separate saved/waiting/review/error symbols. The target client remains
inside the extension. Existing queue/ticket/receipt/session/UNKNOWN and explicit
price-basis confirmation contracts are unchanged. This removes manual share-link
copying from ordinary completed FUN&SUN searches; physical owner import remains
a separate acceptance step.

The popup declares its370px root/body minimum before Chrome's native viewport
autosizing. A body-only width capped to100vw could collapse to the initial narrow
viewport. Browser fixtures exercise that40px bootstrap then readable390px content
and scrolling; actual installed owner popup geometry is a separate acceptance.

## Collector return to the exact selection (0.5.3)

The popup opens the displayed client's selection directly instead of the generic
Inbox. A click captures the active target; a changed client/connection refuses
that stale navigation. Each saved/review row can instead open its original
selection, even after the active collector changes. The bridge return link uses
the same path and clears its displayed identity/link after access loss.

The URL contains only the conversation and expected manager IDs, never a session,
connection capability or customer name. Existing canonical conversation navigation
performs its normal authentication/access read first. The selection module consumes
the one-time matching hint after that read, verifies the expected account and current
conversation generation, then loads the ordinary draft/recovery envelope. A denied
read, another account, mismatched target or newer navigation cannot open the editor.
The hint is removed before opening; refresh/close do not reopen it. No auth, shared
navigation, selection persistence, publication or sending owner is duplicated.
Opening occurs in a new tab, leaving the original reply and selected files intact.


## Mixed draft photo review

After the manager explicitly groups variants as the same hotel, the editor can
copy missing cover and beach/pool photo URLs from a chosen exact variant in that
group. It is an explicit local draft edit through the ordinary optimistic save
and recovery path. Equal hotel names alone do not enable it. Existing images,
room images, observed source terms and manual prices are preserved. The copied
URLs are independent values; regrouping or editing one variant does not change
another or a frozen publication. This does not verify hotel identity or photos.

Canonical `prepare` errors now offer direct links to each incomplete variant's
field or first missing photo. These are the server builder's returned errors,
not a second readiness/validation owner. Editing or reloading clears the old
check; a fresh preview remains required. Preview starts at its heading/first
card even if the editor was scrolled down to photos. No action sends or publishes.

Synthetic mixed FUN&SUN + ANEX + Tourvisor drafts exercise grouped hotel photo
reuse and the complete three-card preview at 390/430/768/1440, with exact original
source/conditions preserved and independent room photos. Wrong group, same name,
readonly, stale actor/conversation/auth and concurrent-save boundaries remain
covered. This is source/fixture acceptance, not physical Chrome/Yandex import,
source-photo capture, full-fare verification or owner-native A1–A8 completion.


## First photos from a local file

Each cover, beach/territory and illustrative room slot also accepts an explicit
JPEG/PNG file from desktop or mobile. The existing direct URL path remains.
The assigned manager first saves the ordinary draft, then uploads one bounded
file against its exact offer, version and current access. Upload does not save
the draft or send/publish anything; its confirmed reference is attached by the
ordinary optimistic save. An uncertain reply offers an explicit identical-file,
identical-operation retry or cancellation. Switching conversation/account,
closing, conflict or changed access prevents a late reference being applied.

`TourSelectionPhotoStore` remains the only image byte owner: the same private
root, 2MiB/file, JPEG/PNG/dimension validation, immutable hash-checked bytes,
0700/0600 modes and 1024-file quota. Upload references are canonical HTTPS inputs
to the existing publication/cache pipeline. They require current Manager access
and cannot be moved to another conversation by save or prepare. Public readers
still see only explicitly published cached bytes; revocation closes those reads.
No second store, renderer/sender or public draft-upload capability is introduced.
No file name, local path or base64 data is stored in the draft or publication.
Room images remain illustrative and upload does not establish a catalogue match,
source photo evidence or a verified fare. Original bytes are retained; native
formatting/physical acceptance and automatic TO photo capture remain separate.

Protected HTTP/file-byte and browser cases cover fresh auth/CSRF/project/access,
readonly/stale draft, same/different retry, bad/oversized bytes, cross-client
references, device-file intake, lost replies, cancel, late responses and original
reply/File preservation. Required widths390/430/768/1440 plus short height are
covered. Installed release and natural/physical mixed-source A1–A8 remain distinct.
