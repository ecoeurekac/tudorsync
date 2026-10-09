# TUDOR e-Stock Sync (WordPress / WooCommerce)

Conector WooCommerce de tudorsync: un plugin de WordPress que publica en el programa e-Stock de
TUDOR (botón «Comprar ahora» de tudorwatch.com) los relojes que la tienda puede vender online en
ese momento, y atribuye los pedidos que llegan desde tudorwatch.com. Mismas funciones que el
conector Magento (`modules/magento`), con las piezas propias de WordPress.

Probado el 09-10-2026 en un WordPress 7.1.3 local con WooCommerce 11.2 (HPOS), TranslatePress 3.3
y PHP 8.4, contra un TUDOR simulado (token, `/health`, `/v1/point-of-sales`, `/v1/stocks`, batch con
200/207, 401 y 429): todavía **no** contra PREPROD de TUDOR ni en una tienda real.

Nada de una tienda concreta va en el plugin: lo propio de cada tienda (regla de SKU, ámbito de
productos, métodos de recogida, cookie de consentimiento…) son ajustes vacíos por defecto, o el
filtro `tudorsync_localized_urls`.

## Qué hace

Lee el catálogo, decide qué relojes TUDOR se pueden comprar **ya** y entrega a `tudorsync/core` un
`StockAvailability` por código de modelo; el core revisa cada registro (`AvailabilityFilter`) y lo
envía (`SyncEngine` → `POST /v1/stocks/batch`). El plugin ejecuta la misma revisión por su cuenta
(`Catalog\CatalogReview`) para que el preview, el admin, las pruebas de API y el log enseñen lo mismo
que se envía, con cada reloj descartado y su motivo. Si no pasa nada, no se envía nada
(`NothingPassedReviewException`): un batch vacío pondría a 0 todo el catálogo en TUDOR.

| Paso | Regla |
|---|---|
| Participa | Tiene código de modelo: campo «TUDOR model code» (meta `_tudor_model_code`, en la ficha y en cada variación) o, si está vacío, el derivado del SKU con la regla opcional |
| Se excluye | No publicado (o el padre de la variación), oculto del catálogo, protegido con contraseña, admite reservas o está «en reserva» (bajo demanda), agotado, sin gestión de stock (salvo que el ajuste lo envíe como 1) o **sin cantidad vendible**: stock menos lo retenido por checkouts en curso (`wc_get_held_stock_quantity()`) |
| Productos variables | El padre no se lee: cada variación, con su SKU o su código, y su URL con la variante preseleccionada |
| Varias fichas del mismo modelo | Un único registro: se suman las cantidades y las URL son las de la ficha con más stock (la de id más bajo si empatan) |
| `value` | Cantidad vendible, o siempre 1 (ajuste «Value sent to TUDOR») |
| URLs | `defaultUrl` = permalink; `localizedUrls` = una por idioma publicado de TranslatePress (claves `es-ES`/`en-GB`, o `es`/`en`), más lo que añada el filtro `tudorsync_localized_urls`; todas con las UTM de `UtmUrlBuilder` |

## Instalación

El plugin necesita su `vendor/` (core + `phpoffice/phpspreadsheet` para la lista de modelos vigentes
y el informe), así que se instala **construido**:

```sh
modules/woocommerce/bin/build.sh   # → modules/woocommerce/dist/tudorsync-woocommerce-<versión>.zip
```

Subir el zip en *Plugins › Añadir nuevo › Subir plugin* (o la carpeta `dist/tudorsync-woocommerce/`
a `wp-content/plugins/` por (S)FTP) y activarlo. Al activarse crea sus tablas
(`{prefijo}tudorsync_pending_stock`, `_api_log`, `_report_file`) y programa sus tareas. Requiere PHP
8.1+ con `zip`, `xml`, `gd` y `mbstring` (phpspreadsheet); `intl` es opcional (nombres de mes y país
del informe).

En desarrollo, `composer install` en `modules/woocommerce` enlaza `core/` con un symlink (path
repository): ⚠️ no borrar nada dentro de `vendor/tudorsync/core` por SFTP, borra el original.

Al borrar el plugin desde el admin se eliminan sus tablas, opciones y tareas; los códigos de modelo
de los productos y las atribuciones de los pedidos se quedan.

## Configuración

*WooCommerce › TUDOR e-Stock › Settings*:

| Ajuste | Por defecto | Notas |
|---|---|---|
| Environment | PREPROD | PREPROD / PROD, cada uno con su Client ID y Client Secret (el secret se guarda cifrado con las sales de `wp-config.php`) |
| Market / Country | — | 2 letras (`ES`). Sin él no se sincroniza nada |
| Value sent to TUDOR | Salable quantity | o «Always 1» |
| Products without stock management | Leave them out | o enviarlos como 1 si están «En stock» |
| Click & collect | No | `storePickupAvailable` de toda la tienda |
| Home delivery time (hours) | — | Solo dígitos; vacío = no se envía |
| SKU prefix / pattern / replacement | — | Regla para sacar el código del SKU: regex **con delimitadores** y reemplazo con `$1`… Ej.: `/^M?(\d{4,5}[A-Z0-9]*-\d{4})$/` → `M$1` |
| Product scope | — | `taxonomía:slug` separados por comas (`product_cat:tudor`, `product_brand:tudor`): la regla de SKU solo se prueba en esos productos. Un producto con el código puesto a mano se lee siempre |
| Language codes | language_region | `es-ES` o `es` |
| Full sync frequency | hourly | hourly / twicedaily / daily |
| Publish changes within a minute | Yes | Cola de cambios de stock publicada cada minuto |
| Cookie consent | Not required | `none` / WP Consent API (categoría, `statistics` por defecto) / cookie del banner (`nombre=valor`) |
| Click & collect shipping methods | `local_pickup, pickup_location` | Ids (o su comienzo) de los métodos de envío que son recogida, para el informe |

Los botones *Test Connection* (`GET /v1/point-of-sales`) y *Run Sync Now* usan lo guardado.

**Credenciales:** en una tienda de pruebas, solo PREPROD; PROD solo en la tienda real y cuando esté
validado. **Nunca las mismas en dos instalaciones**: cada batch pone a 0 lo que no lleva.

## Comandos (WP-CLI)

```sh
wp tudorsync catalog preview [--all] [--json-file=<archivo>]  # en seco: qué se enviaría y por qué no el resto
wp tudorsync sync run                                         # sincronización completa
wp tudorsync connection test                                  # GET /v1/point-of-sales
wp tudorsync realtime publish [--send]                        # cola de cambios: plan, o enviarla
wp tudorsync model-code fill [--apply] [--overwrite] [--all]  # rellenar el código (textos de las imágenes o regla de SKU)
wp tudorsync report programme-sales [--month=AAAA-MM] [--all-orders]
wp tudorsync api-log clean [--days=<n>]
```

(`--json` está reservado por WP-CLI, por eso es `--json-file`.)

`model-code fill` toma el código de los textos de las imágenes del producto (alt, título, leyenda:
como los nombra el banco de imágenes de TUDOR; si hay dos códigos distintos, «conflict») o, si no,
de la regla de SKU. Sin `--apply` no escribe nada; sin `--overwrite` no cambia códigos ya puestos.

## Cron y registro

WP-Cron: `tudorsync_run_sync` (frecuencia del ajuste), `tudorsync_publish_pending` (cada minuto) y
`tudorsync_clean_api_log` (diario, borra llamadas de más de 60 días). WP-Cron solo se dispara con
visitas: para que «en el minuto» sea un minuto, `define('DISABLE_WP_CRON', true);` en `wp-config.php`
y un cron de servidor cada minuto con `wp cron event run --due-now` (o pidiendo `wp-cron.php`).

Un candado (opción `tudorsync_send_lock`, se da por perdido a los 10 min) evita dos envíos a la vez.

Log propio en *WooCommerce › Estado › Registros*, fuente `tudorsync` (WooCommerce lo rota): envíos,
descartes de la revisión (`warning`), fallos (`error`), cola. Todas las llamadas a TUDOR quedan en
la tabla `{prefijo}tudorsync_api_log` (pestaña *API log*), con el secret, el token y la cabecera
`Authorization` enmascarados.

## Publicación en el minuto

Con «Publish changes within a minute» activo, se apuntan en `{prefijo}tudorsync_pending_stock` los
modelos TUDOR cuyo stock puede haber cambiado: stock guardado (a mano, importación o pedido:
`woocommerce_product_set_stock` / `woocommerce_variation_set_stock`), cambio de estado de stock,
producto guardado (código viejo y nuevo), enviado a la papelera, restaurado o borrado, pedido creado
en el checkout y cambio de estado de un pedido. Cada minuto:

- si algún modelo de la cola ya no está disponible, o hay más de 20, **un batch completo** (es lo
  único que lo retira en TUDOR);
- si no, un `POST /v1/stocks` por modelo; los que fallan se quedan en la cola;
- un **429** pausa la publicación en el minuto 5 minutos (la completa no se pausa); credenciales
  rechazadas o fallo del token cortan la vuelta;
- solo se borran de la cola las filas apuntadas antes de leer el catálogo.

Lo que se escriba en la base de datos sin pasar por WooCommerce no se ve: lo cubre la sincronización completa.

## Pedidos que llegan desde tudorwatch.com

`assets/js/utm-capture.js` (en todas las páginas, por las cachés de página) guarda la cookie propia
`tudorsync_utm` = `tudorwatch.com|<utm_medium>|<utm_campaign>|<hora>` durante 30 días cuando la visita
trae `utm_source=tudorwatch.com`; otra `utm_source` la borra (gana el último clic). Si hace falta
consentimiento y aún no lo hay, la UTM espera en `sessionStorage` y se escribe en cuanto se da.

Al hacer el pedido (checkout clásico y de bloques; nunca los pedidos creados en el admin) se guarda en
el pedido como meta `_tudorsync_utm_source`, `_medium`, `_campaign` y `_landed_at` (compatible con
HPOS) y se ve en la ficha del pedido. Cada línea TUDOR guarda además su código en
`_tudor_model_code`, para que el informe lo conozca aunque luego se borre el producto.

## Informe mensual

*WooCommerce › TUDOR e-Stock › Monthly report*: un mes por fila, con las ventas online y de click &
collect contadas de los pedidos atribuidos (unidades; sin cancelados, fallidos, pendientes de pago ni
borradores; sin restar devoluciones; meses en la zona horaria de la tienda) y las cifras a mano
(sesiones y visitantes únicos, obligatorias; añadidos al carrito, ventas y citas en boutique,
comentarios). *Generate* rellena la plantilla Excel de TUDOR (`MonthlyReportWriter` del core, hasta
15 meses, plantilla ES/EN/FR/DE/IT); sin las obligatorias solo como borrador. Los Excel se guardan en
la base de datos (`{prefijo}tudorsync_report_file`), no en `uploads/`.

## Admin

*WooCommerce › TUDOR e-Stock*:

- **Settings**: ajustes, estado (último test, última sincronización con su revisión, último envío en
  el minuto, pausa por 429, próxima ejecución) y los botones.
- **Programme data**: lo que se enviaría ahora mismo (sin enviar nada), por producto y con motivos;
  la cola; los últimos pedidos atribuidos.
- **API tests**: un botón por endpoint; `POST /v1/stocks` y batch con productos elegidos (valor
  opcional; 0 retira) o con el catálogo entero. Los POST están bloqueados en PROD.
- **API log**: llamadas con filtros (origen, operación, endpoint, resultado, fechas, ejecución) y el detalle.
- **Monthly report**: ver arriba.

## Pendiente

- Probar contra **PREPROD** de TUDOR con credenciales de la tienda y en su staging.
- Multi-idioma con WPML o Polylang: solo TranslatePress viene de serie; los demás, con el filtro
  `tudorsync_localized_urls`.
- Click & collect por punto de venta (`storesAvailabilityDetails` / RSWI), como en el resto de conectores.
- Traducción del admin al español (las cadenas están preparadas, dominio `tudorsync`).
