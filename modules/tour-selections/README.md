# Selection public presentation module

This is the first bounded extraction of the existing constructor, not a second
application or a new V1 plan. `selection/view.php`, `selection/selection.css` and
`selection/activity.js` remain the single canonical source. The explicit module
manifest assembles those exact bytes into an independently identifiable artifact.
There is no directory copy, copied bootstrap, authentication, database, sender,
callback key or customer fixture in the public ZIP.

## Build and inspect

From the repository root, with Python3.10+ and PHP8.3 available:

```sh
python3 -m unittest discover -s modules/tour-selections/tests -p test_package.py -v
php modules/tour-selections/tests/test_hosting_inventory.php
python3 modules/tour-selections/build.py build --output /tmp/selection-public-build
python3 modules/tour-selections/build.py verify --output /tmp/selection-public-build
```

Use a fresh output directory. Uncommitted module inputs refuse a release build;
`--working-tree` permits development output marked `release_eligible=false`.
`selection-public.zip` contains only three presentation files and `release.json`.
The archive and package ID are reproducible across unrelated commits. A separate
receipt retains the actual source commit/tree rather than relabelling old evidence.
`compatibility.json` fingerprints the declared public controller and selection
service boundaries. Verification refuses changed presentation or boundary inputs,
archive members, hashes and modes. This is a declared-boundary check, not proof of
all transitive dependencies or authority to reuse global required-CI receipts.

The separate `preview/` renders the packaged PHP template with synthetic offers,
local illustrative SVGs and local contact links. It has no interest capability,
visitor tracking, business connection, live bot URL or send. Serve that directory
locally to inspect the ready and unavailable states. Preview is separate from
authorized real-source, physical-browser and different-account acceptance.

## Release boundary

The template takes the existing public `$selection` DTO, optional `$interest`
capability and escaping callable `$h`. `photo.php` and `activity.php` are relative
same-origin routes. Their authorization, revocation, immutable-version and privacy
contracts remain with the existing backend. A public host needs these exact
routes, CSP/headers and an explicit accepted origin; moving only HTML/CSS does not
establish a working deployment. Manager sessions/cookies must not be exposed to a
new public origin. Bot entry and same-manager dialogue ownership remain canonical.

This first package does **not** switch a URL, add a DNS record, change a vhost,
publish customer data or authorize an independent production deployment. Existing
links and public controllers stay installed as before. Current repository-wide
required checks, main publisher and full installed verification remain in force.
The dedicated module workflow supplies a quick artifact/preview on its own input
changes; it is not a replacement for the current release gates.

Before independent deployment, identify the existing host, commission its
same-origin backend boundary, verify revoke/photos/bot continuation there, and
define atomic artifact activation/rollback and its own installed receipt. Editor
and collector remain separate surfaces of the same constructor and the existing
specification. They must not gain parallel stores or senders.

## Owner-created dedicated host

The owner created tours.anytoour.ru on25October. The dedicated host workflow
runs only on main, after the canonical production deployment succeeds. It checks
fresh authoritative main and installed identity before touching that exact host.
The existing app, DB, cached images, immutable versions, revoke and bot-entry
owners remain canonical; no config, manager app or credentials are copied.

deploy.php validates the exact source/payload and refuses foreign site content.
Only the recorded ISPmanager placeholder or its own three front controllers and
two asset links are admissible; ACME .well-known is preserved. Versioned views
and assets live outside document roots. Atomic current-release activation keeps
the packaged view and its two static assets together. Initial rollback restores
the exact archived placeholder; later rollback restores the previous release.
The fixed CLI uses only the owner-created site and this project's release root.

The public wrappers expose only index, reviewed-photo and signed-activity routes.
They set the exact tours origin; the original app endpoint still accepts only
its original origin. Anonymous fetches create no manager session. Strict-TLS
smoke requires the unavailable page and headers, exact CSS/JS, closed config/
manager routes and correct origin rejection before a private deployment receipt
enables optional public/preview URLs. The UI accepts only the exact tours origin
with the existing token. Existing app paths and signed previews remain available.

Any declared backend or presentation drift invalidates that receipt and falls
back to existing app links until a matching public release is verified. Unrelated
commits keep the package identity and receipt valid. A failed smoke automatically
rolls back the pending installation and does not enable new links. This technical
host verification does not replace valid-photo, physical mixed-source import,
different-account bot continuation or the remaining V1 acceptance.

## Existing-host inventory

`tour-selection-hosting-inventory.yml` runs only on an explicit main manual run,
with three fixed read-only operations. It uses the existing canonical SSH
identity read-only. The CLI probe lists bounded owned document-root directory
names and declared nginx/Apache hostnames; it emits only `anytoour.ru` names and
availability/truncation flags. It does not bootstrap the app, access customer/DB
data, read certificates/private keys, change hosting/configuration, or test all
discovered URLs. Missing visibility is not evidence that a domain is absent.
Directory/vhost existence does not establish current DNS/TLS or a selection app.

The owner-requested25Oct commissioning reads are consumed, not scheduled:
40225971588 found the existing `d.anytoour.ru` directory and vhost;
40226320888 confirmed DNS and strict HTTPS HEAD403 (curl0/SSLverify0);
40226614056 identified `anytour-yandex-autopilot`0.1.0 / “AnyTour Yandex Direct &
Metrica Autopilot”, with its existing Python sources. That host belongs to the
advertising project and must not be overwritten by the constructor. No hosting
configuration, file, package or application on that host was changed. The temporary
exact source-branch probe trigger has been removed; merging this package does not
rerun those consumed observations. A constructor host still needs its own explicit
binding and accepted publication boundary.
