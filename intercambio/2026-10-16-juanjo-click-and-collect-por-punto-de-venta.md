# Click & collect con plazo por punto de venta (Magento) — 16-10, Juanjo

Respuesta a la pregunta de Jorge: hasta hoy no se podía, porque solo existía la opción general Sí/No. Ya está
hecho en `modules/magento`. **El core no cambia**: se rellena `StockAvailability::$storesAvailabilityDetails`,
que `TudorApiClient` ya enviaba.

## Dónde está en el admin

*Stores > Configuration > Catalog > TUDOR E-commerce Sync > General*, con *Offer Click & Collect* = Sí:

- **Store Pickup Timing (hours)**: plazo por defecto (0 = ya en tienda). Sin filas debajo, vale para
  **todos** los puntos de venta del distribuidor, leídos de `GET /v1/point-of-sales` (en caché 24 h).
- **Click & Collect per Point of Sale**: filas con RSWI, Sí/No y horas (vacío = el plazo por defecto). Si hay
  filas, solo se mandan esas: TUDOR quita la recogida de los puntos de venta que no estén.

La API no tiene un plazo de recogida general, solo `storePickupTiming` dentro de cada punto de venta (spec
1.7.0). Por eso el «global» se reparte entre todos los RSWI.

## Probado en PLO (sin tocar su configuración, que sigue con la recogida desactivada)

Con config simulada: apagado → sin detalle; encendido sin plazo → sin detalle; con filas → cada RSWI con lo
suyo; solo plazo general → `RSWI_2450` y `RSWI_18488` (PREPROD) a 24 h. El preview real de PLO no cambia
(30 registros) y el cron sigue en verde.

## Para desplegar en las otras tiendas Magento

Sin `setup:upgrade` (solo `system.xml`, DI y clases nuevas). **Quera, en modo producción:**
`setup:di:compile`, comprobar que `grep -c __set_state generated/metadata/global.php` da 0, y `cache:flush`.
En PLO (modo default) ha bastado con `cache:clean config`.

PrestaShop y WooCommerce siguen enviando solo la opción general.
