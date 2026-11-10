# Explicit project settings

The existing AnyTour facade continues to load and cache project.php.
Legacy search URLs, origin overrides, state paths, routing and analytics remain
owned by their existing callers.

ProjectConfig::forProject($expectedProjectKey, $serverLoadedValues) creates an
independent ProjectConfiguration. It never replaces the legacy static config
or the settings held by another snapshot. Both readers share the same dot-path
lookup semantics, including explicit null, false, zero and numeric array keys.

The constructor requires the exact expected id. New explicit keys follow the
existing advertising-visit project-key shape: a lowercase letter followed by up
to 39 lowercase letters, digits or underscores. This validation does not change
the legacy default-project path.

The snapshot copies arrays recursively, including branches held through PHP
references. Returned arrays are independent values. Objects, resources,
non-finite numbers and nesting beyond 32 levels are refused, so a cycle cannot
turn a settings read into unbounded recursion.

## Trusted caller boundary

This is a settings boundary, not an authorization service or a project router.
The caller must derive the expected project from an already verified server-side
connection/source or an authorized manager scope. Existing conversation, source,
file and operation access checks remain mandatory.

Never choose both the expected ID and its configuration from arbitrary request
parameters. Never use resetForTests() to switch between live projects. The new
factory accepts an already loaded array; it does not turn a request value into a
file path, perform database registration, activate a bot or export a conversion.

Typical use after the existing scope check:

```php
$settings = ProjectConfig::forProject($resolvedProjectKey, $serverLoadedValues);
$name = $settings->get('brand.name');
```

There is no implicit merge with AnyTour. An absent repair search URL, bot,
lead destination or analytics binding stays absent. Connection secrets remain
in the existing private configuration, outside these public settings.

## Repair template and next consumer

resources/project-examples/iphone-repair.php is an unconfigured public example,
not an enabled project. It declares iphone_repair and device_repair only.
Brand, routes, messengers and analytics require the actual business inputs; it
contains no invented prices, hours, addresses, bots or counters.

The next slice binds the business's verified connection to its configuration and
routes one real service context through the existing conversation and handoff
owners. That slice must apply project authorization and its own delivery policy.
This settings API alone does not establish full database, media or dialogue
isolation, a repair workflow, or a working second business.

### AppleService39 publication preview

Run `php tools/preview_appleservice39_offer.php` to prepare one reviewed offer
from two price rows on the AppleService39 site. The example's `appleservice39`
project key is explicit and used only for this preview. The generic
`iphone_repair` example is a separate template. Production must verify the
actual project and connection before importing or publishing this offer.

The service identity uses the business, model and operation. The fingerprint
changes with the source evidence or draft while the identity stays stable.
An exact model price appears only when two distinct same-domain source pages
agree on the exact amount and no price review is pending. Other price kinds
remain in the evidence, and the draft asks customers to confirm the cost.
The raw warranty marks and conflicting durations are retained without a promise
in the caption. The draft includes the button label and a local iPhone X
illustration in `resources/project-examples/assets/`. The source page's artwork
advertises a third duration, so the pilot uses a separate illustration without
a time claim. The live button URL and message/version identifiers belong to the
existing channel post registry after observation of a real publication.

Use `--catalog /path/to/catalog.json` with the extracted onboarding catalog to
resolve the two pinned original row IDs. The preview checks business, operation
and exact model and retains the table and row coordinates. Unknown review flags
hide the price. With `--cover /path/to/reviewed.png`, it additionally accepts
the owner-provided iPhone X cover only when the bytes match and the catalog
still supports both printed claims: 3,500 RUB and free iPhone X diagnostics.
The resulting asset name is `appleservice39-iphone-x-3500-reviewed.png`.
The onboarding catalog and the reviewed cover remain operator inputs.

Add `--posts /path/to/posts.json` from the launch package to verify the exact
first publication. It checks the pinned post ID, iPhone X and battery context,
price, supporting catalog rows, reviewed cover and exact customer-facing caption.
This is an offline check. It reports provider capability separately.

On the launch host, add `--channel-id ID` using the numeric MAX channel ID from
the provider's update. Supply `APPLESERVICE39_MAX_TOKEN` through the host's
private configuration. The tool reads the dedicated bot's identity, admin/read/
edit permissions, and exact active public channel link. It prints a safe receipt
and exits with status 3 if that read-only verification fails. The tool does not
load the existing AnyTour config, change a webhook or publish. A positive result
only proves the channel capabilities. The repair runtime and the manager route
still require their own verification before publication.

For repair ads, the candidate is the [universal CSV feed](https://yandex.ru/support/direct/ru/feeds/requirements-other-categories#universal),
with a stable offer ID and a verified offer-page URL. A preview creates no feed
row, since this example has no verified offer-to-bot landing URL. It does not
register a project, write to the database or contact a provider.

## Verification

php tests/run_project_marker_boundary_regression.php runs in the existing
required architecture group; its marker checks are retained and the settings
cases extend that same invocation. It exercises real legacy/default search behavior,
app/tracking overrides, alternating projects, wrong-scope rejection, returned
array mutations, shared PHP references, bounded cycles and typed lookup values.
It does not make network calls, open a database or mutate live configuration.
The existing channel-post registry regression runs the repair draft on MAX and
Telegram fixtures with an isolated database. It checks the existing token,
project boundaries, idempotent entry and immutable manager readback.
The normal required CI for the complete change remains authoritative.

## Shared manager connection for AppleService39

The repair runtime now consumes the explicit settings boundary. The production
route is the existing application and Manager Workspace V2. A separate website
directory, private JSON inbox and personal MAX recipient are not prerequisites.

`MAX_SEARCH_PROJECT_CONNECTIONS` is read only from the existing protected server
configuration. Its public `project` settings and private `max` credentials are
separate. The Apple entry uses project `appleservice39`, bot
`id390521129062_bot`, and source `max:appleservice39-main`. Unknown or incomplete
entries remain disabled. The legacy project continues using its existing token.
Duplicate bot identities, tokens or webhook secrets are rejected for additional
projects. Neither requests nor conversation metadata can supply a credential.

| Responsibility | Canonical owner |
| --- | --- |
| Trusted settings and connection | `ProjectConfiguration`, `ProjectMaxConnection` |
| Authenticated repair ingress | `appleservice39_webhook.php`, `RepairMaxWebhookService` |
| Customer, conversation and history | Scoped methods of `ConversationRecorder` |
| Manager queue and ownership | Scoped methods of `ConversationControlService` |
| Observed publication context | `ChannelPostRegistryService` |
| Manager text, media and edits | Existing services through `ProjectMaxMessengerFactory` |
| Protected photo/video recovery | Existing manager media endpoint and archive |

The webhook accepts authenticated private customer messages and bot starts.
An active project and its active MAX source must already exist. The core history,
source context and manager handoff commit before HTTP success. MySQL advisory
locking serializes a project's bot/user deliveries, and saved message/event
identities suppress provider retries even after a conversation is closed.
The same MAX user or message ID in another company remains independent.

The `as39_x_battery` pilot entry carries the reviewed iPhone X / battery context.
It does not assert that a channel post was published. A genuine `p_` entry must
resolve through the existing registry with the exact project, platform and bot.
Existing manager ownership and quiet reentry are preserved. Unknown starts do
not erase a known device or service. The first greeting asks for the problem and
allows a photo; later customer messages reach the existing manager queue without
automatic bot replies or mandatory phone capture.

A first greeting has a bounded delivery intent in the existing handoff event.
A retry may recover a persisted intent only before any provider write was
attempted. A timeout or lost receipt stays explicitly unknown and is not sent
again automatically. This avoids duplicate messages and does not claim an
exactly-once guarantee from MAX. Confirmed replies are recorded in the exact
conversation. Media archival runs after the HTTP response and lock release;
the authorized manager endpoint can recover a missing local copy later.

Manager operations resolve the authorized saved conversation's project and bot
binding before dispatch. Replacing a bot in private configuration cannot silently
retarget an existing conversation. Missing or mismatched connections produce a
definite `connection_unavailable` rejection. Lifecycle actions and travel context
keep using the legacy dialogue store only for its original project, including
the existing MAX, Telegram and website chat keys. Repair replies and sales events
cannot enqueue that project's conversion goals.

### Installation and commissioning

The new route is disabled until its private connection and directory entries are
configured. Use the existing checked application deployment. The dedicated
manual setup workflow resolves the accepted repository/application SHA and shares
the existing deployment serialization. Its CLI updates the existing protected
config; it does not create a parallel public/private inbox.

The setup exchanges only an expiring public key and authenticated ciphertext.
The token stays out of Git, workflow inputs, command arguments and logs. The
import verifies the dedicated bot identity before an atomic private config
replacement and preserves other companies' settings. Register the source through
the existing project/routing owners with an identified active administrator.
Ordinary managers retain their explicit project memberships and shift settings.

After installation and configuration, inspect the bot's existing subscriptions
before setting its exact application webhook. Do not replace an unrelated bot's
subscription. Commission with one real owner-authorized conversation: start the
Apple bot, see its model/service and customer message in Manager Workspace V2,
reply from that panel and verify receipt in MAX. A provider configuration check,
synthetic regression or successful deployment alone is not live acceptance.

The added required regressions cover connection/authentication rejection,
cross-project customer and message collisions, transactional rollback, duplicate
starts, quiet manager ownership, greeting delivery ambiguity, exact bot-bound
manager sends/edits, protected media and analytics isolation. The original
settings, publication, lifecycle and delivery regressions remain required.

## Another repair business through server configuration

The same `MAX_SEARCH_PROJECT_CONNECTIONS` registry can bind another repair
company to its own existing active project, MAX source, bot and credentials.
The canonical setting is `business.kind = device_repair`. The earlier
`business.profile` spelling remains an alias when `kind` is absent or equal;
conflicting, malformed or non-repair values are rejected by repair ingress.
The legacy AnyTour configuration is never switched or implicitly inherited.

An additional company explicitly enables the common entrypoint with its exact
server-owned URL:

```php
'project' => [
    'id' => 'iphone_repair',
    'brand' => ['name' => $verifiedBusinessName],
    'business' => [
        'kind' => 'device_repair',
        'repair' => ['entries' => [
            'battery' => ['model' => $reviewedModel, 'service' => $reviewedService],
        ]],
    ],
    'routing' => ['source_key' => 'max:iphone_repair-main'],
    'messenger' => ['max' => [
        'webhook_url' => 'https://app.anytoour.ru/project_max_webhook.php?project=iphone_repair',
    ]],
],
```

The private `max` section retains its own `bot_key`, `token` and
`webhook_secret`. This example is a configuration shape, not a registered
company or authorization to activate it. Existing project/source membership,
connection uniqueness, explicit setup admission and immutable operation claims
remain mandatory. The common endpoint authenticates the selected registry entry
before reading an update; URL, credentials and catalog content never come from
request parameters. A missing URL, another host/path/project, or an unconfigured
connection fails closed. The AppleService39 endpoint and fixed encrypted
bootstrap continue to use their original exact bot/source/URLs. Other companies
have no implied legacy webhook to delete.

`ProjectMaxWebhookSetup::verify()` and `activate()` use the resolved descriptor
for the company, retaining identity/readback/TLS checks and the bounded request
budget. Verification can perform provider reads and an authenticated inert
probe; activation still requires the operator's durable `beforeMutation` claim
and exact registration proof. These methods do not provision directories or
import secrets. No generic mutation workflow or automatic activation is added.

Each configured entry is a server-reviewed model/service pair with a payload of
1–64 ASCII letters, digits, underscores or hyphens. At most128 entries are
admitted. Reserved `p_`, `as39_`, `ts_` and `s3_` prefixes keep their existing
owners. Labels are bounded plain text. This slice supplies a contextual greeting
and manager handoff without inventing prices, warranty, post URLs or publication
receipts. Actual publications can link the payload after their own acceptance.

History, handoff, manager ownership, media and outbound transport remain owned
by the existing project-scoped services. Ordinary messages in a manager-owned
conversation remain quiet. A configured entry captures its text and a settings
fingerprint before any send. A genuinely unattempted pending reply can recover
only with the same project/bot/source/URL, entry and brand. Configuration drift
holds it; restoring the exact configuration can recover only that original
unsent intent. Attempted or unknown sends are never retried. Historical Apple
intents and the original `as39_x_battery` alias retain their accepted contract.

Required connection tests exercise the actual HTTP endpoint with inert local
fixtures. Repair-ingress tests run two companies with identical user/message IDs,
separate context and manager transports, and configuration-drift/no-replay cases.
Setup tests verify a second bot's own identity, URL and registration claim.
These synthetic checks and source installation do not prove a newly activated
business or a natural customer conversation. Repair events remain excluded from
the existing AnyTour monetary model and advertising goals.
