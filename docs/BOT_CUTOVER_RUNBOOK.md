# Bot cutover runbook

This runbook moves live bot processing to the standalone server while intentionally keeping the legacy Bitrix lead receiver available on the old host.

## Safety invariants

- Never run old and new bot live-processing concurrently.
- Never start the final DB sync until production bot writes are actually frozen.
- Keep `lead-receiver.php` and Bitrix available on the old host throughout bot cutover.
- Do not change Yandex Metrika, goals, lead semantics, manager routing, or project boundaries.
- Keep the old checkout and database available for rollback.

## Ordered cutover

1. Verify old production and standby are on the same expected `main` SHA.
2. Verify standby reports `STANDALONE_READY=YES` and the intentional lead bridge is healthy.
3. Freeze old bot live-processing so no new conversation writes can be accepted there. Do not disable the Bitrix lead receiver.
4. Prove the freeze by taking two production conversation snapshots separated by a quiet observation interval; row counts and max IDs must remain identical.
5. Run `Cutover DB sync execution` with confirmation `SYNC_CONVERSATION_DB` and `writes_frozen=true`.
6. Require the workflow to back up standby, import the fresh transactional production snapshot, re-run migrations/readiness, and finish with exact final data match.
7. Re-run strict cutover preflight with data match required. Legacy-host independence is not required for bot cutover.
8. Point MAX/Telegram webhook delivery and any bot cron/live workers to the new server. Activate only one live-processing side.
9. Verify new-server webhook health, one controlled dialogue, Manager visibility/handoff, and one controlled lead reaching Bitrix through the existing bridge.
10. Confirm the old server is no longer processing bot traffic. Leave only the Bitrix lead receiver and rollback assets operational.

## Rollback

If any activation verification fails, stop new-server bot processing before re-enabling the old bot processing. Do not restore the standby backup over newer live data. Preserve both databases and diagnose the divergence before any data mutation.

Full old-host retirement is a separate project phase and requires removal/replacement of the intentional Bitrix lead bridge.