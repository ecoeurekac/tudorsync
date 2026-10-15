# Un solo plugin WooCommerce para Gordillo y Saphir (rama `woocommerce-unificado`)

**De:** Juanjo (sesión de Gordillo, Mac local) · **Para:** la sesión de Saphir, Jorge, Carlos · 15-10-2026

## Qué pasaba
El 09-10 se reescribió `modules/woocommerce` a paridad con Magento (≈50 archivos), pero el push quedó
bloqueado desde el Mac y no llegó a GitHub. Entretanto la sesión de Saphir siguió sobre la versión
antigua (`e17e892`, `9368ff5`, `7e39cd7`, `bca0fc0`, `4421579`): había dos plugins distintos para lo mismo.
Decisión de Juanjo: **un solo plugin genérico** para las dos tiendas (nada de una tienda en el código).

## Qué hay en la rama
Reescritura rebasada sobre `main` (`394e591`) + un commit que trae lo de la versión de Saphir:
- **Informe:** `Report\ProgrammeSalesSource` implementa `ProgrammeSalesSourceInterface` de core
  (click & collect `null`, como se acordó). Cuenta processing/completed/refunded; otros estados con el
  filtro `tudorsync_programme_sales_statuses`. `wp tudorsync report programme-sales [--month] [--all-orders]`.
- **`wp tudorsync model-code fill`:** SKU que ya es un TMC → imagen de TUDOR (nombre de archivo, alt,
  título, pie; separador guion/espacio/_) → regla de SKU. Control imagen↔SKU: si la referencia de la
  imagen no casa con la del SKU (4 primeros caracteres) sale como `mismatch` y no se rellena.
  Un SKU propio de la tienda (sin referencia TUDOR) no se puede comparar y acepta la imagen. `--category=<slug>`.
- **`localizedUrls` nunca vacío:** sin URLs por idioma (una sola lengua, o traductor tipo overlay como
  gTranslate) va el idioma del sitio con la URL por defecto (`{"es-ES": …}`). Esto esquiva el 400 de
  `intercambio/2026-10-15-juanjo-batch-localizedurls-array-vacio.md`, **pero el arreglo en core sigue
  haciendo falta** (Magento/PrestaShop y cualquier otro caso).
- **Publicación en el minuto:** cola, hooks de Woo, envío cada minuto (sueltos o batch si >20 o alguno
  deja de estar disponible), `realtime_enabled`. Pausa de 5 min ante 429 **y ahora también ante
  credenciales rechazadas**.
- **Migración de ajustes de la primera versión** (al cargar, una vez): credenciales, país, entorno y
  click & collect conservan su nombre de opción; `tudorsync_attribution_consent_category` pasa a
  consentimiento por cookie `cookielawinfo-checkbox-<cat>=yes`; se borran `tudorsync_api_key_*`.
- Versión **0.3.0**. Fuera los archivos antiguos (`SettingsPage`, `CatalogConnector`, `OrderAttribution`,
  `Cli/ModelCodeCommand`, `Cli/ReportCommand`, `ProgrammeSalesSource` en la raíz).

## Probado (WordPress local: WP 7.1.3, Woo 11.2 HPOS, TranslatePress, PHP 8.4, TUDOR simulado)
Migración e idempotencia · `model-code fill` con 7 casos (SKU = TMC, archivo, alt con espacio, imagen
de otro modelo, SKU propio, referencia truncada, sin código) en transacción deshecha · Test Connection ·
preview (con la lista de modelos vigentes de core) · `localizedUrls` sin idiomas → `{"es-ES": …}` ·
`sync run` · publicación en el minuto, pausa con 429 y con credenciales rechazadas, y reanudación ·
informe y la interfaz de core · las 6 pestañas del admin sin avisos · portada y ficha en 200 · `bin/build.sh`.

**Sin probar todavía contra PREPROD real ni en el staging de Saphir.** Por eso va en rama, no en `main`.

## Siguiente
1. Sesión de Saphir: validar la rama en el staging **con el correo aislado antes** (SendGrid activo:
   los avisos de pedido llegan a marketing). Cambiar el checkout a la rama (`git fetch && git switch
   woocommerce-unificado`), `composer install` con PHP 8.1 desde `modules/woocommerce`. Revisar que el
   import de stock del ERP (`consultaStock_csv.php` / `cron_prices.php`) pase por funciones de Woo: si
   escribe por SQL, el minuto no lo ve y solo lo recoge el batch horario.
2. Si pasa, `main` pasa a esta rama (fast-forward) y Gordillo instala el build 0.3.0.
3. Jorge: el arreglo de `localizedUrls` vacío en `TudorApiClient::toStockCreatePayload` sigue pendiente.
