# Client onboarding

Owner decision: AppleService39 is one customer of the shared platform. New
customers are isolated project accounts with reusable industry behavior, rather
than repository forks. `ClientBusinessProfile` owns the finite choices:
`device_repair` asks about the device, while `manager_inbox` supports a company
of any industry through a neutral question and its employee's reply. Both use
the same canonical manager handoff. The neutral profile includes no industry
questionnaire, automatic pricing, AI configuration or publication catalogue;
specialized profiles for other industries are not presented as ready.

## Operator path

Open **Компании** from Manager2's account menu or administration, then **Добавить
клиента**. A platform administrator chooses the profile and enters the company name, MAX bot username and
token, first employee credentials and optional UTF-8 TXT/CSV materials (100 KB).
Saving creates the company, manager-first MAX source, employee and connection
without registering a provider subscription. Existing companies, employees,
shifts, lead destinations and routing settings are not edited.

The form presents five local steps: company/profile, employee, optional
materials, existing MAX bot and a final non-secret review. Next/Back retain the
same fields in memory; only explicit final saving sends the one creation
operation. Enter and step navigation never create a partial account. Native
validation reveals the relevant step, including the server's UTF-8 byte limits.
The review contains neither token nor password. Closing/reloading an unsaved
form does not persist its credentials; a saved operation still recovers by its
existing receipt. The operator then separately verifies and activates the bot;
the employee separately signs in and enables their own shift.

Material import has one current file/form/actor generation. A newer file,
manual text edit, cancellation or form/account reset invalidates an earlier
read. A pending read blocks advancing that step and final saving, with an
explicit cancellation that preserves current text. Late results cannot overwrite
a newer draft, review or another account.

The employee has role `manager`, membership only in that company and starts off
shift. Platform administrators retain their platform-wide authority. Employees
can read their own company's materials here. Materials are reference text; this
slice does not feed them into AI or promise automatic prices.

**Проверить подключение** uses the existing `ProjectMaxWebhookSetup`: provider
GETs, the actual active project/source directory and an authenticated ignored
endpoint probe. It sends no customer message. **Активировать подключение** is a
separate explicit action through that same service. A conflicting existing
provider URL blocks activation and is never removed. New companies use the
existing generic project webhook, recorder, access policy and outbound adapter;
there is no parallel channel/history/sender owner.

Profile settings are built from the selected kind and this company's name/key,
without copying another company's settings. Materials remain its own reference
text. The selected profile and its limits are visible in the company card;
an unknown profile is not silently labelled as a supported one. The existing
reviewed travel and repair configurations retain their own behavior.

Before commissioning, **Исправить данные до активации** can correct the bot,
replace its token and edit the reference materials. An empty replacement token
keeps the current one. Saving resets the prior check; unsaved edits disable
checking and activation. Existing staff and their access remain unchanged.
Any attempted activation, confirmed registration or recorded conversation locks
this correction path so it cannot retarget an account with history.

## Isolation and credentials

`project_id` / `project_key` remain the company boundary. Generated
`client_<24 hex>` keys need no source edit or deployment per company. Migration
101 adds connection and operation records attached to that existing directory.
Applied migrations and existing companies are unchanged. Runtime requests never
create tables. The registry extends the protected static MAX registry; legacy
settings are preserved. Duplicate bot names or tokens are rejected.

Token and generated webhook secret are AES-256-GCM envelopes bound to the exact
project and bot. A keyed token fingerprint supplies uniqueness. No API/debug
view returns credentials. Passwords use the existing password hashing owner.
Browsers retain no token/password in local/session storage.

The 32-byte key is `client-credentials/master.key` beside the existing private
diagnostic log, outside the checkout/web root (directory 0700, file 0600). Only
the explicit first creation with an empty registry can create it. Keep this same
private file in protected server backups alongside the database; ciphertext
alone cannot recover connections. A missing, changed, publicly readable or
symlinked key fails closed and is never regenerated for a nonempty registry.
Restore the original key and permissions; do not erase records or generate a
replacement to bypass the failure.

## Lost response and activation recovery

Creation, correction and activation have actor-scoped immutable operation IDs. The browser
stores only that ID per account. A lost response/reload reads its receipt and
does not replay the mutation. Form data remains in memory until a known creation
result; late responses cannot restore another account or card.

Explicit cancellation records a tombstone before a late creation, or cancels an
activation only before its durable provider claim. After that claim, cancellation
and repetition are refused even when a response was lost. Accepted exact
registration plus readback supply the receipt. GET /subscriptions alone cannot
prove the secret. An unknown mutation retains that company's activation fence
for investigation by the existing operator; this workflow never retries it or
marks it active from a URL alone. Other companies remain usable. No transaction
or native session lock crosses provider I/O. Installation activates no client.

## Verification

The required suite includes real SQLite transactions/crypto and an exact PHP
HTTP endpoint with real session, CSRF and access checks. The existing personal
workspace MySQL workflow additionally applies the actual forward migration in
an empty loopback fixture and races independent creation/cancellation workers.
Manager2's canonical Chromium/WebKit inventory includes the shipped page at
390/430/768/1440 and short 390×420, lost responses, identity/selection races,
explicit activation and employee-only materials. Full shared-source CI and the
ordinary sole-publisher installation/tails remain required.

`run_manager_client_scale_regression.php` repeats the actual creation,
authenticated inbound recorder/handoff, authorized employee conversation read
and own quick-reply preparation for50 isolated SQLite companies (25 per supported
profile). It checks identical provider identities, replay, bot conflicts,
materials, keys, revoked membership and one company's unknown activation while
another remains usable. All provider writes/greetings are intercepted locally;
no live database, customer messages or real company activation is used. Reported
times measure the synthetic server path, excluding human entry and provider
network latency. Native tests cover final review, two tabs, reload, lost outcomes,
short screens and material-import races; these do not represent50 live clients.
