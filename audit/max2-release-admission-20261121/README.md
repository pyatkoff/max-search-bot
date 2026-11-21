# Read-only native canonical release admission

Preparation only. The concrete request is the parent-supplied PR1643 / head `de603d8c5b53a5dbf3e972e166da96c42c4e5500`. Admission has not run, and this request must not be published until its required CI is green. The separate template remains null and fails closed.

The immutable BASE is `b678bc2f211c5f141751137453b199036491222d`. Its `release_priority.js` exports `runGuard({env, readEvent, fetchImpl, admission, now, clock})`; the full final path requires a real `GITHUB_TOKEN`, including the prior-release resolver. The documented API-only pure priority decision does not replace that complete prior-release proof. This package therefore uses the explicitly selected native fallback, without a dummy token, a simulated workflow context or a replacement authority decision.

## Exact operation

Root verifies the actual PR/head and completes its required CI before publishing the concrete `admission-request.json`. Retain the fixed base. No publisher tuple is accepted in this first-admission request. The concrete request is separately SHA256-bound by the prepare step; it is not included in the reusable source-file manifest.

Publish this package under `audit/max2-release-admission-20261121/` and the byte-identical `workflow.yml` at `.github/workflows/max2-release-admission-20261121.yml`, on the new `audit/max2-release-admission-20261121` branch, with exact message `audit(max2): verify canonical release admission`.

Only the actual owner's original branch-creation push attempt is admitted. The job has read-only contents, actions, issues and pull-request permissions. It checks out the immutable audit request, the candidate HEAD for identity, and the separate BASE coordinator checkout. Every local coordinator dependency resolves inside BASE. The three canonical module Git blobs and file bytes are verified before and after the call.

The sole authority call is the unchanged full command:

```text
node canonical/tools/release_priority.js --admit ACTUAL_PR ACTUAL_HEAD b678bc2f211c5f141751137453b199036491222d
```

It receives the real platform `github.token` and unchanged native GitHub identity. All API reads, repeated fresh reads, timing rules, priority decisions and previous-release proofs belong to the original canonical code. The runner does not call SSH, acquire a publisher, write repository state, dispatch checks, merge, deploy or close a release. The resulting admission remains a momentary pre-merge check; normal publisher acquisition, binding, fresh required checks and expected-head merge still apply.

The artifact records actual source/run/attempt/job, request digest, candidate/base pins, canonical module hashes and the finite canonical decision or sanitized refusal. It excludes secrets, raw API responses, raw logs and signed URLs. A refusal, malformed output or timeout never becomes approval. No rerun or follow-up admission is created automatically; a later continuation would need its actual publisher readback tuple and separate review.

Local tests cover only request validation, argument construction, fail-closed native-context absence and bounded output decoding. They do not execute the guard, simulate GitHub authority or claim release admission.
