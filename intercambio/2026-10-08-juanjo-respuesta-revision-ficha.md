# Revisión de la ficha: hecho en el módulo (Juanjo → Jorge, 08-10-2026)

Respuesta a `2026-10-08-jorge-revision-ficha.md` (y a la de la lista). Commit `a3cd176`, sin tocar core.

## Lo que pediste

1. **`tudorsync:catalog:preview`**: debajo de las tablas, cada descarte con su motivo y los avisos; el
   resumen dice «73 record(s) to send (76 built, 3 left out by the review)». En `--json`, `records` son
   los que pasan y hay un bloque `review` con `received`, `passed`, `exclusions` (`mc`, `country`,
   `reason`, `message`) y `warnings`.
2. **Panel**: en la configuración (bajo «Last sync», también justo después de «Run Sync Now») y en
   «Datos del programa», los descartes y avisos de la última sincronización. En el catálogo de esa
   página, los productos descartados salen como «TUDOR review: <tu mensaje>».
   `NothingPassedReviewException` se recoge aparte: «No se ha enviado nada: ningún reloj ha superado
   la revisión (N recibidos)…».
3. **`var/log/tudorsync.log`**: cada descarte y cada aviso como `warning` en cada batch completo
   (sync y tiempo real cuando cae a batch); la excepción como `error`.
4. **«N model(s) sent»** cuenta lo que pasa la revisión: «73 modelo(s) enviado(s), 3 descartado(s) en
   la revisión, 73 resultado(s), 0 fallo(s)» (Run Sync Now, 12:24 UTC, PREPROD).
5. **Cola normalizada**: `planPending()` compara con `ValidModelList::normalize()` (probado con
   `m79360n-0013` en minúsculas: se reconoce).

Las pruebas de API del admin también pasan por la revisión. El batch del catálogo completo va por
`SyncEngine`, y los productos elegidos se revisan antes de enviarlos.

## Para que lo sepas (no pido cambio)

Con **uno o dos productos**, tu protección «si la lista dejara al país sin relojes, se desactiva» salta
casi siempre. Por eso, en las pruebas con productos elegidos el módulo aplica `ValidModelList` a cada
uno cuando la lista está cargada. Lo vimos porque, antes de ese ajuste, un `POST /v1/stocks` de prueba
envió `M79030N-0002` con valor 1 a PREPROD (12:21 UTC). El Run Sync Now de las 12:24 lo volvió a poner
a 0 (no iba en el batch). En la sync completa y en el tiempo real no pasa, porque se revisa el catálogo
entero.
