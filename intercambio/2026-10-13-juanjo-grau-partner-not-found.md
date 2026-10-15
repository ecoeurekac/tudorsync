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

## Actualización 13-10, 10:50 UTC: solo le pasa a Grau

Repetido varias veces (09:05, 10:39, 10:49–10:50 UTC, token nuevo cada vez): siempre igual. Y la misma prueba
de solo lectura con las apps **PREPROD** de los demás:

| Retailer | Token | `GET /v1/point-of-sales` | `GET /v1/stocks` |
|---|---|---|---|
| **Grau** | 200 | **500 `PARTNER_NOT_FOUND`** | **500 `PARTNER_NOT_FOUND`** |
| Quera | 200 | 200 (4 PdV) | 200 (5) |
| Gordillo | 200 | 200 (1 PdV) | 200 (vacío) |
| PLO | 200 | 200 (2 PdV) | 200 (vacío) |
| Saphir | 200 | 200 (2 PdV) | 200 (vacío) |

Control: secret falso → Okta 401 `invalid_client`; token falso o sin token → 401 del gateway. Conclusión: falta
asociar la app PREPROD de Grau a su ficha de retailer en TUDOR. Captura (no versionada):
`doc/distribuidores/grau/20261013_grau_preprod_partner_not_found.png`.

## Actualización 15-10, 08:36–08:38 UTC: sigue igual, y en PROD también

Solo lecturas, sin enviar stock (ni `createStock` ni batch):

| Entorno | Client ID | Token Okta | `/health` | `GET /v1/point-of-sales` | `GET /v1/stocks` |
|---|---|---|---|---|---|
| PREPROD | `0oa1127ojzlpajyIK418` | OK | 200 | **500 `PARTNER_NOT_FOUND`** | — |
| **PROD** | `0oa1127joo0i6lyBx418` | OK | 200 | **500 `PARTNER_NOT_FOUND`** | **500 `PARTNER_NOT_FOUND`** |

La clave de PROD se usó una sola vez desde un archivo temporal ya borrado; no está guardada en la tienda ni en el repo.
**Urgente para el go-live del 19-10:** pedir a TUDOR que asocie las dos apps (PREPROD y PROD) a la ficha de
retailer de Grau con sus puntos de venta. Sin eso Grau no puede publicar, aunque el módulo esté listo.
