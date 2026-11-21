# Isolated MySQL 8 gate for new common-goal runtime state

This is source-only CI preparation. No production connection, migration, activation, provider request, deployment or successful real HTTP authentication is performed by preparing or reading this package.

The proposed public group is `audit/max2-common-v2-20261121/mysql-runtime/`. Copy `ops/workflow.yml` byte-for-byte to `.github/workflows/max2-common-v2-mysql-runtime-20261121.yml`. The workflow verifies the equality of these copies and the complete manifests of this group and its dependencies before executing the fixture. Root owns publication and the original native run.

## Isolation

`tests/mysql.php` requires CLI, `GITHUB_ACTIONS=true` and the distinct `MAX2_V2_MYSQL_RUNTIME_FIXTURE=ephemeral-ci-only` marker. It connects only to fixed loopback `127.0.0.1:33307`, database and user `max2_v2_runtime_ci`, with explicitly synthetic credentials. It rejects another database, user or non-8.0 engine. It proves the service database initially empty before creating its disposable marker. Further resets accept only the fixed fixture table set. They do not accept a caller-supplied DSN, table, database or production configuration.

The separate job uses the same pinned official MySQL 8.0.44 image as the accepted migration-112 engine gate, its own service and port, PHP 8.2, a normal database user and an empty PHP environment apart from the required fixture markers and PATH/locale. URL wrappers, provider cURL calls, external socket helpers, shell commands and subprocess functions are disabled inside PHP. The fixture's two-process races use local Unix socket pairs and independent PDO connections to this one service. The job has no production secrets or deployment step.

## Native contracts exercised

- Execute the exact frozen migration `113_max2_common_runtime_v2.sql`, SHA256 `092c123615aae8261c19b8aa7316b0631926698906491c03869f28373646ada9`, without SQL rewriting: five InnoDB tables, thirteen CHECK constraints, two tick foreign keys and the default-OFF row.
- Use the production MySQL constructor of `Max2CommonValueStateV2`. Synthetic activation samples the clock and current global AnyTour profile maximum, including a Telegram profile and excluding a larger foreign-project profile. Repeated activation does not reset the floor; a mode change preserves the epoch.
- Preserve atomic outcome/cursor updates, state/lease checks, UNKNOWN after actual commit acknowledgement loss, one durable intent before any hypothetical call permission, and refusal to reissue the same action with another nonce. A CI-only CHECK forces a failure after the outcome insert and verifies that the transaction also rolls that insert back.
- Run the actual new entry and message capture services over synthetic native columns, the actual frozen 1055-entry map and the actual new frame table. The private Reflection seam does **not** test successful real HTTP authentication. MySQL returns normalized JSON; canonical reconstruction must preserve the stored digest and string IDs. Duplicate capture must not overwrite the frame. Native scope/byte-size/partial-entry constraints and nullable-entry uniqueness are checked.
- Run two independent overlapping PDO connections for the tick lease and the same profile/action intent. Only one tick and one committed lookup intent may win. These are real MySQL row-lock tests, unlike the separate sequential SQLite suites.
- Preserve a synthetic old-v1 sentinel and all original dependency bytes. No old settings or sender is invoked.

`fixture_activation=true` in the result refers only to the disposable database. `production_activation=false`, `deployment=false`, `provider_calls=0` and `live_database_connections=0` remain explicit. The fixture cannot establish runtime installation, actual webhook authentication, live source availability or provider delivery.

## Status

Prepared locally for independent source review and the original native source CI run. PHP/MySQL execution is not claimed until that run produces its own exact source/run/job/artifact receipt. Accepted migration 112, migration 113 and existing source/test files are unchanged by this package.
