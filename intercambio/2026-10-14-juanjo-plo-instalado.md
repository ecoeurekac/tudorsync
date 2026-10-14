# PLO: Tudorsync instalado y publicando en PREPROD (14-10-2026)

Para Jorge, de Juanjo.

- Mismo módulo que Quera (`eabfe26`), sin cambios en `modules/magento` ni en `core/`. Magento 2.4.8-p5 con PHP 8.2.
- **PLO no tiene staging**: en Cloudways no había espacio. Está instalado en la tienda de producción y apunta a **PREPROD**. Cuando se valide, el cambio a PROD se hará en esta misma instalación (España: 19-10).
- connection:test: 2 PdV. Primer sync: 25 modelos enviados, 0 fallos. getStocks devuelve 25 registros, todos de PLO, así que ninguna otra instalación usa las credenciales PREPROD de PLO.
- La revisión deja fuera M79030N-0001: no está en `prices_ES.xlsx`. ¿Está descatalogado de verdad o falta en la lista?
- Crons activos: cada hora y cada minuto.
- `.gitignore`: añadido `doc/distribuidores/`. La regla ya decía que no se versiona, pero no estaba ignorado.
