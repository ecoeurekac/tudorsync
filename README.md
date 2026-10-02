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
      Rules/                   # AvailabilityFilter — enforces TUDOR's visibility rules
      Url/                     # UtmUrlBuilder, LocaleUrlResolver
      Api/                     # TudorApiClient, NdjsonCodec, BatchSyncResult, StockImportResult, PointOfSale, HttpClientInterface
      Sync/                    # SyncEngine — orchestrates one run
      Reporting/               # MonthlyReportWriter, MonthlySalesReportRow
    resources/
      report-templates/       # TUDOR's real monthly-report .xlsx templates, one per language
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
| **Model code (`mc`) source** | New EAV attribute `tudor_model_code` (`Setup\Patch\Data\AddTudorModelCodeAttribute`) | New Feature "TUDOR Model Code" (created on install, id in config) | New post meta `_tudor_model_code` (product edit screen field) |
| **Enrollment rule** | Attribute non-empty | Feature value non-empty | Meta non-empty |
| **Exclusion rule** | Not enabled, out of stock, or backorders allowed | Available quantity ≤ 0 (covers both true out-of-stock and backorder/"on demand") | Not in stock, or backorders allowed |
| **`value` sent** | Real stock qty (legacy single-source `StockRegistryInterface`) | Real available qty | Real qty if stock is managed, else `1` as a plain signal |
| **Product URL** | Per store view via `ProductRepositoryInterface` reload | Per active language via `Link::getProductLink()` | Site permalink; per-locale needs a `tudorsync_localized_urls` filter hook (no multilingual plugin assumed) |
| **Admin config** | Stores > Configuration > TUDOR E-commerce Sync (`etc/adminhtml/system.xml`) | Modules > TUDOR E-commerce Sync > Configure (`HelperForm`) | Settings > TUDOR Sync (Settings API) |
| **Test Connection / Run Sync Now** | AJAX buttons on the config screen (`Block\...\StatusAndActions` → `Controller\Adminhtml\Sync\{Test,Run}`), result persisted via `Model\Status` | Two plain-HTML forms on the same config screen, handled inline in `getContent()`, result persisted in `Configuration` | Two forms posting to `admin-post.php` (`SettingsPage::handle{TestConnection,RunSync}`), result in `wp_options` + a flash notice |
| **Sync trigger** | Native Magento cron (`etc/crontab.xml`, frequency configurable) | No native scheduler — a token-protected front controller (`controllers/front/cron.php`) that a real server cron must hit | WP-Cron, scheduled hourly on plugin activation |
| **HTTP client** | `Model\Api\CurlHttpClient` (wraps `\Magento\Framework\HTTP\Client\Curl`) | `Api\CurlHttpClient` (plain cURL — no HTTP library assumed) | `Api\WordPressHttpClient` (wraps `wp_remote_post`/`wp_remote_get`) |

"Test Connection" calls `GET /v1/point-of-sales` on all three — a real, auth-requiring TUDOR
endpoint, so a success is a genuine end-to-end confirmation, not just a reachability ping.
"Run Sync Now" builds the exact same `SyncEngine` the scheduled trigger uses, so a manual
run and a scheduled run always behave identically.

All three were only `php -l` linted, not run inside an actual Magento/PrestaShop/WordPress
installation — see "What's still open" below for what each still needs before going live.

## Business rules encoded in `core/`

- Only products sellable **immediately online** are ever sent — never "on demand" /
  backorder-only items (`AvailabilityFilter`, gated on `onlinePurchaseEnabled && value > 0`).
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

## What's still open

- **Modules still on `tudorApiKey`**: core authenticates with OAuth2 since 2026-10-02, but
  the three modules don't pass `clientId`/`clientSecret` to `ClientConfig` yet (see
  `intercambio/2026-10-02-jorge-oauth-listo.md`). Remove `tudorApiKey` once all three have.
- **Per-platform `CatalogConnector` implementations exist but are unverified against a real
  store.** Each uses a *new* field (attribute/Feature/meta) for the TUDOR model code rather
  than an existing SKU or attribute — confirm with each client whether they already have a
  convention for this before relying on the new field in production. None have been tested
  against a real catalog, and per-POS click & collect (`storesAvailabilityDetails`/RSWI) and
  MSI multi-source stock (Magento) aren't wired up at all yet — only a flat, store-wide
  click & collect toggle.
- **WooCommerce multi-language** isn't implemented, only an extension point
  (`tudorsync_localized_urls` filter) — needs wiring to whatever plugin (if any) Gordillo/
  Saphir actually use.
- **PrestaShop's cron** depends on the server operator adding a real crontab entry that hits
  the generated, token-protected URL — nothing in the module itself schedules that call.
- **"Test Connection" / "Run Sync Now" admin actions** are new and, like the rest of the
  connectors, unverified against a real installation — in particular Magento's AJAX
  controllers (ACL, routing, `FORM_KEY` handling) are the most likely to need adjustment
  once actually loaded in a Magento admin.
- **Monthly report data sourcing**: nothing yet pulls sessions/visitors from analytics or
  sales counts from each platform's orders into `MonthlySalesReportRow` — only the Excel
  writer exists so far.
- Package naming/versioning conventions for internal Composer registry distribution (how
  each store actually pulls `tudorsync/core` — private Packagist, Satis, a path repo baked
  into deployment, etc.) — currently each module's `composer.json` points at `core/` via a
  local path repository for development only.

## Development

```sh
cd core
composer install
composer test
```

`core/` currently has 14 passing PHPUnit tests, including `MonthlyReportWriterTest`, which
writes into a copy of the real ES report template and reads the cells back — that's how the
row-5-has-example-content and row-20-is-a-footnote-not-data issues documented above were
actually found, not just guessed from reading the XML.

The platform modules (`modules/*`) have real `CatalogConnector`/config/cron code (see
"Platform connectors" above), and every file passes `php -l`, but none of it has run inside
an actual Magento/PrestaShop/WordPress installation — no platform test framework (Magento's
`bin/magento`, PrestaShop's module validator, WP-CLI/PHPUnit with the WP test suite) was
available in the environment this was written in. Treat the connectors as needing a real
smoke test against each client's staging store before going live.
