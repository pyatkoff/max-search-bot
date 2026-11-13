# AnyTour Catalog, Price & SEO Data Foundation

Updated: 2026-08-30

## Goal

Build one first-party data layer that supports four product areas without replacing Tourvisor as the live source of availability and final price:

1. hotel-first search and fast hotel autocomplete;
2. passive and scheduled price history / price intelligence;
3. a real `/hot/` inventory snapshot;
4. SEO pages with fast, server-rendered offer snapshots, including month pages.

The live Tourvisor search, selected-tour refresh, flights, analytics and lead contracts stay unchanged.

## Architecture

```text
Browser
  -> AnyTour application
       -> AnyTour data DB
            - countries / regions / subregions / hotels
            - hotel aliases + search index
            - price observations + daily aggregates
            - current hot tours
            - SEO offer snapshots
       -> Tourvisor live APIs
            - final search / availability
            - selected tour / flights / rooms
```

Tourvisor remains the source of truth for live tour availability and current bookable price. The local DB is a catalog, cache, history and SEO delivery layer.

## Database contract

Initial schema lives at `v2/data/schema-v1.sql`.

### Catalog

- `catalog_countries`
- `catalog_regions`
- `catalog_subregions`
- `catalog_hotels`
- `hotel_aliases`
- `catalog_sync_state`

Hotel IDs are the Tourvisor hotel IDs already accepted by the existing `hotelIds` parameter in `api-v2.php` search requests.

### Price history

- `tour_price_observations`: raw observations captured from real search results and optional scheduled monitor searches.
- `tour_price_daily`: compact daily aggregates for long-term analytics.

Do not compare unrelated products. Price intelligence segmentation must include, at minimum, departure, destination/hotel, departure period, nights, adults/children and meal where available.

### Hot tours

- `hot_tours_current`: replaceable snapshot of Tourvisor hot-tour output. It is a cache for fast rendering, not a booking source of truth.

### SEO offers

- `seo_offer_snapshots`: precomputed blocks of real offers/minimum prices for indexable editorial pages. Page rendering must not launch a fresh Tourvisor search.

## Hotel-first search

### UX target

The search form gets an optional hotel field and supports two intents without duplicating the core search engine:

- destination-first: country/region -> dates -> nights -> travellers -> filters;
- hotel-first: hotel -> dates -> nights -> travellers -> find price.

Autocomplete reads the local catalog. Selecting a hotel stores its Tourvisor `hotel_id`; the existing live search sends that ID via `hotelIds`.

### Search behavior

`v2/data/hotel-search-v1.php` provides the first local API contract:

```text
GET /data/hotel-search-v1.php?q=rixos&countryId=4&regionId=12&limit=10
```

Response items contain `id`, `name`, country/region/subregion, category and rating. Manual aliases are supported so Russian spelling, transliteration and known brand variants can resolve to the same Tourvisor hotel ID.

## Catalog synchronization

The Tourvisor hotel catalog is pageable and returns stable IDs plus country, region, subregion, category, rating, type and coordinates. Catalog calls should populate/update local rows, not create a second identity system.

Recommended cadence:

- countries/regions/subregions: daily;
- hotels in high-demand countries: daily;
- long-tail countries: every 3-7 days;
- mark missing hotels inactive only after a conservative grace period; never delete on one failed sync.

Catalog sync is independent from the live search request limit and must have logging plus per-country sync state.

## Price collection

### Passive collection first

Every successful real user search already produces hotel/tour prices. A collector should persist the returned tour rows asynchronously/non-blockingly after successful rendering/data retrieval. User search must not fail because history persistence failed.

Suggested observation dimensions:

- observed_at
- source (`user_search`, `scheduled_monitor`, `hot_tours`)
- search_id where available
- departure_id
- country_id / region_id / subregion_id / hotel_id
- tour_id
- departure_date
- nights
- adults / children count and child-age signature
- meal_id
- room_id / room type
- operator_id
- price / currency
- fuel_charge where available

### Scheduled monitoring second

Do not launch one search per hotel. Use broad destination/date-window searches that return many hotels, with a strict daily request budget. Start only after passive collection is stable and measured.

## Price intelligence rules

Never show a historical claim with insufficient comparable data.

Suggested confidence gates:

- fewer than 5 comparable observations: no badge;
- 5-14: simple `Хорошая цена` only when current price is materially below median;
- 15+: show percentage/ruble delta to a recent median;
- 30+ across enough dates: allow 30-day chart/minimum language.

Prefer median and percentiles over arithmetic mean. Exclude stale/invalid currency mixes and materially different traveller/nights segments.

## Current-date low-price calendar

This can ship before historical analytics. A normal Tourvisor search can span up to 21 departure days. Group current returned tours by departure date and expose the minimum comparable price per date.

Example UX:

```text
8 Sep   128 400 ₽
9 Sep   121 900 ₽
10 Sep  116 700 ₽  best
11 Sep  119 300 ₽
```

This is current inventory intelligence, not a historical claim.

## Hot tours

Tourvisor exposes a dedicated hot-tours endpoint with up to 200 offers per request. Snapshot it into `hot_tours_current`, then render `/hot/` from the local snapshot for speed.

Refresh target: every 30-60 minutes, with `fetched_at` visible in UX when useful. Clicking an offer must still refresh/validate the selected tour through the existing live flow before any purchase decision.

## SEO page model

### Required page types

1. country: `/country/turkey/`
2. resort: `/country/turkey/kemer/`
3. month: `/country/turkey/september/`
4. resort + month: `/country/turkey/kemer/september/`
5. curated intent pages, e.g. all-inclusive / family / 5-star, only where there is real value and inventory
6. hotel page: `/hotel/rixos-premium-tekirova/` after hotel-page content/data contracts are ready

Month pages are a first-class SEO dimension, not merely query-string filters.

### Month/year handling

Human-facing evergreen month routes can represent the next relevant season, but the data layer must store explicit `departure_year` + `departure_month` (or `month_start`) so prices are never mixed across years. Canonical/indexing policy must avoid two URLs competing for the same intent.

### Publishability

Do not generate every mathematical filter combination. An SEO page becomes indexable only when it has:

- an approved stable route/template;
- useful unique editorial content;
- sufficient current offer coverage or a valid seasonal state;
- internal links from the controlled page graph;
- no duplicate/canonical conflict.

### Snapshot rendering

`seo_offer_snapshots` stores a compact JSON payload plus min price/count/freshness for a specific page key. Pages render immediately from this snapshot; CTA hands the user to live `/poisk-turov/` with explicit search parameters.

## Delivery order

1. Keep AnyTour Design System 2.0/shared-shell work as the canonical user-facing design generation while the active technical/CI phase is executed.
2. Land DB schema and isolated data access helpers with no production behavior change.
3. Add catalog sync for countries/regions/subregions/hotels and validate against Tourvisor samples.
4. Populate a staging/local DB and measure hotel counts/search latency.
5. Add hotel autocomplete API and hotel-first form UX; preserve existing search lifecycle ownership.
6. Start passive price capture from successful search results.
7. Add current 21-day low-price calendar from live result data.
8. Add hot-tour snapshot ingestion and rebuild `/hot/` around real offers.
9. Add SEO offer snapshot builder, then country/resort/month blocks.
10. After enough history exists, ship guarded price-intelligence badges/charts.
11. Add broader persistence/price watch only after permission/contact/notification contracts are explicit.

## Production prerequisites

Runtime DB credentials are intentionally not committed. Production needs either `ANYTOUR_DATA_DSN` or `ANYTOUR_DATA_DB_HOST`, `ANYTOUR_DATA_DB_NAME`, `ANYTOUR_DATA_DB_USER`, `ANYTOUR_DATA_DB_PASSWORD` (optional port). Create a dedicated least-privilege DB user.

Applying `schema-v1.sql` is an explicit infrastructure step. Take a DB backup/snapshot first once a production database exists.

## Non-negotiable boundaries

- Do not change Yandex Metrika/goals.
- Do not change the existing lead-sending mechanism.
- Do not change the Tourvisor live-search external contract merely to collect history.
- History/cache failures must not break a user's live search or lead flow.
- Never advertise a cached price as final; refresh selected-tour price before decision/payment as already required by the product.
