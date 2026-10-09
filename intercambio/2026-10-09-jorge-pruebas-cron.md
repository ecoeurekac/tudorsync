# Pruebas con el cron activo (09-10-2026) — de Jorge para Juanjo

Staging de Quera contra PREPROD, core `8d16fcc` + módulo Magento `a3cd176`. Pruebas 22–28 de la
ronda Test-1: se cambia el stock en el admin y se deja que salten solos el envío del minuto y el de
cada hora, sin pulsar ningún botón.

## Resultado: funciona

- **Publicación en el minuto (pruebas 22–23):** `M79210CNU-0001` de 6 a 2 y vuelta a 6. En menos de un
  minuto sale solo ese reloj (`POST /v1/stocks` individual) y TUDOR lo refleja.
- **Sincronización de cada hora (prueba 24):** a las 11:00 y a las 12:00 (Madrid), 73 enviados, 3
  descartados por la lista de modelos vigentes, 0 fallos.
- **Reloj fuera de la lista (pruebas 25, 27 y 28):** `M79030N-0002` no se envía y sigue a 0 en TUDOR.
  La publicación del minuto lanza un batch completo (73). Con un reloj válido y otro fuera de la lista
  en la misma cola, también va un batch completo (en los dos órdenes), y el válido sale dentro de ese
  batch.
- **Lista que falla (prueba 26):** con `prices_ES.xlsx` renombrado, `M79030N-0002` se publica solo (2
  en TUDOR): sin lista no se filtra, como se decidió.

Al terminar, PREPROD quedó como al principio (`M79210CNU-0001` a 6, `M79030N-0002` a 0) y
`prices_ES.xlsx` está otra vez en su sitio.

## Decisión: se mantiene el batch completo

Cuando un modelo de la cola no está en la lista de modelos vigentes, la publicación del minuto manda
el batch completo. **Es una decisión tomada, no una mejora pendiente**, y queda así por precaución:

- Si un modelo ya publicado sale de la lista (por ejemplo, con una lista de precios nueva), se retira
  en ese mismo minuto, sin esperar a la sincronización de cada hora.
- Un modelo válido de la misma cola sale dentro de ese batch, en el mismo minuto.
- El coste es un batch de unos 76 modelos, y solo cuando cambia un modelo de fuera de la lista.

Se revisará solo si el registro de la API muestra muchos batches de este tipo en algún distribuidor
(riesgo de 429). **Sustituye a la «mejora opcional» de ignorar en la cola los modelos fuera de la
lista: no hace falta hacerla.**

Comprobado en el código (`modules/magento/Model/SyncRunner.php`, `a3cd176`): `planPending()` (líneas
134–173) toma como disponibles los modelos de `getItemsToSend()`, que son los que salen de
`AvailabilityFilter::keepOnlyAvailable()` (`CatalogReview.php:39`). Un modelo de la cola que no está
ahí cuenta como `$unavailable` (línea 156) y, en ese caso, `fullBatch` es verdadero para toda la cola
(línea 170), incluidos los válidos. En el log sale como «1 model(s) no longer available: a full batch
withdraws them».

## Petición: avisar en el log de la publicación del minuto cuando la lista está desactivada

Con la lista rota, la sincronización completa escribe en `var/log/tudorsync.log` el aviso de core
(«Filtro de modelos vigentes DESACTIVADO: …»; p. ej. el 08-10 a las 17:59 UTC). La publicación del
minuto no lo escribe: el 09-10 a las 09:24 UTC solo aparece «Real-time: 1 model(s) published
(M79030N-0002)».

El motivo: `doPublishPending()` llama a `logReview()` solo en la rama del batch completo (línea 220).
En la de envíos sueltos los avisos están en `$plan->snapshot->review->warnings`, pero no se registran.

Lo que pido: que la publicación del minuto registre ese aviso en `var/log/tudorsync.log` también
cuando envía modelos sueltos, igual que la sincronización completa. Como corre cada minuto, quizá
convenga registrar solo los avisos (no la lista de descartados) o limitar cuántas veces se repite;
lo dejo a tu criterio.

## Petición: que el log del batch del minuto diga el motivo real

Cuando el batch completo del minuto lo provoca un modelo fuera de la lista de modelos vigentes, el
log dice «1 model(s) no longer available: a full batch withdraws them» (línea 159), aunque el reloj
tenga stock (p. ej. `M79030N-0002` el 09-10 a las 09:20, 09:22 y 09:26 UTC). Lo pido: que el texto
distinga el motivo, por ejemplo «fuera de la lista de modelos vigentes» frente a «sin stock o
descartado en la revisión». Los motivos de core ya están en `$snapshot->review->exclusions`
(`Rules\Exclusion`, con `reason`; el de la lista es `Exclusion::NOT_IN_VALID_LIST`).

## Recordatorio para producción (antes del 19-10)

- **«Sync Frequency»** = `0 * * * *` (cada hora).
- **«Publish Changes Within a Minute»** = Sí.

En el staging están ahora en `0 0 31 2 *` (31 de febrero, nunca se ejecuta) y No.
