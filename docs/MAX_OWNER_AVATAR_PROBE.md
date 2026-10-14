# MAX owner private-dialogue avatar probe

This diagnostic answers one question: does `GET /chats/{chatId}` return an avatar
for the existing owner-authorized MAX test dialogue? It does not enable MAX avatar
loading in the manager interface.

The CLI is the only entry point. It returns HTTP 404 outside CLI and has no public
HTTP API. The target is fixed to the owner's already identified conversation in
this project; there are no arbitrary conversation, user, URL or command inputs.
The provider user and private chat must match the exact stored customer-channel
identity. The historical owner test is not required to have the database's
analytics exclusion marker; this command does not change that marker.

## Execute once

After the source PR passes required CI, is approved for publication, and the exact
merged main SHA is installed and production-verified, use the manual
`MAX owner avatar probe` Actions workflow on `main`:

- `expected_sha`: the full installed main SHA;
- `confirm_owner_test`: explicitly enabled for the single authorized read.

The production job does not run on pull requests or pushes. It refuses a different
workflow SHA, installed SHA, or dirty tracked checkout before invoking the CLI.
It uses the existing deployment SSH credentials inside Actions; no token or
private external identity belongs in workflow inputs or chat.

The installed CLI invocation is fixed:

```sh
php tools/max_private_avatar_probe.php --expected-sha=<installed-main-sha> --confirm-owner-test
```

Only one provider GET is attempted, with strict TLS, no redirects/retry, a bounded
response and timeout. No image is downloaded. The command reads the identity in a
read-only database transaction and makes no schema, conversation, read-marker,
profile, avatar-cache, shift, routing, webhook or configuration changes.

## Interpret the result

- `photo_url_present`: HTTP and exact private chat/user identity passed, and at
  least one nonempty photo URL field exists. This does not prove the image can be
  downloaded or displayed.
- `photo_url_absent`: the same HTTP and identity checks passed, but both photo
  fields are absent or empty for this one tested user.
- Any other reason is inconclusive about general MAX avatar availability. Check
  `request_attempted` before considering another action.

The report contains only bounded statuses and boolean identity/field-presence
results. It never prints a token, ID, name, URL, raw response or provider error
body. Do not automatically rerun or retry after a timeout, lost workflow receipt,
or unknown execution outcome. Check the existing workflow/run receipt first and
record the outcome in #55. Absence in one user's response does not prove MAX never
returns private-dialogue photos.

## Verification and rollback

Required deterministic tests cover target binding, read-only SQL, returned
identity, missing/photo responses, errors, size limits, redaction and transport
options. The workflow harness checks preconditions using fake SSH/git/PHP only;
PR CI never queries production. Remove this diagnostic through a normal revert
PR if its binding or safety contract is wrong. No data rollback is needed.
