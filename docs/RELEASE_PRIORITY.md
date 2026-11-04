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
The closer has only repository/actions/PR read and issue-comment write permission;
it has no deployment credential and cannot change main or deploy.

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
for original post-deploy runs; reruns, duplicate origins or incomplete snapshots
stop closure. An unclassified new post-deploy workflow also stops closure until
its gate is included in the same checked package; it is never silently ignored.
The existing deployment and diagnostic telemetry must also match
this exact source and deployment run. No new live probe or acceptance is run.

Pending gates are read together every 20 seconds for at most 18 minutes within
the existing server job. Completed gates are not rerun. Failed/cancelled/skipped
mandatory gates, changed main, unavailable/ambiguous metadata, API refusal,
rate limiting and timeouts stop without freeing the slot. There is no retry on
429 or other API errors.

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
