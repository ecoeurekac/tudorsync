# tudorsync

Syncs authorized TUDOR retailers' online stock availability to `tudorwatch.com`'s
"Comprar ahora" program, across multiple clients running different e-commerce platforms.

See `doc/Primeros pasos del programa de comercio electrónico de TUDOR - TUDOR Official
Communication Centre.pdf` for TUDOR's own onboarding overview of the program this
integrates with.

## Clients

| Client               | Platform    |
|----------------------|-------------|
| Pedro Luis Olivares  | Magento     |
| Quera                | Magento     |
| Grau                 | PrestaShop  |
| Gordillo             | WooCommerce |
| Saphir               | WooCommerce |

## Architecture

Core + adapters, deployed **hybrid**: a shared, platform-agnostic PHP package (`core/`)
carries every TUDOR-facing rule and is installed as a Composer dependency inside three
thin platform modules, one per CMS. Each module runs **inside its own client's store** —
there is no central service. That means:

- credentials (TUDOR staging/production keys) are stored per store, in that platform's own
  admin/config screen — never in a shared vault;
- each store's own native scheduler triggers the sync (Magento cron, PrestaShop task
  scheduler, WP-Cron) — there is no unified custom scheduler;
- sales-tracking reporting is generated per client/store — there is no cross-client
  aggregation.

```
tudorsync/
  core/                       # Composer package: tudorsync/core (no platform dependencies)
    src/
      Domain/                 # StockAvailability, StorePickupAvailability, ClientConfig, Environment
      Contract/                # CatalogConnectorInterface — the seam every module implements
      Rules/                   # AvailabilityFilter — reviews every record before sending; Exclusion, ValidModelList
      Url/                     # UtmUrlBuilder, LocaleUrlResolver
      Api/                     # TudorApiClient, NdjsonCodec, BatchSyncResult, StockImportResult, PointOfSale, HttpClientInterface
      Sync/                    # SyncEngine — orchestrates one run; NothingPassedReviewException
      Reporting/               # MonthlyReportWriter, MonthlySalesReportRow
    resources/
      report-templates/       # TUDOR's real monthly-report .xlsx templates, one per language
      valid-models/           # TUDOR's price list per country (prices_ES.xlsx): current models
    tests/
  modules/
    magento/                  # Magento 2 module (Pedro Luis Olivares, Quera)
    prestashop/               # PrestaShop module (Grau)
    woocommerce/              # WordPress plugin (Gordillo, Saphir)
  doc/                        # TUDOR's own program documentation, API spec zips, report template zip
```

Each platform module implements `Tudorsync\Core\Contract\CatalogConnectorInterface`
(one `CatalogConnector` class) to translate that platform's own catalog/stock data into
`Tudorsync\Core\Domain\StockAvailability` — that's the only thing that differs per platform.
(Earlier versions of this README called it `AvailabilityItem`; that class never existed.)
Everything else (visibility rules, UTM injection, locale URL resolution, talking to
TUDOR's API, per-client reporting) lives once in `core/` and is shared by all five clients.

## TUDOR's e-Stock Retail Publish API (v1.7.0)

The real technical spec (`doc/stock-retail-publish-public-rest-api-1.7.0-*.zip`, OAS +
RAML — identical contract in both formats) defines:

| Method & path              | Purpose                                                        |
|-----------------------------|-----------------------------------------------------------------|
| `GET /v1/point-of-sales`    | This retailer's own active, non-virtual TUDOR points of sale.  |
| `POST /v1/stocks`           | Create a single stock record.                                  |
| `GET /v1/stocks`            | Paginated list of this retailer's currently published stocks.  |
| `POST /v1/stocks/batch`     | **Batch create/update via NDJSON** — the main sync call. Any model/country combination previously published but *missing* from the batch is automatically zeroed out by TUDOR. |
| `GET /health`               | Spring Boot Actuator health check.                              |

A `StockCreateDto` record — `Tudorsync\Core\Domain\StockAvailability` mirrors it field for
field — carries: `mc` (TUDOR model code), `country`, `value` (integer; see the class
docblock for the open question on what this actually represents, given the onboarding
document's promise that shoppers never see a quantity), `defaultUrl` + `localizedUrls`
(locale keys can carry a region, e.g. `fr-CH` vs `fr-FR`, not just a bare language code),
`onlinePurchaseEnabled` (the real gate for "Comprar ahora"), `storePickupAvailable`,
optional `homeDeliveryTiming`, and optional `storesAvailabilityDetails` (per-point-of-sale
click & collect detail keyed by the point of sale's TUDOR "RSWI" id, which is the `stoId`
returned by `/v1/point-of-sales`, e.g. `RSWI_185580`).

Neither OAS nor RAML declares base URLs or an auth scheme; both come from TUDOR's "API
Spotlight" page (`doc/Community Asset_ stock-retail-publish-public-rest-api*.zip`) and the
official Postman collection (`doc/eStock collections/`), and were verified against PREPROD on
2026-09-30:

| | PREPROD (`Environment::Staging`) | PROD (`Environment::Production`) |
|---|---|---|
| API base URL | `https://pp-api.services.mytudorwatch.com/estock-retail/retailer` | `https://api.services.mytudorwatch.com/estock-retail/retailer` |
| Token URL | `https://login.rolex.com/oauth2/aus3qkuvb8CliPktG417/v1/token` | `https://login.rolex.com/oauth2/aus3rz4418Eok4GHr417/v1/token` |

### Authentication (OAuth2 client credentials)

Each store gets an Okta application per environment (client ID + client secret), passed in
`ClientConfig::$clientId` / `$clientSecret`. `Api\Auth\AccessTokenProvider` exchanges them
for an access token:

- `POST` to the token URL with `Content-Type: application/x-www-form-urlencoded` (**not**
  JSON, although TUDOR's HTML docs show a JSON body) and the fields `grant_type=client_credentials`,
  `client_id`, `client_secret`, `scope=com.myrolex.api.estock.publish app_owner`.
- The response carries `access_token`, `token_type: Bearer` and `expires_in` (300 s today).
- The token is kept in memory and reused until 30 s before it expires
  (`AccessTokenProvider::EXPIRY_MARGIN_SECONDS`); a new `TudorApiClient` starts with no token,
  so in practice a sync run requests one. The clock is behind `Api\Auth\ClockInterface`
  (PSR-20 shape, no dependency) so tests can expire it.
- Every API call sends `Authorization: Bearer <access_token>`. On a 401 the token is dropped,
  a new one is requested and the call is retried **once**; a second 401 is an error.

Errors are `Api\Exception\TudorApiException` subclasses, whose messages never contain the
client secret or the token:

| Exception | When |
|---|---|
| `MissingCredentialsException` | Client ID or secret empty — raised before any request. |
| `CredentialsRejectedException` | Token endpoint answered 400/401/403 ("TUDOR rejected the client credentials"; `$oauthError` holds Okta's code, e.g. `invalid_client`). |
| `TokenRequestException` | Token endpoint failed otherwise (5xx, no `access_token`...). |
| `ApiResponseException` | An API call returned a non-2xx status (`$statusCode`, `$method`, `$path`). 207 on `/v1/stocks/batch` is a partial success, not an error: see `BatchSyncResult`. |

`ClientConfig::$tudorApiKey` is deprecated and ignored; it stays (default `''`) only so the
platform modules keep working unchanged until they pass the client ID/secret.

## TUDOR's monthly sales report

`doc/e-Com-e-Stock_program-template.zip` contains TUDOR's real Excel template in five
languages (DE/EN/ES/FR/IT), copied into `core/resources/report-templates/`. It's a single
sheet with one row per **month**, not per sale:

- Row 2: country name.
- Row 4: headers; rows 5-20 hold up to 16 monthly rows (an Excel Table already spans
  `A4:J20` in the file — extending past 16 months needs manual template editing).
- Columns: Retailer · Period · **Sessions\*** · **Unique visitors\*** · Added to cart ·
  **Total online sales\*** · of which Click & Collect · Boutique sales · Boutique
  appointments · Comments (`*` = mandatory per the template).

`MonthlyReportWriter` fills this template from `MonthlySalesReportRow` data. Note the data
sources are *not* the catalog connector: sessions/unique visitors/added-to-cart come from
web analytics (identifiable via the UTM parameters `UtmUrlBuilder` adds), online/click&collect
sales come from the store's own orders, and the template itself notes boutique
sales/appointments are based on in-store staff conversations "whenever possible" — likely
manual input, not something to automate.

## Platform connectors

Each module has a real (not stub) `CatalogConnector`, admin config screen, and scheduled
sync trigger — all following the same pattern, adapted to each platform's own idioms:

| | Magento (Pedro Luis Olivares, Quera) | PrestaShop (Grau) | WooCommerce (Gordillo, Saphir) |
|---|---|---|---|
| **Model code (`mc`) source** | EAV attribute `tudor_model_code` (`Setup\Patch\Data\AddTudorModelCodeAttribute`) or, if empty, derived from the SKU by a configurable rule; `tudorsync:model-code:fill` fills the attribute | New Feature "TUDOR Model Code" (created on install, id in config) | New post meta `_tudor_model_code` (product edit screen field) |
| **Enrollment rule** | Has a model code (attribute or SKU rule) | Feature value non-empty | Meta non-empty |
| **Exclusion rule** | Not enabled or not visible, no stock management, out of stock, backorders allowed, or no salable quantity (MSI reservations deducted) | Available quantity ≤ 0 (covers both true out-of-stock and backorder/"on demand") | Not in stock, or backorders allowed |
| **`value` sent** | Salable qty (MSI, website of the default store), or always `1` (config "Value Sent to TUDOR"); several products of one model are summed | Real available qty | Real qty if stock is managed, else `1` as a plain signal |
| **Product URL** | Default store view + one per active store view selling it, keys `es-ES`/`en-GB` (or `es`/`en`) | Per active language via `Link::getProductLink()` | Site permalink; per-locale needs a `tudorsync_localized_urls` filter hook (no multilingual plugin assumed) |
| **Admin config** | Stores > Configuration > TUDOR E-commerce Sync (`etc/adminhtml/system.xml`) | Modules > TUDOR E-commerce Sync > Configure (`HelperForm`) | Settings > TUDOR Sync (Settings API) |
| **Test Connection / Run Sync Now** | AJAX buttons on the config screen (`Block\...\StatusAndActions` → `Controller\Adminhtml\Sync\{Test,Run}`), result persisted via `Model\Status` | Two plain-HTML forms on the same config screen, handled inline in `getContent()`, result persisted in `Configuration` | Two forms posting to `admin-post.php` (`SettingsPage::handle{TestConnection,RunSync}`), result in `wp_options` + a flash notice |
| **Sync trigger** | Native Magento cron (`etc/crontab.xml`): full sync (frequency configurable, hourly by default) + changed TUDOR models published within a minute (`tudorsync_publish_pending`) | No native scheduler — a token-protected front controller (`controllers/front/cron.php`) that a real server cron must hit | WP-Cron, scheduled hourly on plugin activation |
| **HTTP client** | `Model\Api\LoggingHttpClient` over `Model\Api\CurlHttpClient` (wraps `\Magento\Framework\HTTP\Client\Curl`); every call logged in `tudorsync_api_log` | `Api\CurlHttpClient` (plain cURL — no HTTP library assumed) | `Api\WordPressHttpClient` (wraps `wp_remote_post`/`wp_remote_get`) |

"Test Connection" calls `GET /v1/point-of-sales` on all three — a real, auth-requiring TUDOR
endpoint, so a success is a genuine end-to-end confirmation, not just a reachability ping.
"Run Sync Now" builds the exact same `SyncEngine` the scheduled trigger uses, so a manual
run and a scheduled run always behave identically.

**Magento** runs in Quera's store (Magento 2.4.8, PHP 8.3) since 2026-09-30 and has been tested
end to end on Quera's staging against TUDOR's PREPROD (full sync, single-model and batch sends,
withdrawals, the valid-model list failing). It also does more than the table shows — real-time
publishing, attribution of orders coming from tudorwatch.com (`tudorsync_utm` cookie, with
CookieScript consent), the monthly report in the admin (*Reports › TUDOR e-Stock*), an API test
page and a log of every call to TUDOR: see [`modules/magento/README.md`](modules/magento/README.md).
**PrestaShop and WooCommerce** have still only been `php -l` linted, not run inside an actual
installation — see "What's still open" below.

## Business rules encoded in `core/`

- Every record is reviewed before it is sent (`AvailabilityFilter::keepOnlyAvailable()`), in
  four steps:
  1. **Normalize** `mc` and `country`: no spaces (non-breaking ones included), upper case.
  2. **Drop what can't be published**: no `mc`, a country that isn't two letters, not sellable
     **immediately online** (`value <= 0` or `onlinePurchaseEnabled` false — never "on demand"
     / backorder-only items), or a default URL that is empty, malformed or not `https://`.
     `localizedUrls` keys are normalized (`es_ES` → `es-ES`: hyphen, language in lower case,
     region in upper case) and must be BCP 47 / ISO 639-1, with no closed list of languages: a
     2–3 letter language (`es`, `ca`, `ast`), optionally a 4-letter script (`zh-Hant`) and/or a
     region of 2 letters or 3 digits (`es-ES`, `fr-CH`, `es-419`). An entry with a bad URL or
     key is removed on its own, keeping the watch.
  3. **One record per `mc` + country** (TUDOR keeps only the last line of a repeated `mc`, and
     a line it rejects as FAILED zeroes that watch): values are added up, the other fields
     come from the record with the highest value (the first one on a tie), in order of first
     appearance.
  4. **Only models in TUDOR's current price list** for the country (`ValidModelList`, see
     "TUDOR's valid model list" below).

  `getExclusions()` lists each dropped watch with a reason code (`missing_model_code`,
  `invalid_country`, `not_available`, `invalid_url`, `not_in_valid_list`) and a Spanish text
  to display; `getWarnings()` what was fixed or skipped without dropping a watch (localized
  URLs removed, repeated models merged, price list skipped).
- **Never an empty batch by mistake:** if the connector returns watches but none passes the
  review, `SyncEngine` sends nothing and throws `NothingPassedReviewException` with a summary
  of the reasons — an empty batch would zero the retailer's whole catalog at TUDOR. A
  connector that really returns nothing (everything sold out) still sends the empty batch.
- An unavailable model must disappear from the feed entirely: always submit the *complete*
  current catalog to `POST /v1/stocks/batch`, never a delta — TUDOR zeroes out whatever's
  missing automatically.
- Product URLs carry UTM tracking parameters so TUDOR-driven sales can be reported on
  request (`UtmUrlBuilder`, defaults to `utm_source=tudorwatch.com&utm_medium=website&
  utm_campaign=tudor_e-stock_program`).
- Multi-language stores provide one product URL per language/region plus a default/fallback
  URL (`LocaleUrlResolver`).
- Two environments, staging and production, each with their own TUDOR-issued OAuth client
  ID/secret (`Environment`, `ClientConfig`).

## TUDOR's valid model list

`AvailabilityFilter` also drops watches whose model code (TMC) isn't in TUDOR's current price
list for their country: `core/resources/valid-models/prices_{COUNTRY}.xlsx`
(`prices_ES.xlsx` for Spain), the file exactly as TUDOR publishes it. Only the column titled
"TMC" is used, wherever it is; codes are compared without spaces and in upper case. A watch
not in the list isn't sent (TUDOR sets it to 0 when it doesn't receive it). It applies to the
full sync and to the Magento module's real-time (every-minute) publishing.

**If the country's file is missing, can't be read, has no TMC column or has fewer than 100
models**, or if it would leave that country without any available watch, the filter isn't
applied: the sync goes on as if it didn't exist and `AvailabilityFilter::getWarnings()`
returns a warning ("Filtro de modelos vigentes DESACTIVADO: no se encuentra prices_ES.xlsx").
`getExcludedModelCodes()` gives the TMCs the list removed. Both refer to the last call to
`keepOnlyAvailable()`, so the modules can log and display them.

Updating (without touching code or editing the Excel file):

0. **When:** every time TUDOR publishes a new price list, not just once a year (the current
   one has models with validity dates 01-04, 28-05 and 24-07-2026). If TUDOR launches a new
   model and the list isn't up to date, that model won't be sent.
1. Download TUDOR's new price list.
2. Save it as `core/resources/valid-models/prices_ES.xlsx` (same name, replacing the previous
   one; if TUDOR names it differently, rename it).
3. `cd core && composer test`. If `RealPriceListTest` fails, TUDOR has changed the file's
   format.
4. Commit, push and deploy to the stores.

For another market, just add `prices_XX.xlsx` with the country code from `StockAvailability::$country`.

## What's still open

- **Going live in Spain (2026-10-19):** Quera's store is production since 2026-10-05, with TUDOR
  not connected yet. Before the activation it needs its PROD credentials, the hourly full sync
  (`0 * * * *`) and "Publish Changes Within a Minute" on. The automatic sends (cron) haven't been
  tested on the staging yet: its crons are paused on purpose.
- **Semantics of `value`**: still to be confirmed by TUDOR (see `StockAvailability`'s docblock).
- **Modules still on `tudorApiKey`**: core authenticates with OAuth2 since 2026-10-02. Magento
  passes `clientId`/`clientSecret` since 2026-10-06; PrestaShop and WooCommerce don't yet (see
  `intercambio/2026-10-02-jorge-oauth-listo.md`). Remove `tudorApiKey` once all three have.
- **PrestaShop and WooCommerce connectors are unverified against a real store**, including their
  Test Connection / Run Sync Now actions. Each uses a *new* field (Feature/meta) for the TUDOR
  model code: confirm with Grau, Gordillo and Saphir whether they already have a convention for it.
- **Model codes to review with Quera**: the SKU rule's codes, and `M25707B/25-0001`, which isn't
  in TUDOR's price list (only `M25707B/26-0001` is) and so isn't sent.
- **Per-POS click & collect** (`storesAvailabilityDetails`/RSWI) isn't wired up on any platform
  (only a flat, store-wide toggle), nor is **MSI with several sources** in Magento.
- **WooCommerce multi-language** isn't implemented, only an extension point
  (`tudorsync_localized_urls` filter) — needs wiring to whatever plugin (if any) Gordillo/
  Saphir actually use.
- **PrestaShop's cron** depends on the server operator adding a real crontab entry that hits
  the generated, token-protected URL — nothing in the module itself schedules that call.
- **Monthly report data**: Magento computes the order side (online and click & collect sales)
  and writes the Excel from its admin; sessions and unique visitors are typed in by hand. Core
  still has no interface for the report's data source and nothing reads them from GA4.
- Package naming/versioning conventions for internal Composer registry distribution (how
  each store actually pulls `tudorsync/core` — private Packagist, Satis, a path repo baked
  into deployment, etc.) — currently each module's `composer.json` points at `core/` via a
  local path repository for development only.
- No CI runs `composer test` automatically.

## Development

```sh
cd core
composer install
composer test
```

`core/` currently has 80 passing PHPUnit tests. Among them:

- `MonthlyReportWriterTest` writes into a copy of the real ES report template and reads the
  cells back — that's how the row-5-has-example-content and row-20-is-a-footnote-not-data
  issues documented above were actually found, not just guessed from reading the XML;
- `RealPriceListTest` checks the real `prices_ES.xlsx` (TMC column, at least 100 models, every
  code shaped like a TMC), so a format change by TUDOR shows up when the file is replaced;
- `ConstructorDefaultsTest` fails if any constructor in `core/src` has an object as a default
  value (`Foo $foo = new Foo()`), which breaks Magento's `setup:di:compile` in production mode.
  Use `?Foo $foo = null` and `$this->foo = $foo ?? new Foo()` instead.

The platform modules (`modules/*`) have no unit tests of their own. The Magento module is
checked inside Quera's staging store against TUDOR's PREPROD: `bin/magento tudorsync:catalog:preview`
(dry run, no call to TUDOR), `tudorsync:connection:test`, `tudorsync:sync:run` and the admin's
API test page; before a change goes to production, `setup:di:compile` on the staging must leave
no `__set_state` in `generated/metadata/global.php`. PrestaShop and WooCommerce only pass
`php -l`: treat them as needing a real smoke test against each client's staging store before
going live.
