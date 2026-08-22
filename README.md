# MAX Search Bot

Production bot for tour search in MAX with AI-assisted parameter collection, classic button flow, Yandex traffic attribution, lead capture, follow-ups and funnel diagnostics.

## Current architecture

`webhook.php` is intentionally kept as a thin entry point. The main logic is split into focused handlers and services:

- `handlers/MaxUpdateHandler.php` — MAX transport/update routing (`bot_started`, messages, callbacks, contacts).
- `handlers/AiMessageHandler.php` — AI conversational search flow and parameter application.
- `handlers/StateMessageHandler.php` — classic text-state handling: city, country, child ages, nights, phone.
- `handlers/CallbackHandler.php` — callback/button routing.
- `handlers/AiDateHandler.php` — date-related coordination and pending-month handling.
- `services/DateParser.php` — parsing human date phrases such as beginning/middle/end of month and decade-style phrases.
- `services/PendingMonthStore.php` — temporary month context between messages.
- `ai/AiRouter.php` / `ai/AiClient.php` — AI routing/client layer.
- `maxsearchclass.php` / `maxsearchbaseclass.php` — search state, UI, lead and business logic.

## Main flow

1. MAX sends an update to `webhook.php`.
2. `MaxUpdateHandler` normalizes the MAX payload and routes it.
3. Text search goes either to `AiMessageHandler` or `StateMessageHandler` depending on current status.
4. Button callbacks go to `CallbackHandler`.
5. Search parameters are stored in the existing status/HL structure and the user is sent to tour results, manager flow or channel offer.

## Traffic attribution

The bot supports Yandex payloads containing `yclid`, region and campaign. Current supported formats include the main `{yclid}_region_{region_id}_campaign_{campaign_id}` form and older compatibility formats. Traffic metadata is saved before the search flow starts.

## Funnel events

Typical funnel events include:

- `bot_started`
- `ai_start`
- `ai_text`
- `start_search`
- `country_selected`
- `tourists_selected`
- `search_ready`
- `show_tours`
- `site_open`
- `manager_request`
- `phone_received`
- `followup_sent`
- `channel_click`

Runtime funnel/log files are not source code and should not be committed to `main`.

## Phone / manager flow

The bot supports both a MAX contact attachment and manual phone entry. After a successful lead save, the `max_phone` goal is queued/sent through the existing Metrika flow. Manager-request and site-open goals are handled separately.

## Follow-up

`cron_followup.php` handles delayed follow-up after tour results where applicable. The production cron should call it on the configured schedule; runtime follow-up state is excluded from Git.

## Production update

Production path currently used on the server:

```bash
cd /var/www/545v0023442/data/www/anytour.online/max-search
git pull --rebase origin main
```

Before pushing production edits, run PHP syntax checks for every changed PHP file, for example:

```bash
php -l webhook.php
php -l handlers/AiMessageHandler.php
```

Do not commit `config.php`, runtime data, diagnostics exports or local `*.before_*` backups.

## Diagnostics

Production diagnostics are published separately to the `diagnostics` branch. They are intended for debugging and should not be copied into `main` as a local `diagnostics/` directory.

Useful runtime sources include AI debug logs and funnel output. When changing conversational logic, verify the actual user flow in diagnostics before committing.

## Safe change procedure

For larger refactors:

1. make a local backup or rely on Git history;
2. change one logical block at a time;
3. run `php -l` on every touched PHP file;
4. test the live scenario;
5. inspect diagnostics;
6. commit and push only the verified source files.

The repository history is the primary rollback mechanism; temporary `.before_*` files are intentionally ignored.
