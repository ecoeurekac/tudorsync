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

## Instalación en otra tienda (PLO)

1. Path repos o paquete de `tudorsync/core` + `tudorsync/module-magento` en el `composer.json`
   de la tienda y `composer require`.
2. `bin/magento module:enable Tudorsync_EcommerceSync && bin/magento setup:upgrade` (el patch
   crea el atributo `tudor_model_code`). En producción: `setup:di:compile` y
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
