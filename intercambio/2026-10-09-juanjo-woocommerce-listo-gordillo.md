# Plugin WooCommerce a paridad con Magento; puesta en marcha en Gordillo (Juanjo, 09-10-2026)

## Para Jorge

- `modules/woocommerce` pasa ya `clientId`/`clientSecret` a `ClientConfig` y **no lee `tudorApiKey`**
  (`Config::getClientConfig()`). Queda solo PrestaShop para poder quitar `tudorApiKey` del core.
- Nada tocado en `core/`. El plugin usa: `TudorApiClient` (`getPointOfSales`, `createStock`,
  `batchUpsertStocks`, `getStocks`, `getHealth`), `SyncEngine`, `AvailabilityFilter` (+ `getExclusions()`,
  `getWarnings()`), `ValidModelList` (`normalize`, `isLoaded`, `contains`, `fileName`), `UtmUrlBuilder`,
  `MonthlyReportWriter` y las excepciones de `Api\Exception`. Sin objetos por defecto en constructores.
- La revisión de la ficha se ve en preview (`wp tudorsync catalog preview`, también `--json-file`), en el
  admin (último sync y «Programme data») y en el log (`warning` por descarte), y «N model(s) sent» cuenta
  lo que pasa la revisión. Igual que en Magento.
- El plugin se instala construido (`modules/woocommerce/bin/build.sh`): lleva `vendor/` con una copia de
  core (sin `tests/`) y phpspreadsheet, porque en cPanel no hay Composer. Si cambias `resources/`
  (p. ej. un `prices_ES.xlsx` nuevo), hay que reconstruir y subir el plugin a cada tienda WooCommerce.

## Probado (local, sin TUDOR real)

WordPress 7.1.3 + WooCommerce 11.2 (HPOS) + TranslatePress 3.3.7, PHP 8.4, con un TUDOR simulado por
`pre_http_request` (token, health, point-of-sales, stocks, batch 200/207, 401, 429, caída de red):
test de conexión, sync completo, revisión (URL no https, país mal, lista de vigentes), agrupación por
`mc`, variaciones, reservas/agotados/borradores/ocultos, cola en el minuto (pedido, edición de stock,
retirada por batch, `createStock` uno a uno, pausa de 5 min por 429), log de API con secret/token
enmascarados, cookie UTM (JS con y sin consentimiento), atribución en checkout clásico y de bloques,
informe mensual en Excel (plantilla real ES), `model-code fill` y la página de pruebas de API (POST
bloqueados en PROD). **Falta PREPROD de verdad**: no hay credenciales de Gordillo y las de Quera no se
pueden reutilizar.

## Gordillo (joyeriagordillo.com) — lo propio de la tienda, solo aquí

- Hosting cPanel (212.83.133.251), WordPress 7.1.3, WooCommerce 11.2.0, PHP 8.4 (ea-php84, LiteSpeed),
  prefijo `JG25_`. **Solo hay producción** (no hay staging; el cPanel lo gestiona un tercero). Acceso
  nuestro: FTPS. El plugin viejo (02-10) está subido en `wp-content/plugins/tudorsync-woocommerce/` sin activar.
- Idiomas: TranslatePress (es-ES en `/`, en-GB en `/en/`) → el plugin los saca solo.
- Consentimiento: WebToffee GDPR Cookie Consent + WP Consent API; caché LiteSpeed (por eso la cookie va por JS).
- Catálogo TUDOR (Store API pública, 09-10): 40 fichas en la categoría `tudor`; el **SKU es el TMC**
  casi siempre (`M79030B-0001`), alguno sin la `M` (`28400-0003`, `2542G267NU-0002`). Varios en
  «Disponible para reserva» (reservas = bajo demanda → fuera).
- Ajustes propuestos (en el admin, no en el código):
  - Market `ES`, entorno PREPROD hasta validar.
  - SKU pattern `/^M?(\d{4,5}[A-Z0-9]*-\d{4})$/`, replacement `M$1`, product scope `product_cat:tudor`
    (comprobar si existe también `product_brand:tudor`).
  - Cookie consent: WP Consent API, categoría `statistics` o `marketing` según cómo lo tenga WebToffee
    (comprobar en su panel); si WebToffee no informa a WP Consent API, modo cookie con su
    `cookielawinfo-checkbox-<categoría>=yes`.
  - Click & collect: preguntar a Gordillo si tienen recogida en tienda y con qué método de envío.
- Preguntas para Gordillo: ¿la referencia TUDOR está siempre en el SKU?, ¿qué modelos sin `M` son
  realmente TMC?, ¿gestionan stock por unidades en todas las fichas TUDOR?, ¿recogida en tienda?
- Antes de la primera sincronización con credenciales: `wp tudorsync catalog preview` (o la pestaña
  «Programme data») y revisar que salen los modelos esperados. Con 0 modelos el batch no se envía.
