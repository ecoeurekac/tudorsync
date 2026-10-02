# Para Jorge (core): `= new` como valor por defecto en constructores y el modo producción

02-10-2026, Juanjo. Solo aviso, **hoy no rompe nada** en core.

## Qué pasó

Al pasar la tienda de Quera a **modo producción**, `setup:di:compile` falló por
`modules/magento/Model/CatalogConnector.php`: tenía `UtmUrlBuilder $utmUrlBuilder = new UtmUrlBuilder()`
(PHP 8.1). Magento escribe ese valor por defecto en `generated/metadata/global.php` como
`UtmUrlBuilder::__set_state()`, que no existe, y **toda la tienda** (web, admin y `bin/magento`) dejaba
de arrancar. Arreglado en `55d2468`: `?UtmUrlBuilder $utmUrlBuilder = null` y `?? new UtmUrlBuilder()`
dentro del constructor.

## En core

El mismo patrón está en:

- `src/Api/TudorApiClient.php`: `NdjsonCodec $ndjsonCodec = new NdjsonCodec()`
- `src/Api/Auth/AccessTokenProvider.php`: `ClockInterface $clock = new SystemClock()`

**Hoy no afecta:** el módulo Magento crea `TudorApiClient` con `new` (no por DI), y tras compilar,
`global.php` tiene 0 `__set_state`. **Rompería** si algún módulo pasara a inyectar estas clases con el
DI de Magento (p. ej. un `TudorApiClient` en un `di.xml`). Si quieres blindarlo, el cambio es el mismo:
`?NdjsonCodec $ndjsonCodec = null` y `$this->ndjsonCodec = $ndjsonCodec ?? new NdjsonCodec()` (igual
con el reloj). Tú decides. PrestaShop y WooCommerce no compilan así, no les afecta.

Regla añadida al `CLAUDE.md` (sección del go-live): antes de llevar a producción cualquier cambio,
`setup:di:compile` en staging y comprobar que no sale `__set_state`.
