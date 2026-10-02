# Para Juanjo (módulos): OAuth2 listo en core

02-10-2026, Jorge. Respuesta al punto 1 de `2026-09-30-juanjo-peticion-oauth-e-informe.md`.
El informe mensual (punto 2 y tu nota `2026-10-02-juanjo-fuente-ventas-informe-magento.md`)
va aparte.

## Qué ha cambiado (y por qué no rompe nada)

- `ClientConfig` **añade** al final `clientId` y `clientSecret` (`string`, por defecto `''`).
  Los parámetros de antes siguen con el mismo nombre y orden. `tudorApiKey` pasa a tener
  `''` por defecto y queda **@deprecated**: ya no se usa para autenticar.
- `TudorApiClient` pide el token a TUDOR (Okta) con esas credenciales, lo guarda en memoria y
  manda `Authorization: Bearer <token>` en todas las llamadas. El constructor admite un 4.º
  parámetro opcional (`AccessTokenProvider`, solo para tests); `new TudorApiClient($config,
  $http)` sigue igual.
- **Si no tocas nada, todo sigue como hoy:** Magento sigue saltándose test y sync por el
  aviso de `getCredentialsProblem()` (`tudorApiKey === ''`); PrestaShop y WooCommerce, si
  tienen clave, ahora fallarían con «TUDOR client credentials are not configured» en vez de
  con un 401 de TUDOR (la clave nunca fue válida, así que el resultado es el mismo: no se
  publica). Ningún cambio necesita `di:compile` ni `setup:upgrade`.
- Antes los cuatro métodos no miraban el código HTTP; ahora cualquier respuesta no 2xx es
  una excepción. **207** en `/v1/stocks/batch` sigue siendo éxito parcial (detalle por línea
  en `BatchSyncResult::failures()`), igual que antes. `getStocks()` también lanza excepción
  si la respuesta no es 2xx.

## Cómo pasar las credenciales (Magento)

En `Model\Config::getClientConfig()`:

```php
return new ClientConfig(
    clientName: ...,
    market: ...,
    languages: [],
    environment: $environment,
    offersClickAndCollect: ...,
    clientId: $this->getClientId($storeId),
    clientSecret: $this->getClientSecret($storeId),
);
```

(sin `tudorApiKey`; ya no hace falta). Lo mismo en PrestaShop y WooCommerce cuando tengan
los campos Client ID / Client Secret en su configuración.

## Comprobaciones de `tudorApiKey === ''`

Hay que cambiarlas para que miren `clientId`/`clientSecret` (o quitarlas y dejar que el core
lance `MissingCredentialsException`):

- Magento: `Model\SyncRunner::getCredentialsProblem()` — quitar el bloque «OAuth pending in
  core»; `Config::hasCredentials()` ya cubre el caso.
- PrestaShop: `tudorsync.php` (dos sitios) y `controllers/front/cron.php`.
- WooCommerce: `includes/Admin/SettingsPage.php` (dos sitios) e `includes/Cron.php`.

## Excepciones que puede lanzar ahora el core

Todas extienden `Tudorsync\Core\Api\Exception\TudorApiException` (`RuntimeException`). Sus
mensajes **nunca** llevan el secret ni el token: se pueden enseñar tal cual en el admin y
en el log. Tu `catch (Throwable $e)` actual ya las recoge todas.

| Excepción | Qué significa | Qué mostrar en el admin |
|---|---|---|
| `MissingCredentialsException` | Falta el Client ID o el Client Secret. No se ha enviado nada. | «Configura el Client ID y el Client Secret de este entorno». |
| `CredentialsRejectedException` | Okta ha rechazado las credenciales (400/401/403). `$oauthError` trae el código de Okta (`invalid_client`, `invalid_scope`…). | «TUDOR ha rechazado las credenciales: revisa que sean las de este entorno (PREPROD/PROD)». |
| `TokenRequestException` | El servidor del token ha fallado por otra cosa (5xx, respuesta sin token). `$statusCode`. | «TUDOR no responde, se reintentará». No es cosa de la configuración. |
| `ApiResponseException` | Una llamada al API ha devuelto un código que no es 2xx. `$statusCode`, `$method`, `$path` y un extracto de la respuesta. Un 401 aquí = token rechazado incluso después de pedir uno nuevo. | El mensaje tal cual (`TUDOR API error: HTTP 400 on POST /v1/stocks/batch: …`). |

Los errores de red del `HttpClientInterface` de cada módulo (`RuntimeException` de tu
`CurlHttpClient`, etc.) pasan sin cambios, como antes.

## `tudorApiKey` se eliminará

Cuando los tres módulos pasen `clientId`/`clientSecret` y no lean `tudorApiKey`, lo quito
del core. Avísame cuando estén, y lo hacemos a la vez (es un cambio del contrato compartido).

## Siguiente paso propuesto

Conectar las credenciales en Magento, quitar el aviso y probar en **PREPROD** con los `mc`
de ejemplo de la colección Postman (`Demo-Retailers-001…005`) antes de mandar relojes de
Quera: Test Connection (`/v1/point-of-sales`), un `createStock` o un batch con esos `mc` y
`getStocks()` para comprobar lo publicado. Ojo: el batch pone a 0 todo lo que no va en él,
así que en PREPROD conviene hacerlo con un batch que solo lleve los Demo-Retailers.
