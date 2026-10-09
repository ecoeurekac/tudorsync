# La lista de modelos vigentes, siempre; la protección, solo en el batch — de Jorge para Juanjo

09-10-2026, Jorge. Cambio en `core/` (commit «Lista de modelos vigentes: siempre en envíos sueltos; en el batch,
sin filtro solo si no pasa ningún reloj del país»). No cambian los constructores, ni `StockAvailability`, ni
`CatalogConnectorInterface`: no hace falta `setup:di:compile`.

## Qué cambia

- **`AvailabilityFilter::keepOnlyAvailable()` aplica siempre la lista** (si el archivo es válido). Un reloj fuera
  de la lista se descarta con `not_in_valid_list`, aunque deje al país sin relojes. Ya no sale el aviso
  «Filtro de modelos vigentes DESACTIVADO: ningún reloj disponible de ES está en prices_ES.xlsx».
- **Nuevo `keepOnlyAvailableForBatch()`**, solo para el catálogo completo. Es igual, salvo que, en un país donde
  los pasos 1–3 dejan relojes pero la lista no deja ninguno, no aplica la lista a ese país:
  - se envían todos;
  - en `getWarnings()` sale: «Lista de modelos vigentes NO aplicada en ES: ningún reloj (76) la supera, lo que
    suele indicar una lista errónea. Para no dejar el catálogo vacío en TUDOR, se envían sin este filtro. Revisa
    prices_ES.xlsx.»;
  - `getCountriesSentUnfiltered()` devuelve `list<Rules\CountrySentUnfiltered>`, con `country`, `message` (ese
    texto) y `wouldExclude` (los relojes que la lista habría quitado, como `Exclusion` con `not_in_valid_list`).
    Esos relojes **no** están en `getExclusions()` ni en `getExcludedModelCodes()`, porque sí se envían.
- **`SyncEngine::run()`** usa `keepOnlyAvailableForBatch()`. Es la única fuente de verdad de lo que se envía en un
  batch.
- **Nuevo `SyncEngine::plan()`**: hace lo mismo que `run()` hasta justo antes de la llamada y devuelve la lista
  que se enviaría, sin ninguna llamada a TUDOR (ni token). Si hay fichas y no pasa ninguna, lanza la misma
  `NothingPassedReviewException`. Los avisos, descartes y países sin filtro quedan en el `AvailabilityFilter`
  que pasas a `SyncEngine`.
- Sin cambios: si el archivo falta, no se puede leer, no tiene columna TMC o tiene menos de 100 modelos, la lista
  no se aplica en ningún caso y queda el aviso «Filtro de modelos vigentes DESACTIVADO: …», como ahora. Los pasos
  1–3 tampoco cambian.

En resumen: la lista se aplica siempre en los envíos sueltos, en la página de pruebas y en la cola del minuto. La
protección solo actúa en el batch del catálogo completo.

## Lo que he comprobado en el módulo (solo lectura, `a3cd176`)

1. **La cola del minuto sigue bien con el filtro estricto.** `SyncRunner::planPending()` toma como disponibles
   los de `$snapshot->getItemsToSend()` (`Model/SyncRunner.php:147`). Esos salen de `CatalogReview::of()` →
   `keepOnlyAvailable()` (`Model/CatalogConnector.php:172`, `Model/CatalogReview.php:39`). Un modelo de la cola
   fuera de la lista no está ahí, así que no se publica suelto: va a `$unavailable` (línea 156).
2. **Con una lista rota (no reconoce ningún reloj), la cola acaba en el batch protegido.**
   - El filtro estricto deja vacío `getItemsToSend()`, así que todos los modelos de la cola van a `$unavailable`
     (línea 156) y `fullBatch` es verdadero (línea 170).
   - `doPublishPending()` (líneas 219–221) llama entonces a `sendBatch()`, que crea
     `new SyncEngine(new SnapshotConnector($snapshot), …)` (líneas 349–358).
   - `SnapshotConnector::getAvailableCatalog()` devuelve `$snapshot->items`, las fichas **antes** de la
     revisión (`Model/SnapshotConnector.php:22`). Por eso `run()` revisa el catálogo entero con
     `keepOnlyAvailableForBatch()` y salta la protección.
   - Lo mismo vale para el sync de cada hora y Run Sync Now (`SyncRunner.php:97`), y para el batch del catálogo
     desde la página de pruebas (`Model/Api/ApiTester.php:333`).
3. **El parche de `ApiTester::review()` (líneas 205–262) sigue funcionando sin cambios.**
   - Con el filtro estricto, `$review->items` ya no trae relojes fuera de la lista, así que el bucle de las
     líneas 235–245 no descarta nada.
   - `$checked` queda vacío, así que el filtro de avisos de las líneas 251–259 no quita nada.
   - El aviso «DESACTIVADO: ningún reloj…» que buscaba ya no existe.
   - Si falta el archivo, el aviso «no se encuentra prices_ES.xlsx» pasa igual que antes.

## Tu parche de la página de pruebas: conviene quitarlo

En `ApiTester::review()`, las líneas 228–259 (comprobar contra `ValidModelList` directamente y quitar el aviso)
ya no hacen falta: con `CatalogReview::of($items)` basta, conservando lo del value 0 de las líneas 217–226 y
247. Además, duplican el texto del motivo («No está en la lista de modelos vigentes de TUDOR (…)») y detectan
el aviso de core por su texto (`str_contains(…, 'DESACTIVADO')`). Las dos cosas se rompen si core cambia un
mensaje.

## Qué llamadas del módulo tienen que cambiar y cuáles no

**Catálogo completo: pasan a `keepOnlyAvailableForBatch()` o, mejor, a `SyncEngine::plan()`:**

- `Model/CatalogConnector.php:172`: `CatalogReview::of($items)`, que es el `$snapshot->review` de todo lo de
  abajo. Ojo con el punto siguiente: `planPending()` también usa ese mismo review.
- Simulación: `Console/Command/CatalogPreviewCommand.php:66` y `:68` (recuentos), `:80` y `:81` (`--json`:
  `review` y `records`), `:103` y `:148`.
- «Datos del programa»: `Block/Adminhtml/Data/Overview.php:141` y
  `view/adminhtml/templates/data/overview.phtml:64–79` (recuentos, «nothing passed» y avisos).
- Sync completo (cron de cada hora y Run Sync Now): `Model/SyncRunner.php:96` (`logReview`), `:103`, `:109` y
  `:125` (estado guardado), y `:119–120` (recuentos del mensaje).
- Batch del minuto: `Model/SyncRunner.php:220` (`logReview`) y `:226–227` (recuentos).
- `Model/SyncRunner.php:351–353`: `sendBatch()` crea un `new AvailabilityFilter()` aparte del de la revisión,
  así que hoy lo que se muestra sale de otro objeto. Pasa a `SyncEngine` el mismo filtro del que lees avisos,
  descartes y países sin filtro, o usa `plan()` con ese filtro.
- Batch del catálogo desde la página de pruebas: `Model/Api/ApiTester.php:79` (lo que se muestra; el envío de
  la línea 333 ya va por `SyncEngine`).

**Publicación en el minuto y envíos sueltos: se quedan con `keepOnlyAvailable()` estricto:**

- `Model/SyncRunner.php:143–156` (`planPending()`: qué modelos de la cola se publican sueltos). **Importante:**
  hoy lee `$snapshot->getItemsToSend()`, el mismo review que el catálogo completo. Si cambias la línea 172 de
  `CatalogConnector` al método de batch sin más, `planPending()` también pasaría a usarlo. Con una lista rota,
  publicaría sueltos los relojes fuera de la lista, que es justo lo que se quiere evitar. `planPending()` necesita
  su propia revisión estricta: por ejemplo, que el snapshot lleve las dos o que llame a `keepOnlyAvailable()`
  sobre `$snapshot->items`.
- `Model/Api/ApiTester.php:80`, `:134` y `:215–262` (`review()`: POST /v1/stocks, vista previa de la ficha y
  batch de productos elegidos).
- `Model/Api/ApiTester.php:334` (`batchUpsertStocks($items)` de los productos elegidos: pasa por `review()`, que
  es estricto).

**Hasta que hagas el cambio:** solo en el caso raro de una lista rota (archivo válido que no reconoce ningún
reloj), la simulación, el panel, los recuentos y el log dirían menos relojes de los que de verdad envía
`SyncEngine`. Dirían «0 enviados, 76 descartados» y «ningún reloj ha superado la revisión», con una línea «left
out» por reloj, cuando en realidad se envían los 76 con el aviso. Con la lista buena, todo coincide.

## Petición para tu lado

1. Que la simulación (`tudorsync:catalog:preview` y `--json`), «Datos del programa» y los recuentos usen el
   método de batch, o mejor `SyncEngine::plan()`, para que muestren exactamente lo que se enviaría.
2. En la simulación:
   - fichas recibidas;
   - resultado con la lista aplicada, con el motivo de cada descarte;
   - si un país va sin filtro (`getCountriesSentUnfiltered()`), un **aviso destacado** con el porqué («se
     **enviarían** N sin este filtro…») y lo que se enviaría de verdad.
3. En el resultado de Run Sync Now, en el panel y en el log: lo mismo, pero en pasado («se **ha enviado** sin
   filtrar…»).

## Pruebas (staging contra PREPROD, sin `setup:di:compile`)

- `composer test`: 90 tests en verde (80 de antes + 10 nuevos). Solo se ha adaptado uno:
  `AvailabilityFilterTest::testNeverEmptiesACountryBecauseOfTheList`, que ahora es
  `testOutsideTheBatchTheListIsAppliedEvenIfItEmptiesACountry`. Comprobaba la protección dentro de
  `keepOnlyAvailable()`, que ya no la tiene; la versión de batch está en `AvailabilityFilterBatchTest`.
- `plan()` con el catálogo real, sin enviar nada: 76 fichas, 73 para enviar, fuera `M79030N-0002`,
  `M25807KN-0001` y `M25707B/25-0001` (`not_in_valid_list`), ningún país sin filtro. 0 llamadas HTTP.
- `plan()` con una lista de prueba válida (columna TMC, 150 modelos inventados) que no reconoce ningún reloj:
  ES sin filtro, 76 para enviar, el aviso y 76 relojes en `wouldExclude`. 0 llamadas HTTP. Archivo temporal
  borrado; `prices_ES.xlsx` sin tocar.
- `keepOnlyAvailable()` solo con la ficha de `M79030N-0002`: descartada con `not_in_valid_list`, sin avisos.
- `bin/magento tudorsync:sync:run` contra PREPROD: 73 enviados, 3 fuera, 73 resultados, 0 fallos.
- Sin errores nuevos en `exception.log` ni en `tudorsync.log`.
