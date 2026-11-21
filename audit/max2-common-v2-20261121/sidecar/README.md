# New signed MAX2 own-reserve lookup sidecar

**Source preparation only. This package is not installed and no real lookup was called.** It exposes one read-only purpose for the new common-value accounting path, with its own endpoint and protocol. The existing attribution endpoint, its six-file inventory, public verification key, app signing key, tracker settings and old goal sender remain outside this package.

## Exact endpoint and trust boundary

Future fixed URL: **`https://tour-max.ru/new/max2-value-attribution/lookup.php`**.

The canonical request uses the existing header names:

| Header | Required content |
|---|---|
| `X-Max-Attribution-Signature` | Base64 RSA/SHA256 signature of the **new purpose**, newline and exact canonical request body |
| `X-Max-Attribution-Evidence` | `max2-own-early-reserve-v2` |

The signed purpose is `anytour-max2-own-early-reserve-lookup-v2`; version2, project1538, counter98615635, fixed producer contract, exact actor/context, nonce and 90-second clock bound are validated by the already reviewed protocol. An old-purpose signature, old header, extra field, wrong counter/project/contract, stale or noncanonical request never reaches the database. The endpoint accepts only the exact URI, no query string, POST and JSON. The body is bounded to8192 bytes before authentication; the response is bounded to65536 bytes and independently validated against the original signed request.

The `publicKey` and `read` callbacks in the pure HTTP kernel are internal source seams for tests. HTTP input cannot supply a key, path, PDO credentials, admission digest, callback, SQL or writer permission. The actual endpoint binds them to the fixed runtime class. Method/path/content/authentication failures return generic errors; no actor, identity, private exception or credentials are logged. Every response is `Cache-Control: no-store` with `nosniff`. There is no CORS or redirect behavior.

## Existing dependencies only

The future server directory is `/var/www/www-root/data/www/tour-max.ru/new/max2-value-attribution`.

- The tracker reads its **existing sibling** public key at `/var/www/www-root/data/www/tour-max.ru/new/max-attribution/verification.pem`, without copying or editing it. The SPKI DER SHA256 must remain `b5a8227d84674149ae1384772bc3ce2e153ed67de1b9e19d6721965dc8203702`. The calculation matches the existing pinned attribution installer. No private key is installed on the tracker.
- The app retains its existing signing key and the parent-owned new HTTPS adapter uses this exact URL and these exact headers. No key, credential, role, secret or global configuration is added here.
- Only after authentication, the runtime loads the existing fixed `/var/www/www-root/data/www/tour-max.ru/bitrix/.settings.php` connection settings. It captures/discards include output, checks regular exact non-symlink files and stable metadata, validates DSN components, creates a nonpersistent PDO connection with five-second connection timeout, and passes the separately compiled producer-contract digest to the new repository. It does not boot Bitrix, create a session or invoke the legacy endpoint.

The trusted producer contract is `3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e`, binding the actual installed initial producer053228… and accepted runtime3f1b98…. The same digest in a request is a consistency check, not a grant of admission.

The exact immutable dependency copies are `Max2ValueLookupProtocolV2.php` SHA256 `04100d16541e5d1c961845ad59a5fb9b11840361630c4bf3b83a80c360bc2fe2` and `Max2ValueLookupRepositoryV2.php` SHA256 `40ab600c7b8e619da212d0a10942046fb36a37ab13ba661f6019d40b3bd9fac9`. They are not refactored. The repository uses an explicit read-only transaction and bounded SELECTs, metadata/actor-index/counter checks, a 65-row prefix sentinel before filtering, maximum64 rows/32KiB, query timeout250ms and wall budget500ms. A point recheck uses only the selected primary key. No broad fallback, latest-row selection or write exists.

## Zero, unknown and delivery

A valid, complete empty result proves zero **own initial new-goal reserves** for the exact actor at observation under the admitted producer contract. It does not prove no historical MAX2 entry, absence of a contact, or a paid click. An invalid or partial source, unavailable key/settings/database, schema/index/clock mismatch, exceeded budget or malformed response fails closed; it cannot become zero.

A repository `unknown` result is returned as an authenticated, structured HTTP200 response with null counts, no candidates and `possible_max2_unresolved=true`. An unavailable runtime returns genericHTTP503, which the client must treat as unknown. The endpoint makes one repository call with no retry. It has no persistent claims, event issuance, goal call, CSV/upload, ledger mutation or financial activation. `issued_unconfirmed` remains a reservation, never evidence of JS delivery or provider processing.

## Required installation gate

This source package does **not** contain an installer or release request. Before creating the new sibling directory, a fresh operation must independently verify the current exact source, path absence or explicit preimages, owner/modes and parent-directory permission; preserve the old endpoint subtree/key; verify the existing key DER pin and settings access without printing contents; and retain original source/run/job/artifact evidence. Unexpected existing members or an unknown filesystem outcome require inspection, not overwrite/replay. The runtime's regular-file check rejects group/other-writable key/settings files; any observed incompatibility must be reviewed explicitly rather than silently weakening the check.

The activation order must install the reviewed helper files first and `lookup.php` last, with exact readback and bounded rollback for only this new operation. A passive GET can verify method refusal/no-store without touching database rows. A real signed read belongs to a separately admitted natural worker intent and is not synthesized here. Current producer/map/old-receiver releases must not be replayed to install this sibling.

Source justification for the existing key/settings paths and old six-file inventory: app source [`b678bc2f211c5f141751137453b199036491222d`](https://github.com/pyatkoff/max-search-bot/tree/b678bc2f211c5f141751137453b199036491222d), `max-attribution-lookup.php`, `services/MaxTrackerLookupRepository.php`, `tools/install_max_attribution_lookup.py`. New source lives in this package only.

## Verification

Local source verification:

```sh
python -B verify.py --lookup-root ../max2-common-v2-lookup-20261121
```

Native source-only CI, with PHP8.2/OpenSSL and no credentials/network:

```sh
find candidate tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -d allow_url_fopen=0 -d allow_url_include=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail tests/sidecar.php
```

The fixture creates an ephemeral synthetic RSA key, uses callbacks and validates existing-settings *shape* with synthetic values. It never calls the real runtime key/settings methods, opens PDO, or contacts the endpoint. It covers old-purpose/header/signature substitution, malformed/stale/extra/counter/contract requests, early request/body failures before any key/DB callback, precise zero/unknown semantics, single failed read/no retry, bounded invalid responses, exact point-recheck coverage, public DER pin and DSN injection rejection. No native PHP result is claimed from a machine without PHP. The existing lookup repository fixtures should additionally run unchanged against the same copied modules in native CI.
