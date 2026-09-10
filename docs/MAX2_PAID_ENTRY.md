# Paid MAX entry → /new/max2/

Owner request, 2026-09-08: connect the existing MAX2 mechanism to MAX Search and
offer optional channel subscription on the first screen of advertising entries.
The owner explicitly selected `/new/max2/`.

## Integration

- MAX Search remains `id9704048781_1_bot`. Only `bot_started` passes its freshly
  parsed metadata through `MaxSearchApi::showStart` to `DialogueView::start`.
- The owner selected the two-action advertising screen on 2026-09-08: the exact
  MAX2 invitation, then `🔥 Подписаться на канал` and `🔎 Подобрать тур`. The
  channel button opens the configured `id9704048781_2_bot` Mini App via `startapp`.
- `search_options` shows the existing AI/wizard choices immediately, without a
  timer, subscription check, trip reset, state transition or message deletion.
  The canonical InteractionGuard accepts it only in start state, suppresses rapid
  repeats and permits immediate retry after failed delivery. Once AI/wizard/check/
  phone handling has begun, an old chooser button cannot interrupt it.
- Both advertising screens retain start-state free-text handling: a message goes
  directly into the existing AI pipeline without requiring either button. The
  existing AI/wizard action callbacks themselves are unchanged.
- The invitation is copied from `MaxTransport::sendStart` in the same upstream
  production.php at the receipt SHA below; no neighboring file is modified.
- That bot's Mini App is deployed at `https://tour-max.ru/new/max2/` according to
  the neighboring [PR #46](https://github.com/pyatkoff/tour-max/pull/46) execution
  receipt (`docs/MAX2_BOT_START_REPAIR_STATUS.md`, head
  `4670082c9a435b01426ff922740d8a9eb5506c76`). A plain website link would not be a
  replacement for MAX's signed Mini App launch context.
- `ChannelOfferService::startUrl` projects the three accepted numeric fields into
  the existing URL builder. MAX2 accepts YCLID 1–64 digits with a nonzero digit,
  region 1–20 digits with a nonzero digit, and campaign 1–20 digits. Search retains
  its existing six-digit YCLID minimum. Absent region/campaign use MAX2's explicit
  defaults 1/0; supplied invalid values suppress this optional offer.
- `entry_channel` stays in Search storage and the existing source suppression
  policy. It is not appended to MAX2's three-field payload, which rejects it.
- Ordinary restarts, organic entries, Telegram and missing Mini App configuration
  keep the original greeting. Old saved attribution cannot trigger this offer.
- A successfully delivered greeting records `channel_offer_start` only. No click
  or greeting is recorded as a subscription or Metrica conversion.

## Existing MAX2 ownership

The Mini App validates its own bot's signed user/start context, matches the
advertising data, confirms its existing save and opens the regional channel.
Actual membership monitoring is owned by a separate bot, per the owner and #46.
Neither MAX Search nor the new MAX2 start-only webhook should duplicate it.

This integration adds no Bitrix/HL9 writes, exporter, Metrica goal, callback
registration, credential/config mutation or copied MAX2 runtime. It changes no
lead delivery, shifts, routing, search URL or Tourvisor path. MAX2's client stays
unchanged and therefore keeps its existing size budget.

## Verification and rollback

The required DialogueView regression exercises fresh paid metadata, both known
incoming payload shapes, source-tagged input, exact MAX2 URL, organic/restart/TG
boundaries, invalid fields, missing configuration and failed message delivery.
It also exercises actual chooser dispatch, stale/duplicate callbacks, retry after
failed delivery, preservation of state/data/invitation, and actual controller entry
into the AI handler before any remote inference for text on either start screen.
Full required CI and the normal exact-SHA production gates are required.

CI/deployment evidence does not prove an actual MAX Mini App click, database match
or received advertising conversion. A natural new ad entry → channel button →
Mini App → subscription is still needed for that end-to-end claim. Neighboring
#46 likewise leaves its real new bot-start button click unconfirmed.

Rollback is a normal revert PR for the Search greeting integration. Do not revert
MAX2's webhook cutover, alter its registration or restore its retired handler.

## Channel offers after entry — owner clarification, 2026-09-10

The repeated pre-results MAX/Telegram channel choice belongs only to the website
online consultant. Native MAX and Telegram conversations must not receive that
promotion again or wait through its subscription delay. The initial paid MAX
screen above remains unchanged; this is not a subscription/membership check.
Post-tour and lead acknowledgements retain their confirmation and return-to-tours
action without repeating a channel invitation in native messengers.

Use the actual request-scoped IntegrationRegistry messenger for this boundary.
ProjectConfig may still say provider=max while Telegram and website webhooks use
their own adapters. Attribution tags do not identify the active transport. Website
source-level suppression and configured channel choices remain in force.
