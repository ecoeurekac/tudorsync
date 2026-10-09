# Contexto del proyecto — Tudorsync

Este documento resume el contexto de negocio y las decisiones tomadas hasta ahora, para que
cualquiera que se sume al proyecto (persona o su propia sesión de Claude Code) tenga el
mismo punto de partida sin tener que reconstruirlo desde cero. El detalle técnico de
implementación vive en [`README.md`](README.md); aquí está el "por qué" y la cronología.

## Qué es tudorsync

Tudorsync conecta el inventario online de **5 distribuidores autorizados TUDOR** con el
programa oficial de e-commerce de TUDOR: cuando un reloj está disponible para compra
inmediata en la tienda de un distribuidor, tudorwatch.com muestra el botón "Comprar ahora"
con enlace directo a la ficha de producto de ese distribuidor.

La documentación oficial de TUDOR que originó el proyecto está en `doc/`:
- `Primeros pasos del programa de comercio electrónico de TUDOR...pdf` — visión general del programa.
- `stock-retail-publish-public-rest-api-1.7.0-fat-oas.zip` / `-raml.zip` — especificación técnica real de la API ("e-Stock Retail Publish API" v1.7.0).
- `e-Com-e-Stock_program-template.zip` — plantillas Excel reales del informe mensual de ventas (5 idiomas), ya copiadas también a `core/resources/report-templates/`.

## Clientes y plataformas

| Cliente | Plataforma |
| --- | --- |
| Pedro Luis Olivares | Magento |
| Quera | Magento |
| Grau | PrestaShop |
| Gordillo | WooCommerce |
| Saphir | WooCommerce |

## Cronología de decisiones

1. **Requisito multi-cliente/multi-plataforma.** Un mismo contrato con TUDOR debe cubrir 5
   tiendas en 3 plataformas distintas. Decisión: construirlo de forma modular, no como 5
   integraciones independientes.
2. **Arquitectura: core + adaptadores.** Un núcleo (`tudorsync/core`) agnóstico de
   plataforma con toda la lógica que TUDOR exige, más un conector delgado por plataforma que
   solo traduce el catálogo/stock nativo al formato del core.
3. **Modelo de despliegue: cambiado de servicio centralizado a híbrido.** Primero se eligió
   un servicio único operado por la agencia; luego se cambió a **híbrido**: cada módulo
   corre **dentro de la propia tienda del cliente** (módulo Magento, módulo PrestaShop,
   plugin WooCommerce), sin servidor central. Cada tienda guarda sus propias credenciales y
   dispara su propio cron nativo.
4. **Repo: monorepo**, con `core/`, `modules/magento`, `modules/prestashop`,
   `modules/woocommerce` y `doc/` en un mismo repositorio.
5. **Se obtuvo la especificación técnica real de TUDOR** (antes solo había el PDF de
   onboarding). Esto obligó a reescribir `core` para que coincidiera con el contrato real:
   endpoints reales, formato NDJSON del batch, campos exactos de `StockCreateDto`, y la
   estructura real del Excel de reporte mensual (que resultó ser un informe **agregado
   mensual**, no un listado de ventas individuales).
6. **Se construyeron los 3 conectores reales** (ya no stubs), cada uno con: un campo nuevo
   para el código de modelo TUDOR, lógica de exclusión de "bajo demanda" derivada del stock
   nativo de cada plataforma, pantalla de configuración admin, y disparador de sync.
7. **Se agregaron acciones de admin**: "Test Connection" (llama a
   `GET /v1/point-of-sales`) y "Run Sync Now" (corre el mismo `SyncEngine` que el cron), con
   el resultado persistido y visible en cada pantalla de configuración.
8. **Se publicó el repo en GitHub** (`github.com/ecoeurekac/tudorsync`) y se armaron tres
   documentos de trabajo (ver más abajo).
9. **Reparto del trabajo (29-09).** Jorge, el core y la relación con TUDOR; Juanjo, los
   conectores (Quera → PLO → Grau → Gordillo/Saphir). Cada uno edita solo su parte y pide los
   cambios en la del otro por `intercambio/` (reglas completas en [`CLAUDE.md`](CLAUDE.md)). El
   checkout pasó de `var/tudorsync-src` a `packages/tudorsync`, dentro del Magento de Quera.
10. **Pérdida del checkout (30-09).** Se vació `packages/tudorsync` entero al borrar dentro de
    un symlink por SFTP; se recuperó de GitHub. Desde entonces, todo cambio probado va en un
    commit con push inmediato, y no se borra nada dentro de los symlinks de `vendor/tudorsync/`.
11. **TUDOR resuelto (30-09 – 02-10).** La URL base y la autenticación (OAuth2 client
    credentials) salieron de la documentación «Community Asset» y la colección Postman, y se
    probaron en PREPROD. El id RSWI es el `stoId` de `GET /v1/point-of-sales`.
12. **Conector de Magento en Quera (30-09).** El código de modelo sale del atributo o, si está
    vacío, de una regla sobre el SKU configurable (`tudorsync:model-code:fill` rellena el
    atributo). Primera prueba en seco: 77 fichas con stock → 63 modelos.
13. **Pedidos que llegan desde tudorwatch.com (30-09).** Para el informe mensual, el módulo
    guarda la UTM de TUDOR en una cookie (`tudorsync_utm`) y apunta los pedidos atribuidos en
    `tudorsync_order_attribution`. El 08-10 se añadió el consentimiento con CookieScript.
14. **Publicación en el minuto (02-10).** TUDOR pide disponibilidad en tiempo real y una pieza
    única vendida no puede seguir anunciada hasta 59 min: Magento publica en el minuto los
    modelos TUDOR que cambian, y el sync completo de cada hora queda como red de seguridad.
15. **Producción y staging (02-10).** Acordado con Bea: desde el 05-10 la instalación donde
    vive el checkout es la tienda **real** de Quera, y el desarrollo y las pruebas pasan a un
    staging (creado el 06-10). Credenciales PREPROD solo en el staging y PROD solo en
    producción, nunca las mismas en las dos (cada batch pone a 0 lo que no lleva). Magento en
    modo producción: nada de objetos como valor por defecto en constructores (rompe
    `setup:di:compile`). España se activa en TUDOR el **19-10**.
16. **Primer catálogo real en PREPROD (06-10).** 76 de 76 modelos de Quera sin fallos, y
    atribución de un pedido de punta a punta. Ese día llegaron también el informe mensual en el
    admin, la página de pruebas de API y el registro de todas las llamadas a TUDOR.
17. **Revisión antes de enviar (08-10).** Juanjo prefirió pasar al core todos los relojes,
    también los descatalogados, y que el filtro lo decida el core. El core comprueba ahora cada
    ficha antes de enviarla: normaliza, descarta lo que no se puede publicar (con su motivo),
    junta los repetidos y quita los modelos que no están en la lista de precios vigente de
    TUDOR (`prices_ES.xlsx`). Si la lista falla, se sigue sin ella y con un aviso; si no pasa
    ninguna ficha, no se envía nada, para no poner a 0 el catálogo en TUDOR. Con la lista, en
    Quera se envían 73 modelos y quedan fuera 3.

## Qué está construido y verificado

- `core/`: modelo de dominio, revisión de cada ficha antes de enviar, cliente de la API con
  OAuth2 (batch NDJSON, envío de un modelo, `getStocks`, health), y el generador del informe
  mensual sobre las plantillas Excel reales. **90 tests de PHPUnit pasan**
  (`cd core && composer install && composer test`).
- **Magento**: instalado en la tienda de Quera (producción desde el 05-10, aún sin conectar a
  TUDOR) y probado de punta a punta en su staging contra PREPROD: sincronización completa,
  envíos sueltos y por lotes, retiradas, lista de modelos que falla. Detalle en
  [`modules/magento/README.md`](modules/magento/README.md).
- **PrestaShop y WooCommerce**: implementación real con pantalla de config y Test Connection /
  Run Sync Now, pero solo pasan `php -l`; **no han corrido en una instalación real**.

Ver la tabla comparativa por plataforma en la sección "Platform connectors" de
[`README.md`](README.md).

## Qué falta y de quién depende

**Antes del 19-10 (activación de España):**
- Producción de Quera: credenciales PROD, sync completo cada hora y publicación en el minuto
  activada. Los envíos automáticos (cron) aún no se han probado en el staging, donde están
  pausados a propósito: activarlo es decisión de Juanjo y Bea.

**Pendiente de TUDOR:**
- Semántica real del campo `value` (¿cantidad real de stock o solo una señal?).

**Pendiente de cada cliente:**
- Quera: revisar los códigos de modelo que salen de la regla del SKU, y `M25707B/25-0001`, que
  no está en la lista de precios de TUDOR (solo `M25707B/26-0001`) y por eso no se envía.
- Grau, Gordillo y Saphir: si ya tienen su propio campo/convención para el código de modelo
  TUDOR, antes de confiar en el campo nuevo que agregó cada conector.
- Si quieren click & collect por tienda física (afecta alcance).
- (Solo WooCommerce) Qué plugin de idiomas usan, si usan alguno.

**Ingeniería pendiente, sin bloqueo externo:**
- Informe mensual: en Magento ya salen las ventas de los pedidos y el Excel se genera desde el
  admin, pero sesiones y visitantes únicos se escriben a mano. Falta en el core la interfaz de
  la fuente de datos del informe y leer la analítica de GA4 (Jorge).
- PrestaShop y WooCommerce: pasar a OAuth2 (`clientId`/`clientSecret`); después, quitar
  `tudorApiKey` del core.
- Stock multi-almacén (MSI con varias fuentes) en Magento: hoy se usa el stock del website de la
  tienda por defecto.
- Click & collect por punto de venta / mapeo RSWI en las tres plataformas.
- Estrategia de distribución real del paquete `tudorsync/core` (hoy cada módulo lo referencia
  con un path repository local, solo válido en este monorepo).
- CI que corra `composer test` automáticamente.

**Verificación pendiente:**
- Smoke test real de PrestaShop y WooCommerce, y de sus acciones de admin, contra una
  instalación real de cada plataforma.
- Instalación del módulo de Magento en Pedro Luis Olivares.

## Documentos de trabajo

En `doc/`: `Tudorsync Estado y Pendientes(1).pdf` (estado y lista de pendientes) y
`Tudorsync Arquitectura y Reparto de Trabajo.docx` (reparto del 29-09). Entre los dos
desarrolladores, las peticiones y notas van en `intercambio/`.

Al principio se armaron además tres documentos que viven en claude.ai (Claude Docs) — hay que
compartirlos aparte (botón **Share** en cada uno) para que otra persona los vea:

1. **Tudorsync: Estado y Pendientes** — estado del proyecto y lista de tareas pendientes.
2. **Tudorsync: Alcance Técnico y Presupuesto Tentativo** — desglose de trabajo por partida
   con estimación de esfuerzo en días-persona, base técnica para presupuestar.
3. **Presupuesto Integración TUDOR** — plantilla de presupuesto comercial genérico por
   tienda (72 €/hora), con placeholders para personalizar por cliente.

## Por dónde seguir

- Detalle técnico completo de arquitectura, contrato de API y estado de cada conector:
  [`README.md`](README.md).
- Reglas de trabajo del equipo, producción y staging: [`CLAUDE.md`](CLAUDE.md).
- Documentación original de TUDOR: `doc/`.
