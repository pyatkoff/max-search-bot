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
- working window: 11:00–21:00 Europe/Moscow;
- during working hours, manager handoff must not be blocked by mandatory phone capture;
- after 5 minutes without a manager reply, offer phone capture once through the existing fallback;
- outside working hours, prefer self-service tours/site plus optional phone with a truthful next-working-period expectation.

Presentation of SLA/urgency must not silently mutate routing or manager shift state.

## Required verification

Before merge: the required `regression` and `guard` CI checks must pass. PR CI selects the audited OAuth or tour-selection component scope described in `docs/CI_SCOPES.md`; functional selection changes keep the full backend/MySQL suite. The exact styling-only subprofile described there retains inventory, syntax, architecture and complete component browser/package proof, without repeating unchanged backend/MySQL groups. Modifications confined to the five audited selection PHP CLI regression entrypoints retain full backend/MySQL coverage and omit browser jobs only with successful scope selection and all three declared skips in `screenshots`. Mixed, shared, unknown, migration, CI-rule and manual changes retain full coverage. `bash tests/run_required_checks.sh` remains the canonical full local and deployment check.

Before moving `main`, preserve the bounded priority of a frozen ready product release using [the release-priority contract](docs/RELEASE_PRIORITY.md). The existing required `regression` job checks this priority after its source checks. Repeat only its read-only admission immediately before the existing publisher lease and expected-head merge; CI is a snapshot, not a lease. A source-complete product may reserve at most 30 minutes in its existing PR description while its necessary final checks finish. A later cleanup must not advance the base during that window. Changed head/base, expiry, draft or closure invalidate the reservation; no automatic renewal. A confirmed incident keeps the documented safety priority without bypassing any required check or active publisher. A priority-only refusal reuses successful unchanged-source checks and reruns only the failed aggregate job once the blocker actually clears; never repeat a full workflow just to refresh release state.

After merge: production deploy must pass verify, SSH/sync, migrations, smoke, diagnostics and deploy telemetry. Treat diagnostic-transfer failure as deployment-health failure, not a warning.

Close an admitted shared publisher automatically in the existing post-deploy tail using the [release-slot binding](docs/RELEASE_PRIORITY.md#automatic-terminal-update). Keep one existing issue #1122 record and its owner; bind that record in the existing PR before merge. Only the original exact-source deployment and all mandatory technical gates can release it. Never dispatch checks or create another record for closure. Failure, ambiguity, changed source/owner, API refusal or unknown write outcome retain the slot for its current owner; do not repeat the mutation. Separate natural monitoring or product acceptance must not retain a technically completed shared slot.

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


## Owner correction — 2026-09-29

Every explicit manager request (button or recognized text), and a non-hotel manager-first source, enters `waiting_manager` at any hour, before attempting a customer confirmation. Night hours affect response expectations only; phone is optional. Already waiting/manager-owned conversations keep ownership and do not receive duplicate queue events or push. Operator shifts and routing eligibility remain unchanged. This supersedes the older outside-hours self-service-only policy above.


## Owner correction — quiet manager waiting (2026-09-29)

After the initial manager-entry greeting, ordinary customer messages go to the existing manager/queue and trigger manager notification without a bot reply. This includes legacy phone-step conversations and typed phone numbers. Automatic five-minute phone offers are disabled; no scheduled bot followup should ask for a number while a manager is awaited. Explicit contact/manual-phone actions remain customer initiated. This supersedes the older automatic phone fallback policy.


## Owner correction — one greeting per handoff (2026-09-29)

A manager-source entry sends the initial concise greeting only when it creates a new handoff. Reopening a source link while already waiting or assigned is silent, including after a manager has replied. Preserve the genuine `bot_started` recovery event and entry attribution without changing ownership, queue or transcript. This supersedes the earlier acknowledgement on every source reentry. Night entry retains truthful working-hours wording.


## Owner correction — hotel-channel needs first and Moscow time (2026-11-04)

Hotel-channel and feed entries first establish the missing trip needs through the
existing dialogue flow. They do not, by themselves, promise a manager or enter
`waiting_manager`. Use the related publication as context, preserve the tourist's
explicit conditions and ask only useful missing questions. A direct request for a
manager still uses the existing immediate handoff; a waiting or assigned dialogue
keeps its owner and quiet reentry behavior. When enough conditions are available,
the qualifying flow uses the canonical manager handoff before confirming it.

Manager-facing dates, task calendar days and user-visible event times use
`Europe/Moscow`. Project a validated server recording receipt as an explicit instant
when available; historical bare DATETIME values have mixed bases and must not be
bulk-shifted or silently declared UTC. Preserve stored values and the original
physical working interval, now expressed as 11:00–21:00 Moscow time. This does not
change operator shifts, runtime server clocks or local travel departure/arrival times.
