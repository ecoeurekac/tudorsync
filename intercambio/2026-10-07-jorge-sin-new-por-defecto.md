# Para Juanjo: core ya no tiene objetos como valor por defecto en constructores

07-10-2026, Jorge. Respuesta a `2026-10-02-juanjo-aviso-defaults-objeto-core.md`.

## Qué cambia

Los dos únicos casos de `core/src` (lo comprueba el test nuevo), con el patrón de `CLAUDE.md`:

- `TudorApiClient`: `NdjsonCodec $ndjsonCodec = new NdjsonCodec()` → `?NdjsonCodec $ndjsonCodec = null`
  y `$this->ndjsonCodec = $ndjsonCodec ?? new NdjsonCodec()`.
- `AccessTokenProvider`: `ClockInterface $clock = new SystemClock()` → `?ClockInterface $clock = null`
  y `$this->clock = $clock ?? new SystemClock()`.

**El comportamiento es el mismo:** mismos parámetros, en el mismo orden y con el mismo nombre; quien no pasa
nada sigue recibiendo el `NdjsonCodec` / `SystemClock` de siempre. Los 39 tests anteriores pasan sin tocarlos.
A partir de ahora estas clases **se podrían inyectar por DI de Magento** sin romper `di:compile`.

## Test de protección

`core/tests/ConstructorDefaultsTest.php` recorre todas las clases de `core/src` y falla si algún parámetro de
constructor tiene un objeto como valor por defecto (los enums están permitidos). Contra el código anterior
fallaba con los dos casos de arriba. `composer test`: 41 tests en verde.

## Compilación en el staging (07-10, ~11:17–11:21 UTC)

- **El primer `setup:di:compile` falló al empezar** («generated/code/Magento cannot be deleted… Directory not
  empty»), seguramente porque algo (cron o una petición) escribía a la vez en `generated/`. Dejó `generated/code`
  a medio borrar unos 3 minutos. El segundo intento salió bien (rc=0). Si viste algún error en el staging a esa
  hora, era eso. No hay líneas nuevas en `exception.log`.
- `grep -c __set_state generated/metadata/global.php` → **0**.
- `tudorsync:catalog:preview` → **76** registros (igual que el 06-10).
- `tudorsync:connection:test` → conectado a PREPROD, **4** puntos de venta.
- `tudorsync.log` sin líneas nuevas; en `system.log`, solo el INFO de siempre `adapt_stock_status_filter`.

Para producción: `git pull` y el procedimiento habitual (`di:compile` + `static-content:deploy` + `cache:flush`).
No hace falta `setup:upgrade`.

## Recomendación para producción

Por lo que pasó en el primer intento, **compilar en producción con la tienda en mantenimiento y los crons pausados**,
para que nadie (cliente o cron) escriba en `generated/` mientras se borra y para que, si falla, ningún cliente vea la
tienda a medias:

1. Pausar los crons de Magento.
2. `bin/magento maintenance:enable`
3. `setup:di:compile` (+ `static-content:deploy` si toca) y comprobar `grep -c __set_state generated/metadata/global.php` = 0.
4. `cache:flush`
5. `bin/magento maintenance:disable` y reactivar los crons.
