# AI model profile

Owner decision, 19 October 2026: use GPT-6.1 Sol throughout this application,
including MAX Search. The selected profile is `gpt-6.1-sol`, `low` reasoning,
Standard (`service_tier: default`), matching the comparison. No instruction,
state policy, routing or automatic-send behavior changes with this rollout.

Runtime caller inventory:

- MAX, Telegram and website: `AiRouter` and `TouristExtractorV2` use `AiClient`.
- Explicit manager reply draft: `ManagerAiDraftService` uses `ManagerDraftClient`.
- These are the only OpenAI HTTP transports in this repository. External research
  tools, archived comparisons and neighbouring projects are not runtime callers.

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
`ai-profile-before-sol-61.runtime.php` / `.external.php` backups beside external
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

Rollback requires reverting the source/profile rollout through a green PR and
the normal exact-main deploy. If runtime configuration must also be restored,
restore only the four previous profile values from the private backup after
checking current config drift; do not copy an old whole configuration over newer
secrets or unrelated settings. Remove or update the rollout in that same revert
so a later deploy does not reapply it. No database rollback is involved.

Offline instruction candidates and corpus-review claims remain independent.
Historical Luna outputs, sealed ledgers and A/B labels are not rewritten. Future
evaluations default to Sol unless a new bounded comparison explicitly names a
different model; this decision does not start a paid evaluation or change a
schedule.
