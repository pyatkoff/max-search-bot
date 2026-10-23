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

## Tour-selection component

The `tour-selection` scope accepts regular-file **modifications only**, within
the exact existing owners listed in `TOUR_SELECTION_PATHS`:

- the selection JS/CSS and `manager/tour-selections-api.php` /
  `manager/tour-selection-photo.php`;
- the four public selection controller/template/style files;
- `ManagerTourSelectionService`, `ManagerTourSelectionPublicationDraft`,
  `ManagerTourSelectionDeliveryService`, `ManagerTourSelectionShareService` and
  the private `TourSelectionPhotoStore`;
- the three dedicated PHP regressions, their private fixture, the single
  `manager-tour-selection.spec.js` and the dedicated selection document.

These services are called by the selection controllers/component, not the
shared Inbox/notification/media/auth owners. The private PHP fixture is used by
the delivery/share regressions. The browser spec has no consumers in another
spec; its shared `internal-notes` setup is **not** in the allowlist. A change to
that setup, auth, shell, shared callbacks, another feature, migrations, a new
file, mode or CI rules takes `full`. A combined OAuth/selection diff is also full.
The selector does not infer dependencies from broad directory globs.

Selection keeps **all five required PHP groups, syntax, inventory and native
prepared/delivery MySQL coverage**. Only browser scope narrows. Chromium and
WebKit execute the whole selection spec, including editor, exact conditions,
lost responses/UNKNOWN, readonly access, preview/send, publish/revoke, pending
photos and the anonymous public page. The initial inventory is29 cases per
engine, compared with the then-current full752/731 inventories. New component
cases are listed and all must run too; there is no grep/sample selection.

`verify_tour_selection_browser_report.js` requires the29 existing mandatory
contracts, exact agreement with the fresh native `--list`, one unretried passing
result per case and all16 editor/preview/share/public captures per engine at
390/430/768/1440. It rejects skips, expected failures, missing/duplicate/foreign
cases, wrong browser, bad statistics or incomplete screenshots. Its receipt
binds the exact clean checked-out source tree, tested/head SHA, workflow/run/PR,
reports and PNG digests. It is component proof, never full-panel donor evidence.

Unrelated sound, post-context, Firefox, full-panel layout/captures do not run
for this exact family. The stable `regression`/`screenshots` aggregates still
fail on missing/failed selected evidence. Changes to the selector, component
verifier or visual workflow run **full coverage plus the component runner** once
to commission its real invocation/report path; ordinary full changes do not pay
for that additional proof. The prepared environment below also removes repeated
OS, browser and npm installation; test timeouts and assertions are unchanged.

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

## Failed-job-only retries

The successful `selection` job publishes the immutable plan artifact ID.
Browser parts require a single positive numeric ID and download that artifact,
instead of inventing an artifact name from the newer `run_attempt`. A plan from
an earlier attempt of the **same** source/head/run/PR/repository/workflow is
accepted; malformed/future attempts, foreign identities and changed source
manifests fail. Each fresh browser part records its actual attempt. Successful
older parts retain theirs, and the existing exact union/PNG/report gates remain.
This fixes partial reruns without relabelling previous-head results as current.

Selector, report-validation and workflow dependency/result regression tests run
before every scope decision. Manual runs and production deployment verification
retain the canonical full suite. No branch rule, application behavior, runtime
configuration, data, migrations or authorization policy is changed.
