# Context from channel publications

An administrator connects a channel once in `/manager/publications.php`, selects
an existing active source with `manager` or `ask` handling, and enables automatic
buttons. SMM then publishes ordinary posts with text or photographs (up to 11 in one MAX
post, or 10 in a Telegram media group). The bot
receives the authenticated channel update, stores its exact content, and replaces any existing channel keyboard with only
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
  markup and every ordered stable photo ID. Existing channel keyboards are
  deliberately replaced with exactly one AnyTour URL button on both platforms,
  including other bots' comment or callback buttons (owner instruction 2026-10-15).
  Telegram edits reply markup only. Non-photo media remain `requires_attention`
  without a provider write.
- New explicit entries use the source's existing handling policy. Existing
  assigned/waiting conversations keep their manager and routing source; a new
  post only updates publication context. Original paid MAX attribution survives.
- Repeated authenticated events are idempotent. Missing provider event identity
  produces a fresh local entry, without claiming deduplication for that case.
- Telegram media-group members merge under the publication-row lock into one
  publication and one start token. The first observed member is the stable button
  target; later members retain their own event clocks, so out-of-order delivery
  cannot erase photos or move the button. Sent and ambiguous in-flight anchor
  operations carry to successor versions without another edit. The original
  caption and ordered image identities are retained in immutable group snapshots.
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
Publish a new ordinary text or photo-album post after activation; historical posts
are not imported.
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

## Album correction and the reported SPB post

The former MAX single-image guard produced `album_not_supported` on the owner's
six-image post. MAX permits twelve total attachments, including the keyboard;
therefore this feature reserves one slot and accepts up to eleven photos. See
[the official media contract](https://dev.max.ru/docs-api/use-cases/sending-messages/media).
Telegram media groups contain at most ten messages and are keyed by their shared
`media_group_id`, with a numeric member ID used only for the actual markup edit.
No code decodes a MAX permalink or manufactures a Telegram update.

`ChannelPostAutomationService::processPublication()` processes only one exact
current publication/version through the existing lease, write receipt and
UNKNOWN rules. It is available to a separately reviewed recovery operation;
it never drains or expires the global queue. Re-observing a previously rejected
MAX post requires a fresh provider read and a new immutable snapshot; resetting
its database state or replaying a historical webhook is not an accepted repair.

Album source and synthetic provider tests are distinct from a natural Telegram
album acceptance. After an authorized release, verify one individual button,
unchanged photographs/caption, a real owner entry and the correct Manager2 context.

## Missing MAX event recovery (2026-10-16)

The existing operational cron also runs the MAX worker once per minute with
`--run --reconcile`. Each invocation reads one page (up to 100 posts) for one
fairly selected enabled channel, then processes at most one durable button job.
The provider-read window and inclusive page boundary live in migration040's
private `post_channel_reconciliation` table. A full page resumes next time;
ambiguous equal-millisecond pagination is an explicit attention state rather
than a silently advanced cursor. Read failures retain the unfinished window.
An overlapping five-minute window covers delayed provider visibility without
creating duplicate publication identities or repeated edits.

Rollout seeds existing channels from rollout time. Newly enabled/re-enabled
channels start from that activation; ordinary scans never import earlier posts.
Provider observations are recorded as provider reads, without manufacturing or
replaying webhook updates. Changed registered content still requires its real
edit observation; a fresh same-content read can repair a replaced keyboard only
after the previous edit is known sent. UNKNOWN and active writes retain their
existing immutable operation and can only reconcile through the canonical path.
Configuration revisions fence delayed provider reads and cursor completions.
Preview mode makes no database or provider writes, including lease expiration.

A separately authorized existing post can be selected exactly by channel and
provider publication timestamp with `tools/recover_max_channel_post.php`.
Supply the installed clean `--expected-sha`, `--channel-id` and exact
`--published-ms`. The default preview returns a content fingerprint and photo
count. Apply additionally requires that fingerprint as `--content-hash` and
`--apply`; it re-reads the same unique provider post and uses the existing
claim/one-write/readback path. It preserves the original caption, markup,
forward context and ordered photographs. No permalink decoder, global queue
reset, customer send, webhook reconfiguration or historical event replay occurs.
Migration040 and existing publication records remain in place on rollback.
