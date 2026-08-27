# Repository Map

Fast orientation for autonomous work. This is a map, not a replacement for `docs/ARCHITECTURE.md`.

## In scope

### Entry points / legacy core
- `webhook.php` — production webhook entry;
- `maxsearchclass.php`, `maxsearchbaseclass.php` — legacy search/business state still being reduced incrementally;
- `cron_*.php` — scheduled follow-up/analytics jobs.

### Dialogue
- `handlers/` — normalized update/message/callback handling;
- `actions/`, `actions/callbacks/` — user/business actions;
- `services/` — focused application/domain/persistence services;
- `ai/` — AI client/router boundaries;
- `contracts/`, `integrations/` — transport/provider contracts and adapters.

Canonical direction for recognized needs:
`NeedValueResolver → NeedApplicationService → NeedProgressionService / DialogueView`.

### Manager / sales
- `manager/` — legacy manager panel plus Workspace V2 endpoints/UI;
- `manager/assets/workspace-v2-*` — V2 Inbox, Conversation, Lead Card, Pipeline, Media, Tasks, Notifications modules;
- `services/Manager*` — manager auth/read/conversation/routing/delivery/health services;
- `services/SalesPipelineService.php`, `LeadTaskService.php` — sales state/tasks, separate from technical conversation state.

Workspace V2 is the forward path; legacy `manager/index.php` is compatibility-only unless a confirmed production defect requires it.

### Persistence
- `migrations/` — versioned production schema changes;
- `services/ConversationDb.php` and repositories/services — database access.

### Diagnostics / operations
- `tools/production_snapshot.php` — canonical production JSON snapshot;
- `tools/live_session_snapshot.php` — bounded recent live-session evidence;
- `tools/conversation_db.php` — conversation inspection CLI;
- `tools/export_handoff_snapshot.php` — handoff evidence;
- `.github/workflows/deploy.yml` — production deployment;
- `.github/workflows/publish-conversation-diagnostics.yml` — production diagnostics publishing;
- `.github/workflows/live-session-diagnostics.yml` — fresh live-session artifact publishing.

### Tests
- `tests/run_required_checks.sh` — required suite orchestration;
- `tests/live_regressions/`, `tests/conversations/`, `tests/fixtures/` — scenario evidence and fixtures;
- focused `tests/run_*_regression.php` — behavior/architecture contracts.

## Durable docs

- `/AGENTS.md` — first-read autonomous rules;
- `docs/PRODUCT.md` — durable product policy;
- `docs/ARCHITECTURE.md` — dependency/ownership contract;
- `docs/AUTOPILOT.md` — execution loop;
- `docs/OPERATIONS.md` — deploy/diagnostic runbook;
- `docs/REFACTOR_ROADMAP.md` — architectural refactor direction;
- issue #55 — current live roadmap/status only.

## Explicitly out of scope

This repository does **not** grant permission to edit neighbouring projects or server folders. In particular, `anytour.com`, `anytour.online` website/search projects outside this repository, `poisk-turov-test`, and other MAX/TG project folders are separate projects unless the user explicitly authorizes that exact repository/folder for the current task.

Do not infer permission from a shared domain, hosting account, parent directory or brand name.

## Protected product/integration boundaries

Do not change incidentally:
- Yandex Metrica counters/goals/goal semantics;
- existing lead-sending mechanism/destination;
- operator-controlled manager shift state;
- production secrets/runtime config;
- neighbouring project files/config/deploys.