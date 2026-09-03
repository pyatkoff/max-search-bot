# MAX Search Bot — Autonomous Development Contract

Read `START_HERE.md` first for the current handoff/checkpoint. This file owns the stable operating contract for autonomous work in this repository.

## Scope

Work only inside `pyatkoff/max-search-bot` and its production checkout. Neighbouring AnyTour projects, domains, folders and repositories are separate systems and are out of scope unless the user explicitly authorizes that exact project.

Never change as incidental cleanup:
- Yandex Metrica counters, goals or goal semantics;
- the existing lead-sending destination/mechanism;
- operator-controlled manager `is_working` state;
- routing bonuses/eligibility merely to make diagnostics green;
- production secrets or runtime config values.

## Product priority

Use this order when choosing work:
1. production broken or deploy unhealthy;
2. loss of leads, sales or manager handoff;
3. incorrect user/business data;
4. dialogue, search, manager-workspace or responsive UX friction;
5. roadmap product work;
6. performance/architecture cleanup with measured evidence;
7. cosmetic refactor.

UX is a primary product concern, not optional polish.

## Evidence first

Start each autopilot pass from current production evidence, not commit history:
1. production/deploy health;
2. fresh live conversations and actual customer messages/replies;
3. manager handoff/response/push health;
4. recent anomalies and conversion funnel;
5. only then roadmap/refactor work if no higher-priority defect is confirmed.

Do not invent a production defect from a metric alone. Inspect message-level evidence before changing dialogue behavior.

## Change rule

Prefer the smallest behavior-preserving or evidence-backed slice. For a confirmed defect:
1. capture the exact live phrase/state/evidence;
2. add the smallest useful regression;
3. fix the canonical owner rather than adding another parallel special case;
4. run required checks;
5. open a PR;
6. merge only after required CI is green;
7. verify production deploy, migrations, smoke and diagnostics;
8. update roadmap/status with what is actually verified.

Do not claim live confirmation until a natural post-deploy case exists.

## Canonical architecture direction

Dialogue value flow should converge toward:
`NeedValueResolver → NeedApplicationService → NeedProgressionService / DialogueView`.

AI may interpret free text but must not become a parallel state owner. Preserve edit-flow semantics while removing direct mutations incrementally.

Manager Workspace V2 is the forward manager surface. Keep responsibilities separated into Inbox, Conversation, Lead Card, Pipeline, Media, Tasks and Notifications. Do not rebuild the legacy panel unless required for a confirmed production issue.

See `docs/ARCHITECTURE.md` for the detailed dependency contract.

## Handoff policy

Product policy is fixed unless explicitly changed by the user:
- working window: 10:00–20:00 Europe/Kaliningrad;
- during working hours, manager handoff must not be blocked by mandatory phone capture;
- after 5 minutes without a manager reply, offer phone capture once through the existing fallback;
- outside working hours, prefer self-service tours/site plus optional phone with a truthful next-working-period expectation.

Presentation of SLA/urgency must not silently mutate routing or manager shift state.

## Required verification

Before merge: `bash tests/run_required_checks.sh` must pass in required CI.

After merge: production deploy must pass verify, SSH/sync, migrations, smoke, diagnostics and deploy telemetry. Treat diagnostic-transfer failure as deployment-health failure, not a warning.

Production schema changes are migrations only. Applied migrations are immutable; repairs are forward-only.

## Visual QA

For user-facing website/search and Manager Workspace V2 changes, visually inspect relevant desktop and mobile widths whenever tooling/artifacts permit. Responsive regressions outrank cosmetic cleanup.

Target baseline viewports for automated visual evidence: 390, 430, 768 and 1440 CSS px.

## Documentation ownership

- `START_HERE.md` — first-read current handoff, verified checkpoint and next safe sequence;
- `AGENTS.md` — stable autonomous operating rules;
- `docs/PRODUCT.md` — durable product decisions and success criteria;
- `docs/ARCHITECTURE.md` — code ownership/dependency direction;
- `docs/AUTOPILOT.md` — execution loop and evidence gates;
- `docs/OPERATIONS.md` — production/deploy/diagnostic runbook;
- `docs/REPO_MAP.md` — fast repository map and hard scope boundaries;
- issue #55 — current roadmap/checkpoints, not a permanent knowledge dump.

When rules conflict, production safety and explicit current user instructions win.
