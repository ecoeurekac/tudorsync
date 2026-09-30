# Plan de trabajo — Juanjo (conectores, `modules/*`)

30-09-2026. Montado a partir de `doc/Tudorsync Arquitectura y Reparto de Trabajo.docx`,
`doc/Tudorsync Estado y Pendientes(1).pdf`, `CONTEXT.md`, `README.md`, la spec de la API
(`doc/stock-retail-publish-...-oas.zip`), el código de `modules/magento` y consultas de solo
lectura a la BD de Quera. El PDF de onboarding de TUDOR no se pudo leer: es escaneado y en el
servidor no hay herramientas para extraer el texto.

Orden (según el reparto): **Quera → PLO → Grau → Gordillo/Saphir.**

---

## Fase 1 — Quera (Magento, instalado en este servidor)

### 1.1 Lo que ya se sabe de Quera (medido el 30-09)

| Dato | Valor |
|---|---|
| Tiendas | `es` (es_ES, heredado del default), `en` (en_GB), `fr` (fr_FR), `ca` (ca_ES) |
| Atributo `tudor_model_code` | creado (id 292), el patch ya está aplicado; **0 productos lo tienen relleno** |
| Productos TUDOR | 252 (todos simples, visibles, activos); **77 con stock**, 175 sin stock |
| Cómo reconocerlos | SKU `1001TU…` (los 252 y solo esos); categoría `TUDOR` (193). `mgs_brand` **está vacío** en todos |
| Patrón del SKU | `1001TU` + referencia + código de ¿brazalete/esfera? + `-NNNN` — p. ej. `1001TU79360N61580-0014` |
| Modelos distintos | 141 «bases» de SKU (quitando el `-NNNN`); **63 con stock** repartidas en 77 fichas |
| Stock | MSI con una sola fuente (`default`) y un solo stock; backorders desactivados de forma global y ninguna ficha lo cambia |
| Origen del stock | ERP PQnet → AdvImport (cron nocturno); PQnetStock sigue **apagado** hasta el go-live |

### 1.2 Problemas detectados en el código — ✅ ARREGLADOS el 30-09 en `modules/magento`

Ver `modules/magento/README.md`. Además de los puntos de abajo: la clave de API se leía **cifrada**
(se habría mandado el texto cifrado como token); el estado de los botones no se veía hasta
limpiar la caché (ahora va en la tabla `flag`); el cron no guardaba estado; Curl sin timeout;
script inline sin nonce CSP. Nuevos: `tudorsync:catalog:preview` (en seco), `tudorsync:sync:run`,
`tudorsync:connection:test`, log `var/log/tudorsync.log`, regla opcional SKU → mc, campos
«value» y plazo de entrega. Prueba en seco real: `intercambio/20260930_quera.json`.
Nota para Jorge: con 0 modelos disponibles el batch sale vacío y TUDOR pone todo a 0 (es lo
correcto según la spec, pero una regla de SKU mal puesta tendría el mismo efecto: revisar siempre con preview).

1. **Claves de idioma con el formato equivocado.** `Config::getLocaleCode()` devuelve `en_GB`,
   pero TUDOR espera `en` / `fr-FR` (BCP 47, con guion). Hay que convertirlas, y decidir si
   se manda idioma solo (`es`) o idioma-región (`es-ES`). Esto último lo tiene que confirmar Jorge con TUDOR.
2. **El mismo modelo aparece en varias fichas.** 8 modelos con stock están en 2 o 3 fichas a la
   vez (p. ej. `1001TU79360N61580-0013` y `-0014`). Si el `mc` sale igual, el conector
   manda registros duplicados y el core **no** los agrupa (`SyncEngine` pasa la lista tal
   cual). El conector tiene que agrupar por `mc` (sumar el stock y elegir una URL, por ejemplo la
   de la ficha con más stock). Se hace dentro del conector, así que no cambia el contrato.
   Depende de 1.3: si el `-NNNN` es parte del `mc` de TUDOR, a lo mejor no hay duplicados.
3. **El stock tiene que ser el vendible, no el físico.** `StockRegistryInterface::getQty()` no
   descuenta las reservas de MSI. Quera tiene reservas de pedidos pendientes de envío y hay piezas
   únicas: un reloj ya vendido y todavía sin enviar se seguiría publicando en tudorwatch.com.
   Propuesta: usar `GetProductSalableQtyInterface` (stock 1). Esto es la parte «MSI» sin dueño
   del reparto, pero en Quera no es multialmacén, son reservas.
4. `value` = stock real: queda pendiente de lo que responda TUDOR sobre qué significa ese campo (Jorge).
5. De menor importancia: el filtro de `status` solo mira la tienda por defecto; en cada tienda se recarga la ficha
   con `getById` (4 × 77 cargas: asumible).

### 1.3 Mapeo producto → código de modelo TUDOR (a confirmar con Quera y con TUDOR)

- Hoy nadie rellena `tudor_model_code`, pero **la referencia TUDOR parece estar ya dentro del SKU**.
  Si se confirma la regla, se puede **rellenar de forma masiva con un guion** (sin
  `setup:upgrade`) en vez de que el equipo de Quera lo meta a mano ficha por ficha.
- Hace falta saber el **formato exacto del `mc`** que espera TUDOR (¿`M79360N-0002`?). En la spec
  solo aparece `TMC-001` de ejemplo. → **coordinar con Jorge** (pregunta para TUDOR).
- ⚠️ **Go-live de Quera:** esta instalación es la nueva, todavía en staging. En el go-live se
  vuelven a traer los datos de producción, y **los valores de `tudor_model_code` que se rellenen
  aquí se perderían**. El guion de relleno tiene que poder repetirse después de la migración; hay que avisar
  a la persona que lleva el Magento de Quera para que lo meta en su checklist del go-live.
- El atributo no lo toca AdvImport (el ERP no lo conoce), así que los imports nocturnos no lo borran.

### 1.4 Prueba rápida en la instalación real — ✅ 30-09 por CLI (falta mirar la pantalla en el admin con sesión)

1. La pantalla *Stores > Configuration > Catalog > TUDOR E-commerce Sync* carga bien.
2. Los botones Test Connection / Run Sync Now: rutas, ACL, `FORM_KEY` y la respuesta JSON cuando no
   hay clave (tiene que dar un error controlado, no un 500).
3. **Prueba en seco del conector** por CLI (sin llamar a TUDOR): guardar la salida real de
   `getAvailableCatalog()` en `intercambio/AAAAMMDD_quera.json`. Sirve
   para comprobar las URLs por idioma (`/es/`, `/en/`…), los UTM y los duplicados, y **le sirve a Jorge**.
4. Cron `tudorsync_run_sync` (cada hora por defecto): comprobar que está registrado. Sin clave
   se salta la ejecución (visto en el código); comprobar que el aviso del log no llena `system.log`.

### 1.5 Preguntas para Quera

- ¿La referencia TUDOR está en el SKU (`1001TU…`)? ¿Qué significan el bloque que va tras la referencia y el `-NNNN`? 54 SKUs antiguos no llevan `-NNNN` (p. ej. `1001TU79230NCUERO`).
- Las fichas repetidas del mismo modelo, ¿son a propósito (distintas unidades o variantes) o sobran?
- ¿Todos los TUDOR se venden online o hay alguno que sea solo «consultar/reservar»? (Afecta a la exclusión «bajo demanda».)
- ¿Quieren click & collect? ¿En qué tiendas físicas?
- ¿Mercado solo `ES`?

### 1.6 Pendiente para el Test Connection de verdad (bloqueado por Jorge/TUDOR)

URL base, esquema de autenticación y las claves de staging de TUDOR.

---

## Fase 2 — Pedro Luis Olivares (Magento)

Mismo recorrido que la fase 1 en su instalación. Primero hay que conseguir: acceso (¿tienen staging?),
versión de Magento y PHP, si tienen MSI y con cuántas fuentes, idiomas y tiendas, y cómo tienen
la referencia TUDOR en el catálogo. Instalación: path repo + `setup:upgrade` (lleva el patch del
atributo), cosa que habrá que coordinar con quien lleve esa tienda.

## Fase 3 — Grau (PrestaShop)

Sin probar nunca en un PrestaShop real. Hace falta: staging, versión de PS, instalar el módulo
(crea la característica «TUDOR Model Code»), revisar la exclusión `cantidad ≤ 0` frente a su configuración
de pedidos sin stock y probar los 2 formularios. **Cron de servidor** que llame al controlador
con token: es trabajo de infraestructura en su hosting (sin dueño en el reparto).

## Fase 4 — Gordillo y Saphir (WooCommerce)

Sin probar nunca en un WordPress real. Hace falta: staging, **plugin de idiomas** (Polylang, WPML
o ninguno) para enganchar el filtro `tudorsync_localized_urls`, y comprobar que WP-Cron se ejecuta
de verdad (con poco tráfico no se dispara; puede hacer falta un cron de servidor).

---

## Qué depende de Jorge (core/TUDOR)

| Tema | Por qué me bloquea |
|---|---|
| URL base + autenticación + claves de staging | Test Connection / Run Sync Now de verdad |
| Formato de `mc` | Mapeo y relleno masivo en Quera (1.3) |
| Formato de las claves de idioma (`es` o `es-ES`) | Arreglo 1.2.1 |
| Semántica de `value` | Qué número manda cada conector |
| RSWI = `stoId` | Click & collect por tienda (más adelante) |

Ninguno de los arreglos de 1.2 cambia `CatalogConnectorInterface` ni `StockAvailability`.

## Cosas del entorno pendientes

- Quitar el symlink de compatibilidad `var/tudorsync-src` y corregir las 2 `url` viejas de
  `vendor/composer/installed.json`.
- 2 commits locales sin push (`34dcb7f`, `9a2a13e`).
- `pub/tudor/composer.json` y `composer.lock` se pueden descargar públicamente (es del micrositio,
  no de tudorsync; lo lleva el equipo del Magento de Quera).
