# Required PR CI scopes

Small OAuth consent changes previously triggered every dialogue group plus full
Manager2 layout, sound, post-context and MySQL browser workflows through broad
`manager/**` filters. CI now selects from the complete PR base/head Git diff.

`tools/ci_change_scope.js` is the single selector. It recognizes `oauth`,
`tour-selection` and conservative `full` coverage. The `oauth` scope accepts only
regular-file modifications to this exact isolated family:

- `manager/ai-oauth.php`
- `services/ManagerPreparationOAuth.php`
- `tests/run_manager_preparation_oauth_regression.php`
- `tests/visual/manager-ai-draft.spec.js`
- `tests/visual/fixtures/oauth-consent.js` (also permits its initial addition)
- `docs/MANAGER_PREPARED_REPLIES.md`

Each component diff must include a non-document change. Empty, malformed, unavailable,
non-PR, shared, mixed and unknown diffs, deletions, renames and mode changes use
`full`. A CI-rule edit itself uses full coverage. No previous run or report is
reused to certify the current PR.

For OAuth-only changes, required regression CI runs syntax, inventory, OAuth,
MCP, preparation endpoint and Manager HTTP boundary tests. Browser QA executes
only the three native consent/callback cases in Chromium and WebKit (allow,
deny and legacy-policy control), selected by `OAuth native (allow|deny|legacy policy control)`. The selector also
compares the spec's shared/non-OAuth source before choosing this scope; an edit
to its existing AI tests uses full coverage. Missing consent tests, empty
selection, skips, expected failures, flaky retries and mismatched browser
evidence fail the gate. Manager2 layout and AI-panel UI tests do not run here.

Unrelated full Manager2 layout/captures, Firefox history/notifications, sound,
post-context and prepared-reply MySQL jobs are omitted only in that exact scope.
The stable `regression` and `screenshots` aggregate names require the selected
current-run jobs to succeed; selector failure or an unexpected status is red.
For non-component changes the existing full path and its screenshot proof remain.

## Reject a known stale PR base before expensive jobs

The active main rules require the event base commit to be an ancestor of the PR
head. After validating both commit identities, the canonical selector checks that
ancestry. A known stale branch exits nonzero with `base_not_in_head` and publishes
no mode outputs, so the required aggregate fails and dependent expensive scopes
do not start. This prevents a full green synthetic-merge run followed by a strict
merge rejection. Integrate the accepted base first, then run the ordinary checks.

The existing full-history checkout also fetches `refs/remotes/origin/main`.
For an event explicitly targeting `main`, the selector rejects
`event_base_superseded` when that fetched commit is a different descendant of
the saved event base. This catches an already obsolete event even when its old
base is an ancestor of the PR head, or the head already contains the newer main.
It uses the existing fetched snapshot, with no extra fetch, API call, permission
or workflow. A missing/non-commit, older or divergent snapshot uses conservative
full coverage; an explicitly unknown base branch also stays full.
Those fallbacks preserve component commissioning for changed CI rules and do not
apply a styling-only backend shortcut.

Both rejections fail without publishing scope outputs. Dependent PHP/MySQL and
browser groups therefore do not start; independent inventory and syntax jobs may
still run. This is never passing/skipped evidence or merge permission. Manual
runs retain full coverage. The snapshot cannot predict a main change after
checkout, so the existing fresh final priority/publication check still applies.
The existing release order remains necessary before pushing a prepared candidate
to an open PR; this early guard does not reserve the next release window.

## Tour-selection component

The `tour-selection` scope accepts regular-file modifications within the exact
owners listed in `TOUR_SELECTION_PATHS`:

- the selection JS/CSS and `manager/tour-selections-api.php` /
  `manager/tour-selection-photo.php`;
- the four public selection controller/template/style files;
- `ManagerTourSelectionService`, `ManagerTourSelectionPublicationDraft`,
  `ManagerTourSelectionDeliveryService`, `ManagerTourSelectionShareService` and
  the private `TourSelectionPhotoStore`;
- the three dedicated PHP regressions, their private fixture, the single
  `manager-tour-selection.spec.js` and the dedicated selection document;
- `modules/tour-selections/editor-tours.php` and
  `tests/visual/fixtures/selection-private-editor.js`, which renders that standalone wrapper
  and is imported only by the complete selection spec. A wrapper-only change
  explicitly triggers visual QA and the existing editor-module package/host/
  installation/private-HTTP gate. This is not a `modules/**` or fixture-directory
  exemption: `editor-app.php`, shared host/auth/bootstrap, other fixtures, new
  files, removals, renames and mode changes keep full coverage.

The audited collector family uses the same component runner:

- `manager/tour-selection-collector.php` and its own bridge JS/CSS;
- the three dedicated import drafts (`ManagerTourSelectionImportDraft`,
  `ManagerTourSelectionAnexImportDraft`, `ManagerTourSelectionTourvisorImportDraft`);
- the exact shipped files in `extensions/selection-collector`, including its
  manifest, worker, bridge, three adapters, popup/price editor, styles,
  reproducible Python packager, ZIP and README;
- the collector PHP/worker regressions, its private PHP/MySQL fixtures and
  `tests/visual/fixtures/selection-collector.js`.

These are explicit file paths in the selector, not a directory exemption.
Only these audited collector paths may also be initial regular-file additions,
with raw Git modes `000000` to `100644`; an unknown new extension/helper file
still takes full coverage. A collector README or selection-document change on
its own does not select component coverage. Workflow triggers include the
extension packager/ZIP/JSON as well as JS/CSS so packaging-only changes cannot
silently miss their native gate. The extension README alone does not trigger
native UI, preserving its prior documentation-only behavior; changing it with
code/package files still triggers the gate. Backend regression stays unfiltered.

These services are called by the selection controllers/component, not the
shared Inbox/notification/media/auth owners. The private PHP fixtures are used
by the selection/collector delivery regressions. The browser spec and collector
browser fixture have no consumers in another spec; the shared `internal-notes`
setup is **not** in the allowlist. Collector session/CSRF handling and the draft
owner shared with bot callbacks are also outside this scope. A change to shared
setup, auth, shell, shared callbacks, another feature, migrations, an unaudited
new file, mode or CI rules takes `full`. A combined OAuth/selection diff is also full.
The selector does not infer dependencies from broad directory globs.

The whole diff matters: an editor-only package with its version-only manifest,
standalone wrapper, private fixture and complete component spec remains in the
selection scope. Adding the shared `tests/run_manager_prepared_reply_regression.php`
still selects full coverage, even for a diagnostic edit. Its reusable fixture
interface is not an unreviewed test-only exemption. The rule does not retroactively
replace accepted evidence or cancel the current required checks. The CI-rule
change itself retains full and scoped commissioning before it can be adopted.

The standalone editor release manifest `modules/tour-selections/editor.json`
may accompany the component only when the selector reads both exact Git
revisions and verifies a valid version change with every other parsed field
unchanged. Package files, backend inputs, host contract, schema, name and unknown
fields are not exempted. Malformed manifests, additions, removals, renames and
mode changes retain full coverage. The visual workflow also triggers on the
manifest alone, so both a version-only release and a contract change receive
their selected native proof.

Ordinary editor JS/CSS/spec plus that version change therefore runs the complete
selection browser suite, including the protected standalone editor and iframe
cases, without full Manager2 layout/history/notification tests. The existing
editor-module workflow still builds and verifies the exact package, host fences,
installation/rollback and private HTTP boundary. Sound and post-context heavy
jobs remain gated by the canonical scope; their lightweight scope workflows
may still start. No broad path exclusion hides a deleted or mixed shared input.
The main production deployment retains its canonical full verification; this
PR scope does not change that deployment contract.

Except for the styling-only subprofile below, selection keeps **all five
required PHP groups, syntax, inventory and native prepared/delivery MySQL
coverage**. Functional JS/PHP/API/collector changes never enter that exception. Chromium and
WebKit execute the whole selection spec, including editor, exact conditions,
lost responses/UNKNOWN, readonly access, preview/send, publish/revoke, pending
photos and the anonymous public page. It also executes every collector case for
the shipped adapters, one-click import, optional price editor, destination/row
freshness, popup geometry and uncertain-result recovery. The inventory is
freshly listed without filtering on each part; the two exact-key parts together
execute every current case once, without sampling. A fixture proof does not replace physical Chrome/Яндекс installation
and an authorized live source/import acceptance.

`verify_tour_selection_browser_report.js` retains the29 original mandatory
contracts, exact agreement with the fresh native `--list`, one unretried passing
result per case and all16 editor/preview/share/public captures per engine at
390/430/768/1440. It rejects skips, expected failures, missing/duplicate/foreign
cases, wrong browser, bad statistics or incomplete screenshots. Its receipt
binds the exact clean checked-out source tree, tested/head SHA, workflow/run/PR,
reports and PNG digests. It is component proof, never full-panel donor evidence.

Unrelated sound, post-context, Firefox, full-panel layout/captures do not run
for this exact family. The stable `regression`/`screenshots` aggregates still
fail on missing/failed selected evidence. Changes to the selector, component
verifier, shared case allocator or visual workflow run **full coverage plus the component runner** once
to commission its real invocation/report path; ordinary full changes do not pay
for that additional proof. The prepared environment below also removes repeated
OS, browser and npm installation; test timeouts and assertions are unchanged.

### Two isolated component parts

`tour-browser-parts` runs two parts for each of Chromium and WebKit in the same
pinned native environment. The existing `manager2_visual_shards.js` allocator
has an explicit two-part `tour-selection` profile. It sorts the canonical
`[browser, project, relativeSpecPath, titleArray]` identities and alternates them
between parts zero and one. Odd inventories differ by one case; inventory size
is never a hard-coded acceptance count. The full profile retains its four parts,
existing case cycle, whole-spec and daily-cache ownership, and workers=1.

Every component part first runs an unfiltered native `--list`. The allocator then
validates the native filtered listing against that part's exact expected keys
before execution. All three invocations retain workers=1 and `--forbid-only`.
The original complete listing, filtered listing, execution report and receipt
are preserved. Each case has one passing result and zero retries. The part
receipt binds its real run attempt, source tree, tested/head SHA, repository,
workflow, PR, browser, prepared environment and report/PNG digests.

The existing `tour-browser` result now assembles both parts per engine without
running another browser. It requires successful matrix results and downloads
two strictly named artifacts into separate directories. It compares canonical
inventories rather than timestamp-bearing listing bytes, independently derives
ownership, and proves the exact union without gaps, duplicate or foreign cases.
The original 29 mandatory contracts are checked against that complete union.
Missing parts, mixed source/run/browser/environment, changed reports, future
attempts or invalid statistics fail. A successful original part of an earlier
attempt in the same unchanged run retains its real receipt during a failed-job
retry; successful siblings are not rerun or relabelled.

Ordinary component PNG names are prefixed with `case-part-0--` or
`case-part-1--`, preserving equal basenames produced by different runners.
Each of the 16 mandatory logical captures has exactly one case owner; assembly
checks its part, SHA256, PNG dimensions and viewport before restoring its
canonical name. Duplicate mandatory captures fail even when their bytes match.
Traversal, symlinks, wrong namespaces and file collisions fail. The final
component artifact retains both original proof directories and all namespaced
PNG bytes. Existing full-only OneTouch captures remain full evidence, not an
additional mandatory component capture or a full-panel donor.

The intended elapsed-time reduction must be measured on the actual native run.
Two parts add runner preparation and an assembly step; queue time and complete
full-suite commissioning can still dominate. No assertion, viewport, engine or
required screenshot is removed to meet a timing target.

## Styling-only backend subprofile

The existing selector may attach `backend=selection-style` to `tour-selection`
only after checking the complete exact base/head diff and the existing manifest
contracts. It requires a modification to at least one of these existing styles:

- `manager/assets/workspace-v2-tour-selection.css`
- `selection/selection.css`

The only permitted companions are the complete component visual spec
`tests/visual/manager-tour-selection.spec.js` and version-only changes to the
existing editor/public module manifests. Every path must remain a regular 100644
file, with modification status and no rename/copy. Manifest validation still
reads both Git blobs and rejects changes to schema, contract, package files,
backend inputs or unknown fields. A version-only or spec-only PR is not a styling
PR. CSS mixed with JS, PHP, an API, a fixture, collector code, migration, auth,
documentation, unknown paths or CI retains its ordinary backend coverage.

CSS does not alter the PHP/SQL implementation. Its observable browser effects
remain covered by the full selection suite in both Chromium and WebKit, including
all four existing widths, private/anonymous behavior and required screenshots.
The editor's package, host, install/rollback and private-HTTP workflow is unchanged.
No case is omitted from the complete native union or accepted from another run.

For this exact subprofile, the existing required matrix runs architecture and
excludes dialogue, website, manager and diagnostics. Inventory and PHP syntax
remain mandatory. The prepared MySQL job does not start. No new jobs or workflows
are added. The existing aggregate explicitly requires successful scope,
inventory, syntax and selected architecture, and declared skips for MySQL/OAuth.
Absent/unknown profiles, failed/cancelled selected jobs and unexpected results
fail; a styling result cannot certify a full or OAuth profile. Other selection
changes still run all five groups and MySQL. Manual and production verification
remain full, and publisher/admission behavior is unchanged.

`tests/check_ci_selection_style_scope.js` executes disposable Git histories and
the actual scope-output shell. The existing workflow contract suite validates
matrix exclusions, wiring and the actual aggregate truth table. Policy edits
still receive full regression plus the existing component commissioning. Actual
runner savings and duration must come from a subsequent ordinary styling PR,
not an artificial release or a claimed percentage from these local fixtures.


## Booking stylesheet backend subprofile

A modification of exactly `manager/assets/workspace-v2-tour-choices.css` may
select `mode=full, backend=workspace-style`. This stylesheet contains the
selected-tour and booking form layout. It does not change PHP, SQL, request
payloads, authorization or module manifests. Only an existing regular 100644
file modification qualifies; additions, deletion, rename/copy, mode changes
and **any companion path** keep ordinary full server coverage.

This independently selects server work without changing browser coverage.
The existing required matrix keeps architecture, inventory and PHP syntax;
the four other server groups and the prepared MySQL matrix are declared skips.
The source aggregate requires those exact current-run results. A missing,
failed or foreign profile never passes, and the existing release admission
remains mandatory.

All full native Chromium/WebKit parts, Firefox checks, screenshots, sound,
post-context and their existing MySQL checks keep their current mode/full
conditions. This profile cannot substitute component screenshots for full
panel evidence. No browser case, invocation, assertion, timeout or screenshot
is removed. Mixed CSS/JS, PHP, API, fixture, test, documentation, migration,
manifest or CI changes run full server coverage. The existing selection-style
profile is unchanged.

The canonical selector/output Git-history tests and workflow aggregate truth
tables exercise the positive scope and conservative fallbacks. The CI-rule
package itself receives full regression and existing browser commissioning;
it cannot self-select its new shortcut. Manual and production PHP8.3
verification remain full. Any duration or runner savings require a subsequent
ordinary qualifying stylesheet change; no benchmark-only PR is needed.

## Prepared native browser environment

The eight browser workflows run their native jobs in the immutable GHCR image
committed in `tools/browser_qa_environment.json`. This includes the full visual
selection planner, so strict same-environment donor comparison remains valid.
Each consumer declares the exact digest and verifies its checked-out recipe,
Node22, PHP8.3/extensions/SQLite, Playwright1.55.0 and all three native browser
executables before running the existing commands. The image uses the pinned
AMD64 Microsoft Playwright1.55.0 Ubuntu24.04 base. PHP and the locked npm package
are installed once by `.github/workflows/browser-qa-environment.yml`.

The builder launches Chromium, Firefox and WebKit, checks DOM/text and captures
one PNG in each before publishing. Its artifact records source/head/run/recipe,
actual runtime versions and the registry digest. To change a recipe input,
first build and verify the replacement, then update the source pin and every
consumer digest together. Consumers never follow a mutable tag. Pulls use the
built-in repository token with read-only package access; only the separate
builder has package write access. No production credential is involved.

The prepared shell maps the packaged dependencies and browsers to their normal
lookup locations. It removes only the official base image's known browser-path
default and rejects other paths. Existing release override guards still run.
Full partition and scoped-selection receipts additionally require the pinned
image, recipe and exact runtime versions. Host assembly retains the original
native partition proof. The canonical toolchain's `ubuntu-latest` field describes
the Actions host; the receipt's `containerImage` and runtime identify execution.

Photo-opening now uses the canonical PHP8.3 image instead of its previous8.2
setup. The separate Telegram archive MySQL job keeps PHP8.2. Native case lists,
matrices, assertions, per-job timeouts, report/PNG gates and failed-only retry
rules are unchanged. Image transfer and container startup still take time;
removing package installation does not establish an end-to-end latency SLA.

## Full browser workload allocation

Full Chromium and WebKit coverage uses four isolated parts, each retaining
workers=1. Whole-spec allocation follows observed native execution time;
the isolated selection cases retain the existing fixed 20/0/10/70 cycle.
History remains in part zero, alongside its existing layout/CLI and daily-cache
ownership. No shared fixture is split and the complete spec/case inventory is
unchanged. Every engine assembly downloads and validates all four original
parts; missing or old two-part evidence cannot pass. The source-bound registry
identity changes with allocation, so the first run executes fresh full coverage.
Exact union, report, screenshot, source and retry gates remain mandatory. This
allocation does not guarantee end-to-end latency: runner queueing, setup,
individual failures and assembly still contribute to the actual result.

## Failed-job-only retries

The successful `selection` job publishes the immutable plan artifact ID.
Browser parts require a single positive numeric ID and download that artifact,
instead of inventing an artifact name from the newer `run_attempt`. A plan from
an earlier attempt of the **same** source/head/run/PR/repository/workflow is
accepted; malformed/future attempts, foreign identities and changed source
manifests fail. Each fresh browser part records its actual attempt. Successful
older parts retain theirs, and the existing exact union/PNG/report gates remain.
This fixes partial reruns without relabelling previous-head results as current.

Every PR runs selector, report-validation and workflow dependency/result selftests
once in the required regression workflow. Its canonical scope job must succeed
before the required `regression` aggregate can pass. The three named PR companions
(visual QA, incoming sound and post context) omit only that identical prelude;
each still computes its own complete base/head scope and runs its selected native
checks. Unknown callers and standalone manual runs retain the selftests. Renaming
a caller never silently opts it out. This reduces four identical PR validation
preludes to one when all four workflows start; it does not reuse an older result
or reduce the product test inventory.

Regression, sound, post-context and capacity workflows cancel only superseded
read-only runs of the same PR and workflow. Manual runs have unique run/attempt
groups; main pushes and deployment workflows are not in those groups. Current-head
checks still have to pass before merge. This releases runners occupied by stale
heads without treating a cancelled run as passing evidence.

## Production verification critical path

Production verification keeps the complete canonical catalog on PHP8.3; a
successful PR run on PHP8.2 does not replace it. The architecture group, whose
first command validates inventory, must succeed before the two isolated group
runners start. One runner executes diagnostics; the other executes dialogue,
website and manager. Each catalog group runs exactly once, including architecture.
This allocation follows the original measured 238s of sequential group work:
architecture 18s, diagnostics 150s and the other three groups 70s. The catalog
coverage contract rejects missing, duplicate or unknown groups. PHP syntax runs
alongside them.

The always-running verify aggregate admits deployment only when
inventory, syntax and both group runners succeed. Failure, cancellation, skip
or a missing result blocks deployment. Every runner checks the same exact SHA;
the production concurrency, current-main check and all server/post-deploy
stages are unchanged. The standalone full-suite command remains available.
Parallel placement reduces elapsed verification time; no test is removed,
cached from a different source or silently marked successful. The first actual
deployment must establish the new duration; the old timings are a baseline,
not an end-to-end latency guarantee.

Manual runs and production deployment verification retain the canonical full
suite. No branch rule, application behavior, runtime configuration, data,
migrations or authorization policy is changed.
