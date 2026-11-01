# Dormant WORKING import and server cycle

The existing queue, plan baseline and accepted counters are retained. New internal
`importWorking` imports exact approved bytes under the current owner/control and
lane→queue→journal locks. The data SHA is distinct from the original queue SHA.
No acceptance credit is created by import. Original bytes preserve all history,
variants, origins and reservations. Import ACK replay verifies stored bytes.

`readWorking` is a control-only complete immutable JSON pointer/byte-cursor reader;
root reads return exact original bytes. The admitted producer's `working_read`
tool is restricted to assigned hotel index and knowledge IDs. Its base64 cursor
preserves long records and every variant. It cannot read control/checkpoint
sections. This is not automatic interpretation of all legacy budgets or evidence:
control must prepare complete scopes and dependency slices before admission.

A control caller can arm an exact ready stage with `queueCycle`. The existing
HOTEL cron/event service reads the protected outbox through the same Queue API,
signs/delivers it, and acknowledges the exact outbox row. Legacy claim/complete
remain fenced. `consumeDispatch` burns a single permit after acknowledged cron
delivery. Lost send/ACK stays UNKNOWN and never refunds it.

`HotelCollectionCoordinator` requires an explicitly configured existing-native
adapter callback. It checks stored task/capability routing, records dispatch intent
before the external call and never retries an uncertain send. No adapter is
configured, no native worker enabled and no production callback is invoked by
this PR. Installing a new tool list is not proof the existing native tasks can
access it. Authenticating an instance is still capability-based, not HOST proof.

Research produces immutable candidate bytes. `sealResearch` stores the producer
receipt and advances research to intake without adding an accepted batch. It
issues a separate private CENTRAL capability. CENTRAL cannot use the producer
capability, replace the candidate or commit data. First context read binds one
review instance; an immutable verdict is tied to the candidate and distinct
reported review run. Due criteria require the original bounded receipt shape;
these hashes/counts are attestations, not independent proof of case review.

Control `finalizeIntake` checks reviewer decision, current base/reserves and exact
candidate, then commits canonical overlay, domain receipt, accepted count,
queue stage, journal state and next outbox in one transaction. The fault test
interrupts after data write and before queue advance and verifies rollback of
both. Exact finalization replay returns the original acceptance without another
batch. Existing accepted receipts are not recreated by WORKING import.

The historical exclusion check currently rejects exact prior packet digests and
logical packet references found in imported WORKING. It is not a semantic legacy
converter or a complete policy for renamed/reformatted historical knowledge.
Protected reservations and typed canonical duplicate constraints continue to apply.

## Activation limits

This is code preparation only. Current WORKING17 bytes were not imported into
production. Claimed/UNKNOWN CENTRAL41 cannot be adopted by the existing journal;
it must be reconciled before a ready-stage cutover or a separate adoption path
must be implemented and reviewed. The dormant signed-event adapter is described
in `HOTEL_NATIVE_AUTOMATION_ADAPTER.md`. Its actual host binding, complete approved
legacy/dependency scope preparation and later activation remain deployment gates.
No merge, deployment, live migration, task enablement or queue creation occurs.

The existing tools/hotel_work.php tick invokes HotelCollectionCronRuntime after
signed event delivery. Trusted config.php bootstrap may register the server
coordinator there; default is an explicit unconfigured zero-work result. Adapter
configuration and approved scope selection are deployment prerequisites, not
worker-supplied tool arguments. Nothing registers it in this PR.
