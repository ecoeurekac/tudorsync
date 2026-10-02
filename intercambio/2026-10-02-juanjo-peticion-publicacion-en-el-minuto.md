# Para Jorge (core): publicación en el minuto con `createStock()`

02-10-2026, Juanjo. Lo que hace Magento y lo poco que necesito de core.

## Por qué

TUDOR insiste en «Real-Time synchronization» (presentación del programa, p. 10; «Primeros pasos»,
p. 2). Con el sync cada hora, un reloj de pieza única vendido sigue anunciado en tudorwatch.com
hasta 59 min.

## Hecho en el módulo Magento (sin tocar core)

- Hooks **solo de productos TUDOR** (código de modelo por la misma regla del sync): reservas MSI
  (pedido hecho/cancelado/enviado/abonado), stock guardado por MSI (admin, ERP), ficha guardada o
  borrada. Encolan el código de modelo en `tudorsync_pending_stock`; nunca llaman a TUDOR durante
  la compra.
- Cron cada minuto:
  - todos los modelos de la cola siguen disponibles → **`TudorApiClient::createStock()`** una vez
    por modelo (máx. 20; si hay más, batch);
  - alguno ya no está disponible → **`batchUpsertStocks()` completo**, porque el batch pone a 0 lo
    que falta (documentado). Así no necesitamos mandar `value: 0` por `POST /v1/stocks`.
- El sync completo cada hora sigue como red de seguridad.
- Construyo el cliente igual que hoy: `new TudorApiClient($clientConfig, $httpClient)`, uno por
  ejecución.

## Lo que necesito de core

1. **Confirmar en PREPROD, cuando esté el OAuth, que `POST /v1/stocks` actualiza un `mc` que ya
   existe** (no da 400/409). La OAS dice «Create a new stock object»; la presentación técnica
   (p. 9) dice «create or update a stock item, automatically versioned». Si no actualiza, aviso y
   paso a mandar siempre el batch.
2. `createStock()`: con tu cambio un no 2xx ya es excepción (bien). Queda el `status: 'CREATED'`
   fijo: si TUDOR responde 200 a una actualización, ¿lo devuelves como `UPDATED` o lo dejamos así?
   No lo uso para nada más que el log.
3. ~~Reutilizar el token entre llamadas~~ y ~~firma del constructor~~: respondido en tu
   `2026-10-02-jorge-oauth-listo.md` (token en memoria, `new TudorApiClient($config, $http)` igual).
4. El docblock de `createStock()` dice «one-off corrections»: ahora será uso normal. Cámbialo si te
   parece.

**Opcional, más adelante:** si TUDOR acepta `value: 0` por `POST /v1/stocks`, un modelo agotado
podría retirarse sin batch completo. No hace falta para el lanzamiento.
