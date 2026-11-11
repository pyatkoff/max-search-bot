# Bounded hotel knowledge catalogue

`ManagerHotelKnowledge` remains the sole approved hotel-reference reader. Its
repository-owned storage index is `knowledge/hotels/catalogue.json`. The existing
`manifest.json` is the first shard; its accepted entries and hotel bundle files
are not rewritten by this migration. The index contains no hotel facts,
identity decisions, replacement approvals or customer information.

## Index and admission

The index has exactly `schema_version: hotel_catalogue_v1`, `project_key: anytour`
and a nonempty `shards` list. Each descriptor has exactly `file` and
`git_blob_sha`. The first file is `manifest.json`; additional files are named
`manifest-0001.json`, `manifest-0002.json` and so on. Names must be unique and
cannot contain paths or URLs. The Git blob ID binds the exact manifest bytes:
`SHA1("blob " + byte_length + NUL + bytes)`, equivalently `git hash-object FILE`.
This repository-version check does not replace any existing SHA-256 identity,
bundle or individual-record validation, or independent content admission.

Every shard retains the original manifest v1 schema and checks: at most 32
bindings and 65,536 bytes, accepted typed source identities, country/name
consistency, real admission member references, and canonical identity hashes.
The reader additionally rejects duplicate source identities or AnyTour hotel
identities across different shards. It validates the complete listed set before
returning a match, including shards after the selected hotel. A missing,
corrupt, incorrectly pinned or invalid later shard never produces partial facts.
There is no fallback to the first shard when the index is unavailable.

The index itself is limited to 16,384 bytes and 32 shards. Thus the theoretical
count ceiling is 1,024 bindings, subject to every shard's unchanged byte ceiling.
At most 32 bounded manifest files are read, never unbounded directory discovery.
Only one decoded shard, the uniqueness sets and a selected entry need to be
retained. The selected hotel bundle and final reference remain limited to
16,384 bytes. Existing country scope (Egypt and Turkey), source/record caveats,
selector authorization, applicability and encoded model-request limits remain
unchanged. Indexing does not authorize Thailand/Vietnam or any new data.

## One ordinary data release

Use the existing HOTEL owner and independent content/identity admissions. Add
an admitted entry to a shard with sufficient count and byte capacity; otherwise
add one allowed new shard. Preserve the exact accepted bundle bytes, sources,
limits and original acceptance credit. Update the changed shard's Git blob pin
in the same commit, after its final serialization. Do not refresh unrelated
shards, facts or approvals. A branch change is not a production installation.

The index and its shards are a single published version. The existing normal
installation, required checks and sole-publisher completion remain mandatory.
For rollback, restore the corresponding reader and index/shard version together;
never mix an old index with a changed manifest. Moving an unchanged entry between
shards does not change its fact contents or knowledge fingerprint.

A consumer enumerating the catalogue must follow all listed shards rather than
assuming the first manifest is complete. This storage change does not itself
write MATCH/LOCAL, publish MAX/Telegram posts, extend those consumers' permissions,
or prove that facts influenced an AI selection. Their guarded writes and
readbacks remain separate results.

## Verification

`tests/hotel_catalogue_checks.php` is included by the existing hotel knowledge
read regression, not a new workflow or scheduler. Its synthetic capacity and
rejection cases cover 1,024 entries, cross-shard uniqueness, missing/corrupt/pinned
files, count and byte limits, namespaces, names, country and admission checks.
The existing source manifest's first exact reference and fingerprint are compared
through the production capture path; the prior data and native-context suites
remain in the normal required suite. Synthetic approval strings stay in test
memory and never become production admission or hotel coverage.
