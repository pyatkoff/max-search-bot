# Ready release priority

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
separately: the exact origin ID must identify a successful original scheduled
monitor in this repository, on main and at the same source; the child must retain
that exact origin. Only a completed child with one original `activate` job,
explicitly skipped and with zero steps, is excluded from release candidates.
The original deployment monitor's activation remains mandatory and is the only
activation written into the terminal proof. A pending independent child shares
the existing finite metadata wait; it cannot silently permit closure. Duplicate
origins, wrong repository/source/attempt, failed or executed jobs, incomplete
metadata and API failures retain the publisher. This classification starts no
monitor, activation, installation or retry and changes no workflow or schedule.

Pending gates are read together every 20 seconds for at most 18 minutes within
the existing server job. Completed gates are not rerun. Failed/cancelled/skipped
mandatory gates, changed main, unavailable/ambiguous metadata, API refusal,
rate limiting and timeouts stop without freeing the slot. There is no retry on
429 or other API errors.

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
never blindly repeated.

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


## Finite PR1420 late-closure maintenance exception

This exception is limited to the already installed application
`5c18f7a49e1fba15e58f7e6a266c2b3a5e7527f2` (P), original Deploy
42599160592/1 and failed closer 42599880179/1. Its original required activation
42603556603 is the skipped, zero-step child of monitor 42599880395/1. A generic
failed closer, scheduled substitute, another source, rerun or an unknown terminal
write cannot use this route. Original failure and installation remain immutable.

The owner explicitly approved the eight-file recovery scope on 2026-11-09 after
the local audit explained the no-CI-change exception. Implementation must receive
independent review and required CI before promotion. The existing SOURCE
6727936549 and publisher 6682640708 remain the only claim and slot; do not create
another slot, rewrite its original PR1420 binding, or revive historical packages.

A reviewed maintenance head H is promoted as an ordinary two-parent merge Q
with P as first parent and exactly H's tree. It is operational source, not a new
application installation. Both the PR title/merge message and H use
`[anytour-pr1420-closure-recovery-v1]`. The maintenance PR has exactly one marker:

```text
<!-- release-slot-recovery:v1 {"source_head":"<H>","base_sha":"5c18f7a49e1fba15e58f7e6a266c2b3a5e7527f2","changes_digest":"<canonical manifest SHA256>","diagnostics":{"commit":"<captured ref>","blob":"<deploy_status Git blob>","record_digest":"<canonical record SHA256>"}} -->
```

The complete immutable Git-tree comparison must contain exactly the eight
reviewed regular-file changes in `RECOVERY_PATHS`, with unchanged file modes.
The canonical manifest is the path-sorted array of
`{path,old_mode,old_blob,new_mode,new_blob}`, hashed by
`deployment_release.digest`. No additional path, rename, symlink, submodule,
configuration/migration/runtime change or modeled value/activation marker is
admitted. Directory hashes alone are not file changes. The intent does not
embed its own merge SHA and is not a general development-only exemption.

Q promotion automatically starts one original first-attempt Deploy DQ. Its
admission authenticates Q, the exact original installation, original publisher
version and captured diagnostics reference before SSH credentials. DQ publishes
only a digest-bound recovery plan. Key preparation, bundle/sync/migrations,
ownership/cron/profile/smoke, diagnostics capture and installed verification are
skipped. The existing downstream resolver authenticates that plan and returns
`perform_actions=false` for Q's automatic technical children. The existing
value/tail workflow stays byte-identical; business configure must be skipped.

The existing first-attempt release-slot X follows DQ. It separately validates
the original P gates, failed closer/job and recognized gate_deadline log, skipped
old upload/terminal steps, and exact late activation/job. It writes a strict
repository_release schema2 `terminal_recovery` record: repository source Q,
application P, original installer run/stages unchanged, every maintenance server
stage explicitly not performed, original failure preserved and
`baseline_eligible=false`. No new observation or healthier business flags are
invented. Original ops/snapshot data and privacy fencing remain intact.

One non-force diagnostics ref update uses the captured parent/blob/digest; any
drift stops rather than rebasing. X binds its record digest and exact publication
commit in the existing uploaded proof. The final process verifies that exact
proof, current Q, original PR1420 binding and publisher identity/body/version
before its single terminal PATCH to
`PR1420: установлено, выпуск свободен`. GitHub comments have no atomic CAS:
the exclusive retained lease and existing workflow serialization are necessary.
A non-force ref update also is not an expected-old-SHA conditional API.

After X completes successfully, its authenticated digest-bound terminal step
seals the exact PATCH/readback result. Historical authentication does not require
the mutable publisher comment to keep PR1420's old text: the next ordinary PR
may legitimately reuse that same slot. This never relaxes the fresh original
binding/body/version admission immediately before X's one PATCH, and a failed
or unknown X is not accepted as a closed historical record.

Any unknown write, response or readback is HOLD, never permission to resend,
rerun, choose another key or free the slot manually. Exact terminal text alone
does not establish acceptance: the successful authenticated producer and bound
record are required. An unsuccessful producer after an unknown PATCH requires
separate read-only reconciliation; this route supplies no automatic retry.

Readers retain strict v1 handling. After Q closes, ordinary future release N
can authenticate Q historically and prove Q is its ancestor, but P is only the
previous installed receipt, never an eligible optimization baseline. The
recovery branch of acceptedBaseline returns null explicitly. Therefore even a
content-only N requires its own ordinary authorized installation and its own v1
installed receipt with application/repository SHA N and DeployN; while N is
pending, consumers defer. Q's intent cannot activate on N. This does not request
an N release or an installation replay of P, and administrative closure is not a
condition of constructor readiness. Booking/OAuth/customer live acceptance
remains a separate, deferred stage.

