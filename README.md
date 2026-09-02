# MAX Search Bot

Production bot for tour search in MAX with AI-assisted parameter collection, classic button flow, Yandex traffic attribution, lead capture, follow-ups, Tourvisor route advice and funnel diagnostics.

## Current architecture

`webhook.php` is intentionally kept as a thin entry point. The main logic is split into focused handlers and services:

- `handlers/MaxUpdateHandler.php` — MAX transport/update routing (`bot_started`, messages, callbacks, contacts).
- `handlers/AiMessageHandler.php` — AI conversational search flow and parameter application.
- `handlers/AiShortAnswerHandler.php` — deterministic handling of short follow-up answers.
- `handlers/DepartureRouteAdviceHandler.php` — Tourvisor-backed route/recommendation UX.
- `handlers/StateMessageHandler.php` — classic text-state handling: city, country, child ages, nights, phone.
- `handlers/CallbackHandler.php` — callback/button routing.
- `handlers/AiDateHandler.php` — date-related coordination and pending-month handling.
- `services/DateParser.php` — human date parsing.
- `services/DepartureCityResolver.php` — explicit departure extraction/storage.
- `services/DestinationAreaResolver.php` / `DestinationResolver.php` — destination resolution.
- `services/DestinationPreferenceResolver.php` — preference filtering such as sea/warm over already confirmed routes.
- `services/PendingMonthStore.php` — temporary month context between messages.
- `services/ProjectHealth.php` — production health snapshot for diagnostics.
- `DepartureRouteResolver.php` / `DepartureRouteAdvisor.php` — local Tourvisor route cache resolution and fallback airports.
- `ai/AiRouter.php` / `ai/AiClient.php` — AI routing/client layer.
- `maxsearchclass.php` / `maxsearchbaseclass.php` — existing search state, UI, lead and business logic.

## Main flow

1. MAX sends an update to `webhook.php`.
2. `MaxUpdateHandler` normalizes the payload and routes it.
3. Deterministic resolvers first preserve explicit departure/destination facts.
4. `DepartureRouteAdviceHandler` can answer route/discovery requests from the local Tourvisor cache.
5. Remaining text goes to `AiShortAnswerHandler`, `AiMessageHandler` or classic `StateMessageHandler` depending on state.
6. Search parameters are stored in the existing status/HL structure and the user is sent to results, manager flow or channel offer.

## Configuration

Real secrets and environment-specific values live in `config.php`, which is ignored by Git. `config.example.php` documents the safe non-secret contract. When source code introduces a new config name, update `config.example.php` in the same change without copying the real value.

## Tourvisor route cache

`tourvisor_routes.json` is generated runtime data and is not committed to `main`. Production recommendation logic reads this local cache only; it does not call Tourvisor in the user request path.

Diagnostics expose cache age, hash, departure/route/date counts and validity through `ProjectHealth`, so stale route data can be detected without opening the server manually.

## Traffic attribution

The bot supports Yandex payloads containing `yclid`, region and campaign. Traffic metadata is saved before the search flow starts. Runtime attribution data is not committed to Git.

## Tests

Two deterministic suites live under `tests/`:

```bash
php tests/run_regression.php
php tests/run_conversation_regression.php
```

GitHub Actions runs syntax checks and both regression suites with PHP 8.2. Production diagnostics also run the conversation regression using the newest compatible PHP binary discovered on the server.

Every fixed conversational bug should ideally become a regression case so the same failure cannot silently return.

## Automated production deploy

Production checkout:

```text
/var/www/anytoour/data/www/app.anytoour.ru
```

`.github/workflows/deploy.yml` connects to production over a dedicated SSH key after pushes to `main`, updates the checkout, performs a syntax check, runs conversation regression and refreshes diagnostics.

Normal source changes should therefore not require a manual `git pull` on the server.

Important: the target architecture is **test first, deploy second**. Until the deploy workflow is fully gated by successful CI, keep changes small and rely on regression + diagnostics immediately after each push.

## Diagnostics

Production diagnostics are published separately to the `diagnostics` branch. `main` contains source only; generated diagnostic files remain ignored.

The diagnostics manifest includes:

- current production Git commit and branch;
- server PHP binary/version and discovered PHP binaries;
- configuration contract names without secret values;
- Tourvisor cache freshness and counts;
- runtime log freshness/writability;
- deterministic conversation regression result;
- recent funnel, AI, follow-up and Metrika logs.

This is the primary way to verify production after a change without asking a user to send test messages to the live bot.

## Runtime files

Do not commit `config.php`, logs, funnel exports, traffic/followup state, generated Tourvisor cache, diagnostics snapshots or emergency `.before_*` backups. See `.gitignore` for the canonical list.

## Safe change procedure

For normal changes:

1. change one logical block at a time;
2. add/update deterministic regression coverage;
3. push to `main`;
4. automated deploy updates production;
5. inspect `diagnostics` for production commit, regression and runtime errors;
6. only then continue with the next logical block.

For larger refactors, split work into smaller commits and preserve behavior before moving business logic out of legacy classes.

## Refactor direction

`maxsearchbaseclass.php` remains the largest legacy component. New work should avoid adding unrelated responsibilities there. Prefer focused services for traffic attribution, Metrika, lead handling, follow-ups, search context and Tourvisor logic. Move existing behavior gradually and keep regression coverage around each extraction.
