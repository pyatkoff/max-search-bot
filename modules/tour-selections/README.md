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

## Existing-host inventory

`tour-selection-hosting-inventory.yml` runs only on main changes to its own probe
or workflow, or an explicit main manual run. It uses the existing canonical SSH
identity read-only. The CLI probe lists bounded owned document-root directory
names and declared nginx/Apache hostnames; it emits only `anytoour.ru` names and
availability/truncation flags. It does not bootstrap the app, access customer/DB
data, read certificates/private keys, change hosting/configuration, or test all
discovered URLs. Missing visibility is not evidence that a domain is absent.
Directory/vhost existence does not establish current DNS/TLS or a selection app.
