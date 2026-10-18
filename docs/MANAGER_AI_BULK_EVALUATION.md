# Private K03 historical replay

This AUX branch, `analysis/manager-ai-replay-k03-20261019`, is a dedicated
one-controller diagnostic replay of exactly 100 frozen K02 development prefixes.
It must NEVER merge into main. It does not install K01/K02 candidates, change
instructions or runtime configuration, train weights, send customer messages,
write business state, access a database or fetch a live selection page.

The ceiling is 100 total submitted case attempts, including policy-only outputs,
errors and UNKNOWN outcomes. Concurrency is exactly one. The fixed canonical
profile remains `gpt-6.1-sol / low / fast`, 2200 maximum output tokens per request,
20-second request timeout, zero retries and zero graders. The output reservation
is at most 220000 tokens, not an estimate of actual usage. No exact input tokenizer
is available here; input tokens remain unknown until valid provider usage exists.
Requested profile, served model/tier/usage and timing are separate observations.

## Frozen input and provenance

The encrypted study envelope contains exactly 100 historical cases and cannot
contain authored synthetic cases. Their case keys are exactly `case_id`,
`context` and `provenance`. Cases are distinct by source SHA256 plus source alias.
Conversation IDs are synthetic `1000000 + replay_position` for positions 1–100;
they are never derived from a TG suffix.

Historical provenance has exactly these keys:

- `kind: k02_development_prefix`;
- `cutoff_at`, a strict UTC ISO second derived from the explicit final export epoch;
- `source_sha256` and `source_alias`, owner-attested export identity;
- `cutoff_ordinal` and `source_ordinals`, exactly the complete list 1 through cutoff;
- `source_message_ids`, exactly matching the retained context IDs in increasing order;
- `source_epoch_seconds`, explicit integer Unix seconds for every retained message;
- `source_prefix_sha256`, the unchanged owner-attested K02 delivery-prefix hash;
- `projected_prefix_sha256`, the independently computable adapter tuple hash below;
- `delivery_revision` and `selection_freeze_sha256`, shared by the entire study.

The runner cannot reconstruct original delivery rows, so it validates the
source-prefix hash's format and retains the owner's attestation without claiming
to recompute it. The projected hash is SHA256 of compact ASCII JSON of:

`[source_ordinals, source_message_ids, source_epoch_seconds, message_tuples]`

Each message tuple is `[id,sender_type,text,event_type,created_at,media_unread,selections_link_unread,text_truncated]`.
Only absent `text_truncated` defaults to false. The other tuple fields are required.
PHP uses `json_encode(..., JSON_UNESCAPED_SLASHES)` without
`JSON_UNESCAPED_UNICODE`; Python uses `ensure_ascii=True` and compact separators.
Arrays remove object-key ordering ambiguity and preserve U+2028/supplementary
Unicode equivalence across the two implementations.

Every timestamp must equal the UTC encoding of its explicit export epoch and be
at or before cutoff. Cutoff equals the last retained epoch and cannot be after
input generation. Partial, duplicate, reordered or future ordinals/IDs/epochs,
legacy provenance kinds, mismatched tuples and mixed freezes fail before spend.
Missing/truncated history and unread media remain explicit. Later responses,
outcomes, edits, cached/current pages and reference answers do not enter the model
context. These already reviewed prefixes are development data, not an independent
holdout or new primary archive coverage.

## Canonical pure projection and capture

The seven canonical dependencies are compiled from the fresh main checkout.
Only their enumerated local import statements are removed; client, redactor,
instructions, parser, service, result validation and all caps remain unchanged.
Dependency hashes and the complete compiled bundle hash bind the execution.
Unknown imports fail closed. No canonical file is copied from an older runner.

The same pure evaluation helper calls the canonical `ManagerAiTextRedactor` on
message text before canonical generation in both preflight and execution.
Selection-unread flags follow that projection. No provenance, fixture, rubric,
delivery revision, case ID or capture metadata is added to the model payload.
Company knowledge is explicitly empty/offline.

Before config or the first provider call, every case receives a no-provider
capture through the canonical service seam. Its canonical request must fit the
unchanged 196608-byte encoded request cap. The private preflight record contains
the normalized context actually projected to the model, payload/request hashes,
encoded bytes, instruction hash, requested profile and count of changed message
texts. Totals and these records are retained in the private encrypted header.
Policy cases short-circuit the service seam: their normalized request is explicitly
a counterfactual with `provider_planned:false`, never a claim of a sent request.
The byte total includes these counterfactuals. Hashes alone are not substituted
for reviewable normalized inputs. No input-token count is fabricated.

Per-case `provider_roundtrip_ms` measures the one actual canonical client
invocation inside the provider closure, including errors/timeouts; it is null
for policy-only results. `elapsed_ms` is labeled `service_total`, including
projection, normalization, request and result validation. `context_projection_ms`
is only the offline redactor projection, not a DB snapshot or browser latency.
Valid allowlisted served-model/tier/usage metadata is unchanged; failed responses
do not acquire invented usage, response bodies or private exception strings.

Authored synthetic selection fixtures exist only in unit tests. Their mandatory
in-memory reader cannot fall through to a network fetch; URLs/HTML/provenance
never enter the model. The real 100-case envelope rejects this fixture path.

## One-use execution and recovery

The task, input, output, lease, encryption and AAD schemas use the fresh
`manager_ai_k03_*_v1` namespace. Private remote directories, public temporary
proof paths, runner directories, artifacts and workflow concurrency also have
fresh K03 names. Never copy or reuse an old task, key, lease or study queue.

Node task and bundle validation, PHP unit checks/compiled syntax and Python queue
checks run before Actions accesses credentials. A `phase=verify` task uses no SSH
or provider. Prepare/evaluate retain authoritative-main and actual-deployed SHA
fences. A fresh one-use sodium lease is authenticated by exact public preparation
bytes at the fixed HTTPS origin; later SSH uses the pinned host key. Input is
sealed-box plus deterministic-gzip ciphertext; private output uses
RSA3072-OAEP-SHA256 plus AES256-GCM with exact task/source/recipient bindings.
Keys and plaintext remain 0700/0600 private material. Only ciphertext and safe
public prepare/cleanup receipts are artifacted. The public proof contains no
cases, normalized contexts or source-prefix hashes.

The lease is consumed before config, preflight and provider use. A remote
evaluation has a 2400000 ms overall deadline (40 minutes), SSH is bounded by
2520 seconds (42 minutes), the job by 50 minutes and the lease by 3600 seconds
(60 minutes). Every worker launch rechecks a 30-second margin before both the
lease expiry and monotonic remote deadline. There is never more than one worker.
Three global errors or an UNKNOWN worker stop further launches. Remaining
submitted cases still consume the attempt reservation and cannot be replayed.

The fresh private queue atomically reserves exactly 100 cases under
`BEGIN IMMEDIATE`; policy/error/UNKNOWN cases all spend the same global ceiling.
New membership freezes after submission. No automatic retry, expansion,
replacement or second paid batch is permitted. Started/completed remote records
survive interruption. Collect only retrieves records and invokes no provider.
Retain authenticated encrypted/private results durably before deleting the
ephemeral output identity; then explicitly clean the new lease and proof.
Expiry is bounded housekeeping, not recurring evaluation.

Review drafts against their exact captured prefix and criteria prepared before
generation. Useful answers, excessive/justified abstention, factual or constraint
errors, policy results and technical failures are separate outcomes. Same-assistant
review is not independent human review. No conversion evidence, quality gain,
current-company fact approval or live customer result follows from this study.
No plaintext case, normalized input/output, source identity, private URL or key may
appear in repository files, Actions logs or public artifacts.
