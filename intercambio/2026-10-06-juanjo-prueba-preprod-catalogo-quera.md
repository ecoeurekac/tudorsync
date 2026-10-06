# Para Jorge: primer envío real del catálogo de Quera a PREPROD y prueba de atribución

06-10-2026, Juanjo. Hecho desde el **staging** (`ruzwumpmzd`), aislado de la tienda real (ERP, correo, pagos y
analítica apagados). No toca `core/`.

## 1. Catálogo de Quera en PREPROD

- El módulo ya pasa `clientId`/`clientSecret` a `ClientConfig` (`9ba927c`); `tudorsync:connection:test` → 4 PoS.
- `tudorsync:sync:run` (batch completo): **76 modelos enviados, 76 resultados, 0 fallos**.
- Lectura con `getStocks()`: 82 registros = los 76 de Quera, con el mismo `value` que se envió, más
  `Demo-Retailers-001…006`, todos a 0 (los retira el batch, como se esperaba).
- `M25707B/25-0001` (referencia con barra) aceptada y guardada tal cual.
- ⚠️ Que PREPROD acepte un `mc` **no demuestra que sea un modelo TUDOR real**: también aceptó en su día
  `Demo-Retailers-00x`. ¿Sabes si PROD valida los `mc` contra su catálogo? Si no, convendría pedir a TUDOR que revise
  la lista (está en `tudorsync:catalog:preview`).
- Las URL que hay ahora en PREPROD son las del dominio del staging (`magento-1638574-6712079.cloudwaysapps.com`).
  En producción saldrán con el de quera.es.

## 2. Cliente que llega desde tudorwatch.com

1. Script real de `utm-capture.phtml`, renderizado por Magento y ejecutado en Node con la URL exacta que tiene
   PREPROD: crea `tudorsync_utm=tudorwatch.com|website|tudor_e-stock_program|<ts>` (30 días); una visita posterior con
   otro `utm_source` la borra (último clic); sin UTM no la toca.
2. Pedido real en el staging con esa cookie (transferencia, envío gratuito, Pelagos FXD `M25707B/25-0001`):
   pedido `000001630` → fila en `tudorsync_order_attribution` → `report:programme-sales --month=2026-10` = **1**.
3. El reloj vendido sale del preview (75) y el siguiente `sync:run` lo pone a **0 en PREPROD** (version 1).
4. Pedido cancelado → el informe vuelve a 0 (los cancelados no cuentan) → nuevo `sync:run`: PREPROD otra vez con los 76.

No probado aquí: la publicación en el minuto (crons de Tudorsync pausados a propósito en el staging) y el aterrizaje
en un navegador de verdad (el staging tiene la protección por contraseña de Cloudways y no hay navegador en el servidor).

## Para el informe mensual

La cifra de ventas ya sale de `ProgrammeSalesSource` (tu nota del 02-10 sigue pendiente: nombre de la interfaz en
core y confirmar los criterios de unidades/cancelados). Falta la parte de GA4 (sesiones, visitantes, añadidos al
carrito); en el staging GTM está apagado, así que esa parte hay que probarla con el GA4 de producción.
