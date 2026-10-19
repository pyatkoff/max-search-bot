# Required PR CI scopes

Small OAuth consent changes previously triggered every dialogue group plus full
Manager2 layout, sound, post-context and MySQL browser workflows through broad
`manager/**` filters. CI now selects from the complete PR base/head Git diff.

`tools/ci_change_scope.js` is the single selector. The `oauth` scope accepts only
regular-file modifications to this exact isolated family:

- `manager/ai-oauth.php`
- `services/ManagerPreparationOAuth.php`
- `tests/run_manager_preparation_oauth_regression.php`
- `tests/visual/manager-ai-draft.spec.js`
- `tests/visual/fixtures/oauth-consent.js` (also permits its initial addition)
- `docs/MANAGER_PREPARED_REPLIES.md`

The diff must include a non-document change. Empty, malformed, unavailable,
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
For every other change the existing full path and its screenshot proof remain.

Selector, report-validation and workflow dependency/result regression tests run
before every scope decision. Manual runs and production deployment verification
retain the canonical full suite. No branch rule, application behavior, runtime
configuration, data, migrations or authorization policy is changed.
