# Revisión de la ficha antes de enviar (Jorge → Juanjo, 08-10-2026)

Sustituye al control simple de `AvailabilityFilter` (`onlinePurchaseEnabled && value > 0`).
**Actualiza la petición de `2026-10-08-jorge-lista-modelos-validos.md`**: ahora hay más motivos
de descarte que «no está en la lista».

## Los cuatro pasos de `keepOnlyAvailable()`

1. **Normalizar** `mc` y `country`: sin espacios (también el espacio duro) y en mayúsculas. Si
   no cambia nada, se devuelve el mismo objeto.
2. **Descartar lo que no se puede publicar**: `mc` vacío; país que no es de dos letras;
   `value <= 0` o `onlinePurchaseEnabled = false`; `defaultUrl` vacío, mal formado o que no
   empieza por `https://`. Las claves de `localizedUrls` se normalizan (`es_ES` → `es-ES`:
   guion, idioma en minúsculas, región en mayúsculas) y tienen que ser BCP 47 / ISO 639-1, sin
   lista cerrada de idiomas: idioma de 2 o 3 letras (`es`, `ca`, `ast`), opcionalmente una
   escritura de 4 letras (`zh-Hant`) y/o una región de 2 letras o 3 cifras (`es-ES`, `fr-CH`,
   `es-419`). Una entrada con URL mala, clave no válida o clave repetida tras normalizar se
   quita **sola**, sin descartar el reloj, y queda como aviso.
3. **Un registro por mc + país**: si llegan varios, `value` = suma; el resto de campos, del de
   más `value` (si empatan, el primero); orden de la primera aparición; aviso con el modelo y
   las veces.
4. **Lista de modelos vigentes** (`prices_ES.xlsx`), igual que en `e6a9a81`.

Contrato sin cambios: mismos constructores (`new AvailabilityFilter()` sigue valiendo) y la
misma forma de `StockAvailability`. No hace falta `di:compile`.

## Cómo leer los motivos (misma instancia del filtro, última llamada)

```php
$filter->getExclusions();          // list<Tudorsync\Core\Rules\Exclusion>: cada reloj descartado
//   ->modelCode, ->country        (normalizados)
//   ->reason                      missing_model_code | invalid_country | not_available | invalid_url | not_in_valid_list
//   ->message                     texto en español para mostrar, p. ej. "Enlace no válido: debe empezar por https://"
$filter->getWarnings();            // list<string>: enlaces de idioma quitados, repetidos agrupados, lista desactivada
$filter->getExcludedModelCodes();  // igual que antes: solo los quitados por la lista
```

## Nueva excepción en `SyncEngine::run()`

`Tudorsync\Core\Sync\NothingPassedReviewException`: el conector ha dado fichas pero **ninguna**
ha superado la revisión. **Significa «no se ha enviado nada»** (ni siquiera se pide token): un
batch vacío pondría a 0 todo el catálogo en TUDOR. El mensaje ya lleva el resumen
(«Ningún reloj ha superado la revisión: no se envía nada para no vaciar el catálogo en TUDOR (3
fichas recibidas; 2 × Enlace no válido: …)») y tiene `getReceived()` y `getExclusions()`. En
`SyncRunner::doRunSync()` hoy cae en el `catch (Throwable)` → «Sync failed: …», que vale, pero
conviene que el panel lo muestre como «no se ha enviado nada» y con los motivos. Si el conector
devuelve una lista vacía de verdad (todo agotado), se envía el batch vacío como hasta ahora.

## Publicación en el minuto (`planPending()`)

Indexa por `modelCode` lo que devuelve el filtro. Tras la normalización el `mc` sale en
mayúsculas y sin espacios: si el módulo diera un código distinto que el de la cola, ese modelo
contaría como «no disponible» y forzaría un batch completo (no rompe, pero no es lo que se
quiere). **En Quera no pasa hoy:** de las 76 fichas del staging, 0 tienen el `mc` sin
normalizar. Para que no pase nunca (p. ej. un código metido a mano en minúsculas), conviene
normalizar también en la cola (`ValidModelList::normalize()`) o al guardar el atributo.

## Prueba en el staging (08-10, ~11:42 UTC, PREPROD)

- En seco: 76 fichas → 73; descartes solo los 3 `not_in_valid_list` (`M25707B/25-0001`,
  `M25807KN-0001`, `M79030N-0002`); 0 avisos; todas las URL `https://` (304 de idioma con
  claves `es-ES`, `en-GB`, `fr-FR`, `ca-ES`); 0 repetidos; los 73 objetos son los mismos que dio
  el módulo (sin copias).
- `tudorsync:sync:run`: 73 resultados, 0 fallos; `getStocks()`: 73 con valor y los 3 a 0.

## Petición para tu lado (sustituye a la de la lista)

Que se vea **cada reloj descartado con su motivo** (`getExclusions()`) **y los avisos**
(`getWarnings()`) en:

1. **`tudorsync:catalog:preview`** y su `--json`.
2. **El panel de Magento**, junto al resultado de la última sincronización (y, si salta
   `NothingPassedReviewException`, como «no se ha enviado nada» con los motivos).
3. **`var/log/tudorsync.log`** en cada sincronización completa (descartes y avisos como
   `warning`; la excepción como `error`).

Y, como ya decía la nota anterior, que «N model(s) sent» cuente lo que se envía de verdad.
