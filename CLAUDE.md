# CLAUDE.md — tudorsync

Guía para Claude Code (y para quien se sume) al trabajar en este monorepo. El "por qué" y la
cronología están en [`CONTEXT.md`](CONTEXT.md); el detalle técnico en [`README.md`](README.md).
Léelos antes de tocar código.

## Qué es

Tudorsync conecta el stock online de 5 distribuidores TUDOR (Quera y Pedro Luis Olivares en
Magento, Grau en PrestaShop, Gordillo y Saphir en WooCommerce) con el botón "Comprar ahora" de
tudorwatch.com, vía la "e-Stock Retail Publish API" v1.7.0. Arquitectura core + adaptadores,
despliegue híbrido (cada módulo corre dentro de la tienda del cliente, sin servidor central).

## Equipo y reparto de trabajo

La cuenta de Claude Code es **compartida** entre varias personas: no asumas quién habla; si no
se identifica, pregunta. Ambos hablan español. Reparto según
`doc/Tudorsync Arquitectura y Reparto de Trabajo.docx` (29-09-2026, Carlos):

- **Juanjo — conectores** (`modules/magento`, `modules/prestashop`, `modules/woocommerce`).
  Orden: Quera (Magento, ya instalado aquí) → PLO → Grau → Gordillo/Saphir. Tareas: confirmar
  con cada cliente el mapeo producto → código de modelo TUDOR (hoy campo nuevo
  `tudor_model_code`), puesta en marcha en staging, ajustar la exclusión "bajo demanda" al
  catálogo real, probar Test Connection / Run Sync Now end-to-end, y luego PrestaShop y
  WooCommerce (incluido el plugin de idiomas).
- **Jorge — core y TUDOR** (`core/`). Conseguir de TUDOR URL base, esquema de auth, semántica
  de `value` y si RSWI = `stoId`; actualizar `TudorApiClient`; fuente de datos del informe
  mensual (analítica + pedidos → `MonthlyReportWriter`); mantener tests; más adelante,
  distribución del paquete (Packagist privado / Satis).

### Regla de propiedad del código (obligatoria)

- **Solo la sesión de Jorge modifica `core/`.** Solo la sesión de Juanjo modifica `modules/*`.
- Cada uno puede **leer** la parte del otro, pero **nunca editarla** (ni Edit/Write, ni `sed`,
  ni scripts, ni "arreglos rápidos"). Si hace falta un cambio en la parte ajena, redacta la
  petición (qué, por qué, propuesta de firma/diff) y déjala en `intercambio/` para el otro.
- **Antes de editar cualquier archivo de `core/` o `modules/`, confirma quién habla.** Si no se
  ha identificado en la sesión, pregunta y no edites hasta saberlo.
- **`intercambio/`** es la carpeta neutral: datos de prueba (p. ej. salidas reales de un
  `CatalogConnector`, respuestas de la API de TUDOR), peticiones de cambio y notas entre los
  dos. Ambos pueden escribir ahí; nombra los archivos con fecha y autor
  (`2026-09-30-juanjo-plan-conectores.md`). **Los JSON van como `AAAAMMDD_retailer.json`**
  (`20260930_quera.json`).
- `CLAUDE.md`, `CONTEXT.md`, `README.md` y `doc/` son comunes.
- Ambos trabajan en **este mismo checkout** del servidor. **Todo cambio va en un commit** en cuanto
  esté hecho y verificado, seguido de `git push` (el 30-09 se perdió el checkout entero con
  trabajo sin commit). En la sesión de Juanjo, commit + push siempre, sin preguntar; la sesión de
  Jorge sigue su propio criterio. El servidor empuja con una deploy key (ver `CLAUDE.local.md`).
- **Commit solo de lo ya probado:** un push sube también los commits locales del otro, así que no
  se hace commit de nada que aún se esté depurando. Y como el checkout es el mismo, `git add` solo
  de las rutas propias (nunca `git add -A` / `git commit -a` en la raíz): así no entra el trabajo
  sin terminar del otro.
- **Prefijo de los mensajes de commit:** `Nombre - [Parte V1] - descripción`, p. ej.
  `Jorge - [Core V1] - …` o `Juanjo - [Magento V1] - …` (`[Docs]` para docs e `intercambio/`).
  Sirve para localizar en GitHub lo que ha tocado cada uno.
- ⚠️ `vendor/tudorsync/{core,module-magento}` son **symlinks** a este checkout: no borrar nada
  dentro de ellos por SFTP (borra el original).

**Contrato compartido:** `CatalogConnectorInterface` (devuelve `StockAvailability`) y
`TudorApiClient`. Cualquier cambio en esas piezas o en la forma de `StockAvailability` requiere
coordinar a los dos: con Juanjo, marca los impactos en core como "coordinar con Jorge", y al revés.

Sin dueño asignado todavía: MSI en Magento, click & collect por punto de venta (RSWI), CI,
cron de servidor de Grau.

Documentos de trabajo en `doc/`: `Tudorsync Estado y Pendientes(1).pdf` (estado y lista de
pendientes) y `Tudorsync Arquitectura y Reparto de Trabajo.docx` (reparto). El resto de `doc/`
es documentación oficial de TUDOR (PDF de onboarding, spec OAS/RAML, plantillas Excel).

## ⚠️ Desde el go-live del lunes 05-10-2026: esta instalación es PRODUCCIÓN

Acordado con Bea el 02-10. La instalación de Magento donde vive este checkout pasa a ser la tienda
**real** de Quera. Cloudways creará después una aplicación **staging** en este mismo servidor.

- **Todo el desarrollo y las pruebas de Tudorsync, incluidos sus crons, se hacen en el staging.** En
  producción no se edita nada de `packages/tudorsync` (es código en vivo de la tienda real): solo
  `git pull` de lo ya probado en staging.
- **Credenciales de TUDOR:** el staging, solo PREPROD; producción, solo PROD y únicamente cuando esté
  todo validado (España se activa el 19-10). **Nunca las mismas en las dos**: cada batch pone a 0 lo
  que no lleva, y las dos instalaciones se pisarían el catálogo cada hora.
- **Producción hasta entonces:** credenciales sin conectar y `realtime_enabled = 0` (lo de cada hora y
  lo de cada minuto se saltan sin hacer nada). La captura de la UTM de tudorwatch.com en los pedidos sí
  funciona, y es lo que se quiere desde el primer día.
- **Al crear el staging:** la copia lleva el checkout, pero comprobar la deploy key
  (`private_html/.ssh-tudorsync` y `core.sshCommand`) para poder hacer push. Apagar ahí PQnetStock y los
  crons del ERP si Bea no dice lo contrario: con las dos instalaciones activas, los pedidos de prueba
  descontarían stock real.
- **Desde el 02-10 Magento está en modo PRODUCCIÓN** (código compilado en `generated/`). Consecuencias:
  - Antes de llevar a producción **cualquier** cambio de `modules/magento` (o de `core/` que use el
    módulo), lanzar `bin/magento setup:di:compile` en el staging, comprobar que
    `grep -c __set_state generated/metadata/global.php` da 0 y pasar `tudorsync:catalog:preview`.
  - En producción, un cambio de código exige `setup:di:compile` + `setup:static-content:deploy` +
    `cache:flush`. Borrar `generated/code/Tudorsync` solo sirve en developer.
  - **Prohibido un objeto como valor por defecto de un parámetro de constructor** (`Foo $foo = new Foo()`,
    PHP 8.1) en cualquier clase que cree el DI de Magento: `di:compile` lo escribe como `Foo::__set_state()`,
    que no existe, y **toda** petición y todo `bin/magento` fallan («There is an error in
    generated/metadata/global.php»). Usar `?Foo $foo = null` y `$this->foo = $foo ?? new Foo()`. Pasó el
    02-10 en `CatalogConnector` (arreglado en `55d2468`). En `core/` hoy no rompe porque el módulo crea
    `TudorApiClient` con `new`, pero lo rompería si algún día se inyectara por DI.
- **En el go-live:** si se recargan pedidos desde la instalación vieja, vaciar `tudorsync_order_attribution`
  y `tudorsync_pending_stock`. Comprobar que la config `tudorsync/*` (regla de SKU, país ES) sigue en la
  BD y, si se han perdido los códigos, relanzar `bin/magento tudorsync:model-code:fill --apply`.

## Entorno: este checkout vive dentro de la tienda Magento de Quera

- Ruta: `public_html/packages/tudorsync` (antes `var/tudorsync-src`, trasladado el 2026-09-29) de la instalación Magento 2.4.8 de Quera (Cloudways,
  PHP 8.3). El `CLAUDE.md` de `public_html/` aplica también (reglas de Magento, secretos).
- El `composer.json` raíz de Magento usa **path repositories** a `packages/tudorsync/core` y
  `packages/tudorsync/modules/magento`; `vendor/tudorsync/{core,module-magento}` son
  **symlinks** a este directorio. **Editar aquí = cambio en vivo en la tienda.**
- El módulo `Tudorsync_EcommerceSync` está habilitado en `app/etc/config.php`. Tras cambiar
  DI/plugins: `php bin/magento setup:di:compile`; tras cambiar `etc/`/patches:
  `setup:upgrade`; siempre `cache:clean` (desde `public_html/`).
- **⚠️ `setup:upgrade` en esta tienda falla** con `Magento_SalesSequence Unique constraint
  violation` (dato heredado de la migración). Existe un procedimiento manual sobre la tabla
  `sales_sequence_meta` y su copia `sales_sequence_meta_ERROR_UPGRADE`; si se hace mal, **dejan
  de funcionar las compras**. No lanzar `setup:upgrade` sin coordinarlo con el equipo que
  desarrolla el Magento de Quera. Evitar cambios que lo requieran siempre que se pueda
  (ACL, `system.xml`, DI y plantillas no lo necesitan: basta con `di:compile`/`cache:clean`).
- Tests del core: `cd core && composer install && composer test`.

## Usuarios del servidor

- **`hyszgktpab`** — usuario de la aplicación: dueño de los archivos y usuario con el que corre
  PHP-FPM. Es el preferido para trabajar (git, `bin/magento`, subidas SFTP sin problemas de
  permisos). Su home es de `root` y no escribible, así que la memoria de Claude por usuario
  puede no persistir: el contexto compartido vive en este archivo y en `CLAUDE.local.md`.
- **`master_prxfkggjks`** — usuario maestro de Cloudways (grupo `www-data`). Funciona, pero git
  da "dubious ownership" (usar `git -c safe.directory='*' ...` o
  `git config --global --add safe.directory <ruta>`), y lo que sube por SFTP llega con 644.

## Discrepancias conocidas

- El README menciona `Tudorsync\Core\Domain\AvailabilityItem`, que no existe; el nombre real es
  `StockAvailability`.

## Registro de sesiones

Ver `CLAUDE.local.md` (no versionado): quién trabajó, cuándo y en qué. Añade una entrada al
final de cada sesión.
