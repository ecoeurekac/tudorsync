# Para Juanjo: interfaz de core para las ventas del informe mensual

14-10-2026, Jorge. Respuesta a `2026-10-02-juanjo-fuente-ventas-informe-magento.md` (y al punto 2 de
`2026-09-30-juanjo-peticion-oauth-e-informe.md`).

## Nombre y namespace

- `Tudorsync\Core\Contract\ProgrammeSalesSourceInterface`:
  `getProgrammeSales(\DateTimeImmutable $from, \DateTimeImmutable $to): ProgrammeSales`, periodo semiabierto `[$from, $to)`.
- `Tudorsync\Core\Domain\ProgrammeSales`: `int $watchesSoldOnline`, `?int $clickAndCollectSales` (solo lectura).
  Lanza `InvalidArgumentException` con números negativos o si el click & collect supera las ventas online (es una
  parte de ellas: columna G dentro de F).

Tu `ProgrammeSalesSource` puede implementar la interfaz y dejar de usar su propio `ProgrammeSales`. Las líneas de
detalle (`lines`) que usa el comando de comprobación no están en el objeto de core: si las quieres conservar para
el CLI, sácalas por otro método del módulo. PrestaShop y WooCommerce tendrán que implementar la interfaz igual.

## Criterios confirmados

1. **Unidades**, no pedidos (líneas de pedido con código de modelo TUDOR).
2. **Cancelados fuera**; las **devoluciones no se restan**.
3. **Zona horaria: la de la propiedad de GA4** (decisión del equipo), no la de la tienda: los límites del periodo
   los decide quien llama, para que coincida con lo que se ve en GA4. Comprueba si en Quera coinciden (deberían
   ser las dos `Europe/Madrid`); si coinciden, puedes dejarlo como está.
4. **Click & collect: `null` por ahora.** Es compra online (se paga online y se recoge en tienda), pero hoy no se
   ofrece dentro del programa.
5. **Atribución:** cuentan las ventas de clientes que llegan desde tudorwatch.com (`utm_source=tudorwatch.com`).
   Si se atribuye con GA4 o con la atribución propia del módulo está por decidir y puede variar por tienda; la
   interfaz no lo fija. Si el módulo usa su cookie, solo con el consentimiento para cookies analíticas.

## Otras decisiones para el informe

- **Visitantes únicos siguen siendo obligatorios**: llevan `*` en las plantillas ES y EN, así que
  `MonthlySalesReportRow` no cambia (respuesta al punto 3 de tu nota del 30-09).
- **Un informe por mercado** (España), con todos los idiomas juntos.
- **«Añadidos a la cesta» = número de personas** (respuesta de TUDOR).
- Rellenar todo lo que se pueda sacar de GA4.

## Avance de la revisión de GA4 (13-10)

- GA4 reconoce bien la fuente por los parámetros del enlace.
- Eventos de cesta: en Grau (`addToCart`, nombre no estándar) y en Saphir (`add_to_cart`); **nada en PLO ni en
  Gordillo**. En esas tiendas, al menos, las ventas tendrán que salir de los pedidos (de ahí la interfaz).
- Las pruebas se hacen con `utm_source=ecoeureka-test`, **nunca con `tudorwatch.com`**, para no falsear los informes
  ni la atribución.
