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
