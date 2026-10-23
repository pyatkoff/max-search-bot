# Manager2 browser measurement

This is the reproducible synthetic browser baseline for the measurement task in
[#929](https://github.com/pyatkoff/max-search-bot/issues/929#issuecomment-6178701643).
It measures the installed shell and source-matched static assets using the
existing `tests/visual/fixtures/personal-workspace.js` transport. It does not
measure production API, SQL, network latency or real customer conversations.

## Frozen workspace protocol (`manager2-workspace-v1`)

- Playwright **1.55.0**, Chromium and WebKit, one page at a time per job.
- Viewports, in order: **390×844, 430×844, 768×844, 1440×900, 390×420**, device
  scale factor 1, Europe/Moscow timezone, no CPU or network throttle.
- Synthetic manager **7**, 200 Inbox rows in both the system queue and personal
  group, 600 available messages per dialogue, latest page 200, older pages 200.
  Every twentieth message includes lazy audio/document metadata. A refresh adds
  one incoming message. Names, text, media and the CSRF token are synthetic.
- **Three discarded whole-path warmups, then 30 measured cycles per viewport.**
  Separately, three startup observations use fresh contexts/pages. The shared
  browser process and OS caches are uncontrolled. Playwright routing disables
  HTTP cache: “warm” means a reused page/context, not warmed HTTP cache.
- Each cycle has the fixed sequence below. The data resets between cycles.
  Outside the timed phases, clear the previous draft/File, open client B and
  wait for its 200-message transcript/card, then return to Inbox. Thus every
  timed A-open is a real B→A switch, including repeated warm-page cycles; the
  preceding Back cannot silently turn it into a same-client reconciliation.
  These are correlated repeated synthetic observations, not independent users.
  Report sample medians and ranges. Stable P95 remains **`not_measured`**;
  nothing here establishes a production SLA or a physical-device score.

Actions uses a separate job for each engine/viewport pair. This preserves all
30 cycles per pair without a sequential five-viewport WebKit job exceeding its
time budget. Runners are independent: preserve each shard's platform identity
and do not attribute differences between viewport/engine hosts to product code.
Each full shard has **423 raw records** (3 startup + 30×14 phases); ten shards
total **4,230**, with 2,115 per engine. A draft shard has only 15 protocol-check
records. Local all-viewport runs keep the supplied viewport order.

| Phase | Required finish state |
| --- | --- |
| System queue, then personal group | New list response parsed; canonical selection ready; 200 rows |
| Open client A | Correct 200-message transcript and authorized, visible, enabled reply and Send controls |
| Draft input | Exact multiline draft adopted |
| Older page, twice | Canonical command completed; 400, then 600 loaded messages; reply remains ready |
| Refresh unchanged, then append | Canonical command completed; 600, then 601 loaded messages |
| Quick reply insert | Exact draft plus canonical newline and resolved text |
| Quick reply preview, then insert | Actual unresolved-variable preview; edited text adopted in exact draft |
| Local File preview | Native `File`, filename and decoded object-URL image preview |
| Next waiting client | Native keyboard shortcut; client B's transcript and authorized reply ready |
| Return to A | Native browser Back on mobile, row click on desktop; exact draft and original File restored |

The last guard compares the original `File` from the input change event with the
object used to create the restored preview URL. It does not mistake an empty
native file input after canonical queue adoption for a missing attachment. No
send/upload function is called. Audio/document metadata must remain lazy: any
unplanned local or external request fails the run. Service-worker/push state is
the existing fixture stub, not a real OS delivery or cache measurement.

## Complete reply protocol (`manager2-reply-v1`)

Select `--journey reply` to measure the actual installed frontend through AI and
ordinary text sending. The default CLI `--journey workspace` preserves the
frozen no-send protocol above. PR measurement jobs explicitly select `reply`;
manual dispatch defaults to `workspace` and exposes the same choice.

The engines, viewports, 200-row Inbox, 600-message source, three fresh-context
startup observations, three discarded warmups and 30 measured cycles are the
same. Each cycle contains **both** fixed cohorts, prepared first and generated
second. Each cohort starts with client B fully open, then performs:

| Phase | Required finish state |
| --- | --- |
| Open A from Inbox | Correct 200-message transcript; authorized reply controls ready |
| Open AI | Exact synthetic proposal visible and insertion enabled; prepared or generated response parsed |
| Insert AI | Revision and latest-message validation parsed; exact text in composer; preview closed |
| Manager edit | Native input event adopts the exact edited multiline text |
| Explicit send | Prepared cohort: confirmation parsed, composer empty, exactly one matching manager message in transcript |
| Lost acknowledgement | Generated cohort: synthetic transport accepts one message then aborts its response; exact draft remains and button offers receipt checking |
| Explicit receipt check | Same operation ID confirms the existing send; composer empty and exactly one matching manager message; no second send |
| Full cycle | One continuous span from the A-open native event through the confirmed transcript, including driver gaps and phase verification |

The transport accepts only the fixed synthetic text, manager/client, CSRF token
and operation shape. It checks generated-draft provenance, rejects a duplicate
operation, and requires an existing operation before confirming a receipt.
Each cohort asserts exactly one send; only the generated cohort has one receipt
request. The request is intercepted locally: **zero real customer/provider
sends, AI model calls or production data writes**. Other mutations remain denied.

Each reply shard has **393 raw records** (3 startup + 30×13); ten shards have
**3,930 records**, including 600 full-cycle observations. Prepared contributes
five phases plus its aggregate; generated contributes six plus its aggregate.
A one-cycle protocol check has 14 records. Aggregates overlap their component
phases: never add them to compute total time. Both cohorts retain 30 observations
per engine/viewport and are reported separately, without pooling hosts or modes.

AI/provider responses are immediate synthetic fixtures; one acknowledgement is
deliberately lost. The automated edit/click sequence includes no human thinking
time. These measurements describe frontend state/rendering and automation
overhead, **not natural production response time, AI latency or channel latency**.
The secondary card is observed separately and, as in the workspace protocol,
its post-open observation wait is included in the continuous full-cycle span.

## Timing and raw evidence

The meter is installed before application scripts. A native event capture starts
click/input/keyboard/Back phases. File selection uses Playwright `setInputFiles`
with a real `File`; this does not measure the operating-system chooser or paste
permissions. Command phases start when armed in the browser, before the driver
invokes the canonical command, and require its promise to complete. Driver and
synthetic-route overhead are included. Startup starts at document init-script
execution; navigation time before that script is outside the metric.
History/refresh also require the canonical command to return `true`. If an
already pending automatic detail read suppresses a manual refresh (`false`),
the meter records that attempt, waits for the read to settle, then permits one
retry. The retry must succeed and parse a response started after phase arming;
the wait/retry overhead stays inside the measured duration. Attempt timestamps
and results are retained. Other false results fail rather than waiting for a
later automatic refresh to masquerade as command completion.

The finish is the first required state followed by two animation frames with
the state still valid. This is a browser rendering opportunity, not a physical
screen-presentation timestamp. Open/Back additionally record first-frame
transcript, authorized-reply and separate secondary-card state milestones. The
card does not gate the main reply duration. Visibility checks use rendered boxes
intersecting the viewport and enabled controls; they do not simulate a real
software keyboard. State polling, fetch observation and JSON reserialization add
instrumentation overhead and stay identical across comparison runs.

Every accepted phase is written to `samples.jsonl`: individual start/ready/end
`performance.now()` values, time origin and UTC timestamps, event trust flag,
milestones, fetch start/header/JSON timestamps, response status/error, JSON byte
counts, resource timing entries, loaded-state assertions and long tasks where
supported. Byte counts are reserialized JSON UTF-8 bytes, not compressed/wire
bytes. Resource Timing sizes may be zero for routed resources; zero does not
establish zero transfer. Unsupported long-task reporting, server/SQL and wire
bytes explicitly remain `not_measured`.

`manifest.json` records the explicit protocol ID, expected raw-record count,
exact product SHA/tree, tooling HEAD and three script
hashes, dataset SHA, installed HTML/assets/worker hashes, engine version,
Playwright/Node/platform/CPU identity, cache and sample protocol. `summary.json`
contains grouped medians/ranges and the SHA-256 of the raw JSONL. Final viewport
screenshots are inspection aids. A failure produces a nonzero exit and
`failure.json`; a phase failure also saves synthetic state and attempts a screenshot. A screenshot failure is recorded separately and preserves the primary error.
Incomplete artifacts are not a completed baseline. A failed cycle also preserves its completed phase records in `partial-cycle-<index>.json`, explicitly excluded from accepted samples. `primary-error.json` preserves the original failure before bounded browser shutdown; a separate cleanup failure cannot replace it.

## Run

Run the **Manager2 browser measurement** Actions workflow at a reviewed tooling
SHA, with the exact deployed `product_sha`. The PR workflow uses its base SHA.
Draft PRs run only a one-cycle-per-viewport protocol check in both engines;
marking the PR ready triggers the full declared series. `purpose` in the
manifest and summary distinguishes `protocol_check` from `baseline`. Native
actions, navigation, startup and canonical-command waits are bounded to 15 s;
timeouts fail with diagnostics rather than waiting for the runner limit.
An outer watchdog also bounds browser/driver shutdown: three minutes for a
protocol check, sixteen minutes for a full measurement, with partial artifacts
explicitly rejected as a baseline.
Capture first reads only anonymous public shell, stylesheet/script URLs and
`/manager/sw.js` from `https://app.anytoour.ru`. Each body must match `git show`
for the specified product SHA. The input artifact is shared by both engines.
Any installed/source mismatch stops capture before browser measurements.

For a local run with a verified installed-static directory:

```sh
npm install --no-save --package-lock=false @playwright/test@1.55.0
npx playwright install --with-deps chromium
node tools/measure_manager2_browser.js --engine chromium \
  --journey reply \
  --product-sha <40-character-deployed-sha> --assets <installed-directory> \
  --output <new-result-directory> --samples 30 --warmups 3 --cold 3
```

The input directory contains `shell.html`, `receipt.json`, source-matched
`manager/assets`, `manager2/assets` and `manager/sw.js`. Public capture is also
available through `--capture --assets <directory>`; `--capture-only` stops after
input verification. No local PHP installation is needed: a strict temporary
shim supplies the verified PHP-generated shell to the existing fixture.
An output directory containing prior measurement evidence is refused, so a
failed rerun cannot leave an old successful summary beside new partial data.

Small `--samples 1 --warmups 1 --cold 1` runs are protocol checks, never the
accepted baseline. Preserve raw artifacts, workflow run/attempt, tested tooling
SHA and exact product/source identities when reporting results. Compare like
engine, host, viewport, dataset, role, filter, cache and method. Before proposing
an optimization, identify the expensive phase and establish a causal control;
then repeat only the comparable affected series. Do not replay unchanged large
baselines, full visual QA or old one-shot production profiles as progress.
