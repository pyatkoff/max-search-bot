# Common V2: disposable MySQL 8 engine gate

This is a source-only CI test package. Publish it as
`audit/max2-common-v2-20261121/mysql/`, beside sealed `policy/`, `delivery/` and
`ledger/` packages. Its separate workflow is
`.github/workflows/max2-common-v2-mysql-20261121.yml`. It does not install a
migration or activate a worker in the application environment.

The PHP entry is `tests/mysql.php`. It requires PHP 8.2 with `pdo_mysql` and
`pcntl`, `GITHUB_ACTIONS=true`, and
`MAX2_V2_MYSQL_FIXTURE=ephemeral-ci-only`. There is no configurable DSN or app
configuration read. It connects only to `127.0.0.1:33306`, database and user
`max2_v2_ci`, using the public synthetic password defined in the disposable
service. It verifies the MySQL 8.0 version, database/user identity and an
initially empty database before its first DDL. A dedicated marker and an
exact table allowlist guard subsequent fresh cases. Every test write, drop
and injected failure is confined to this disposable database.

The service uses the Docker Official Image `mysql:8.0.44`, pinned to index
digest `sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714`.
The digest was read from the official image page:
https://hub.docker.com/layers/library/mysql/8.0.44/images/sha256-00ae178085bfcfbcab92d1cd5942da056dfb3663f212dbe99e85b156f5762958
This fixes the test engine image; it is not a recommendation to deploy that
version to a server.

The fixture executes the exact SHA-pinned migration 112 SQL, split at its two
statement terminators without a dialect transformation. It uses the actual
repository's production MySQL constructor, with no SQLite escape flag. Only
three pure synthetic input-builder functions are reused from the sealed ledger
fixture; their exact excerpt and original file digest are checked before use.
Neither that fixture's top-level runner nor any provider transport is executed.

Coverage includes the actual InnoDB engine and all eleven enforced CHECK
constraints; refusal of the protected old goal; the foreign key; receipt
uniqueness and multiple NULL receipt accounts; immutable inquiry binding;
positive deltas; durable intent and exact CSV hash; unknown/no-reissue;
separate accepted and processed receipts; preservation of an outer transaction;
and lost acknowledgement after a real successful reservation or intent commit.
The rollback case adds one temporary CI-only CHECK after creating the exact
migration, so the event fails after the account was inserted. It changes no
server privilege or global variable. This avoids trigger/SUPER prerequisites
documented at https://dev.mysql.com/doc/refman/8.0/en/create-trigger.html .

Two further tests fork independent processes, which open their own MySQL
connections only after the fork. A Unix-socket barrier holds both empty-account
locking reads before the reserve race. A separate barrier brings both attempt
contenders to the account lock while the first owner is held. The assertions
require distinct connection IDs, one stored reserve, one durable attempt and
exactly one sendable result. A deadlock loser may be HOLD or UNKNOWN, but cannot
be retried or become a second sendable result. These are real MySQL transactions
against synthetic data, not a sequential SQLite approximation.

Every case checks a synthetic v1 outbox sentinel containing its unchanged
300-ruble old-target value. All original migration, repository, policy and
delivery source hashes are checked afterward. No private credentials, live
database connection, existing application table, provider request, source
publication or deployment occurs in this package.

Status at source preparation: **native PHP/MySQL execution pending**. There is
no local PHP binary or MySQL test run here. The CI report must be evaluated from
the original native run; these source files do not establish a PASS by themselves.
