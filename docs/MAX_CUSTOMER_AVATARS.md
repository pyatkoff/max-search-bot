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
Only `dialog_with_user.avatar_url`, the documented thumbnail, can be downloaded.
Channel icons and the full-size URL are never fallbacks. The successful owner
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
