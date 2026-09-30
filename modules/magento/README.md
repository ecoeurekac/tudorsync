# Tudorsync_EcommerceSync (Magento 2)

Conector Magento de tudorsync. Probado en Quera (Magento 2.4.8, PHP 8.3, MSI con una fuente)
el 30-09-2026; la misma base se replicará en Pedro Luis Olivares.

## Qué hace

Lee el catálogo, decide qué relojes TUDOR se pueden comprar **ya** y entrega a
`tudorsync/core` un `StockAvailability` por código de modelo. El core se encarga del resto
(filtro final, llamada a `POST /v1/stocks/batch`).

| Paso | Regla |
|---|---|
| Participa | Tiene código de modelo: atributo `tudor_model_code` o, si está vacío, el derivado del SKU (regla opcional en config) |
| Se excluye | Deshabilitado en la tienda por defecto, no visible, sin gestión de stock, sin stock, admite backorders, o **sin cantidad vendible** (MSI: se descuentan las reservas de pedidos pendientes de envío) |
| Varias fichas del mismo modelo | Un único registro: se suman las cantidades vendibles y la URL es la de la ficha con más stock |
| `value` | Cantidad vendible, o siempre 1 (config «Value Sent to TUDOR»), hasta que TUDOR aclare qué significa |
| URLs | `defaultUrl` = tienda por defecto; `localizedUrls` = una por tienda activa donde el producto esté a la venta, con clave `es-ES` / `en-GB`… (o `es` / `en`, según la config) y UTM |

## Configuración

*Stores > Configuration > Catalog > TUDOR E-commerce Sync*

- **General**: país (ISO de 2 letras; sin él no se sincroniza), entorno, claves de staging y
  producción (se guardan cifradas), «value», click & collect (todo o nada), plazo de entrega
  a domicilio (opcional), frecuencia del cron y los botones Test Connection / Run Sync Now.
- **TUDOR Model Code**: regla opcional para sacar el código del SKU: prefijo (filtro SQL) +
  expresión regular + sustitución. Ejemplo: prefijo `1001TU`, patrón `/^1001TU(.+)-\d{4}$/`,
  sustitución `$1`. **El atributo, si está relleno, siempre gana.**
- **Product URLs**: formato de las claves de idioma.

Los botones usan los valores **guardados**: primero hay que guardar y después probar.

## Comandos

```sh
bin/magento tudorsync:catalog:preview [--all] [--json=fichero.json]  # en seco: no llama a TUDOR
bin/magento tudorsync:connection:test                                # GET /v1/point-of-sales
bin/magento tudorsync:sync:run                                       # sync real, igual que el cron
```

`preview` muestra lo que se enviaría y, para cada producto candidato, si se envía, se agrupa
con otro o se excluye, y por qué.

## Cron y registro

- Tarea `tudorsync_run_sync` (grupo `default`), frecuencia configurable (por defecto cada hora).
  Sin clave o sin país, se salta la ejecución sin marcar error.
- Log propio: `var/log/tudorsync.log`. El último test y el último sync (cron, admin o CLI) se
  guardan en la tabla `flag` y se ven en la pantalla de configuración.

## Pedidos que llegan desde TUDOR (informe mensual)

Datos para la fila «Relojes TUDOR vendidos en línea mediante este programa» del informe mensual
de TUDOR (plantilla en `core/resources/report-templates/`).

- **Al llegar:** si la URL trae `utm_source=tudorwatch.com`, un script en todas las páginas
  (`view/frontend/templates/utm-capture.phtml`) guarda la cookie propia `tudorsync_utm`
  (`fuente|medio|campaña|marca de tiempo`, 30 días). Se hace en el navegador porque las
  fichas salen de la caché de página completa y el PHP no se ejecuta.
- **Último clic:** si luego llega con cualquier otra `utm_source` (Google, newsletter…), la
  cookie se borra y el pedido ya no cuenta para TUDOR.
- **Al hacer el pedido:** `Observer\SaveOrderAttribution` (evento
  `sales_model_service_quote_submit_success`, solo en frontend y REST, nunca en el admin) guarda
  una fila en **`tudorsync_order_attribution`**: `order_id`, `increment_id`, `store_id`, las 3 UTM,
  `landed_at` (UTC) y `created_at`. Si falla, lo registra en `var/log/tudorsync.log` y el pedido
  sigue adelante.
- **Tabla aparte, no columnas en `sales_order`:** una recarga de datos de las tablas de ventas no
  le afecta. Pero sus filas apuntan a `entity_id` de pedidos: **tras recargar los pedidos de otra
  instalación hay que vaciarla** (`TRUNCATE tudorsync_order_attribution`), o se atribuirían a
  pedidos que no son.

Qué es un «reloj TUDOR vendido» (líneas con código de modelo, pedidos cancelados o devueltos,
click & collect) lo decide el informe al leer la tabla, no la captura.

## Instalación en otra tienda (PLO)

1. Path repos o paquete de `tudorsync/core` + `tudorsync/module-magento` en el `composer.json`
   de la tienda y `composer require`.
2. `bin/magento module:enable Tudorsync_EcommerceSync && bin/magento setup:upgrade` (el patch
   crea el atributo `tudor_model_code` y la tabla `tudorsync_order_attribution`). En producción: `setup:di:compile` y
   `setup:static-content:deploy`.
3. Configurar y ejecutar `tudorsync:catalog:preview` antes de poner la clave.

Sin MSI el módulo también funciona: usa la cantidad física (el preview lo avisa).

**Si cambias constructores** de clases del módulo en modo developer, borra
`generated/code/Tudorsync`: los interceptores generados copian el constructor antiguo y dan
un error fatal.

## Pendiente

- Click & collect por punto de venta (`storesAvailabilityDetails`, RSWI): depende de TUDOR.
- MSI con **varias** fuentes o stocks por website: hoy se usa el stock del website de la
  tienda por defecto (en Quera solo hay uno).
- Una tienda con varios mercados (un país por website) necesitaría un batch por país.
