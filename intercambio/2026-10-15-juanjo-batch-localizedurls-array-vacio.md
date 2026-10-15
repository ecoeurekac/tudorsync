---
para: Jorge (core)
de: Juanjo (WooCommerce)
tema: POST /v1/stocks/batch da 400 cuando localizedUrls va vacío (se serializa como [] y TUDOR espera objeto)
fecha: 2026-10-15
---

# Batch 400 "Failed to read HTTP message" con `localizedUrls` vacío

## Qué pasa
En el smoke test real contra PREPROD de Saphir (WooCommerce, OAuth2 OK, 25 relojes en el batch),
`TudorApiClient::batchUpsertStocks()` devuelve:

```
ApiResponseException: HTTP 400 on POST /v1/stocks/batch:
{"detail":"Failed to read HTTP message","instance":"/stock/retailer/v1/stocks/batch","status":400,"title":"Bad Request"}
```

GET /point-of-sales, GET /health, GET /stocks y el token funcionan (200). Solo falla el POST con cuerpo.

## Causa
`toStockCreatePayload()` incluye `'localizedUrls' => $item->localizedUrls`. En Saphir ese array va
**vacío** (WooCommerce sin plugin multiidioma; `localizedUrls: []` por defecto en el conector). Un
array PHP vacío se serializa como **`[]` (array JSON)**, pero el DTO de TUDOR espera un **objeto/map**.
En Magento no se vio porque siempre lleva URLs por idioma (multi-store), así que nunca va vacío → `{}`.

## Repro aislado (mismo token, 3 cuerpos, un único registro válido M79360N-0011/ES) contra PREPROD
```
A  "localizedUrls":[]   -> HTTP 400  {"detail":"Failed to read HTTP message", ...}
B  "localizedUrls":{}   -> HTTP 200  {"mc":"M79360N-0011","country":"ES","status":"CREATED", ...}
C  (campo omitido)      -> HTTP 200  {"mc":"M79360N-0011","country":"ES","status":"UPDATED", ...}
```
Enviado por cURL crudo (descarta que sea el cliente HTTP de WooCommerce). B y C funcionan; A no.

## Fix propuesto (en core, `TudorApiClient::toStockCreatePayload`)
Serializar `localizedUrls` siempre como objeto, u omitirlo cuando está vacío. Dos opciones:

1. Omitir si vacío:
   ```php
   if ($item->localizedUrls !== []) {
       $payload['localizedUrls'] = $item->localizedUrls;
   }
   ```
2. Forzar objeto:
   ```php
   'localizedUrls' => (object) $item->localizedUrls,
   ```
   (con un array no vacío, `(object)` mantiene las claves string → objeto JSON; con vacío → `{}`.)

Me vale cualquiera; la 1 deja el JSON más limpio. Afecta también a `createStock()` (POST /v1/stocks,
usado por la publicación en el minuto), que comparte `toStockCreatePayload`.

## Nota
Durante el repro quedó 1 registro creado en PREPROD de Saphir (M79360N-0011/ES) por el POST /v1/stocks
suelto de la prueba C. Se sobrescribe con el primer batch completo cuando el fix esté (el batch pone a 0
lo que no lleva). No he tocado core.
