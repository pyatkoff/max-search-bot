# Manager2 browser measurement

This is the reproducible synthetic browser baseline for the measurement task in
[#929](https://github.com/pyatkoff/max-search-bot/issues/929#issuecomment-6178701643).
It measures the installed shell and source-matched static assets using the
existing `tests/visual/fixtures/personal-workspace.js` transport. It does not
measure production API, SQL, network latency or real customer conversations.

## Frozen protocol

- Playwright **1.55.0**, Chromium and WebKit, one page at a time per engine.
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

## Timing and raw evidence

The meter is installed before application scripts. A native event capture starts
click/input/keyboard/Back phases. File selection uses Playwright `setInputFiles`
with a real `File`; this does not measure the operating-system chooser or paste
permissions. Command phases start when armed in the browser, before the driver
invokes the canonical command, and require its promise to complete. Driver and
synthetic-route overhead are included. Startup starts at document init-script
execution; navigation time before that script is outside the metric.

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

`manifest.json` records exact product SHA/tree, tooling HEAD and three script
hashes, dataset SHA, installed HTML/assets/worker hashes, engine version,
Playwright/Node/platform/CPU identity, cache and sample protocol. `summary.json`
contains grouped medians/ranges and the SHA-256 of the raw JSONL. Final viewport
screenshots are inspection aids. A failure produces a nonzero exit and
`failure.json`; a phase failure also saves synthetic state and a screenshot.
Incomplete artifacts are not a completed baseline.

## Run

Run the **Manager2 browser measurement** Actions workflow at a reviewed tooling
SHA, with the exact deployed `product_sha`. The PR workflow uses its base SHA.
Capture first reads only anonymous public shell, stylesheet/script URLs and
`/manager/sw.js` from `https://app.anytoour.ru`. Each body must match `git show`
for the specified product SHA. The input artifact is shared by both engines.
Any installed/source mismatch stops capture before browser measurements.

For a local run with a verified installed-static directory:

```sh
npm install --no-save --package-lock=false @playwright/test@1.55.0
npx playwright install --with-deps chromium
node tools/measure_manager2_browser.js --engine chromium \
  --product-sha <40-character-deployed-sha> --assets <installed-directory> \
  --output <new-result-directory> --samples 30 --warmups 3 --cold 3
```

The input directory contains `shell.html`, `receipt.json`, source-matched
`manager/assets`, `manager2/assets` and `manager/sw.js`. Public capture is also
available through `--capture --assets <directory>`; `--capture-only` stops after
input verification. No local PHP installation is needed: a strict temporary
shim supplies the verified PHP-generated shell to the existing fixture.

Small `--samples 1 --warmups 1 --cold 1` runs are protocol checks, never the
accepted baseline. Preserve raw artifacts, workflow run/attempt, tested tooling
SHA and exact product/source identities when reporting results. Compare like
engine, host, viewport, dataset, role, filter, cache and method. Before proposing
an optimization, identify the expensive phase and establish a causal control;
then repeat only the comparable affected series. Do not replay unchanged large
baselines, full visual QA or old one-shot production profiles as progress.
