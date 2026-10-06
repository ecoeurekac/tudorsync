# Para Jorge (core): `GET /health` en TudorApiClient

06-10-2026, Juanjo. Petición pequeña, sin prisa. No toca nada que funcione hoy.

## Qué

Un método público en `TudorApiClient`, por ejemplo:

```php
/** GET {base}/health (sin /v1, con Bearer): estado del servicio, "UP" si va bien. */
public function getHealth(): HttpResponse
```

La spec lo define como `GET /health` (Spring Boot Actuator, 200 `{"status":"UP"}` o 503). Como comprobé el 30-09,
la ruta va sin `/v1` y también pide token.

## Por qué

El módulo de Magento tiene ya una página de pruebas de la API (Informes › TUDOR e-Stock › Pruebas de API) con un
botón por endpoint. Todos pasan por `TudorApiClient` menos `/health`, porque core no lo tiene. De momento el módulo
lo resuelve así (en `Model\Api\ApiTester`):

- token con `new AccessTokenProvider($clientConfig, $httpClient)` (pública en core, sin cambios);
- **copia de `BASE_URLS`** de `TudorApiClient` (privada allí) para montar `{base}/health`.

Con `getHealth()` en core quito esa copia y las URL quedan en un solo sitio.

## Para que lo sepas

- El módulo pasa a core un `HttpClientInterface` que **registra todas las llamadas** (`LoggingHttpClient` →
  tabla `tudorsync_api_log`, con origen cron/admin/cli/test, petición y respuesta, secret y tokens tapados). No
  cambia nada en core: es la misma interfaz.
- Si algún día core hace llamadas que no pasan por el `HttpClientInterface` recibido, no saldrían en ese registro.
