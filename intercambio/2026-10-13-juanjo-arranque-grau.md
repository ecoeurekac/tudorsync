---
name: tudorsync-grau
description: Retomar Tudorsync en joieriagrau.com (PrestaShop): quién es quién, reglas, estado real del módulo, fallos ya vistos al leerlo y qué traer del módulo de Magento
metadata:
  type: project
  creado: 2026-10-13 (desde la sesión de Juanjo en el staging de Quera)
---

# Tudorsync en Joieria Grau (PrestaShop)

## Quién habla y reglas de trabajo
- Cuenta de Claude **compartida**: si no se ha identificado, preguntar quién habla antes de editar. Se trabaja en español.
- **Juanjo** = conectores (`packages/tudorsync/modules/*`; aquí, `modules/prestashop`). **Jorge** = `core/`.
  Nunca editar la parte del otro (ni Edit, ni sed, ni "arreglos rápidos"): las peticiones van como archivo
  en `intercambio/` (`AAAA-MM-DD-autor-tema.md`; JSON de pruebas como `AAAAMMDD_retailer.json`, p. ej. `20261013_grau.json`).
- Cambios en el contrato compartido (`CatalogConnectorInterface`, `StockAvailability`, `TudorApiClient`, `SyncEngine`)
  → "coordinar con Jorge".
- **Git (sesión de Juanjo):** commit + push sin preguntar, pero **solo de lo ya probado**; `git add` solo de rutas propias
  (nunca `-A` / `commit -a`); `git pull --ff-only` antes del push;
  `git -c user.name=Ecoeureka -c user.email=team@ecoeureka.com commit` + línea `Co-Authored-By`;
  prefijos `Juanjo - [PrestaShop V1] - …` (código del módulo) / `Juanjo - [Docs] - …` (docs e `intercambio/`).
- Repo: `github.com/ecoeurekac/tudorsync` (monorepo: `core/`, `modules/{magento,prestashop,woocommerce}`, `doc/`, `intercambio/`).
  En el servidor de Quera se empuja con deploy key en `private_html/.ssh-tudorsync/` + `core.sshCommand`;
  **en el servidor de Grau habrá que montar su propia deploy key** (no copiar claves; no imprimirlas).

## Reglas que no se negocian
- **Nada de datos de otras tiendas en el módulo ni en core** (ni Quera, ni PLO, ni Grau): ni prefijos de SKU, ni
  transportistas, ni catálogos, ni nombres en comentarios/README. Lo específico de Grau → configuración del módulo
  (vacía por defecto) o un módulo/override propio de la tienda, **no** copiando el módulo. Los datos propios de Grau
  para pruebas (exportaciones, previews, notas de su tienda) van en `doc/distribuidores/grau/`, que **no se versiona**.
  `intercambio/`, `doc/` y `CLAUDE*.md` no se distribuyen.
- **Credenciales TUDOR:** Grau tiene que tener **las suyas** (client id/secret propios; pedirlas vía Jorge/TUDOR).
  En staging solo **PREPROD**; PROD solo en producción y cuando esté validado. **Nunca las mismas credenciales en dos
  instalaciones** (ni las de Quera, ni las de staging en producción): cada batch pone a 0 lo que no lleva y se pisarían.
- En el staging, **nada de correos a clientes reales ni escrituras en sistemas reales** (ERP/TPV, pasarelas, tracking,
  marketplaces): aislarlo antes de hacer pedidos de prueba (copia de BD de producción = clientes reales).
- ⚠️ Si el módulo se enlaza con **symlink** (`modules/tudorsync` → checkout), no borrar nada dentro por SFTP: borra el
  original. Así se perdió el checkout entero el 30-09 en Quera. Commit en cuanto algo funcione.

## Estado del módulo PrestaShop (a 13-10-2026)
- `modules/prestashop/` (`tudorsync/module-prestashop` 0.1.0, PSR-4 `Tudorsync\Prestashop\` en `src/`):
  `tudorsync.php` (clase `Tudorsync`, install/uninstall, `getContent()` con HelperForm + formularios Test Connection /
  Run Sync Now), `src/Config.php` (tabla `Configuration`), `src/CatalogConnector.php`, `src/Api/CurlHttpClient.php`,
  `controllers/front/cron.php` (cron por URL con token). ~650 líneas.
- **Solo ha pasado `php -l`; nunca ha corrido en un PrestaShop real.** Es un andamiaje, no un módulo terminado.
- Código de modelo: característica (Feature) nueva **"TUDOR Model Code"** creada al instalar (id en
  `TUDORSYNC_MODEL_CODE_FEATURE_ID`). Exclusión: cantidad disponible ≤ 0. `value` = cantidad disponible real.
  URLs: una por idioma activo con `Link::getProductLink()`.

### Cosas que ya se ven mal leyendo el código (arreglar antes o durante el smoke test)
1. **PHP:** core pide **PHP ^8.1** (readonly, enums). `ps_versions_compliancy` dice min `1.7`, pero **PrestaShop 1.7.x
   no pasa de PHP 7.4** → si Grau está en 1.7, core no carga. Lo primero: versión de PS y de PHP. PS 8.0/8.1 admiten
   PHP 8.1; PS 9 hasta 8.4. Si están en 1.7, es un bloqueo a hablar con Jorge/Carlos (actualizar la tienda o no hay módulo).
2. **Sigue en `tudorApiKey`**: `Config::getClientConfig()` lee `TUDORSYNC_API_KEY_{STAGING,PRODUCTION}`, y `cron.php`,
   `handleTestConnection()` y `handleRunSync()` se saltan todo si `tudorApiKey === ''`. Pasar a **OAuth2**
   (`clientId`/`clientSecret` por entorno, secret sin mostrarlo en el formulario) como Magento
   (`intercambio/2026-10-02-jorge-oauth-listo.md`). Cuando lo tengan PrestaShop y WooCommerce, Jorge quita `tudorApiKey`.
3. **`= new` por defecto** en el constructor de `CatalogConnector` (`UtmUrlBuilder $utmUrlBuilder = new UtmUrlBuilder()`):
   cambiar a `?UtmUrlBuilder $x = null` + `?? new` (regla del equipo; PS 8 también tiene contenedor Symfony).
4. **Instalación con Composer:** `tudorsync.php` hace `require_once __DIR__ . '/vendor/autoload.php'` y `composer.json`
   apunta a core con path repo `../../core` (solo vale dentro del monorepo). PrestaShop exige que la carpeta se llame
   como el módulo: **`modules/tudorsync/`**, no `prestashop/`. Propuesta: checkout fuera de `modules/` (p. ej.
   `<raíz PS>/packages/tudorsync`), `composer install` **desde la ruta real** `packages/tudorsync/modules/prestashop`
   (si se lanza a través del symlink, `../../core` apunta a otro sitio) y symlink `modules/tudorsync` → esa carpeta.
   Ojo con lo que copia `vendor/` como symlink a core (mismo aviso de SFTP).
5. **No mira si el producto está activo ni visible**: Magento excluye deshabilitados/no visibles; aquí solo cuenta el
   stock. Añadir `active`, `visibility` (both/catalog… según se decida), `available_for_order` y producto en la tienda
   (`product_shop`).
6. **Stock / pedidos sin stock:** `StockAvailable::getQuantityAvailableByProduct($id)` sin combinación = total del
   producto (ok si los relojes no tienen combinaciones; comprobarlo). El TODO del conector sigue abierto: productos
   **sin gestión de stock** quedarían fuera; confirmar con Grau cómo tienen configurados los TUDOR y el
   "permitir pedidos sin stock" (`out_of_stock` / `PS_ORDER_OUT_OF_STOCK`).
7. **Idioma del código de modelo:** la consulta lee `feature_value_lang` **solo en el idioma del contexto**. En el cron
   (front controller) el contexto es el idioma por defecto del front; si un valor personalizado solo se rellenó en
   otro idioma, el reloj no sale. Leer el valor del idioma por defecto de la tienda (`PS_LANG_DEFAULT`) o el primero no vacío.
8. **Multitienda:** `getModelCodeFeatureId()` y `getCronToken()` no usan `id_shop`; `StockAvailable` va por tienda.
   Ver si Grau tiene multitienda (probablemente no) y dejarlo dicho.
9. **`NothingPassedReviewException`** (core, 08-10): `SyncEngine::run()` la lanza si hay fichas y no pasa ninguna;
   hoy `cron.php` y Run Sync Now no la capturan → error 500 en vez de mensaje. Capturarla y registrarla.
10. `ClientConfig::$languages` va vacío (TODO). Claves de idioma a TUDOR en BCP 47 con guion: el `locale` de PS 1.7+/8
    ya viene como `es-ES`/`ca-ES`; core normaliza igualmente. Seguramente Grau tenga catalán + castellano (+ inglés):
    comprobar que todas las URLs salen `https://` y con la UTM.

## Qué traer del módulo de Magento (referencia ya probada en PREPROD el 08-10, 21 pruebas OK)
Mirar `modules/magento/` y su `README.md` como modelo de paridad:
1. **Stock vendible**, no físico (en PS `quantity` de `stock_available` ya descuenta lo reservado; piezas únicas).
2. **Agrupar por `mc`** cuando el mismo modelo está en varias fichas (sumar stock, elegir una URL). Core ya junta
   repetidos `mc`+país en la revisión, pero conviene hacerlo en el conector igual que Magento.
3. **Código de modelo (`mc`)**: Feature "TUDOR Model Code" o **regla configurable desde la referencia/EAN** (vacía por
   defecto) + comando de relleno masivo. **Preguntar a Grau** si ya tienen la referencia TUDOR (TMC, p. ej. `M79030N-0002`)
   en la referencia, la referencia del proveedor, el EAN13 o una característica propia: si la tienen, mejor leer de ahí.
   Cruzar con los Excel de TUDOR de `doc/` (activos 2026, descatalogados 2025/2026) como se hizo en Quera.
   El módulo pasa **todos** los relojes a core; el filtro de la **lista de modelos válidos** lo hace core
   (`intercambio/2026-10-08-jorge-lista-modelos-validos.md`, `…-2026-10-09-jorge-lista-solo-en-batch.md`).
4. **Revisión de la ficha antes de enviar** (normalización, URLs, repetidos, motivos de descarte) visible en preview y
   admin: usar `SyncEngine::plan()` / `keepOnlyAvailableForBatch()` (`intercambio/2026-10-08-jorge-revision-ficha.md`,
   la respuesta de Juanjo y `intercambio/2026-10-09-jorge-lista-solo-en-batch.md`).
5. Herramientas: **preview en seco** (comando de consola de PS 8 —`bin/console` con servicio en `config/services.yml`
   del módulo— o, si no, un script CLI del módulo) → JSON a `doc/distribuidores/grau/`; `sync run`, `connection test`,
   log propio (`PrestaShopLogger` o archivo en `var/logs`), **registro de llamadas a la API** (tabla propia, sin secretos,
   limpieza diaria), estado del último sync, **health** (`getHealth()`, `intercambio/2026-10-07-jorge-health-listo.md`).
6. **Publicación en el minuto**: cola `tudorsync_pending_stock` alimentada por hooks de PS (`actionUpdateQuantity`,
   `actionValidateOrder`, `actionOrderStatusPostUpdate`, `actionProductUpdate`, `actionProductDelete`, cambio de
   `active`) + llamada cada minuto, además del batch completo cada hora
   (`intercambio/2026-10-02-jorge-respuesta-publicacion-en-el-minuto.md`). Ante un 429 o fallo de credenciales, cortar y
   pausar 5 min como Magento. Interruptor `realtime_enabled` y frecuencia del batch en la config.
7. **Cron**: PrestaShop no tiene programador propio → **cron de servidor** que llame a
   `index.php?fc=module&module=tudorsync&controller=cron&token=…` (o mejor, por CLI para no depender de HTTP/Varnish/
   mantenimiento). Una tarea por hora (batch) y otra por minuto (cola). Es trabajo de infraestructura del hosting de Grau
   (sin dueño en el reparto: avisar a Carlos). Con la tienda en mantenimiento, el front controller no responde.
8. **Atribución UTM** de tudorwatch.com en los pedidos: cookie `tudorsync_utm` (hook `actionFrontControllerSetMedia`/
   `displayHeader`) **con el consentimiento** del gestor de cookies que use Grau (averiguar cuál) → tabla
   `tudorsync_order_attribution` en `actionValidateOrder`. Y **ventas del programa** para el **informe mensual** Excel
   (`MonthlyReportWriter` de core; ver `intercambio/2026-10-02-juanjo-fuente-ventas-informe-magento.md`) en una pestaña
   del admin (`Tab` + `ModuleAdminController`).
9. Sin `new` por defecto en objetos del core (`intercambio/2026-10-07-jorge-sin-new-por-defecto.md`).
- Ojo: con 0 modelos disponibles el batch sale vacío y TUDOR pone todo a 0 → revisar siempre con preview antes del primer
  envío real, y probar primero con `Demo-Retailers-*` en PREPROD como se hizo en Quera.

## Primeros pasos en joieriagrau.com
1. Confirmar si la instalación donde se trabaja es **staging o producción** (y apuntarlo en memoria). Si no hay staging,
   pedirlo antes de instalar nada: el desarrollo, las pruebas y los crons van en staging.
2. Inventario: **versión de PrestaShop y de PHP** (punto 1 de arriba: bloqueante), multitienda sí/no, idiomas activos,
   gestión de stock (combinaciones, pedidos sin stock, almacenes), cómo reconocer los productos TUDOR (marca/fabricante,
   categoría, referencia), gestor de cookies, módulos de pagos/correo/ERP/TPV a aislar, caché (Varnish/CDN) y modo
   debug/mantenimiento.
3. Clonar el repo (deploy key propia), `composer install` en `modules/prestashop` desde la ruta real, symlink
   `modules/tudorsync`, instalar el módulo desde el back office (crea la Feature y el token del cron).
4. Primeros arreglos (OAuth2, `= new`, activo/visible, idioma de la Feature, excepción de core) y smoke test: config,
   Test Connection y Run Sync Now contra PREPROD con credenciales de Grau; preview en seco →
   `doc/distribuidores/grau/AAAAMMDD_grau.json`. Commit `Juanjo - [PrestaShop V1] - …` solo de lo probado.
5. Ir cubriendo la lista de paridad de arriba y dejar el `README.md` del módulo como el de Magento (en inglés, sin nada de Grau).

## Fechas
- Go-live TUDOR España: **19-10-2026** (Quera). Orden de conectores: Quera → PLO → **Grau (PrestaShop)** → Gordillo/Saphir.
- Leer al empezar: `packages/tudorsync/CLAUDE.md`, `CONTEXT.md`, `README.md` (tabla "Platform connectors" y "What's still
  open"), `modules/magento/README.md` y la nota hermana `intercambio/2026-10-09-juanjo-arranque-gordillo.md`.
