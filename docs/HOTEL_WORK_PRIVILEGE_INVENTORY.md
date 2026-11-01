# Diagnostic coverage of existing hotel migration privileges

This packet reads one already-established application PDO. It does not repair
the helper, activate a role, select a maintenance credential, authenticate a
different account, perform DDL/DML, change a global/session role setting,
retire a queue, release HOTEL or start any worker.

The only entry is the internal library:
    HotelWorkPrivilegeInventory::read($existingApplicationPdo, $configuredDatabase)

The trusted caller must supply the exact existing ConversationDb application
connection and its configured conversation database, inside a separately
approved private read-only server context. The library creates no PDO and adds
no CLI, MCP tool, HTTP endpoint, workflow or remote execution route. This source
packet is not permission to reuse the previously declined audit publication or
to bypass a route's access controls. Root source review and a concrete supported
read-only route must precede one production read; otherwise report that exact
route limitation. Mac and Windows checking chats remain out of scope.

## Private reads and public result

The reader rejects a foreign transaction, starts its own READ ONLY transaction
and rolls it back. It binds DATABASE() to the trusted expected database and
compares the private server/account/session identity again after the read.
Only its hash is returned; account, role, database and host names are not emitted.
Flavor and normalized core version are reported separately.

Queries read only:
- private identity/version and current binlog/trust flags;
- the current account's USER_PRIVILEGES and SCHEMA_PRIVILEGES;
- its TABLE_PRIVILEGES/TRIGGER for the 13 fixed migration083 target tables;
- self SHOW GRANTS, reduced privately to SUPER/TRIGGER at exact scopes;
- version-supported current, enabled and applicable/default/mandatory role metadata;
- MySQL SHOW GRANTS FOR CURRENT_USER USING only verified currently enabled roles;
- MariaDB SHOW GRANTS FOR only verified enabled roles, including inherited roles.

There is no mysql.* authentication-table access or other-account enumeration.
Legacy self grant text can contain authentication hashes: it stays private and
never appears in returned data, exceptions or logs. The reducer is deliberately
not a general GRANT parser: only two privilege types and exact known scopes are
recognized. REVOKE restrictions, ambiguous relevant patterns, malformed data,
denied/unsupported reads, excessive results or incomplete inheritance produce
UNKNOWN, not a false denial or an allow. Quoted role identifiers cannot be
mistaken for privilege statements.

Budgets are 32 metadata statements, 16 role rows, 64 self/expanded grant rows,
16 KiB per returned scalar and 128 KiB total private result text. Each privilege
query is bound to the current account/database and the fixed083 target names.

## Meaning of authority

null is UNKNOWN. Direct observed grants, currently effective coverage and role
assignment/default/mandatory counts are separate. Assigned/default/mandatory
roles are never activated or treated as effective merely because they exist.
If mandatory-role metadata cannot distinguish explicit direct assignment, the
explicit assigned count remains UNKNOWN. Such assignment-count uncertainty
does not turn an independently complete active-role expansion into a denial.

Supported models are MySQL5.7 (native roles not applicable), MySQL8.0/8.4,
and native MariaDB10.x–12.x. MySQL metadata tables introduced in8.0.19 may be
unavailable on earlier8.0 releases; that stays UNKNOWN. MariaDB uses its native
ENABLED_ROLES inheritance and plain self grants (including PUBLIC on10.11+);
MySQL USING is never sent to MariaDB. Other flavors/versions are identified
where possible but effective authority remains UNKNOWN.

The aggregate asks whether TRIGGER covers all13 targets and whether the
observed binlog creation condition is satisfied by the currently effective
SUPER or the already configured logging/trust condition. It is not a full DDL
admission: CREATE/SELECT/other requirements, current migration state and the
actual previous1419 are not overridden. Every result hard-codes zero role
activation, migration/retirement attempts and false DDL/new-start admission.

Foreign transaction, target mismatch, changed identity or changed current
role never yields positive effective authority. A failing transaction bootstrap
must not be reused as an ordinary application transaction.

## Verification and references

The new checks are included in the existing retirement-operations regression,
including its already existing native MySQL fixture invocation. No CI selection,
required-gate or workflow files change. Transcript tests cover table-level
coverage, active versus assigned/default/mandatory roles, MySQL/MariaDB branches,
partial restrictions and ambiguous scopes, secret/error redaction, limits,
foreign transactions, and session/role drift. Native coverage performs read-only
queries on an isolated allowlisted fixture database; it is not production proof.

Primary contracts:
- https://dev.mysql.com/doc/refman/8.0/en/show-grants.html
- https://dev.mysql.com/doc/refman/8.0/en/information-schema-applicable-roles-table.html
- https://dev.mysql.com/doc/refman/8.0/en/information-schema-enabled-roles-table.html
- https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/show/show-grants
- https://mariadb.com/docs/server/reference/system-tables/information-schema/information-schema-tables/information-schema-enabled_roles-table

The old base,19accepted batches, CENTRAL41 UNKNOWN, original receipts, migration083
and all existing write barriers are unchanged by this packet. Production remains
HOLD until the independently reviewed read and actual authority support any
later separately admitted canonical083 and retirement.
