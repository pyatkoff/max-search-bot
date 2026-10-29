# Isolated replies and hotels connections

Two additive resources reuse the existing database, stores and event workers:

| Profile | MCP | OAuth issuer | Scopes | Commands / events |
|---|---|---|---|---|
| Replies | `/manager/replies-mcp.php` | `/manager/replies-oauth.php` | `queue:read prepared:write` | Exactly list_pending_replies, get_reply_context, save_prepared_reply; manager_replies.pending |
| Hotels | `/manager/hotels-mcp.php` | `/manager/hotels-oauth.php` | `hotel_work:write` | Existing hotel_work tools; hotel_work.pending and hotel_work.attention |

Each profile has its own issuer, resource, client namespace, consent session,
refresh-token family and filtered connection-management page. Tokens are not
accepted by another profile, including the legacy or development endpoints.
All calls and subscriptions are restricted before dispatch, not just hidden
from tools/list. Neither new profile can step up into the other domains.

Legacy ai-mcp.php and development-mcp.php remain compatible. Existing grants,
subscriptions, reply tasks, queues and archives are not edited by deployment.
Existing cron lines are retained; their background-only authorization can read
the canonical old or new resource for its own event domain. This does not add
a second scheduled worker. Production callbacks and delivery must still be
verified with actual supported HOST subscriptions after explicit consent.

## Cutover, not automatic migration

Deploy after review and CI. First consent to Replies, verify its exact command
and event lists, then deliberately transfer the existing reply task and its
subscription. Do not run two reply writers. Verify an actual saved draft and
manager readback before retiring the old connection. Connect Hotels separately.
Do not revoke a combined legacy grant until its other consumers are reconciled.

EG26 remains the original held operation: a new hotel grant is not ownership.
The unchanged handoff contract still requires original stage/baseline/run and
retained claim evidence, canonical old-grant retirement for existing_run,
independent packet acceptance and once-only receipt. The new profiles neither
create another queue nor change the old claim/run. No study or import is replayed.

Local tests use synthetic SQLite grants only. A green test or installed endpoint
is not evidence of user consent, HOST event delivery or HOTEL data acceptance.
