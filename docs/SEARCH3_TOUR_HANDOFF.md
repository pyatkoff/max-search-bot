# Selected Search3 tour → existing native conversation

Status: receiver source contract only. Activation is held. The committed producer
public-key allowlist is empty; the endpoint returns503 rather than trusting a
browser observation. Source, current-head CI, installed endpoint, connected
producer and natural native arrival are separate acceptance facts in issue#55.
This continues constructor SOURCE6369459360/checkpoint6369864074, not Search3/INT
implementation. It does not add another sender, transcript, queue or model call.

## Producer responsibility and activation

SEARCH owns its selection/lifecycle and server producer; INT owns retained
supplier/session/price facts. Before signing, that owner must bind exactly the
chosen row, room, party and selected flight pair (or explicit deferred flights)
to its retained immutable server record/revision. Signing arbitrary posted
browser fields is not an acceptable fallback. The881f observation module and a
diagnostic quote receipt do not establish that provenance. Receiver activation
requires a reviewed exact retained-record example, signing ownership, dedicated
public verification material and end-to-end native proof. No supplier lookup,
fresh search/quote or API session credential is requested by this receiver.

The authorized deployment owner retains the dedicated Ed25519 private key in
its existing protected custody. It is never sent to the browser or this repo.
Only public keys are pinned in resources/search3-handoff-producers.json:

```json
{"schema":1,"keys":{}}
```

After acceptance, an entry keyed by a1–48 character lowercase `[a-z0-9_-]` ID has
`producer:"search3"`, `project:"anytour"`, integer epoch-second `not_before` and
`not_after`, and `public_key` (standard base64 of32 public bytes). Expiry/removal
closes outstanding capabilities. Rotation is a reviewed source/deployment change,
not a caller-controlled URL, environment override or borrowed bot/lead/AI secret.
Tests inject ephemeral verification keys through reflection, never a runtime
test switch or production private key. PHP sodium is mandatory.

## HTTP and signature

Server-to-server `POST /search3/tour-handoff.php`, `Content-Type: application/json`,
max32768 raw bytes. Browser Origin or cookies are denied; no CORS/session auth.
Responses are private/no-store/noindex/no-referrer, with no new session cookie.
Headers:

| Header | Meaning |
| --- | --- |
| X-Anytour-Search3-Key | Accepted dedicated public-key ID |
| X-Anytour-Search3-Timestamp | Ten-digit epoch seconds, within±300s |
| X-Anytour-Search3-Signature | Canonical unpadded base64url of64 signature bytes |

Sign the exact UTF-8 message below, LF separators, no final LF:

```text
anytour-search3-handoff-v1
POST
/search3/tour-handoff.php
{timestamp}
{lowercase SHA256 of the exact raw JSON request bytes}
```

Authentication runs before SQL. Body reserialization is a different intent hash;
the producer must retain the original create bytes/operation for readback.

## Create input

Root contains exactly `action:"create"`, `operation_id` (random32 lowercase hex),
`binding:{record_id,revision}`, `observation`. The private retained record ID is
1–128 characters `[A-Za-z0-9:_-]`, revision integer1..2147483647. Neither is put
in native bot links or the Manager2 projection. All nested objects require every
listed key, including nullable fields, and reject extras. Snapshot max24576
encoded bytes; new capture age at most15min, future tolerance60s.

`observation` is the exact881f browser-observation schema, always unverified:

| Object | Required fields and bounds |
| --- | --- |
| root | schema1, kind=search3_browser_observation, capturedAt epoch milliseconds, source anex/tourvisor/andromeda, hotel/trip/stay/flights/price |
| hotel | uiId positive31-bit integer, namespace=search3-ui, name≤191, resort≤300; no LOCAL match promotion |
| trip | origin≤180, countryUiId≤80, departure YYYY-MM-DD, nights1..365, stayEnd nullable YYYY-MM-DD, stayEndBasis shown-stay-end/unknown, adults1..6, childAges ordered0..17 integers, max3 |
| stay | room≤500 and meal≤300 required nonblank; placement≤500, operator≤300 |
| flights | state selected/manager-deferred/not-selected; outward/inward arrays |
| price | amount, currency, state, description≤1000, expiresAt, basis, finalPriceVerified=false, authority=browser-observation-only |

Strings must be valid Unicode without control characters. Repeated infant ages
remain ordered duplicates. stayEnd is present iff its basis is shown-stay-end,
later than departure, and is never presented as a return flight.

Selected flights require1–4 explicit legs in EACH direction. A leg is exactly
`{label}` (nonblank≤1000) OR `{number,carrier,departure,arrival,baggage,carryOn}`.
Number≤120, carrier≤180; points are exactly `{date,time,town,port,portCode}` with
bounds80/80/160/160/80 respectively. Time/baggage/carryOn can be null; baggage
and carryOn≤160. Deferred/not-selected directions must both be empty: no first
flight substitution.

Price amount is a canonical positive decimal string (max9 whole digits and2
decimal places) or null; currency RUB/EUR/USD; state listing/checked/estimated/
unknown/expired. Null only unknown/expired; unknown cannot have an invented
amount. `expiresAt` is nullable epoch seconds. `basis=selected-pair` iff selected
flights, otherwise shown-offer. A browser checked state is not a verified full
fare. Price expiry does not prevent discussing the frozen historical conditions.

## Receipt, repeat and revoke

One private060 capability row per project+operation binds producer key and exact
raw-body hash. Repeating the same bytes returns the original links; changing
conditions under that operation returns409 operation_mismatch. Status/revoke root
contains exactly `{action:"status"|"revoke",operation_id,request_hash}` where the
hash belongs to the original CREATE bytes. It uses the same signed channel.
Missing is an explicit read-only result, not a newly minted link or cancellation
tombstone for a request that has not arrived yet. Revoke closes both stored
links; retrying create never revives a revoked row.

Success includes context_id, request_hash, expires_at (UTC) and state
ready/revoked/expired/unavailable. Only ready includes `links.telegram/max` using
the existing configured bot URLs. Payloads are distinct random128-bit `s3_`
tokens (35bytes), not public offers, contacts, exact ages or supplier refs.
Capability lifetime24h is separate from quoted-price expiry. Current active
project, key validity and exact bot binding are rechecked before native acceptance.
Bad auth401; invalid shape422; unavailable producer/storage503; quota429.

Creates are capped at5000/project/day under the project lock. Opportunistic
cleanup deletes at most500 capability rows older7days per accepted new create;
this is not guaranteed scheduled erasure. Canonical inquiry history is retained.

## Native arrival and Manager2

Link possession alone cannot identify a tourist. Only the existing trusted private
Telegram/start or MAX/bot_started envelope resolves its own platform capability.
Project→actual conversation→capability locks recheck revocation and exact identity
before a single canonical tour_search_inquiry and labelled inbound message commit.
A forwarded relative uses their own conversation/default source, never another
customer's assignment/history/contact. No phone gate or supplier call is added.

Existing ConversationRecorder/handoff/feedback once intents finish normal queue
entry and recovery. UNKNOWN feedback is not replayed. Strict native likes,
delivered messages and manager publications are not fabricated by this path.
Manager2 shows a separate selected-search-tour section with frozen room/party/
flight conditions and honest observed/estimated/unknown/expired whole-party
price. The private binding and capability internals are omitted. No send occurs
at producer create/open or manager read. General collection, multi-source browser
import, physical Chrome/Yandex and owner-only A1–A8 acceptance remain separate.
