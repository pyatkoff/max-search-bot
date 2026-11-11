# Fixed regional-manager operator

This standalone audit-branch package is not part of the application's main
feature tree. It has no HTTP endpoint and is never merged to `main`.

The separate explicit owner instruction and the existing canonical SSH operator
authorize account creation. Audit attribution is pinned to authenticated actor
2, AnyTour project 1 and the previously verified link-context receipt. The
operator checks that same active identity again before each canonical mutation;
it never chooses an arbitrary administrator or constructs a browser session.

Before publishing the branch, pin `EXPECTED_APPLICATION_SHA` in
`.github/workflows/regional-manager-provision.yml` to the accepted **installed**
feature SHA. The initial non-hex placeholder cannot reach SSH. Only the exact
`audit/regional-manager-provision-20261111` push can execute this workflow.
`PROVISION_OPERATION` is a reviewed literal changed separately to `prepare`,
`apply`, or `status`; there is no dispatch, schedule, main or post-deploy trigger.

## Prepare and encrypted input

Prepare creates a 3072-bit RSA private key and one request with a maximum
30-minute lifetime. It returns the server public key, request ID, source SHA,
expiry and preview digest **inside a packet encrypted to the existing fresh
local recipient public key**. No recipient private key is read or transmitted.

The owner decrypts this packet locally and builds one plan containing exactly:

```text
contract: anytour-regional-manager-provision-v1
request_id, application_sha, expires_at, preview_digest: exact prepare values
actor_id: 2
project_id: 1
project_key: anytour
targets: exactly two objects
  territory_region_id: one 62 and one 11131
  login: an unused ASCII account login
  display_name: the approved staff label
  group_key: an unused lower-case regional group key
  group_name: the approved group label
```

Only the encrypted plan goes in `audit/regional-manager-plan.enc.json`. Personal
labels and logins do not belong in source, workflow variables or clear logs.
All hybrid packets use RSA-OAEP with SHA-1 (PHP's OAEP padding contract) wrapping
a random 32-byte AES-256-GCM key, a 12-byte nonce and a 16-byte tag. Each packet
contains exactly `version=1`, `algorithm=RSA-OAEP-SHA1+A256GCM`, `encrypted_key`,
`nonce`, `tag`, and `ciphertext`, with canonical base64 fields.

Response AAD is `anytour-regional-manager-provision-v1`. Apply-plan AAD is
`anytour-regional-manager-provision-v1:apply:<request_id>`. Plan expiry, source,
actor, project and preview values are checked again after authenticated decryption.

## Apply and recovery

Before its first database call that can write, apply generates both independent
256-bit URL-safe passwords in server memory, encrypts their recovery packet to
the local recipient, and durably records `phase=applying`. It then invokes only:

1. `AdminDirectoryService::saveManagerForProject` for new active manager accounts,
   each with only project 1 and regional restriction enabled; shift stays off.
2. `RoutingAdminService::saveGroupResult` for two **new** groups, each containing
   its new manager and exactly its reviewed territory.
3. `RoutingAdminService::appendMembersResult` for existing common group 1,
   preserving all prior members, including inactive members, and all territories.

Common group 1 must have **no** regional territory headers or descendants. A
scoped common group is refused before the first database write, because adding
both accounts to it could union unwanted territories into their permissions.

Existing logins or group keys are conflicts; no update/reset is attempted.
Sources, bonuses, operator shifts and existing assignments are never changed.
Final readback verifies account role, active state, off-shift state, exact project
grant and restriction; exactly the common and personal group memberships;
group members and actual compiled territory membership;
preserved common-group metadata/scopes/members; and unchanged sources. Only then
does the request become `configured`, with an encrypted final result, and its
ephemeral server private key is removed.

Any partial write, lost result or failed final check remains `applying`/UNKNOWN.
Prepare and apply cannot replay it, reset a password, or create replacements.
Use status for read-only reconciliation and to recover the original sealed
credential packet. Status does not repair or mark an unknown result successful.
An exact already-configured replay returns the original encrypted result without
database writes; a different envelope is refused. A retained key after a final
cleanup failure can only be removed by that exact configured replay.

Private files are fixed beside the existing protected configuration:
`max-search.php.regional-managers-v1-{lock,state.json,private.pem}`. They require
owner-only `0600` regular files in the existing private directory. The state
contains commitments, IDs and encrypted packets, never clear passwords. Expiry
does not erase UNKNOWN evidence or automatically start a new request.

## Verification

`php tests/run_regional_manager_provision_regression.php` exercises real hybrid
crypto and private-file permissions against generated synthetic keys, injected
synthetic directory operations and temporary files. It never accesses a server,
application configuration, actual recipient private key or database. The workflow
runs this test before preparing its SSH connection. The actual application
services and native regional ACL are verified by the feature's required suites.
