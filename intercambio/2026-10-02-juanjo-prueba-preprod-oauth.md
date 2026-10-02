# Para Jorge (core): tu OAuth probado en PREPROD desde Quera

02-10-2026 ~08:37 UTC, Juanjo. Con tu código **sin commit** tal como está en el checkout, desde un
script aparte (no he tocado `core/` ni he conectado aún las credenciales en el módulo Magento).
Credenciales: las de Quera PREPROD guardadas en el admin.

## Solo lectura

| Llamada | Resultado |
|---|---|
| Token + `getPointOfSales()` | ✔ 4 tiendas: `RSWI_185580`, `RSWI_2422`, `RSWI_16105`, `RSWI_184657` |
| `getStocks()` | ✔ 200, vacío |
| Secret falso | ✔ `CredentialsRejectedException` (401, `invalid_client`) |
| Sin credenciales | ✔ `MissingCredentialsException` |

## Escritura, solo con `Demo-Retailers-001` (country ES)

| Paso | `GET /v1/stocks` después |
|---|---|
| `createStock` value 3 | value 3, `version: 0` |
| `createStock` otra vez, value 1 | **value 1, `version: 1`** → `POST /v1/stocks` **actualiza** (responde el punto 1 de mi petición de hoy) |
| `createStock` value 0, `onlinePurchaseEnabled: false` | **value 0, `version: 2`** → se acepta value 0 por `POST /v1/stocks` |

- `createStock()` devuelve `status: CREATED` también al actualizar (está fijo). Solo lo uso para el log.
- En PREPROD queda únicamente `Demo-Retailers-001` con value 0. No se ha mandado ningún reloj.
- Campos que devuelve cada fila de `GET /v1/stocks`: `id, retailerId, country, value, defaultUrl,
  localizedUrls, mc, version, updatedAt, storePickupAvailable, onlinePurchaseEnabled,
  homeDeliveryTiming, storesAvailabilityDetails`.

## Siguiente

Cuando hagas commit del OAuth, conecto `clientId`/`clientSecret` en `Model\Config::getClientConfig()`
y quito el aviso «OAuth pending in core». Ojo: en cuanto lo haga, el sync de cada hora y el de cada
minuto publican el catálogo real de Quera en PREPROD (77 modelos). Si prefieres que antes probemos
un batch solo con Demo-Retailers, dímelo.
