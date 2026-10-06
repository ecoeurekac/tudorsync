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

- **General**: país (ISO de 2 letras; sin él no se sincroniza), entorno, **Client ID y Client
  Secret** de staging y producción (OAuth2 de TUDOR; el secret se guarda cifrado), «value», click & collect (todo o nada), plazo de entrega
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
bin/magento tudorsync:model-code:fill [--apply] [--overwrite] [--all]  # rellena tudor_model_code (en seco sin --apply)
bin/magento tudorsync:realtime:publish [--send]                      # cola de cambios TUDOR y qué se enviaría (en seco sin --send)
bin/magento tudorsync:report:programme-sales [--month=AAAA-MM] [--store=N] [--all-orders]  # cifras de ventas del informe mensual
```

`model-code:fill` rellena el atributo (tienda 0) de las fichas con el prefijo de SKU configurado:
primero con el código que traen las **etiquetas de imagen** de TUDOR (`M79363N-0002`), y si no hay,
con la regla de SKU. Los nombres de fichero de las fotos no se usan (algunos son genéricos,
`M2542GXX7NU-0002`). No toca los valores ya puestos salvo con `--overwrite`. Se puede repetir
(p. ej. tras una re-migración de datos).

Regla de Quera (30-09): prefijo `1001TU`, patrón
`/^1001TU(.+?)(?:\d{5}[A-Z]?|CUERO|TEJIDO|CROCO|CAUCHO)-(\d{4})$/`, sustitución `M$1-$2`
(`1001TU79363NCUERO-0002` → `M79363N-0002`). Coincide con la etiqueta en 86 de 87 fichas.

`preview` muestra lo que se enviaría y, para cada producto candidato, si se envía, se agrupa
con otro o se excluye, y por qué.

## Cron y registro

- Tarea `tudorsync_run_sync` (grupo `default`), frecuencia configurable (por defecto cada hora).
  Sin clave o sin país, se salta la ejecución sin marcar error.
- Tarea `tudorsync_publish_pending`, **cada minuto**: publica solo los modelos TUDOR que han
  cambiado (ver «Publicación en el minuto»). Con la cola vacía no hace nada.
- Log propio: `var/log/tudorsync.log`. El último test y el último sync (cron, admin o CLI) se
  guardan en la tabla `flag` y se ven en la pantalla de configuración.

## Publicación en el minuto (solo TUDOR)

TUDOR pide disponibilidad «en tiempo real»: con piezas únicas, un sync cada hora deja un reloj
vendido anunciado hasta 59 min. Además del sync completo, el módulo vigila los cambios **solo de
productos TUDOR** (los que dan código de modelo con la misma regla que el sync) y los publica en
el minuto siguiente. Interruptor: *Publish Changes Within a Minute* (`tudorsync/general/realtime_enabled`, sí por defecto).

1. **Qué se vigila** (`Model\Realtime\ChangeRecorder`; nunca lanza excepción, va dentro del checkout):
   - reservas MSI (`AppendReservationsInterface`, plugin): pedido hecho, cancelado, enviado, abonado;
   - stock guardado por MSI (`SourceItemsSaveInterface`, plugin): admin, import del ERP de Quera;
   - ficha guardada o borrada (`catalog_product_save_after` / `_delete_after`): se encolan el
     código de antes y el de después;
   - stock antiguo sin MSI (`cataloginventory_stock_item_save_after`), para tiendas sin MSI.

   Lo que se escribe con SQL directo (parte del import del ERP de Quera) no pasa por aquí: lo
   recoge el sync completo.
2. **Cola:** tabla `tudorsync_pending_stock`, una fila por código de modelo (no por producto). Solo
   se llama a TUDOR desde el cron, nunca durante la compra.
3. **Cron cada minuto** (`SyncRunner::publishPending()`):
   - todos los modelos de la cola siguen disponibles → un `POST /v1/stocks` por modelo (registro
     completo, cantidad sumada de todas sus fichas, igual que en el batch);
   - alguno ya no está disponible (agotado, desactivado, borrado), o hay más de 20 → **un batch
     completo**: TUDOR pone a 0 lo que falta, que es la forma documentada de retirar un modelo;
   - un modelo que falla sigue en la cola y se reintenta al minuto siguiente;
   - **429 (demasiadas peticiones)**: se para en ese mismo modelo (el resto sigue en la cola) y la
     publicación en el minuto se pausa 5 min (flag `tudorsync_realtime_paused_until`); el sync de
     cada hora no se pausa. Un fallo de credenciales o del servicio de token también corta la
     ejecución, porque daría lo mismo con todos los modelos.
4. **Red de seguridad:** el sync completo sigue cada hora y vacía la cola de todo lo anterior a él.
5. Mientras no se pueda hablar con TUDOR (credenciales, país) la cola se conserva.

`tudorsync:realtime:publish` enseña la cola y qué se haría, sin llamar a TUDOR; `--send` hace lo
mismo que el cron.

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

Qué es un «reloj TUDOR vendido» lo decide el informe al leer la tabla, no la captura:
**`Model\Report\ProgrammeSalesSource`** (y el comando `tudorsync:report:programme-sales`, que
enseña además las líneas de pedido contadas). Criterio propuesto a core el 30-09, **pendiente de
que Jorge lo confirme** para que los 3 conectores cuenten igual:

- **Unidades**, no pedidos: cantidad pedida menos cancelada de cada línea de primer nivel cuyo
  producto da un código de modelo TUDOR (el mismo `ModelCodeResolver` del sync; si la ficha ya
  no existe, vale la regla de SKU sobre el SKU guardado en el pedido).
- **Se excluyen los pedidos cancelados; las devoluciones no se restan** (pueden llegar después de
  enviado el informe del mes).
- **Click & collect** = método de envío que empieza por uno de los prefijos del argumento DI
  `pickupShippingMethodPrefixes` (Quera: `amstorepick_`, Amasty Store Pickup). Lista vacía = la
  tienda no lo distingue y el campo queda en blanco (`null`).
- **Mes** en la zona horaria de la tienda (`general/locale/timezone`); las fechas de Magento
  están en UTC y se convierten.
- `--all-orders` ignora la atribución y cuenta todos los pedidos: solo para comprobar el conteo
  mientras la tabla está vacía. Esas cifras **no** son las del informe.

Cuando core publique la interfaz de la fuente de ventas, esta clase la implementa (la forma de
`ProgrammeSales` es la de la propuesta).

## Informes y datos en el admin

Menú **Informes › TUDOR e-Stock** (permiso `Tudorsync_EcommerceSync::reports`, colgado de
`Magento_Reports::report`):

- **Informe mensual** (`tudorsync/report/index`): una fila por mes desde el primero que importa (el
  del primer pedido atribuido o con datos guardados, y como mínimo el mes pasado). Las ventas online
  y el click & collect se calculan siempre al vuelo con `ProgrammeSalesSource`; lo demás se escribe a
  mano en «Editar» (`tudorsync/report/edit`) y se guarda en `tudorsync_report_period`:
  sesiones y visitantes únicos (obligatorios en la plantilla; a mano hasta que core los lea de GA4),
  añadidos a la cesta, ventas y citas en boutique, comentarios.
- **Generar Excel**: rango de meses (máximo 15, lo que cabe en la plantilla) e idioma (ES/EN/FR/DE/IT).
  Escribe la plantilla oficial con `MonthlyReportWriter` de core (sin tocar core: se usa tal cual).
  Sin sesiones o visitantes en algún mes se niega, salvo que se marque «Borrador» (van a 0 y el
  fichero lleva `_BORRADOR`). El periodo sale como «Octubre 2026» en el idioma elegido y el país
  con su nombre en ese idioma.
- **Informes generados**: cada Excel se guarda **en la base de datos** (`tudorsync_report_file`, no en
  `var/`, que se vacía) con las cifras con que se hizo, para volver a descargar exactamente lo que se
  mandó a TUDOR. Se pueden borrar.
- **Datos del programa** (`tudorsync/data/index`): conexión y última prueba/sincronización, el catálogo
  que se enviaría ahora (mismo `CatalogConnector` que la sync; filtro enviados / fuera / todos, con
  el motivo de cada exclusión y la URL enviada), la cola de publicación en el minuto y los últimos
  pedidos llegados desde tudorwatch.com. Esta página no envía nada a TUDOR.

- **Pruebas de API** (`tudorsync/apitest/index`, JS propio `view/adminhtml/web/js/api-tester.js`): un botón
  por endpoint — `GET /health`, `GET /v1/point-of-sales`, `GET /v1/stocks` (página y tamaño), `POST /v1/stocks`
  y `POST /v1/stocks/batch` — y la petición y la respuesta completas en pantalla. Para los POST se buscan
  productos TUDOR (SKU o nombre), se ve el JSON antes de enviar y se puede forzar el valor (0 = retirar). El
  batch puede ser el catálogo completo o solo los elegidos (pide confirmar: pone a 0 lo que no va). Todo pasa
  por `TudorApiClient` de core salvo `/health`, que core no tiene (`Model\Api\ApiTester` copia sus URL base;
  petición en `intercambio/2026-10-06-juanjo-peticion-health.md`). **Los POST se niegan en el entorno de
  producción** (en pantalla y en el servidor).
- **Registro de API** (`tudorsync/apilog/index`): cada llamada HTTP a TUDOR —token, API y health— con fecha,
  origen (`cron`, `admin`, `cli`, `test`), operación (`full_sync`, `realtime`, `test_connection`,
  `api_test:…`), usuario del admin, entorno, endpoint, cabeceras, cuerpo enviado y recibido y duración.
  Filtros por origen, operación, endpoint, resultado y fechas; las llamadas de una misma operación comparten
  `run_id`. Lo hace `Model\Api\LoggingHttpClient`, el `HttpClientInterface` que el módulo pasa a core, así
  que las sincronizaciones reales quedan igual de registradas que las pruebas. El client secret, el Bearer y
  los `access_token` se guardan como `***`. Tabla `tudorsync_api_log`; el cron `tudorsync_clean_api_log`
  borra lo de más de 60 días (`ApiLog::RETENTION_DAYS`).

Despliegue de esta parte en modo producción: `setup:upgrade` (tabla del registro) + `di:compile` +
`setup:static-content:deploy -f --area adminhtml es_ES en_US` (el JS y el CSS nuevos). Ojo: el despliegue **no
sobrescribe** ficheros que ya existen en `pub/static`; para que entren textos nuevos del JS hay que borrar antes
`pub/static/adminhtml/Magento/backend/<locale>/js-translation.json` (y el `.js`/`.css` del módulo si cambian).
Al cambiar un constructor o la firma de un método público, `generated/code/Tudorsync` queda desfasado y
`bin/magento` falla hasta borrarlo y recompilar.

Las tablas nuevas requieren `setup:upgrade` (en Quera, con el guion de intercambio de
`sales_sequence_meta`). Textos en inglés con traducción en `i18n/es_ES.csv` (solo frases propias:
las palabras genéricas las traducen los paquetes de idioma instalados).

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
un error fatal. **En modo producción** (Quera desde el 02-10) hay que `setup:di:compile`; antes de
subir un cambio, compilar en staging y comprobar que `generated/metadata/global.php` no tiene
`__set_state`.

**Nunca `= new Clase()` como valor por defecto de un parámetro de constructor** en clases que crea el
DI de Magento: `di:compile` lo serializa como `Clase::__set_state()`, que no existe, y la tienda entera
deja de arrancar en modo producción. Usar `?Clase $x = null` y crearlo dentro del constructor (ver
`CatalogConnector`).

## Pendiente

- Click & collect por punto de venta (`storesAvailabilityDetails`, RSWI): depende de TUDOR.
- MSI con **varias** fuentes o stocks por website: hoy se usa el stock del website de la
  tienda por defecto (en Quera solo hay uno).
- Una tienda con varios mercados (un país por website) necesitaría un batch por país.
