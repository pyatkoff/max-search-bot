# Private selection-context evaluation

This auxiliary branch `analysis/manager-ai-selection-eval-20261018` adapts the
completed encrypted bulk protocol for one new bounded diagnostic study:
**at most36 primary case attempts, zero grader calls, five workers maximum**.
The public task is always fresh and owned by the coordinating controller.
Do not copy, reactivate or replay the completed bulk task or its leases.
This branch is evaluation-only and must not merge into main.

The selected profile remains `gpt-6.1-sol / low / fast`. The helper checks the
actual request projection before spending; actual served model/tier/token counts
are recorded separately, never inferred. Canonical service instructions, client
limits and eight-field result validation are unchanged. Company knowledge is
explicitly null for both series, so this study does not validate the active
company-fact catalog. There is no weight training, customer sending, production
prompt/config/database change or live selection-page fetch.

## Two separate evidence series

Historical cases have exact keys `case_id`, `context`, `provenance`.
Provenance is `{kind:"historical_prefix",cutoff_at,source_sha256,source_alias}`;
timestamps are UTC ISO seconds and aliases are `TGdddd`. Every retained message
has a UTC timestamp at or before cutoff, which is at or before input generation.
Use one cutoff per new history; identified manual reviews and completed automatic
studies remain excluded using the cumulative same-export register. Later edits,
responses, outcomes and current/cached pages observed after cutoff must not enter
this context. Literal links are redacted and stay unread. Missing or truncated
media/history are explicit. Do not claim independence where cross-export identity
overlap is unknown.

Synthetic cases have those three keys plus `selection_fixture`. Their provenance
is `{kind:"authored_synthetic",author:"codex",authored_at}`.
The fixture contains exactly `source_refs` and `responses`:

- A reference is `{message_id,sender_type,url}`, bound to an untruncated retained
  customer/manager message explicitly marked `selections_link_unread=true`.
- A response is `{url,status,type,html}`, with authored UTF-8 HTML at most1MiB.
  At most41responses and41source references may be frozen; the canonical reader
  attempts only the latest2distinct sources. Older frozen responses are never
  fetched. Missing responses are deliberate unread observations.
- URLs use only authored `https://qui-quo.ru/AAAA-0001`-shape capabilities. They
  never enter the model context or authorize an external fetch.

The runner supplies every synthetic read with a mandatory in-memory transport
through the real `ManagerSelectionSnapshot::read()` and typed server-only reader.
An absent/failed fixture cannot fall back to the network. Historical cases receive
no selection reader at all. The canonical parser, source binding, offer caps,
redaction, unread fallback and request-byte guard execute unchanged. Observations
retain `historical_send_snapshot=false`, `current_price_verified=false` and unknown
price basis. Synthetic evidence cannot establish historical answer accuracy.

Freeze membership and separate expected criteria before generation. Rubrics,
provenance, aliases and fixture HTML stay outside model input. Review useful
customer drafts, justified/excessive abstention, factual/constraint errors and
technical failures separately. The reviewer is the same assistant, not an
independent human. No pass-rate improvement from abstention alone is implied.
Do not tune on reserved validation, regrade completed studies, or call outputs
conversion evidence. Future source changes require their own confirmed failure,
regression and publication gates.

## Exact source and private transport

`manager_ai_bulk_envelope.cjs` compiles seven exact canonical files plus the
evaluation helper. Only their explicit local dependency imports are removed;
instructions and class logic are not rewritten. Every original dependency's
SHA256 is included in the compiled bundle and safe preparation/header metadata.
Unknown dependency imports fail closed. The entire compiled bundle has a separate
source hash, generated with:

```sh
node tools/manager_ai_bulk_envelope.cjs bundle-hash
```

Synthetic PHP checks, compiled PHP syntax, Node own-key/wrong-key/tamper checks
and Python queue checks run before any credential step. A fresh public
`phase=verify` task validates this source and bundle without SSH/provider use.
Prepare/evaluate additionally fence authoritative main and actual deployed SHA.

The reused protocol retains a fresh one-use sodium key/lease authenticated through
the fixed HTTPS origin, pinned SSH host key after that proof, sealed-box+gzip
input, encrypted RSA3072-OAEP-SHA256/AES256-GCM output, private0700/0600 material
and exact task/recipient/source/production/input bindings. The input lease is
consumed before config/provider use. Only the existing provider key is read by
config on the server; it is never returned to the controller. No DB/session/bot
bootstrap, business mutation or customer send is used. Temporary proof and
private remote material use the new study-specific prefix.

Started/completed per-case records survive interruption. The controller queue
allows at most36 submitted generation cases in this study database, including
policy results and unknown outcomes. Submitted/unknown/error records cannot
automatically replay. Collect retrieves existing records without provider calls.
The zero-call office check stays a policy output. Retain authenticated private
results durably before deleting the ephemeral output identity, then explicitly
clean every new lease; expiry is housekeeping, not recurring evaluation.

Never publish plaintext cases, source identities, inputs, outputs, private links,
keys or study hashes in GitHub, Actions logs or artifacts. Only safe metadata and
ciphertext leave private execution. Local source checks are not PHP execution or
provider-quality acceptance, and server case durations are not browser latency.
