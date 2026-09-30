# Para Jorge (core): OAuth en el cliente de la API y contrato del informe mensual

30-09-2026, Juanjo. Propuesta: la firma y la implementación las decides tú. Lo que sigue es
lo que el módulo Magento ya tiene preparado y lo que necesitaría del core.

## 1. OAuth2 en `TudorApiClient` — bloquea todo envío a TUDOR

**Qué hay hoy en core:** `ClientConfig::$tudorApiKey` se manda como `Authorization: Bearer <clave>`.
TUDOR no da una clave: da una aplicación de Okta (Client ID + Client Secret) y hay que pedir un
token. Probado desde este servidor en PREPROD el 30-09 (ver
`2026-09-30-juanjo-peticion-auth-y-url-tudor.md`) y confirmado por la **colección Postman oficial**
(punto 3.2 de la doc v2, `eStock_API_Postman_Collection.zip`).

**Datos exactos (de la colección Postman de TUDOR):**

| | PREPROD | PROD |
|---|---|---|
| Token URL | `https://login.rolex.com/oauth2/aus3qkuvb8CliPktG417/v1/token` | `https://login.rolex.com/oauth2/aus3rz4418Eok4GHr417/v1/token` |
| Base URL API | ya en `BASE_URLS` (a5a6fa3) | ya en `BASE_URLS` |

- `POST` con `Content-Type: application/x-www-form-urlencoded` y cuerpo
  `grant_type=client_credentials`, `client_id`, `client_secret`,
  `scope=com.myrolex.api.estock.publish app_owner` (**no** JSON, aunque la doc HTML lo diga).
- Respuesta: `access_token`, `token_type: Bearer`, `expires_in: 300` (5 min).
- Después, todas las llamadas (también `/health`) con `Authorization: Bearer <access_token>`.

**Propuesta:**

1. `ClientConfig`: sustituir `tudorApiKey` por `clientId` + `clientSecret` (los dos `string`).
   Las token URL y el scope, constantes por `Environment` dentro de core (como `BASE_URLS`):
   son iguales para todos los distribuidores; lo que cambia por tienda es la aplicación.
2. `TudorApiClient`: pedir el token la primera vez que haga falta (con el mismo
   `HttpClientInterface::post()`), guardarlo en memoria hasta `expires_in` menos un margen, y
   ante un 401 pedir otro y reintentar **una** vez.
3. Que un fallo del token dé un error claro («TUDOR rejected the client credentials»), distinto
   de un fallo de la API: es lo primero que verá quien configure una tienda nueva.
4. Nunca escribir el secret ni el token en logs ni en mensajes de excepción.

**Lo que ya está en el módulo Magento (ef58068):** en el admin, `Client ID` y `Client Secret`
(cifrado) por entorno; `Model\Config::getClientId()` / `getClientSecret()` los devuelven. Hoy
`getClientConfig()` pasa `tudorApiKey: ''` y `SyncRunner` se salta test y sync con el aviso
«OAuth pending in core». **Cuando lo subas**, paso las credenciales, quito el aviso y
probamos en PREPROD con los `mc` de ejemplo de la colección (`Demo-Retailers-001…005`) antes
de mandar relojes de Quera. El Client ID y el secret de Quera PREPROD ya están guardados.

## 2. Contrato del informe mensual

La plantilla de TUDOR (`core/resources/report-templates/`) pide, por mes: visitas desde TUDOR*,
visitantes únicos, añadidos a la cesta, **relojes TUDOR vendidos online con el programa***, de
ellos click & collect, ventas y citas en boutique (* = obligatorio).

**Reparto que propongo:**

| Dato | Quién lo da |
|---|---|
| Visitas, visitantes únicos, añadidos a la cesta | **core**: GA4 es igual en las 5 tiendas (un cliente de la Data API de GA4, filtrando por `utm_source=tudorwatch.com`) |
| Relojes vendidos con el programa, de ellos click & collect | **cada módulo**, a través de una interfaz de core |
| Ventas y citas en boutique | a mano (la plantilla lo dice) |
| Montar la fila y escribir el Excel | **core** (`MonthlySalesReportRow` + `MonthlyReportWriter`, ya hechos) |

**Interfaz que necesitaría** (nombre y forma a tu gusto), algo como:

```php
interface ProgrammeSalesSourceInterface
{
    /** Relojes TUDOR vendidos online a clientes llegados desde tudorwatch.com, en [$from, $to). */
    public function getProgrammeSales(\DateTimeImmutable $from, \DateTimeImmutable $to): ProgrammeSales;
}
// ProgrammeSales: int $watchesSoldOnline, ?int $clickAndCollectSales (null = la tienda no lo distingue)
```

**Lo que ya está en Magento (5ab4b16):** cookie `tudorsync_utm` al llegar con
`utm_source=tudorwatch.com` (último clic, 30 días) y una fila por pedido en
`tudorsync_order_attribution`. Con eso implemento la interfaz cuando exista.

**Decisiones que necesito de ti para que los 3 conectores cuenten igual:**

1. ¿Relojes = **unidades** de líneas con código de modelo TUDOR, o **pedidos**? (propongo unidades)
2. ¿Se descuentan cancelados y devueltos? (propongo: se excluyen cancelados; devoluciones, no, porque
   el informe es del mes y la devolución puede llegar después)
3. ¿Mes por fecha del pedido en la zona horaria de la tienda? (propongo sí)
4. ¿Qué es «click & collect» para un conector? En Magento, un método de envío concreto
   (recogida en tienda); cada tienda lo configuraría.

## 3. Corrección menor en `MonthlySalesReportRow`

El docblock dice que `$uniqueVisitors` es obligatorio, pero en la plantilla (ES y EN) solo llevan
`*` las **visitas** y los **relojes vendidos online**. Los visitantes únicos no. Si lo cambias, el
constructor pasaría `$uniqueVisitors` a `?int`.
