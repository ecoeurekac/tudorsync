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

## Qué está construido y verificado

- `core/`: modelo de dominio, reglas de negocio, cliente de la API (NDJSON batch), y el
  generador del informe mensual sobre las plantillas Excel reales. **14 tests de PHPUnit
  pasan** (`cd core && composer install && composer test`).
- Los 3 conectores de plataforma (Magento, PrestaShop, WooCommerce) tienen implementación
  real, con pantalla de config y las acciones Test Connection / Run Sync Now.
- Todo el PHP pasa `php -l`. **Nada de esto corrió dentro de una instalación real de
  Magento/PrestaShop/WordPress** — falta ese smoke test antes de confiar en los conectores
  en producción.

Ver la tabla comparativa completa por plataforma en la sección "Platform connectors" de
[`README.md`](README.md).

## Qué falta y de quién depende

**Bloqueado en TUDOR** (hay que pedírselo directamente):
- URL base de staging/producción y esquema de autenticación — no vienen en la especificación.
- Semántica real del campo `value` (¿cantidad real de stock o solo una señal?).
- Si el id "RSWI" de `storesAvailabilityDetails` es el mismo `stoId` que devuelve
  `GET /v1/point-of-sales`, o uno distinto.

**Bloqueado en cada cliente** (hay que confirmarlo con ellos):
- Si ya tienen su propio campo/convención para el código de modelo TUDOR, antes de confiar
  en el campo nuevo que agregó cada conector.
- Si quieren click & collect por tienda física (afecta alcance).
- (Solo WooCommerce) Qué plugin de idiomas usan, si usan alguno.

**Ingeniería pendiente, sin bloqueo externo:**
- Conectar datos reales de analítica web y pedidos al informe mensual (hoy solo existe el
  mecanismo de escritura del Excel, no la fuente de datos).
- Stock multi-almacén (MSI) en Magento — solo está conectado el modelo de fuente única.
- Click & collect por punto de venta / mapeo RSWI en las tres plataformas.
- Estrategia de distribución real del paquete `tudorsync/core` (hoy cada módulo lo referencia
  con un path repository local, solo válido en este monorepo).
- CI que corra `composer test` automáticamente.

**Verificación pendiente:**
- Smoke test real de los 3 conectores y sus acciones de admin contra una instalación real de
  cada plataforma (ninguno se probó fuera de `php -l`).

## Documentos de trabajo (Claude Docs)

Estos tres documentos se armaron durante el desarrollo y viven en claude.ai — hay que
compartirlos aparte (botón **Share** en cada uno) para que otra persona los vea:

1. **Tudorsync: Estado y Pendientes** — estado del proyecto y lista de tareas pendientes.
2. **Tudorsync: Alcance Técnico y Presupuesto Tentativo** — desglose de trabajo por partida
   con estimación de esfuerzo en días-persona, base técnica para presupuestar.
3. **Presupuesto Integración TUDOR** — plantilla de presupuesto comercial genérico por
   tienda (72 €/hora), con placeholders para personalizar por cliente.

## Por dónde seguir

- Detalle técnico completo de arquitectura, contrato de API y estado de cada conector:
  [`README.md`](README.md).
- Documentación original de TUDOR: `doc/`.
