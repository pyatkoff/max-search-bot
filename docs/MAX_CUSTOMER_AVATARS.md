# MAX customer portraits in Manager2

`ManagerCustomerAvatarService` extends the existing private binary portrait
endpoint and cache. List/detail requests do not load portraits. The existing
visible-only frontend loads Telegram and MAX portraits, at most two jobs at once;
website clients keep initials. Missing photos and temporary failures also keep
initials without blocking the transcript or composer.

Authorization and deployment project identity are checked before every server
cache hit. The session lock is released before provider work. MAX cache keys use
the versioned provider format plus project, channel, user, conversation and
customer-channel identity; old unsupported-MAX negative entries cannot hide the
new adapter. Private fixed-slot positive/negative records and nonblocking locks
bound storage and coalesce repeated loads. No new database schema or business
write is introduced.

## Private dialogue identity

The stored MAX external chat key is an internal alias, not the provider chat ID.
On a cold cache miss, one metadata-only query reads at most 256 recent rows using
the existing `(conversation_id, id)` index. It selects the newest inbound MAX
customer message for the authorized identity, excluding callbacks and unrelated
senders. If no eligible MID exists in this bounded window, initials remain until
a later message/cache expiry. No transcript text is selected.

`MaxCustomerAvatarAdapter` makes one message GET and verifies the exact MID,
sender and private recipient before using its real signed int64 chat ID. One
chat GET must then match that chat ID, type `dialog` and `dialog_with_user.user_id`.
The thumbnail path downloads only `dialog_with_user.avatar_url`. Channel icons
and the full-size URL are never implicit thumbnail fallbacks. The successful owner
diagnostic in #954 is evidence for private-dialogue URL availability, not proof
that every customer has an accessible portrait; it is not repeated by this work.

## Transport boundary

The entire MAX resolution chain shares a five-second deadline, shorter than the
browser's ten-second portrait timeout. Each provider body is bounded to 64 KiB;
the image is bounded to 256 KiB and must pass the existing JPEG/PNG/WebP and
1024-pixel checks. There are no redirects, retries or extra image candidates.

The image origin is exactly `https://i.oneme.ru` on port 443. This origin is
observable in the portrait link on the official public
[AnyTour MAX page](https://max.ru/anytour). No private signed URL is recorded in
source, DOM, logs or artifacts. Unknown image hosts fail closed to initials.

One bounded UDP IPv4 DNS lookup uses the first numeric system resolver with at
most 600 ms of the remaining budget. The bounded DNS answer must match the
question and valid CNAME chain; every returned A address must be public. The
selected address is pinned with `CURLOPT_RESOLVE`, IPv4 is forced, and the actual
connected address must match. IPv6-only resolution, malformed DNS and unsupported
resolver setups fail to initials. Bot Authorization is sent only to the exact
MAX API origin and never to the image CDN. Both API and image transport use
strict TLS, no proxy, bounded bodies and no raw error logging.

## Verification boundary

Offline tests exercise identity, cache/ACL/session, bounded SQL, transport,
DNS/SSRF and invalid images. Synthetic browser tests exercise the actual PHP shell
and assets at 390/430/768/1440 px, including short screens, late responses,
account/access changes, drafts and selected files. They are separate from a
production image decode/display check and natural customer privacy cases.
Neither the diagnostic JSON nor synthetic tests close M2-22 by themselves.

## Repeated refreshes

The owner explicitly accepts an older portrait and requests no freshness check
on every reload. Successful photos therefore stay fresh for 24 hours in the
browser and server caches. The page keeps an account/generation-scoped object-URL
cache bounded to 48 thumbnails and 8 MiB. List, conversation-header and client-card
surfaces share each entry. Full photos have a separate eight-entry, 8 MiB page
cache and are loaded only when opened. Polling preserves the decoded thumbnail
node instead of removing and inserting it again.

Successful account-and-channel-scoped binary responses use
`private, max-age=86400, must-revalidate`, `Vary: Cookie` and a validator scoped to
the manager, conversation, image mode and content. The loader uses the normal
browser HTTP cache. An ordinary complete page reload can therefore reuse the
fresh response without an avatar HTTP request. The URL includes the current
manager and channel so a different account or channel cannot reuse its cache
entry. The endpoint checks those values against the authenticated manager and
canonical conversation. Legacy unscoped requests retain private mandatory
revalidation for compatibility. After expiry, an unchanged authorized photo can
return 304 without another image body.

The canonical authenticated list/detail response determines which portraits may
be attached. Account recovery and confirmed access loss clear the displayed
object URLs and viewer. Authentication, current access and project identity are
checked whenever the binary endpoint is reached, before cache reads or a
conditional response. A fresh browser cache deliberately does not perform a new
server permission check for each photo request; already downloaded bytes may
remain cached for the accepted 24-hour freshness window. Errors remain
`no-store`; there is no public cache or separate persistent browser blob store.

The server retains validated bytes for 24 hours in 128 private fixed thumbnail
slots and 32 independent full-photo slots. Slot collisions, host cleanup or
browser eviction can require another read before that time. Unavailable/provider
error results retain the existing short retry windows (one minute in the page,
five minutes on the server), so a transient failure does not hide a photo for a
day.

## On-demand profile-photo viewer

Clicking an available avatar opens a separate, keyboard-accessible modal. It
starts with the already loaded thumbnail and requests a larger photo only after
that explicit action. Closing, account recovery, access loss and a conversation
change cancel pending work and prevent late responses from repainting the viewer.
Viewing an avatar does not open a different conversation, mark it read, send a
message or save a reply/file draft.

The protected binary endpoint's explicit `size=full&photo=N` mode uses 32
independent fixed cache slots and retains the same authorization and provider-identity
checks. Larger images are limited to 1 MiB and 4096 pixels in either dimension;
the thumbnail limits above remain unchanged. No larger image or profile history
is preloaded with the Inbox.

Telegram uses `getUserProfilePhotos` with `offset=N, limit=1` and chooses the
largest permitted size of that available photo. Navigation is based on the
returned count, with at most the first 1000 photos exposed by this bounded
interface. An exceeded cap is reported rather than presented as a complete
archive. This is the bot-visible set, not a promise of every photo in the account.
The private binary response reports the available count in `X-Avatar-Photo-Count`
and sets `X-Avatar-Photo-Limited: 1` only when that cap is exceeded.

MAX exposes a single optional `full_avatar_url`; the explicit larger-photo mode
can use it after the existing MID/private-dialogue/user checks. It has the same
exact CDN origin, DNS pinning and strict-TLS rules. No undocumented MAX profile
history is inferred. If the larger image is unavailable, the viewer retains its
thumbnail and explains that a larger version could not be loaded. Provider URLs
and Telegram token-bearing download links remain server-only.

Official provider contracts:

- [Telegram getUserProfilePhotos](https://core.telegram.org/bots/api#getuserprofilephotos)
- [MAX UserWithPhoto](https://dev.max.ru/docs-api/objects/UserWithPhoto)

Actual loopback PHP regressions cover the protected 200/304 headers, empty
conditional body, image validation and account/access/session changes. A separate
browser fixture serves the real avatar JavaScript over loopback HTTP without
request interception, because Playwright routing disables the HTTP cache. It
measures first-load body bytes, a fresh full reload without another avatar
request, expired-response revalidation and account/channel isolation. Those
synthetic measurements are not production latency or transfer totals.
