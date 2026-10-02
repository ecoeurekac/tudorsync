# Para Juanjo (módulos): respuesta a la publicación en el minuto y siguiente prueba en PREPROD

02-10-2026, Jorge. Respuesta a `2026-10-02-juanjo-peticion-publicacion-en-el-minuto.md` y a la
pregunta final de `2026-10-02-juanjo-prueba-preprod-oauth.md`. Gracias por probar el OAuth.

## Tus tres puntos

1. **`POST /v1/stocks` actualiza un `mc` existente:** sí. Lo dice la doc v2 (Community Asset)
   y lo viste en tu prueba (`version` 0 → 1 → 2). No hay que cambiar nada en core; lo
   confirmamos del todo en la prueba conjunta de abajo.
2. **`createStock()` y el `status`:** cambiado en `fd9db80`. **201 → `CREATED`, 200 →
   `UPDATED`** (cualquier otro 2xx sigue como `CREATED`; lo que no es 2xx ya es excepción).
   Con tests para los dos casos. No cambia la firma.
3. **Docblock de `createStock()`:** actualizado en el mismo commit: crea o actualiza, y es de
   uso normal (publicación casi en tiempo real), no solo para correcciones.

## Antes de conectar el catálogo real: batch solo con Demo-Retailers

Sí, quiero esa prueba primero. **No conectes `clientId`/`clientSecret` en
`Model\Config::getClientConfig()` todavía:** en cuanto lo hagas, los crons de cada hora y de
cada minuto publican los 77 modelos de Quera. Hazla con un script aparte, como la de hoy.

En PREPROD, con `Demo-Retailers-001…005` (country ES) y nada más:

- [ ] `batchUpsertStocks()` con los cinco: responde 200 (o 207 si alguna línea falla) y el
      resultado se lee **línea a línea** (`BatchSyncResult::results` / `failures()`, con `mc`,
      `country`, `status` y `message`). Si puedes, fuerza una línea mala (p. ej. un `value`
      negativo) para ver un 207 y un `FAILED` de verdad.
- [ ] `createStock()` de un `mc` que **no existe** → código HTTP esperado **201** (`CREATED`).
- [ ] `createStock()` del mismo `mc` otra vez → código HTTP esperado **200** (`UPDATED`).
      Apunta los códigos que veas: es lo único del cambio de hoy que sale de la OAS y no de una
      prueba.
- [ ] `getStocks()` al final, para ver qué queda publicado.

Recuerda que el batch pone a 0 todo lo que no lleva; en PREPROD ahora mismo solo está
`Demo-Retailers-001`, así que no afecta a nada más. Deja el resultado en `intercambio/`.

## Después

1. Si todo cuadra: conecta las credenciales en `getClientConfig()`, quita el aviso «OAuth
   pending in core» de `SyncRunner::getCredentialsProblem()` y deja que publique el catálogo
   real de Quera **en PREPROD**.
2. **Antes de PROD:** revisar con Quera los 35 códigos de modelo que salen de la regla de SKU.

## Para hablar sobre la publicación en el minuto

1. **setup:upgrade (db_schema.xml):** ¿lo has ejecutado ya?
   - Si es que sí: comprueba con Bea que la tienda funciona bien, sobre todo los pedidos (por
     el aviso de CLAUDE.md sobre `sales_sequence_meta`).
   - Si es que no: antes de ejecutarlo, coordínalo con Bea, porque creo que la idea es subir la
     tienda a producción el lunes, y hay que asegurarse de que no se ha roto nada.

2. **Frecuencia y tiempo real:** Carlos y yo habíamos pensado que lanzar una sincronización
   completa cada 15 minutos podría ser suficiente.
   Sobre tu publicación en el minuto: ¿cómo funciona exactamente? Si solo mira una cola de
   cambios y envía únicamente los relojes que han cambiado, me parece buena idea, y entonces
   quizá podríamos alargar las sincronizaciones globales a 1 hora, por ejemplo (porque los
   cambios en un modelo vendido se estarían reflejando casi en tiempo real). ¿Ese cron que
   corre cada minuto mirando cambios en los relojes TUDOR puede ser algo que afecte al
   rendimiento de la tienda, o es una tarea ligera que puede correr sin problema?

3. **Límite de peticiones:** la doc de TUDOR menciona el código 429 (demasiadas peticiones).
   Hay que tenerlo en cuenta: ¿qué hace tu código si TUDOR responde 429? Si hace falta algo en
   core para gestionarlo, dímelo y lo preparo.
