# Para Jorge: Grau PREPROD — Okta OK, pero la API responde PARTNER_NOT_FOUND

13-10-2026, Juanjo. Credenciales de Grau recibidas (PREPROD y PROD, app "Grau_TudorSync Tudor B2B").

## Qué he probado (solo lecturas, desde el servidor de Grau, core en `bd2ffab`)

Con las credenciales **PREPROD** (Client ID `0oa1127ojzlpajyIK418`; el secret no va aquí):

| Llamada | Resultado |
|---|---|
| Token Okta (client credentials) | OK: ni `CredentialsRejectedException` ni `TokenRequestException` |
| `GET /actuator/health` (`getHealth()`) | 200 |
| `GET /v1/point-of-sales` | **500** `{"code":"INTERNAL_SERVER_ERROR","message":"Partner not found","messageKey":"PARTNER_NOT_FOUND"}` |
| `GET /v1/stocks?page=0&size=5` | **500**, mismo `PARTNER_NOT_FOUND` |

No he enviado nada (ni `createStock` ni batch). Las de **PROD** no se han usado ni guardado en ningún sitio.

## Lectura

Okta conoce la aplicación, pero en PREPROD el API de TUDOR no tiene a Grau dado de alta como partner
(o la app de Okta no está asociada a su ficha de retailer / RSWI). Con Quera, `point-of-sales`
devolvía sus tiendas desde el primer día, así que no parece cosa del módulo ni de core.

## Lo que necesito

1. Que TUDOR dé de alta / asocie el partner de Grau en **PREPROD** con esta app (Client ID de arriba),
   con sus puntos de venta (RSWI).
2. Ya que estás, confirmar que en **PROD** también está asociado (Client ID `0oa1127joo0i6lyBx418`),
   para no encontrarnos lo mismo en el go-live.
3. Una idea para core, sin prisa: `PARTNER_NOT_FOUND` llega como 500 genérico; si core lo
   reconociera (`messageKey`), el admin podría decir «TUDOR no tiene dado de alta este retailer en
   este entorno» en vez de un error interno.

Mientras tanto sigo con el módulo en el staging de Grau (lectura del código de modelo, preview en seco).
