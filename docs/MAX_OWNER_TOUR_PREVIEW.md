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
