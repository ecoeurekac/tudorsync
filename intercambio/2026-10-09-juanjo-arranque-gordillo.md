---
name: tudorsync-gordillo
description: Retomar Tudorsync en joyeriagordillo.com (WordPress/WooCommerce): quién es quién, reglas, estado del plugin y qué traer del módulo de Magento
metadata:
  type: project
  creado: 2026-10-09 (desde la sesión de Juanjo en el staging de Quera)
---

# Tudorsync en Joyería Gordillo (WooCommerce)

## Quién habla y reglas de trabajo
- Cuenta de Claude **compartida**: si no se ha identificado, preguntar quién habla antes de editar. Se trabaja en español.
- **Juanjo** = conectores (`packages/tudorsync/modules/*`; aquí, `modules/woocommerce`). **Jorge** = `core/`.
  Nunca editar la parte del otro (ni Edit, ni sed, ni "arreglos rápidos"): las peticiones van como archivo
  en `intercambio/` (`AAAA-MM-DD-autor-tema.md`; JSON de pruebas como `AAAAMMDD_retailer.json`, p. ej. `20261009_gordillo.json`).
- Cambios en el contrato compartido (`CatalogConnectorInterface`, `StockAvailability`, `TudorApiClient`) → "coordinar con Jorge".
- **Git (sesión de Juanjo):** commit + push sin preguntar, pero **solo de lo ya probado**; `git add` solo de rutas propias
  (nunca `-A` / `commit -a`); `git pull --ff-only` antes del push;
  `git -c user.name=Ecoeureka -c user.email=team@ecoeureka.com commit` + línea `Co-Authored-By`;
  prefijos `Juanjo - [WooCommerce V1] - …` (código del plugin) / `Juanjo - [Docs] - …` (docs e `intercambio/`).
- Repo: `github.com/ecoeurekac/tudorsync` (monorepo: `core/`, `modules/{magento,prestashop,woocommerce}`, `doc/`, `intercambio/`).
  En el servidor de Quera se empuja con deploy key en `private_html/.ssh-tudorsync/` + `core.sshCommand`;
  **en este servidor habrá que montar su propia deploy key** (no copiar claves; no imprimirlas).

## Reglas que no se negocian
- **Nada de datos de otras tiendas en el plugin ni en core** (ni Quera, ni PLO, ni Gordillo): ni prefijos de SKU, ni envíos,
  ni catálogos, ni nombres en comentarios/README. Lo específico de Gordillo → ajustes del admin (vacíos por defecto) o
  hooks/filtros desde un mu-plugin/plugin propio de la tienda, **no** copiando el plugin. Lo de Gordillo se documenta fuera del paquete.
  `intercambio/`, `doc/` y `CLAUDE*.md` no se distribuyen.
- **Credenciales TUDOR:** en staging solo **PREPROD**; PROD solo en producción y cuando esté validado. **Nunca las mismas
  credenciales en dos instalaciones**: cada batch pone a 0 lo que no lleva y se pisarían el catálogo.
- En el staging, **nada de correos a clientes reales ni escrituras en sistemas reales** (ERP, pasarelas, tracking): aislarlo
  antes de hacer pedidos de prueba (copia de BD de producción = clientes reales).
- ⚠️ Si el plugin se enlaza con **symlinks** (p. ej. `wp-content/plugins/tudorsync-woocommerce` → checkout), no borrar nada
  dentro por SFTP: borra el original. Así se perdió el checkout entero el 30-09 en Quera. Commit en cuanto algo funcione.

## Estado del plugin WooCommerce (a 09-10-2026)
- `modules/woocommerce/` (`tudorsync/plugin-woocommerce` 0.1.0, PSR-4 `Tudorsync\Woocommerce\` en `includes/`):
  `tudorsync-woocommerce.php`, `Config`, `Cron` (WP-Cron), `ProductField` (meta `_tudor_model_code` en la ficha),
  `CatalogConnector`, `Admin/SettingsPage` (Test Connection / Run Sync Now), `Api/WordPressHttpClient`. ~650 líneas.
- **Solo ha pasado `php -l`; nunca ha corrido en un WordPress real.** Es un andamiaje, no un módulo terminado.
- Aún usa `tudorApiKey`: hay que pasarlo a **OAuth2 (`clientId`/`clientSecret`)** como ya hace Magento
  (ver `intercambio/2026-10-02-jorge-oauth-listo.md`); cuando lo tengan PrestaShop y WooCommerce, Jorge quita `tudorApiKey` del core.
- Multi-idioma: solo existe el filtro `tudorsync_localized_urls`; hay que engancharlo al plugin de idiomas que use Gordillo
  (WPML, Polylang o ninguno). Claves de idioma a TUDOR en BCP 47 con guion (`es`, `en`, `fr-FR`), no `es_ES`.
- WP-Cron solo se dispara con visitas: probablemente haga falta `DISABLE_WP_CRON` + cron de servidor (`wp cron event run --due-now`).

## Qué traer del módulo de Magento (referencia ya probada en PREPROD el 08-10, 21 pruebas OK)
Mirar `modules/magento/` y su `README.md` como modelo de paridad:
1. **Stock vendible**, no físico (en Woo: respetar `manage_stock`, `stock_status`, backorders; piezas únicas).
2. **Agrupar por `mc`** cuando el mismo modelo está en varias fichas (sumar stock, elegir una URL); el core no agrupa.
3. **Código de modelo (`mc`)**: meta `_tudor_model_code` o regla configurable desde el SKU (vacía por defecto) + comando
   de relleno masivo (WP-CLI). Confirmar con Gordillo si ya tienen la referencia TUDOR en algún campo/SKU.
   Aplicar la **lista de modelos válidos** de TUDOR (`intercambio/2026-10-08-jorge-lista-modelos-validos.md`).
4. **Revisión de la ficha antes de enviar** (normalización, URLs, repetidos, motivos de descarte) visible en preview y admin
   (`intercambio/2026-10-08-jorge-revision-ficha.md` y la respuesta de Juanjo).
5. Herramientas: **preview en seco** (WP-CLI `catalog preview` → JSON a `intercambio/`), `sync run`, `connection test`,
   log propio, registro de llamadas a la API con limpieza diaria, estado del último sync, health
   (`intercambio/2026-10-07-jorge-health-listo.md`).
6. **Publicación en el minuto**: cola de cambios de stock (hooks de Woo al cambiar stock/pedidos) + cron cada minuto,
   además del batch completo cada hora (`intercambio/2026-10-02-jorge-respuesta-publicacion-en-el-minuto.md`).
7. **Atribución UTM** de tudorwatch.com en los pedidos (cookie → meta del pedido) y **ventas del programa** para el
   **informe mensual** Excel (`MonthlyReportWriter` del core; ver `intercambio/2026-10-02-juanjo-fuente-ventas-informe-magento.md`).
8. Sin `new` por defecto en objetos del core (`intercambio/2026-10-07-jorge-sin-new-por-defecto.md`).
- Ojo: con 0 modelos disponibles el batch sale vacío y TUDOR pone todo a 0 → revisar siempre con preview antes del primer envío real.

## Primeros pasos en joyeriagordillo.com
1. Confirmar si este WordPress es **staging o producción** (y apuntarlo en memoria). El desarrollo y los crons, en staging.
2. Inventario: versiones de WP/Woo/PHP (core pide PHP ^8.1), plugin de idiomas, cómo gestionan stock, productos TUDOR
   (cómo reconocerlos: categoría, marca, SKU), plugins de envío/pagos/correo a aislar.
3. Clonar el repo (deploy key propia), instalar core + plugin con Composer (path repo a `../../core`) y activar.
4. Smoke test: ajustes, Test Connection y Run Sync Now contra PREPROD; preview en seco → `intercambio/AAAAMMDD_gordillo.json`.
5. Ir cubriendo la lista de paridad de arriba; Saphir (también WooCommerce) usará el mismo plugin, así que nada de Gordillo dentro.

## Fechas
- Go-live TUDOR España: **19-10-2026** (Quera). Orden de conectores: Quera → PLO → Grau (PrestaShop) → Gordillo/Saphir.
- Leer al empezar: `packages/tudorsync/CLAUDE.md`, `CONTEXT.md`, `README.md` (tabla "Platform connectors" y "What's still open").
