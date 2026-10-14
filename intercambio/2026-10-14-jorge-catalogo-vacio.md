# Para Juanjo: el core ya no envía nunca un batch con el catálogo vacío

14-10-2026, Jorge. Respuesta a `2026-10-13-juanjo-batch-vacio.md` (commit `18e7424`).

## Qué hace ahora el core

- `SyncEngine::plan()` lanza **`Tudorsync\Core\Sync\EmptyCatalogException`** en cuanto el conector devuelve `[]`, antes
  del filtro y antes de cualquier llamada a TUDOR (ni token). Como `run()` pasa por `plan()`, también queda cubierto.
- Mensaje: «El conector ha devuelto un catálogo vacío: no se envía nada a TUDOR para no retirar todo el catálogo.
  Revisa que los productos TUDOR tengan el código de modelo y que la lectura de la tienda funcione».
- `EmptyCatalogException` **extiende `NothingPassedReviewException`** (que ha dejado de ser `final`; su constructor no
  cambia). Donde ya capturas la general, la nueva entra sin cambiar nada. `getReceived()` da 0 y `getExclusions()` `[]`.
- Si el catálogo trae fichas y ninguna pasa la revisión, sigue saliendo `NothingPassedReviewException` como hasta ahora.
- No cambia la publicación en el minuto (`createStock`, envíos sueltos), que no pasa por `plan()`, ni constructores,
  `StockAvailability`, `CatalogConnectorInterface` ni `AvailabilityFilter`. No hace falta `di:compile`.

**Para mostrar un mensaje propio del caso vacío, en cada `try` captura `EmptyCatalogException` ANTES que
`NothingPassedReviewException`**; si no, entra siempre en la general.

## Qué hay que cambiar en los módulos

**WooCommerce — antes de instalar en Gordillo y Saphir (prioridad):**
- `includes/Cron.php:59` llama a `run()` sin capturar nada: con un catálogo vacío, error fatal en WP-Cron. Captura
  `EmptyCatalogException` / `NothingPassedReviewException` y regístralo con `getMessage()`.
- `includes/Admin/SettingsPage.php:213`: hoy lo recoge el `catch (Throwable)` y sale «Sync failed». Mejor un aviso
  (warning) con `getMessage()`, como en PrestaShop.

**PrestaShop — tu arreglo de `63e4dab` queda como código muerto:**
- En `controllers/front/cron.php:52` y `tudorsync.php:230`, `$engine->plan() === []` ya no se da nunca: con el catálogo
  vacío, `plan()` lanza la excepción y se ve el mensaje genérico del core, no tu aviso.
- Para conservarlo: mueve el aviso («no product has a TUDOR model code yet») a un `catch (EmptyCatalogException $e)`,
  antes del de `NothingPassedReviewException`, comprobando dentro `countEnrolledProducts() === 0`; si no se cumple,
  muestra `$e->getMessage()`. Y quita la comprobación con `plan() === []` (de paso, evita leer el catálogo dos veces).

**Magento — antes del 19-10 si te da tiempo (no envía nada en ningún caso, solo es el texto):**
- Ya no hace falta añadir la protección en `SyncRunner`: la pone el core.
- `Model/SyncRunner.php:100-101` muestra un texto propio («Nothing sent: no watch passed the review (%1 received)…») que
  con un catálogo vacío sería engañoso («0 received»). Captura antes `EmptyCatalogException` y muestra su `getMessage()`.
- `Model/Api/ApiTester.php:333` (batch de pruebas) ya muestra el mensaje del core por el `catch (\Throwable)` de la
  línea 97: no hace falta tocarlo.

## Sin opción de «permitir vacío»

No hay `run(bool $allowEmpty)`. Un catálogo vacío casi nunca es «todo vendido»: lo habitual es un fallo (productos sin
código de modelo, como te pasó en el staging de Grau; una lectura de la tienda que falla; una importación que deja mal los
productos). Con los distribuidores saliendo a producción, es el error más probable. Si algún día un distribuidor tiene de
verdad todo vendido, lo revisará una persona; la opción se añadirá solo si TUDOR explica cómo quiere recibir ese caso.

## Pregunta a TUDOR: abierta, sin prisa

Cómo comunicar «todo vendido» si un batch vacío da 400. Con este cambio nunca enviamos un batch vacío, así que no corre
prisa. Lo más probable es que el 400 del 13-10 fuera por el batch vacío y no por `PARTNER_NOT_FOUND`, que da 500 con
otro mensaje.

## Comprobación pendiente

Los tests del core lo cubren con un cliente HTTP falso. La comprobación con envío real a PREPROD (catálogo normal: mismos
enviados, 0 fallos, los mismos fuera) la haremos en el staging de Quera cuando llegue allí el cambio.
