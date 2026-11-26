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
16,384 bytes. Supported country consistency covers Egypt, Turkey, Thailand, Maldives,
United Arab Emirates, Sri Lanka, Vietnam and Oman. This is a finite comparison
allowlist, not permission to import facts. Source/record caveats, selector
authorization, applicability and encoded model-request limits remain unchanged.
Indexing and country support do not admit any new hotel data.

## Native Library admission references

Approval references accept the legacy `libfile_` plus 32 lowercase hexadecimal
characters and the native `libfile_fde3_f-` plus a 32-character lowercase
hexadecimal file key, underscore and 32-character lowercase hexadecimal account
key. Preserve the complete returned identifier; do not shorten it or infer a
legacy alias. Both formats retain the exact four approval fields, a real positive
integer common version, canonical accepted-binding member path and nonempty
bounded scope. Initial version zero is not a production admission.

This is identifier compatibility only. A matching string does not prove file
access, independent content acceptance, publication authority or member bytes.
The existing owner must obtain and review that evidence before a data release.
No file fetch, identity discovery, approval synthesis or automatic data activation
is added to the reader.

## Retained search candidates

The existing `ManagerSearch3CandidateReader` can attach accepted references to
its already validated and selected candidates. It calls the same
`ManagerHotelKnowledge` reader; it does not maintain a second fact reader or
reuse a native-inquiry selector. The new `retained_search3_candidate` provenance
binds the request scope, source scope, exact offer, source timestamp and canonical
candidate projection. Identity uses the accepted `{catalog: "anytour", id: ...}`
binding. A numerically equal Tourvisor/local ID is not an AnyTour identity.

The resolved request-country name must agree with the accepted country and any
supplied candidate-country name. It is never reinterpreted as a Tourvisor
country number. Accepted source-name checks, complete catalogue validation,
independent admissions, bundle/record hashes and validity checks still apply.
This consumer uses the same finite supported-country checks. A missing room
label stays null; property facts do not establish a booked room category or its
inclusions. Every exact stay keeps its own room label and projection binding.

Candidate references have a separate usage policy. The existing native-inquiry,
working-draft and publication policy and their knowledge revisions are unchanged.
Only a whole reference may be attached after the existing candidate selection
and response truncation. Its facts, sources, qualifications and validity travel
together. The separate 32KiB candidate-response limit does not enlarge the
16KiB per-reference limit or the private draft request budget. References never
remove or reorder an otherwise retained candidate to make room for facts.

An explicit `hotel_reference_admission` can report a budget omission when that
small record fits. If even it does not fit, the stable tool contract treats an
absent reference as unknown. Held, omitted and absent references are never proof
that the hotel lacks a service. This read-only attachment does not choose or
refresh a tour, mutate a selection, call a model, or demonstrate natural use by
an AI assistant.

## Country compatibility is not hotel admission

Tourvisor snapshot country keys are explicit: Egypt 1, Turkey 4, Thailand 2,
Maldives 8, United Arab Emirates 9, Sri Lanka 12, Vietnam 16 and Oman 64.
Retained candidates use explicit English/Russian country names, never numeric
country IDs, regions or arbitrary country codes. Candidate and request countries
must agree with the independently admitted identity anchor.

The full accepted legacy-catalog → AnyTour → local-tv chain, real Library
admission reference, accepted source names and every integrity/validity check
remain mandatory. A native Tourvisor ID or a published channel post is not a
substitute for that chain. No new country automatically creates AnyTour IDs,
identity proofs, facts or approvals. Existing hotel bundles and catalogue pins
are unchanged by this compatibility extension. Tests use synthetic memory-only
records and compare every existing captured reference and fingerprint before
and after; they are not production admissions or hotel coverage.

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

## Prepare a sealed data package

The HOTEL owner can prepare a package from two complete private snapshots of
the same catalogue. Use the current accepted snapshot as `CURRENT` and the
independently admitted, append-only candidate as `STAGED`. Both directories
contain the existing `catalogue.json`, listed manifests and referenced bundles
with their exact source bytes. Use the approved deployed reader file as
`READER`. The staging utility runs as a CLI outside the manager interface.

```sh
node tools/hotel_catalogue_package.js build "$CURRENT" "$READER" "$STAGED" "$PACKAGE"
node tools/hotel_catalogue_package.js stage "$CURRENT" "$READER" "$PACKAGE" "$EXPECTED_SHA256" "$RELEASES"
```

Build creates a private file at a previously unused path and emits a JSON
receipt. The deterministic package binds the old and new catalogue SHA-256,
the reader SHA-256 and every member byte. Verify takes the expected package
digest from the trusted staging receipt, checks every manifest and bundle
pin and recomputes the append-only decision against the supplied current
snapshot. It checks the same file and size boundaries on input and on the
received package. It needs neither a Git checkout nor the staging directory.

Stage runs the same verification before creating a version in the existing
private `RELEASES` directory. Use the separate `verify CURRENT READER PACKAGE
EXPECTED_SHA256` command to inspect a package without placing it. Stage
preserves each member byte, reserves the version without replacing an existing
directory, and writes the readiness seal after the members. A duplicate stage
fails with `EEXIST`. The active pointer is untouched.

Independent content approvals, the full typed checks in
`ManagerHotelKnowledge` and production release admission are needed for
activation.

## Read a selected immutable version

The reader uses the checkout catalogue when no active selection exists. An
active selection is a single `current` pointer under the checkout's existing
sibling private-data directory, `max-search-bot-private-logs/hotel-catalogue-releases`.
It must identify a canonical immutable child named `<package SHA-256>-<reader
SHA-256>`. That child contains `hotels/` and a regular `release.json` bearing
`contract: anytour-hotel-catalogue-package-v1`, `package_sha256`,
`reader_sha256` and `catalogue_index_sha256`. The receipt binds the staged
index to the installed reader bytes. The reader resolves the directory once
per standalone reference and uses that directory for every manifest and bundle.
The retained Search3 reader pins the version for all references in one response,
including an unavailable catalogue. A later response resolves the current version.
An invalid published pointer or receipt holds the affected references.

The hotel regression exercises preparation, version selection and rollback
using the editor publisher's existing lock and atomic pointer primitives in
an isolated directory. The public reader returns original approved references
before publication, data from the new snapshot after publication and the old
references after rollback. It also tests a reader already pinned to the prior
snapshot, corruption and rollback replay. The fixture does not authorize a
production pointer.

## Check an active catalogue against an incoming core

Run the read-only check from the verified candidate source. Pass the canonical
absolute application root and the exact staged reader file from that source.

```sh
php tools/hotel_catalogue_compatibility.php "$APPLICATION_ROOT" "$CANDIDATE_READER"
```

The check uses the same active pointer, receipt and index verification as the
serving reader. It admits the bundled state or exact reader bytes and returns a
failure for a corrupted active package or incompatible reader. It reads files
only and does not change the active selection. The shared installer takes the
catalogue publisher's `operation.lock` before inspecting the active version and
holds it through the core switch. The bundled state skips the PHP compatibility
check. An independent version requires checking the exact candidate reader
from the verified Git bundle. A pending catalogue operation, an incompatible
reader or an invalid active version stops the core switch.

## Verification

`tests/hotel_catalogue_checks.php` is included by the existing hotel knowledge
read regression, not a new workflow or scheduler. Its synthetic capacity and
rejection cases cover 1,024 entries, cross-shard uniqueness, missing/corrupt/pinned
files, count and byte limits, namespaces, names, country and admission checks.
The existing source manifest's first exact reference and fingerprint are compared
through the production capture path; the prior data and native-context suites
remain in the normal required suite. Synthetic approval strings stay in test
memory and never become production admission or hotel coverage.

`tests/hotel_candidate_reference_checks.php` runs from the existing manager
preparation MCP regression. It exercises the production candidate reader with
accepted catalogue references, exact-stay bindings, identity/country rejection
and whole-reference response budgets. The same suite preserves the MCP
administrator/project/connection checks around the source read.
