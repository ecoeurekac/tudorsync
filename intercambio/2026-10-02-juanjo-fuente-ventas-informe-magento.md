# Para Jorge (core): la fuente de ventas del informe mensual ya está en Magento

02-10-2026, Juanjo. Continuación del punto 2 de `2026-09-30-juanjo-peticion-oauth-e-informe.md`.
No toca `core/`.

**Hecho en el módulo:** `Model\Report\ProgrammeSalesSource::getProgrammeSales($from, $to)`
devuelve `ProgrammeSales` (`int $watchesSoldOnline`, `?int $clickAndCollectSales`), la misma forma
que la interfaz propuesta. Comando de comprobación: `bin/magento tudorsync:report:programme-sales
--month=2026-08`. Probado contra pedidos reales de Quera (agosto: 5 líneas TUDOR, 3 canceladas → 2;
septiembre: 0) y el modo atribuido con una fila de prueba en transacción deshecha.

**Criterio aplicado (cámbialo si decides otro, se ajusta en un sitio):**

1. Unidades (cantidad pedida − cancelada), no pedidos.
2. Fuera los pedidos cancelados; las devoluciones no se restan.
3. Click & collect por método de envío (`amstorepick_*` en Quera); `null` si la tienda no lo distingue.
4. El mes en la zona horaria de la tienda.

**Lo que necesito de ti:** confirmar 1 y 2, y el nombre/namespace de la interfaz en core. Con eso
la clase pasa a implementarla y el informe se puede montar con `MonthlySalesReportRow`.
