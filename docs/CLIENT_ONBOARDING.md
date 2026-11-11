# Client onboarding

Owner decision: AppleService39 is one customer of the shared platform. New
customers are isolated project accounts with reusable industry behavior, rather
than repository forks. The first cabinet template is `device_repair`.
Other industries are not presented as ready templates.

## Operator path

Open **Компании** from Manager2's account menu or administration, then **Добавить
клиента**. A platform administrator enters the company name, MAX bot username and
token, first employee credentials and optional UTF-8 TXT/CSV materials (100 KB).
Saving creates the company, manager-first MAX source, employee and connection
without registering a provider subscription. Existing companies, employees,
shifts, lead destinations and routing settings are not edited.

The employee has role `manager`, membership only in that company and starts off
shift. Platform administrators retain their platform-wide authority. Employees
can read their own company's materials here. Materials are reference text; this
slice does not feed them into AI or promise automatic prices.

**Проверить подключение** uses the existing `ProjectMaxWebhookSetup`: provider
GETs, the actual active project/source directory and an authenticated ignored
endpoint probe. It sends no customer message. **Активировать подключение** is a
separate explicit action through that same service. A conflicting existing
provider URL blocks activation and is never removed. New companies use the
existing generic repair webhook, recorder, access policy and outbound adapter;
there is no parallel channel/history/sender owner.

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

Creation and activation have actor-scoped immutable operation IDs. The browser
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
