# Para Jorge: módulo de Magento al día con core (catálogo vacío, plan(), revisión estricta)

14-10-2026, Juanjo. Respuesta a `2026-10-14-jorge-catalogo-vacio.md` y `2026-10-09-jorge-lista-solo-en-batch.md` (y a la
segunda petición de `2026-10-09-jorge-pruebas-cron.md`). Solo `modules/magento`; core sin tocar.

## Hecho
- **`EmptyCatalogException`** capturada antes que `NothingPassedReviewException` en `SyncRunner::doRunSync()`: estado y log
  «Nothing sent: <mensaje de core>». Preview y «Datos del programa» avisan del catálogo vacío (`CatalogReview::isEmptyCatalog()`).
- **Dos revisiones** en `Model\CatalogReview`: `ofBatch()` (= `keepOnlyAvailableForBatch()`, la de `CatalogSnapshot::$review`:
  preview/--json, «Datos del programa», batch del catálogo en la página de pruebas) y `of()` (estricta).
- **`sendBatch()`** hace `SyncEngine::plan()` y luego `batchUpsertStocks()` con el resultado: el log, el estado y las cuentas
  salen del **mismo** `AvailabilityFilter` que decidió el envío (`CatalogReview::fromFilter()`).
- **`planPending()`** con su propia revisión estricta (`CatalogReview::of($snapshot->items)`).
- **Parche de `ApiTester::review()` quitado**: `CatalogReview::of()` + lo del value 0. Ya no duplica el texto del motivo ni
  busca «DESACTIVADO».
- **Log del batch del minuto**: distingue los que deja fuera la revisión de los que ya no están en el catálogo, p. ej.
  «1 model(s) no longer available (1 left out by the review: M79030N-0002): a full batch withdraws them».
- Fuera del módulo lo propio de Quera (camino a PLO): el prefijo `amstorepick_` pasa del `di.xml` a la config
  `tudorsync/report/pickup_shipping_method_prefixes` (vacía por defecto); README y comentarios sin datos de tiendas.

## Probado en el staging de Quera (core `91bd959`)
- Preview: 76 construidos, 3 fuera de la lista, 73 a enviar (igual que antes).
- `sync:run` PREPROD: 73 enviados, 3 fuera, 73 resultados, 0 fallos.
- Catálogo vacío y «no pasa nada» con un conector falso: no llaman a TUDOR; mensajes correctos en estado y log.
- Minuto: 1 modelo disponible → POST suelto; disponible + `M79030N-0002` → batch completo (73/3/0) con el motivo en el log.
- Pruebas de API (vista previa): `M79030N-0002` sale fuera con su motivo; valor 0 se mantiene en 0.
- Es la comprobación con envío real que pedías en la nota del catálogo vacío: mismos enviados, 0 fallos, los mismos fuera.

Cambia el constructor de `ProgrammeSalesSource` (añade `Config`; solo tiene Proxy generado): en producción, `di:compile`.
