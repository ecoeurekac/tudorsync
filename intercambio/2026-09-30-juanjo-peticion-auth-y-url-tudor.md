# Para Jorge (core): autenticación, URLs y respuestas de TUDOR — RESUELTO

30-09-2026, Juanjo. Fuente: `doc/Community Asset_ stock-retail-publish-public-rest-api.zip`
(página «API Spotlight» guardada, **la documentación técnica completa**) y
`doc/e-Com - Technical presentation support(1).pdf`, subidos hoy.

## ✅ Probado en PREPROD el 30-09 (solo lectura, no se ha publicado nada)

| Llamada | Resultado |
|---|---|
| Token Okta | 200, `token_type: Bearer`, **`expires_in: 300`** (5 min), scope concedido |
| `GET /health` | 200, UP (también pide token) |
| `GET /v1/point-of-sales` | 200, tiendas reales de Quera (`RSWI_185580` Málaga, `RSWI_2422` Girona, `RSWI_16105` Alicante, `RSWI_184657` San Sebastián…) |
| `GET /v1/stocks` | 200, vacío |

## Autenticación (OAuth2 client credentials)

- **Token endpoint:** `https://login.rolex.com/oauth2/{issuer}/v1/token`
  - issuer PREPROD: `aus3qkuvb8CliPktG417`
  - issuer PROD: `aus3rz4418Eok4GHr417`
- `grant_type=client_credentials`, `scope=com.myrolex.api.estock.publish app_owner`
- La doc pone un body JSON, pero **lo que funciona es `application/x-www-form-urlencoded`**
  (`client_id`, `client_secret`, `grant_type`, `scope`), que es lo normal en Okta.
- Credenciales de Quera PREPROD: Client ID `0oa1127pcn5y1c6fg418`; el secreto lo tiene Juanjo y
  **no va en ningún fichero** (irá cifrado en el admin de Magento).
- El token dura 5 min: basta con pedir uno por ejecución (o renovarlo con un 401).

## URLs base

- PREPROD: `https://pp-api.services.mytudorwatch.com/estock-retail/retailer`
- PROD: `https://api.services.mytudorwatch.com/estock-retail/retailer` (confirmada en la presentación técnica)
- La ruta de health es `{base}/health`, sin `/v1`.

## Respuestas a las preguntas abiertas del core

| Pregunta | Respuesta de la doc |
|---|---|
| Semántica de `value` | «Value of the online stock», entero ≥ 0; en la presentación, «Quantity (absolute value)» → **la cantidad real** |
| RSWI = `stoId` | **Sí**: «stoId represents the identifier, often named RSWI». Formato `RSWI_185580` |
| Formato de `mc` | La TMC de TUDOR, p. ej. `M7939A1A0RU-0001`, `M79360B-0002` |
| Claves de idioma | ISO 639-1 / BCP 47: vale `fr` y vale `en-CA` |
| Batch | Lo que no se envía queda a 0; **un mc repetido en el mismo batch se queda con el último valor** (no suma) |
| `storesAvailabilityDetails` | Sobrescribe el global para cada tienda; una tienda que se deja de enviar se borra; `storePickupTiming` 0 = en tienda ahora |
| IP | Puede que haya que permitir `34.65.7.214` (desde Quera se conecta sin hacer nada) |

## Lo que haría falta en core (propuesta, decides tú)

1. `BASE_URLS` con las 2 URLs de arriba.
2. `ClientConfig`: `clientId` + `clientSecret` (en lugar de `tudorApiKey`); el issuer y el scope
   pueden ser constantes por entorno en core, porque son iguales para todos los retailers.
3. `TudorApiClient`: pedir el token (form-urlencoded), guardarlo en memoria hasta ~`expires_in`
   y mandarlo como Bearer; con un 401, pedir otro y reintentar una vez.

**Esto cambia `ClientConfig` (contrato compartido).** Cuando lo fijes, adapto el módulo Magento
(Client ID + secreto cifrado por entorno). El conector ya agrupa por mc y manda la cantidad
vendible, así que sigue siendo correcto con lo que dice la doc.

## Fecha

Presentación España: **go-live el lunes 19 de octubre de 2026** (activo en tudorwatch.com).
