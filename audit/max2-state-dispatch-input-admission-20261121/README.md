# Normal release admission for the MAX2 optional dispatch input correction

This is a new, finite, read-only native invocation of the existing full canonical
release-priority admission. It does not install or activate MAX2, call a provider,
take a publisher, edit a comment, merge a PR, or recover/replay an earlier run.

## Exact coordinator source

Both the admitted base and the coordinator checkout are the actual main commit
`adfaf7877fc15ea7017cf8750ed44a06dc315b5e` after PR1644. Its exact tree is
`49660f0e04f52a4ad8e759b426004cd9cc6f176d`. Fresh immutable source reads confirm
that all three core helpers are byte-identical to the accepted PR1645 base
`16631e06f4eca1f95ee7a5216a1c5e07e563d06d`. The candidate is checked out
separately only to verify its exact identity; no candidate code is executed.
The unchanged coordinator runs the complete command:

```text
node canonical/tools/release_priority.js --admit PR_NUMBER EXACT_HEAD_SHA adfaf7877fc15ea7017cf8750ed44a06dc315b5e
```

This includes `verifyPreviousRelease` and its original installation, terminal,
publisher and current-main checks. All transitive local requires belong to the
same immutable coordinator checkout. The three exact core Git blobs, sizes and
SHA256 digests are in `canonical-source-pins.json`; the runner independently
pins their Git blobs and lengths before and after the call. Source was read from
the immutable GitHub ref and matched to exact local bytes. The priority fixture
is that source file byte for byte, used only by source tests.

There is no repair-source coordinator override and no `--publisher` continuation
input. This is the ordinary initial admission path for the integrated PR1646.
At this preparation checkpoint, PR1644's original Deploy44923138788/attempt1 is
still running. Its installation and terminal acceptance are not asserted here.
Root must prove that original release complete before native admission; the full
canonical previous-release check remains mandatory. No prior admission,
including run44919222495, is reused.

## Request remains unsealed

Both `admission-request.template.json` and `admission-request.json` deliberately
have `pr_number: null` and `head_sha: null`. They refuse before any canonical call.
Root has integrated candidate PR1646 at head
`8d28ad527c93513b03e7a19c4631f59103ded472` on the new base; its required CI is
still pending at this preparation checkpoint. This is preparation context only.
It is not a sealed request or an assertion that its required CI has passed.

After the exact PR has full required CI and independent review, root must obtain
a fresh actual PR head and main, then replace only the active request with the
five concrete keys. The template stays null. If main is no longer the pinned
base, this package stops: integrating the new main and reviewing a new base/core
binding must precede sealing. Changing only the request cannot bypass the base
guard. All legitimate reservations retain the unchanged canonical priority
decision. PR1646's recorded first reservation is not renewed by this package;
no expiry, current ownership or release acceptance is inferred from this document.

The request digest is bound by the initial audit commit and the prepare/run
handoff. Hashes identify reviewed bytes; they are not an authorization token.

## Original native operation

Repository: `pyatkoff/max-search-bot`.

Branch: `audit/max2-state-dispatch-input-admission-20261121`.

Exact commit message:

```text
audit(max2): verify dispatch-input release admission
```

Publish the files listed by `source-pins.json`, that manifest, and the root-sealed
`admission-request.json` under `audit/max2-state-dispatch-input-admission-20261121/`.
Copy `workflow.yml` byte for byte to
`.github/workflows/max2-state-dispatch-input-admission-20261121.yml`.
No other local package, private material or earlier operation is included.

The workflow accepts only the actual owner's original branch-created push,
attempt1, exact source/workflow/job and request. It uses the real GitHub platform
token with repository contents/actions/issues/pull-request read permissions.
All checkouts disable persisted credentials. Native environment and fresh API
reads remain genuine; there is no fabricated token, event, clock or API answer.

One call invokes the full canonical CLI. The unchanged canonical output contains
the five decision keys `allowed`, `reason`, `holder_pr`, `expires_at`, and
`active_reservations`; the decoder preserves a refusal and rejects unexpected
success output or stderr. The bounded artifact is
`max2-dispatch-input-admission-result.json`, with actual audit/run/request and
coordinator provenance, including `coordinator_source_sha` equal to the base.
It has no raw logs, credentials or business data. A timeout or refusal does not
acquire a publisher and is not automatically retried.

After actual ADMITTED readback, the existing owner still performs the same
ordinary publisher acquisition/readback, exact PR binding and expected-head
merge. This read is not a lease, source CI replacement or atomic exclusion.

## Local source verification only

```sh
python3 -B -m unittest discover -s tests -p 'test_*.py'
```

Fixtures exercise closed drafts, stale bases, forbidden coordinator/publisher
overrides, native-context rejection, immutable core identity/byte drift and the
actual CLI output contract. Their API/context values are explicitly synthetic.
They do not execute the canonical CLI, access GitHub, start a native admission,
prove real CI, or establish a production result.
