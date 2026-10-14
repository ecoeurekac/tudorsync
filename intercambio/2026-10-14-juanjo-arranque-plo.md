---
name: tudorsync-plo
description: Arrancar Tudorsync en Pedro Luis Olivares (Magento 2): quién es quién, reglas, estado del módulo, lecciones de Quera y pasos de instalación
metadata:
  type: project
  creado: 2026-10-14 (desde la sesión de Juanjo en el staging de Quera)
---

# Tudorsync en Pedro Luis Olivares (PLO, Magento 2)

PLO usa **el mismo módulo de Magento que Quera** (`modules/magento`, `Tudorsync_EcommerceSync`). Lo que se cambie aquí
llega también a Quera: nada de PLO dentro del módulo, y nada que rompa Quera.

## Quién habla y reglas de trabajo
- Cuenta de Claude **compartida**: si no se ha identificado, preguntar quién habla antes de editar. Se trabaja en español.
- **Juanjo** = conectores (`packages/tudorsync/modules/*`; aquí, `modules/magento`). **Jorge** = `core/`. Nunca editar la
  parte del otro: las peticiones van como archivo en `intercambio/` (`AAAA-MM-DD-autor-tema.md`; JSON `AAAAMMDD_plo.json`).
- Cambios en el contrato compartido (`CatalogConnectorInterface`, `StockAvailability`, `TudorApiClient`, `SyncEngine`,
  `ProgrammeSalesSourceInterface`) → "coordinar con Jorge".
- **Git (sesión de Juanjo):** commit + push sin preguntar, pero **solo de lo ya probado**; `git add` solo de rutas propias
  (nunca `-A` / `commit -a`); `git pull --ff-only` antes del push;
  `git -c user.name=Ecoeureka -c user.email=team@ecoeureka.com commit` + línea `Co-Authored-By`;
  prefijos `Juanjo - [Magento V1] - …` / `Juanjo - [Docs] - …`.
- Repo: `github.com/ecoeurekac/tudorsync`. **En el servidor de PLO hay que montar su propia deploy key** (fuera del docroot,
  `core.sshCommand "ssh -i <ruta>/id_ed25519 -o IdentitiesOnly=yes"`; la pública la añade Carlos/Jorge con escritura). No
  copiar claves de otros servidores ni imprimirlas.
- **Mismo módulo que Quera:** antes de tocar `modules/magento`, `git pull --ff-only` y `git log -- modules/magento`.
- Al acabar cada sesión: entrada en `packages/tudorsync/CLAUDE.local.md` (no versionado) y memoria del servidor al día.

## Reglas que no se negocian
- **Nada de datos de ninguna tienda en el módulo ni en core** (prefijos de SKU, métodos de envío, catálogos, nombres en
  comentarios/README). Lo de PLO → config del admin (vacía por defecto) o, si es un comportamiento distinto, un `di.xml`
  en un módulo propio de PLO en `app/code` (**no copiar el módulo**). Datos de PLO para pruebas → `doc/distribuidores/plo/`
  (no se versiona).
- **Credenciales TUDOR:** app PREPROD de PLO probada el 13-10 y el 14-10: token OK, `point-of-sales` 200 con **2 puntos de
  venta**, `stocks` vacío. En staging solo **PREPROD**; PROD solo en producción y cuando esté validado (España: 19-10).
  **Nunca las mismas credenciales en dos instalaciones** (cada batch pone a 0 lo que no lleva). Los secrets no se escriben
  en notas, commits ni memoria: se meten en el admin (se guardan cifrados).
- **Producción no se toca para desarrollar**: desarrollo, pruebas y crons en staging; en producción solo `git pull` de lo
  probado. Si el staging es copia de producción: aislarlo antes (correo a clientes, pasarelas, ERP/stock, tracking).
- ⚠️ `vendor/tudorsync/{core,module-magento}` son **symlinks** al checkout (path repositories): no borrar nada dentro por
  SFTP, borra el original (se perdió el checkout entero de Quera el 30-09).
- Pruebas de atribución con `utm_source=ecoeureka-test`, **nunca con `tudorwatch.com`**.

## Estado del módulo (a 14-10-2026, `eabfe26`)
- En marcha en Quera desde el 30-09 (Magento 2.4.8, PHP 8.3, MSI con una fuente). Sync completo, publicación en el minuto,
  atribución UTM (cookie `tudorsync_utm` + `tudorsync_order_attribution`), informe mensual y Datos del programa en
  *Informes › TUDOR e-Stock*, Pruebas de API y Registro de API. README de `modules/magento` al día.
- `eabfe26`: catálogo vacío no envía nada (`EmptyCatalogException` de core), revisión de batch y estricta como en core,
  y **sin datos de Quera**: el click & collect del informe va por config (`tudorsync/report/pickup_shipping_method_prefixes`).
- Requiere PHP ≥ 8.1 (core usa readonly, enums). Comprobar la versión de Magento y PHP de PLO antes de nada.

## Lecciones de Quera (Magento)
- **`setup:upgrade`** crea el atributo `tudor_model_code` y las tablas `tudorsync_*`. En Quera fallaba por un dato heredado
  (`sales_sequence_meta`): si en PLO falla, **parar y avisar**; no "arreglar" tablas de ventas sin hablarlo.
- **Modo production:** tras cualquier cambio de código, `setup:di:compile` y comprobar
  `grep -c __set_state generated/metadata/global.php` = 0 (un `= new Foo()` por defecto en un constructor tumba toda la
  tienda). El primer `di:compile` falló a menudo («generated/code/... cannot be deleted»): hacerlo con
  `maintenance:enable` y los crons parados, y repetir si falla. Luego `static-content:deploy` y `cache:flush`.
- Estáticos del admin: el deploy no sobrescribe; borrar `pub/static/adminhtml/.../Tudorsync_EcommerceSync` y
  `js-translation.json` antes. En Cloudways, Varnish cachea estáticos: purgar en el panel.
- Los jobs van en el grupo `default` de cron de Magento. Si el cron de Magento está parado en el staging, se pueden lanzar
  con un script propio (en Quera: `private_html/tudorsync-cron-staging/run.php hourly|minute`).
- Stock: lo que un ERP escribe con SQL directo no entra en la cola del minuto; solo lo recoge el sync de cada hora.
- `value` = cantidad vendible (MSI descuenta reservas). Se excluye lo deshabilitado, no visible, sin gestión de stock,
  sin stock o con backorders ("bajo demanda" nunca se publica).

## Pasos
1. **Reconocimiento (solo lectura):** versión de Magento/PHP, modo, MSI (fuentes/stocks), store views e idiomas, cómo
   están las SKU y atributos de los relojes TUDOR (¿hay un campo con el código de modelo `M…-…`? ¿etiquetas de imagen?),
   métodos de envío de recogida en tienda, gestor de cookies, integraciones de stock/ERP, crons. Si hay staging y
   producción, cuál es cuál.
2. **Instalar** en staging: checkout en `packages/tudorsync`, path repositories en el `composer.json` raíz,
   `composer require tudorsync/module-magento:*`, `module:enable Tudorsync_EcommerceSync`, `setup:upgrade`,
   y si está en production `di:compile` + `static-content:deploy` + `cache:flush`.
3. **Configurar sin credenciales:** país `ES`, entorno `staging`, regla de SKU propia de PLO (o atributo), formato de URLs,
   consentimiento, prefijos de recogida en tienda. `tudorsync:model-code:fill` (en seco, luego `--apply`), cruzar con los
   Excel de modelos de TUDOR (`doc/`) y `tudorsync:catalog:preview` hasta que salga lo esperado (catálogo vacío = no envía).
4. **PREPROD:** credenciales PREPROD en el admin, `tudorsync:connection:test` (2 PdV), `tudorsync:sync:run`,
   `getStocks` en Pruebas de API para comprobar. Después, crons (cada hora y cada minuto) y pruebas de cambios de stock.
5. Anotar lo de PLO en `doc/distribuidores/plo/` y el resultado en `intercambio/` para Jorge.
