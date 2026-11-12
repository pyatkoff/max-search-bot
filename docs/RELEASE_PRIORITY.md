# Ready release priority

## Explicit continuation of the PR1505 pre-write read refusal

The original PR1505 installation and every original required gate succeeded.
Closer43231780686/attempt1 prepared and published its digest-bound terminal
proposal, then refused with `api_http_503` before the publisher PATCH. Its
failure remains immutable history. Pavel's subsequent continuation is handled
by one exact checked fast-forward of `ops/recover-pr1505-terminal-20261112` in the same
existing serialized tail, only after exact-source required PR CI succeeds.
This is neither a rerun nor a replacement installation or publisher. The first
source-accepted continuation43239070014 stopped at its initial context guard:
proof upload and terminal steps are explicitly skipped and its allowlisted
failure precedes every writer. Read-only observation43240168728 then proved the
genuine update event from658e387 to f4db027. The final continuation accepts only
the observed native update semantics and independently verifies the actual
predecessor jobs/logs before any write. The checked d6f732 continuation43243320862
also stopped during preparation on another503; upload/terminal were skipped,
and original diagnostics commit34f4a2c/publisher version remained exact.
Only the exact checked descendant in the same original branch update is admitted next.
GitHub run summaries can remain queued while their real Jobs are already running.
The continuation therefore requires its exact original source/run/attempt job to
be in_progress on an assigned positive runner, rather than using that summary
alone. A genuinely queued job, missing metadata or a different source refuses.

Inside this explicit continuation only, idempotent metadata GETs may perform
at most two additional reads after HTTP503 across each preparation/final phase,
with one second between them and the existing request/job deadlines. This
includes original immutable job logs/artifacts. No other HTTP error,429,
transport uncertainty, POST or PATCH is retried. Every final source, original
gate, owner/version and diagnostics compare remains mandatory after reading.
All ordinary release paths retain their existing no-retry behavior.

The fixed recipe pins the original SHA, proposal commit/blob/digest, failed
run/job and original publisher tuple. Before any write it authenticates that
immutable proposal and its original native artifact, failed job/step and
allowlisted 503 log outcome. It rechecks every original gate, installation,
ops receipt, PR binding, current main and unchanged owner/version. Unknown
writes, other errors, changed source/evidence and unavailable data refuse.

The actual continuation has its own native push/source/job identity in the
same versioned `deploy_status.json` closure. Its digest-bound artifact and final
successful step are required by consumers; the failed original step is never
reported as success. Only the continuation's single original attempt may
perform the one not-previously-attempted publisher PATCH and readback. Refusal
or an UNKNOWN write retains the record; no automatic retry, branch recreation,
secret, SSH access, live probe, schedule or new workflow/queue is introduced.
The fixed branch cannot update main. Normal releases retain their original
attempt1 validation and all existing verification rules below.

A small cleanup must not repeatedly advance main while a complete product package
is finishing its required checks. Priority is recorded in that product's existing
PR description. There is no new queue, scheduler, controller or deployment owner.

## A bounded reservation

The existing product owner adds exactly one hidden marker after finishing the
coherent source/test package and before its necessary final CI. Preserve the rest
of the PR description. Use the actual immutable head, current main and UTC times:

```text
<!-- anytour-release-priority:v1 {"head":"FULL_HEAD_SHA","base":"FULL_MAIN_SHA","started_at":"YYYY-MM-DDTHH:MM:SSZ","expires_at":"YYYY-MM-DDTHH:MM:SSZ","kind":"product"} -->
```

This is one metadata save, not another review or test run. The period is at most
30 minutes and starts now; future reservations and automatic renewal are invalid.
It protects a complete product, not speculative work, cosmetic refactoring or an
external wait. Read back the saved marker once if the save result is unclear.
The marker grants neither CI success nor a deployment lease.

A changed head, changed main, expiry, draft or closed PR releases priority.
An old marker never silently moves to a new source version. If several owners
reserve concurrently, the oldest start wins, with the lowest PR number as a tie
breaker. Only trusted same-repository PRs targeting main may reserve priority.

A confirmed production failure, lost lead or data-integrity incident can use
`kind:"incident"` and an `incident` field containing the existing exact
same-repository GitHub issue URL. The reference is an accountable attestation by
the existing owner, not automatic proof of an incident. A metric alone is not an
incident. Incident priority does not skip any CI, data protection or active
publisher. Do not create an issue or another approval chain merely for priority.

## Existing required checks

The required `regression` aggregate first requires every existing gate.
Only then it checks current main and all open PR reservations through read-only
GitHub API calls. The existing `guard`, full/component scope, MySQL, browser,
deploy, cron observations and post-deploy checks remain mandatory as before.

A malformed authoritative reservation, stale target/base, failed API request,
429, invalid response or incomplete snapshot fails closed. There is no retry,
alternate host, automatic claim renewal or mutation. Reads use a ten-second
deadline each and at most three pages of 100 PRs; reaching the limit stops rather
than assuming an omitted product has no reservation.

If only priority failed, wait for the stated owner or expiry/new input. Rerun
only the failed aggregate job, retaining the already successful jobs for the same
unchanged source and dependencies. Do not rerun the entire workflow, rehash,
repeat visual acceptance or add reviewers solely to refresh mutable priority.
A genuine source/dependency change follows the existing verification rules.

## Last admission before merge

Immediately before the ordinary publisher lease and expected-head merge, run the
same read-only decision with fresh main and the intended exact PR head:

```sh
GITHUB_REPOSITORY=pyatkoff/max-search-bot node tools/release_priority.js --admit PR_NUMBER FULL_HEAD_SHA FULL_MAIN_SHA
```

Use the existing server/CI read capability via GITHUB_TOKEN. Do not obtain it
from a forbidden host or print it. API-only coordinators may call the same
exported decision with the equivalent fresh main/open-PR snapshot. Manual
workflow_dispatch validates code but cannot grant merge admission.

On refusal, the existing owner keeps the same task. No new coordinator, chat,
publisher or status loop is needed. After admission, retain the fresh shared
publisher lease, branch protection, head CAS and all release gates. Revalidate
main if it changed before the operation; do not force a merge.

### Continuing an already admitted publisher

A reservation created after the existing publisher was acquired must not make
that same publisher wait for a candidate which cannot yet acquire its busy slot.
The same authorized owner may repeat final admission with the exact original
head/base and the publisher ID, author ID and timestamp saved by the original
acquisition readback:

```sh
GITHUB_REPOSITORY=pyatkoff/max-search-bot node tools/release_priority.js --admit PR_NUMBER ORIGINAL_HEAD_SHA ORIGINAL_MAIN_SHA --publisher COMMENT_ID OWNER_ID ACQUIRED_AT
```

This is a continuation of successful ordinary admission and acquisition, never a
way to acquire, renew, transfer or release a publisher. Do not discover an old
busy record and treat it as permission, substitute a new head/base, or copy
another owner's binding. The expected tuple and source identity must come from
that owner's original acquisition evidence. API-only callers pass the same
`publisher: {comment_id, owner_id, updated_at}` in explicit admission context.
This relies on the existing trusted sole-publisher operating contract: the
current tuple authenticates the current bound record, not historical execution
of the earlier priority decision. Required source checks and head CAS remain
mandatory. Ordinary required CI has no publisher continuation exception.

The guard validates every reservation, the target's existing
`release-slot-close:v1` binding and the exact active issue #1122 comment. It reads
that comment again after refreshing the target and main. Only reservations
starting strictly after the recorded acquisition are excluded for this owner,
including later incidents, which already cannot preempt an active publisher.
Earlier or simultaneous reservations retain the ordinary priority decision. A changed source, main,
binding, owner, version or record; malformed metadata; incomplete snapshot; or
failed API read stops without fallback, mutation or retry. Thirty minutes is the
reservation limit, not a publisher lifetime. Age never frees a busy publisher.

The reads are bounded and perform no writes. This is not atomic GitHub exclusion;
the existing owner must still avoid concurrent changes to its admitted record.

This gate protects required CI and the documented merge path. It is not an atomic
GitHub merge queue and cannot stop a privileged bypass or a new reservation made
after its last snapshot. Existing publisher serialization remains necessary.
Do not claim platform-level atomic exclusion or change branch protection as an
incidental part of this feature.

Release the publisher after the original installation and mandatory technical
gates finish. A separate external/browser acceptance remains with its owner and
must not hold the shared release slot. Neither this rule nor reservation expiry
waives a failed installation, data protection or any required gate.

## Verification and rollback

Priority cases live in the existing required CI-scope regression: deterministic
ownership, expiry, source/base changes, trust boundaries, incident priority,
malformed metadata, pagination, API/rate-limit failures, explicit final admission
and CLI failure without a read capability. They never contact production.

Revert this feature through the normal checked release if its admission is
defective. Preserve all pre-existing tests, release leases and production gates.
Removing a reservation is an explicit metadata release by its owner, not a way
to bypass a failed source check.


## Automatic terminal update

The existing post-deploy workflow `modeled-conversion-value-export.yml` now has
a metadata-only `release-slot` job after its unchanged `configure` job. It uses
the same existing workflow serialization. No new workflow, schedule, business
operation, check dispatch, publisher, queue or customer notification is created.
The closer has repository contents write only for the existing public-safe
diagnostics result, actions/PR read and issue-comment write permission. It has no
deployment credential and cannot change main or deploy. Its content writer uses
the fixed diagnostics ref, preserves the existing public JSON allowlist and
removes legacy raw captures; it never commits a downloaded diagnostic archive.

Before the normal expected-head merge, the **same authorized publisher** writes
its one existing issue #1122 record as the exact text
`PR1234: выпуск занят`. Do not create a second record or repurpose another
owner's record. In the existing PR description, bind the read-back comment ID,
original author ID, exact source head and comment timestamp:

```text
<!-- release-slot-close:v1 {"comment_id":123456789,"head_sha":"FULL_40_CHARACTER_SOURCE_HEAD","owner_id":123456,"updated_at":"2026-11-03T19:00:00Z"} -->
```

The IDs and timestamp are the real API readback, never guessed. Only a merged
same-repository PR with that exact merge SHA and source head is eligible.
No marker means no automatic write; old releases are not replayed or relabeled.
This opt-in concerns the technical shared slot. A separate browser/user or
natural-monitor acceptance remains with its current owner, outside this slot.

The original successful main-push deployment starts the existing tail. It waits
for the original deployment, retired-domain guard, strict TLS, MAX and Telegram
health, both diagnostic workflows, dedicated selection host, and the canonical
modeled-conversion monitor to finish
successfully for the same source. The installed pipeline-activation workflow is
also classified: its original run and sole `activate` job must succeed when the
original deployment commit carries its approved activation marker. Without that
marker the original job must be explicitly skipped; workflow success alone does
not prove a safe skip. Missing commit metadata, unexpected activation, missing or
extra jobs, failed activation and unknown outcomes retain the slot. This closer
only reads that existing workflow's metadata; it never requests activation or
changes a schedule. The existing optional configuration job must
be successful or explicitly skipped. Scheduled/manual checks cannot substitute
for original post-deploy runs; duplicate origins or incomplete snapshots stop closure.
The narrowly admitted verification-only second attempt below is the sole rerun exception. An unclassified new post-deploy workflow also stops closure until
its gate is included in the same checked package; it is never silently ignored.
For an ordinary installation, the existing deployment and diagnostic telemetry
must also match this exact source and deployment run. For `application_unchanged`,
the original accepted installation telemetry continues to identify A; the versioned
repository release identifies R and authenticates that inheritance. The closer
also proves that automatic child jobs executed only their finite metadata steps
and skipped the protected operations. No new live probe or acceptance is run.

A same-source scheduled monitor may independently create a skipped activation
while the original deployment tail is running. The closer classifies that child
separately: the exact origin ID must identify a completed original scheduled
monitor with a `success` or `failure` conclusion in this repository, on main and
at the same source; the child must retain that exact origin. Only a completed
child with one original `activate` job,
explicitly skipped and with zero steps, is excluded from release candidates.
The original deployment monitor's activation remains mandatory and is the only
activation written into the terminal proof. A failed independent scheduled
monitor remains a failure in the observed metadata; neither it nor its skipped
child becomes accepted release evidence. In particular, a scheduled consumer
refusing a still-pending release cannot make its harmless child a new closure
gate. Cancelled, pending and unknown parent outcomes still refuse. A pending
independent child shares the existing finite metadata wait; it cannot silently
permit closure. Duplicate
origins, wrong repository/source/attempt, failed or executed jobs, incomplete
metadata and API failures retain the publisher. This classification starts no
monitor, activation, installation or retry and changes no workflow or schedule.

Pending gates are read together every 20 seconds for at most 18 minutes within
the existing server job. Completed gates are not rerun. Failed/cancelled/skipped
mandatory gates, changed main, unavailable/ambiguous metadata, API refusal,
rate limiting and timeouts stop without freeing the slot. There is no retry on
429 or other API errors.

On `gate_deadline`, `release_slot_pending` names the missing original workflow
files and the exact IDs/statuses of still-pending runs. Independently scheduled
children also retain their scheduled-parent ID. This bounded diagnostic contains
only fixed workflow names, validated IDs and allowlisted states from the final
snapshot; it includes no raw API response, log, customer data or credentials.
It neither changes the finite wait nor permits a retry or terminal mutation.
This forward correction does not retroactively close an already failed closer.

An original consumer of an ordinary push installation may briefly await one narrowly pending
Jobs API snapshot before any protected operation. The original Deploy and its
exact required jobs must already be completed successfully; every required step
must be either completed successfully or explicitly `in_progress` with a null
conclusion. The consumer shares one 30-second monotonic budget across its proof
reads, permits at most two additional jobs snapshots ten seconds apart, and
rechecks the same source, attempt, job identities and current main. Missing,
duplicate, failed, skipped or unknown required steps, changed authority and API
errors still refuse immediately. A still-pending snapshot exhausts the bounded
wait without granting acceptance. Manual/scheduled consumers, admission and
recovery attempts receive no such wait. This is read-only metadata convergence
within the original run, not permission to rerun a consumer or replace a failed
original gate.

Before the one terminal update, the same closer prepares `pending_terminal` in
the existing `deploy_status.json` using a non-force diagnostics ref update. The
next job step binds the exact record digest into its original GitHub
step name and uploads the small publication proof. A write or upload failure
retains the publisher. Later consumers require that original digest-bound step
and the successful final terminal step; a record alone or an expired/downloaded
artifact cannot manufacture acceptance. No fail-capable publication follows the
terminal PATCH. Unknown writes are reconciled by reading the original state,
never blindly repeated. After an uncertain transport/decoding result from the
one diagnostics ref PATCH, the existing publisher may prove that exact write
through read-only commit/blob/digest and ancestry checks. Explicit HTTP refusal,
a contradictory returned SHA, unavailable evidence or a different lineage still
hold the slot. This does not authorize another mutation, a new installation
receipt or retroactive recovery of an earlier failed run.

Immediately before the one update, the helper rereads main, original results,
PR binding and the exact publisher comment/version. It updates **only that
comment** to `PR1234: установлено, выпуск свободен` and reads it back once.
An already terminal record is a no-op. A changed record is never overwritten.
An uncertain write/readback result is UNKNOWN: inspect that same record and
original run to establish the outcome; never resend or switch hosts.

GitHub issue-comment edits do not offer atomic compare-and-swap. This narrow
update therefore retains the existing exclusive-publisher operating contract
and serialized post-deploy workflow, with a fresh version check; it does not
claim a new platform-level lock. The publisher must not edit its admitted record
concurrently. Stop on a conflict instead of taking over another owner's work.

Behavioral failure, provenance, waiting, mutation and idempotency cases run inside
the existing CI-scope regression. Its existing workflow-wiring test also checks
permissions, exact checkout, dependency, timeout, and unchanged triggers.


## Recover unfinished verification without another installation

The same Deploy workflow retains six executions (including the two source matrix
lanes). Its source-result aggregate is now the first step of deploy, before
checkout or server credentials. It rejects any installer attempt other than 1.
All sync, migration, ownership, profile, private-log probes and snapshot export
remain in that first installer. The existing verify job follows installation.

After a failed verification, the same publisher may request exactly one native
rerun of that failed verify job. This is not a rerun of Deploy, source checks,
successful siblings or post-deploy workflows. Before protected access, the
metadata-only admission requires the original failed push, unchanged exact main,
merged PR binding and active publisher version. Complete job history must prove
that every source check and installer succeeded in attempt 1. GitHub's copied
successful parents are accepted only when all execution timestamps, runner and
step metadata are identical; a real second installer execution is refused.
Attempt 3, cancelled/unknown history, missing metadata, API refusal and 429 stop.

Within verification, a successful check, transfer, status, publication or cleanup is reused.
A negative published result is refreshed when the failed check/transfer recovers.
The original private snapshot has a SHA/run-owned directory outside the webroot,
one sealed archive and transfer checksum. A failed export is an installer failure
and cannot use this recovery. Failed transfer retains that exact snapshot; it
never regenerates diagnostic input. Cleanup follows successful transfer/status,
reconciles an already absent directory and never deletes original logs or data.
Raw diagnostics are never committed to the public diagnostics branch.

Status and its original digest-bound publication are distinct outcomes. If only
publication failed, recovery binds the same immutable status without writing a
new installation receipt. If only cleanup failed, the accepted original status
and publication remain authoritative. A successful status alone cannot replace
missing publication proof. The unchanged-application route requires attempt 1;
this recovery remains limited to unfinished verification of a real installation.

Existing post-deploy workflows record the upstream deployment ID and attempt in
their run title. Activation records its exact monitor ID and attempt. On recovery
the closer may ignore a first-wave result only with an exact original-parent
title and API proof that every job was skipped with no executed steps. Executed
monitor/notification/configuration/activation work is never silently replayed.
All seven mandatory gates still execute once after successful verification.
The closer itself remains attempt 1, revalidates recovered job history and final
telemetry, and performs the same single publisher PATCH/readback. No new timer,
workflow, queue, publisher, customer message or failure experiment is introduced.

Recovery is effective only for releases containing this package. Do not repeat
an accepted older installation to obtain the new workflow layout.


