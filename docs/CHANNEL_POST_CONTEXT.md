# Context from channel publications

An administrator connects a channel once in `/manager/publications.php`, selects
an existing active source with `manager` or `ask` handling, and enables automatic
buttons. SMM then publishes ordinary posts with text or one photograph. The bot
receives the authenticated channel update, stores its exact content, and appends
the native URL button `Рассчитать на мои даты` to that same post.

The URL contains an opaque `p_<token>` instead of the public source key. The
registry binds that token to the project, platform, canonical bot, channel, actual
message ID, source and immutable publication versions. A bot entry pins the
version current at entry; later edits do not rewrite earlier customer entries.
Manager2 shows the original text, publication link when available, protected
photograph and previous entries. Prices remain the prices in the publication.

## Runtime boundaries

- Only explicitly enabled channels are processed. Migration 033 enables none.
- Channel updates are intercepted before customer dispatch. They never create a
  customer dialogue or emit a lead/customer-activity goal.
- Channel events use their own durable observation receipts before acknowledgment,
  independently of the existing customer deduplicator. Temporary channel storage
  failures request webhook retry rather than acknowledging a lost publication.
- MAX uses its actual webhook MID, including private channels whose API URL is
  null. No undocumented permalink decoder is used.
- MAX re-reads the post before its single attachments-only edit, preserving text,
  markup, stable photo IDs and existing URL buttons. Telegram edits reply markup
  only. Foreign callback buttons, albums and other unsupported media receive
  `requires_attention` without a provider write.
- New explicit entries use the source's existing handling policy. Existing
  assigned/waiting conversations keep their manager and routing source; a new
  post only updates publication context. Original paid MAX attribution survives.
- Repeated authenticated events are idempotent. Missing provider event identity
  produces a fresh local entry, without claiming deduplication for that case.
- A separate observation clock advances even when a bot edit leaves the body
  unchanged. Telegram's authenticated update ID orders edits within the same
  second; newer event dates take precedence if its sequence has reset.
- A provider timeout or lost receipt becomes `unknown`. The operation is never
  blindly edited a second time. MAX can reconcile by readback; Telegram requires
  an authenticated edited-channel event, otherwise administrator attention.
- Fresh Manager authentication, project and canonical conversation ACL protect
  both context and images. Context API reads do not mark a dialogue as read.

The feature adds `post_channels`, `post_publications`, `post_versions`,
`post_observations` and `post_entries`. Runtime performs no DDL. Operation leases
and external-write receipts belong to publication versions, independently of the
existing customer update deduplicator.

## Activation and verification

After the reviewed source passes required checks, Chromium/WebKit visual QA and
native MySQL migration checks, deploy through the established main release flow.
Apply migration 033 forward; verify zero pending migrations and checksum errors.
Do not edit or reverse already applied migrations.

The existing webhook URL, secret, old update types and pending updates must be
preserved. Preview the additional event subscriptions using:

```sh
php tools/channel_post_webhook_updates.php max
php tools/channel_post_webhook_updates.php telegram
```

MAX needs `message_created` and `message_edited`; Telegram needs `channel_post`
and `edited_channel_post`. The setup helper is read-only by default. Its explicit
`--apply` path verifies canonical bot identity and URL, adds missing event types
without deleting the webhook, and verifies readback. A lost apply response is
reconciled with `--reconcile` before any further change. Tokens, secrets, signed
media URLs and raw API bodies are never included in its output.
`CHANNEL_POST_WEBHOOK_STATE_DIR` should point to a durable private directory on
production; the default `/tmp` directory is only local-host durability. Keep its
write claims across retries and avoid simultaneous setup from different hosts.

The existing **Telegram webhook status** GitHub Actions workflow provides these
fixed operations through **Run workflow**:

- `inspect` is the default and reads the current webhook without changing it.
- `preview_channel_posts` runs the Telegram helper without write flags.
- `enable_channel_posts` explicitly runs its addition-only `--apply` path once.
- `reconcile_channel_posts` runs `--reconcile`, which reads the current provider
  configuration without retrying a previous write.

For every channel post operation, supply `expected_sha` with the full 40-character
SHA already verified on production. The workflow checks the installed SHA and a
clean tracked checkout before running the installed helper. It serializes these
operations and retains write claims in the established private directory
`/var/www/anytoour/data/channel-post-operations/webhook`. Preview first; enable
only after checking the intended bot and additions. If enable reports an unknown
outcome, use reconcile and inspect its readback before considering further work;
never delete its claim or repeat the write to obtain a green result. This workflow
does not publish posts, import historical posts, or replay customer updates.

Open `/manager/publications.php` as an authorized project administrator. Connect
only the test channel first, select the actual manager source, and enable it.
For the owner-provided MAX test channel the canonical ID is `-80095447388179`.
Publish a new ordinary post after activation; historical posts are not imported.
Verify one button, unchanged caption/photo, and the correct original post after
a real test entry. Repeat entry, another post and an edited post must retain the
manager while preserving the separate entry history. No customer send is needed
for synthetic acceptance tests.

After-webhook work is bounded to one durable operation. A recovery runner can be
installed in the existing operational scheduler during rollout:

```sh
php tools/channel_post_worker.php --platform=max
php tools/channel_post_worker.php --platform=max --run
php tools/channel_post_worker.php --platform=telegram --run
```

The first command is a read-only queue preview. Each `--run` processes at most
one operation with the same lease and no-repeat-write rules. Scheduling is not
installed implicitly by this feature. Run it once per minute per enabled bot to
recover a process crash after durable receipt but before after-ACK work.

## Original photograph availability

The exact observed version is archived best effort after webhook ACK, before
button processing. Private image cache files are outside the document root, with
0700 directories and 0600 files, in a separate namespace from customer media.
`MAX_SEARCH_CHANNEL_POST_MEDIA_DIR` can select a durable private directory.
The default cache is `/tmp/max-search-bot-channel-post-images`, limited to 512
files/128 MiB; new images are refused when full, without replacing old versions.
Images are limited to 5 MiB JPEG/PNG/WebP. Video, GIF and SVG are unsupported.

If a saved provider URL has expired, MAX refreshes it only when the actual MID
still contains the recorded stable photo ID; Telegram resolves the saved file
ID server-side. A changed photo is never substituted for the original. Cache
loss, capacity, a changed provider photo or an unavailable image produces an
honest unavailable-image state while original text and history remain visible.

## Rollback

Disable the affected channel first to stop new publication writes. Keep existing
post buttons and records; remove a button only after reading its current state.
Revert the code through the normal reviewed release flow, without migration down
or destructive data repair. A rolled-back bot may not understand existing
`p_` links, so retain entry resolution until those links are retired or the fix
is restored. Existing webhook security and private-log containment stay intact.
