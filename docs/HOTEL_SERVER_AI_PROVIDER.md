# Server hotel AI adapter on the existing Responses transport

Preparation only. No factory registration, config change, live request, import,
campaign, migration, merge, release or enablement is performed by this package.
The accepted retired queue and19 historical batches remain unchanged;
CENTRAL41 remains historical UNKNOWN. No Mac access is used.

## Implemented path

HotelServerAiProvider implements the existing HotelServerProvider plus an optional
local-only preflight. The existing cycle executes that preparation before it
persists an attempt or reserves a send. It still owns UNKNOWN before network,
the once-only stage/role admission, independent review and atomic acceptance.
An in-process second send of the same attempt is also refused. Cross-process
once-only authority remains in the existing cycle, not a second adapter journal.

ManagerDraftClient::requestHotelJson is a role-specific entry point on the SAME
existing HTTPS/cURL transport. Manager requests retain their original schema,
profile, image budget, timeout and behavior. The hotel request uses a separate
strict schema and the existing configured model/reasoning/tier. Its deadline
remains20seconds, connect3seconds and output ceiling2200tokens; it has no images,
web tools, automatic retry, prior response/session or model fallback. store=false
is retained. Requests and streamed responses keep the existing byte caps and TLS/
no-redirect controls. No additional HTTP client or source-reader infrastructure
is introduced.

Only completed assistant JSON plus a real response ID, actual model and consistent
input/output/total token usage is accepted. Refusal, incomplete output, lost ACK,
timeout or missing trustworthy invocation metadata stays UNKNOWN. A received refusal,
incomplete generation or invalid candidate/review with verified invocation/usage
evidence is persisted as a known rejected result: the campaign holds, no candidate
or acceptance is created, and the response ID remains available. Transport success
is distinct from business acceptance. The core never retries or refunds either
outcome automatically. Responses IDs are private invocation evidence,
not ChatGPT/native task IDs and not a native-terminal claim.

## Research means assigned retained evidence, not fresh browsing

The immutable campaign plan must explicitly set research_mode to
assigned_retained_excerpts. A missing or different mode cannot silently use old
materials as a fresh-web research result. The constructor's policy uses the same
source_mode. This path can extract a NEW unaccepted hotel fact from supplied
approved material and independently check it. It cannot acquire a new web page.

Each assigned stage_contexts.dependencies.archives string contains this bounded
JSON envelope (all fields mandatory):

- schema: hotel-assigned-source-slice-v1
- sources: one to eight exact objects with url, excerpt, sha256, captured_at, kind
- kind: retained_excerpt; sha256 is over the exact excerpt bytes, not an asserted
  hash of an unread full web page; captured_at is an explicit UTC epoch
- URLs must be syntactic HTTPS without userinfo/non443 ports, literal IP or local/
  internal host suffixes. The adapter makes no DNS or source HTTP request.
- Every excerpt is <=4096bytes and must satisfy the private maximum source age.
  Existing outer8192byte stage-dependency bounds still apply.

The producer may return only the exact assigned candidate and citations. Every
quote must match an exact substring of an assigned excerpt at that URL for that
scope. A hallucinated quote, foreign URL, missing source or insufficient evidence
cannot become an accepted candidate. Source-byte provenance still depends on
the private input preparation; matching a quotation is not independent proof of
the web page's authenticity or of the answer's entailment.

The reviewer uses a distinct server-owned role principal and a separate stateless
Responses call. Its verdict must cover every assigned scope exactly once and pin
the unchanged candidate/criteria through server-owned lineage. Overall approval
requires all individual checks to approve. Logical role principals are not a claim
of separate vendor accounts or different underlying models. Model output cannot
set attempt IDs, principal, budgets or queue transitions. Raw WORKING, full corpus,
retirement controls and internal packet/receipt IDs are not model request fields.

## Private policy and budget boundary

No default production policy is supplied. A trusted operator-owned policy must pin
the existing configured model; distinct producer/reviewer principals; valid_until;
max_source_age_seconds; max_scopes<=4; request_byte_ceiling within the existing
client cap; input_token_ceiling; budget_attestation_sha256; and source_mode.
There is no provider availability probe in ready/preflight. A default real client
is unavailable without the existing configured key/cURL; offline tests inject an
explicit synthetic transport. That injection does not configure the runtime.

The policy's input-token/byte ceiling is an externally reviewed model bound, not
a token count invented from character length. Preflight checks the prepared body
against it and the plan's full token allocation including the existing output cap.
Actual returned token counts are checked after completion. A violated bound holds
the original attempt rather than retrying it.

The cycle already reserves the full per-call money ceiling. Adapter result
usage.micros retains that full reservation and explicitly labels it
full_reserved_ceiling_not_provider_invoice. Measured provider token usage is stored
separately. No API price, real monetary invoice or zero-cost claim is invented.
An attestation hash is a reference to operator evidence, not a certificate verifier.

## Exact remaining contracts and owners

| Missing/currently unproven function | Owner and return contract |
| --- | --- |
| Materialize each assigned source slice from retained approved bytes | Existing PR1274 hotel input-preparation owner; prepare exact scoped excerpt/hash/capture-time/provenance and preserve the original file references. HotelServerInputs::load only reads local prepared files; it does not perform this conversion. |
| Fetch a fresh hotel source when a future stage actually requires fresh-web research | Existing hotel server evidence/source owner; required method fetchAssignedHotelSource(url, scope, byteLimit, deadline) must return exact fetched bytes/final URL/retrieval time/hash under the project's authorized egress rules. No such method is exposed by AiClient or ManagerDraftClient. It is not implemented or replaced by model recollection here. |
| Certify the private request-token and monetary reservation policy | Existing AI profile/provider-budget owner; required certifyHotelRequestBudget(profile, byteCeiling, inputTokenCeiling, outputCap, moneyCeiling) must identify the reviewed bounds/rate assumptions/validity. The current provider response supplies tokens, not an authoritative money invoice. No new pricing subsystem is created. |
| Recover an original response after a lost ACK with no usable response ID | Existing attempt-reconciliation owner; recordResult exists for independently obtained SAME-attempt evidence, but this transport has no supported lookup by local attempt alone. UNKNOWN never authorizes another send. |
| Real retained input files and full19-semantic/quarantine/reserve mapping | Existing PR1274 input owner; current bounded server check did not locate/verify them. No archive/accepted batch is reconstructed, re-researched or imported by this source change. |

These are launch gates, not reasons to leave the available source integration as
another callback placeholder. The adapter's supplied-evidence HTTP request/parser,
quote checks, independent coverage and once-only integration are concrete.
A live full collector is still not claimed ready. The existing result transaction
also records known rejected responses with their IDs/usage and zero acceptance;
no new observation table or separate journal was introduced.

## Verification and references

The new required regression uses synthetic completed HTTP envelopes through the
real hotel response parser, never a real provider. It checks unchanged legacy
body/profile, strict role schemas, response-ID/usage/status refusal, exact quotes,
independent coverage, missing/stale/corrupt sources, local budget/mode refusal
before attempt persistence, lost ACK/no-resend and one canonical acceptance.
Accepted77core checks are not manually repeated; ordinary required CI may execute
them because the transport/local-preflight integration changed.

The API supports strict JSON-schema output; schema conformance does not establish
factual truth, and refusals/incomplete responses need separate handling.
[Official structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses).
Response identity, status and token usage are distinct API fields.
[Official response reference](https://developers.openai.com/api/reference/resources/responses/methods/retrieve).
