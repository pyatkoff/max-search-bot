# One fixed owner tour-card preview

This is one explicitly requested demonstration in the existing MAX owner dialogue,
not Manager2 selection functionality or a sender for arbitrary customers.
No Telegram owner identity is inferred. The existing read-only avatar probe stays
read-only and is not used to send.

After normal reviewed merge/deploy and exact production SHA verification, manually
run **MAX owner tour preview** on `main` with `expected_sha` equal to that installed
SHA and `confirm_owner_send=true`. Alternatively, the authenticated owner can
create one exact comment in repository issue **55**, containing only
`/owner-tour-preview <installed-main-sha>` (40 lowercase hexadecimal characters;
no newline, Markdown, extra spaces or other text). Edited comments do not activate
it. Comments on PRs, other issues/repos, from another login/ID or actor are rejected.
The permanent owner identity `pyatkoff` / `226193297` was verified from GitHub
authenticated profile and PR1041 metadata. This is an explicit manual activation,
not a push/deploy trigger or a general ChatOps command.

Both paths parse event JSON via `GITHUB_EVENT_PATH`, require the trusted
default-main workflow, match the requested SHA to `GITHUB_SHA`, and independently
GET the fixed GitHub main-ref endpoint with the default contents-read token before
loading deployment SSH credentials. The installed SHA must also match before the
fixed CLI runs. Comment text never enters shell interpolation. Workflow permissions
remain contents-read only. Inspect the issue_comment workflow run and jobs through
GitHub Actions GET APIs; no completion comment or additional write permission is
introduced. The only installed command is:

```sh
php tools/max_owner_tour_preview.php --expected-sha=<installed-main-sha> --confirm-owner-send
```

The CLI is HTTP404 outside CLI. It accepts no recipient, URL, text, token or shell
command input. It binds conversation2 / anytour / max / original name Pavel using
two bounded SELECTs in a READ ONLY transaction. The saved inbound MID is verified
against the provider's exact sender/private recipient before the sole POST. It
requires existing manager/waiting-manager ownership; it never changes ownership
to make the test possible. Credentials remain in the installed config.

The fixed message labels all dates/prices/conditions as demonstration data and
contains three official Delphin Imperial image URLs and one inline **Нравится**
button. Native MAX `type=message` sends the button's text as an ordinary inbound
user message. It does not toggle a hidden like, attach an offer ID or preserve a
choice between multiple tours. Existing normal inbound handling, recording and
notifications apply when the owner chooses to press it; no callback handler or
business/metrics path is added. The CLI itself writes no business/database,
manager, routing, model or Metrika state. How MAX lays out the three photos must be
observed in the actual app; API verification is not visual acceptance.

References: [MAX keyboard types](https://dev.max.ru/docs-api/use-cases/sending-messages/keyboard)
and official [MessageButton schema](https://github.com/max-messenger-bot/max-bot-api-schemas/blob/main/schema_2026_06_11.json).
Inline MessageButton has only type/text; separate payload belongs to reply-keyboard
SendMessageButton, so this preview does not invent a hidden payload.

## Exactly one attempt

Before POST the CLI exclusively creates and fsyncs the mode0600 fixed receipt
`/var/www/anytoour/data/max-owner-tour-preview/delphin-imperial-20261021.json`
inside a mode0700 directory outside the webroot. Any existing receipt, including
an incomplete/corrupt one, blocks every subsequent send. The fixed demo ID stays
the same across future commits and deploys. The workflow has no retries/schedule
and serializes both manual activation paths.

`verified` means one POST returned a MID and one GET readback matched the exact
owner/bot identities, text, three images and native message button.
`sent_unverified` or `unknown` is not permission to retry. Inspect the private
receipt/provider message first; do not delete the receipt or change the demo ID
to obtain a second attempt. Public logs contain only bounded status/counts.
No message deletion is part of rollback. Removing this auxiliary code leaves the
private once-receipt intact and sends no further messages.

## One separately authorized owner recovery

The original attempt returned `unknown`. The owner reported no visible card; a
verified owner inbound containing «пуст» was observed at 2026-10-20 23:43:21.694 UTC.
A read-only query of the original attempt window returned no messages. That
bounded observation alone does not authorize replay or prove the private history
is complete. The original receipt remains untouched and continues to block the
ordinary send command.

After reviewed merge/deploy and exact installed-main verification, the owner can
explicitly authorize **one additional attempt total** with the exact issue55 command
`/owner-tour-preview-recover <installed-main-sha>`. Manual workflow dispatch can
instead set `confirm_owner_recovery=true` and `confirm_owner_send=false`; both true
is rejected. Recovery requires the same permanent owner login/ID even on dispatch.
The installed fixed CLI command is:

```sh
php tools/max_owner_tour_preview.php --expected-sha=<installed-main-sha> --confirm-owner-recovery
```

Recovery requires the original private receipt to be valid `unknown`, with private
file/directory modes and no symlinks. It verifies the same fixed owner binding and
latest saved inbound MID against MAX: owner sender, native private chat, text
containing «пуст», timestamp after the original attempt (23:40:44 UTC).
One GET then covers 2026-10-20 23:40:12 UTC through the current server time, count100.
MAX documents `from` as the upper and `to` as the lower boundary, in milliseconds.
There must be fewer than100 messages, every timestamp inside that window, and
exactly one copy of the just-verified inbound MID with its exact timestamp.
Empty results, a missing anchor, any bot message (including one without optional
recipient user ID), media, foreign sender/recipient, malformed item or truncation
block recovery. No pagination, fallback probe or automatic retry follows a block.

Only after those checks does the CLI exclusively create and fsync
`delphin-imperial-20261021-recovery.json` in the same private directory, recording
the SHA256 of the unchanged original receipt and a new `created_at`. It rechecks
the original hash before POST. Any recovery journal blocks ordinary replay; only
the exact historical rejection below can enter its fixed continuation. Incomplete
or corrupt records never permit creation. The demo ID and desired caption/photo
URLs stay unchanged; the original receipt is never deleted, renamed or rewritten.
There is one recovery POST at most. Timeout, HTTP400, `unknown` or
`sent_unverified` never triggers an automatic retry; a returned MID is verified
with one bounded GET readback. The narrowly gated fixed repair below is a separate
explicit continuation of a definitive historical validation rejection.

## Fixed continuation after a proven validation rejection

The same owner-only recovery command can continue only the historical journal
whose state is `unknown`, HTTP status400, curl0 and API code `proto.payload`, with
no MID, prior revision or nested previous recovery. Its original-receipt hash must
still match. Transport-unknown, sent, corrupt or concurrently locked records
cannot create another message. Before creation the same fresh owner «пуст» and
complete anchored original-to-now history proof must pass again.

The fixed `payload-repair-v1` continuation makes one canonical text-only POST of
the clearly labelled demo caption. Its MID must be successfully flushed/fsynced
into the private journal before editing can begin. A GET of that exact MID must
match the bot, owner, native private chat and exact caption. Only then does one
PUT add the unchanged full desired photos/button body to that same message,
followed by one GET readback. A failed PUT cannot create a duplicate postcard.
There is no speculative payload rewrite or automatic POST retry.

The complete prior journal is retained nested with its exact byte SHA256. The
old journal is exclusively locked; a private replacement is also locked and
fsynced, atomically renamed into place and its directory fsynced before POST.
Repair outcomes also replace atomically so a torn write cannot erase that
history. Interrupted temporary files block rather than being deleted. Every new
recovery journal carries the revision immediately. Unknown creation without a
MID permanently blocks creation, even after HTTP400.

When the journal already contains this revision and its known MID, the same
explicit owner command becomes edit-only: fixed owner/source checks and GET of
that exact stored MID come first. Owner text may have changed and history may now
contain the demo, so the creation-only «пуст»/empty-history predicate is not used.
If GET already matches the full card, it returns verified with no PUT. Otherwise
one same-MID PUT/readback can be performed. Missing/foreign/changed-caption MID
never falls back to POST. No runtime callback/business handler is introduced.

A rejected fixed-demo PUT exposes only a bounded, further sanitized
`validation_message`: exact token/MID/owner/bot/chat IDs, MID-shaped strings, URLs,
decimal IDs, control characters and source dumps are removed; the result is at
most2KiB valid UTF8. Ordinary send/private fields never enter stdout. HTTP200 with
`success:false` is classified as `provider_rejected` and retains its validation
message too. Final GET is the authority for the visible card, including after an
uncertain edit. The normal workflow prints this bounded CLI JSON directly; there
is no separate output-key filter hiding new edit diagnostics.

The fixed transport uses strict TLS, no redirects, a30-second timeout and a256KiB
response cap. It accepts canonical `message.mid` as well as `message.body.mid`.
Receipts written by the updated sender retain `created_at` across outcome changes;
the historical receipt without it is not backfilled. Public diagnostics expose
only bounded HTTP/curl numbers, fixed error categories and an explicit safe API
code allowlist (including `proto.payload` and `attachment.not.ready`). Unknown
codes become null publicly. The original receipt/recovery journal privately retain
only the structured provider code when it matches `[a-z][a-z._]{1,63}` exactly;
digits, URLs, whitespace and longer values are rejected. This field never enters
stdout. Provider descriptions, raw bodies/text, IDs, URLs and tokens are never
publicly logged. On a rejection only the top-level string `message` field may be
retained privately, capped at2KiB after exact-token/URL/long-digit/control-character
redaction; nested values and the full response body are never persisted. The
journal also stores sanitized diagnostics plus its private MID/hash.
This does not implement callback state, offer binding or general customer recovery.

## Reuse MAX image tokens for the existing demo

The first same-MID full-body edit was rejected with `Failed to upload image.`
The owner already has the text demo; further formatting uses that exact stored
MID only. After its owner/bot/private-chat/caption GET proof, missing images are
downloaded from the three existing literal official hotel URLs. Each download is
strict TLS with system CA, no redirects,15-second timeout and5MiB cap, stored as
a mode0600 temporary file in the private journal directory. Actual bytes are
sniffed as JPEG/PNG (maximum7680×7680); file extensions and response Content-Type
are not trusted. The two `.png` source URLs actually serve JPEG bytes, so these
are uploaded with JPEG MIME/name. Owned temporary files are always removed.

Each missing image reserves `POST /uploads?type=image` on the fixed MAX API
origin. Only that API request carries bot Authorization. The returned signed URL
must be HTTPS on exact `iu.oneme.ru`, path `/uploadImage`, no userinfo/fragment or
non443 port. This narrow allowlist follows the documented image endpoint; other
hosts fail closed. The multipart request uses field `data`, strict TLS/no
redirects,30-second timeout and64KiB response cap, with no bot Authorization or
manually supplied multipart Content-Type. No helper creates a message.

A successful upload must return exactly one `photos.<opaque-id>.token` string.
No recursive/query/prefetched-token fallback is used. Each bounded opaque token
is flushed/fsynced privately before proceeding; partial successes are reused on
manual same-MID resume. The journal cap is64KiB including newline, consistent
across parsing and atomic replacements; each token is at most4096 bytes and8192
JSON-encoded bytes. No token/upload URL enters stdout; media tokens are also
redacted before error-message truncation. The final PUT keeps the caption/button
and replaces the three image URL payloads with stored token payloads, then reads
back the same MID. `attachment.not.ready` can require a later explicit edit-only
resume using those same tokens; there is no automatic retry or re-creation.
The workflow allows8minutes for bounded download/reserve/upload/edit operations.

## Separately requested compact comparison card

After reviewing the original card, the owner explicitly requested a second card
to compare the appearance. This is a separate fixed demonstration, not a retry of
either historical unknown send. The original plain card, its once-receipt and
the complete recovery journal remain byte-for-byte unchanged.

After the normal review/CI/deploy gates, the same authenticated owner may create
one exact issue55 comment `/owner-tour-preview-compare <installed-main-sha>` or
dispatch with only `confirm_owner_comparison=true`. The installed command is
`php tools/max_owner_tour_preview.php --expected-sha=<installed-main-sha>
--confirm-owner-comparison`. Existing owner/SHA/event guards apply; confirmations
are mutually exclusive. The comparison accepts no text, photo or recipient input.

Its fixed caption moves the demo notice below the offer and uses native HTML bold
only for the hotel title and total price. Dates, departure, two adults, room,
Ultra All Inclusive, included flight/transfer/insurance and the illustrative-room
caveat remain explicit. The three images are day facade, beach and room; one
native **Нравится** message button remains. The beach image is the verified
[official Imperial factsheet gallery](https://www.delphinhotel.com/delphin-imperial-factsheet-en)
image `https://www.delphinhotel.com/main_pics/pages/big/4325.png`. Its bytes are
JPEG, so the existing sniffed MIME/filename handling still applies.

Before any upload or message creation, the CLI requires the existing fixed
recovery journal to be verified, with `payload-repair-v1`, its known MID and three
bounded cached tokens. It GETs that exact old MID and proves the unchanged old
caption, owner/bot/native private chat, three images and one button. The legacy
unlabelled token positions come only from that fixed prior implementation's
source order:2996.png,1823.jpg,709.png. The comparison copies only day index0 and
room index2. It records the old journal's exact byte hash and the three legacy
source-URL hashes privately; the night token is never assigned to the beach.

The mode0600 fixed receipt
`/var/www/anytoour/data/max-owner-tour-preview/delphin-imperial-compact-compare-20261021.json`
stores tokens with SHA256 hashes of their exact desired source URLs. Only the
missing beach is downloaded/uploaded; each successful token is durably persisted.
An explicit later activation can resume a pre-send upload failure. Source/hash,
identity, cache and file-lock failures never authorize a send.

Before its single `POST /messages`, the CLI atomically flushes/fsyncs a permanent
`post_reserved` marker and exact token-bearing request-body hash. Any attempted
POST, including400, timeout or an exception, permanently prevents another POST.
A result without a durably saved MID remains unknown. A stored new MID allows
only GET readback; no edit, upload or second creation is performed. Verification
binds that MID to the recorded source-aware request, owner/bot/chat, exact visible
caption, exactly three images and the one message button. The previous card is
never edited by this mode.

MAX returns formatted text as plain `body.text` plus `body.markup`; raw `<b>` tags
are not expected in GET. Readback requires exactly the two `strong` spans with
strict numeric offsets/lengths, independent of associative/span ordering. The
fixed caption uses only BMP characters, for which Unicode codepoint and UTF-16
offsets agree. Missing, shifted, duplicate or different formatting does not verify.
See [MAX text formatting](https://dev.max.ru/docs-api/use-cases/sending-messages/text-formatting)
and the [official MessageBody/MarkupElement schema](https://github.com/max-messenger/api-schema/blob/main/schema.yaml).
Native photo layout and the visual result still require inspection in MAX.

## Native examples only: compact and price-first

Owner correction,2026-10-21: approve only implementable native messages directly
in the bots, never an image-generation mockup presented as a native-card promise.
The exact owner-only command `/owner-tour-preview-variants <installed-main-sha>`
(or only `confirm_owner_variants=true`) sends two fixed MAX captions: compact
room/meal lines, then price-first. Both use the same real day/beach/room photos,
native bold title/total price, demo/room caveats and one native Нравится button.
No font size, CSS spacing, button colour or photo-mosaic layout is promised.

The existing verified comparison receipt is read-only, bounded/private and bound
to the original recovery hash, owner/bot/chat, exact source hashes and token-bearing
body hash. Its exact known MID must still match by GET. All three cached tokens
are reused; no new download/upload is needed. Historical receipts/messages stay
unchanged. Each new variant has its own fixed once receipt:
`delphin-imperial-native-compact-20261021.json` and
`delphin-imperial-native-price-first-20261021.json`.
The same durable pre-POST reservation, known-MID GET-only resume and unknown-send
stop apply independently. The pair stops on the first unverified result. Rerunning
a verified first variant reads it back and may continue the not-yet-attempted
second variant; it never creates another first card. No recipient/content/variant
CLI input or arbitrary sender is introduced. Telegram identity is not inferred;
delivery there requires an independently verified owner private dialogue.
