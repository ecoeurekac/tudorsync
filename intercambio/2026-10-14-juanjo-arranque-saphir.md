---
name: tudorsync-saphir
description: Retomar Tudorsync en Joyería Saphir (WordPress/WooCommerce): quién es quién, reglas, estado del plugin, lecciones de Quera y Grau y primeros pasos
metadata:
  type: project
  creado: 2026-10-14 (desde la sesión de Juanjo en el servidor de Grau)
---

# Tudorsync en Joyería Saphir (WooCommerce)

Saphir es el último de los 5 distribuidores que falta por arrancar. Usa **el mismo plugin WooCommerce que Gordillo**
(`modules/woocommerce`): todo lo que se haga aquí vale para los dos, así que nada de Saphir dentro del plugin.

## Quién habla y reglas de trabajo
- Cuenta de Claude **compartida**: si no se ha identificado, preguntar quién habla antes de editar. Se trabaja en español.
- **Juanjo** = conectores (`packages/tudorsync/modules/*`; aquí, `modules/woocommerce`). **Jorge** = `core/`.
  Nunca editar la parte del otro (ni Edit, ni sed, ni scripts, ni "arreglos rápidos"): las peticiones van como archivo
  en `intercambio/` (`AAAA-MM-DD-autor-tema.md`; JSON de pruebas como `AAAAMMDD_retailer.json`, p. ej. `20261014_saphir.json`).
- Cambios en el contrato compartido (`CatalogConnectorInterface`, `StockAvailability`, `TudorApiClient`, `SyncEngine`,
  `ProgrammeSalesSourceInterface`) → "coordinar con Jorge".
- **Git (sesión de Juanjo):** commit + push sin preguntar, pero **solo de lo ya probado**; `git add` solo de rutas propias
  (nunca `-A` / `commit -a`); `git pull --ff-only` antes del push;
  `git -c user.name=Ecoeureka -c user.email=team@ecoeureka.com commit` + línea `Co-Authored-By`;
  prefijos `Juanjo - [WooCommerce V1] - …` (código del plugin) / `Juanjo - [Docs] - …` (docs e `intercambio/`).
- Repo: `github.com/ecoeurekac/tudorsync` (monorepo: `core/`, `modules/{magento,prestashop,woocommerce}`, `doc/`, `intercambio/`).
  **En el servidor de Saphir hay que montar su propia deploy key** (no copiar claves de otros servidores; no imprimirlas).
  En Grau se hizo así: clave en una carpeta fuera del docroot (`<home>/.ssh-tudorsync/`) y
  `git config core.sshCommand "ssh -i <ruta>/id_ed25519 -o IdentitiesOnly=yes"`; la clave pública la añade Carlos/Jorge
  como deploy key con escritura en GitHub.
- **Mismo plugin que Gordillo:** antes de tocar `modules/woocommerce`, `git pull --ff-only` y mirar
  `git log -- modules/woocommerce` por si la sesión de Gordillo ha avanzado. Commits pequeños y frecuentes para no pisarse.
- Al acabar cada sesión: entrada en `packages/tudorsync/CLAUDE.local.md` (no versionado) y memoria del servidor al día.

## Reglas que no se negocian
- **Nada de datos de otras tiendas en el plugin ni en core** (ni Quera, ni PLO, ni Grau, ni Gordillo, ni Saphir): ni prefijos
  de SKU, ni catálogos, ni nombres en comentarios/README. Lo específico de Saphir → ajustes del admin (vacíos por defecto)
  o filtros/hooks desde un mu-plugin propio de la tienda, **no** copiando el plugin. Los datos de Saphir para pruebas
  (exportaciones, previews, capturas) van en `doc/distribuidores/saphir/`, que **no se versiona**.
- **Credenciales TUDOR:** Saphir tiene las suyas (app PREPROD ya probada el 13-10: token OK, `point-of-sales` 200 con
  **2 puntos de venta**, `stocks` vacío). En staging solo **PREPROD**; PROD solo en producción y cuando esté validado.
  **Nunca las mismas credenciales en dos instalaciones**: cada batch pone a 0 lo que no lleva y se pisarían.
  Los secrets no se escriben en notas, commits ni memoria.
- **Producción no se toca para desarrollar.** Desarrollo, pruebas y crons, en staging. En producción solo `git pull` de lo
  ya probado, y sin credenciales PROD hasta que esté todo validado.
- En el staging, **nada de correos a clientes reales ni escrituras en sistemas reales** (ERP/TPV, pasarelas, tracking,
  marketplaces, CRM): aislarlo antes de hacer pedidos de prueba (copia de BD de producción = clientes reales).
- ⚠️ Si el plugin se enlaza con **symlink** (`wp-content/plugins/tudorsync-woocommerce` → checkout), no borrar nada
  dentro por SFTP: borra el original. Así se perdió el checkout entero el 30-09 en Quera.
- Pruebas de atribución con `utm_source=ecoeureka-test`, **nunca con `tudorwatch.com`** (falsea informes y atribución).

## Estado del plugin WooCommerce (a 14-10-2026)
- `modules/woocommerce/` (`tudorsync/plugin-woocommerce` 0.1.0, PSR-4 `Tudorsync\Woocommerce\` en `includes/`):
  `tudorsync-woocommerce.php`, `Config`, `Cron` (WP-Cron cada hora), `ProductField` (meta `_tudor_model_code` en la ficha),
  `CatalogConnector`, `Admin/SettingsPage` (Test Connection / Run Sync Now), `Api/WordPressHttpClient`. ~650 líneas.
- **Sin cambios desde el andamiaje inicial: solo ha pasado `php -l`; nunca ha corrido en un WordPress real.**
  (Comprobar con `git log -- modules/woocommerce` si Gordillo ya lo ha movido.)

### Fallos que ya se ven leyendo el código (los mismos que se arreglaron en PrestaShop, `72f3594` y `63e4dab`)
1. **Sigue en `tudorApiKey`** (`Config.php:42`, `Cron.php:47`, `SettingsPage.php:167` y `:203`). Pasar a **OAuth2**
   (`clientId`/`clientSecret` por entorno, secret sin mostrarse en el formulario) como Magento y PrestaShop
   (`intercambio/2026-10-02-jorge-oauth-listo.md`; referencia: `modules/prestashop/src/Config.php` y `hasCredentials()`).
   Cuando lo tenga WooCommerce, Jorge podrá quitar `tudorApiKey` de core.
2. **`= new` por defecto** en el constructor (`CatalogConnector.php:38`, `UtmUrlBuilder $utmUrlBuilder = new UtmUrlBuilder()`):
   cambiar a `?UtmUrlBuilder $x = null` + `?? new` (regla del equipo, `intercambio/2026-10-07-jorge-sin-new-por-defecto.md`).
3. **Batch vacío:** si ningún producto tiene código de modelo, el catálogo sale `[]` y core manda un batch vacío
   (TUDOR respondió 400 en Grau; si lo aceptara, pondría a 0 todo el catálogo). Copiar la guarda de PrestaShop:
   `if ($engine->plan() === [] && $connector->countEnrolledProducts() === 0)` → no llamar a TUDOR y avisar, en Run Sync Now
   **y** en el cron (`intercambio/2026-10-13-juanjo-batch-vacio.md`; puede que Jorge lo meta en core: mirar antes).
4. **`NothingPassedReviewException`** sin capturar en `Cron::run()` y `handleRunSync()` → capturarla y registrarla como aviso.
5. **Stock vendible:** respetar `manage_stock`, `stock_status`, backorders, producto publicado (`post_status = publish`) y
   visibilidad del catálogo. Productos variables: ver si los relojes son simples.
6. **Idiomas:** `languages: []` (TODO en `Config.php:40`); solo existe el filtro `tudorsync_localized_urls`. Engancharlo al
   plugin de idiomas real de Saphir (WPML, Polylang, TranslatePress o ninguno). Claves BCP 47 con guion (`es`, `es-ES`).
   Todas las URLs `https://` y con la UTM.
7. **WP-Cron** solo se dispara con visitas: `DISABLE_WP_CRON` + cron de servidor (`wp cron event run --due-now`) cada minuto,
   en el **staging** (es infraestructura del hosting de Saphir: avisar a Carlos).
8. **PHP:** core pide **^8.1** (readonly, enums). En Grau la web iba con 8.0 y fue un bloqueo. Comprobarlo lo primero
   (web **y** CLI: suelen ser distintos; usar el binario 8.1+ explícito para `composer` y WP-CLI).

## Qué traer de Magento (referencia probada en PREPROD) y de PrestaShop
Mirar `modules/magento/README.md` como modelo de paridad y `modules/prestashop/` como ejemplo ya adaptado a otra plataforma:
1. **Stock vendible**, no físico (piezas únicas).
2. **Agrupar por `mc`** si el mismo modelo está en varias fichas (sumar stock, elegir una URL).
3. **Código de modelo (`mc`)**: meta `_tudor_model_code` o **regla configurable desde el SKU** (vacía por defecto) + relleno
   masivo por WP-CLI. **Mirar primero si Saphir ya tiene la referencia TUDOR** en el SKU, en un atributo o en un meta
   (en Grau estaba en la referencia sin la «M»: `12100-0001` → `M12100-0001`). Cruzar con los Excel de TUDOR de `doc/`
   (vigentes 2026, descatalogados 2025/2026). El plugin pasa **todos** los relojes a core; el filtro de la lista de
   modelos válidos lo hace core (`…-2026-10-08-jorge-lista-modelos-validos.md`, `…-2026-10-09-jorge-lista-solo-en-batch.md`).
4. **Revisión de la ficha antes de enviar** con `SyncEngine::plan()` visible en preview y admin
   (`intercambio/2026-10-08-jorge-revision-ficha.md` y la respuesta de Juanjo).
5. Herramientas: **preview en seco** (WP-CLI `wp tudorsync catalog preview` → JSON en `doc/distribuidores/saphir/`),
   `sync run`, `connection test`, log propio, registro de llamadas a la API (sin secretos, limpieza diaria), estado del
   último sync, **health** (`getHealth()`, `intercambio/2026-10-07-jorge-health-listo.md`).
6. **Publicación en el minuto**: cola `tudorsync_pending_stock` alimentada por hooks de Woo (`woocommerce_product_set_stock`,
   `woocommerce_variation_set_stock`, cambio de estado de pedido, guardar/borrar/despublicar producto) + envío cada minuto,
   además del batch completo cada hora (`intercambio/2026-10-02-jorge-respuesta-publicacion-en-el-minuto.md`).
   Ante 429 o fallo de credenciales, cortar y pausar 5 min. Interruptor `realtime_enabled`.
7. **Atribución UTM** de tudorwatch.com en los pedidos: cookie `tudorsync_utm` **con consentimiento** del gestor de cookies
   que use Saphir (averiguar cuál: CookieYes, Complianz, Cookiebot…) → meta del pedido.
8. **Informe mensual:** implementar `Tudorsync\Core\Contract\ProgrammeSalesSourceInterface`
   (`getProgrammeSales($from, $to)`, periodo `[$from, $to)`) con los criterios de `intercambio/2026-10-14-jorge-interfaz-informe.md`:
   unidades (no pedidos), cancelados fuera, devoluciones no se restan, click & collect `null`, zona horaria de GA4.
   Según la revisión de GA4 del 13-10, Saphir sí manda `add_to_cart` a GA4.

## Primeros pasos en el servidor de Saphir
1. **Staging o producción:** confirmar dónde se está y apuntarlo en memoria. Si no hay staging usable, montarlo antes de
   instalar nada (en Grau el staging del hosting estaba roto y se montó uno local: copia del código + BD con otro prefijo,
   solo catálogo y configuración, **sin clientes/pedidos** (PII), servidor PHP integrado en `127.0.0.1` con túnel SSH,
   correo desactivado y módulos externos apagados).
2. **Inventario:** versiones de WP / WooCommerce / PHP (web y CLI), multisite sí/no, plugin de idiomas e idiomas activos,
   cómo reconocer los TUDOR (marca, categoría, atributo, SKU) y cuántos hay (activos/total), si son productos simples,
   gestión de stock y backorders, dónde está la referencia TUDOR, gestor de cookies, plugins de pagos/envíos/correo/ERP a
   aislar en staging, caché (página/objeto/CDN). Guardarlo en la memoria del servidor.
3. **Checkout fuera del docroot** (p. ej. `<home>/packages/tudorsync`) con deploy key propia; `composer install` con el PHP
   8.1+ **desde la ruta real** `packages/tudorsync/modules/woocommerce` (no a través del symlink: `../../core` apuntaría a
   otro sitio); symlink `wp-content/plugins/tudorsync-woocommerce` → esa carpeta, **solo en staging**.
4. Primeros arreglos (puntos 1–4 y 8 de arriba), activar el plugin y **smoke test** contra PREPROD con las credenciales de
   Saphir: ajustes, Test Connection, preview en seco → `doc/distribuidores/saphir/AAAAMMDD_saphir.json`. **No pulsar Run Sync
   Now hasta que el preview salga bien** (lección de Grau). Commit `Juanjo - [WooCommerce V1] - …` solo de lo probado.
5. Ir cubriendo la lista de paridad y dejar `modules/woocommerce/README.md` como el de Magento (en inglés, sin nada de ninguna tienda).

## Pendientes abiertos que afectan aquí
- `intercambio/2026-10-13-juanjo-batch-vacio.md`: propuesta a Jorge de que core no envíe nunca un batch vacío sin
  confirmación, y pregunta a TUDOR de cómo se comunica "todo vendido" si el batch vacío da 400.
- `intercambio/2026-10-13-juanjo-grau-partner-not-found.md`: Grau sin dar de alta en PREPROD (Saphir sí funciona).

## Fechas
- Go-live TUDOR España: **19-10-2026**. Orden de conectores: Quera → PLO → Grau (PrestaShop) → Gordillo/Saphir.
- Leer al empezar: `packages/tudorsync/CLAUDE.md`, `CONTEXT.md`, `README.md` (tabla "Platform connectors" y "What's still
  open"), `modules/magento/README.md`, y las notas hermanas `intercambio/2026-10-09-juanjo-arranque-gordillo.md` y
  `intercambio/2026-10-13-juanjo-arranque-grau.md`.
