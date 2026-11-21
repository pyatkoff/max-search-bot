# MAX2 atomic public pair analytics — prepared source only

No provider request, SSH connection, database read, workflow, Git mutation, or financial action has been performed by this package. Root supplied accepted serving provenance: source 4f107fffacd1a3f7e69ef3d7caed5a9928a350b6, original run 44889151835 / job 136800938412, attempt 1, observed at 2026-11-21 09:21:14 UTC. This package does not itself verify serving or natural delivery; the new analytics read remains unexecuted here.

## Source and credential boundary

The original scope is pinned in source-pins.json: max-search-bot ca1b2e5277a35168d892a9de89c10ad93d3a2e20, reader/runner/workflow audit/max2-public-params-20261121. Its workflow passes STANDBY_DEPLOY_SSH_KEY, STANDBY_DEPLOY_HOST and STANDBY_DEPLOY_USER to a pinned SSH connection. The reader runs as Python stdin in /var/www/anytoour/data/www/d.anytoour.ru, proves that exact root and yandex-direct-autopilot origin, and reads the existing local YANDEX_OAUTH_TOKEN only with READ_ONLY=true and sandbox disabled. No token is returned. Those are the existing credential roles; this preparation adds none.

The original workflow contains only a public report key. Its runner uses AES-256-GCM and wraps the random AES key with RSA-OAEP-SHA256. It never creates or exports the private report key. Nothing in this source proves where the original operator retained that private key. Do not look for it in another run or attempt to reuse the old artifact.

For a new admitted read, root creates and retains a new RSA3072 key pair locally, with private-key permissions 0600. The carrier receives only REPORT_PUBLIC_KEY_B64 and REPORT_PUBLIC_KEY_SHA256. The local public key hash must match before credentials or SSH are used. No operational key has been created here. Decryption stays with root, authenticating source SHA, artifact provenance and exact AAD before interpreting the report.

Prepared carrier branch and repository folder: audit/max2-public-pair-params-20261121. It requires one original, unforced branch-creation push, attempt 1. The workflow template retains repository/actor checks, pure tests before credentials, pinned checkout/upload actions and ciphertext-only artifact. Root authenticates the accepted pair installation before admitting that branch; this preparation is not an execution request or a replacement release workflow.

New AAD: anytour-max2-public-pair-98615635-20261121-<workflow_sha>.
New artifact directory: encrypted-max2-public-pair.
New filename: anytour-max2-public-pair.enc.json.

## Query contract

The actual tour-max a0d9b5136acd9e13078e9765d454b41e0b744f48 producer sends:
max2_public_pair_v1 = {key: 'p' + saved post_id + '__' + saved offer_id}.
The saved public IDs are strings, with positive ASCII decimal components of 1–19 digits and offer prefix max_. It also keeps the old separate public-context fields. Those older independent fields cannot be cross-joined into measured pairs.

Exactly six GET attempts, with no retries or pagination:

1. /management/v1/counter/98615635 — exact counter and timezone metadata.
2. /management/v1/counter/98615635/goals — retained MAX2 URL goal 563541588 and exact modeled action 678187097 / anytour_modeled_value_v1.
3. all_visits — counter-wide totals.
4. tagged_visits — unique provider visits containing the namespace and key.
5. tagged_max2_visits — the same segment with MAX2 reached, for same-visit overlap.
6. pair_values — the parameter tree grouped by levels 1, 2, 3, accepting only the exact namespace/key branch and valid atomic string.

Each reporting query has the same fixed metrics: visits, users, reaches/visits/users for each retained goal, and modeled convertedRUBRevenue. The exact query plan is in reader.py; any altered endpoint, counter, date, timezone, metric, dimension or filter is rejected.

The per-key GET is:

```text
GET https://api-metrika.yandex.net/stat/v1/data
ids=98615635
date1=2026-11-21
date2=2026-11-21
timezone=+03:00
accuracy=full
lang=en
include_undefined=true
offset=1
limit=100
dimensions=ym:s:paramsLevel1,ym:s:paramsLevel2,ym:s:paramsLevel3
metrics=ym:s:visits,ym:s:users,ym:s:goal563541588reaches,ym:s:goal563541588visits,ym:s:goal563541588users,ym:s:goal678187097reaches,ym:s:goal678187097visits,ym:s:goal678187097users,ym:s:goal678187097convertedRUBRevenue
filters=EXISTS(ym:s:paramsLevel1=='max2_public_pair_v1' AND ym:s:paramsLevel2=='key')
```

The query is encoded with urllib.parse.urlencode, including %2B for the timezone plus. The fixed Moscow day is 2026-11-20 21:00:00 UTC through 2026-11-21 21:00:00 UTC exclusive. If the intended read moves to another day, root must reseal the explicit date scope and tests before publication. There is no rolling-date input.

Limits: six GET attempts total, 15 seconds per request, 180-second total alarm, 2,000,000 bytes per provider response, 4,000,000 bytes returned, and 100 grouped rows. HTTPS certificate and hostname verification are required; proxies and redirects are disabled. HTTP errors retain short machine codes only. A 401/403 stops remaining queries; no retry is attempted. Missing/unsupported MAX2 condition metadata is explicitly partial; a known different URL goal or changed modeled target stops the read.

## Safe output and interpretation

Per-key rows contain only the canonical atomic key, its public post/offer components and numeric counts. Raw URLs, ClientID, UserID, YCLID, parameter branches outside the allowlist and arbitrary provider text are not returned. Invalid/undefined keys are counted without retaining their text.

Every query preserves its exact request, provider totals, sampled/sample_share/sample_size/sample_space, total_rows, total_rows_rounded, contains_sensitive_data and data_lag. Missing quality flags are unknown, not false. Rounded row totals, suppression risk, sampling or truncation prevent a complete-table claim. Complete table quality does not mean mature, final data or complete coverage of all eligible saves.

One visit can retain several pair values. Thus grouped visits, goal counts and modeled RUB are nonadditive across keys; they are never allocated to a single hotel. Unique tagged totals and the MAX2-tagged totals are stored separately. An atomic public pair proves only that the provider reported that string in that visit segment. It does not prove causal hotel-to-entry attribution, a distinct customer/request, purchase, Direct attribution, or the historical version selected by the visitor.

Coverage divides pair-tagged visits by all counter visits, with the exact denominator label. This is not a successful-save delivery rate. Missing reports and unrecognized keys remain unknown; a zero result cannot on its own separate no natural traffic, blocked analytics, best-effort loss and provider delay.

The date filter applies to visit start time, not parameter arrival time. A visit begun before installation or near midnight may acquire the new parameter later. The provider normally processes fresh data after a delay; preserve data_lag and the read timestamp. This query is not a strict post-install timestamp cohort and cannot yet calibrate purchase probability or hotel economics.

## Subsequent strict registry join — separate bounded read

The pair carries a saved public route/publication key, not a proven app table primary key, native MAX external_message_id, hotel_id or price version. Do not join a numeric suffix or hotel name by coincidence.

For at most the 100 accepted pair strings, reuse the existing strict inventory/source mapping to prove the platform, channel, publication and offer together. Unmapped, conflicting or multiple mappings must remain separate unknown/ambiguous groups. That prerequisite mapping is not reconstructed from the old independent Metrika post/offer aggregates.

The current immutable ChannelPostRegistryService contract provides:
- post_channels.platform/channel_id/project_id/source_id;
- post_publications.id/post_channel_id/source_id/external_message_id/current_version_id;
- post_versions.id/publication_id/version_number/content_hash/snapshot_json/created_at/state;
- post_entries.publication_id/version_id when a separately authorized exact entry link exists.

versionForPublication(publicationId, versionId) verifies the version belongs to that publication; versionForEntry also verifies entry/conversation/project. This is a source contract, not an authorization to read customer rows. The future registry-only reader should use bounded indexed SELECTs, a read-only transaction and an output allowlist, without exporting texts or customer identifiers.

A historical price requires an exact saved version or reliable version history covering the observation period. current_version_id alone proves only the current reference version. With only this daily pair report and multiple plausible versions, retain version/price unknown or show separate reference-price evidence; do not label it price-at-visit.

Where a version is pinned, parse its bounded snapshot text internally with the installed, hash-pinned pure ModeledConversionDealValue::publicationRange. Export only the version/hash and numeric low/high/point/unit/party/basis metadata, with price_verified=false and explicit missing/unparseable states. No profile prediction is recomputed. No probability, ledger delta, goal or export policy is changed.

## Verification

Run locally: python3 -B -m unittest discover -s . -p 'test_*.py'

23 synthetic tests pass: 15 exercise the actual reader/carrier source, and 8 exercise authenticated local decryption. They cover six-query collection, strict key grammar, wrong branches and privacy, duplicate protection, quality/truncation/unknowns, partial errors, goal guards, malformed counts/scope, TLS/proxy/redirect controls, exact attempt cap, forbidden response stop, bounded output, fresh public-key gate, unadmitted execution, source/archive/AAD/key mismatches, ZIP extras/symlinks/size, private-key permissions and exclusive output. They never call the provider, SSH or a database. Test RSA keys are ephemeral synthetic fixtures, unrelated to operational decryption.

## Publishing the prepared carrier and local decryption

Root copies workflow-template.yml only to .github/workflows/max2-public-pair-params-20261121.yml. The other eight files belong in audit/max2-public-pair-params-20261121. Do not duplicate the workflow template into that audit folder. The template retains the exact accepted checkout/upload action SHAs and ubuntu-latest/system python3/cryptography dependency approach; it adds no pip installation. Source hashes and the new public key are verified before the existing SSH credentials become available to the read step.

The embedded new public key has SHA256 2d5165fc88a5b95eb20c1e8a43f284f4065105c5caa010198f18db72fa51c86e. Only its explicitly authorized public PEM was read. Its private counterpart was not read, copied or discovered.

After the single admitted original read, root authenticates the run/job/artifact metadata and source, downloads that one artifact, and fills a separate copy of expected-provenance-template.json. The numeric IDs, workflow SHA and archive digest must come from that authenticated observation; filenames, timestamps and successful decryption alone are insufficient. The helper is offline and does not independently authenticate GitHub metadata. Its provenance output labels this trust boundary explicitly.

Example local invocation, with root-selected paths:

```text
python3 -B decrypt_report.py \
  --archive /absolute/path/downloaded-artifact.zip \
  --private-key /absolute/path/root-owned-new-private-key.pem \
  --expected /absolute/path/completed-expected-provenance.json \
  --output /absolute/path/new-public-pair-report.json \
  --provenance-output /absolute/path/new-public-pair-provenance.json
```

The helper requires exactly one expected ZIP member, verifies the archive hash, reviewed local reader/runner hashes, exact scope and AAD, RSA3072 key binding and AES-GCM authentication, then checks report scope, safe public keys and forbidden private fields. It rejects symlink inputs and nonprivate key permissions. Both output files are created exclusively with mode 0600; existing paths are not overwritten. It prints only a fixed completion/error code, never plaintext, key material or ciphertext. It performs no network request, provider operation, SSH call or key search.

## Primary documentation

- https://yandex.ru/dev/metrika/ru/stat/openapi/data_1
- https://yandex.ru/dev/metrika/ru/stat/segmentation
- https://yandex.ru/dev/metrika/ru/stat/attributes/visitsbehavior_/visit_params
- https://yandex.ru/support/metrica/ru/data/visit-params-data
- https://yandex.ru/dev/metrika/en/stat/metrics/visits/conversions
- https://yandex.ru/support/metrica/ru/general/how-it-works

The audit-sales-attribution skill and its business-rules reference were read before defining the output semantics.

