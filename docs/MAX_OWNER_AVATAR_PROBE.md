# MAX owner private-dialogue avatar probe

This diagnostic answers one question: does `GET /chats/{chatId}` return an avatar
for the existing owner-authorized MAX test dialogue? It does not enable MAX avatar
loading in the manager interface.

The CLI is the only entry point. It returns HTTP 404 outside CLI and has no public
HTTP API. The target is fixed to the owner's already identified conversation in
this project; there are no arbitrary conversation, user, URL or command inputs.
The provider user and private chat must match the exact stored customer-channel
identity. MAX conversation database chat keys are internal addresses derived
from the user ID, not provider chat IDs. They are used only for consistency
checks, never as the `GET /chats/{chatId}` path. The historical owner test is not required to have the database's
analytics exclusion marker; this command does not change that marker.

## Execute once

After the source PR passes required CI, is approved for publication, and the exact
merged main SHA is installed and production-verified, use the manual
`MAX owner avatar probe` Actions workflow on `main`:

- `expected_sha`: the full installed main SHA;
- `confirm_owner_test`: explicitly enabled for the bounded owner identity and photo check.

The production job does not run on pull requests or pushes. It refuses a different
workflow SHA, installed SHA, or dirty tracked checkout before invoking the CLI.
It uses the existing deployment SSH credentials inside Actions; no token or
private external identity belongs in workflow inputs or chat.

The installed CLI invocation is fixed:

```sh
php tools/max_private_avatar_probe.php --expected-sha=<installed-main-sha> --confirm-owner-test
```

The command selects one stored inbound MAX message belonging to the fixed owner,
excluding callbacks before selecting the latest row. It does not select message
text. One bounded `GET /messages/{storedMid}` verifies the returned message ID,
sender user ID and private recipient type, and supplies the real signed int64
provider chat ID. Only then is one `GET /chats/{verifiedChatId}` attempted.
Maximum two provider reads, strict TLS, no redirects/retry, bounded responses and
timeouts. No image is downloaded. The command reads the identity in a
read-only database transaction and makes no schema, conversation, read-marker,
profile, avatar-cache, shift, routing, webhook or configuration changes.

## Interpret the result

- `photo_url_present`: HTTP and exact private chat/user identity passed, and at
  least one nonempty photo URL field exists. This does not prove the image can be
  downloaded or displayed.
- `photo_url_absent`: the same HTTP and identity checks passed, but both photo
  fields are absent or empty for this one tested user.
- Any other reason is inconclusive about general MAX avatar availability. Check
  `identity_lookup_attempted`, `request_attempted` (the avatar GET), and
  `provider_request_count` before considering another action. A failed identity
  lookup never triggers a chat or photo query.

The report contains only bounded statuses and boolean identity/field-presence
results. It never prints a token, ID, name, URL, raw response or provider error
body. Do not automatically rerun or retry after a timeout, lost workflow receipt,
or unknown execution outcome. Check the existing workflow/run receipt first and
record the outcome in #55. Absence in one user's response does not prove MAX never
returns private-dialogue photos.

## Verification and rollback

Required deterministic tests cover target binding, read-only SQL, internal versus
provider chat identity, exact message/sender/recipient proof, missing/photo
responses, errors at either read, size limits, redaction and transport
options. The workflow harness checks preconditions using fake SSH/git/PHP only;
PR CI never queries production. Remove this diagnostic through a normal revert
PR if its binding or safety contract is wrong. No data rollback is needed.
