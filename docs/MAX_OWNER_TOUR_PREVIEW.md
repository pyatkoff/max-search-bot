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
the original hash before POST. Any recovery journal, even incomplete/corrupt,
permanently blocks every later recovery attempt. The fixed demo ID/body/photoURLs
stay unchanged; the original receipt is never deleted, renamed or rewritten.
There is one recovery POST at most. Timeout, explicit HTTP400, `unknown` or
`sent_unverified` never triggers a retry. No further attempt is authorized by
this mechanism; a returned MID is verified with one bounded GET readback.

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
logged. The journal also stores sanitized diagnostics plus its private MID/hash.
This does not implement callback state, offer binding,
image upload conversion or general customer recovery.
