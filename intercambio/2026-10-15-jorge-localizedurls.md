# Para Juanjo: el core ya no manda nunca `localizedUrls` como `[]`

15-10-2026, Jorge. Respuesta a `2026-10-15-juanjo-batch-localizedurls-array-vacio.md` (commit `881949b`).

## Qué hace ahora el core

- `TudorApiClient::toStockCreatePayload()` convierte `localizedUrls` en objeto (`(object) $item->localizedUrls`) antes de
  codificarlo. Sin URLs por idioma sale **`"localizedUrls":{}`**; con URLs, el JSON es **exactamente el mismo que antes**
  (las claves son de texto, `es-ES`, así que ya salía como objeto).
- Vale para los dos caminos que usan ese método: el batch (`batchUpsertStocks`) y el envío suelto (`createStock`, el de la
  publicación en el minuto).
- No cambian constructores, `StockAvailability`, `CatalogConnectorInterface`, `AvailabilityFilter` ni `SyncEngine`. No hace
  falta `di:compile` ni tocar nada en los módulos.

## Por qué `{}` y no omitir el campo

Las dos dieron 200 en tu prueba, pero `{}` dice explícitamente «este reloj no tiene URLs por idioma». Si omitimos el campo,
no sabemos si TUDOR conservaría las URLs que tuviera guardadas de un envío anterior (p. ej. de antes de quitar un idioma
de la tienda); con `{}` no hay duda.

Y no es solo de WooCommerce: en cualquier módulo puede llegar vacío (tienda de un solo idioma, o la revisión de la ficha
quita todas las claves de idioma por incorrectas), y un solo reloj así tumbaba el batch entero.

## Tu arreglo en el plugin

Puede quedarse: mandar al menos el idioma del sitio (`{"es-ES": …}`) es mejor información para TUDOR que `{}`. El core
solo garantiza que, si aun así llega vacío, no rompe el envío.

## `storesAvailabilityDetails`

No lo he cambiado. Hoy solo se envía si no está vacío, así que nunca sale como `[]`. Con claves `RSWI_…` (el `stoId` real
de `/v1/point-of-sales`, p. ej. `RSWI_185580`) `json_encode` siempre escribe un objeto. Solo saldría como lista si un
módulo lo indexara con números consecutivos desde 0 (`0, 1, 2…`, p. ej. con `[] =` en vez de `[$stoId] =`); con ids
numéricos no consecutivos o en texto sale objeto. Ningún módulo lo rellena todavía: cuando se haga el click & collect por
tienda, la clave tiene que ser el `stoId`.

## Registro de prueba en PREPROD de Saphir

El `M79360N-0011/ES` que quedó del `createStock` de la prueba C se corregirá solo con el primer batch completo (el batch pone
a 0 lo que no lleva).

## Comprobado

Tests del core con `FakeHttpClient`, mirando el cuerpo real: batch y `createStock` con `localizedUrls` vacío → `{}`; con
URLs (`es-ES`, `en-US`) → cuerpo idéntico al de antes. No se ha enviado nada a TUDOR. La comprobación con envío real
(73 enviados, 0 fallos, los mismos 3 fuera) la haremos en el staging de Quera cuando llegue allí el cambio.
