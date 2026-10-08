# Filtro por lista de modelos vigentes de TUDOR (Jorge → Juanjo, 08-10-2026)

## Qué hace

`AvailabilityFilter::keepOnlyAvailable()` quita ahora, además de lo no disponible, los relojes
cuyo TMC no está en la lista de precios de TUDOR de su país
(`core/resources/valid-models/prices_ES.xlsx`, el archivo de TUDOR tal cual; clase nueva
`Tudorsync\Core\Rules\ValidModelList`). Comparación sin espacios y en mayúsculas.

- Se aplica a los dos caminos sin tocar el módulo: la sincronización completa (`SyncEngine`) y
  la publicación en el minuto (`SyncRunner::planPending()`). **En la cola, un TMC fuera de la
  lista se trata como «no disponible»**: entra en `$unavailable` y provoca un batch completo.
- Un reloj fuera de la lista no se envía con 0: no se envía (TUDOR lo pone a 0).
- Contrato sin cambios: `new AvailabilityFilter()` sin argumentos sigue funcionando (parámetro
  opcional `?ValidModelList $validModels = null`), ni `StockAvailability` ni
  `CatalogConnectorInterface` cambian. No hace falta `di:compile` (el módulo crea el filtro con `new`).

## Si la lista falla, el filtro se desactiva solo

Si `prices_XX.xlsx` no existe, no se puede leer, no tiene columna TMC o tiene menos de 100
modelos (o dejaría a un país sin ningún reloj), ese país se envía **sin filtro**, como antes.
Nunca se envía un catálogo vacío por culpa de la lista.

Para saberlo, después de llamar a `keepOnlyAvailable()` (o a `SyncEngine::run()`) con **la
misma instancia** del filtro:

```php
$filter->getWarnings();            // list<string>, p. ej. "Filtro de modelos vigentes DESACTIVADO: no se encuentra prices_ES.xlsx"
$filter->getExcludedModelCodes();  // list<string>, TMC quitados por no estar en la lista (ordenados)
```

Ambos describen la última llamada. Hoy el módulo crea el filtro dentro de `new SyncEngine(...)`
y en `planPending()` sin guardarlo, así que **esos avisos no llegan todavía a ningún sitio**.

## Prueba en el staging (08-10, ~10:25 UTC, PREPROD)

- `tudorsync:sync:run`: **73 enviados, 0 fallos** (antes 76).
- TMC quitados por el filtro: **`M25707B/25-0001`, `M25807KN-0001`, `M79030N-0002`**.
  `getStocks()`: los tres a 0 en PREPROD; 73 con valor > 0 (82 registros en total con los 6 demo).
- Con `prices_ES.xlsx` renombrado (prueba en seco, sin enviar): 76 pasan y aviso
  «Filtro de modelos vigentes DESACTIVADO: no se encuentra prices_ES.xlsx». Archivo restaurado.
- ⚠️ El mensaje del módulo dice «76 model(s) sent, 73 result(s)»: cuenta `$snapshot->items`
  antes del filtro. Ahora los enviados son 73.

**Revisa con Quera `M25707B/25-0001`:** en la lista de TUDOR solo está `M25707B/26-0001`. ¿Es la
edición /26 mal codificada en la ficha?

## Petición para tu lado (la necesito para mis pruebas)

Que los TMC excluidos por no estar en la lista, y el aviso de filtro desactivado, se vean en:

1. **`tudorsync:catalog:preview`**, con el motivo «no está en la lista de modelos vigentes de
   TUDOR», también en la exportación `--json`.
2. **El panel de Magento**: junto al resultado de la última sincronización, el aviso de filtro
   desactivado (si lo está) y la lista de TMC excluidos.
3. **`var/log/tudorsync.log`**, en cada sincronización completa (aviso como `warning`; lista de
   excluidos como `info`).

Propuesta mínima en `SyncRunner::sendBatch()` (y lo mismo en `planPending()`):

```php
$filter = new AvailabilityFilter();
$engine = new SyncEngine(new SnapshotConnector($snapshot), $filter, new TudorApiClient($clientConfig, $this->httpClient));
$result = $engine->run();
// después: $filter->getWarnings() → logger->warning + resultado; $filter->getExcludedModelCodes() → log/panel
```

Y corregir el «N model(s) sent» para que cuente lo que realmente se envía.
