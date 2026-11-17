# AI model profile

The 17 November 2026 owner decision selects `gpt-6.2-sol` with `medium`
reasoning and Standard processing (`service_tier: default`) for the customer bot
and manager reply drafts. The previous profile was `gpt-6.1-sol` with `low` reasoning.

Runtime caller inventory:

- MAX, Telegram and website: `AiRouter` and `TouristExtractorV2` use `AiClient`.
- Explicit manager reply draft: `ManagerAiDraftService` uses `ManagerDraftClient`.
- HOTEL, development and roadmap workers retain their approved `gpt-6.1-sol` / `low` / Standard profile, including the profile used to verify their saved requests.
- The private virtual reply author and critic use separate evaluation profiles.

The common bot uses `OPENAI_MODEL`; drafts independently use
`MANAGER_AI_DRAFT_MODEL`, `MANAGER_AI_DRAFT_REASONING` and
`MANAGER_AI_DRAFT_SERVICE_TIER`. Defaults and `config.example.php` select the same
profile. Existing secrets/config can override defaults, so defaults alone do not
establish a rollout.

Canonical production deploy invokes `tools/ai_profile_rollout.php --apply` with
the explicit `MAX_SEARCH_APPLY_AI_PROFILE=1` write guard, then `--verify` during
its required smoke stage. Apply is limited to the canonical checkout config wrapper and external
`/var/www/anytoour/data/config/max-search.php`. It changes only four profile
literals, preserves existing literal ownership and unrelated bytes, and adds
truly missing settings only to external config. This avoids duplicate definitions
when a legacy wrapper already owns a key. It rejects ambiguous/dynamic definitions,
stages/lints candidates outside the document root, retains owner-only
`ai-profile-before-sol-62-medium.runtime.php` / `.external.php` backups beside external
config, and installs each changed file atomically on the same filesystem. If the effective profile check fails immediately after an
install, it attempts to restore only its own unchanged candidate. Existing backup
or concurrent drift prevents another non-idempotent apply; investigate instead
of overwriting it. The backup remains outside the document root and is never
published. Logs contain only the model, reasoning and requested tier.

Verify loads the actual runtime config and builds both canonical outgoing request
bodies from synthetic text; it makes zero provider calls and sends no customer
messages. This verifies configuration, not provider availability, the actual
served tier, quality or natural post-deploy success. It never prints keys or
config contents. A model/profile-only change needs no UI screenshot.

Bot deadline remains 12 seconds/1200 output tokens; manager deadline remains
20 seconds/2200 output tokens. The bounded old webhook deadline is intentional;
switching models does not authorize extending it or adding retries.

To return to the previous profile, prepare a forward PR restoring the previous
client defaults, config targets, smoke and regression expectations. Give that
rollout a fresh backup basename, because the original 6.1 backup can already
exist. Use the normal verified deployment so only the four owned profile
values are reconciled and all other current settings are preserved.

Offline instruction candidates and corpus-review claims remain independent.
Historical Luna outputs, sealed ledgers and A/B labels are not rewritten. Future
evaluations default to Sol unless a new bounded comparison explicitly names a
different model; this decision does not start a paid evaluation or change a
schedule.
