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

Run the canonical full local and deployment checks:

```bash
bash tests/run_required_checks.sh
```

Required PR CI selects coverage from the complete base/head diff using [the CI scope contract](docs/CI_SCOPES.md). Shared, mixed and unknown changes retain full coverage. Production pre-install verification retains the canonical full suite on PHP 8.3; green PR checks do not replace it. The focused dialogue runners remain available as `php tests/run_regression.php` and `php tests/run_conversation_regression.php`.

Every fixed conversational bug should ideally become a regression case so the same failure cannot silently return.

## Automated production deploy

Production checkout:

```text
/var/www/anytoour/data/www/app.anytoour.ru
```

After a checked PR is merged into `main`, `.github/workflows/deploy.yml` verifies the exact current source before connecting to production over a dedicated SSH key. An ordinary installation updates the checkout, applies migrations, runs smoke checks and publishes diagnostics. The existing post-deploy tail validates the mandatory technical results and automatically releases the bound publisher. See [operations](docs/OPERATIONS.md) and [release priority and ownership](docs/RELEASE_PRIORITY.md) for the complete gates and the bounded accepted-application path.

Normal source changes should therefore not require a manual `git pull` on the server.

The required `regression` and `guard` checks must pass before merge. Fresh priority admission and the sole publisher remain required; an old green run or a direct push is not the normal release procedure.

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
3. open a PR from a fresh current-main branch and pass its required current-head checks;
4. repeat fresh release-priority admission, acquire the sole publisher and bind its existing record before the expected-head merge;
5. verify the original installation, mandatory post-deploy results and automatic publisher release for the exact merged source;
6. record the result and remaining uncertainty, keeping natural user confirmation separate from technical completion.

For larger refactors, split work into smaller commits and preserve behavior before moving business logic out of legacy classes.

## Refactor direction

`maxsearchbaseclass.php` remains the largest legacy component. New work should avoid adding unrelated responsibilities there. Prefer focused services for traffic attribution, Metrika, lead handling, follow-ups, search context and Tourvisor logic. Move existing behavior gradually and keep regression coverage around each extraction.

### Manager2

`/manager2/` is the owner-authorized modern Manager presentation. It uses the same login/session, live clients, protected `/manager/` APIs and canonical workspace modules. The previous `/manager/` remains available. See [Manager2 presentation and verification](manager2/README.md).
