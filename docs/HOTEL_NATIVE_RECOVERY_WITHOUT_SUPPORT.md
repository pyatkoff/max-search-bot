# Recovery preparation without support

Code preparation only. No migration, host update, subscription, quarantine apply,
queue change, merge, release, task enablement or execution is performed here.
Existing accepted results and closed data checks are retained by their evidence.

## Exact binding evidence

`tools/hotel_native_recovery.php inventory PRIVATE_REQUEST.json` reads the
current owned queue's server registrations, including the two new role channels.
It exports exact subscription IDs, filters and eligibility without callback URLs,
keys, prompts, customer data or capabilities. An argument named task_id is
explicitly subscriber-supplied, not authenticated native identity.

`verify-bindings` joins a fresh **trusted operator's native host export** to this
inventory. The private request supplies admin_id, connection_id, queue_id,
expected_tasks (producer/reviewer), connector_id, and host_export:

```json
{
  "source": "native_host_export",
  "observed_at": "2026-11-01T11:00:17Z",
  "bindings": [
    {
      "task_id": "existing 32-hex task ID",
      "trigger_id": "actual native trigger ID",
      "subscription_id": "actual subscription ID",
      "connector_id": "actual existing connector ID",
      "event": "hotel_work.research_ready",
      "arguments": {
        "project_key": "anytour",
        "queue_id": "existing queue ID",
        "task_id": "same existing 32-hex task ID"
      }
    }
  ]
}
```

The real export must contain exactly two rows, including review_ready for the
reviewer. It must come from a supported native task/trigger export or a retained
exact normalized task-update/registration receipt disclosing **all** IDs, not an
invented desired configuration. Retain provenance privately and pass only this
safe projection. The parser does not authenticate the host or prove a native run.
It refuses missing/hidden fields, stale exports, mixed connectors, foreign task
IDs, wrong filters, inactive/ambiguous registrations and private extra fields.
Success only prepares evidence for the existing server-only bind method. It
does not apply the binding or grant dispatch.

This removes server-side aggregate ambiguity and makes the final join executable.
It cannot expose a field the native host withholds. In the observed account,
peek and the native editors hide trigger/subscription IDs and extra settings.
If the supported host export/update receipt still omits these IDs, obtaining an
exact native mapping remains impossible through the currently available surface;
the command returns HOLD rather than fabricating it. No support request is made.
A nonce in subscriber arguments or a key in a prompt is not a substitute: the old
controller can read/update task prompts using its existing host privileges.
Do not weaken the adapter's host-binding gate to accept such self-attestation.

## CENTRAL41: isolate effects without claiming native termination

The alternative to waiting for terminal proof is a **limited canonical-effects
quarantine**. The prepared HotelLegacyEffectQuarantine is internal server code,
not an MCP tool, cron action or automatic recovery. The CLI offers only its
read-only preflight. Apply is deliberately absent from the CLI.

The preflight requires a current owned legacy claimed intake with its original
claim still retained, no current run association and no existing canonical writer.
Its CAS digest pins business identity, owner/connection, plan/baseline, stage,
claim hash, state, accepted/phase counters and current data pin. Routine cron
delivery timestamps do not invalidate this business CAS.

On a later separately authorized application, one lane -> queue -> grant-locked
SQL transaction saves an immutable exact approved WORKING snapshot, the full
pre-barrier queue row, the original run reference, TR40 packet digest/provenance
and receipt; then sets the sticky protocol to quarantine_v1. Everything else in
the queue is unchanged, including the original claim/key hash and 19 accepted
batches. Failure rolls back snapshot and barrier together. Exact lost-ACK replay
returns the same receipt and never repeats a business action.

The existing legacy write boundary refuses mutations from **cached as well as
fresh clients**, including delayed started/complete/claim/release/handoff/repair
paths. Journal enrollment rejects the quarantine marker; canonical service also
refuses it. Deleting/unavailability of the snapshot table does not remove the
sticky fence. There is no automatic downgrade, adoption, stage advance, packet
import, acceptance, fresh worker capability or dispatch permit.

Five database triggers additionally protect queue UPDATE/DELETE and receipt
INSERT/UPDATE/DELETE even if a pre-deploy PHP request ignores the new protocol.
The queue trigger checks the current OLD row; receipt checks take current locks,
not a stale repeatable-read snapshot. Business values and the quarantine marker
cannot change, while ordinary delivery metadata can still be updated. MySQL
uses unconditional scalar-cardinality/unique-key errors on a prohibited branch,
independent of strict SQL mode, rather than a possibly coerced NULL assignment.
Apply requires all five triggers and their constraint gate to exist. Release
must confirm trigger installation privileges/migration success before any apply.
A future unquarantine/adoption would need a separately reviewed forward change;
ordinary application code cannot undo this barrier.

### Scope that remains outside the server

This does NOT terminate/cancel the old native run, revoke its OAuth, isolate its
host tools or prevent Library writes. Old Library files and all their versions
must be retained as a quarantined legacy surface; never silently ingest their
later edits into the frozen snapshot. A delayed host call could still overwrite
an existing task prompt/settings, which is an availability risk. Before future
activation, exact prompt/trigger binding must be checked again, and every worker
must require the server's fresh role capability/admission. Old literal bindings
and Library-only results grant no canonical acceptance.

Consequently the receipt says original_run_status=UNKNOWN,
host_and_Library_fenced=false and continuation_allowed=false. This is a working
server preservation barrier, **not full native isolation**. The old run must not
be relabelled failed/cancelled. Do not clear its ownership merely because this
transaction succeeded.

## Safe future sequence

1. Keep current live state untouched while reviewing this draft. No further
   unchanged archive/WORKING hashing, repeated native history search or support
   requests are needed for code preparation.
2. After explicit release and operational approval, perform a fresh affected
   preflight. If a new acceptance/current_run/claim/owner or canonical writer
   exists, stop and reconcile it. Freeze exact approved accepted-state bytes;
   retain any later legacy Library mutations separately for review.
3. If canonical-effects quarantine is chosen, apply the barrier once and verify
   its scope with the receipt. The same queue stays claimed; it is not runnable.
4. Obtain actual native binding evidence through the supported export/receipt,
   validate it with the read-only join, and separately approve the binding. If
   native IDs remain hidden, retain HOLD. Never create a substitute queue/worker.
5. A later reviewed adoption path must stage the already completed TR40 packet
   with all evidence, preserve historical IDs/variants/reserves/criteria, and
   issue only a fresh independent CENTRAL review authority. It must atomically
   connect the existing claim to canonical acceptance. This path is not supplied
   by quarantine and no new TR research is permitted. Full native isolation
   would additionally require host-enforced per-run revocation/fencing, which
   the available tools do not provide.

## Verification

New targeted SQLite/native MySQL regressions cover the read-only binding join,
hidden/mismatched/expired data, wrong-owner access, atomic barrier rollback,
business CAS changes, preservation of claim/counters/history, exact replay and
cached/delayed legacy mutation rejection, plus raw SQL mutations with MySQL strict
mode disabled. They use synthetic data and actual
forward SQL migrations. Required CI is run for the changed code; previously
accepted production archives and batches are not replayed or rehashed.
