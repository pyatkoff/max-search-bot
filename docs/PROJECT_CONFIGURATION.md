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

## Verification

php tests/run_project_marker_boundary_regression.php runs in the existing
required architecture group; its marker checks are retained and the settings
cases extend that same invocation. It exercises real legacy/default search behavior,
app/tracking overrides, alternating projects, wrong-scope rejection, returned
array mutations, shared PHP references, bounded cycles and typed lookup values.
It does not make network calls, open a database or mutate live configuration.
The normal required CI for the complete change remains authoritative.
