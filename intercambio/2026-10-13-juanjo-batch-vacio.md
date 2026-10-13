# Para Jorge: batch vacío — se envía cuando el catálogo sale vacío, y TUDOR responde 400

13-10-2026, Juanjo. Visto en el staging de Grau (PrestaShop), credenciales PREPROD de Grau.

## Qué pasó

Al probar Run Sync Now con el módulo recién instalado (Feature "TUDOR Model Code" aún sin valores), el
conector devolvió `[]`. `SyncEngine::plan()` solo lanza `NothingPassedReviewException` si **hay** fichas
y no pasa ninguna; con `$catalog === []` devuelve `[]` y `run()` manda un **batch vacío**:

`POST /v1/stocks/batch` → **400** `{"detail":"Invalid request content","instance":"/stock/retailer/v1/stocks/batch","status":400,"title":"Bad Request"}`

Sin daño (Grau además da `PARTNER_NOT_FOUND` en PREPROD, ver `2026-10-13-juanjo-grau-partner-not-found.md`),
pero hay dos cosas que aclarar.

## 1. Fallo de configuración ≠ todo vendido

Un catálogo vacío puede ser legítimo (todos los TUDOR vendidos → TUDOR debe ponerlos a 0) o un fallo
(nadie ha rellenado el código de modelo, lectura rota…). En el segundo caso, si TUDOR aceptara el batch
vacío, pondría a 0 el catálogo entero del distribuidor.

- **PrestaShop (hecho):** antes de `run()`, si `plan() === []` **y** ningún producto de la tienda tiene
  código de modelo (`CatalogConnector::countEnrolledProducts() === 0`), no se llama a TUDOR y se avisa.
  Si hay relojes con código pero ninguno vendible, se envía como hasta ahora.
- **Magento (Quera, producción):** `SyncRunner` tiene el mismo hueco (snapshot vacío → `sendBatch()` →
  batch vacío). Lo arreglo yo en el staging de Quera, misma regla.
- **Propuesta para core (coordinar):** que `SyncEngine` no envíe nunca un batch vacío sin que el conector
  lo confirme, p. ej. una excepción `EmptyCatalogException` o un flag en `run(bool $allowEmpty = false)`.
  Así no depende de que cada módulo se acuerde.

## 2. ¿Cómo se pone todo a 0 si TUDOR no acepta un batch vacío?

TUDOR ha respondido **400 "Invalid request content"** al batch vacío. Si es así siempre (no solo por el
`PARTNER_NOT_FOUND` de Grau), "todo vendido" no se puede comunicar con un batch vacío: habría que mandar
los modelos con `value: 0` o borrar los stocks. ¿Lo puedes confirmar con TUDOR / probar en PREPROD con
Quera o con los `Demo-Retailers-*`?
