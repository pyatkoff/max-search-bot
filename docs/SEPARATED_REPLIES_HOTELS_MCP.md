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

## Review repairs

Reply unsubscription is bound to the current authenticated OAuth connection as
well as administrator, project and subscription identity. Old connection cleanup
cannot stop a subscription currently owned by its replacement, even if both use
the same callback URL. This does not add an automatic transfer or migration.

Background event authorization resolves a known canonical grant/client/resource
before returning a denial. Missing or inconsistent authority defers delivery and
preserves the subscription, pending evidence and original hotel work. A known
revoked/expired grant still denies access. Neither behavior permits replay.

Synthetic regression coverage includes actual new OAuth/discovery/consent/token
HTTP routes in a temporary test database. Production consent, HOST bindings and
actual client delivery remain separate deployment/migration acceptance.

## Source verification checkpoint — 2026-10-29

Repair commit `18bc00c52edd8616d5bb2f2db7fb6604e23e68f1` integrates the
accepted PR1238 commit `8e913ab3fa9f72a5159197946e96620c4afa2ce5`, preserving
its requesting-connection metadata. Source-only Actions run `40931284019`,
job `123650069051`, completed successfully without production credentials.

The complete `manager` required group passed all 177 test commands (45.540 s).
Included suites reported 101 separated-MCP assertions, 199 manager OAuth
assertions, 307 manager MCP assertions, 370 hotel queue assertions and 121 hotel
OAuth/MCP assertions. New HTTP routes were exercised with real synthetic
consent/PKCE/token/revocation flows; discovery rejects non-GET requests.
CLI health fixtures exercise the new authorization callbacks, including unknown
authority failures, and the HTTP boundary inventory checks the fixed wrappers
and their actual shared bearer/session boundaries rather than exempting them.

This checkpoint is author-side test evidence, not independent approval. The
previous independent review findings require reassessment on the repaired head;
ordinary exact-head CI and deployment/readback remain separate release gates.
No production grant, subscription, task, queue, receipt or hotel record was
changed by this source verification. Earlier failed fixture runs are superseded
only for the repaired source, not relabelled as successful executions.
